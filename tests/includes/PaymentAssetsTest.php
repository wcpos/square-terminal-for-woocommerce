<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

final class PaymentAssetsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array();
		$GLOBALS['sqtwc_transients'] = array();
		Settings::reset_cache_for_tests();
	}

	public function test_sandbox_devices_are_squares_magic_test_ids(): void {
		$devices = Gateway::get_available_devices( 'sandbox' );
		$ids     = array_map( static fn( $d ) => $d['id'], $devices );

		self::assertContains( '9fa747a2-25ff-48ee-b078-04381f7c828f', $ids ); // Success.
		self::assertContains( '0a956d49-619a-4530-8e5e-8eac603ffc5e', $ids ); // Timeout.
		self::assertContains( 'da40d603-c2ea-4a65-8cfd-f42e36dab0c7', $ids ); // Offline.
		self::assertCount( 5, $devices );
		foreach ( $devices as $device ) {
			self::assertArrayHasKey( 'label', $device );
			self::assertNotSame( '', $device['label'] );
		}
	}

	public function test_production_device_list_uses_environment_and_location_cache(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment'             => 'production',
			'production_access_token' => 'token',
			'location_id'             => 'LOC',
		);
		Settings::reset_cache_for_tests();
		$key = Gateway::get_device_cache_key( 'production', 'LOC' );
		$GLOBALS['sqtwc_transients'][ $key ] = array(
			'value'      => array( array( 'id' => 'device_1', 'label' => 'Front' ) ),
			'expiration' => 300,
		);

		self::assertSame(
			array( array( 'id' => 'device_1', 'label' => 'Front' ) ),
			Gateway::get_available_devices( 'production' )
		);
	}

	public function test_saving_gateway_settings_clears_cached_devices(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment' => 'production',
			'location_id' => 'LOC',
		);
		Settings::reset_cache_for_tests();
		$key = Gateway::get_device_cache_key( 'production', 'LOC' );
		$GLOBALS['sqtwc_transients'][ $key ] = array( 'value' => array(), 'expiration' => 300 );

		( new Gateway() )->process_admin_options();

		self::assertArrayNotHasKey( $key, $GLOBALS['sqtwc_transients'] );
	}

	public function test_localized_payment_data_carries_ajax_contract(): void {
		$data = Gateway::get_localized_payment_data();

		self::assertSame( 'sqtwc_create_terminal_checkout', $data['actions']['create'] );
		self::assertSame( 'sqtwc_cancel_terminal_checkout', $data['actions']['cancel'] );
		self::assertSame( 'sqtwc_get_terminal_status', $data['actions']['status'] );
		self::assertSame( 'sqtwc_detach_terminal_checkout', $data['actions']['detach'] );

		self::assertSame( 2000, $data['poll']['cadenceMs'] );
		self::assertSame( 15000, $data['poll']['backoffCapMs'] );
		self::assertSame( 3, $data['poll']['unstableAfter'] );
		self::assertSame( 330000, $data['poll']['deadlineMs'] );

		self::assertSame( 'sandbox', $data['environment'] );
		self::assertNotEmpty( $data['devices'] );
		self::assertArrayHasKey( 'startPayment', $data['strings'] );
		self::assertArrayHasKey( 'connectionUnstable', $data['strings'] );
		self::assertFalse( $data['debugLog'] );
	}

	public function test_debug_log_flag_reflects_setting(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'checkout_debug_logs' => 'yes' );
		Settings::reset_cache_for_tests();

		$data = Gateway::get_localized_payment_data();
		self::assertTrue( $data['debugLog'] );
	}

	public function test_checkout_debug_logs_setting_registered(): void {
		$gateway = new Gateway();
		self::assertArrayHasKey( 'checkout_debug_logs', $gateway->form_fields );
		self::assertSame( 'checkbox', $gateway->form_fields['checkout_debug_logs']['type'] );
	}
}
