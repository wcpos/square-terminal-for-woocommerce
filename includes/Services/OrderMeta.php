<?php
/**
 * Attempt-scoped order metadata helpers.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

/**
 * Centralizes bounded logs and attempt lifecycle metadata.
 */
final class OrderMeta {
	/**
	 * Append a structured cashier log entry, retaining the newest 100 entries.
	 *
	 * @param object $order   WooCommerce order.
	 * @param string $level   Log severity.
	 * @param string $message Cashier-safe message.
	 */
	public static function append_log( $order, string $level, string $message ): void {
		$entries   = $order->get_meta( '_sqtwc_payment_log', true );
		$entries   = is_array( $entries ) ? $entries : array();
		$entries[] = array(
			't'     => gmdate( 'c' ),
			'level' => $level,
			'msg'   => $message,
		);

		$order->update_meta_data( '_sqtwc_payment_log', array_slice( $entries, -100 ) );
	}

	/**
	 * Start a new attempt before calling Square.
	 *
	 * @param object $order           WooCommerce order.
	 * @param string $attempt_id      Attempt UUID.
	 * @param string $idempotency_key Square idempotency key.
	 * @param string $device_id       Square device ID.
	 */
	public static function start_attempt( $order, string $attempt_id, string $idempotency_key, string $device_id ): void {
		$order->update_meta_data( '_sqtwc_current_attempt_id', $attempt_id );
		$order->update_meta_data( '_sqtwc_checkout_idempotency_key', $idempotency_key );
		$order->update_meta_data( '_sqtwc_checkout_id', '' );
		$order->update_meta_data( '_sqtwc_checkout_status', 'PENDING' );
		$order->update_meta_data( '_sqtwc_checkout_updated_at', '' );
		$order->update_meta_data( '_sqtwc_device_id', $device_id );
		$order->update_meta_data( '_sqtwc_attempt_started', time() );
		$order->update_meta_data( '_sqtwc_square_checked_at', 0 );
		$order->save();
	}

	/**
	 * Move the current attempt to history and clear active identity pointers.
	 *
	 * @param object $order         WooCommerce order.
	 * @param string $status        Final attempt status.
	 * @param string $cancel_reason Optional Square cancellation reason.
	 */
	public static function close_current_attempt( $order, string $status, string $cancel_reason = '' ): void {
		$attempt_id  = (string) $order->get_meta( '_sqtwc_current_attempt_id', true );
		$checkout_id = (string) $order->get_meta( '_sqtwc_checkout_id', true );

		if ( '' !== $attempt_id || '' !== $checkout_id ) {
			$history   = $order->get_meta( '_sqtwc_attempt_history', true );
			$history   = is_array( $history ) ? $history : array();
			$record    = array(
				'attempt_id'  => $attempt_id,
				'checkout_id' => $checkout_id,
				'status'      => $status,
				'device_id'   => (string) $order->get_meta( '_sqtwc_device_id', true ),
				'started'     => $order->get_meta( '_sqtwc_attempt_started', true ),
				'ended'       => time(),
			);
			if ( '' !== $cancel_reason ) {
				$record['cancel_reason'] = $cancel_reason;
			}
			$history[] = $record;
			$order->update_meta_data( '_sqtwc_attempt_history', $history );
		}

		self::clear_current_pointers( $order );
	}

	/**
	 * Clear identity fields belonging only to the active attempt.
	 *
	 * @param object $order WooCommerce order.
	 */
	public static function clear_current_pointers( $order ): void {
		foreach ( array( '_sqtwc_current_attempt_id', '_sqtwc_checkout_idempotency_key', '_sqtwc_checkout_id', '_sqtwc_device_id', '_sqtwc_attempt_started' ) as $key ) {
			$order->update_meta_data( $key, '' );
		}
		$order->update_meta_data( '_sqtwc_square_checked_at', 0 );
	}

	/**
	 * Append a webhook event ID, retaining the newest 50 IDs.
	 *
	 * @param object $order    WooCommerce order.
	 * @param string $event_id Square event ID.
	 */
	public static function append_processed_event_id( $order, string $event_id ): void {
		$processed   = $order->get_meta( '_sqtwc_processed_event_ids', true );
		$processed   = is_array( $processed ) ? $processed : array();
		$processed[] = $event_id;
		$order->update_meta_data( '_sqtwc_processed_event_ids', array_slice( $processed, -50 ) );
	}
}
