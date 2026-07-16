<?php
/**
 * Background Square payment reconciliation.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use Throwable;
use WCPOS\WooCommercePOS\SquareTerminal\Logger;

/**
 * Rechecks stale active and detached Terminal checkouts in bounded batches.
 */
final class PaymentSweeper {
	public const EVENT = 'sqtwc_sweep_payments';
	public const SCHEDULE = 'sqtwc_ten_minutes';
	private const RECONCILIATION_SEEDED_OPTION = 'sqtwc_reconcile_seeded';
	private const RECONCILIATION_SEEDED_VALUE = '18446744073709551615';

	/** @var object|null */
	private $terminal_adapter;

	/** @var CheckoutReconciler|object|null */
	private $reconciler;

	/** @var OrderLock */
	private OrderLock $order_lock;

	/** Whether hooks have already been registered on this instance. */
	private bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param object|null                    $terminal_adapter Square Terminal adapter.
	 * @param CheckoutReconciler|object|null $reconciler       Checkout reconciler.
	 * @param OrderLock|null                 $order_lock       Per-order mutation lock.
	 */
	public function __construct( $terminal_adapter = null, $reconciler = null, ?OrderLock $order_lock = null ) {
		$this->terminal_adapter = $terminal_adapter;
		$this->reconciler       = $reconciler;
		$this->order_lock       = $order_lock ?? new OrderLock();
	}

	/**
	 * Register the cron schedule and sweep callback, scheduling the event once.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) );
		add_action( self::EVENT, array( $this, 'sweep' ) );

		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + 600, self::SCHEDULE, self::EVENT );
		}
	}

	/**
	 * Add the ten-minute WordPress cron interval.
	 *
	 * @param array<string,array<string,mixed>> $schedules Existing schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 600,
			'display'  => __( 'Every 10 minutes (Square Terminal)', 'square-terminal-for-woocommerce' ),
		);

		return $schedules;
	}

	/**
	 * Remove the recurring event during plugin deactivation.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::EVENT );
	}

	/**
	 * Reconcile up to 25 orders with stale active or detached checkouts.
	 */
	public function sweep(): void {
		$this->seed_legacy_index();

		foreach ( $this->pending_order_ids() as $order_id ) {

			try {
				$this->order_lock->with_lock(
					(int) $order_id,
					fn() => $this->sweep_order( $order_id )
				);
			} catch ( Throwable $exception ) {
				$this->log_error( (int) $order_id, '', $exception );
			}
		}
	}

	/**
	 * Seed the explicit index once from legacy lifecycle metadata.
	 */
	private function seed_legacy_index(): void {
		if ( self::RECONCILIATION_SEEDED_VALUE === (string) get_option( self::RECONCILIATION_SEEDED_OPTION, '' ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'objects',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-time lazy migration from the legacy reconciliation discovery path.
					'relation' => 'OR',
					array(
						'relation' => 'AND',
						array(
							'key'     => '_sqtwc_checkout_id',
							'value'   => '',
							'compare' => '!=',
						),
						array(
							'key'     => '_sqtwc_attempt_started',
							'value'   => time() - 600,
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
					array(
						'key'     => '_sqtwc_abandoned_checkout_ids',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
		foreach ( $orders as $candidate ) {
			$order_id = is_object( $candidate ) && method_exists( $candidate, 'get_id' ) ? (int) $candidate->get_id() : absint( $candidate );
			if ( $order_id <= 0 ) {
				continue;
			}

			OrderMeta::index_order( $order_id );
		}
		// Mark completion only after every candidate is indexed: an interrupted
		// seed retries on the next sweep (index_order is idempotent, so a
		// concurrent double-seed is harmless). The marker value sorts after all
		// timestamped work matched by the prefix discovery query.
		update_option( self::RECONCILIATION_SEEDED_OPTION, self::RECONCILIATION_SEEDED_VALUE, false );
	}

	/**
	 * Discover the oldest per-order reconciliation options in a bounded batch.
	 *
	 * @return array<int,int>
	 */
	private function pending_order_ids(): array {
		global $wpdb;

		if ( is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'get_results' ) ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded option-prefix discovery is the reconciliation queue.
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'sqtwc\\_reconcile\\_%' ORDER BY CAST(option_value AS UNSIGNED) ASC LIMIT 25"
			);
		} else {
			$options = isset( $GLOBALS['sqtwc_options'] ) && is_array( $GLOBALS['sqtwc_options'] ) ? $GLOBALS['sqtwc_options'] : array();
			$rows    = array();
			foreach ( $options as $option_name => $option_value ) {
				if ( str_starts_with( (string) $option_name, OrderMeta::RECONCILIATION_OPTION_PREFIX ) ) {
					$rows[] = (object) array(
						'option_name'  => $option_name,
						'option_value' => $option_value,
					);
				}
			}

			usort(
				$rows,
				static fn( object $first, object $second ): int => (int) $first->option_value <=> (int) $second->option_value
			);
			$rows = array_slice( $rows, 0, 25 );
		}

		$order_ids     = array();
		$option_prefix = preg_quote( OrderMeta::RECONCILIATION_OPTION_PREFIX, '/' );
		foreach ( (array) $rows as $row ) {
			$option_name = is_object( $row ) && isset( $row->option_name ) ? (string) $row->option_name : ( is_array( $row ) ? (string) ( $row['option_name'] ?? '' ) : '' );
			if ( 1 !== preg_match( '/^' . $option_prefix . '([0-9]+)$/', $option_name, $matches ) ) {
				continue;
			}

			$order_id = absint( $matches[1] );
			if ( $order_id > 0 ) {
				$order_ids[] = $order_id;
			}
		}

		return $order_ids;
	}

	/**
	 * Re-fetch and reconcile every eligible checkout for one locked order.
	 */
	private function sweep_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			OrderMeta::unindex_order( $order_id );

			return;
		}

		$checkout_ids = array();
		$current_id   = (string) $order->get_meta( '_sqtwc_checkout_id', true );
		$started      = (int) $order->get_meta( '_sqtwc_attempt_started', true );
		if ( '' !== $current_id && $started > 0 && $started < ( time() - 600 ) ) {
			$checkout_ids[ $current_id ] = false;
		}

		$abandoned = $order->get_meta( '_sqtwc_abandoned_checkout_ids', true );
		$abandoned = is_array( $abandoned ) ? $abandoned : array();
		foreach ( $abandoned as $checkout_id ) {
			$checkout_id = (string) $checkout_id;
			if ( '' !== $checkout_id ) {
				$checkout_ids[ $checkout_id ] = true;
			}
		}

		foreach ( $checkout_ids as $checkout_id => $is_abandoned ) {
			try {
				$adapter  = $this->terminal_adapter();
				$checkout = $adapter->get_checkout( $checkout_id );
				$result   = $this->reconciler( $adapter )->reconcile( $checkout, $order );

				if ( $is_abandoned && in_array( (string) ( $result['status'] ?? '' ), array( 'COMPLETED', 'CANCELED' ), true ) ) {
					$this->remove_abandoned_checkout( $order, $checkout_id );
					$order->save();
				}
			} catch ( Throwable $exception ) {
				$this->log_error( $order_id, $checkout_id, $exception );
			}
		}

		$abandoned = $order->get_meta( '_sqtwc_abandoned_checkout_ids', true );
		$abandoned = is_array( $abandoned ) ? array_filter( $abandoned ) : array();
		if ( '' !== (string) $order->get_meta( '_sqtwc_checkout_id', true ) || ! empty( $abandoned ) ) {
			OrderMeta::index_order( $order_id );
		} else {
			OrderMeta::unindex_order( $order_id );
		}
	}

	/**
	 * Lazily create the Square adapter so plugin initialization never opens a client.
	 */
	private function terminal_adapter() {
		if ( null === $this->terminal_adapter ) {
			$this->terminal_adapter = new SquareTerminalAdapter( ( new SquareClientFactory() )->create() );
		}

		return $this->terminal_adapter;
	}

	/**
	 * Lazily create the reconciler using the sweep adapter.
	 *
	 * @param object $adapter Square Terminal adapter.
	 * @return CheckoutReconciler|object
	 */
	private function reconciler( $adapter ) {
		if ( null === $this->reconciler ) {
			$this->reconciler = new CheckoutReconciler( $adapter );
		}

		return $this->reconciler;
	}

	/**
	 * Remove a finalized checkout from the detached reconciliation list.
	 */
	private function remove_abandoned_checkout( $order, string $checkout_id ): void {
		$ids = $order->get_meta( '_sqtwc_abandoned_checkout_ids', true );
		$ids = is_array( $ids ) ? $ids : array();
		$ids = array_values( array_diff( $ids, array( $checkout_id ) ) );
		if ( empty( $ids ) ) {
			$order->delete_meta_data( '_sqtwc_abandoned_checkout_ids' );
		} else {
			$order->update_meta_data( '_sqtwc_abandoned_checkout_ids', $ids );
		}
	}

	/**
	 * Log a sanitized checkout-level sweep error without stopping the batch.
	 */
	private function log_error( int $order_id, string $checkout_id, Throwable $exception ): void {
		Logger::error(
			'Square Terminal payment sweep failed',
			array(
				'order_id'        => $order_id,
				'checkout_id'     => $checkout_id,
				'exception_class' => get_class( $exception ),
				'detail'          => $exception->getMessage(),
			)
		);
	}
}
