# Mollie Terminal plugin: production patterns for a Square Terminal sibling

## Review scope

**Observed:** the checked-out `main` was three commits behind `origin/main`. I reviewed the latest fetched `origin/main` at `aa6f56a` / v0.4.0; file/line references below refer to that revision. The requested `git log --oneline -40` was also run against the checked-out branch.

**Core architectural principle:** Mollie is authoritative; WooCommerce meta is a cache. Every meaningful path re-fetches provider state before changing the order (`README.md:5-13`).

---

## Executive recommendations for Square

Port these patterns:

1. Use **one reconciliation function** for poll, webhook, cancellation, recovery, and reload.
2. Make the cashier flow recoverable from offline terminals: **detach locally, preserve the provider payment ID, reconcile later**.
3. Layer cleanup: browser timeout → page-close beacon → order-status hook → server cron sweep.
4. Track both a **current attempt pointer and attempt history**.
5. Redirect directly to the thank-you/POS completion URL after out-of-band success.
6. Treat terminal selection as a server-enforced authorization decision.
7. Keep cashier diagnostics bounded, copyable, and redacted; send durable logs to WooCommerce logs.

Do **not** copy these gaps:

- Square webhooks should have signature verification; this plugin has none.
- Return a retryable HTTP error on transient webhook failure; this plugin always returns 200.
- Add explicit exponential poll backoff or documented fixed-cadence reasoning.
- Use a genuinely atomic lifecycle lock or idempotency key, not only WordPress transients.
- Do not create a local WooCommerce refund before remote refund success without rollback/reconciliation.

---

# 1. Payment status polling

## Cadence, backoff, and timeout

- Default interval is **2 seconds**, filterable through `mtfwc_poll_interval_ms`; timeout is **300,000 ms / 5 minutes**, filterable through `mtfwc_poll_timeout_ms` (`includes/Gateway.php:296-306`).
- The same defaults exist client-side as fallbacks (`assets/js/payment.js:4-7`).
- There is **no exponential backoff** and no explicit attempt counter. The same interval is reused for every tick (`assets/js/payment.js:336-358`, `391-410`).
- Therefore the nominal maximum is roughly 150 requests over five minutes, but this is not enforced: the next tick is scheduled only after the previous request resolves, so network latency reduces the actual count.
- Timeout is wall-clock based using a deadline, not attempts (`assets/js/payment.js:341-342`, `364-366`).

### Square lesson

Use a deadline rather than only `maxAttempts`, but combine it with capped exponential backoff and jitter. Terminal APIs do not normally need a request every two seconds for five uninterrupted minutes.

## Poll behavior

Each tick invokes an authenticated WooCommerce AJAX request. Server-side polling:

1. Returns `idle` when there is no current attempt.
2. Fetches Mollie only if cached state is non-final.
3. Runs the fetched payment through the common reconciler.
4. Returns cached final state without another provider request.

See `includes/Services/MolliePaymentService.php:65-84`.

Network and malformed-response failures produce an empty status; the classifier treats unknown states as pending, so automatic polling continues until timeout (`assets/js/payment.js:218-237`, `240-266`, `391-410`).

After a payment has remained open for 60 seconds, the server adds a “still waiting” message (`includes/Services/MolliePaymentService.php:80-83`), although the current UI does not prominently render that returned message outside the logs.

## What stops polling

The loop stops on:

- Provider/reconciler outcomes `paid`, `already_paid`, or `conflict` (`assets/js/payment.js:254-260`, `397-403`).
- Final unpaid states: `failed`, `canceled`, `expired`, or verification failure (`assets/js/payment.js:259-260`, `400-403`).
- `idle`, meaning no current attempt (`assets/js/payment.js:404-407`).
- Five-minute timeout, followed by an automatic cancel (`assets/js/payment.js:365-389`).
- Cashier clicking Cancel (`assets/js/payment.js:513-523`).
- Switching to another payment method (`assets/js/payment.js:547-615`).
- Completion/redirect (`assets/js/payment.js:425-447`).
- A superseding attempt: poll sessions carry a sequence ID, and stale responses are ignored (`assets/js/payment.js:341-342`, `391-395`).

## Polling and webhooks coexistence

Polling and webhooks are not competing implementations. Both feed `PaymentReconciler::reconcile()`:

- Poll path: `includes/Services/MolliePaymentService.php:72-75`.
- Webhook path: `includes/WebhookHandler.php:23-31`.
- Reconciler: `includes/PaymentReconciler.php:10-32`.

If a webhook marks the attempt paid first, the next poll sees final cached status and completes the browser flow without another Mollie fetch. If polling wins, a later webhook becomes idempotent.

## Reload recovery

If checkout reloads while an unfinished payment exists, PHP renders `data-resume="1"` (`includes/Gateway.php:213-226`), and JavaScript resumes polling immediately (`assets/js/payment.js:789-798`).

This was added after a real tester reported that refreshing left an open payment stranded at Mollie (`docs/feedback/2026-07-08-beta-feedback-plan.md:47-66`).

---

# 2. Payment cancellation

## Cashier initiation

The v0.4 UI has one primary button:

- `Start Terminal Payment` while idle.
- `Cancel Terminal Payment` while polling.

Server-rendered mode and label are based on whether an open payment is being resumed (`includes/Gateway.php:249-256`). Client switching is handled by `setActionMode()` (`assets/js/payment.js:268-284`).

On cancel, the client:

1. Rejects duplicate clicks while a request is pending.
2. Stops polling.
3. Disables the primary action.
4. Shows a busy “Contacting…” status.
5. Calls `mtfwc_cancel_payment`.

See `assets/js/payment.js:513-529`.

## Server confirmation strategy

Cancellation never assumes that `DELETE` succeeded:

1. Fetch current payment state first.
2. If already final, reconcile that state immediately.
3. If non-final and cancelable, send cancellation.
4. Ignore cancellation exceptions caused by a completion race or transient failure.
5. Fetch the payment again.
6. Reconcile only the post-cancel authoritative state.

See `includes/Services/MolliePaymentService.php:87-110`.

This is a strong Square pattern: **the cancellation response is not the final truth; fetch status again**.

## Edge cases

### Payment completed during cancellation

If the fetched or re-fetched payment is paid, the reconciler completes the order, and the UI redirects rather than saying “canceled” (`assets/js/payment.js:530-533`; `includes/PaymentReconciler.php:24-25`, `48-60`).

The same handling is used for the race where the cashier switches to cash exactly as the terminal approves (`assets/js/payment.js:580-605`).

### Offline or unresponsive terminal

When Mollie keeps the payment open or reports `isCancelable=false`, the plugin:

- Marks the historical attempt `abandoned`.
- Preserves its payment ID in `_mtfwc_abandoned_payment_ids`.
- Clears the current-attempt pointer.
- Returns the panel to idle so a fresh attempt can begin.
- Leaves webhook/cron to resolve the old payment later.

Server implementation: `includes/Services/MolliePaymentService.php:112-121`; `includes/PaymentAttempt.php:69-110`.

This is probably the most valuable production learning in the repository. A real cashier was previously forced to create an entirely new order when a powered-off terminal trapped the original attempt (`docs/feedback/2026-07-08-beta-feedback-plan.md:22-45`).

### Network failure

Failed cancel requests show an explicit support-oriented error and unlock the panel (`assets/js/payment.js:523-528`). A later Start does not blindly create a new provider payment: it fetches and reuses the existing non-final attempt (`includes/Services/MolliePaymentService.php:32-44`).

### Page closed mid-payment

A `pagehide` listener sends a best-effort cancel beacon (`assets/js/payment.js:698-725`). It is deliberately treated as unreliable; server cron is the backstop.

### Order paid another way or canceled

An order-status hook watches transitions to `processing`, `completed`, `cancelled`, or `failed`, and cancels any still-open Mollie payment (`includes/PaymentCleanup.php:38-58`). It uses an 8-second API timeout so a provider outage cannot stall checkout for the normal 30 seconds (`includes/PaymentCleanup.php:28-34`).

### Server-side stale cleanup

- Cron runs every 10 minutes (`includes/PaymentSweeper.php:21-47`).
- Stale threshold defaults to 10 minutes and is filterable (`includes/PaymentSweeper.php:57-60`).
- Batch defaults to 25 orders (`includes/PaymentSweeper.php:63-76`).
- It handles both current attempts and locally abandoned IDs, regardless of current order status (`includes/PaymentSweeper.php:67-77`).
- If an abandoned payment later proves paid, it completes the order instead of discarding it (`includes/Services/MolliePaymentService.php:153-172`).

---

# 3. Cashier-facing UI

## Status display

- Status is rendered as a banner with `role="status"` and `aria-live="polite"` (`includes/Gateway.php:257`).
- Semantic levels map to distinct info/success/error/warning styles (`assets/css/payment.css:205-226`).
- `data-mtfwc-busy=true` adds a spinner (`assets/css/payment.css:228-244`).
- Main states include idle, sending, waiting, completing, canceled, abandoned, timeout, and request failure (`includes/Gateway.php:307-329`).

## Button and selector states

- A request-pending attribute prevents duplicate Start/Cancel submissions (`assets/js/payment.js:476-490`, `513-520`).
- The terminal selector locks while a payment is active and is only re-enabled if it is not administratively locked, still loading, or unavailable (`assets/js/payment.js:302-318`).
- A removed/default terminal does not silently select the first physical device; the cashier must choose explicitly (`assets/js/payment.js:640-675`).
- When a terminal list recovers after being empty, the stale “unavailable” marker is removed so the selector can be enabled again (`assets/js/payment.js:648-658`).

## Logs

The checkout log panel:

- Stores logs in `sessionStorage`, scoped by order ID (`assets/js/payment.js:44-53`, `131-155`).
- Retains only the newest 50 lines (`assets/js/payment.js:48-53`).
- Shows timestamp, severity, action, and a reduced response summary (`assets/js/payment.js:89-136`).
- Supports Show/Hide, Copy, and Clear (`assets/js/payment.js:735-784`).
- Is hidden by default and exposed only through the “Checkout debug logs” setting (`includes/Gateway.php:59-66`, `263-281`).

## Error-message strategy

Cashiers receive short actionable messages—retry, select a terminal, copy logs, or start another method—while technical details stay in logs (`includes/Gateway.php:315-329`).

Premature order submission is a non-error WooCommerce notice explaining that the terminal has not confirmed yet (`includes/Gateway.php:342-366`).

## i18n

- PHP labels and localized JS status strings use the plugin text domain (`includes/Gateway.php:307-330`).
- Translation files are loaded on `init` (`mollie-terminal-for-woocommerce.php:61-64`).
- A POT and Dutch translation are shipped.
- **Gap:** diagnostic log prose inside `payment.js` remains hardcoded English, e.g. `assets/js/payment.js:157`, `209`, `374`, `478`. Cashier-facing statuses are localized; support logs are not fully localized.

## Theme isolation

The CSS explicitly documents hostile WooCommerce/theme rules and anchors selectors on a doubled container ID with `!important` overrides (`assets/css/payment.css:1-14`, `16-41`). This arose because real store themes can distort embedded gateway buttons and selectors.

For Square, maintain a hostile-theme visual harness rather than assuming dashboard CSS isolation.

---

# 4. Settings and admin UX

## Test versus live

- Settings expose Test/Live mode (`includes/Gateway.php:27-44`).
- Test mode shows an inline warning that Mollie terminals only exist on live accounts (`includes/Gateway.php:153-161`).
- Terminal validation checks environment before dispatch (`includes/Services/TerminalService.php:25-31`).

## Credentials

API key source can be:

1. This plugin’s own password field.
2. The official Mollie WooCommerce plugin’s mode-matched key.

If the shared key is missing, it falls back to the locally stored key rather than breaking checkout (`includes/Settings.php:21-45`). Diagnostics show the selected source and whether an effective key exists, never the key itself (`includes/Gateway.php:163-170`).

### Gap

There is no explicit “Test credentials” button or save-time credential validation. Fetching terminals is the implicit validation flow; on API failure, the default-terminal field quietly falls back to free text (`includes/Gateway.php:69-90`, `121-150`).

For Square, add a deliberate credential/location/device validation action with a clear success or error notice.

## Terminal configuration defenses

- Terminal lists are fetched live so users select IDs rather than paste them (`includes/Gateway.php:69-90`).
- Inactive/disabled terminals are removed (`includes/AjaxHandler.php:67-81`).
- The list is cached for five minutes and fetched with an 8-second timeout (`includes/Gateway.php:130-150`).
- Cache is keyed by mode and cleared on settings save (`includes/Gateway.php:287-293`).
- If terminal fetching fails, the enabled-terminals field is omitted rather than rendered empty, preventing a temporary API outage from wiping saved selections (`includes/Gateway.php:92-110`).
- The default terminal is always added to the allowlist, preventing locked checkout from becoming unusable (`includes/Settings.php:51-69`).
- Terminal selection and allowlists are enforced server-side, not merely through disabled HTML (`includes/AjaxHandler.php:19-28`; `includes/Services/TerminalService.php:19-31`).

## Legacy pairing endpoint

An authenticated pairing-code AJAX endpoint remains (`includes/AjaxHandler.php:101-106`), but `assets/js/admin.js:1` is empty and no settings button invokes it. It also depends on the removed Profile ID path (`includes/Services/TerminalService.php:18`).

Do not reproduce this orphaned helper in Square; either expose and support pairing fully or remove the endpoint.

---

# 5. Webhooks

## Registration

There is no dashboard registration lifecycle. The webhook URL is included automatically in every payment creation payload (`includes/Services/MolliePaymentService.php:49-58`). The endpoint itself is an authenticated/unauthenticated WordPress AJAX action (`includes/WebhookHandler.php:7-12`).

## Signature verification

**None is implemented.** The handler accepts a payment ID from POST or GET (`includes/WebhookHandler.php:15-20`).

Instead, it treats the incoming ID only as a notification hint and fetches the authoritative payment using the configured API key (`includes/WebhookHandler.php:21-30`). The reconciler then verifies payment/order ID association, amount, currency, method, and environment (`includes/PaymentReconciler.php:35-45`).

This is sensible for Mollie’s notification model, but it is not sufficient for Square. Square’s signature mechanism should be verified before any provider lookup or order mutation.

## Idempotency and deduplication

There is no webhook-event ID store or explicit event deduplication. Idempotency comes from state reconciliation:

- Already-paid order with the same transaction ID returns idempotent success.
- Already-paid order with another transaction records a conflict and does not complete twice.

See `includes/PaymentReconciler.php:48-60`.

## Retries and out-of-order delivery

Out-of-order payload data cannot rewind local state because payload status is ignored; every webhook fetches current provider state. An old abandoned payment also cannot overwrite the current attempt pointer because status updates only change the pointer when payment IDs match (`includes/PaymentAttempt.php:49-66`).

However:

- Unknown payments return HTTP 200 (`includes/WebhookHandler.php:25-28`).
- Missing IDs return 200 (`includes/WebhookHandler.php:16-20`).
- Exceptions are logged but still return 200 (`includes/WebhookHandler.php:32-35`).

Therefore Mollie will not be prompted to retry transient failures. Polling and cron may recover them, but a Square implementation should return retryable non-2xx responses for genuine transient errors.

---

# 6. Order state management

## Payment meta keys

Defined in `includes/PaymentAttempt.php:5-11`:

| Key | Purpose |
|---|---|
| `_mtfwc_current_attempt_id` | Current local attempt UUID |
| `_mtfwc_current_payment_id` | Current Mollie payment ID |
| `_mtfwc_current_terminal_id` | Selected terminal |
| `_mtfwc_current_payment_status` | Cached remote status |
| `_mtfwc_current_payment_created_at` | Staleness timestamp |
| `_mtfwc_payment_attempts` | Historical attempt records |
| `_mtfwc_abandoned_payment_ids` | Detached remote payments still needing reconciliation |

New attempts store amount, currency, mode, timestamps, payment and terminal IDs (`includes/PaymentAttempt.php:25-46`). Historical records remain available while their statuses are updated in place.

## Status transitions

- `paid`: set transaction ID and call WooCommerce `payment_complete()` (`includes/PaymentReconciler.php:48-60`). WooCommerce decides whether the order becomes processing or completed.
- `failed`, `canceled`, `expired`: add an order note and allow retry; the plugin does not force a WooCommerce failed/canceled status (`includes/PaymentReconciler.php:27-32`).
- Verification failure: add an order note and refuse completion (`includes/PaymentReconciler.php:19-23`).
- Open/pending/authorized: retain the current attempt and keep polling.

## Double-completion guards

- Starting a new payment immediately returns `already_paid` if the order is paid (`includes/Services/MolliePaymentService.php:27-31`).
- Active provider attempts are fetched and reused rather than duplicated (`includes/Services/MolliePaymentService.php:32-44`).
- Reconciliation checks `order->is_paid()` and transaction ID before completion (`includes/PaymentReconciler.php:48-55`).
- Create, cancel, abandoned cleanup, and refunds use per-operation locks (`includes/PaymentLock.php:7-28`).

### Locking caution

These are transient-based, operation-specific locks. Webhook and poll reconciliation are not locked, and `get_transient()` followed by `set_transient()` is not a database-atomic compare-and-set. Square should use provider idempotency keys plus a stronger single-order mutation lock.

## Refunds

Refund meta keys (`includes/RefundReconciler.php:9-12`):

- `_mtfwc_refund_attempt_id`
- `_mtfwc_mollie_refund_id`
- `_mtfwc_refund_status`
- `_mtfwc_refund_amount`

Patterns:

- Refunds are order-locked.
- An existing Mollie refund ID short-circuits duplicate processing.
- Remote payment and refund lists are fetched first.
- Refund metadata includes order ID, Woo refund ID, and attempt ID.
- Retries search Mollie for matching metadata before creating another refund.
- Remaining refundable value is calculated using integer minor-unit money helpers.

See `includes/RefundReconciler.php:16-52`.

### Refund cautions

- Over-refund protection relies indirectly on `Money::subtract()` throwing when the requested amount exceeds the remaining value (`includes/RefundReconciler.php:33-35`; `includes/Utils/Money.php:21-27`). This deserves a clear named comparison.
- `RefundHandler` creates the WooCommerce refund before calling Mollie, and contains no rollback when the remote call fails (`includes/RefundHandler.php:10-15`).
- There is no webhook-based refund-status reconciliation; the immediate returned status is merely cached.

---

# 7. Logging

## Server logs

All durable diagnostics go to WooCommerce status logs under source `mollie-terminal-for-woocommerce` (`includes/Logger.php:4-16`, `53-62`).

Logged areas include:

- AJAX operation receipt/completion/failure (`includes/AjaxHandler.php:109-135`).
- Terminal listing.
- API success, transport errors, and HTTP errors (`includes/Services/MollieApiClient.php:28-55`).
- Payment creation, reuse, polling, cancellation, abandonment, and sweeps.
- Webhook receipt/reconciliation/failure.
- Refund failure.
- Automatic order-state cleanup.

Logging can be disabled through the `mtfwc_logging` filter (`includes/Logger.php:38-41`).

## Redaction

Server redaction:

- Masks `Bearer` credentials and long `test_`/`live_` API keys.
- Recursively masks context keys containing key, token, secret, authorization, password, or bearer.
- Truncates messages to 1,000 characters.

See `includes/Logger.php:70-95`.

Browser redaction additionally treats metadata, customer, and email keys as sensitive and reduces API results to a summary (`assets/js/payment.js:4-5`, `56-114`).

For Square, use the broader browser rule set on the server too, especially for customer and card-related fields.

## Where logs surface

- Durable: WooCommerce → Status → Logs; admin diagnostics links directly there (`includes/Gateway.php:171-176`).
- Cashier/support: optional on-panel log textarea (`includes/Gateway.php:263-280`).
- Legacy option-backed diagnostic records are deleted when settings are opened (`includes/Gateway.php:181-188`).

The move away from `wp_options` was deliberate: the old capped-array store caused option bloat and had no atomic append under concurrent AJAX/webhook traffic (`CHANGELOG.md:28-38`).

---

# 8. Other production lessons and defensive guards

1. **Out-of-band success must redirect directly.** Re-submitting an already-paid order hit WooCommerce’s “already paid” guard and trapped POS in a loop. The plugin now returns a POS-aware thank-you URL (`assets/js/payment.js:425-462`; `includes/AjaxHandler.php:84-99`, `124-130`).

2. **Use real DOM semantics in tests.** Assigning to `select.options` worked in a fake harness but throws in browsers; terminal dropdown construction now uses `createElement`, `appendChild`, and `replaceChildren` (`assets/js/payment.js:640-693`).

3. **Never silently choose a physical device.** If the configured default is absent, require explicit selection instead of using the first terminal (`assets/js/payment.js:659-674`).

4. **Ignore stale removed settings.** An old Profile ID is deliberately ignored so upgraded stores are not stuck querying a now-uneditable profile (`includes/Services/TerminalService.php:11-16`).

5. **Bound synchronous admin API work.** Terminal fetching uses an 8-second timeout and five-minute cache so a provider outage does not hang WordPress admin (`includes/Gateway.php:130-150`).

6. **Keep minimum-runtime compatibility real.** The code declares PHP 7.4 and avoids PHP 8-only helpers; `str_starts_with()` was replaced with a PHP 7.4-safe character check (`includes/Utils/Money.php:52-60`).

7. **Do not expose arbitrary terminal IDs from the browser.** Nopriv AJAX is authorized by staff capability or an order-specific token checked with `hash_equals()` (`includes/AjaxHandler.php:139-145`).

8. **Payment payloads need regression coverage.** API-key payments must send `redirectUrl` and must not send obsolete `profileId` (`includes/Services/MolliePaymentService.php:49-57`).

9. **Scope money support conservatively.** Terminal payments are explicitly EUR-only until broader provider support is verified (`includes/Utils/Money.php:36-39`).

---

# Recent fix/bug history and lessons

## Confirmed live-user feedback

| Commit | What it fixed | Lesson for Square |
|---|---|---|
| `93b5116` | v0.4 recovery work based on live beta feedback: offline terminal dead-end, refresh recovery, stale cron sweep, one-button UX, method-switch cancellation, i18n | Design the “terminal never responds” path before happy-path polish. A cashier must always regain control without replacing the order. |
| `5955f83` | Abandoned payment IDs became invisible to the sweeper after clearing the current pointer; method-switch cancellation could return paid | Detached remote resources need a separate durable index. Every cancel response must be treated as a possible late success. |
| `a4b2f00` | Paid-order redirect loop, terminal curation, cleanup and UI hardening | Terminal confirmation is out of band; do not re-run the normal synchronous checkout submission path afterward. |
| `036c9a5` / issue `#5` | Diagnostics in `wp_options` caused bloat and unsafe concurrent capped-array writes | Logs belong in WooCommerce’s logger, not mutable option arrays. |

The direct tester report is preserved at `docs/feedback/2026-07-08-beta-feedback-plan.md:1-5`, with the stuck-terminal case at `22-45` and lingering open payments at `47-66`.

## Review- or regression-discovered fixes

| Commit | Lesson |
|---|---|
| `3cbf72f` | Cancellation needs the same HTTP/network failure guards as payment creation; never leave the UI frozen on “Contacting…”. |
| `5390e32` | Test DOMs must match browser constraints; ignore stale migrated settings; never silently choose a replacement terminal; localize all cashier statuses. |
| `c56b6bd` | Clear stale UI state markers when data recovers, or a selector can remain disabled after the underlying problem disappears. |
| `2ad5a0d` | Gateway UI runs inside hostile theme/WooCommerce CSS; verify under adversarial styles. |
| `36c245f` | Do not perform uncached 30-second provider calls during every settings render. |
| `01b0a9b` | CI must exercise the declared minimum PHP version, not just the developer runtime. |
| `fda5e6d` | Deduplicate click bindings after `updated_checkout`, block concurrent requests, bound logs, redact them, and test JavaScript separately. |
| `2632f97` | Avoid recording the same failure through multiple logging layers; duplicate diagnostics hide the useful signal. |
| `b237e69` | Provider payload fields differ by authentication flow; verify documented payload shape with a captured-request regression test. |
| `285ba2d` | Test/documentation cleanup only; no runtime production behavior changed. |
| `e12f8ee` | Release-workflow hardening only; not a payment-runtime lesson. |

---

# `git log --oneline -40`

The exact requested command on the checked-out branch returned:

```text
89e6363 docs: triage 2026-07-08 beta feedback into actionable plan
22421ea Merge pull request #9 from wcpos/worktree-mollie-diagnostics-to-wc-logs
cf26d6b Merge pull request #8 from wcpos/worktree-mollie-terminal-v0.3.0
3a98174 refactor: match the sibling terminal plugins' Logger pattern
036c9a5 fix: log diagnostics to WooCommerce status logs, not wp_options (#5)
33a4e01 docs: add 0.3.0 release notes (and backfill 0.2.0), sync CHANGELOG
5923fba Merge pull request #7 from wcpos/worktree-mollie-terminal-v0.3.0
c56b6bd fix: address review findings (CSS keyword casing, stale select marker)
2ad5a0d fix: harden panel CSS against theme/WooCommerce style leakage
01b0a9b fix: keep money utility compatible with PHP 7.4
a4b2f00 feat: v0.3.0 — thank-you redirect, terminal curation, stale-payment cleanup, UI polish
f1684fd Merge pull request #6 from wcpos/worktree-mollie-terminal-auto-flow
36c245f perf: cache settings terminal list + bound the fetch timeout (Greptile P1)
3cbf72f fix: guard onCancel against failed requests (Greptile P1)
5390e32 fix: address PR review findings (dropdown DOM, profile fallback, status)
2e65196 feat: auto-complete terminal flow, live terminal dropdown, drop Profile ID
4af28bb Merge pull request #4 from wcpos/codex/mollie-diagnostics-panel
285ba2d fix: address review nits in diagnostics tests
fda5e6d fix: harden payment log panel interactions
2632f97 fix: avoid noisy duplicate diagnostics
8fac303 feat: add Mollie terminal diagnostics panel
449d4db docs: add 0.1.1 release notes
c5fb0d4 Merge pull request #3 from wcpos/codex/mollie-payment-redirect-url
4174797 chore: bump version to 0.1.1
b237e69 fix: align Mollie terminal payment payload
7b50f39 Merge pull request #2 from wcpos/add-release-notes-changelog
1d98707 docs: add initial release notes
4bc707f Merge pull request #1 from wcpos/add-release-workflow
e12f8ee Harden release workflow
71782ed Add automated release workflow
1220ca3 Initial Mollie Terminal WooCommerce plugin
```

The fetched `origin/main` additionally contains:

```text
aa6f56a Merge pull request #10 from wcpos/worktree-mollie-terminal-v0.4.0
5955f83 fix: keep abandoned payments sweepable and handle paid cancels on method switch
93b5116 feat: v0.4.0 — stuck-payment recovery, panel redesign, i18n, key reuse
```

## Behavior changes / regressions

None introduced; this was a read-only code and history review. Runtime behavior was not executed or independently verified.
