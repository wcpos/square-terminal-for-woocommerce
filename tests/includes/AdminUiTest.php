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

	public function test_admin_ui_shows_exact_webhook_url_help_and_device_buttons(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'webhook_notification_url' => 'https://dev-pro.wcpos.com/wp-json/sqtwc/v1/webhook' );
		Settings::reset_cache_for_tests();

		$html = Gateway::render_admin_fields();

		self::assertStringContainsString( 'exactly match Square Developer Dashboard', $html );
		self::assertStringContainsString( 'https://dev-pro.wcpos.com/wp-json/sqtwc/v1/webhook', $html );
		self::assertStringContainsString( 'Create Device Code', $html );
	}

	public function test_admin_js_calls_device_code_and_validation_endpoints(): void {
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin.js' ) ?: '';

		self::assertStringContainsString( 'create_device_code', $js );
		self::assertStringContainsString( 'validate_settings', $js );
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
		self::assertStringNotContainsString( '<button id=', $html );
		self::assertSame( 2, substr_count( $html, 'type="button"' ) );
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
