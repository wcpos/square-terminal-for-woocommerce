<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareClientFactory;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareDeviceAdapter;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

final class FailingSquareClientFactory {
	public static int $calls = 0;

	public function create(): object {
		++self::$calls;
		throw new \RuntimeException( 'Square unavailable' );
	}
}

final class EmptySquareClientFactory {
	public function create(): object {
		return new \stdClass();
	}
}

final class EmptySquareDeviceAdapter {
	public function __construct( object $client ) {
		unset( $client );
	}

	public function list_paired_devices( string $location_id ): array {
		unset( $location_id );

		return array();
	}
}

final class PaymentAssetsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array();
		$GLOBALS['sqtwc_transients']                              = array();
		$GLOBALS['sqtwc_registered_scripts']                      = array();
		$GLOBALS['sqtwc_enqueued_scripts']                        = array();
		$GLOBALS['sqtwc_registered_styles']                       = array();
		$GLOBALS['sqtwc_enqueued_styles']                         = array();
		$GLOBALS['sqtwc_is_checkout']                             = false;
		$GLOBALS['sqtwc_is_checkout_pay_page']                    = false;
		Settings::reset_cache_for_tests();
	}

	public function test_checkout_enqueues_payment_assets(): void {
		$GLOBALS['sqtwc_is_checkout'] = true;

		( new Gateway() )->enqueue_payment_assets();

		// Regression guard: 0.2.2 restored the cashier controls on WCPOS
		// checkouts, which are not always is_checkout_pay_page(). Narrowing this
		// gate to the pay page alone stops the assets loading in the POS.
		self::assertContains( 'sqtwc-payment', $GLOBALS['sqtwc_enqueued_scripts'] );
		self::assertContains( 'sqtwc-payment', $GLOBALS['sqtwc_enqueued_styles'] );
	}

	public function test_order_pay_page_enqueues_payment_assets(): void {
		$GLOBALS['sqtwc_is_checkout_pay_page'] = true;

		( new Gateway() )->enqueue_payment_assets();

		self::assertContains( 'sqtwc-payment', $GLOBALS['sqtwc_enqueued_scripts'] );
	}

	public function test_ordinary_request_does_not_enqueue_payment_assets(): void {
		( new Gateway() )->enqueue_payment_assets();

		self::assertNotContains( 'sqtwc-payment', $GLOBALS['sqtwc_enqueued_scripts'] );
		self::assertNotContains( 'sqtwc-payment', $GLOBALS['sqtwc_enqueued_styles'] );
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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_production_device_list_uses_last_known_good_devices_after_discovery_failure(): void {
		class_alias( FailingSquareClientFactory::class, SquareClientFactory::class );
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment'             => 'production',
			'production_access_token' => 'token',
			'location_id'             => 'LOC',
		);
		Settings::reset_cache_for_tests();
		$key     = Gateway::get_device_cache_key( 'production', 'LOC' );
		$devices = array( array( 'id' => 'device_1', 'label' => 'Front' ) );
		$GLOBALS['sqtwc_transients'][ $key . '_last_known_good' ] = array(
			'value'      => $devices,
			'expiration' => 0,
		);

		self::assertSame( $devices, Gateway::get_available_devices( 'production' ) );
		self::assertSame( $devices, Gateway::get_available_devices( 'production' ) );
		self::assertSame( 1, FailingSquareClientFactory::$calls );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_empty_production_device_list_uses_short_cache_lifetime(): void {
		class_alias( EmptySquareClientFactory::class, SquareClientFactory::class );
		class_alias( EmptySquareDeviceAdapter::class, SquareDeviceAdapter::class );
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment'             => 'production',
			'production_access_token' => 'token',
			'location_id'             => 'LOC',
		);
		Settings::reset_cache_for_tests();
		$key = Gateway::get_device_cache_key( 'production', 'LOC' );

		self::assertSame( array(), Gateway::get_available_devices( 'production' ) );
		self::assertSame( 30, $GLOBALS['sqtwc_transients'][ $key ]['expiration'] );
	}

	public function test_saving_gateway_settings_clears_cached_devices(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment' => 'production',
			'location_id' => 'LOC',
		);
		Settings::reset_cache_for_tests();
		$key = Gateway::get_device_cache_key( 'production', 'LOC' );
		$GLOBALS['sqtwc_transients'][ $key ] = array( 'value' => array(), 'expiration' => 300 );
		$GLOBALS['sqtwc_transients'][ $key . '_last_known_good' ] = array( 'value' => array(), 'expiration' => 0 );

		( new Gateway() )->process_admin_options();

		self::assertArrayNotHasKey( $key, $GLOBALS['sqtwc_transients'] );
		self::assertArrayNotHasKey( $key . '_last_known_good', $GLOBALS['sqtwc_transients'] );
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
