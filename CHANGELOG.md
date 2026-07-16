# Changelog

All notable changes to this project are documented in this file. Release notes for each version live in `docs/releases/`.

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
