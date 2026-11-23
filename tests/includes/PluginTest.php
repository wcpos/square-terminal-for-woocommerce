<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Plugin;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareClientFactory;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareDeviceAdapter;
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

final class CurrentSettingsSquareClientFactory {
	public static array $arguments = array();

	public function create( ?string $access_token = null, ?string $environment = null ): object {
		self::$arguments = array( $access_token, $environment );

		return new \stdClass();
	}
}

final class CurrentSettingsSquareDeviceAdapter {
	public static string $location_id = '';

	public function __construct( object $client ) {
		unset( $client );
	}

	public function validate_location( string $location_id ): void {
		self::$location_id = $location_id;
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
		$GLOBALS['sqtwc_current_user']     = (object) array( 'ID' => 7, 'display_name' => 'Store Manager Sam' );
		$GLOBALS['sqtwc_caps_checked']     = array();
		$_POST                             = array();
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

	private static function held_order( bool $unverified ): \SQTWC_Test_Order {
		$order         = new \SQTWC_Test_Order( 99 );
		$order->status = 'pending';
		$order->update_meta_data( '_sqtwc_pos_transaction_id', 'TXN-HELD' );
		$order->update_meta_data( '_sqtwc_payment_ids', array( 'PAY-1' ) );
		if ( $unverified ) {
			$order->update_meta_data( '_sqtwc_pos_unverified', 'yes' );
		}
		$GLOBALS['sqtwc_orders'][99] = $order;

		return $order;
	}

	public function test_registers_the_pos_hold_clear_on_status_change(): void {
		$GLOBALS['sqtwc_actions'] = array();
		$plugin                   = new Plugin();

		$plugin->init();

		self::assertContains( array( $plugin, 'clear_pos_hold_on_manual_pending' ), $GLOBALS['sqtwc_actions']['woocommerce_order_status_changed'] ?? array() );
	}

	public static function heldOrders(): array {
		return array(
			'unverified hold' => array( true ),
			'partial hold'    => array( false ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'heldOrders' )]
	public function test_a_manual_move_back_to_pending_clears_the_square_hold( bool $unverified ): void {
		$order = self::held_order( $unverified );

		( new Plugin() )->clear_pos_hold_on_manual_pending( 99, 'on-hold', 'pending', $order );

		self::assertSame( '', $order->get_meta( '_sqtwc_pos_transaction_id', true ) );
		self::assertSame( '', $order->get_meta( '_sqtwc_pos_unverified', true ) );
		self::assertSame( array( 'PAY-1' ), $order->get_meta( '_sqtwc_payment_ids', true ) );
		self::assertCount( 1, $order->notes );
		self::assertStringContainsString( 'Store Manager Sam', $order->notes[0] );
		self::assertStringContainsString( 'TXN-HELD', $order->notes[0] );
		self::assertStringContainsString( 'refund it in Square', $order->notes[0] );
		self::assertGreaterThan( 0, $order->saves );
		self::assertContains( 'edit_shop_orders', $GLOBALS['sqtwc_caps_checked'] );
	}

	public static function holdsThatStay(): array {
		return array(
			'cancelled'  => array( 'cancelled', true ),
			'on-hold'    => array( 'on-hold', true ),
			'processing' => array( 'processing', true ),
			'no user'    => array( 'pending', false ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'holdsThatStay' )]
	public function test_the_square_hold_stays_otherwise( string $to, bool $can_edit ): void {
		$GLOBALS['sqtwc_current_user_can'] = $can_edit;
		$order                             = self::held_order( true );

		( new Plugin() )->clear_pos_hold_on_manual_pending( 99, 'on-hold', $to, $order );

		self::assertSame( 'TXN-HELD', $order->get_meta( '_sqtwc_pos_transaction_id', true ) );
		self::assertSame( 'yes', $order->get_meta( '_sqtwc_pos_unverified', true ) );
		self::assertSame( array(), $order->notes );
	}

	public function test_a_move_to_pending_without_a_hold_writes_nothing(): void {
		$order                       = new \SQTWC_Test_Order( 99 );
		$GLOBALS['sqtwc_orders'][99] = $order;

		( new Plugin() )->clear_pos_hold_on_manual_pending( 99, 'failed', 'pending', $order );

		self::assertSame( array(), $order->notes );
		self::assertSame( 0, $order->saves );
	}

	public function test_registers_admin_device_actions_without_nopriv_variants(): void {
		$GLOBALS['sqtwc_actions'] = array();

		( new Plugin() )->init();

		self::assertArrayHasKey( 'wp_ajax_sqtwc_create_device_code', $GLOBALS['sqtwc_actions'] );
		self::assertArrayHasKey( 'wp_ajax_sqtwc_validate_settings', $GLOBALS['sqtwc_actions'] );
		self::assertContains( array( Gateway::class, 'enqueue_admin_assets' ), $GLOBALS['sqtwc_actions']['admin_enqueue_scripts'] ?? array() );
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

	public function test_create_device_code_uses_current_location_and_clears_its_discovery_cache(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment'  => 'production',
			'location_id' => 'OLD',
		);
		Settings::reset_cache_for_tests();
		$key                                = Gateway::get_device_cache_key( 'production', 'NEW' );
		$GLOBALS['sqtwc_transients'][ $key ] = array( 'value' => array( array( 'id' => 'old' ) ), 'expiration' => 300 );
		$adapter                            = new AdminDeviceAdapter();
		$_POST                              = array(
			'_wpnonce'    => 'valid',
			'environment' => 'production',
			'access_token' => 'current-token',
			'location_id' => 'NEW',
			'name'        => 'Front Desk',
		);

		( new Plugin( null, null, null, null, $adapter ) )->ajax_create_device_code();

		self::assertSame( 200, $GLOBALS['sqtwc_last_json_response'][1] );
		self::assertSame( 'PAIR-ME', $GLOBALS['sqtwc_last_json_response'][0]['code'] );
		self::assertSame( 'NEW', $adapter->create_data['location_id'] );
		self::assertSame( 'Front Desk', $adapter->create_data['name'] );
		self::assertArrayNotHasKey( $key, $GLOBALS['sqtwc_transients'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_validate_settings_uses_current_credentials_environment_and_location(): void {
		class_alias( CurrentSettingsSquareClientFactory::class, SquareClientFactory::class );
		class_alias( CurrentSettingsSquareDeviceAdapter::class, SquareDeviceAdapter::class );
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment'             => 'sandbox',
			'sandbox_access_token'    => 'saved-token',
			'location_id'             => 'OLD',
		);
		Settings::reset_cache_for_tests();
		$_POST = array(
			'_wpnonce'    => 'valid',
			'environment' => 'production',
			'access_token' => 'current-token',
			'location_id' => 'NEW',
		);

		( new Plugin() )->ajax_validate_settings();

		self::assertSame( array( 'current-token', 'production' ), CurrentSettingsSquareClientFactory::$arguments );
		self::assertSame( 'NEW', CurrentSettingsSquareDeviceAdapter::$location_id );
		self::assertSame( 200, $GLOBALS['sqtwc_last_json_response'][1] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_validate_settings_prefers_matching_oauth_credentials_to_manual_tokens(): void {
		class_alias( CurrentSettingsSquareClientFactory::class, SquareClientFactory::class );
		class_alias( CurrentSettingsSquareDeviceAdapter::class, SquareDeviceAdapter::class );
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment'             => 'sandbox',
			'production_access_token' => 'saved-manual-token',
			'location_id'             => 'LOC',
		);
		$GLOBALS['sqtwc_options'][ \WCPOS\WooCommercePOS\SquareTerminal\Services\SquareOAuth::OPTION ] = array(
			'access_token' => 'oauth-token',
			'environment'  => 'production',
		);
		Settings::reset_cache_for_tests();
		$_POST = array(
			'_wpnonce'    => 'valid',
			'environment' => 'production',
			'access_token' => 'posted-manual-token',
			'location_id' => 'LOC',
		);

		( new Plugin() )->ajax_validate_settings();

		self::assertSame( array( 'oauth-token', 'production' ), CurrentSettingsSquareClientFactory::$arguments );
		self::assertSame( 200, $GLOBALS['sqtwc_last_json_response'][1] );
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
