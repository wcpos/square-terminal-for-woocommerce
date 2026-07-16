# Square Terminal for WooCommerce — current-state gap analysis (2026-07-16)

Branch: feat/square-terminal-working-plugin-wcpos (after pull, 15 files changed came in).
~1,174 lines of PHP/JS. Solid skeleton, good domain language (CONTEXT.md), but large gaps vs "best in class".

## What exists and is sound
- Gateway registered (`sqtwc`), redirects unpaid orders to order-pay page (Gateway.php:88).
- CreateTerminalCheckout with per-order idempotency key stored in `_sqtwc_checkout_idempotency_key`, reference_id `woocommerce_order_{id}` (AjaxHandler.php:71-86).
- Cancel checkout AJAX with checkout-id-matches-order guard (AjaxHandler.php:109).
- Webhook REST route `sqtwc/v1/webhook` (Plugin.php:33), HMAC verification via scoped SDK WebhooksHelper (WebhookSignatureVerifier), event dedup via `_sqtwc_processed_event_ids` order meta, `payment_complete()` only when not already paid (WebhookHandler.php:98-107).
- Order access model: manage_woocommerce OR order_key OR signed PaymentRequestToken (OrderAccess.php). Nonce required only for logged-in users (AjaxHandler::requires_nonce).
- Logger with secret redaction + order notes mirror (Logger.php).
- Device code creation adapter exists (SquareDeviceAdapter::create_device_code, TERMINAL_API product type).
- CurrencyConverter to minor units.
- Scoped Square SDK vendored (build/scoped) — includes Webhooks Subscriptions API (create/list/update/delete/test, UpdateWebhookSubscriptionSignatureKey), Devices API, Terminal API.
- Test suite exists (tests/includes/*Test.php).

## Gaps (numbered for spec reference)
1. **Frontend payment.js is a stub** (16 lines): no AJAX calls at all — Start/Cancel buttons only write local log lines. No polling, no status display transitions, no disable/enable states, no error handling. The whole cashier flow is unimplemented client-side.
2. **No status/poll AJAX action**: adapter has get_checkout() but no `wp_ajax_sqtwc_get_terminal_checkout` action exposes it. Polling ("keep UI responsive; webhook authoritative" per CONTEXT.md) can't happen.
3. **Manual device ID entry**: cashier must type a Square device_id into a text input (Gateway.php:130-133). No ListDevices/ListDeviceCodes, no saved default device, no per-register device selection, no device online status.
4. **Webhook handles only terminal.checkout.updated + COMPLETED**: CANCELED/CANCEL_REQUESTED/timeout not reflected to order/payment log; no device.code.paired handling for pairing UX; no payment.updated fallback.
5. **No webhook auto-provisioning** (prior conversation): Webhook Subscriptions API is in the vendored SDK. Create/list/test + store signature_key + notification URL automatically. Token type caveat: works with personal access tokens (app-level); breaks if OAuth later.
6. **No API error handling in AjaxHandler**: `$result['id']` assumed present; Square SDK exceptions (auth failure, device offline, NOT_FOUND) propagate as PHP fatals/blank 500s. No mapping of Square error codes to cashier-readable messages.
7. **No deadline/timeout on checkout**: CreateTerminalCheckout sent without deadline_duration; no payment_options (autocomplete), no device_options (skip_receipt_screen, collect_signature, show_itemized_cart), no tip settings.
8. **Settings**: single shared location_id across sandbox/production (sandbox location IDs differ — needs per-env); webhook_signature_key + notification URL manual; admin.js stub (6 lines) — validate_settings & create_device_code actions declared client-side but no server AJAX handlers registered in Plugin.php for them; no inline connection status.
9. **No refunds**: CONTEXT.md defers refunds v1 but stores payment_ids; spec should define the path (RefundPayment API via WC refund UI).
10. **Payment log**: append-only strings in `_sqtwc_payment_log` meta, rendered server-side only at page load; JS log lines are client-local and lost on reload; no timestamps server-side; unbounded meta growth; concurrent writes (webhook vs ajax) can clobber (read-modify-write without locking).
11. **Storefront vs POS surfacing**: gateway shows in normal checkout by default (payment_fields renders the terminal UI with order_id=0); CONTEXT.md says storefront must be opt-in.
12. **process_payment doesn't guard environment/config**: no is_available() override checking credentials/location set, currency support, etc.
13. **No HPOS/blocks declarations visible**, no WC min-version compatibility declarations (needs verify).
14. **Race**: webhook clears idempotency key + completes; concurrent poll response also acting could double-write meta. Poll handler must be read-only on order state or use same dedup.
15. **Uncaptured payment risk**: if webhook is misconfigured/unreachable, COMPLETED checkouts never mark orders paid — a reconciliation path (poll fallback that CAN complete order after signature-verified fetch by server, or admin "sync status" button) is needed. CONTEXT.md says webhook authoritative; spec must define recovery when webhooks never arrive.
16. **i18n**: AjaxHandler error strings not translatable ('Order not found.' etc. raw English, no __()).

## Meta keys currently used
- `_sqtwc_checkout_idempotency_key`, `_sqtwc_checkout_id`, `_sqtwc_payment_log`, `_sqtwc_processed_event_ids`, `_sqtwc_payment_ids`, transaction_id.

## Notes for spec
- Keep CONTEXT.md ubiquitous language; extend it (Webhook Subscription, Device, Reconciliation).
- dev-pro.wcpos.com = hosted Square sandbox validation site; demo.wcpos.com final smoke.
- Tests are unit-style with mocks; spec should require integration-ish tests for webhook handler + reconciliation.
