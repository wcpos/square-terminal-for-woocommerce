# Changelog

All notable changes to this project are documented in this file. Release notes for each version live in `docs/releases/`.

## [0.4.1] - 2026-07-22

### Fixed

- Corrected misleading wording in the reader list. Square's Devices API only reports Terminals that have been set up for Terminal API use — a Terminal running Square POS is invisible to it until it has been paired with a device code. The 0.4.0 wording implied Terminals paired by any means would be listed, so an empty list looked like a fault rather than the expected state before pairing.
- Device discovery is resolved once per request. WooCommerce builds the localized payment data several times per page render, so each render produced several duplicate cache reads and log entries, drowning the useful entries during setup.

## [0.4.0] - 2026-07-22

### Added

- **Check for readers** on the gateway settings screen. Lists Terminals paired through this plugin (selectable at checkout) alongside Terminals Square reports on the account that were paired elsewhere (informational). A Terminal paired outside this plugin cannot be selected for checkouts until it is paired here, and the list now says so instead of simply appearing empty.
- Environment and Location ID are pre-filled from the official WooCommerce Square plugin when it is configured. Only those two non-secret values are read — access and refresh tokens are never read, copied, stored, or logged, and the field description says so. Values populate the form and are not stored until saved; a value you have already saved always wins.

### Fixed

- Device discovery now logs every outcome — skipped, served from cache, or completed with a count — not only failures. Previously a check that ran and found nothing was indistinguishable in the logs from a check that never ran, which made an empty device selector impossible to diagnose.

## [0.3.1] - 2026-07-22

### Fixed

- **No Square API call has ever succeeded from a distributed build.** The Square SDK locates an HTTP client through `php-http/discovery`, which searches for well-known class names — php-scoper rewrites those names, and no concrete PSR-18 client was in the dependency tree at all. Every request threw `NotFoundException` before it was sent, surfacing as "Unable to reach Square". A PSR-18 client is now a runtime dependency and is passed to the SDK explicitly, bypassing discovery. Affects Terminal payments, device discovery, and settings validation alike.
- The scoped-vendor autoloader used a hand-maintained namespace map, so a newly added dependency was silently unloadable in the built plugin. It is now generated from Composer's own autoload data and the build fails if the result cannot load the SDK and its HTTP client.
- Square failures were logged with the error code and HTTP status redacted, because the log redactor matched any key containing `code` — including `error_code` and `status_code`. Diagnostic fields are no longer redacted; Terminal pairing codes still are.
- Removed the duplicate webhook URL input from the Terminal pairing row, which repeated the Webhook Notification URL setting shown directly above it.
- Square requests now use a bounded 10-second timeout.
- Updated `symfony/http-foundation` to clear CVE-2026-48736.

## [0.3.0] - 2026-07-21

### Added

- Production Terminal discovery: the device selector is populated from paired Square Device Codes for the configured location, replacing the manual device-ID entry that production installs previously required. Results are cached for five minutes and fall back to the last known good list if Square is unreachable.
- Terminal pairing controls on the gateway settings screen: **Create Device Code** returns a pairing code to enter on the Terminal, and **Validate Settings** verifies the configured credentials and location against Square. Both act on the values currently shown in the form.

### Fixed

- The **Create Device Code** and **Validate Settings** buttons had no registered handlers and did nothing when clicked.

## [0.2.2] - 2026-07-21

### Fixed

- Render the Square Terminal device selector and payment controls on WCPOS/order-pay checkouts while keeping the interactive controls hidden before standard checkout creates an order.

## [0.2.1] - 2026-07-16

### Fixed

- Block new Terminal checkouts while an unresolved partial capture exists; payment IDs and collected/tip totals are now append-only so no capture is dropped from the record.
- Keep attempts resumable on indeterminate create responses and `IDEMPOTENCY_KEY_REUSED`; resume replays the persisted original create payload (`_sqtwc_attempt_request`) verbatim.
- Replace sweeper meta-query discovery with an explicit `sqtwc_pending_reconciliation` index (oldest-first, storage-agnostic, starvation-free); delete abandoned-checkout meta when empty.
- Owner-token option-fallback locks with atomic owner-checked release and a 300-second lease.

## [0.2.0] - 2026-07-16

### Added

- `CheckoutReconciler`: a single server-verified reconciliation path for webhook, polling, and cancellation state, with attempt correlation, `updated_at` monotonicity, and Payment-object verification (actual collected and tip amounts persisted).
- Status polling endpoint (`sqtwc_get_terminal_status`) with a 5-second Square-fetch throttle and forced-read bypass; returns POS-aware thank-you redirects when paid.
- Detach action for offline/unresponsive terminals: releases the order for another payment method while the abandoned checkout remains reconciled in the background.
- Full cashier frontend: chained polling with capped backoff, button state machine, resume-after-reload, accessible live status region, sandbox test-device selector, optional bounded debug log panel.
- `SquareErrorMapper`: Square error taxonomy mapped to retriability and cashier-safe messages; single retry with the same idempotency key on transient failures.
- Terminal checkout options: explicit 5-minute `deadline_duration`, order note, `skip_receipt_screen` and `collect_signature` settings.
- Attempt-scoped order meta schema with attempt history and capped payment log / webhook event dedup lists.
- Ten-minute payment reconciliation sweeper for stale active checkouts and every detached checkout, with bounded batches and per-order error isolation.
- Atomic per-order mutation locks across checkout creation, polling, cancellation, detach, webhook, order-status, and background reconciliation paths.
- CI: test workflow (PHP 8.1/8.4 + Node) and release workflow (version-gated GitHub release with scoped-vendor plugin ZIP).
- Best-in-class update spec and sibling-plugin/Square-API research under `docs/`.

### Changed

- Webhook handler routes all `terminal.checkout.updated` events through the reconciler (all statuses, not just `COMPLETED`), and returns HTTP 500 on transient processing failures so Square retries delivery.
- Checkout creation recomputes the amount from the order server-side, guards against duplicate starts, and handles the `TIMED_OUT`-boundary race by verifying payments before honoring a cancellation.
- Cancellation is fetch → cancel → refetch: a payment completed during cancellation completes the order instead of reporting an error.
- Cancel/detach requests require matching `checkout_id` and `device_id` (compare-before-cancel).
- Under-collected Terminal payments no longer complete the WooCommerce order: verified payment metadata is retained, the order is placed on hold, and the cashier is told to verify the payment in Square Dashboard.
- Any additional Square capture on an already-paid order now adds a prominent order note, logs an error, and appends the payment IDs to duplicate-payment metadata for refund review.
- Attempt correlation now relies on checkout identity plus Square's `updated_at` monotonicity; local and Square clock skew can no longer reject a matching checkout.

## [0.1.0] - 2026-06-05

### Added

- Initial Square Terminal gateway: Terminal checkout create/cancel, webhook signature verification with event dedup, device-code pairing adapter, POS order access model, redacting logger.
