<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Plugin;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

final class OrderStatusCancelHandler {
	public int $calls = 0;
	public array $args = array();
	public bool $throw = false;

	public function cancel_terminal_checkout_for_order( $order, string $checkout_id, string $device_id, array $options = array() ): array {
		++$this->calls;
		$this->args = func_get_args();
		if ( $this->throw ) {
			throw new \RuntimeException( 'outage' );
		}

		return array( 'status' => 'CANCEL_REQUESTED' );
	}
}

final class AdminDeviceAdapter {
	public int $creates = 0;
	public int $validations = 0;
	public array $create_data = array();
	public ?\Throwable $exception = null;

	public function create_device_code( array $data ): array {
		++$this->creates;
		$this->create_data = $data;
		if ( $this->exception ) {
			throw $this->exception;
		}

		return array( 'code' => 'PAIR-ME' );
	}

	public function validate_location( string $location_id ): void {
		++$this->validations;
		if ( $this->exception ) {
			throw $this->exception;
		}
	}
}

final class PluginTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['sqtwc_orders']           = array();
		$GLOBALS['sqtwc_options']          = array();
		$GLOBALS['sqtwc_transients']       = array();
		$GLOBALS['sqtwc_current_user_can'] = true;
		$GLOBALS['sqtwc_nonce_valid']      = true;
		Settings::reset_cache_for_tests();
	}

	public function test_order_status_change_cancels_open_attempt_with_eight_second_timeout(): void {
		$order = new \SQTWC_Test_Order( 99 );
		$order->update_meta_data( '_sqtwc_current_attempt_id', 'attempt' );
		$order->update_meta_data( '_sqtwc_checkout_id', 'chk' );
		$order->update_meta_data( '_sqtwc_device_id', 'device' );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$handler = new OrderStatusCancelHandler();

		( new Plugin( $handler ) )->cancel_open_attempt_on_order_status_change( 99, 'pending', 'processing', $order );

		self::assertSame( 1, $handler->calls );
		self::assertSame( 'chk', $handler->args[1] );
		self::assertSame( 'device', $handler->args[2] );
		self::assertSame( 8.0, $handler->args[3]['timeout'] );
		self::assertSame( 0, $handler->args[3]['maxRetries'] );
	}

	public function test_order_status_cancel_failure_never_escapes_or_runs_for_unrelated_status(): void {
		$order = new \SQTWC_Test_Order( 99 );
		$order->update_meta_data( '_sqtwc_current_attempt_id', 'attempt' );
		$order->update_meta_data( '_sqtwc_checkout_id', 'chk' );
		$order->update_meta_data( '_sqtwc_device_id', 'device' );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$handler        = new OrderStatusCancelHandler();
		$handler->throw = true;
		$plugin         = new Plugin( $handler );

		$plugin->cancel_open_attempt_on_order_status_change( 99, 'pending', 'on-hold', $order );
		self::assertSame( 0, $handler->calls );

		$plugin->cancel_open_attempt_on_order_status_change( 99, 'pending', 'failed', $order );
		self::assertSame( 1, $handler->calls );
	}

	public function test_registers_admin_device_actions_without_nopriv_variants(): void {
		$GLOBALS['sqtwc_actions'] = array();

		( new Plugin() )->init();

		self::assertArrayHasKey( 'wp_ajax_sqtwc_create_device_code', $GLOBALS['sqtwc_actions'] );
		self::assertArrayHasKey( 'wp_ajax_sqtwc_validate_settings', $GLOBALS['sqtwc_actions'] );
		self::assertArrayNotHasKey( 'wp_ajax_nopriv_sqtwc_create_device_code', $GLOBALS['sqtwc_actions'] );
		self::assertArrayNotHasKey( 'wp_ajax_nopriv_sqtwc_validate_settings', $GLOBALS['sqtwc_actions'] );
	}

	public function test_create_device_code_requires_admin_capability_and_nonce(): void {
		$adapter = new AdminDeviceAdapter();
		$plugin  = new Plugin( null, null, null, null, $adapter );

		$GLOBALS['sqtwc_current_user_can'] = false;
		$plugin->ajax_create_device_code();
		self::assertSame( 403, $GLOBALS['sqtwc_last_json_response'][1] );

		$GLOBALS['sqtwc_current_user_can'] = true;
		$GLOBALS['sqtwc_nonce_valid']      = false;
		$plugin->ajax_create_device_code();
		self::assertSame( 403, $GLOBALS['sqtwc_last_json_response'][1] );
		self::assertSame( 0, $adapter->creates );
	}

	public function test_create_device_code_returns_pairing_code_and_clears_discovery_cache(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment'  => 'production',
			'location_id' => 'LOC',
		);
		Settings::reset_cache_for_tests();
		$key                                = Gateway::get_device_cache_key( 'production', 'LOC' );
		$GLOBALS['sqtwc_transients'][ $key ] = array( 'value' => array( array( 'id' => 'old' ) ), 'expiration' => 300 );
		$adapter                            = new AdminDeviceAdapter();
		$_POST                              = array( '_wpnonce' => 'valid', 'name' => 'Front Desk' );

		( new Plugin( null, null, null, null, $adapter ) )->ajax_create_device_code();

		self::assertSame( 200, $GLOBALS['sqtwc_last_json_response'][1] );
		self::assertSame( 'PAIR-ME', $GLOBALS['sqtwc_last_json_response'][0]['code'] );
		self::assertSame( 'LOC', $adapter->create_data['location_id'] );
		self::assertSame( 'Front Desk', $adapter->create_data['name'] );
		self::assertArrayNotHasKey( $key, $GLOBALS['sqtwc_transients'] );
	}

	public function test_validate_settings_calls_square_once_and_maps_errors(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'location_id' => 'LOC' );
		Settings::reset_cache_for_tests();
		$adapter = new AdminDeviceAdapter();
		$plugin  = new Plugin( null, null, null, null, $adapter );

		$plugin->ajax_validate_settings();
		self::assertSame( 200, $GLOBALS['sqtwc_last_json_response'][1] );
		self::assertTrue( $GLOBALS['sqtwc_last_json_response'][0]['success'] );
		self::assertSame( 1, $adapter->validations );

		$adapter->exception = new \RuntimeException( 'secret provider detail' );
		$plugin->ajax_validate_settings();
		self::assertSame( 502, $GLOBALS['sqtwc_last_json_response'][1] );
		self::assertStringNotContainsString( 'secret provider detail', wp_json_encode( $GLOBALS['sqtwc_last_json_response'][0] ) );
	}
}
