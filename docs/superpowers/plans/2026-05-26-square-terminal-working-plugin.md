# Square Terminal Working Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a thin end-to-end WooCommerce plugin that collects in-person payments through Square Terminal with the current Fern-generated Square PHP SDK, scoped dependencies, secure order access, webhook-authoritative completion, and exhaustive local/hosted validation.

**Architecture:** The plugin owns WooCommerce bootstrap/gateway/UI/AJAX/webhook code under `WCPOS\WooCommercePOS\SquareTerminal`. Square SDK calls are isolated behind narrow adapter classes that translate plugin arrays/domain terms into **real v45 typed SDK request/response objects** and normalize responses back to arrays for the rest of the plugin. The distributable plugin loads scoped vendor classes under `WCPOS\WooCommercePOS\SquareTerminal\Vendor` and never references global `Square\...` classes at runtime.

**Tech Stack:** PHP 8.1+ runtime, WordPress/WooCommerce, `square/square ^45.1`, PHP-Scoper PHAR build tool, Composer, PHPUnit + Brain Monkey, WooCommerce logger, local `wcpos.local`, hosted `dev-pro.wcpos.com`.

---

## Review fixes incorporated

- The plan now targets the real Square SDK v45 API shape: public sub-client properties, typed request objects, typed response getters, and `Square\Utils\WebhooksHelper`.
- SDK translation tests must instantiate real SDK request/response classes; no fake client may encode a made-up method/array SDK shape.
- PHP-Scoper is installed/used as a PHAR or external build tool, not as `humbug/php-scoper ^0.18` under PHP 8.1 Composer platform.
- The plugin registers its own namespace autoloader before loading vendor, so release builds do not depend on Composer autoloading plugin classes.
- Test bootstrap includes WordPress helper stubs and a minimal `WC_Payment_Gateway` stub before gateway tests run.
- `Settings` memoizes settings to avoid duplicate `get_option()` calls, and tests assert behavior rather than exact call count unless call count is part of the contract.
- v0.1 supports normal checkout by redirecting unpaid selected-gateway orders to the order-pay flow; the actual Terminal payment UI remains order-pay/POS centered.
- Webhook notification URL is an explicit setting and must exactly match the Square Developer Dashboard URL.
- Webhook replay/idempotency stores processed Square event IDs.

---

## File structure

- `square-terminal-for-woocommerce.php` — plugin header, constants, PHP 8.1 activation/runtime guards, plugin namespace autoloader, scoped/dev vendor loading, init hook.
- `composer.json` — PHP 8.1 runtime, Square SDK, test/lint scripts, no php-scoper Composer dependency.
- `tools/php-scoper.phar` or documented local PHAR path — build tool fetched by script, not solved inside runtime Composer graph.
- `scoper.inc.php` — prefixes vendor dependencies to `WCPOS\WooCommercePOS\SquareTerminal\Vendor`.
- `includes/Plugin.php` — gateway, AJAX, REST webhook registration.
- `includes/Gateway.php` — settings, order-pay payment UI, checkout `process_payment()` redirect behavior, script enqueue.
- `includes/Settings.php` — memoized settings access, environment/base URL, exact webhook notification URL.
- `includes/Logger.php` — sanitized WooCommerce logs and order notes.
- `includes/PaymentRequestToken.php` — short-lived order-bound token.
- `includes/OrderAccess.php` — staff/order-key/payment-token access proof.
- `includes/AjaxHandler.php` — admin/device and payment lifecycle endpoints.
- `includes/WebhookHandler.php` — REST endpoint, SDK helper signature verification, event idempotency, order completion.
- `includes/Services/SquareClientFactory.php` — builds scoped `SquareClient` with token, Square-Version override if needed, environment base URL.
- `includes/Services/SquareTerminalAdapter.php` — **only** class that builds real `CreateTerminalCheckoutRequest`, `GetCheckoutsRequest`, `CancelCheckoutsRequest` and reads typed responses.
- `includes/Services/SquareDeviceAdapter.php` — **only** class that builds real `CreateDeviceCodeRequest`, `GetCodesRequest` and reads typed responses.
- `includes/Services/WebhookSignatureVerifier.php` — wrapper around scoped `WebhooksHelper::verifySignature()`.
- `includes/Utils/CurrencyConverter.php` — minor-unit conversion.
- `assets/js/payment.js`, `assets/css/payment.css`, `assets/js/admin.js`, `assets/css/admin.css` — staff-facing UI/logging.
- `tests/bootstrap.php`, `tests/stubs/wordpress.php`, `tests/stubs/woocommerce.php` — stable test environment.
- `tests/includes/*Test.php` — behavioral tests.
- `docs/testing/square-terminal-validation.md` — local/hosted validation checklist and evidence.

---

## Task 0: Prepare repository/workspace

- [ ] **Step 1: Initialize git if the project is still an empty non-git directory**

Run:

```bash
git rev-parse --is-inside-work-tree || git init -b main
```

Expected: repo exists on `main`.

- [ ] **Step 2: Create an implementation branch/worktree if a remote/main repo exists**

If this repo later has a remote, follow the global worktree rule before code changes. If it remains a brand-new local repo with no remote, continue on a feature branch:

```bash
git checkout -b feat/square-terminal-working-plugin
```

Expected: not committing to `main`.

---

## Task 1: Bootstrap, PHP guards, and test scaffold

**Files:** create `composer.json`, `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/stubs/wordpress.php`, `tests/stubs/woocommerce.php`, `tests/includes/BootstrapGuardTest.php`, `square-terminal-for-woocommerce.php`.

- [ ] **Step 1: Write failing bootstrap/tests-first scaffold**

`tests/bootstrap.php` must load Composer and the test stubs before test classes:

```php
<?php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/wordpress.php';
require_once __DIR__ . '/stubs/woocommerce.php';
```

`tests/stubs/wordpress.php` must define only missing helpers used by units:

```php
<?php
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data ) { return json_encode( $data ); } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $value ) { return $value; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $value ) { return is_string( $value ) ? trim( $value ) : $value; } }
if ( ! function_exists( 'absint' ) ) { function absint( $value ) { return abs( (int) $value ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( '__' ) ) { function __( $text ) { return $text; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text ) { return $text; } }
```

`tests/stubs/woocommerce.php` must define a minimal gateway base before `Gateway` autoloads:

```php
<?php
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public string $id = '';
		public string $method_title = '';
		public string $method_description = '';
		public bool $has_fields = false;
		public array $form_fields = array();
		protected array $settings = array();
		public function init_form_fields(): void {}
		public function init_settings(): void {}
		public function get_option( $key, $default = '' ) { return $this->settings[ $key ] ?? $default; }
		public function process_admin_options() { return true; }
		public function get_return_url( $order = null ) { return '/thank-you'; }
	}
}
```

`BootstrapGuardTest` asserts:
- plugin header contains `Requires PHP:      8.1`,
- `version_compare( PHP_VERSION, '8.1', '<' )` appears before any vendor autoload,
- `register_activation_hook` is present,
- the plugin namespace autoloader is registered before vendor autoload so release builds can load `includes/` without `vendor/autoload.php`.

Run:

```bash
composer install
composer test -- --filter BootstrapGuardTest
```

Expected: FAIL because plugin file is not implemented.

- [ ] **Step 2: Implement bootstrap**

Key bootstrap requirements:

```php
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) { /* admin notice; return before vendor */ }

spl_autoload_register(
	function ( $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) { return; }
		$file = SQTWC_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( file_exists( $file ) ) { require $file; }
	}
);

$scoped_autoload = SQTWC_PLUGIN_DIR . 'vendor_scoped/autoload.php';
$dev_autoload    = SQTWC_PLUGIN_DIR . 'vendor/autoload.php';
```

Run:

```bash
composer test -- --filter BootstrapGuardTest
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add .
git commit -m "chore: scaffold square terminal bootstrap"
```

---

## Task 2: SDK scoping build without PHP 8.2 Composer conflict

**Files:** modify `composer.json`; create `scoper.inc.php`, `tests/includes/ScopedDependencyTest.php`, optional `tools/install-php-scoper.sh`.

- [ ] **Step 1: Write failing scoping tests**

Tests assert:
- `composer.json` does **not** require `humbug/php-scoper`,
- build script uses `php-scoper.phar` or `tools/php-scoper`,
- `scoper.inc.php` prefix is `WCPOS\WooCommercePOS\SquareTerminal\Vendor`,
- bootstrap prefers `vendor_scoped/autoload.php`.

Run:

```bash
composer test -- --filter ScopedDependencyTest
```

Expected: FAIL until scripts/config exist.

- [ ] **Step 2: Implement scoping script**

`composer.json` scripts:

```json
{
  "scripts": {
    "test": "phpunit --configuration phpunit.xml.dist",
    "lint": "phpcs --standard=./.phpcs.xml.dist",
    "build:scoped-vendor": [
      "test -f tools/php-scoper.phar || curl -L https://github.com/humbug/php-scoper/releases/latest/download/php-scoper.phar -o tools/php-scoper.phar",
      "rm -rf build/scoped vendor_scoped",
      "php tools/php-scoper.phar add-prefix --config=scoper.inc.php --output-dir=build/scoped --force",
      "mkdir -p vendor_scoped",
      "cp -R build/scoped/vendor/* vendor_scoped/"
    ]
  }
}
```

`scoper.inc.php` scopes `vendor/` only. Plugin classes are loaded by the plugin's own autoloader, not the scoped vendor autoloader.

- [ ] **Step 3: Build and verify scoped SDK class**

Run:

```bash
composer install
composer run build:scoped-vendor
php -r "require 'vendor_scoped/autoload.php'; echo class_exists('WCPOS\\WooCommercePOS\\SquareTerminal\\Vendor\\Square\\SquareClient') ? 'yes' : 'no';"
```

Expected: `yes`.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock scoper.inc.php tests/includes/ScopedDependencyTest.php tools/install-php-scoper.sh .gitignore
git commit -m "build: add scoped square sdk pipeline"
```

---

## Task 3: Settings, logging, gateway registration, and checkout redirect contract

**Files:** create `includes/Settings.php`, `includes/Logger.php`, `includes/Gateway.php`, `includes/Plugin.php`, tests.

- [ ] **Step 1: Write failing behavior tests**

Tests must cover:
- `Settings::get_gateway_settings()` memoizes one `get_option()` result per request and `Settings::reset_cache_for_tests()` clears it,
- sandbox/production access token selection,
- explicit `webhook_notification_url` setting,
- `Logger::sanitize_context()` masks token/signature/authorization values recursively,
- `Gateway::register_gateway()` appends `Gateway::class`,
- `Gateway::process_payment($order_id)` redirects unpaid orders to `order-pay` and returns thank-you for already-paid orders.

Run:

```bash
composer test -- --filter 'SettingsTest|LoggerTest|GatewayTest'
```

Expected: FAIL until classes exist.

- [ ] **Step 2: Implement settings/logger/gateway**

Important gateway contract:

```php
public function process_payment( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) { wc_add_notice( __( 'Order not found.', 'square-terminal-for-woocommerce' ), 'error' ); return array( 'result' => 'failure' ); }
	if ( $order->is_paid() ) { return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ); }
	return array( 'result' => 'success', 'redirect' => $order->get_checkout_payment_url( true ) );
}
```

This makes Storefront Checkout opt-in workable without trying to collect Terminal payment before a WooCommerce order exists. The interactive Square Terminal UI remains on order-pay/POS.

Run:

```bash
composer test -- --filter 'SettingsTest|LoggerTest|GatewayTest'
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add includes/Settings.php includes/Logger.php includes/Gateway.php includes/Plugin.php tests/includes/SettingsTest.php tests/includes/LoggerTest.php tests/includes/GatewayTest.php
git commit -m "feat: add gateway settings logging and checkout redirect"
```

---

## Task 4: Real Square SDK adapters and typed-object tests

**Files:** create `includes/Services/SquareClientFactory.php`, `SquareTerminalAdapter.php`, `SquareDeviceAdapter.php`, `WebhookSignatureVerifier.php`, `Utils/CurrencyConverter.php`, adapter tests.

- [ ] **Step 1: Inspect installed SDK before writing adapter code**

Run after `composer install`:

```bash
grep -R "class CreateTerminalCheckoutRequest" -n vendor/square/square/src
grep -R "class CreateDeviceCodeRequest" -n vendor/square/square/src
grep -R "class WebhooksHelper" -n vendor/square/square/src
grep -R "public function __construct" -n vendor/square/square/src/SquareClient.php | head
```

Expected current v45 facts:
- `SquareClient::__construct(?string $token = null, ?string $version = null, ?array $options = null)`
- `$client->terminal->checkouts->create(CreateTerminalCheckoutRequest $request)`
- `$client->devices->codes->create(CreateDeviceCodeRequest $request)`
- `Square\Utils\WebhooksHelper::verifySignature(...)`

- [ ] **Step 2: Write failing adapter tests using real SDK classes**

Tests must not use fake method-style `$client->terminal()->checkouts()` APIs.

Use a spy checkout client with the real method signature:

```php
final class SpyCheckoutsClient {
	public ?\WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Terminal\Checkouts\Requests\CreateTerminalCheckoutRequest $createdRequest = null;
	public function create( \WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Terminal\Checkouts\Requests\CreateTerminalCheckoutRequest $request ) {
		$this->createdRequest = $request;
		return new \WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\CreateTerminalCheckoutResponse(
			array(
				'checkout' => new \WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\TerminalCheckout(
					array(
						'id' => 'chk_123',
						'amountMoney' => new \WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Money( array( 'amount' => 1234, 'currency' => 'USD' ) ),
						'deviceOptions' => new \WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\DeviceCheckoutOptions( array( 'deviceId' => 'DEVICE123' ) ),
						'referenceId' => 'woocommerce_order_99',
						'status' => 'PENDING',
					)
				),
			)
		);
	}
}
```

The test asserts the adapter passes a real `CreateTerminalCheckoutRequest`, then reads:

```php
$request = $spy->createdRequest;
$checkout = $request->getCheckout();
self::assertSame( 1234, $checkout->getAmountMoney()->getAmount() );
self::assertSame( 'USD', $checkout->getAmountMoney()->getCurrency() );
self::assertSame( 'DEVICE123', $checkout->getDeviceOptions()->getDeviceId() );
self::assertSame( 'woocommerce_order_99', $checkout->getReferenceId() );
```

Device adapter tests use real `CreateDeviceCodeRequest` and `DeviceCode` and assert `productType`, `locationId`, and `name` through getters.

Run:

```bash
composer test -- --filter 'SquareTerminalAdapterTest|SquareDeviceAdapterTest|WebhookSignatureVerifierTest|CurrencyConverterTest'
```

Expected: FAIL until adapters exist.

- [ ] **Step 3: Implement SDK adapters with scoped imports only**

`SquareClientFactory`:

```php
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\SquareClient;

return new SquareClient(
	Settings::get_access_token(),
	null,
	array( 'baseUrl' => Settings::get_base_url() )
);
```

`SquareTerminalAdapter` builds:
- `Types\Money`
- `Types\DeviceCheckoutOptions`
- `Types\TerminalCheckout`
- `Terminal\Checkouts\Requests\CreateTerminalCheckoutRequest`
- `Terminal\Checkouts\Requests\GetCheckoutsRequest`
- `Terminal\Checkouts\Requests\CancelCheckoutsRequest`

It returns normalized arrays like:

```php
array(
  'id' => $checkout->getId(),
  'status' => $checkout->getStatus(),
  'reference_id' => $checkout->getReferenceId(),
  'payment_ids' => $checkout->getPaymentIds() ?? array(),
)
```

`WebhookSignatureVerifier` wraps scoped SDK helper:

```php
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Utils\WebhooksHelper;
return WebhooksHelper::verifySignature( $body, $signature, $key, $notification_url );
```

- [ ] **Step 4: Run adapter tests and scoped-reference grep**

Run:

```bash
composer test -- --filter 'SquareTerminalAdapterTest|SquareDeviceAdapterTest|WebhookSignatureVerifierTest|CurrencyConverterTest'
grep -R "use Square\\\|new Square\\\|\\Square\\" includes -n && exit 1 || true
```

Expected: tests PASS; grep finds no unscoped Square runtime references.

- [ ] **Step 5: Commit**

```bash
git add includes/Services includes/Utils tests/includes/*AdapterTest.php tests/includes/WebhookSignatureVerifierTest.php tests/includes/CurrencyConverterTest.php
git commit -m "feat: add real square sdk adapters"
```

---

## Task 5: Order access and AJAX lifecycle with behavioral tests

**Files:** `PaymentRequestToken.php`, `OrderAccess.php`, `AjaxHandler.php`, tests.

- [ ] **Step 1: Write failing behavioral tests**

Tests cover:
- token round-trip, wrong order, expiry,
- order access allows staff, matching order key, valid token,
- rejects order-id-only,
- `AjaxHandler::create_terminal_checkout()` returns 403 before adapter side effects when access fails,
- logged-in requests verify nonce; nopriv requests must pass order key or payment token.

Use Brain Monkey stubs for `wp_salt`, `current_user_can`, `wp_verify_nonce`, `wc_get_order`, `wp_send_json_*` wrappers or refactor handler methods to return arrays from pure internal methods that are easier to test.

Run:

```bash
composer test -- --filter 'PaymentRequestTokenTest|OrderAccessTest|AjaxHandlerTest'
```

Expected: FAIL until classes exist.

- [ ] **Step 2: Implement access and AJAX**

AJAX side-effect order:
1. resolve order,
2. verify nonce when applicable,
3. verify `OrderAccess`,
4. validate device/checkout input,
5. call adapter,
6. update order meta/order notes/logs,
7. return sanitized response.

Run tests. Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add includes/PaymentRequestToken.php includes/OrderAccess.php includes/AjaxHandler.php tests/includes/PaymentRequestTokenTest.php tests/includes/OrderAccessTest.php tests/includes/AjaxHandlerTest.php
git commit -m "feat: secure square terminal payment ajax"
```

---

## Task 6: Webhook handling, exact URL setting, and idempotency

**Files:** `WebhookHandler.php`, update `Settings.php`, tests.

- [ ] **Step 1: Write failing webhook behavioral tests**

Tests cover:
- invalid signature rejects before order lookup/completion,
- notification URL is read from `Settings::get_webhook_notification_url()` not derived with `rest_url()` only,
- wrong `reference_id` rejects,
- valid `terminal.checkout.updated` with `COMPLETED` completes unpaid order,
- duplicate Square `event_id` is ignored idempotently,
- processed event IDs are stored on order meta.

Run:

```bash
composer test -- --filter WebhookHandlerTest
```

Expected: FAIL until handler exists.

- [ ] **Step 2: Implement webhook handler**

Key rules:
- Use header `x-square-hmacsha256-signature`.
- Verify against explicit `webhook_notification_url` setting. Admin UI must display the expected URL and warn it must exactly match Square Dashboard.
- Process only `terminal.checkout.updated` initially.
- Require `reference_id = woocommerce_order_<id>`.
- Store `_sqtwc_processed_event_ids` and skip duplicate event IDs.
- On `COMPLETED`, save payment IDs, set transaction ID, call `payment_complete()` only if not paid.

Run tests. Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add includes/WebhookHandler.php includes/Settings.php tests/includes/WebhookHandlerTest.php
git commit -m "feat: process verified square terminal webhooks"
```

---

## Task 7: Payment/admin UI and comprehensive logging

**Files:** `Gateway.php`, `assets/js/payment.js`, `assets/css/payment.css`, `assets/js/admin.js`, `assets/css/admin.css`, UI tests.

- [ ] **Step 1: Write failing UI/source tests plus manual checklist hooks**

Tests assert:
- payment UI is order-pay/POS centered and includes Payment Log,
- checkout `process_payment()` redirects unpaid standard checkout orders to order-pay,
- admin UI shows exact webhook URL field/help text,
- admin UI calls device code and validation endpoints,
- JS logs start/cancel/status/error messages.

Run:

```bash
composer test -- --filter 'PaymentFrontendTest|AdminUiTest|GatewayTest'
```

Expected: FAIL until UI exists.

- [ ] **Step 2: Implement minimal UI**

Payment UI:
- Device ID input or paired device selector if stored devices are available,
- Start Payment,
- Cancel Payment,
- status region,
- read-only on-page Payment Log.

Admin UI:
- Validate settings,
- Create Device Code,
- show exact webhook notification URL to copy into Square Dashboard,
- explain `dev-pro.wcpos.com` webhook validation path.

Run tests. Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add includes/Gateway.php assets/js assets/css tests/includes/PaymentFrontendTest.php tests/includes/AdminUiTest.php
git commit -m "feat: add square terminal ui and payment log"
```

---

## Task 8: Lint/build verification and dependency resolution

**Files:** `composer.json`, `.phpcs.xml.dist`, validation docs.

- [ ] **Step 1: Add lint stack carefully**

Add WPCS/WooCommerce sniffs only after checking dependency resolution under PHP 8.1 platform:

```bash
composer require --dev dealerdirect/phpcodesniffer-composer-installer squizlabs/php_codesniffer wp-coding-standards/wpcs phpcompatibility/phpcompatibility-wp sirbrillig/phpcs-variable-analysis --no-interaction
composer require --dev woocommerce/woocommerce-sniffs --no-interaction
```

If WooCommerce sniffs conflict with WPCS 3, either pin compatible versions verified by Composer or temporarily use WPCS/PHPCompatibility only and file a follow-up issue.

- [ ] **Step 2: Run full verification**

```bash
composer validate
composer test
composer run lint
composer run build:scoped-vendor
php -r "require 'vendor_scoped/autoload.php'; echo class_exists('WCPOS\\WooCommercePOS\\SquareTerminal\\Vendor\\Square\\SquareClient') ? 'yes' : 'no';"
grep -R "use Square\\\|new Square\\\|\\Square\\" includes square-terminal-for-woocommerce.php -n && exit 1 || true
```

Expected: all pass; final class check prints `yes`; no unscoped Square references.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock .phpcs.xml.dist docs/testing/square-terminal-validation.md
git commit -m "chore: add verification tooling"
```

---

## Task 9: Local `wcpos.local` validation

- [ ] Discover local WP root with `find /Users/kilbot -maxdepth 4 -name wp-config.php`.
- [ ] Build scoped vendor.
- [ ] Copy plugin to local `wp-content/plugins/square-terminal-for-woocommerce` excluding unscoped `vendor/`, tests, build cache.
- [ ] Activate via WP-CLI.
- [ ] Validate gateway settings, missing-credential messages, Device Code UI, exact webhook URL display, order-pay UI, Payment Log.
- [ ] Verify AJAX rejects order-id-only requests.
- [ ] Verify webhook simulation is admin-only/debug-only and uses the same processing function as real webhook.
- [ ] Record evidence in `docs/testing/square-terminal-validation.md`.

---

## Task 10: Hosted `dev-pro.wcpos.com` Square Sandbox validation

- [ ] Use `ssh wcpos-prod` to locate the `dev-pro.wcpos.com` WordPress root.
- [ ] Upload/install a release zip containing `vendor_scoped/` and no unscoped `vendor/`.
- [ ] Configure Square Sandbox credentials when provided.
- [ ] Configure Square Dashboard webhook URL to exactly match the plugin `webhook_notification_url` setting.
- [ ] Create Device Code and pair Terminal Device.
- [ ] Create order, start Terminal Checkout, complete/cancel Sandbox flow.
- [ ] Verify Square webhook signature passes, order completes, logs/order notes are sanitized and useful.
- [ ] Record non-secret evidence in validation docs.

---

## Task 11: Final verification and PR/draft PR

- [ ] Run full local verification from Task 8.
- [ ] Run `wcpos.local` checklist.
- [ ] Run `dev-pro.wcpos.com` checklist if credentials are available.
- [ ] If hosted Square credentials are not yet available, open a draft PR with local validation complete and hosted validation blocked on credentials.
- [ ] Before push, run branch safety checks:

```bash
git status --short
git branch -vv | grep "$(git branch --show-current)"
gh pr list --head "$(git branch --show-current)" --state all
```

- [ ] Push and open PR with exact validation commands/results.
