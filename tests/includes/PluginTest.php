<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Plugin;

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

final class PluginTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['sqtwc_orders']  = array();
		$GLOBALS['sqtwc_options'] = array();
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
}
