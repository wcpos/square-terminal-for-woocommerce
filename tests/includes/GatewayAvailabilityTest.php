<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

/** The gateway is POS-only: never the shop's checkout, the order-pay page only for POS staff. */
final class GatewayAvailabilityTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'environment' => 'sandbox', 'sandbox_access_token' => 'sb', 'location_id' => 'LOC1' );
		$GLOBALS['sqtwc_pos_settings'] = array( 'payment_gateways' => array( 'gateways' => array( 'sqtwc' => array( 'enabled' => true ) ) ) );
		unset( $GLOBALS['sqtwc_pos_request'], $GLOBALS['sqtwc_is_checkout_pay_page'], $GLOBALS['sqtwc_current_user_can'], $GLOBALS['sqtwc_is_checkout'] );
		Settings::reset_cache_for_tests();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sqtwc_pos_request'], $GLOBALS['sqtwc_is_checkout_pay_page'], $GLOBALS['sqtwc_current_user_can'], $GLOBALS['sqtwc_pos_settings'] );
		Settings::reset_cache_for_tests();
	}

	public function test_a_pos_request_gets_the_gateway_once_configured(): void {
		$GLOBALS['sqtwc_pos_request'] = true;
		self::assertTrue( ( new Gateway() )->is_available() );
	}

	public function test_unconfigured_credentials_hide_it_everywhere(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings']['location_id'] = '';
		Settings::reset_cache_for_tests();
		$GLOBALS['sqtwc_pos_request'] = true;
		self::assertFalse( ( new Gateway() )->is_available() );
	}

	public function test_the_shop_checkout_never_gets_it_and_a_saved_enabled_flag_counts_for_nothing(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings']['enabled'] = 'yes';
		Settings::reset_cache_for_tests();
		$GLOBALS['sqtwc_is_checkout'] = true;
		self::assertFalse( ( new Gateway() )->is_available() );
	}

	public function test_the_order_pay_page_needs_pos_staff_and_the_pos_switch(): void {
		$GLOBALS['sqtwc_is_checkout_pay_page'] = true;
		self::assertFalse( ( new Gateway() )->is_available(), 'A shopper on the pay page' );
		$GLOBALS['sqtwc_current_user_can'] = true;
		self::assertTrue( ( new Gateway() )->is_available(), 'POS staff with the POS switch on' );
		$GLOBALS['sqtwc_pos_settings']['payment_gateways']['gateways']['sqtwc']['enabled'] = false;
		self::assertTrue( ( new Gateway() )->is_available(), 'The POS switch is read once per request' );
		Settings::reset_cache_for_tests();
		self::assertFalse( ( new Gateway() )->is_available(), 'POS switch off' );
	}
}
