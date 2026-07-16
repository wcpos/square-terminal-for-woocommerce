<?php
/**
 * Per-order mutation locking.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use RuntimeException;
use Throwable;

/**
 * Serializes payment lifecycle mutations for a WooCommerce order.
 */
final class OrderLock {
	/**
	 * Locks held by this instance.
	 *
	 * @var array<int,array{driver:string,name:string,count:int}>
	 */
	private array $held = array();

	/**
	 * Acquire an order lock, waiting up to five seconds when MySQL locks are available.
	 */
	public function acquire( int $order_id ): bool {
		$order_id = absint( $order_id );
		if ( isset( $this->held[ $order_id ] ) ) {
			++$this->held[ $order_id ]['count'];

			return true;
		}

		$mysql_result = $this->acquire_mysql( $order_id );
		if ( null !== $mysql_result ) {
			return $mysql_result;
		}

		$option_name = 'sqtwc_lock_' . $order_id;
		if ( ! add_option( $option_name, time(), '', 'no' ) ) {
			$created = (int) get_option( $option_name, 0 );
			if ( $created <= 0 || $created >= ( time() - 60 ) ) {
				return false;
			}

			delete_option( $option_name );
			if ( ! add_option( $option_name, time(), '', 'no' ) ) {
				return false;
			}
		}

		$this->held[ $order_id ] = array(
			'driver' => 'option',
			'name'   => $option_name,
			'count'  => 1,
		);

		return true;
	}

	/**
	 * Release an order lock held by this instance.
	 */
	public function release( int $order_id ): void {
		$order_id = absint( $order_id );
		if ( ! isset( $this->held[ $order_id ] ) ) {
			return;
		}

		--$this->held[ $order_id ]['count'];
		if ( $this->held[ $order_id ]['count'] > 0 ) {
			return;
		}

		$lock = $this->held[ $order_id ];
		unset( $this->held[ $order_id ] );

		if ( 'option' === $lock['driver'] ) {
			delete_option( $lock['name'] );

			return;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return;
		}

		try {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock['name'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- MySQL advisory locks are the primary atomic lock.
		} catch ( Throwable $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Run a callback while holding the order lock.
	 *
	 * @template T
	 * @param int      $order_id Order ID.
	 * @param callable $callback Callback to run.
	 * @return mixed
	 */
	public function with_lock( int $order_id, callable $callback ) {
		if ( ! $this->acquire( $order_id ) ) {
			throw new RuntimeException( 'Could not acquire the Square Terminal order lock.' );
		}

		try {
			return $callback();
		} finally {
			$this->release( $order_id );
		}
	}

	/**
	 * Try to acquire a MySQL advisory lock.
	 *
	 * @return bool|null True/false for a MySQL result, null when the option fallback is required.
	 */
	private function acquire_mysql( int $order_id ): ?bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return null;
		}

		$name = sprintf( 'sqtwc_order_%d_%d', $order_id, get_current_blog_id() );
		try {
			$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- MySQL advisory locks are the primary atomic lock.
		} catch ( Throwable $exception ) {
			unset( $exception );

			return null;
		}

		if ( null === $result ) {
			return null;
		}

		if ( '1' !== (string) $result ) {
			return false;
		}

		$this->held[ $order_id ] = array(
			'driver' => 'mysql',
			'name'   => $name,
			'count'  => 1,
		);

		return true;
	}
}
