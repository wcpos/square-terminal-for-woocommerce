<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Services\WooCommerceSquareHints;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

final class WooCommerceSquareHintsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_options'] = array();
		$GLOBALS['sqtwc_wc_square_handler'] = null;
		$GLOBALS['sqtwc_wc_square_throws'] = false;
		$GLOBALS['sqtwc_get_option_throws'] = false;
		WooCommerceSquareHints::reset_cache_for_tests();
		Settings::reset_cache_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['sqtwc_wc_square_handler'] = null;
		$GLOBALS['sqtwc_wc_square_throws'] = false;
		$GLOBALS['sqtwc_get_option_throws'] = false;
		WooCommerceSquareHints::reset_cache_for_tests();
	}

	public function test_no_hints_when_the_official_plugin_is_absent(): void {
		$hints = WooCommerceSquareHints::detect();

		self::assertSame( '', $hints['location_id'] );
		self::assertFalse( WooCommerceSquareHints::has_hints() );
	}

	public function test_reads_production_location_from_the_settings_option(): void {
		$GLOBALS['sqtwc_options']['wc_square_settings'] = array(
			'enable_sandbox'          => 'no',
			'production_location_id'  => 'LEZ34XC61CBNQ',
			'sandbox_location_id'     => 'LSANDBOX123',
			'production_access_token' => 'must-not-be-read',
		);

		$hints = WooCommerceSquareHints::detect();

		self::assertSame( 'production', $hints['environment'] );
		self::assertSame( 'LEZ34XC61CBNQ', $hints['location_id'] );
	}

	public function test_reads_sandbox_location_when_sandbox_is_enabled(): void {
		$GLOBALS['sqtwc_options']['wc_square_settings'] = array(
			'enable_sandbox'         => 'yes',
			'production_location_id' => 'LPROD',
			'sandbox_location_id'    => 'LSANDBOX123',
		);

		$hints = WooCommerceSquareHints::detect();

		self::assertSame( 'sandbox', $hints['environment'] );
		self::assertSame( 'LSANDBOX123', $hints['location_id'] );
	}

	public function test_never_exposes_credentials(): void {
		$GLOBALS['sqtwc_options']['wc_square_settings'] = array(
			'enable_sandbox'          => 'no',
			'production_location_id'  => 'LEZ34XC61CBNQ',
			'production_access_token' => 'EAAA-secret-token',
			'sandbox_access_token'    => 'EAAA-sandbox-secret',
			'refresh_token'           => 'refresh-secret',
		);

		$hints = WooCommerceSquareHints::detect();

		self::assertSame( array( 'environment', 'location_id' ), array_keys( $hints ) );
		self::assertStringNotContainsString( 'secret', wp_json_encode( $hints ) );
	}

	public function test_rejects_a_malformed_location_id(): void {
		$GLOBALS['sqtwc_options']['wc_square_settings'] = array(
			'enable_sandbox'         => 'no',
			'production_location_id' => '<script>alert(1)</script>',
		);

		self::assertSame( '', WooCommerceSquareHints::detect()['location_id'] );
	}

	public function test_public_api_takes_precedence_over_the_option(): void {
		$GLOBALS['sqtwc_options']['wc_square_settings'] = array(
			'enable_sandbox'         => 'no',
			'production_location_id' => 'LFROMOPTION',
		);
		$GLOBALS['sqtwc_wc_square_handler'] = new FakeSquareSettingsHandler( 'sandbox', 'LFROMAPI' );

		$hints = WooCommerceSquareHints::detect();

		self::assertSame( 'sandbox', $hints['environment'] );
		self::assertSame( 'LFROMAPI', $hints['location_id'] );
	}

	public function test_option_location_uses_the_public_api_environment(): void {
		$GLOBALS['sqtwc_options']['wc_square_settings'] = array(
			'enable_sandbox'         => 'no',
			'production_location_id' => 'LPRODUCTION',
			'sandbox_location_id'    => 'LSANDBOX',
		);
		$GLOBALS['sqtwc_wc_square_handler'] = new FakeSquareSettingsHandler( 'sandbox', '' );

		$hints = WooCommerceSquareHints::detect();

		self::assertSame( 'sandbox', $hints['environment'] );
		self::assertSame( 'LSANDBOX', $hints['location_id'] );
	}

	public function test_option_fills_a_missing_environment_without_replacing_the_api_location(): void {
		$GLOBALS['sqtwc_options']['wc_square_settings'] = array(
			'enable_sandbox'      => 'yes',
			'sandbox_location_id' => 'LFROMOPTION',
		);
		$GLOBALS['sqtwc_wc_square_handler'] = new FakeSquareSettingsHandler( '', 'LFROMAPI' );

		$hints = WooCommerceSquareHints::detect();

		self::assertSame( 'sandbox', $hints['environment'] );
		self::assertSame( 'LFROMAPI', $hints['location_id'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_option_fallback_respects_the_sandbox_constant(): void {
		define( 'WC_SQUARE_SANDBOX', true );
		$GLOBALS['sqtwc_options']['wc_square_settings'] = array(
			'enable_sandbox'         => 'no',
			'production_location_id' => 'LPRODUCTION',
			'sandbox_location_id'    => 'LSANDBOX',
		);

		$hints = WooCommerceSquareHints::detect();

		self::assertSame( 'sandbox', $hints['environment'] );
		self::assertSame( 'LSANDBOX', $hints['location_id'] );
	}

	public function test_a_throwing_option_filter_never_breaks_setup(): void {
		$GLOBALS['sqtwc_get_option_throws'] = true;

		self::assertSame(
			array( 'environment' => '', 'location_id' => '' ),
			WooCommerceSquareHints::detect()
		);
	}

	public function test_a_throwing_official_plugin_never_breaks_setup(): void {
		$GLOBALS['sqtwc_wc_square_throws'] = true;
		$GLOBALS['sqtwc_options']['wc_square_settings'] = array(
			'enable_sandbox'         => 'no',
			'production_location_id' => 'LFALLBACK',
		);

		$hints = WooCommerceSquareHints::detect();

		// The failure is silent and the option fallback still supplies a hint.
		self::assertSame( 'LFALLBACK', $hints['location_id'] );
	}

	public function test_settings_form_prefills_location_and_explains_why(): void {
		$GLOBALS['sqtwc_options']['wc_square_settings'] = array(
			'enable_sandbox'         => 'no',
			'production_location_id' => 'LEZ34XC61CBNQ',
		);

		$gateway = new Gateway();

		self::assertSame( 'LEZ34XC61CBNQ', $gateway->form_fields['location_id']['default'] );
		self::assertSame( 'production', $gateway->form_fields['environment']['default'] );
		self::assertStringContainsString( 'WooCommerce Square', $gateway->form_fields['location_id']['description'] );
	}

	public function test_settings_form_is_unchanged_without_hints(): void {
		$gateway = new Gateway();

		self::assertSame( '', $gateway->form_fields['location_id']['default'] );
		self::assertSame( '', $gateway->form_fields['location_id']['description'] );
		self::assertSame( 'sandbox', $gateway->form_fields['environment']['default'] );
	}

	public function test_prefill_never_overrides_a_saved_location(): void {
		$GLOBALS['sqtwc_options']['wc_square_settings']          = array(
			'enable_sandbox'         => 'no',
			'production_location_id' => 'LFROMSQUARE',
		);
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'location_id' => 'LMINE' );
		Settings::reset_cache_for_tests();

		// A default only fills an unset field; the stored value still wins.
		self::assertSame( 'LMINE', Settings::get_location_id() );
	}
}

final class FakeSquareSettingsHandler {
	private string $environment;
	private string $location_id;

	public function __construct( string $environment, string $location_id ) {
		$this->environment = $environment;
		$this->location_id = $location_id;
	}

	public function get_environment(): string {
		return $this->environment;
	}

	public function get_location_id(): string {
		return $this->location_id;
	}
}
