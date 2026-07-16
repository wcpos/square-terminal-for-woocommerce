<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock;
use WCPOS\WooCommercePOS\SquareTerminal\Services\PaymentSweeper;

final class SweeperAdapter {
	public array $calls = array();
	public array $checkouts = array();

	public function get_checkout( string $checkout_id ): array {
		$this->calls[] = $checkout_id;
		$result = $this->checkouts[ $checkout_id ] ?? array();
		if ( $result instanceof \Throwable ) {
			throw $result;
		}

		return array_merge(
			array(
				'id'           => $checkout_id,
				'status'       => 'IN_PROGRESS',
				'reference_id' => 'woocommerce_order_99',
			),
			$result
		);
	}
}

final class SweeperReconciler {
	public array $calls = array();

	public function reconcile( array $checkout, $order ): array {
		$this->calls[] = array( $order->get_id(), $checkout['id'] );

		return array( 'applied' => true, 'status' => $checkout['status'] );
	}
}

final class PaymentSweeperTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['sqtwc_actions']            = array();
		$GLOBALS['sqtwc_filters']            = array();
		$GLOBALS['sqtwc_cron_events']        = array();
		$GLOBALS['sqtwc_cron_schedules']     = array();
		$GLOBALS['sqtwc_orders']             = array();
		$GLOBALS['sqtwc_order_query_results'] = array();
		$GLOBALS['sqtwc_wc_get_orders_args'] = array();
		$GLOBALS['sqtwc_options']            = array();
	}

	public function test_register_schedules_sweeper_only_once(): void {
		$sweeper = new PaymentSweeper( new SweeperAdapter(), new SweeperReconciler(), new OrderLock() );

		$sweeper->register();
		$first = $GLOBALS['sqtwc_cron_events'][PaymentSweeper::EVENT];
		$sweeper->register();

		self::assertSame( $first, $GLOBALS['sqtwc_cron_events'][PaymentSweeper::EVENT] );
		self::assertSame( PaymentSweeper::SCHEDULE, $GLOBALS['sqtwc_cron_schedules'][PaymentSweeper::EVENT] );
		self::assertCount( 1, $GLOBALS['sqtwc_actions'][PaymentSweeper::EVENT] );

		PaymentSweeper::unschedule();
		self::assertArrayNotHasKey( PaymentSweeper::EVENT, $GLOBALS['sqtwc_cron_events'] );
	}

	public function test_sweep_reconciles_current_and_abandoned_and_removes_finalized_abandoned(): void {
		$order = new \SQTWC_Test_Order( 99 );
		$order->update_meta_data( '_sqtwc_checkout_id', 'chk_current' );
		$order->update_meta_data( '_sqtwc_attempt_started', time() - 601 );
		$order->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_done', 'chk_open' ) );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$GLOBALS['sqtwc_order_query_results'] = array( $order );
		$adapter = new SweeperAdapter();
		$adapter->checkouts = array(
			'chk_current' => array( 'status' => 'IN_PROGRESS' ),
			'chk_done'    => array( 'status' => 'COMPLETED' ),
			'chk_open'    => array( 'status' => 'PENDING' ),
		);
		$reconciler = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		self::assertSame( array( 'chk_current', 'chk_done', 'chk_open' ), $adapter->calls );
		self::assertSame( array( array( 99, 'chk_current' ), array( 99, 'chk_done' ), array( 99, 'chk_open' ) ), $reconciler->calls );
		self::assertSame( array( 'chk_open' ), $order->get_meta( '_sqtwc_abandoned_checkout_ids' ) );
		self::assertSame( 25, $GLOBALS['sqtwc_wc_get_orders_args'][0]['limit'] );
	}

	public function test_sweep_continues_after_a_throwing_order(): void {
		$first = new \SQTWC_Test_Order( 98 );
		$first->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_throw' ) );
		$second = new \SQTWC_Test_Order( 99 );
		$second->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_ok' ) );
		$GLOBALS['sqtwc_orders'] = array( 98 => $first, 99 => $second );
		$GLOBALS['sqtwc_order_query_results'] = array( $first, $second );
		$adapter = new SweeperAdapter();
		$adapter->checkouts['chk_throw'] = new \RuntimeException( 'Square outage' );
		$adapter->checkouts['chk_ok'] = array( 'status' => 'CANCELED' );
		$reconciler = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		self::assertSame( array( 'chk_throw', 'chk_ok' ), $adapter->calls );
		self::assertSame( array( array( 99, 'chk_ok' ) ), $reconciler->calls );
		self::assertSame( array(), $second->get_meta( '_sqtwc_abandoned_checkout_ids' ) );
	}
}
