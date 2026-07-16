# SumUp Terminal Plugin: Production Design Patterns

## Scope and evidence

- **Observed:** Reviewed local `main` at `e62714d` and ran the requested `git log --oneline -40`.
- **Repository state:** local `main` is three commits behind tracked `origin/main`. The three newer commits contain the reader-loading fix discussed under “Additional production learning.”
- **Not evaluated:** Runtime behavior and test execution. Conclusions below come from source, tests, release notes, and commit diffs.
- **Edits:** None.

## Executive summary for the Square sibling

The strongest patterns to carry over are:

1. Treat the terminal, webhook delivery, and Transactions API as separate, eventually consistent signals.
2. Persist an explicit payment attempt identity before calling the provider.
3. Correlate every callback/status result to that attempt and transaction.
4. Serialize polling requests and perform a fresh authoritative read immediately before cancellation.
5. Treat cancellation as asynchronous; continue watching until a final payment result arrives.
6. Keep cashier controls recoverable: manual status check, visible device state, bounded support logs.
7. Reject duplicate starts server-side, not only through disabled buttons.
8. Normalize SDK response shapes behind an adapter.
9. Do **not** copy the current webhook verification/idempotency, refund, currency-exponent, or final-completion gaps.

---

# 1. Payment status polling

## Mechanics

| Concern | Observed implementation | Square lesson |
|---|---|---|
| Interval | Fixed `setInterval(..., 2000)`: one tick every two seconds. `assets/js/payment.js:299-318` | A short fixed interval is acceptable for cashier feedback, but provider reads should be separately throttled. |
| Maximum | `maxPolls: 150`, described as five minutes. `assets/js/payment.js:271-284` | Make the timeout explicit and configurable. Because the comparison is `pollCount > maxPolls`, timeout occurs on tick 151—approximately 302 seconds, before network latency. |
| Backoff | None. The browser remains at two-second intervals. | Square should consider backoff/jitter for transport failures while retaining responsive polling during active card entry. |
| Request overlap | `requestPending` suppresses overlapping AJAX calls. Poll ticks continue, but long requests do not stack. `assets/js/payment.js:321-360` | Essential when the upstream/API request timeout can exceed the UI interval. |
| Server-side throttle | Authoritative transaction reconciliation is limited to once every five seconds using `_sumup_transaction_checked_at`. `includes/AjaxHandler.php:304-325` | Decouple cheap local-state polls from expensive Square Payments API lookups. |
| Final timeout check | The interval stops, any in-flight request is allowed to finish, and then a **fresh** status request runs. That final request sets `force_transaction_check` to bypass the five-second server throttle. `assets/js/payment.js:305-318`, `321-360`; `includes/AjaxHandler.php:283-287`, `311-334` | Before an irreversible action such as cancel/void, bypass normal caching and perform a fresh authoritative read. |
| Manual recovery | “Check Status” performs a serialized one-shot request and then removes its temporary poll state. `assets/js/payment.js:140-174` | Always give cashiers a recovery path after a timeout, page refresh, or ambiguous network result. |

## How polling coexists with webhooks

Polling reads the order meta populated by webhooks, but it does not depend exclusively on webhook delivery:

1. Webhooks update `_sumup_checkout_status` or `_sumup_transaction_status`.
2. Polling reads both statuses.
3. If the transaction is still unresolved, polling periodically queries the authoritative Transactions API by the saved client transaction ID.
4. `SUCCESSFUL` maps to checkout `PAID`; transaction `FAILED`/`CANCELLED` overrides a non-paid checkout status.

References: `includes/AjaxHandler.php:304-351`, `includes/Services/TransactionService.php:41-61`.

This was added after real completed card charges remained pending when callbacks were delayed or never reached WordPress: `docs/releases/0.0.11.md:3-12`.

## What stops polling

Polling stops when:

- The server returns `continue_polling: false` for `PAID`, `FAILED`, `CANCELLED`, `TIMEOUT`, or `EXPIRED`. `includes/AjaxHandler.php:383-425`
- A terminal result is handled; the reader’s interval and registry entry are cleared. `assets/js/payment.js:379-400`, `449-455`
- A new payment begins on the same reader; the existing reader poll is stopped first. `assets/js/payment.js:271-293`
- A cancellation has remained unresolved for a second full polling window; polling stops and the cashier is told to use “Check Status.” `assets/js/payment.js:364-374`
- The page unloads, implicitly destroying browser timers. There is no explicit unload/visibility cleanup.

## Timeout duration after cancellation

An accepted cancellation resets `pollCount` and continues/restarts polling. Therefore an unresolved payment can receive:

- roughly five minutes before automatic cancellation, then
- another roughly five minutes awaiting final cancellation/payment status.

References: `assets/js/payment.js:230-243`, `271-318`, `364-374`.

---

# 2. Payment cancellation

## UI initiation

- The cancel button is rendered hidden next to each reader and becomes visible only after checkout creation succeeds. `includes/Gateway.php:330-341`; `assets/js/payment.js:118-128`
- Clicking it requires cashier confirmation. `assets/js/payment.js:179-211`
- The request includes `reader_id`, `order_id`, and the WooCommerce order key. `assets/js/payment.js:214-229`

## Server authorization and initiation

Because POS user-context switching makes WordPress nonces unreliable, the payment endpoints intentionally skip nonce validation and use the order key as durable bearer context. Cancellation additionally requires the requested reader to equal `_sumup_reader_id`. `includes/AjaxHandler.php:225-250`

The server sends the reader’s asynchronous terminate request but does **not** write a final cancelled status. `includes/AjaxHandler.php:252-269`

The transport explicitly documents that:

- terminate acceptance is not final cancellation;
- the eventual result arrives through webhook/status;
- HTTP 204 with no body is a successful request.

`includes/Services/WordPressHttpReaderApiClient.php:115-143`

## Confirmation and edge handling

### Accepted cancellation

The UI:

- records a cancellation message;
- hides the cancel button;
- leaves start controls disabled;
- keeps or restarts polling.

`assets/js/payment.js:230-243`

This prevents the dangerous state where the cashier begins another payment while the first reader checkout may still complete.

### Network failure or rejected cancellation

The code:

- clears `cancelRequested`;
- re-enables the cancel button;
- displays/logs the error;
- resumes polling if automatic-timeout logic had already stopped the interval.

`assets/js/payment.js:244-267`

### Payment completes during cancellation

Polling and transaction reconciliation continue. If the provider’s authoritative transaction becomes `SUCCESSFUL`, payment wins, polling stops, and checkout submission proceeds. `includes/AjaxHandler.php:323-350`; `assets/js/payment.js:379-400`

### Already-final payment

Final webhook/transaction handling removes `_sumup_reader_id`. A later cancellation request will then fail the reader-to-order context check rather than terminating unrelated reader state. `includes/AjaxHandler.php:242-250`, `337-340`, `568-573`, `615-620`

### Timeout cancellation race

Automatic cancellation performs the fresh forced transaction read first. This specifically prevents cancelling a payment that completed just before the timeout. `assets/js/payment.js:305-374`

Manual cashier cancellation does **not** perform that same preflight check, but ongoing reconciliation still detects an already-completed payment afterward.

### Stale attempt protection

Before starting a retry, prior statuses and transaction ID are cleared, `CREATING` is persisted, the reader is assigned, and `_sumup_attempt_started` is stamped. `includes/AjaxHandler.php:169-182`

Webhooks are rejected when they:

- predate the active attempt;
- arrive during transaction-ID handoff;
- carry a transaction ID that does not match the current attempt.

`includes/AjaxHandler.php:507-517`, `642-659`

---

# 3. Cashier-facing UI

## Status presentation

Each reader card displays:

- name;
- provider status;
- model;
- Start Payment, Check Status, and Cancel Payment controls;
- an accessible live region.

`includes/Gateway.php:309-342`

The status region uses `role="status"` and `aria-live="polite"`, allowing screen readers to announce changes without interrupting the cashier. `includes/Gateway.php:341`

Live reader states are translated into actionable messages for:

- ready/idle;
- tip selection;
- card presentation;
- PIN entry;
- signature;
- firmware update;
- offline.

`assets/js/payment.js:405-416`; localized strings at `includes/Gateway.php:490-520`.

Dynamic status/error messages are inserted with jQuery `.text()`, not raw HTML. `assets/js/payment.js:508-515`

## Button lifecycle

- Starting a payment disables **all** reader start and status controls, preventing simultaneous/conflicting reader operations. `assets/js/payment.js:100-105`
- Successful initiation keeps Start disabled, enables Check Status, and reveals Cancel. `assets/js/payment.js:118-128`
- A manual check disables only its button during the request. `assets/js/payment.js:140-174`
- Cancellation disables its button while the request is pending. `assets/js/payment.js:214-219`
- Final failure/cancellation resets all start/status buttons and hides Cancel. `assets/js/payment.js:391-400`, `461-465`

The backend independently prevents duplicate starts, so button state is not the sole concurrency guard. `includes/AjaxHandler.php:155-167`

## Errors

The UI attempts to extract structured JSON errors, otherwise falls back to a localized network message. `assets/js/payment.js:478-502`

All errors are prefixed with “Payment failed:” and copied into the cashier log. `assets/js/payment.js:508-511`

Caveat: server `response.data` can be shown directly, so Square should ensure public errors are cashier-safe rather than raw provider/debug messages.

## Payment log panel

The optional checkout panel:

- is off by default;
- is collapsible;
- uses a readonly textarea;
- supports copy and clear;
- keeps the latest 50 entries;
- persists in `sessionStorage` by order ID.

`includes/Gateway.php:131-138`, `345-359`; `assets/js/payment.js:45-79`

The bounded log is an excellent support primitive. One caveat is the fallback key `unknown`, which can mix sessions when no order ID is available. `assets/js/payment.js:51-56`

## i18n

Most PHP and cashier runtime strings are localized through WordPress and passed to JavaScript. `includes/Gateway.php:483-520`

Do not copy these remaining gaps:

- Missing-reader/order messages are hard-coded English. `assets/js/payment.js:95-97`, `160-162`, `199-201`
- Admin pairing messages are partly hard-coded in JavaScript. `assets/js/admin.js:29-63`
- The plugin header has a trailing period in the text domain, and the POT file is still named `stripe-terminal-for-woocommerce.pot`. `sumup-terminal-for-woocommerce.php:10`; `languages/stripe-terminal-for-woocommerce.pot`

---

# 4. Settings and admin UX

## Test versus live

There is no merchant-facing mode switch. The plugin uses one password-type `api_key` field. `includes/Gateway.php:122-130`

An inline warning explains that SumUp uses separate test and live accounts and instructs merchants to swap API keys. `includes/Gateway.php:176-203`

`SUMUP_API_BASE_URL` can override the endpoint for development/testing, but it is a deployment constant, not an admin mode. `includes/Services/HttpClient.php:42-50`

**Square recommendation:** use explicit Sandbox/Production mode with separately stored credentials and visually unmistakable mode warnings. Avoid asking merchants to overwrite production credentials during testing.

## Credential validation

Validation occurs during settings rendering:

- `check_api_key_status()` calls the profile service and renders green/red inline status. `includes/Gateway.php:217-226`, `803-815`
- The connection section distinguishes missing key, connection failure, and connected state. `includes/Gateway.php:546-578`
- The profile response is cached for five minutes and reused within the request. `includes/Services/ProfileService.php:30-64`
- Changing the API key clears the old key’s profile cache and reinitializes services. `includes/Gateway.php:376-395`

There is no separate “Validate credentials” button; saving/reloading performs validation.

## Helper actions and warnings

The page provides:

- a Developer Portal link and step-by-step API-key instructions; `includes/Gateway.php:176-203`
- merchant identity/country display, useful for catching “wrong account” mistakes; `includes/Gateway.php:643-674`
- reader inventory with status/model/serial/ID and Unpair controls; `includes/Gateway.php:682-763`
- pairing instructions even when reader listing fails, allowing recovery rather than a dead settings screen. `includes/Gateway.php:764-781`

Pair/unpair admin AJAX uses a nonce plus `manage_woocommerce`. `includes/AjaxHandler.php:45-60`, `89-103`

## Stale settings code

`includes/Settings.php:20-42` contains unused Stripe-era `test_mode`, secret-key, and webhook-secret accessors; no production consumer calls `Settings::...`.

Do not carry dead sibling-provider configuration into Square. It creates false confidence that test-mode or webhook-secret handling exists when the gateway bypasses it.

---

# 5. Webhooks

## Registration model

There is no manual/global webhook registration. Every reader checkout includes an order-specific `return_url` pointing to `admin-ajax.php?action=sumup_webhook`. `includes/Services/ReaderService.php:122-134`

Some localized admin strings mention webhook registration, but `assets/js/admin.js` has no webhook action. `includes/Gateway.php:435-440`; `assets/js/admin.js:100-125`

For Square, choose one coherent model—normally automatic subscription registration with stored subscription ID and health/status—not dead UI copy.

## Verification

The handler verifies:

1. order ID;
2. order existence;
3. a deterministic order token in the callback URL;
4. non-empty body;
5. valid JSON;
6. `event_type` and `payload`.

`includes/AjaxHandler.php:433-486`

The token is a ten-character substring derived from the order ID and WordPress salt. `includes/AjaxHandler.php:662-675`

**Important:** this is **not provider signature verification**. It does not authenticate the raw body or a SumUp signature header.

For Square, implement Square’s documented HMAC verification over the exact notification URL and raw request body before parsing.

## Idempotency and deduplication

There is no event-ID deduplication:

- webhook `id` is not inspected;
- repeated events repeat order notes;
- `_sumup_last_webhook` is only overwritten with the latest payload.

`includes/AjaxHandler.php:507-545`, `575-592`, `622-631`

Square should persist processed event IDs, make each state transition idempotent, and return 2xx for known duplicates.

## Retries

- Successful/unknown events return 200. `includes/AjaxHandler.php:488-493`, `519-535`
- Processing exceptions return 500, allowing provider retry. `includes/AjaxHandler.php:494-497`
- Malformed/authentication failures return appropriate 4xx responses. `includes/AjaxHandler.php:438-486`

## Out-of-order events

Protection exists across payment attempts:

- reject events older than `_sumup_attempt_started`;
- reject callbacks during `CREATING`;
- require active transaction ID correlation.

`includes/AjaxHandler.php:507-517`, `642-659`

But there is no ordering check **within the same attempt**. Handlers unconditionally overwrite status/timestamp, so an older same-transaction event can regress newer state. `includes/AjaxHandler.php:554-573`, `602-620`

Square should enforce monotonic state transitions and compare provider event timestamps/version data before overwriting final state.

---

# 6. Order state management

## Meta keys

| Meta/order field | Purpose |
|---|---|
| WooCommerce transaction ID | Provider client transaction ID used for correlation and authoritative lookup. `includes/AjaxHandler.php:188-200` |
| `_sumup_checkout_status` | `CREATING`, `PENDING`, and provider checkout outcomes. |
| `_sumup_checkout_updated` | Webhook checkout timestamp. |
| `_sumup_transaction_status` | Transaction outcomes such as `SUCCESSFUL`, `FAILED`, `CANCELLED`. |
| `_sumup_transaction_updated` | Transaction webhook/reconciliation timestamp. |
| `_sumup_transaction_checked_at` | Five-second authoritative lookup throttle. |
| `_sumup_reader_id` | Reader owned by the active attempt; also authorizes cancellation/status lookup. |
| `_sumup_attempt_started` | Rejects callbacks from older attempts. |
| `_sumup_last_webhook` | Complete latest payload for debugging. |

Lifecycle writes are concentrated in `includes/AjaxHandler.php:155-200`, `304-340`, `507-545`, `568-620`.

## Transitions

```text
no attempt
  → CREATING
  → PENDING
  → PAID | FAILED | CANCELLED | TIMEOUT | EXPIRED
```

Before a retry, previous final checkout/transaction state, transaction throttle, and Woo transaction ID are cleared. `includes/AjaxHandler.php:169-182`

Final statuses delete `_sumup_reader_id`, closing the reader context. `includes/AjaxHandler.php:337-340`, `568-573`, `615-620`

Failed/cancelled outcomes do not change the WooCommerce order status; the order remains payable and the UI resets for retry.

## Guarding against double completion

- Creation rejects orders that are already paid, have final successful provider state, or no longer need payment. `includes/AjaxHandler.php:155-167`
- `process_payment()` calls `payment_complete()` only if WooCommerce does not already consider the order paid. `includes/Gateway.php:236-256`

### Completion gap

`process_payment()` checks only that a transaction ID exists; it does not independently require `PAID`/`SUCCESSFUL`. `includes/Gateway.php:236-250`

The normal UI submits only after a PAID result, but the server completion method should still enforce final provider success. Square should make this invariant server-side.

## Refunds

No gateway refund support is implemented:

- no `supports[] = 'refunds'`;
- no `process_refund()`;
- no refund webhook/order-meta lifecycle.

The bundled SDK contains refund APIs, but the gateway does not expose them. A Square sibling needs explicit full/partial refund handling, idempotency keys, refund-status webhooks, order notes, and failure reconciliation.

---

# 7. Logging

## WooCommerce logs

`Logger` writes through `wc_get_logger()` under source `sumup-terminal-for-woocommerce`, default level `info`, and allows filtering through `sutwc_logging`. `includes/Logger.php:13-46`

Logged events include:

- pairing/unpairing/create/cancel failures;
- transaction and reader status request failures;
- transaction/order IDs;
- malformed or unauthorized webhooks;
- stale/mismatched callbacks;
- unknown event types;
- SDK failures and HTTP fallback.

See `includes/AjaxHandler.php:81-82`, `116-117`, `197-218`, `267-268`, `342-367`, `440-495`, `514-654`.

These surface under WooCommerce → Status → Logs.

## Secret redaction

The API key is not normally logged because request headers are not logged. A helper would replace `Authorization` with `Bearer ***`, but that helper is currently unused. `includes/Services/HttpClient.php:244-259`

There is no general redaction pipeline. Non-JSON/error responses can be logged verbatim, and exception messages are logged directly. `includes/Services/HttpClient.php:202-230`; `includes/Abstracts/SumUpErrorHandler.php:25-35`

Square should redact recursively by key/name before both persistent and cashier-facing logs.

## On-screen logs

The support panel records friendly lifecycle messages rather than entire request/response payloads, but raw AJAX error data can still reach it. It is opt-in and limited to 50 lines. `assets/js/payment.js:45-79`, `478-511`

## Payload storage

The complete latest webhook is stored in order meta for debugging. `includes/AjaxHandler.php:537-545`

That is useful operationally but should be data-minimized for Square because payment webhooks can contain customer/payment metadata.

---

# 8. Additional real-user lessons and defensive checks

## Reader UI rendered empty when another gateway was initially selected

Tracked `origin/main` adds a fix not yet present in the local checkout:

- react when SumUp becomes selected;
- re-fetch only an empty SumUp payment box;
- leave an existing reader list untouched;
- queue another reload if WooCommerce replaces the checkout DOM while a request is active;
- restore support logs after DOM replacement.

`origin/main:assets/js/payment.js:55-111`; `origin/main:tests/payment-timeout-polling.test.js:62-218`; `origin/main:docs/releases/0.0.12.md:3-7`

**Lesson:** WooCommerce checkout fragments are disposable DOM. Use event delegation, detect replacements, serialize loaders, and never assume gateway markup was rendered while hidden.

## Pairing codes changed length

Hard-coded length constraints were removed after nine-character pairing codes appeared. `tests/regression/pairing-input-has-no-length-limit.php:11-23`

**Lesson:** Treat pairing codes as opaque provider strings unless the provider publishes a durable contract.

## Blank 2xx responses

Whitespace-only 2xx/204 responses become explicit success instead of false/invalid JSON. `includes/Services/HttpClient.php:211-241`; `tests/regression/http-client-empty-success.php:37-46`

**Lesson:** HTTP status determines success before body decoding.

## SDK shape compatibility

The SDK adapter recursively converts object/camelCase responses to the gateway’s established snake_case array contract. `includes/Services/SdkReaderApiClient.php:212-265`

SDK exceptions fall back to the WordPress HTTP client. `includes/Services/SdkReaderApiClient.php:267-274`

**Lesson:** keep Square SDK DTOs out of gateway/order/UI code.

## PHP compatibility

The PHP 8.2 SDK autoloader is conditionally loaded so PHP 7.4–8.1 never parse incompatible files; activation separately guards the plugin minimum. `sumup-terminal-for-woocommerce.php:23-41`, `64-87`

## Lazy account discovery

Merchant profile/code is cached and loaded only when needed, avoiding repeated account calls during every gateway/service construction. `includes/Services/ProfileService.php:30-64`; `includes/Gateway.php:528-536`

## Currency caveat

Checkout amount hard-codes `minor_unit => 2`. `includes/Services/ReaderService.php:107-114`

Do not copy this to Square: use WooCommerce/provider currency exponent rules for zero- and three-decimal currencies.

---

# Behavior changes / regressions / do-not-copy gaps

No code was changed during this review. Known current limitations are:

1. Fixed two-second polling has no backoff or jitter.
2. Webhooks use an order URL token, not provider body-signature verification.
3. No event-ID deduplication.
4. Same-attempt out-of-order events can overwrite newer state.
5. Refunds are absent.
6. `process_payment()` does not independently verify final provider success.
7. Currency minor units are hard-coded to two decimals.
8. Log redaction is incomplete.
9. Some cashier/admin strings and translation metadata are stale or untranslated.
10. Full webhook payloads are stored in order meta.

---

# Git fix/bug history: lessons

| Commit | Fix | Production lesson |
|---|---|---|
| `77c418f` | Improve terminal payment feedback/lifecycle | Asynchronous cancellation, owned polling, manual recovery, attempt identity, order-key/reader binding, accessible logs, safe targeted form submission, live reader state, 204 handling, opaque pairing codes. Current behavior spans `assets/js/payment.js`, `includes/AjaxHandler.php`, and `includes/Gateway.php`; summary at `docs/releases/0.0.10.md:3-27`. |
| `98cea26` | Match Solo transaction webhooks | Solo events used `transaction_id`, not `client_transaction_id`/`reference`. Inventory identifier fields per event family. Current matcher: `includes/AjaxHandler.php:648-659`. |
| `7710dab` | Recheck before timeout cancellation | A timeout is a decision boundary: wait for an in-flight poll, then issue a fresh read before cancellation. `assets/js/payment.js:305-360`. |
| `af6a7c5` | Whitespace-only API response coverage | Successful 204 bodies may contain whitespace. Trim before decoding. `includes/Services/HttpClient.php:235-241`. |
| `da2e893` | Reconcile completed reader payments | Webhooks can be delayed/missing; query the authoritative Transactions API by client transaction ID. `docs/releases/0.0.11.md:3-12`; `includes/AjaxHandler.php:304-350`. |
| `25fcd80` | Force final transaction status check | The new final poll was still blocked by the five-second reconciliation throttle. Reads before irreversible actions must bypass normal caching. `assets/js/payment.js:321-360`; `includes/AjaxHandler.php:283-320`. |
| `0904aeb` | SDK status and checkout normalization | Missing diagnostics can break the settings page; SDK camelCase DTOs must be adapted to the existing internal shape. `includes/Gateway.php:581-601`; `includes/Services/SdkReaderApiClient.php:233-265`. |
| `c37e9ba` | SDK readiness audit fixes | `false` must not be mistaken for successful cancellation; vendoring/generation scripts must fail on read/regex/short-write errors. `includes/Services/WordPressHttpReaderApiClient.php:126-143`. |
| `9349733` | Activation/autoload guards | Guard incompatible SDK parsing before loading its autoloader; fail plugin activation with a useful message. `sumup-terminal-for-woocommerce.php:37-41`, `64-87`. |
| `79c7a0d` / `5cd9d76` | POT workflow permissions | Generated-translation workflows that commit need explicit job-level `contents: write`. Operational rather than payment-specific. |
| `5284d62` *(tracked origin, not local log)* | Reload readers when selected | WooCommerce can initially render an empty hidden gateway and replace it during checkout refreshes. Reload only missing markup and queue retries. `origin/main:assets/js/payment.js:55-111`. |

Merge commits `9d5da24` and `e62714d` add no separate behavior beyond their constituent fixes.

---

# Requested `git log --oneline -40`

Run at local `main` / `e62714d`:

```text
e62714d Merge pull request #36 from wcpos/codex/fix-payment-completion-status
25fcd80 fix: force final transaction status check
da2e893 fix: reconcile completed reader payments
9d5da24 Merge pull request #35 from wcpos/codex/sumup-payment-feedback
af6a7c5 test: cover whitespace-only API responses
7710dab fix: recheck status before timeout cancellation
98cea26 fix: match Solo transaction webhooks
81435f8 chore: prepare release 0.0.10
c927bd4 chore: retrigger CodeQL analysis
77c418f fix: improve SumUp terminal payment feedback
2d45b67 chore(deps): bump shivammathur/setup-php in the actions group (#32)
3e1e49e chore(deps): bump actions/checkout in the actions group (#31)
3f6307b Merge pull request #30 from wcpos/chore/release-0.0.9
63cfa98 chore: prepare 0.0.9 release
2934f58 Merge pull request #29 from wcpos/feature/prefixed-sumup-sdk-hybrid
c37e9ba fix: address sdk readiness audit feedback
0904aeb Fix SDK status and checkout normalization
e51e4cc chore: tidy SDK integration lint metadata
6f4032f build: map prefixed SDK service classes
8383408 docs: document SumUp SDK hybrid strategy
f78abee feat: use official SumUp SDK when available
0494c80 build: ensure SumUp SDK namespace prefix
e2da379 feat: show SumUp SDK compatibility status
a354734 refactor: extract WordPress HTTP reader client
9349733 feat: guard activation and prefixed SDK autoloading
05d1e2c build: add prefixed SumUp PHP SDK
8df97a2 chore: remove unused SumUp ecommerce SDK
83df53c Merge pull request #22 from wcpos/dependabot/github_actions/actions-039c6fabce
132306f Merge pull request #20 from wcpos/chore/codex-review-guidelines
22f1b5c chore(deps): bump qs from 6.13.0 to 6.15.2 in /packages/test-server (#27)
516843c chore(deps): bump minimatch from 3.1.2 to 3.1.5 in /packages/test-server
3670906 chore(deps): bump path-to-regexp in /packages/test-server (#26)
14ceb29 chore(deps): bump picomatch from 2.3.1 to 2.3.2 in /packages/test-server (#24)
10eeadc chore(deps-dev): update wp-phpunit/wp-phpunit requirement (#23)
31b029f chore(deps): bump the actions group across 1 directory with 2 updates
e97bf39 docs: add Codex review guidelines
fa1c2d6 Merge pull request #18 from wcpos/chore/add-update-uri
f3628ea chore: bump version to 0.0.8
47e3856 chore: add Update URI header
79c7a0d Merge pull request #17 from wcpos/fix/update-pot-permissions
```
