# Square Terminal for WooCommerce

Collect WooCommerce order payments on [Square Terminal](https://squareup.com/hardware/terminal) devices. Staff request a card-present payment from a WooCommerce order and complete it on paired Square hardware, with the result written back to the order.

**Requires WooCommerce POS Pro 2.0 or newer.** The gateway is for staff on the POS: it never appears on the shop's checkout. Without a compatible Pro the plugin shows an admin notice and registers nothing.

## Features

- Send a WooCommerce order to a paired Square Terminal and collect a card-present payment.
- Pair Terminal devices from WooCommerce using a short-lived Square **Device Code**.
- Settings-based **Square Account Connection** — merchant-entered Access Token, Location ID, and Webhook Signature Key (no OAuth in v0.1).
- Sandbox and Production support.
- Verified Square **webhooks** are the authoritative payment-completion signal; polling keeps the UI responsive.
- A per-order **Payment Log** and order notes record each meaningful Square step and outcome.
- The Square SDK is namespace-scoped with [PHP-Scoper](https://github.com/humbug/php-scoper) so it cannot clash with other plugins.

> **Scope of v0.1:** payment collection only. Refunds are not yet supported, but Square identifiers are stored on the order so refund support can be added later.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.5 |
| WooCommerce | 8.0 |
| WooCommerce POS Pro | 2.0.0 |
| PHP | 8.1 |

## Installation

1. Download `square-terminal-for-woocommerce.zip` from the [latest release](https://github.com/wcpos/square-terminal-for-woocommerce/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate the plugin.

## Configuration

1. Go to **WooCommerce → Settings → Payments → Square Terminal**.
2. Enter your Square **Access Token**, **Location ID**, and **Webhook Signature Key** (Sandbox or Production).
3. Use **Create Device Code** to pair a Terminal: enter the generated code on the Square Terminal to register the device.
4. Configure the webhook notification URL shown on the settings screen in your Square Developer dashboard so payment-completion events reach the site.

Switch the gateway on in **POS → Settings → Checkout**. That is the only switch: the gateway is available on POS requests and, to users who may run the POS, on the order-pay page; it is never offered on the shop's checkout.

## Development

```bash
composer install        # install dependencies
composer test           # run the PHPUnit suite
composer lint           # run PHPCS (WordPress / WooCommerce coding standards)
composer run format     # auto-fix coding-standards issues
```

The test suite is self-contained — WordPress and WooCommerce are stubbed in `tests/stubs`, so no WordPress install is required.

To produce the scoped vendor bundle used in distributable builds:

```bash
composer run build:scoped-vendor
```

## WCPOS Pro 2.0 payments base

With WCPOS Pro 2.0 active, the plugin registers a server adapter (`includes/Server/`) with Pro's shared payments base, so the POS app can drive a paired Square Terminal through Pro's ledger: one checkout per ledger row (the row id is Square's idempotency key and the checkout's reference id), polling and cancellation through Square's Terminal API, refunds through Square's Refunds API, and `terminal.checkout.updated` webhooks delivered to Pro's route.

**The order-pay page is Pro's panel** when the collection method is a paired Terminal: it drives the same adapter, and a refund from the WooCommerce order goes through Pro to Square. With the Square Point of Sale app hand-off selected, the plugin keeps its own order-pay panel (the hand-off has no home in Pro's panel yet) and nothing below applies.

**Upgrading with a payment mid-flight:** a Terminal checkout the old panel left live on an unpaid order, started within the last hour (Square ends an unpaid checkout in five minutes; older pointers are the old sweep's), is handed to Pro's ledger once per upgrade (25 orders per request, from a snapshot of order ids), and again when Pro's panel renders that order, under both the POS order lock and this plugin's own, judged on a copy read under both with the caches cleared. Pro owns the checkout while its ledger row is live; its old-panel actions answer 409 and the old panel's script stops, the old webhook, sweep and status check leave it alone, and once Pro's leg ends without money they act again as before. The order-status cleanup (cancelling an open checkout when the order is paid another way) still runs for an adopted checkout. While Pro's ledger holds a live row for this gateway on an order, the old panel starts no checkout on it, whichever collection method is selected. A Square Point of Sale transaction returned for an order already paid is noted on the order. An attempt whose create Square never confirmed has no checkout id and is not adopted; it stays in the sweep's index as before. A checkout pointer older than an hour is not adopted either: Pro's panel is held back with a notice until the sweep has read that checkout from Square and closed it (within about twenty minutes). If the sweep can never read it (a sandbox checkout after the switch to production, say: the production token gets a 404 whatever the checkout's state), the hold stays on that order's Square panel only; take the payment with another gateway, or switch the gateway back to sandbox until the next sweep has closed the pointer. Payments the old panel completed are refunded from the Square dashboard, as before.

Facts the adapter rests on, and what they mean for the store:

- A Square checkout has no decline state. A declined card leaves the checkout in progress for the buyer to try again; the checkout ends completed, or cancelled by the buyer, the seller, or the five-minute deadline. The adapter reports a checkout that ended after a decline as a failed payment with the card's reason, never as the cashier's cancellation.
- Money is read from the payment, never from the checkout. A cancelled checkout whose payment was captured (it timed out at the receipt screen) is money; a completed checkout whose payment cannot be read yet settles nothing until it can.
- A reference carries its environment. A sandbox checkout is polled, cancelled and refunded with the sandbox token even after the gateway is switched to production.
- A refund request Square did not answer keeps its WooCommerce refund record as pending, carrying the idempotency key saved before the request, and is asked about again every two minutes (up to five times) under that same key; Square hands back the refund the first request made, so no second refund is possible. Staff are told to check the Square dashboard if Square never answers.
- Square webhooks for Pro must be subscribed at the URL `Settings::get_pro_webhook_url()` names (Pro's `wcpos/v2/payments/webhook` route with `provider=square`). Edit the existing subscription's notification URL to that URL in the Square Developer dashboard: the subscription keeps its signature key, which is the one in the gateway settings. The old route still verifies deliveries made to it and acknowledges checkouts that are Pro's. The notification URL override does not apply to Pro's route.

### Conformance suite

Pro's provider conformance suite (`tests/conformance/`) runs the real adapter over a scripted Square (a PSR-18 client behind the `sqtwc_square_http_client` filter) under wp-env with the sibling Pro checkout, and compares the recorded transcripts in `tests/conformance/transcripts/`. It needs Docker, a sibling checkout at `../woocommerce-pos-pro` on `next` with its Composer dependencies installed, and this plugin installed **without** dev packages (its PHPUnit 10 cannot share a process with Pro's PHPUnit 9):

```bash
composer install --no-dev
npx wp-env start
npx wp-env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli -- ../woocommerce-pos-pro/vendor/bin/phpunit -c phpunit.conformance.xml.dist
```

A missing transcript fails. To record one, set the opt-in inside the PHPUnit process (wp-env forwards no host variables), review the JSON, rerun without it, then commit:

```bash
npx wp-env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli -- env WCPOS_RECORD_TRANSCRIPTS=1 ../woocommerce-pos-pro/vendor/bin/phpunit -c phpunit.conformance.xml.dist
```

## Releases

Releases are automated by [`.github/workflows/release.yml`](.github/workflows/release.yml). When the `Version:` header in `square-terminal-for-woocommerce.php` changes on `main`, the workflow builds the scoped, packaged plugin and publishes it as a `vX.Y.Z` GitHub Release with a `square-terminal-for-woocommerce.zip` asset. It can also be run manually from the **Actions** tab.

Published releases are picked up automatically by the [WCPOS extensions catalog](https://github.com/wcpos/extensions).

## License

[GPL-3.0-or-later](https://www.gnu.org/licenses/gpl-3.0.html)
