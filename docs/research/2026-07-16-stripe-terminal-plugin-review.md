# Stripe Terminal → Square Terminal production-pattern review

## Scope and evidence

**Observed:** Read-only review of the current code, tests, design notes, changelog, and `git log --oneline -40`.  
**Not evaluated:** Live WordPress, WooCommerce, Stripe hardware, or webhook delivery.  
**Important architecture note:** The active payment UI is the jQuery/AJAX implementation. The older React/`terminal-js` frontend is explicitly disabled; the REST API remains primarily for webhooks (`stripe-terminal-for-woocommerce.php:64-85`).

---

## Executive takeaways for the Square sibling

1. Treat webhooks as an accelerator and audit trail, not the sole source of truth. Poll the provider directly while a cashier is waiting.
2. Only complete the WooCommerce order after an unambiguous provider success plus a paid charge/payment record.
3. Reader state is separate from payment state. Cancellation and recovery must reconcile both.
4. Never automatically take over a shared terminal that is processing a different payment.
5. Before force-clearing a terminal, re-read its state and compare the currently active payment ID.
6. Hardware freshness timestamps are diagnostic—not authoritative proof that a command failed.
7. Persist provider IDs and statuses on the order early so browser reloads and manual reconciliation work.
8. Design explicitly for POS authentication that does not share the browser’s WordPress cookie/nonce session.
9. Preserve saved credentials without rendering them, and validate mode, format, account readiness, and permissions separately.
10. Do not copy this plugin’s weaker areas: missing webhook deduplication, no payment-creation idempotency key, incomplete cancellation confirmation, fixed aggressive polling, public legacy REST routes, and incomplete frontend i18n.

---

# 1. Payment status polling

## Current behavior

| Concern | Implementation | Square lesson |
|---|---|---|
| Interval | Fixed **2-second** `setInterval` (`packages/payment-frontend/src/payment.js:352-398`). | Fast feedback is appropriate during a cashier-attended flow. |
| Backoff | **None.** Network errors are logged to the console and polling continues at 2 seconds (`payment.js:395-397`). | Add backoff/jitter for repeated transport errors while keeping short intervals for healthy requests. |
| Maximum | A separate **300,000 ms / 5-minute** timer stops polling (`payment.js:400-411`). There is no explicit attempt counter—roughly 150 timer firings. | Use both an elapsed deadline and an attempt/error budget. |
| Provider lookup | Every poll reads local order state and retrieves the specific PaymentIntent from Stripe (`includes/AjaxHandler.php:481-516`). | Poll a known provider payment ID; do not repeatedly search a list. |
| Decline detection | Stops when Stripe reports `requires_payment_method` plus `last_payment_error` (`payment.js:381-383`; `AjaxHandler.php:497-507`). | Return structured provider status and decline detail to the UI. |
| Manual recovery | “Check Payment Status” performs a deeper Stripe lookup (`payment.js:651-705`; `AjaxHandler.php:556-622`). | Always provide a cashier-triggered reconciliation action after uncertainty or reload. |

The issue-linked design explains why direct provider polling was added: polling local order metadata alone left the POS stuck after a card decline; the reader showed “declined,” but WooCommerce never learned about it (`docs/plans/2026-02-28-card-decline-detection-design.md:6-31`).

## How polling coexists with webhooks

The system deliberately has multiple reconciliation paths:

1. Webhooks save success/failure metadata on the order (`includes/API.php:351-400`, `430-595`).
2. The poll reads that local metadata **and** asks Stripe directly (`includes/AjaxHandler.php:481-516`).
3. On success, the frontend submits the WooCommerce form (`payment.js:708-748`).
4. `Gateway::process_payment()` revalidates:
   - already-paid order;
   - successful stored metadata with both PaymentIntent and charge;
   - or a direct Stripe check requiring a paid charge and succeeded PaymentIntent (`includes/Gateway.php:316-395`).

This protects the cashier flow from delayed or missing webhooks and protects order completion from an optimistic browser.

## What stops the poll

`stopPolling()` clears the status interval, five-minute deadline, and reader-verification timer (`payment.js:489-502`). It is called on:

- provider/local success (`payment.js:378-380`);
- card decline (`381-383`);
- failed/cancelled Woo order status (`384-393`);
- explicit cashier cancellation (`571-575`);
- five-minute timeout (`400-411`);
- starting a replacement poll clears the prior interval/deadline (`352-361`).

## Hard-won hardware lesson

A secondary check runs 15 seconds after dispatch and compares the reader’s `last_seen_at` timestamp (`payment.js:504-568`). Originally, unchanged timestamps caused the plugin to cancel a valid payment. That was reversed because idle smart readers can accept commands while reporting stale timestamps. The current behavior logs the check as inconclusive and continues polling the authoritative payment status (`payment.js:554-568`; `StripeTerminalService.php:219-246`).

## Gaps not to copy

- `setInterval(async ...)` can overlap requests if an AJAX call takes longer than two seconds.
- No exponential backoff, jitter, page-visibility handling, or consecutive-error counter.
- On five-minute timeout, the UI forgets the active payment but does **not** cancel or reconcile the remote payment/reader (`payment.js:400-410`). For Square, transition to **unknown/reconciliation required**, not “safe to start over.”
- The poll checks `data.status === cancelled`, but `data.status` is the WooCommerce order status, not the provider payment status (`AjaxHandler.php:481-484`, `payment.js:384`). There is no explicit terminal branch for a provider payment whose own status is `canceled`.
- There is no page-unload cleanup or resume-from-order-meta flow.

---

# 2. Payment cancellation

## Cashier initiation

The cancel button is initially hidden and appears whenever `currentPaymentIntent` exists (`includes/Gateway.php:479-499`; `payment.js:1180-1203`).

On click, the frontend:

1. Stops polling immediately.
2. Sends the PaymentIntent ID, order ID, and the reader ID captured when payment began.
3. On success, clears local state and re-enables “Pay with Terminal.”
4. On failure, leaves the payment state present and shows an error (`payment.js:311-350`, `571-599`).

Capturing `activePaymentReaderId` at payment start is important: cancellation targets the reader that received the command, not whichever reader happens to be selected later (`payment.js:92-105`, `311-319`).

## Server-side cancellation

The AJAX handler authenticates the order request, then:

1. Calls terminal `cancelAction` to clear hardware state.
2. Retrieves and conditionally cancels the PaymentIntent.
3. Returns the PaymentIntent data (`includes/AjaxHandler.php:262-326`).

The provider intent is only cancelled in cancellable pre-completion states: `requires_payment_method` or `requires_confirmation` (`StripeTerminalService.php:367-380`).

Reader cancellation normalizes two real-world edge cases:

- `terminal_reader_busy`: authorization is already underway; returns `{status: busy}` rather than a generic failure.
- `resource_missing`: there is no reader action; returns `{status: idle}` (`StripeTerminalService.php:383-418`).

## Stale terminal recovery

Before dispatching a new payment, the service reads the terminal action:

- failed action → automatically clear;
- different non-active/stale payment → automatically clear;
- different `in_progress` payment → do **not** cancel; return structured `reader_busy` data (`StripeTerminalService.php:232-304`).

The frontend then offers a clearly dangerous **Force clear terminal** action with human confirmation (`payment.js:158-225`).

Most importantly, the force-clear endpoint re-fetches reader state and verifies that the active payment ID still equals the ID shown in the warning. If it changed, it refuses to cancel (`AjaxHandler.php:792-857`). This prevents clearing a newer payment started on another register. The associated design rationale is explicit (`docs/superpowers/specs/2026-05-01-terminal-recovery-security-fixes-design.md:29-101`).

## Cancellation weaknesses to fix in Square

- The frontend ignores the returned PaymentIntent status. A succeeded PaymentIntent is returned as a successful AJAX response, and the UI can still announce a cancellation request.
- Cancellation is not followed by polling/retrieval until a terminal provider state is confirmed.
- The UI stops polling **before** cancellation succeeds. A network failure therefore leaves the remote payment active but the browser no longer monitoring it.
- Reader cancellation and payment cancellation are not atomic:
  - reader clear can succeed while payment cancellation fails;
  - reader cancellation can return `busy`, yet the handler still proceeds to cancel the intent.
- Normal cancellation does not write cancellation status/order notes to the WooCommerce order.
- The cancel button itself is not disabled while its request is in flight, allowing duplicate clicks.

For Square, cancellation should be a reconciliation state machine: `cancel_requested → terminal_cancel_checked → payment_status_checked → canceled | completed | unknown`.

---

# 3. Cashier-facing UI

## Status and reader display

The server emits:

- loading state;
- reader list;
- selected reader details;
- pay/cancel/retry/simulate/manual-status buttons;
- collapsible log area (`includes/Gateway.php:420-520`).

Reader cards show label, device, serial number, online/offline status, and last-seen time. Offline readers have disabled connect buttons (`payment.js:980-1017`; server equivalent `Gateway.php:862-915`).

The last selected reader is kept in `localStorage`, but is restored only if it still exists and is online (`payment.js:1036-1054`).

## Button state model

- **Pay:** disabled immediately during creation/processing; re-enabled after failure or timeout (`payment.js:89-127`, `400-410`).
- **Cancel:** visible whenever an active intent exists (`1180-1203`).
- **Try Another Card:** visible only after a decline while retaining the existing intent (`414-486`, `1199-1203`).
- **Simulate:** visible only for simulated readers with an active, non-declined payment (`1183-1191`).
- **Check Payment Status:** cashier-controlled reconciliation helper; it disables itself while checking (`651-705`).
- **Force clear:** only added for structured reader-busy recovery; requires confirmation and disables itself during the request (`167-225`).
- **Connect:** disabled for offline readers (`980-1017`).

A strong pattern for Square is retaining the same provider payment object after a decline and reprocessing it for another card, rather than generating a new payment for every attempt (`payment.js:427-486`; `AjaxHandler.php:715-782`).

## On-screen payment log

The UI appends timestamped, level-prefixed entries to a readonly textarea and auto-scrolls it (`payment.js:803-816`). Cashiers can show/hide or clear it (`Gateway.php:503-515`; `payment.js:1164-1177`, `1208-1214`).

This is useful for support calls because it exposes the sequence without requiring WooCommerce admin access.

## Error messages and i18n

Good patterns:

- Core UI labels and payment-state strings are localized server-side and passed through `wp_localize_script` (`Gateway.php:567-607`).
- PHP-rendered fields use `__()`, `esc_html__()`, and related escaping.
- Blocks scripts register translations (`Blocks/StripeTerminalBlocksSupport.php:96-108`).
- Nonce errors tell the cashier to refresh or reopen checkout rather than saying only “Invalid request” (`AjaxHandler.php:917-940`).

Gaps:

- Many dynamic JS strings remain hard-coded English: force-clear, retry, simulator, manual status, reader labels, and success messages (`payment.js:167-225`, `427-486`, `601-705`, `980-1017`, `1164-1177`).
- `showStatusMessage()` logs every message but visually renders only errors (`payment.js:785-801`). Success and informational messages are intentionally log-only.
- **Observed static gap:** `Gateway::payment_fields()` does not emit `.stripe-terminal-error`, `.stripe-terminal-success`, or `.stripe-terminal-info` elements, while JS expects them (`Gateway.php:420-520`; `payment.js:789-799`). Unless external markup supplies them, visible errors may not render. This was not live-tested.
- JS inserts provider-controlled reader labels/device data through HTML templates without escaping (`payment.js:999-1017`, `1132-1137`). Square reader names should be inserted with text nodes, not raw HTML.

---

# 4. Settings and admin UX

## Test versus live mode

Settings keep separate:

- live secret key;
- test secret key;
- live webhook secret;
- test webhook secret (`Gateway.php:99-125`; `Settings.php:25-43`).

The selected API key and webhook secret follow `test_mode` (`Gateway.php:55-58`; `Settings.php:25-43`).

Live mode is automatically disabled on non-HTTPS sites: settings are changed to test mode and an admin warning is shown (`Gateway.php:688-704`). The test-mode field also carries an inline warning when SSL is absent (`Gateway.php:119-125`).

## Credential validation flow

Validation is layered:

1. Enforce test/live prefix consistency.
2. Accept both standard (`sk_*`) and restricted (`rk_*`) keys.
3. For standard keys, retrieve the Stripe account and check `charges_enabled`.
4. Return inline green/red status markup.
5. Only valid non-restricted keys proceed to automatic webhook setup (`Gateway.php:924-1043`).

Restricted keys receive an explicit warning that Terminal and PaymentIntent permissions are required (`Gateway.php:992-999`). They are not used to auto-manage webhooks, avoiding a guaranteed permission failure (`Gateway.php:935-943`).

## Secret handling

Custom password fields:

- never put the saved value back into HTML;
- show a masked “saved; leave blank to keep” placeholder;
- preserve the stored secret when the submitted field is blank (`Gateway.php:250-306`).

This addresses screenshot/shoulder-surfing leakage documented in issue #45 (`docs/superpowers/specs/2026-05-01-terminal-recovery-security-fixes-design.md:120-133`).

## Helper UX

The admin page fetches and displays configured terminal locations, addresses, and reader counts. If none exist, it links directly to setup documentation (`Gateway.php:200-225`, `1052-1111`).

There is no explicit “Validate credentials” or “Register webhook” button. Instead, rendering a valid key’s description performs validation and may create the webhook (`Gateway.php:234-247`, `924-943`). That is convenient but makes a settings-page GET effectively mutate remote state.

## Settings gaps to avoid

- Keep validation and remote mutation behind explicit actions/buttons in Square.
- Restricted-key format is accepted without actually proving required permissions.
- If an existing Stripe webhook is found, its secret cannot be recovered; the code reports it active but leaves a TODO for secret repair (`Gateway.php:724-737`).
- Webhook errors expose raw provider exception text inline (`Gateway.php:765-769`).
- There is no warning for mode switches invalidating the currently selected webhook secret.

---

# 5. Webhooks

## Registration

Webhook setup is automatic for valid standard API keys. It:

1. Lists existing endpoints.
2. Matches the exact REST URL.
3. Creates one if absent.
4. Subscribes to:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
5. Stores the returned secret in the appropriate live/test setting (`Gateway.php:714-764`).

Because Stripe exposes a signing secret only at creation, existing endpoints create a recovery problem explicitly acknowledged in the code (`Gateway.php:732-737`).

## Signature verification

The active REST callback reads the raw body and `stripe-signature` header, selects the mode-specific secret, and calls `Stripe\Webhook::constructEvent()` (`API.php:351-359`; `Settings.php:35-43`).

Signature/parsing errors return HTTP 400 through `WP_Error` (`API.php:395-400`).

## Event handling

Handled event types:

- `payment_intent.succeeded`
- `charge.succeeded`
- `payment_intent.payment_failed` (`API.php:360-379`).

Success events save provider metadata and order notes but intentionally do not call `payment_complete()` (`API.php:430-547`). The gateway completes the order later after checkout/form submission.

Failed events save error metadata and refuse to overwrite an already-paid or previously succeeded order (`API.php:555-595`).

## Idempotency, retries, and event ordering

### Present protections

- A stale failure arriving after success is ignored (`API.php:568-573`).
- Missing order IDs and missing orders are logged.
- Order completion has separate guards in the gateway.

### Missing protections

- No webhook-event ID is stored.
- No event deduplication table/meta exists.
- Duplicate success events can create duplicate order notes.
- Failure events are not compared with the order’s current PaymentIntent ID; a late failure from an older attempt can mark the order failed while a newer attempt is active.
- Most handler failures are swallowed and the endpoint still returns success. Missing orders or transient provider retrieval failures therefore will not request a webhook retry (`API.php:430-442`, `483-513`).
- The auto-created endpoint does **not** subscribe to `charge.succeeded`, although the handler contains that case (`Gateway.php:739-743`; `API.php:368-371`).
- The charge handler calls `Settings::get_secret_key()`, which does not exist in `Settings`; only `get_api_key()` is defined (`API.php:493-495`; `Settings.php:13-44`). This dormant path should not be copied.
- There is no timestamp/version monotonicity check.

For Square: store provider event ID, event creation time, object ID, and terminal status; process each event once; compare attempts; make success monotonic; and return retryable errors for transient failures.

---

# 6. Order state management

## Primary order metadata

| Key | Purpose | References |
|---|---|---|
| `_stripe_terminal_payment_intent_id` | Provider payment identity | `AjaxHandler.php:141-151`; `API.php:445-458` |
| `_stripe_terminal_charge_id` | Paid charge/transaction identity | `API.php:516-530`; `StripeTerminalService.php:663-680` |
| `_stripe_terminal_payment_status` | `succeeded` or `failed` | `API.php:445-458`, `568-582` |
| `_stripe_terminal_payment_amount` | Provider minor-unit amount | `API.php:448`, `520` |
| `_stripe_terminal_payment_currency` | Provider currency | `API.php:449`, `521` |
| `_stripe_terminal_payment_method` | `card_present` or MOTO `card` | `API.php:450-457`, `522-529` |
| `_stripe_terminal_moto` | MOTO marker | same references |
| `_stripe_terminal_payment_error` | Last decline/failure message | `API.php:575-582` |

Older REST/service paths also use WooCommerce/Stripe-compatible keys such as `_transaction_id`, `_stripe_currency`, `_stripe_charge_captured`, `_stripe_intent_id`, and `_stripe_card_type` (`API.php:314-325`; `StripeTerminalService.php:447-460`).

## Status transitions

1. Payment creation stores the payment ID; the order stays unpaid/pending (`AjaxHandler.php:130-161`).
2. Webhook success stores metadata but does not complete the order (`API.php:445-474`, `516-547`).
3. Poll success triggers WooCommerce form submission (`payment.js:708-748`).
4. Gateway completion requires:
   - stored `succeeded` plus PaymentIntent and charge ID; or
   - direct provider confirmation of a paid charge and succeeded intent (`Gateway.php:328-395`).
5. `payment_complete($charge_id)` lets WooCommerce choose processing/completed according to normal order contents (`Gateway.php:333-348`, `370-387`).
6. Direct order-pay submission without confirmed payment returns failure and a notice rather than looping back silently (`Gateway.php:397-407`).
7. Main checkout creates a pending order and redirects to order-pay; abandonment leaves the order pending (`Gateway.php:409-414`; `README.md:16-24`).

## Double-completion protections

- Immediate `is_paid()` return at the start of `process_payment()` (`Gateway.php:319-326`).
- Strict provider confirmation requires both a paid charge and succeeded PaymentIntent (`Gateway.php:361-369`).
- Charge webhook/service path checks `is_paid()` before changing state (`StripeTerminalService.php:817-824`).
- Duplicate paid POS submissions are redirected to the receipt flow only when POS context, gateway, order key, payment method, and paid state all match (`Gateway.php:149-195`).

There is no explicit database lock or compare-and-swap around concurrent completion, so the design still relies partly on WooCommerce’s `payment_complete()` idempotence.

## Refunds

No active `process_refund()` implementation, refund webhook handler, or refund order-state flow exists in `Gateway`, `API`, or `StripeTerminalService`. Refund-related names only appear in the disabled historical React Terminal configuration. The Square plugin must design refunds independently rather than copying this code.

---

# 7. Logging

## WooCommerce logs

`Logger` writes through `wc_get_logger()` with source `stripe-terminal-for-woocommerce` (`includes/Logger.php:15-65`).

Logged information includes:

- order ID and amount;
- PaymentIntent and reader IDs;
- dispatch, retry, cancellation, and recovery actions;
- webhook metadata updates;
- provider exception message/code;
- Stripe request ID and HTTP status (`AjaxHandler.php:130-194`; `StripeErrorHandler.php:27-98`).

The `stwc_logging` filter can suppress messages (`Logger.php:51-64`).

## On-screen logs

The cashier log shows timestamp, severity prefix, and operational messages in a collapsible textarea (`Gateway.php:503-515`; `payment.js:803-816`, `1164-1214`).

## Redaction and severity gaps

- There is no built-in redaction function.
- Stripe signature-verification errors can log the HTTP body and signature header (`StripeErrorHandler.php:87-98`).
- Arbitrary non-string objects are dumped with `print_r()` (`Logger.php:60-64`).
- Calls such as `Logger::log($message, 'warning')` do not actually select a level because `Logger::log()` accepts only one argument; the static level defaults to `info` (`API.php:433`; `Logger.php:46-64`).
- Raw provider exception messages are often returned to the cashier/admin.

Square should use structured fields, an allowlist, automatic secret/token/header redaction, and real per-call severity.

---

# 8. Other production learnings

## Recalculate the amount server-side

The AJAX endpoint ignores the posted amount and converts the current WooCommerce order total immediately before creating the PaymentIntent (`AjaxHandler.php:85-132`). This fixed retries after cart edits sending stale totals (`CHANGELOG.md:52-57`).

**Square rule:** never trust cached/client monetary amounts.

## POS authentication differs from browser checkout

WooCommerce POS renders as the order customer but submits AJAX as the cashier. The gateway temporarily switches to the POS cashier solely while creating the nonce, then restores the original user in `finally` (`Gateway.php:628-658`).

Desktop/mobile POS may authenticate with JWT instead of WordPress cookies, so the plugin also generates a one-hour HMAC-signed, order-scoped payment token (`PaymentRequestToken.php:13-61`) and accepts order-key authentication when the cookie nonce is unavailable (`AjaxHandler.php:943-988`).

Every order action additionally requires the correct order key and an order that still needs payment (`AjaxHandler.php:990-1005`).

## Shared terminals require compare-before-cancel

The most important production lesson is documented directly: automatically clearing another `in_progress` payment can cancel a legitimate customer payment on another register (`docs/superpowers/specs/2026-05-01-terminal-recovery-security-fixes-design.md:5-42`).

## Timeout recovery is targeted

A provider timeout or connection error triggers one sequence of:

1. cancel reader action;
2. if not busy, retry dispatch once;
3. return the final error (`StripeTerminalService.php:151-190`, `307-329`).

The issue was an S700 remaining stuck after ER400 until physically restarted (`docs/plans/2026-03-02-reader-timeout-recovery-design.md:1-33`).

## Checkout environments need explicit compatibility gates

- Classic payment assets are skipped on Blocks checkout where their markup does not exist (`Gateway.php:661-685`).
- Blocks support is disabled if the required JSX runtime is missing, with both version and actual-script-registry checks (`Blocks/StripeTerminalBlocksSupport.php:38-62`).
- A duplicate paid POS form submission is recovered before WooCommerce renders “already paid” (`Gateway.php:149-195`).

## Security gap: legacy public REST routes

Although the bootstrap says the REST API remains for webhooks, `API` still registers public connection-token, location, reader-registration, payment-creation, capture, and payment-method attachment endpoints with `permission_callback => __return_true` (`stripe-terminal-for-woocommerce.php:67-81`; `API.php:48-121`).

Only the webhook route should be public and signature-authenticated. Do not carry these legacy routes into Square.

## Payment creation lacks provider idempotency

`PaymentIntent::create()` is called without a provider idempotency key (`StripeTerminalService.php:123-131`). UI button disabling reduces ordinary double-clicks, but it does not protect against transport retries, duplicate requests, or multiple registers.

Square payment creation should use an idempotency key derived from order ID plus a persisted attempt identifier.

---

# Recent fix/bug commit lessons

Production-relevant commits inspected from the requested 40-commit window:

| Commit | What it teaches |
|---|---|
| `a68e283` | Never load a checkout integration merely because the gateway is enabled; first verify the runtime dependency actually exists. |
| `dae6f83` | POS/browser flows can double-submit after payment. Add a narrow, authenticated “already paid → receipt” recovery path rather than treating it as a payment error. |
| `a64b969` | Desktop POS may lack the WordPress nonce session. Carry order-bound auth on every status and reader-verification request, and recover the order key from the URL when localization is incomplete. |
| `68a0e83` | Provider reader freshness timestamps can be stale while commands still work. Dispatch/payment status is more authoritative than `last_seen_at`. |
| `449031f` | Regression-test the positive invariant—polling remains alive and no cancel/reset occurs—rather than only checking that an old error string disappeared. |
| `62352cd` | Payment success must be strict: require a real charge ID, PaymentIntent ID, boolean `paid === true`, and `status === succeeded`; also avoid webhook mutation after invalid/restricted credential checks. |
| `5b4b708` | WooCommerce custom settings field renderers must return the full table-row structure, not merely an input, or descriptions/tooltips/layout break. |
| `84eeadb` | Recovery must combine signed order auth, structured reader-busy errors, explicit operator confirmation, and compare-before-cancel. Generic errors are insufficient for dangerous hardware actions. |
| `a3cd20c` | Preserve an active action for a different payment: it may belong to another register/customer. |
| `bb1ab66` | The preceding attempt to automatically clear a different in-progress action was too aggressive and was corrected by `a3cd20c`. “Unblock the reader” is not automatically safe. |
| `3ed2a04` | Only generate cashier-context nonces inside an actual POS request; global user switching can break ordinary checkout/admin behavior. |
| `2fcc313` | Distinguish missing from expired/invalid auth and give the cashier a concrete recovery instruction. |

The local design documents tie several of these to reported issues: reader timeout #22, reader pickup #27, restricted keys #44, saved-key exposure #45, and declined payments approving orders #50.

---

# `git log --oneline -40`

```text
c468efb Merge pull request #77 from wcpos/ci/release-notes-from-changelog
d7f538c ci: populate release notes from CHANGELOG.md
01c9cc8 Merge pull request #75 from robertstaddon/feat/blocks-checkout-support
677688c ci: bump e2e WordPress image to 6.9 for current WooCommerce
b0d21f8 chore: declare cart_checkout_blocks compatibility and build blocks assets on release
a68e283 fix: skip Blocks Terminal UI when JSX runtime is unavailable
383114f feat: add WooCommerce Blocks checkout support
d18a970 Merge pull request #74 from wcpos/codex/fix-paid-terminal-resubmission
dae6f83 fix: recover duplicate paid terminal submissions
1438b07 chore(deps-dev): bump @babel/core in /packages/terminal-ui (#71)
7ee5c46 chore(deps-dev): bump js-yaml in /packages/terminal-ui (#70)
bf53fcf chore(deps-dev): update wp-phpunit/wp-phpunit requirement (#66)
c165fc8 Merge pull request #62 from wcpos/chore/remove-noisy-payment-frontend-test
62ec0d9 chore(deps-dev): bump postcss in /packages/payment-frontend (#54)
8a62dc0 chore(deps-dev): bump postcss in /packages/terminal-ui (#64)
d40863a chore(deps): bump qs from 6.15.0 to 6.15.2 in /packages/terminal-ui (#63)
54ecf22 test: remove payment frontend source regression test
358a35b Merge pull request #60 from wcpos/chore/bump-0.0.24
79c39eb Bump version to 0.0.24
a43cd08 Merge pull request #56 from wcpos/chore/codex-review-guidelines
a0d08ba Merge pull request #59 from wcpos/fix/pos-desktop-order-auth
a64b969 Fix POS desktop terminal order auth
3e16b01 Merge pull request #58 from wcpos/fix/reader-stale-command-dispatch
56eb558 test: skip frontend source test without node
a532a6e Bump version to 0.0.23
449031f test: assert reader pickup keeps polling
68a0e83 Fix stale reader dispatch gate
4260111 docs: add Codex review guidelines
d8aa008 Merge pull request #55 from wcpos/fix/stale-reader-action-recovery
62352cd Address PR feedback for payment success checks
5b4b708 Fix masked secret key settings row rendering
84eeadb Fix terminal payment auth and recovery
57cda8f Document terminal recovery and security fixes design
a3cd20c fix: preserve active terminal reader actions
bb1ab66 Clear stale in-progress reader actions
d78449e Merge pull request #52 from wcpos/feature/stripe-live-integration-tests
b8618c1 Merge pull request #53 from wcpos/investigate/stripe-live-flow
8100a20 Bump version to 0.0.21
3ed2a04 fix: gate POS cashier nonce generation
2fcc313 Improve nonce failure messages
```

---

# Recommended Square Terminal architecture

1. Persist one payment-attempt record per order: Square payment ID, terminal/reader ID, attempt ID, last provider version/time, state, and cancellation state.
2. Create payments with a provider idempotency key.
3. Poll using chained `setTimeout`, not overlapping `setInterval`:
   - 2 seconds while healthy;
   - capped exponential backoff after transport failures;
   - explicit elapsed deadline;
   - resume from persisted order state.
4. Treat timeout as `unknown`, preserving payment identity and exposing reconcile/cancel actions.
5. Make webhooks monotonic and idempotent:
   - store event IDs;
   - ignore duplicates;
   - compare event payment/attempt IDs;
   - never downgrade completed state;
   - return retryable failures for transient processing errors.
6. Cancellation must independently confirm terminal state and payment state.
7. Force-clear only after an explicit warning and compare-before-cancel re-read.
8. Complete WooCommerce only after provider-confirmed paid status; never from browser state alone.
9. Use a real cashier state machine for button visibility and disable all conflicting actions while a mutation is pending.
10. Centralize localized copy, HTML escaping, structured logging, and secret redaction.

## Behavior changes / regressions

No code was changed. The report identifies existing behavior gaps and regression risks only; live compatibility and hardware behavior were not evaluated.
