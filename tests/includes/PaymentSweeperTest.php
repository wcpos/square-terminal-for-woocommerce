<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderMeta;
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
		if ( in_array( $checkout['status'], array( 'COMPLETED', 'CANCELED' ), true ) && $checkout['id'] === $order->get_meta( '_sqtwc_checkout_id', true ) ) {
			OrderMeta::close_current_attempt( $order, $checkout['status'] );
			$order->save();
		}

		return array( 'applied' => true, 'status' => $checkout['status'] );
	}
}

final class SweeperWpdb {
	public string $options = 'wp_options';
	public string $discovery_query = '';

	/** @var array<int,object> */
	public array $discovery_results = array();

	public function get_results( string $query ): array {
		$this->discovery_query = $query;

		return $this->discovery_results;
	}

	public function prepare( string $query, ...$args ): string {
		unset( $args );

		return $query;
	}

	public function get_var( string $query ): string {
		unset( $query );

		return '1';
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
		$GLOBALS['sqtwc_options']            = array( 'sqtwc_reconcile_seeded' => '18446744073709551615' );
		unset( $GLOBALS['sqtwc_wc_get_orders_callback'] );
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
		$GLOBALS['sqtwc_options']['sqtwc_reconcile_99'] = (string) ( time() - 700 );
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
		self::assertSame( array(), $GLOBALS['sqtwc_wc_get_orders_args'] );
		self::assertArrayHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_sweep_continues_after_a_throwing_order(): void {
		$first = new \SQTWC_Test_Order( 98 );
		$first->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_throw' ) );
		$second = new \SQTWC_Test_Order( 99 );
		$second->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_ok' ) );
		$GLOBALS['sqtwc_orders'] = array( 98 => $first, 99 => $second );
		$GLOBALS['sqtwc_options']['sqtwc_reconcile_98'] = '10';
		$GLOBALS['sqtwc_options']['sqtwc_reconcile_99'] = '20';
		$adapter = new SweeperAdapter();
		$adapter->checkouts['chk_throw'] = new \RuntimeException( 'Square outage' );
		$adapter->checkouts['chk_ok'] = array( 'status' => 'CANCELED' );
		$reconciler = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		self::assertSame( array( 'chk_throw', 'chk_ok' ), $adapter->calls );
		self::assertSame( array( array( 99, 'chk_ok' ) ), $reconciler->calls );
		self::assertArrayNotHasKey( '_sqtwc_abandoned_checkout_ids', $second->meta );
		self::assertArrayHasKey( 'sqtwc_reconcile_98', $GLOBALS['sqtwc_options'] );
		self::assertArrayNotHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_sweep_processes_only_the_oldest_twenty_five_indexed_orders(): void {
		for ( $order_id = 30; $order_id >= 1; --$order_id ) {
			$order = new \SQTWC_Test_Order( $order_id );
			$order->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_' . $order_id ) );
			$GLOBALS['sqtwc_orders'][ $order_id ] = $order;
			$GLOBALS['sqtwc_options'][ 'sqtwc_reconcile_' . $order_id ] = (string) ( 1000 + $order_id );
		}
		$adapter = new SweeperAdapter();

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertSame(
			array_map( static fn( int $order_id ): string => 'chk_' . $order_id, range( 1, 25 ) ),
			$adapter->calls
		);
	}

	public function test_checkoutless_attempts_do_not_permanently_starve_newer_checkouts(): void {
		for ( $order_id = 1; $order_id <= 25; ++$order_id ) {
			$order = new \SQTWC_Test_Order( $order_id );
			$order->update_meta_data( '_sqtwc_current_attempt_id', 'attempt_' . $order_id );
			$GLOBALS['sqtwc_orders'][ $order_id ] = $order;
			$GLOBALS['sqtwc_options'][ 'sqtwc_reconcile_' . $order_id ] = (string) $order_id;
		}
		$newer = new \SQTWC_Test_Order( 26 );
		$newer->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_26' ) );
		$GLOBALS['sqtwc_orders'][26] = $newer;
		$GLOBALS['sqtwc_options']['sqtwc_reconcile_26'] = '26';
		$adapter = new SweeperAdapter();
		$sweeper = new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() );

		$sweeper->sweep();
		$sweeper->sweep();

		self::assertSame( array( 'chk_26' ), $adapter->calls );
	}

	public function test_finalized_order_is_unindexed_and_empty_abandoned_meta_is_deleted(): void {
		$order = new \SQTWC_Test_Order( 99 );
		$order->update_meta_data( '_sqtwc_current_attempt_id', 'attempt_current' );
		$order->update_meta_data( '_sqtwc_checkout_id', 'chk_current' );
		$order->update_meta_data( '_sqtwc_attempt_started', time() - 601 );
		$order->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_abandoned' ) );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$GLOBALS['sqtwc_options']['sqtwc_reconcile_99'] = (string) ( time() - 700 );
		$adapter = new SweeperAdapter();
		$adapter->checkouts = array(
			'chk_current'   => array( 'status' => 'COMPLETED' ),
			'chk_abandoned' => array( 'status' => 'CANCELED' ),
		);

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertArrayNotHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
		self::assertArrayNotHasKey( '_sqtwc_abandoned_checkout_ids', $order->meta );
	}

	public function test_missing_index_uses_legacy_meta_query_once_to_seed_it(): void {
		$GLOBALS['sqtwc_options'] = array();
		$order = new \SQTWC_Test_Order( 99 );
		$order->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_open' ) );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$GLOBALS['sqtwc_order_query_results'] = array( $order );
		$sweeper = new PaymentSweeper( new SweeperAdapter(), new SweeperReconciler(), new OrderLock() );

		$sweeper->sweep();
		$sweeper->sweep();

		self::assertCount( 1, $GLOBALS['sqtwc_wc_get_orders_args'] );
		self::assertSame( -1, $GLOBALS['sqtwc_wc_get_orders_args'][0]['limit'] );
		self::assertArrayHasKey( 'meta_query', $GLOBALS['sqtwc_wc_get_orders_args'][0] );
		self::assertArrayHasKey( 'sqtwc_reconcile_seeded', $GLOBALS['sqtwc_options'] );
		self::assertArrayHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_index_order_preserves_the_oldest_work_timestamp_and_unindex_deletes_the_order_option(): void {
		$GLOBALS['sqtwc_options']['sqtwc_reconcile_99'] = '123';

		OrderMeta::index_order( 99 );

		self::assertSame( '123', $GLOBALS['sqtwc_options']['sqtwc_reconcile_99'] );

		OrderMeta::unindex_order( 99 );

		self::assertArrayNotHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_sweep_discovers_per_order_options_from_wpdb_in_timestamp_order(): void {
		$first = new \SQTWC_Test_Order( 98 );
		$first->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_first' ) );
		$second = new \SQTWC_Test_Order( 99 );
		$second->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_second' ) );
		$GLOBALS['sqtwc_orders'] = array( 98 => $first, 99 => $second );

		$wpdb = new SweeperWpdb();
		$wpdb->discovery_results = array(
			(object) array( 'option_name' => 'sqtwc_reconcile_98', 'option_value' => '10' ),
			(object) array( 'option_name' => 'sqtwc_reconcile_99', 'option_value' => '20' ),
		);
		$GLOBALS['wpdb'] = $wpdb;
		$adapter = new SweeperAdapter();

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertSame( array( 'chk_first', 'chk_second' ), $adapter->calls );
		self::assertStringContainsString( "FROM wp_options WHERE option_name LIKE 'sqtwc\\_reconcile\\_%'", $wpdb->discovery_query );
		self::assertStringContainsString( 'ORDER BY CAST(option_value AS UNSIGNED) ASC LIMIT 25', $wpdb->discovery_query );
	}
}
