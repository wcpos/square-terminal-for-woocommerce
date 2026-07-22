<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

final class AdminUiTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array();
		$GLOBALS['sqtwc_registered_scripts']                    = array();
		$GLOBALS['sqtwc_enqueued_scripts']                      = array();
		$GLOBALS['sqtwc_localized_scripts']                     = array();
		$_GET                                                   = array();
		Gateway::reset_device_memo();
		Settings::reset_cache_for_tests();
	}

	protected function tearDown(): void {
		$_GET = array();
	}

	/**
	 * Put the request on the gateway's own settings section.
	 */
	private function on_gateway_settings_screen(): void {
		$_GET = array(
			'page'    => 'wc-settings',
			'tab'     => 'checkout',
			'section' => 'sqtwc',
		);
	}

	public function test_admin_ui_shows_the_pairing_controls(): void {
		$html = Gateway::render_admin_fields();

		self::assertStringContainsString( 'Create Device Code', $html );
		self::assertStringContainsString( 'Validate Settings', $html );
		self::assertStringContainsString( 'sqtwc-admin-status', $html );
	}

	public function test_pairing_row_does_not_duplicate_the_webhook_url_field(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'webhook_notification_url' => 'https://example.test/wp-json/sqtwc/v1/webhook' );
		Gateway::reset_device_memo();
		Settings::reset_cache_for_tests();

		$html = Gateway::render_admin_fields();

		// The Webhook Notification URL is its own settings field directly above
		// this row; repeating it here as a second input was confusing.
		self::assertStringNotContainsString( 'https://example.test/wp-json/sqtwc/v1/webhook', $html );
		self::assertStringNotContainsString( '<input', $html );
	}

	public function test_admin_js_calls_device_code_and_validation_endpoints(): void {
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin.js' ) ?: '';

		self::assertStringContainsString( 'create_device_code', $js );
		self::assertStringContainsString( 'validate_settings', $js );
	}

	public function test_admin_js_keeps_the_connect_label_and_link_in_step(): void {
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin.js' ) ?: '';

		// Updating the URL alone would leave the button reading "sandbox" while
		// starting a production authorization — worse than the original bug.
		self::assertStringContainsString( 'sqtwc-connect-link', $js );
		self::assertStringContainsString( 'connectLabel', $js );
		self::assertStringContainsString( 'textContent', $js );
	}

	public function test_admin_js_copies_the_webhook_url_with_a_fallback(): void {
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin.js' ) ?: '';

		self::assertStringContainsString( 'sqtwc-copy-webhook', $js );
		// The async Clipboard API needs a secure context and plenty of WordPress
		// admins are served over plain http, so a fallback is required.
		self::assertStringContainsString( 'navigator.clipboard', $js );
		self::assertStringContainsString( 'execCommand', $js );
		// execCommand returns false when the browser blocks legacy copying,
		// without throwing, so the button must not claim success regardless.
		self::assertStringContainsString( "execCommand('copy') === true", $js );
		self::assertStringContainsString( 'data-failed', $js );
	}

	public function test_admin_js_binds_buttons_and_sends_the_nonce(): void {
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin.js' ) ?: '';

		self::assertStringContainsString( 'sqtwc-create-device-code', $js );
		self::assertStringContainsString( 'sqtwc-validate-settings', $js );
		self::assertStringContainsString( 'addEventListener', $js );
		self::assertStringContainsString( '_wpnonce', $js );
	}

	public function test_pairing_controls_use_non_submitting_buttons(): void {
		$html = Gateway::render_admin_fields();

		// Inside the WooCommerce settings <form>, a button without an explicit
		// type defaults to submit and would save the settings page instead.
		// Asserted as a ratio rather than a count so adding a control cannot
		// silently reintroduce a submitting button.
		self::assertStringNotContainsString( '<button id=', $html );
		self::assertGreaterThan( 0, substr_count( $html, '<button' ) );
		self::assertSame( substr_count( $html, '<button' ), substr_count( $html, 'type="button"' ) );
	}

	public function test_reader_list_controls_are_present(): void {
		$html = Gateway::render_admin_fields();

		self::assertStringContainsString( 'sqtwc-check-readers', $html );
		self::assertStringContainsString( 'sqtwc-reader-list', $html );
	}

	public function test_admin_js_renders_readers_without_html_interpolation(): void {
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin.js' ) ?: '';

		self::assertStringContainsString( 'sqtwc_list_devices', $js );
		// Device names come from Square; they must never be written as markup.
		self::assertStringContainsString( 'textContent', $js );
		self::assertStringNotContainsString( 'innerHTML', $js );
	}

	public function test_pairing_field_is_registered_on_the_gateway(): void {
		$gateway = new Gateway();

		self::assertArrayHasKey( 'terminal_pairing', $gateway->form_fields );
		self::assertSame( 'terminal_pairing', $gateway->form_fields['terminal_pairing']['type'] );
	}

	public function test_pairing_field_renders_a_settings_row(): void {
		$gateway = new Gateway();

		$html = $gateway->generate_terminal_pairing_html( 'terminal_pairing', array( 'title' => 'Terminal pairing' ) );

		self::assertStringContainsString( '<tr', $html );
		self::assertStringContainsString( 'Terminal pairing', $html );
		self::assertStringContainsString( 'sqtwc-create-device-code', $html );
		self::assertStringContainsString( 'sqtwc-admin-status', $html );
	}

	public function test_admin_assets_are_not_enqueued_outside_the_gateway_settings_section(): void {
		$_GET = array(
			'page'    => 'wc-settings',
			'tab'     => 'checkout',
			'section' => 'stripe',
		);

		( new Gateway() )->enqueue_admin_assets();

		self::assertSame( array(), $GLOBALS['sqtwc_enqueued_scripts'] );
	}

	public function test_admin_assets_enqueue_with_the_ajax_contract_and_nonce(): void {
		$this->on_gateway_settings_screen();

		( new Gateway() )->enqueue_admin_assets();

		self::assertContains( 'sqtwc-admin', $GLOBALS['sqtwc_enqueued_scripts'] );
		self::assertArrayHasKey( 'sqtwc-admin', $GLOBALS['sqtwc_localized_scripts'] );

		$localized = $GLOBALS['sqtwc_localized_scripts']['sqtwc-admin'];
		self::assertSame( 'sqtwcAdmin', $localized['object'] );
		self::assertNotSame( '', (string) $localized['data']['nonce'] );
		self::assertStringContainsString( 'admin-ajax.php', (string) $localized['data']['ajaxUrl'] );
	}
}
