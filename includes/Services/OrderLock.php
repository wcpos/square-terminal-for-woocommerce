<?php
/**
 * Per-order mutation locking.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use RuntimeException;
use Throwable;
use WCPOS\WooCommercePOS\SquareTerminal\Logger;

/**
 * Serializes payment lifecycle mutations for a WooCommerce order.
 *
 * The option driver is best-effort: a lease older than 300 seconds may be
 * taken over while the previous holder is still running. MySQL GET_LOCK is
 * the real mutual-exclusion guarantee.
 */
final class OrderLock {
	/**
	 * Locks held by this instance.
	 *
	 * @var array<int,array{driver:string,name:string,count:int,value:string}>
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
		$owner_token = function_exists( 'wp_generate_password' ) ? wp_generate_password( 12, false ) : uniqid( 'sqtwc_', true );
		$lock_value  = $owner_token . '|' . time();
		if ( ! add_option( $option_name, $lock_value, '', 'no' ) ) {
			$existing = (string) get_option( $option_name, '' );
			$parts    = explode( '|', $existing, 2 );
			$created  = (int) ( $parts[1] ?? $parts[0] );
			if ( $created <= 0 || $created >= ( time() - 300 ) ) {
				return false;
			}

			global $wpdb;
			if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
				return false;
			}

			try {
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option_name, $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic stale-lock takeover must match the observed owner value.
			} catch ( Throwable $exception ) {
				unset( $exception );

				return false;
			}
			if ( 1 !== (int) $deleted ) {
				return false;
			}
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $option_name, 'options' );
			}
			if ( ! add_option( $option_name, $lock_value, '', 'no' ) ) {
				return false;
			}

			Logger::warning(
				'Square Terminal option lock stale lease taken over',
				array(
					'order_id'          => $order_id,
					'lease_age_seconds' => time() - $created,
				)
			);
		}

		$this->held[ $order_id ] = array(
			'driver' => 'option',
			'name'   => $option_name,
			'count'  => 1,
			'value'  => $lock_value,
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
			$this->release_option_lock( $lock['name'], $lock['value'] );

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
	 * Release only the exact option value acquired by this instance.
	 */
	private function release_option_lock( string $option_name, string $lock_value ): void {
		global $wpdb;
		if ( is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			try {
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option_name, $lock_value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic owner-checked release is required for the fallback lock.
				if ( $deleted && function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( $option_name, 'options' );
				}
			} catch ( Throwable $exception ) {
				unset( $exception );
			}

			return;
		}

		if ( (string) get_option( $option_name, '' ) === $lock_value ) {
			delete_option( $option_name );
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
			'value'  => '',
		);

		return true;
	}
}
