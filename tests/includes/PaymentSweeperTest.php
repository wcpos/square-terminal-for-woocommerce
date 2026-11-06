<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderMeta;
use WCPOS\WooCommercePOS\SquareTerminal\Services\PaymentSweeper;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

final class SweeperAdapter {
	public array $calls = array();
	public array $checkouts = array();

	/** @var array<int,array{0:string,1:string}> Device and start of each search. */
	public array $search_calls = array();

	/** @var array<int,array<string,mixed>>|\Throwable */
	public $search_result = array();

	public function search_checkouts( string $device_id, string $created_after ): array {
		$this->search_calls[] = array( $device_id, $created_after );
		if ( $this->search_result instanceof \Throwable ) {
			throw $this->search_result;
		}

		return $this->search_result;
	}

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
		$GLOBALS['sqtwc_logs']               = array();
		unset( $GLOBALS['sqtwc_wc_get_orders_callback'] );
		Settings::reset_cache_for_tests();
	}

	private const RELEASE_NOTE = 'Square shows no checkout for an earlier Terminal attempt on this order, so that attempt was closed and a new payment can start. If the customer says they paid, check the Square dashboard first.';

	/**
	 * An indexed order whose create Square never confirmed. A null in $request removes that key.
	 */
	private function unconfirmed_order( int $age = 700, array $request = array() ): \SQTWC_Test_Order {
		$order = new \SQTWC_Test_Order( 99 );
		$order->update_meta_data( '_sqtwc_current_attempt_id', 'attempt_1' );
		$order->update_meta_data( '_sqtwc_checkout_idempotency_key', 'idem_1' );
		$order->update_meta_data( '_sqtwc_checkout_id', '' );
		$order->update_meta_data( '_sqtwc_checkout_status', 'PENDING' );
		$order->update_meta_data( '_sqtwc_device_id', 'device_a' );
		$order->update_meta_data( '_sqtwc_attempt_started', time() - $age );
		$order->update_meta_data(
			'_sqtwc_attempt_request',
			array_filter(
				array_merge(
					array(
						'amount'          => 1234,
						'currency'        => 'USD',
						'device_id'       => 'device_a',
						'reference_id'    => 'woocommerce_order_99',
						'idempotency_key' => 'idem_1',
						'environment'     => 'sandbox',
					),
					$request
				),
				static fn( $value ): bool => null !== $value
			)
		);
		$GLOBALS['sqtwc_orders'][99]                    = $order;
		$GLOBALS['sqtwc_options']['sqtwc_reconcile_99'] = (string) ( time() - $age );

		return $order;
	}

	/** A searched checkout that matches unconfirmed_order() unless overridden. */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => 'chk_found',
				'status'        => 'IN_PROGRESS',
				'reference_id'  => 'woocommerce_order_99',
				'payment_ids'   => array(),
				'updated_at'    => null,
				'created_at'    => null,
				'cancel_reason' => null,
				'device_id'     => 'device_a',
				'amount'        => 1234,
				'currency'      => 'USD',
			),
			$overrides
		);
	}

	private function assert_released( \SQTWC_Test_Order $order, array $notes = array( self::RELEASE_NOTE ) ): void {
		$history = $order->get_meta( '_sqtwc_attempt_history' );
		self::assertIsArray( $history );
		$last = end( $history );
		self::assertSame( 'attempt_1', $last['attempt_id'] );
		self::assertSame( '', $last['checkout_id'] );
		self::assertSame( 'NOT_FOUND', $last['status'] );
		self::assertSame( '', $order->get_meta( '_sqtwc_current_attempt_id' ) );
		self::assertSame( '', $order->get_meta( '_sqtwc_attempt_started' ) );
		self::assertArrayNotHasKey( '_sqtwc_attempt_request', $order->meta );
		self::assertSame( $notes, $order->notes );
	}

	private function assert_unchanged( \SQTWC_Test_Order $order, array $meta_before ): void {
		self::assertSame( $meta_before, $order->meta );
		self::assertSame( array(), $order->notes );
		self::assertSame( 0, $order->saves );
		self::assertArrayHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
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
		$filters_during_query = null;
		$GLOBALS['sqtwc_wc_get_orders_callback'] = static function ( array $args ) use ( &$filters_during_query ): array {
			unset( $args );
			$filters_during_query = $GLOBALS['sqtwc_filters'];

			// Production asks for ids; the seed must cope with plain integers.
			return array( 99 );
		};
		$sweeper = new PaymentSweeper( new SweeperAdapter(), new SweeperReconciler(), new OrderLock() );

		$sweeper->sweep();
		$sweeper->sweep();

		self::assertCount( 1, $GLOBALS['sqtwc_wc_get_orders_args'] );
		$args = $GLOBALS['sqtwc_wc_get_orders_args'][0];
		self::assertSame( -1, $args['limit'] );
		// Refunds are order objects too and come back by default; ids keep an
		// unbounded query from loading every order object at once.
		self::assertSame( 'shop_order', $args['type'] );
		self::assertSame( 'ids', $args['return'] );
		self::assertArrayHasKey( 'meta_query', $args );
		// The posts order store drops 'meta_query' and warns about it, so the
		// clause must be handed to it through its query filter and the warning
		// switched off while the query runs, and only then.
		self::assertSame(
			array(
				'woocommerce_order_data_store_cpt_get_orders_query'      => array( array( PaymentSweeper::class, 'pass_meta_query_to_posts_store' ) ),
				'woocommerce_order_data_store_cpt_query_unsupported_args' => array( array( PaymentSweeper::class, 'allow_meta_query_on_posts_store' ) ),
			),
			$filters_during_query
		);
		self::assertSame( array(), $GLOBALS['sqtwc_filters']['woocommerce_order_data_store_cpt_get_orders_query'] );
		self::assertSame( array(), $GLOBALS['sqtwc_filters']['woocommerce_order_data_store_cpt_query_unsupported_args'] );
		self::assertArrayHasKey( 'sqtwc_reconcile_seeded', $GLOBALS['sqtwc_options'] );
		self::assertArrayHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_posts_store_unsupported_args_filter_drops_only_meta_query(): void {
		self::assertSame( array( 'field_query' ), PaymentSweeper::allow_meta_query_on_posts_store( array( 'meta_query', 'field_query' ) ) );
		self::assertSame( array(), PaymentSweeper::allow_meta_query_on_posts_store( 'meta_query' ) );
	}

	public function test_posts_store_filter_appends_the_meta_query_as_a_nested_clause(): void {
		$clause        = array( 'relation' => 'OR', array( 'key' => '_sqtwc_checkout_id', 'value' => '', 'compare' => '!=' ) );
		$internal      = array( 'key' => '_customer_user', 'value' => 5, 'compare' => '=' );
		$wp_query_args = array( 'post_type' => 'shop_order', 'meta_query' => array( $internal ) );

		$filtered = PaymentSweeper::pass_meta_query_to_posts_store( $wp_query_args, array( 'meta_query' => $clause ) );

		self::assertSame( array( $internal, $clause ), $filtered['meta_query'] );
		self::assertSame( 'shop_order', $filtered['post_type'] );
		self::assertSame( $wp_query_args, PaymentSweeper::pass_meta_query_to_posts_store( $wp_query_args, array( 'limit' => 1 ) ) );
		self::assertSame( array( 'meta_query' => array( $clause ) ), PaymentSweeper::pass_meta_query_to_posts_store( array(), array( 'meta_query' => $clause ) ) );
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
	public function test_unconfirmed_create_match_is_recorded_and_reconciled_in_the_same_sweep(): void {
		$order                  = $this->unconfirmed_order();
		$adapter                = new SweeperAdapter();
		$adapter->search_result = array( $this->row() );
		$reconciler             = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		self::assertSame( 'chk_found', $order->get_meta( '_sqtwc_checkout_id' ) );
		self::assertSame( 'IN_PROGRESS', $order->get_meta( '_sqtwc_checkout_status' ) );
		self::assertSame( array( 'chk_found' ), $adapter->calls );
		self::assertSame( array( array( 99, 'chk_found' ) ), $reconciler->calls );
		$log = $order->get_meta( '_sqtwc_payment_log' );
		self::assertSame( 'info', end( $log )['level'] );
		self::assertSame( 'Square found the Terminal checkout it had not confirmed.', end( $log )['msg'] );
		// The fake reconciler does not save an IN_PROGRESS checkout, so this one save is the match's own.
		self::assertSame( 1, $order->saves );
		self::assertSame( array(), $order->notes );
		self::assertArrayHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_unconfirmed_create_search_uses_the_attempt_device_and_start_less_clock_margin(): void {
		$order   = $this->unconfirmed_order();
		$started = (int) $order->get_meta( '_sqtwc_attempt_started' );
		$adapter = new SweeperAdapter();

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertSame( array( array( 'device_a', gmdate( 'Y-m-d\TH:i:s\Z', $started - 300 ) ) ), $adapter->search_calls );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $adapter->search_calls[0][1] );
	}

	public function test_unconfirmed_create_matched_canceled_row_is_closed_by_the_reconciler(): void {
		$order                           = $this->unconfirmed_order();
		$adapter                         = new SweeperAdapter();
		$adapter->search_result          = array( $this->row( array( 'status' => 'CANCELED' ) ) );
		$adapter->checkouts['chk_found'] = array( 'status' => 'CANCELED' );
		$reconciler                      = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		self::assertSame( array( array( 99, 'chk_found' ) ), $reconciler->calls );
		$history = $order->get_meta( '_sqtwc_attempt_history' );
		self::assertCount( 1, $history );
		self::assertSame( 'chk_found', $history[0]['checkout_id'] );
		self::assertSame( 'CANCELED', $history[0]['status'] );
		self::assertSame( '', $order->get_meta( '_sqtwc_current_attempt_id' ) );
		self::assertArrayNotHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_unconfirmed_create_answered_no_match_with_saved_environment_releases_the_attempt(): void {
		$order      = $this->unconfirmed_order();
		$adapter    = new SweeperAdapter();
		$reconciler = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		self::assertCount( 1, $adapter->search_calls );
		$this->assert_released( $order );
		self::assertCount( 1, $order->get_meta( '_sqtwc_attempt_history' ) );
		$log = $order->get_meta( '_sqtwc_payment_log' );
		self::assertSame( 'warning', end( $log )['level'] );
		self::assertSame( self::RELEASE_NOTE, end( $log )['msg'] );
		self::assertSame( 1, $order->saves );
		self::assertSame( array(), $adapter->calls );
		self::assertSame( array(), $reconciler->calls );
		self::assertArrayNotHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_unconfirmed_create_under_ten_minutes_old_is_not_searched(): void {
		$order   = $this->unconfirmed_order( 590 );
		$before  = $order->meta;
		$adapter = new SweeperAdapter();

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertSame( array(), $adapter->search_calls );
		$this->assert_unchanged( $order, $before );
	}

	public function test_unconfirmed_create_failed_search_changes_nothing_and_logs(): void {
		$order                  = $this->unconfirmed_order();
		$before                 = $order->meta;
		$adapter                = new SweeperAdapter();
		$adapter->search_result = new \RuntimeException( 'Square outage' );

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertCount( 1, $adapter->search_calls );
		$this->assert_unchanged( $order, $before );
		self::assertCount( 1, $GLOBALS['sqtwc_logs'] );
		self::assertSame( 'error', $GLOBALS['sqtwc_logs'][0][0] );
		self::assertSame( 'Square Terminal payment sweep failed', $GLOBALS['sqtwc_logs'][0][1] );
		self::assertSame( 99, $GLOBALS['sqtwc_logs'][0][2]['order_id'] );
		self::assertSame( '', $GLOBALS['sqtwc_logs'][0][2]['checkout_id'] );
		self::assertSame( 'Square outage', $GLOBALS['sqtwc_logs'][0][2]['detail'] );
	}

	public function test_unconfirmed_create_no_match_without_saved_environment_inside_the_window_changes_nothing(): void {
		$order   = $this->unconfirmed_order( 700, array( 'environment' => null ) );
		$before  = $order->meta;
		$adapter = new SweeperAdapter();

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertCount( 1, $adapter->search_calls );
		$this->assert_unchanged( $order, $before );
	}

	public function test_unconfirmed_create_no_match_without_saved_environment_past_the_window_releases(): void {
		$order   = $this->unconfirmed_order( 3700, array( 'environment' => null ) );
		$adapter = new SweeperAdapter();

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertCount( 1, $adapter->search_calls );
		$this->assert_released( $order );
		self::assertArrayNotHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_unconfirmed_create_saved_in_another_environment_is_not_searched(): void {
		$order   = $this->unconfirmed_order( 3700, array( 'environment' => 'production' ) );
		$before  = $order->meta;
		$adapter = new SweeperAdapter();

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertSame( 'sandbox', Settings::get_environment() );
		self::assertSame( array(), $adapter->search_calls );
		$this->assert_unchanged( $order, $before );
	}

	public function test_unconfirmed_create_without_start_time_is_not_searched(): void {
		$order = $this->unconfirmed_order();
		$order->update_meta_data( '_sqtwc_attempt_started', '' );
		$before  = $order->meta;
		$adapter = new SweeperAdapter();

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertSame( array(), $adapter->search_calls );
		$this->assert_unchanged( $order, $before );
	}

	public function test_unconfirmed_create_without_saved_request_is_not_searched(): void {
		$order = $this->unconfirmed_order();
		$order->delete_meta_data( '_sqtwc_attempt_request' );
		$before  = $order->meta;
		$adapter = new SweeperAdapter();

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertSame( array(), $adapter->search_calls );
		$this->assert_unchanged( $order, $before );
	}

	public function test_unconfirmed_create_saved_without_a_device_is_not_searched(): void {
		$this->assert_request_is_not_searched( array( 'device_id' => null ) );
	}

	public function test_unconfirmed_create_saved_without_an_amount_is_not_searched(): void {
		$this->assert_request_is_not_searched( array( 'amount' => null ) );
	}

	public function test_unconfirmed_create_saved_without_a_currency_is_not_searched(): void {
		$this->assert_request_is_not_searched( array( 'currency' => null ) );
	}

	private function assert_request_is_not_searched( array $request ): void {
		$order   = $this->unconfirmed_order( 700, $request );
		$before  = $order->meta;
		$adapter = new SweeperAdapter();

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertSame( array(), $adapter->search_calls );
		$this->assert_unchanged( $order, $before );
	}

	public function test_unconfirmed_create_row_with_an_empty_id_is_no_match(): void {
		$this->assert_row_is_no_match( array( 'id' => '' ) );
	}

	public function test_unconfirmed_create_row_without_an_id_is_no_match(): void {
		$order = $this->unconfirmed_order();
		$row   = $this->row();
		unset( $row['id'] );
		$adapter                = new SweeperAdapter();
		$adapter->search_result = array( $row );
		$reconciler             = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		self::assertCount( 1, $adapter->search_calls );
		$this->assert_released( $order );
		self::assertSame( '', $order->get_meta( '_sqtwc_checkout_id' ) );
		self::assertSame( array(), $reconciler->calls );
	}

	public function test_confirmed_attempt_is_not_searched(): void {
		$order = $this->unconfirmed_order();
		$order->update_meta_data( '_sqtwc_checkout_id', 'chk_known' );
		$adapter    = new SweeperAdapter();
		$reconciler = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		self::assertSame( array(), $adapter->search_calls );
		self::assertSame( array( 'chk_known' ), $adapter->calls );
		self::assertSame( array( array( 99, 'chk_known' ) ), $reconciler->calls );
	}

	public function test_unconfirmed_create_row_with_another_reference_is_no_match(): void {
		$this->assert_row_is_no_match( array( 'reference_id' => 'woocommerce_order_98' ) );
	}

	public function test_unconfirmed_create_row_on_another_device_is_no_match(): void {
		$this->assert_row_is_no_match( array( 'device_id' => 'device_b' ) );
	}

	public function test_unconfirmed_create_row_with_another_amount_is_no_match(): void {
		$this->assert_row_is_no_match( array( 'amount' => 1235 ) );
	}

	public function test_unconfirmed_create_row_with_another_currency_is_no_match(): void {
		$this->assert_row_is_no_match( array( 'currency' => 'AUD' ) );
	}

	private function assert_row_is_no_match( array $overrides ): void {
		$order                  = $this->unconfirmed_order();
		$adapter                = new SweeperAdapter();
		$adapter->search_result = array( $this->row( $overrides ) );
		$reconciler             = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		self::assertCount( 1, $adapter->search_calls );
		$this->assert_released( $order );
		self::assertSame( '', $order->get_meta( '_sqtwc_checkout_id' ) );
		self::assertSame( array(), $reconciler->calls );
	}

	public function test_unconfirmed_create_row_in_the_abandoned_list_is_no_match(): void {
		$order = $this->unconfirmed_order();
		$order->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_found' ) );
		$adapter                = new SweeperAdapter();
		$adapter->search_result = array( $this->row() );
		$reconciler             = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		$this->assert_released( $order );
		// Only the abandoned path reads it; it never became the current checkout.
		self::assertSame( array( array( 99, 'chk_found' ) ), $reconciler->calls );
	}

	public function test_unconfirmed_create_row_in_the_attempt_history_is_no_match(): void {
		$order = $this->unconfirmed_order();
		$order->update_meta_data(
			'_sqtwc_attempt_history',
			array(
				array(
					'attempt_id'  => 'attempt_0',
					'checkout_id' => 'chk_found',
					'status'      => 'CANCELED',
				),
			)
		);
		$adapter                = new SweeperAdapter();
		$adapter->search_result = array( $this->row() );
		$reconciler             = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		$this->assert_released( $order );
		self::assertCount( 2, $order->get_meta( '_sqtwc_attempt_history' ) );
		self::assertSame( array(), $reconciler->calls );
	}

	public function test_unconfirmed_create_two_matches_change_nothing_and_log_an_error(): void {
		$order                  = $this->unconfirmed_order();
		$before                 = $order->meta;
		$adapter                = new SweeperAdapter();
		$adapter->search_result = array( $this->row( array( 'id' => 'chk_a' ) ), $this->row( array( 'id' => 'chk_b' ) ) );
		$reconciler             = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		$this->assert_unchanged( $order, $before );
		self::assertSame( array(), $reconciler->calls );
		self::assertCount( 1, $GLOBALS['sqtwc_logs'] );
		self::assertSame( 'error', $GLOBALS['sqtwc_logs'][0][0] );
		self::assertStringContainsString( 'chk_a, chk_b', $GLOBALS['sqtwc_logs'][0][1] );
		self::assertSame( 99, $GLOBALS['sqtwc_logs'][0][2]['order_id'] );
	}

	public function test_paid_order_unconfirmed_create_with_no_match_closes_without_a_note(): void {
		$order       = $this->unconfirmed_order();
		$order->paid = true;
		$adapter     = new SweeperAdapter();

		( new PaymentSweeper( $adapter, new SweeperReconciler(), new OrderLock() ) )->sweep();

		self::assertCount( 1, $adapter->search_calls );
		$this->assert_released( $order, array() );
		self::assertSame( '', $order->get_meta( '_sqtwc_payment_log' ) );
		self::assertArrayNotHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_paid_order_unconfirmed_create_match_reaches_the_reconciler(): void {
		$order                  = $this->unconfirmed_order();
		$order->paid            = true;
		$adapter                = new SweeperAdapter();
		$adapter->search_result = array( $this->row() );
		$reconciler             = new SweeperReconciler();

		( new PaymentSweeper( $adapter, $reconciler, new OrderLock() ) )->sweep();

		self::assertSame( 'chk_found', $order->get_meta( '_sqtwc_checkout_id' ) );
		self::assertSame( array( array( 99, 'chk_found' ) ), $reconciler->calls );
	}
}
