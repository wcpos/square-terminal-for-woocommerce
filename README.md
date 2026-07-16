# Square Terminal for WooCommerce

Collect WooCommerce order payments on [Square Terminal](https://squareup.com/hardware/terminal) devices. Staff request a card-present payment from a WooCommerce order and complete it on paired Square hardware, with the result written back to the order.

This is an early **v0.1** release focused on POS / order-pay flows, verified Square webhooks, scoped Square SDK dependencies, and secure order-access checks.

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
| PHP | 8.1 |

## Installation

1. Download `square-terminal-for-woocommerce.zip` from the [latest release](https://github.com/wcpos/square-terminal-for-woocommerce/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate the plugin.

## Configuration

1. Go to **WooCommerce → Settings → Payments** and enable **Square Terminal**.
2. Enter your Square **Access Token**, **Location ID**, and **Webhook Signature Key** (Sandbox or Production).
3. Use **Create Device Code** to pair a Terminal: enter the generated code on the Square Terminal to register the device.
4. Configure the webhook notification URL shown on the settings screen in your Square Developer dashboard so payment-completion events reach the site.

Square Terminal is intended primarily for **POS Checkout**; availability on the customer-facing **Storefront Checkout** is off by default and must be explicitly enabled.

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

## Releases

Releases are automated by [`.github/workflows/release.yml`](.github/workflows/release.yml). When the `Version:` header in `square-terminal-for-woocommerce.php` changes on `main`, the workflow builds the scoped, packaged plugin and publishes it as a `vX.Y.Z` GitHub Release with a `square-terminal-for-woocommerce.zip` asset. It can also be run manually from the **Actions** tab.

Published releases are picked up automatically by the [WCPOS extensions catalog](https://github.com/wcpos/extensions).

## License

[GPL-3.0-or-later](https://www.gnu.org/licenses/gpl-3.0.html)
