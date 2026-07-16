# Best-in-Class Update — Square Terminal for WooCommerce

**Date:** 2026-07-16
**Status:** Proposed
**Baseline:** `feat/square-terminal-working-plugin-wcpos` (~1,200 lines; working skeleton)

## Inputs and provenance

This spec synthesizes four sources:

1. **Gap analysis of this plugin** (all 16 gaps referenced below as G1–G16).
2. **Production-pattern review of `stripe-terminal-for-woocommerce`** — the most battle-tested sibling: reader recovery, compare-before-cancel, decline detection, POS auth (issues #22, #27, #44, #45, #50).
3. **Production-pattern review of `sumup-terminal-for-woocommerce`** — attempt identity, serialized polling, forced fresh read before irreversible actions, async cancellation.
4. **Production-pattern review of `mollie-terminal-for-woocommerce`** — single reconciler, offline-terminal detach/abandon, layered cleanup (beacon → status hook → cron sweeper), beta-tester feedback (2026-07-08).
5. **Square official docs research** (developer.squareup.com, 2026-07-16) — checkout lifecycle, webhook subscriptions API, sandbox test device IDs, refund rules, error taxonomy.

## 1. Goals

- A cashier can take, cancel, retry, and recover a Square Terminal payment from WooCommerce POS without ever abandoning the order — including when the terminal is offline, the page reloads, or webhooks never arrive.
- Setup is near-zero-friction: webhook subscription is provisioned automatically; terminals are paired from the settings screen; sandbox testing works out of the box with simulated devices.
- Every order mutation is server-verified against Square, idempotent, and monotonic. The browser is never trusted.
- WooCommerce-native refunds for Terminal payments.

**Non-goals (this update):** OAuth-based Square connection (constrained anyway — see §7 token caveat); Square Orders API itemized-cart display (`order_id`/`show_itemized_cart`); app fees; delayed capture; Events API backfill (future, §18); gift-card partial authorization (`accept_partial_authorization` stays off).

## 2. Design principles (distilled from production learnings)

1. **Square is authoritative; order meta is a cache.** Every meaningful decision re-fetches provider state server-side (Mollie's core principle).
2. **One reconciler.** Poll, webhook, cancel, sweeper, and reload-recovery all feed a single `CheckoutReconciler::reconcile( TerminalCheckout, Order )`. No path mutates order state on its own (Mollie).
3. **Amend the Payment Completion Signal definition** (CONTEXT.md): the authoritative signal is any *server-verified* Square read — a signature-verified webhook **or** a server-side `GetTerminalCheckout`/`GetPayment` fetch. Both may complete the order; the browser may never. This closes the "webhook misconfigured → money taken, order never paid" hole (G15) while preserving the spirit of the existing rule.
4. **Attempt identity.** Each payment attempt has a UUID persisted *before* calling Square; every callback, poll result, and cancel correlates to the attempt (SumUp). Stale events from prior attempts are rejected.
5. **Cancellation is a request, not a result.** Square documents `CANCEL_REQUESTED → COMPLETED`. Always fetch state before and after cancel; a cancel that resolves to COMPLETED is a *successful payment*, not an error (Square docs §3; Mollie; SumUp).
6. **Fresh forced read before every irreversible decision** — timeout auto-cancel, cancel, refund — bypassing any cache/throttle (SumUp commit `25fcd80`).
7. **Monotonic state.** COMPLETED/CANCELED never regress. Webhooks are at-least-once and unordered (Square docs §6): dedupe by `event_id`, compare `updated_at` before overwriting.
8. **Never trust client amounts.** The create endpoint recomputes the total from the order at call time (Stripe changelog lesson).
9. **Recoverable, never dead-ended cashier UI.** Manual "Check Status" always exists; offline terminals can be detached without abandoning the WooCommerce order (Mollie beta feedback — its single most valuable lesson).
10. **Explicit admin actions.** Validation and webhook provisioning run behind buttons/save handlers, never as a side effect of rendering a settings page (anti-pattern in Stripe sibling).

## 3. Order meta schema (extends current keys)

| Key | Purpose |
|---|---|
| `_sqtwc_current_attempt_id` | UUID of the active attempt, minted before `CreateTerminalCheckout` |
| `_sqtwc_checkout_idempotency_key` | **Attempt-scoped** (currently order-scoped). Reused verbatim when retrying the *same* attempt after ambiguous failure; a new attempt mints a new key (Square idempotency guidance §9) |
| `_sqtwc_checkout_id` | Square checkout ID of the current attempt (existing) |
| `_sqtwc_checkout_status` | Cached Square status (`PENDING`/`IN_PROGRESS`/`CANCEL_REQUESTED`/`CANCELED`/`COMPLETED`) |
| `_sqtwc_checkout_updated_at` | Square `updated_at` of the last applied state — the monotonicity guard |
| `_sqtwc_device_id` | Device the active attempt was dispatched to; authorizes cancel and detach |
| `_sqtwc_attempt_started` | Timestamp; rejects callbacks from earlier attempts |
| `_sqtwc_attempt_history` | Array of prior attempt records `{attempt_id, checkout_id, status, device_id, started, ended}` |
| `_sqtwc_abandoned_checkout_ids` | Checkouts detached from offline terminals, still owed reconciliation by the sweeper (Mollie) |
| `_sqtwc_square_checked_at` | Server-side throttle for authoritative Square fetches (SumUp) |
| `_sqtwc_payment_ids` | Persisted **immediately** on COMPLETED — TerminalCheckout objects are deleted after 30 days; the Payment is the permanent record (existing key, new urgency) |
| `_sqtwc_collected_amount` / `_sqtwc_tip_amount` | Actual amount from the Payment object(s); a COMPLETED checkout may not equal the requested total (tips) |
| `_sqtwc_processed_event_ids` | Webhook dedup (existing; cap at last 50) |
| `_sqtwc_payment_log` | Cashier log (existing; cap at 100 entries, each timestamped server-side) |
| `_sqtwc_refund_ids` | Square refund IDs keyed by WooCommerce refund ID |

## 4. Workstream A — Reconciler and status endpoint (server)

**New: `Services/CheckoutReconciler`.** Input: a normalized checkout array + order. Behavior:

- Reject if `reference_id` doesn't match the order or the checkout ID doesn't match the current attempt (unless listed in `_sqtwc_abandoned_checkout_ids`).
- Reject stale data: incoming `updated_at` older than `_sqtwc_checkout_updated_at` → no-op.
- `COMPLETED`: fetch each Payment in `payment_ids` (`GetPayment`), verify status `COMPLETED`/`APPROVED`-captured, sum `total_money`; store `_sqtwc_payment_ids`, collected/tip amounts; if collected < order total, add a prominent order note (partial-collection guard); then `payment_complete( first_payment_id )` guarded by `! $order->is_paid()`. Log discrepancies but do not block completion for tip overages.
- `CANCELED`: record `cancel_reason` (`TIMED_OUT` / `SELLER_CANCELED` / `BUYER_CANCELED`) in the log + order note, close the attempt (move to history, clear current pointers). **Timeout-boundary race** (documented by Square): on `TIMED_OUT`, fetch payments for the checkout before closing — a payment captured at the deadline boundary must win.
- `CANCEL_REQUESTED` / `PENDING` / `IN_PROGRESS`: cache status, keep attempt open, map to a cashier-facing message.
- All transitions append structured entries to `_sqtwc_payment_log`.

**New AJAX action `sqtwc_get_terminal_status`** (same OrderAccess model as create/cancel):

- Returns cached state instantly when it's already final.
- Non-final: fetch `GetTerminalCheckout` **throttled to one Square call per 5 s** via `_sqtwc_square_checked_at` (SumUp); `force=1` bypasses the throttle (used by the pre-cancel/timeout fresh read).
- Response contract: `{ status, cashier_message, continue_polling, redirect_url? }` — `continue_polling: false` on `COMPLETED`/`CANCELED`/idle; `redirect_url` present when paid (POS-aware thank-you URL, avoiding WooCommerce's "already paid" resubmission trap — Mollie lesson).

**Create endpoint hardening (G6, G16):**

- Wrap all Square SDK calls in an error mapper (§12): SDK exceptions become structured `{status, error_code, cashier_message}` responses, never PHP fatals.
- Reject creation when the order is already paid or an attempt is already active for it (server-side duplicate-start guard — button state is not a concurrency mechanism, SumUp).
- Recompute amount from `$order->get_total()` at call time; ignore any client-posted amount (Stripe).
- Set `deadline_duration: PT5M` explicitly (§10).
- All error strings translatable (`__()`), G16.

## 5. Workstream B — Cashier frontend (rewrite of `assets/js/payment.js`, G1–G3)

The current file is a stub. Full rewrite, vanilla JS, localized strings via `wp_localize_script`.

**Polling loop:**

- Chained `setTimeout` (never overlapping `setInterval` — Stripe gap), 2 s cadence while healthy.
- Transport errors: capped exponential backoff (2 → 4 → 8 s, cap 15 s) with a consecutive-error counter; surface a "connection unstable" status after 3 consecutive failures.
- Wall-clock deadline of 5.5 min (checkout `deadline_duration` is 5 min; the margin absorbs latency). On deadline: let any in-flight request finish, then issue one `force=1` fresh read; only if still non-final, run the cancel flow (§6) and tell the cashier. Never present a timeout as "safe to start over" without that read (SumUp `7710dab`, `25fcd80`).
- Poll sessions carry a sequence ID; stale responses from a superseded session are dropped (Mollie).
- Stop conditions: `continue_polling: false`, cancellation confirmed, page navigation, superseding attempt.

**Resume after reload (Mollie):** the order-pay renderer emits `data-resume="1"` + current attempt data when an open attempt exists; JS resumes polling immediately. A page reload must never orphan an in-flight payment.

**Button state machine** (single source of truth; every mutation disables its trigger while in flight):

| State | Start | Cancel | Check Status | Device select |
|---|---|---|---|---|
| idle | enabled | hidden | enabled | enabled |
| creating | disabled | hidden | disabled | locked |
| polling (PENDING/IN_PROGRESS) | disabled | visible+enabled | enabled | locked |
| cancel in flight | disabled | disabled | disabled | locked |
| final | enabled (retry) | hidden | enabled | unlocked |

- Device selector is a dropdown of paired devices (§8), never free text. No silent auto-selection when the saved default is missing — the cashier must choose (Mollie `5390e32`).
- Status region: `role="status"` + `aria-live="polite"`; semantic info/success/error/warning styles; spinner while busy (Mollie/SumUp).
- All dynamic text inserted via `textContent`, never HTML interpolation (XSS via device names — Stripe gap).
- Cashier log panel: collapsible, readonly, timestamp+severity prefixed, bounded to 50 lines, persisted in `sessionStorage` keyed by order ID, Copy + Clear buttons, hidden behind a "Checkout debug logs" gateway setting (SumUp/Mollie pattern).
- Checkout DOM is disposable: event delegation only; re-init on `updated_checkout`; dedupe bindings (SumUp origin fix, Mollie `fda5e6d`).
- Hostile-theme CSS: scope styles under a high-specificity container; verify under adversarial theme styles (Mollie `2ad5a0d`).

## 6. Workstream C — Cancellation and recovery (G4 partial, new)

**Cancel flow (server, replaces the current fire-and-forget):**

1. `force=1` fresh fetch. Already final → reconcile that state and return it (a payment that completed during cancellation is a *payment*, and the UI redirects to the receipt, not to a "canceled" message).
2. Non-final → `CancelTerminalCheckout`. Treat transport exceptions as inconclusive, not failed.
3. Fetch again; reconcile the post-cancel state. `CANCEL_REQUESTED` is returned to the client as *in-flight* — the client keeps polling until `CANCELED` or `COMPLETED` (Square docs: cancel is a request; SumUp: keep watching until final).
4. The UI never stops polling merely because a cancel request was *sent* (Stripe gap: cancel network failure left the payment unmonitored).

**Offline / stuck terminal — detach (Mollie's flagship lesson):** if a checkout stays `PENDING`/`CANCEL_REQUESTED` (device offline; Square forum-reported stuck state), the cashier gets a "Terminal not responding — release this payment" action after the cancel flow fails to conclude:

- Appends the checkout ID to `_sqtwc_abandoned_checkout_ids`, moves the attempt to history as `abandoned`, clears current pointers, returns the panel to idle.
- The order is immediately payable again (different device, cash, etc.).
- The sweeper (§13) keeps reconciling abandoned checkouts; if one later proves COMPLETED, the reconciler still completes/flags the order (or flags double-payment if it was re-paid — order note + admin notice).

**Multi-register safety (Stripe's hardest-won lesson, adapted):** cancel and detach requests must carry the attempt's `device_id` and `checkout_id`; the server verifies they match the order's current attempt before acting. We never cancel a checkout the order doesn't own, and there is no "clear whatever is on the terminal" action — Square's model is per-checkout, which removes most of Stripe's force-clear surface, but compare-before-cancel still applies.

**Abandonment layering (Mollie):** browser `pagehide` beacon (best-effort cancel) → `woocommerce_order_status_changed` hook cancels open checkouts when an order goes processing/completed/cancelled/failed via another path (8 s API timeout so an outage can't stall checkout) → cron sweeper backstop.

Prefer `DismissTerminalCheckout` where the goal is "return the device to idle" after buyer abandonment — it resolves by actual payment state automatically (Square docs §3). Cancel remains the explicit cashier action.

## 7. Workstream D — Webhooks: auto-provisioning + hardened handler (G4, G5)

**Auto-provisioning (`Services/WebhookProvisioner`, new):**

- Runs from an explicit **"Connect webhook"** button and automatically after saving credentials when no healthy subscription exists for the active environment. Never as a render side effect.
- Flow: `ListWebhookSubscriptions` → match exact `rest_url( 'sqtwc/v1/webhook' )` → create if absent via `CreateWebhookSubscription` with `event_types: [terminal.checkout.updated, device.code.paired, terminal.refund.updated, payment.updated]`, pinned `api_version` equal to the SDK's Square-Version, `notification_url` from `rest_url()` — then persist per environment: subscription ID, `signature_key`, `notification_url`, `api_version`.
- Verification round-trip: `TestWebhookSubscription` → the handler marks a transient on receipt → admin shows ✅ "delivered and signature-verified" or a diagnostic (URL unreachable / signature mismatch). This proves end-to-end delivery, not just API success.
- Existing subscription with unknown key: call `UpdateWebhookSubscriptionSignatureKey` to rotate and capture a fresh key (solves Stripe's unrecoverable-secret problem — Square can rotate, Stripe couldn't).
- Non-public/`localhost` site URL: warn and skip registration rather than registering a dead endpoint. Manual URL + key entry stays as fallback for proxied setups; the field is demoted to an "Advanced" section.
- **Token caveat (documented by Square):** the Webhook Subscriptions API accepts only personal access tokens — exactly what this plugin uses. Record in an ADR that a future OAuth connection cannot manage subscriptions and will need a different strategy.
- Sandbox and production each get their own subscription, key, and URL records (per-environment, §9 of docs research).

**Handler hardening (`WebhookHandler`):**

- Keep raw-body signature verification exactly as is (correct today) — but verify against the *stored provisioned* notification URL.
- Route events: `terminal.checkout.updated` → reconciler (all statuses, not just COMPLETED — G4); `device.code.paired` → finalize pairing (§8); `terminal.refund.updated` → refund reconciler (§11); `payment.updated` → tip/refund amount refresh. Unknown types → 200 ignored.
- Dedupe by `event_id` (existing) **plus** the `updated_at` monotonicity guard in the reconciler (defends against out-of-order delivery, which Square explicitly does not guarantee — the SumUp same-attempt regression).
- Return **500 on transient internal failures** so Square retries (11 attempts / 24 h); 2xx only for processed, duplicate, or genuinely ignorable events; 4xx for malformed/unverifiable. Both Mollie and Stripe swallow errors and return 200 — their known defect, not to be copied.
- Respond fast: verification + dedup inline; reconciliation work stays lightweight (single order fetch + meta writes). If it ever grows, move to a queued action.

## 8. Workstream E — Device pairing and management (G3)

Replaces manual device-ID entry end to end.

- **Settings → Terminals section:** "Pair new terminal" (name + location) → `CreateDeviceCode` (`product_type: TERMINAL_API` — Dashboard-generated codes do **not** work, a documented integrator trap) → display code + expiry; completion detected via `device.code.paired` webhook, with `GetDeviceCode` polling fallback while the admin page is open.
- Store paired terminals as an option: `{device_id, name, location_id, paired_at}`. List with name/ID/status and an Unpair (forget) action. `ListDevices` refresh with a 5-minute cache and 8-second timeout — never an uncached provider call on settings render (Mollie `36c245f`).
- **Health check:** per-terminal "Ping" button → `CreateTerminalAction {type: PING, deadline_duration: PT30S}` → reports online (with battery/network metadata) or timed-out ⇒ offline. Used ad hoc, not as a create precondition.
- **POS device selection:** dropdown fed from the stored terminals (optionally filtered by the order's location). Last-used device remembered per browser (`localStorage`), restored only if still paired (Stripe pattern). A "default terminal" gateway setting for single-register shops.
- **Sandbox:** the device dropdown is replaced by Square's magic test device IDs, labeled by scenario (§16) — pairing is bypassed in sandbox.

## 9. Workstream F — Settings and admin UX (G8, G11, G13)

- **Per-environment everything:** access token, location ID, webhook subscription/key/URL. Today `location_id` is shared (G8) — sandbox and production locations are different IDs. Migrate the existing key on upgrade.
- **Explicit "Validate settings" button** (handler actually registered — today `admin.js` references actions with no server side): token validity (`ListLocations`), location exists and is active, location currency vs store currency, Terminal availability for the location's country. Inline green/red results per check.
- Environment switch shows a stark visual banner when production is active; live mode blocked on non-HTTPS sites (Stripe pattern).
- **Masked secrets:** saved tokens/keys are never re-rendered; placeholder "saved — leave blank to keep"; blank submission preserves the stored value (Stripe issue #45).
- Custom field renderers must return full WooCommerce settings table rows (Stripe `5b4b708`).
- **Storefront opt-in (G11):** gateway available in POS/order-pay by default; classic checkout requires an explicit "Enable at storefront checkout" setting. `is_available()` also gates on: enabled, credentials + location present, at least one paired terminal (or sandbox), order currency == location currency (G12).
- Connection status panel: merchant/location name (catches wrong-account mistakes — SumUp), webhook health (last event received at), terminal count.

## 10. Workstream G — Checkout creation parameters (G7)

`CreateTerminalCheckout` gains:

- `deadline_duration: PT5M` explicit (default == max 5 min; only `PENDING` times out).
- `reference_id`: keep `woocommerce_order_{id}` (≤ 40 chars).
- `note`: store name + order number (≤ 500 chars).
- `device_options.skip_receipt_screen` (setting, default off), `collect_signature` (setting, default off), `tip_settings` (settings: allow tipping, separate tip screen, smart tipping vs custom percentages — supported AU/CA/IE/UK/US).
- `payment_options.autocomplete: true` explicitly (immediate capture); `accept_partial_authorization` **not** enabled (non-goal).
- If tipping is enabled, the reconciler's collected-vs-requested comparison treats overage as tip (stored in `_sqtwc_tip_amount`, order note added; order fee line optional, phase 3 decision).
- Pin `Square-Version` explicitly to the SDK's pinned version and keep webhook subscription `api_version` aligned (docs §10).

## 11. Workstream H — Refunds (G9)

- `supports[] = 'refunds'`; implement `process_refund( $order_id, $amount, $reason )`.
- Guard first: fetch the Payment; if `card_details.refund_requires_card_presence === true` (Canadian Interac), return a `WP_Error` instructing a card-present Terminal refund (out of scope for v1 of this workstream; the error message says how to do it in Square Dashboard/Terminal).
- `RefundPayment` with its own idempotency key (`sqtwc_refund_{wc_refund_id}`), `payment_id`, `amount_money`, `reason`. Multi-payment checkouts: refund against payments in order until the requested amount is covered.
- Store Square refund IDs in `_sqtwc_refund_ids`; reconcile async refund settlement via `terminal.refund.updated`/`payment.updated` webhooks and order notes. WooCommerce's own rollback (refund record deleted when `process_refund` errors) covers the local-before-remote hazard Mollie has.

## 12. Workstream I — Error taxonomy and logging (G6, G10)

**Error mapper (`Services/SquareErrorMapper`, new):** translate Square `errors[].category/code` into `{retriable, cashier_message, log_context}`:

- `AUTHENTICATION_ERROR` → "Square connection failed — check plugin settings" + admin notice; never retried.
- `RATE_LIMITED` / `API_ERROR` / timeouts → retriable; create retries once with the **same idempotency key** (Square guidance — never mint a new key for a retry), polls just back off.
- `INVALID_REQUEST_ERROR` (unpaired device, bad location) → specific messages ("This terminal is no longer paired…").
- `PAYMENT_METHOD_ERROR` → buyer-facing decline message; attempt stays open for retry on the same checkout where Square permits, otherwise new attempt.
- Raw `detail` strings go to logs only, never to the cashier (SumUp/Stripe gap).

**Logging:**

- Keep `Logger` + redaction; extend `SECRET_KEYS` with `signature_key`, `device_code`, `code`, `hmac`; truncate values > 1,000 chars (Mollie).
- Every lifecycle transition logs: order ID, attempt ID, checkout ID, device ID, status, `cancel_reason`, Square request ID where available.
- `_sqtwc_payment_log` entries become structured `{t, level, msg}` rendered to the cashier panel; capped at 100 (unbounded today, G10).
- Meta writes for the log go through a single append helper that re-reads before writing to minimize webhook-vs-AJAX clobbering (G10); full attempt state lives in the dedicated keys, so a lost log line is cosmetic.

## 13. Workstream J — Background reconciliation sweeper

- WP-Cron (or Action Scheduler if already loaded — implementation choice) every 10 minutes: query orders with an open attempt older than 10 minutes **or** non-empty `_sqtwc_abandoned_checkout_ids`, batch 25 (Mollie).
- Each hit: forced fetch + reconcile. Open-and-expired attempts whose checkout is CANCELED get closed; COMPLETED ones complete the order even hours later.
- Sweeper must find abandoned checkouts regardless of order status — including orders already paid by other means (double-payment flag) (Mollie `5955f83`).

## 14. Compatibility and availability

- Declare HPOS compatibility (`custom_order_tables`) — all order access is already CRUD-based; audit for direct postmeta usage (G13).
- Declare `cart_checkout_blocks` compatibility; storefront-blocks UI support is *deferred* — when storefront opt-in is off (default), the gateway never appears there anyway. Guard classic assets from loading on blocks checkout (Stripe `a68e283`).
- PHP 8.1+ per ADR-0002; activation guard with a clear message.
- i18n audit: every PHP and JS string translatable; JS strings via `wp_localize_script`; POT regenerated (G16; SumUp shipped a mis-named POT — check ours).

## 15. Phasing

**Phase 1 — Reliable payment loop (the product):** Workstreams A, B, C, G + error mapper. Attempt model, reconciler, status endpoint, full frontend, cancel/detach/recovery, deadline handling. *Acceptance:* every sandbox magic-device scenario (§16) drives the UI to the correct final state with no dead ends; reload mid-payment resumes; webhooks disabled entirely → orders still complete via poll reconciliation.

**Phase 2 — Zero-friction setup:** Workstreams D, E, F. Webhook auto-provisioning + verified round-trip, device pairing UI, per-env settings + validation. *Acceptance:* fresh install → paired terminal + verified webhook without leaving wp-admin; `TestWebhookSubscription` round-trip green; sandbox/production isolation proven.

**Phase 3 — Completeness:** Workstreams H, I (cashier log polish), J, §14. Refunds, sweeper, HPOS/blocks declarations, i18n audit, tip settings surfaced. *Acceptance:* WooCommerce-native refund lands in Square sandbox; sweeper closes an orphaned attempt and completes a late-paid one; PHPCS + tests green.

Each phase lands as its own PR off this feature lane, implemented per repo TDD conventions (tests first for reconciler transitions, webhook routing, error mapper).

## 16. Test plan

**Sandbox magic device IDs** (no hardware; drive every UI state):

| Scenario | device_id |
|---|---|
| Success (≤ $25) | `9fa747a2-25ff-48ee-b078-04381f7c828f` |
| Success + 20% tip | `22cd266c-6246-4c06-9983-67f0c26346b0` |
| Buyer cancels | `841100b9-ee60-4537-9bcf-e30b2ba5e215` |
| Immediate TIMED_OUT | `0a956d49-619a-4530-8e5e-8eac603ffc5e` |
| Offline terminal (stays PENDING; exercises cancel + detach) | `da40d603-c2ea-4a65-8cfd-f42e36dab0c7` |
| Decline | any success ID with amount > $25 |

**Unit (PHPUnit, existing harness):** reconciler transition matrix (each status × already-paid × stale `updated_at` × wrong attempt); webhook routing/dedup/monotonicity; error mapper; refund guards; provisioner URL-matching.

**JS tests:** poll loop backoff + sequence-ID staleness; button state machine; resume-from-reload; timeout → forced read → cancel ordering.

**Live validation:** hosted test site `dev-pro.wcpos.com` (real sandbox webhooks — per `docs/testing/square-terminal-validation.md`); `demo.wcpos.com` final smoke. Verify: webhook round-trip, all magic-device scenarios, reload resume, webhook-disabled fallback, refund.

## 17. Do-not-copy register (sibling defects deliberately excluded)

- Overlapping `setInterval` polling; no backoff (Stripe, SumUp, Mollie).
- Webhook handlers returning 200 on transient failure (Mollie, Stripe) or lacking signature verification (SumUp, Mollie) / event dedup (Stripe, SumUp).
- Settings-page GET mutating remote webhook state (Stripe).
- UI stops polling before cancellation is confirmed (Stripe).
- Hard-coded 2-decimal currency exponents (SumUp) — keep `CurrencyConverter`.
- Local refund without remote confirmation handling (Mollie — mitigated by WooCommerce rollback + our guard order).
- Public non-webhook REST endpoints with `__return_true` (Stripe legacy).
- Dead settings accessors / orphaned admin endpoints from sibling copy-paste (SumUp, Mollie) — delete, don't port.

## 18. Future / open items

- **OAuth Square connection:** blocked from webhook auto-provisioning (personal-token-only API) — needs an ADR when tackled.
- **Events API backfill** for >24 h webhook outages (sweeper covers the practical cases first).
- Square Orders API integration for `show_itemized_cart`.
- Interac card-present Terminal refunds.
- `UpdateWebhookSubscriptionSignatureKey` scheduled rotation (Square suggests 90 days).

## Appendix — research artifacts

Full reports committed under `docs/research/`:

- `2026-07-16-stripe-terminal-plugin-review.md`
- `2026-07-16-sumup-terminal-plugin-review.md`
- `2026-07-16-mollie-terminal-plugin-review.md`
- `2026-07-16-square-terminal-api-docs.md` (doc URLs and exact field names per topic)
- `2026-07-16-current-plugin-gap-analysis.md` (G1–G16)
