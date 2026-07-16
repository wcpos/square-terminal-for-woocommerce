<?php
/**
 * Square Terminal checkout reconciliation.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use RuntimeException;
use WCPOS\WooCommercePOS\SquareTerminal\Logger;
use WCPOS\WooCommercePOS\SquareTerminal\Utils\CurrencyConverter;

/**
 * Applies every provider-verified checkout state through one monotonic path.
 */
final class CheckoutReconciler {
	/**
	 * Square Terminal adapter.
	 *
	 * @var object
	 */
	private $terminal_adapter;

	/**
	 * Constructor.
	 *
	 * @param object $terminal_adapter Square Terminal adapter.
	 */
	public function __construct( $terminal_adapter ) {
		$this->terminal_adapter = $terminal_adapter;
	}

	/**
	 * Reconcile a normalized Square checkout into a WooCommerce order.
	 *
	 * @param array<string,mixed> $checkout Normalized Square checkout.
	 * @param object              $order    WooCommerce order.
	 * @param array<string,mixed> $options  SDK request options for Payment fetches.
	 * @return array<string,mixed>
	 */
	public function reconcile( array $checkout, $order, array $options = array() ): array {
		$checkout_id = (string) ( $checkout['id'] ?? '' );
		$status      = (string) ( $checkout['status'] ?? '' );
		$reference   = (string) ( $checkout['reference_id'] ?? '' );
		$expected    = 'woocommerce_order_' . $order->get_id();
		$abandoned   = $order->get_meta( '_sqtwc_abandoned_checkout_ids', true );
		$abandoned   = is_array( $abandoned ) ? $abandoned : array();
		$is_abandoned = in_array( $checkout_id, $abandoned, true );

		if ( $expected !== $reference ) {
			return $this->ignored( $order, 'wrong_reference' );
		}

		if ( ! $is_abandoned && $checkout_id !== (string) $order->get_meta( '_sqtwc_checkout_id', true ) ) {
			return $this->ignored( $order, 'wrong_attempt' );
		}

		if ( ! $is_abandoned && $this->predates_attempt( $checkout, $order ) ) {
			return $this->ignored( $order, 'earlier_attempt' );
		}

		if ( ! $is_abandoned && $this->is_stale( $checkout, $order ) ) {
			return $this->ignored( $order, 'stale' );
		}

		if ( ! in_array( $status, array( 'PENDING', 'IN_PROGRESS', 'CANCEL_REQUESTED', 'CANCELED', 'COMPLETED' ), true ) ) {
			throw new RuntimeException( 'Unknown Square Terminal checkout status.' );
		}

		if ( 'CANCELED' === $status && 'TIMED_OUT' === (string) ( $checkout['cancel_reason'] ?? '' ) && ! empty( $checkout['payment_ids'] ) ) {
			$verified = $this->fetch_verified_payments( (array) $checkout['payment_ids'], $order, false, $options );
			if ( ! empty( $verified ) ) {
				$checkout['status'] = 'COMPLETED';

				return $this->complete( $checkout, $order, $verified, $is_abandoned );
			}
		}

		if ( 'COMPLETED' === $status ) {
			$payment_ids = (array) ( $checkout['payment_ids'] ?? array() );
			if ( empty( $payment_ids ) ) {
				throw new RuntimeException( 'Completed Terminal checkout has no payment IDs.' );
			}

			return $this->complete( $checkout, $order, $this->fetch_verified_payments( $payment_ids, $order, true, $options ), $is_abandoned );
		}

		if ( ! $is_abandoned ) {
			$this->cache_state( $checkout, $order );
		}

		if ( 'CANCELED' === $status ) {
			return $this->cancel( $checkout, $order, $is_abandoned );
		}

		$message = self::cashier_message( $status );
		OrderMeta::append_log( $order, 'info', $message );
		$order->save();
		Logger::info( 'Terminal checkout state reconciled', $this->log_context( $checkout, $order ) );

		return array(
			'applied'          => true,
			'status'           => $status,
			'cashier_message'  => $message,
			'continue_polling' => true,
		);
	}

	/**
	 * Return a cashier-safe message for a checkout status.
	 */
	public static function cashier_message( string $status ): string {
		$messages = array(
			'PENDING'          => __( 'Waiting for the terminal to start.', 'square-terminal-for-woocommerce' ),
			'IN_PROGRESS'      => __( 'Payment is in progress on the terminal.', 'square-terminal-for-woocommerce' ),
			'CANCEL_REQUESTED' => __( 'Cancellation requested. Waiting for the terminal.', 'square-terminal-for-woocommerce' ),
			'CANCELED'         => __( 'Terminal checkout canceled.', 'square-terminal-for-woocommerce' ),
			'COMPLETED'        => __( 'Payment completed.', 'square-terminal-for-woocommerce' ),
		);

		return $messages[ $status ] ?? __( 'Checking the terminal payment status.', 'square-terminal-for-woocommerce' );
	}

	/**
	 * Complete a verified checkout.
	 *
	 * @param array<string,mixed>   $checkout     Checkout state.
	 * @param object                $order        WooCommerce order.
	 * @param array<int,array<string,mixed>> $payments Verified payments.
	 * @param bool                  $is_abandoned Whether this is a detached checkout.
	 * @return array<string,mixed>
	 */
	private function complete( array $checkout, $order, array $payments, bool $is_abandoned ): array {
		$payment_ids = array();
		$collected   = 0;
		$tip         = 0;

		foreach ( $payments as $payment ) {
			$payment_ids[] = (string) $payment['id'];
			$collected    += (int) $payment['total_amount'];
			$tip          += (int) $payment['tip_amount'];
		}

		$requested = CurrencyConverter::to_minor_units( $order->get_total(), $order->get_currency() );
		$tip       = max( $tip, $collected - $requested, 0 );
		$order->update_meta_data( '_sqtwc_payment_ids', $payment_ids );
		$order->update_meta_data( '_sqtwc_collected_amount', $collected );
		$order->update_meta_data( '_sqtwc_tip_amount', $tip );

		if ( ! $is_abandoned ) {
			$this->cache_state( $checkout, $order );
		}

		$underpaid = $collected < $requested;
		if ( $underpaid ) {
			$order->add_order_note( __( 'Square Terminal warning: the amount collected is less than the WooCommerce order total. Review this order before fulfillment.', 'square-terminal-for-woocommerce' ) );
			if ( ! $order->is_paid() ) {
				$order->update_status( 'on-hold' );
			}
		}

		if ( $tip > 0 ) {
			$order->add_order_note( __( 'Square Terminal collected a tip in addition to the requested order amount.', 'square-terminal-for-woocommerce' ) );
		}

		if ( $is_abandoned ) {
			$this->remove_abandoned_checkout( (string) $checkout['id'], $order );
		} else {
			OrderMeta::close_current_attempt( $order, 'COMPLETED' );
		}

		if ( ! $underpaid && ! $order->is_paid() ) {
			$order->payment_complete( (string) ( $payment_ids[0] ?? '' ) );
		} elseif ( $is_abandoned && $order->is_paid() ) {
			$order->add_order_note( __( 'Square Terminal warning: a detached checkout later completed after this order was already paid. Review for a possible duplicate payment.', 'square-terminal-for-woocommerce' ) );
		}

		$message = self::cashier_message( 'COMPLETED' );
		OrderMeta::append_log( $order, 'success', $message );
		$order->save();
		Logger::info( 'Terminal checkout completed', $this->log_context( $checkout, $order ) );

		return array(
			'applied'          => true,
			'status'           => 'COMPLETED',
			'cashier_message'  => $message,
			'continue_polling' => false,
		);
	}

	/**
	 * Close a canceled checkout.
	 *
	 * @param array<string,mixed> $checkout     Checkout state.
	 * @param object              $order        WooCommerce order.
	 * @param bool                $is_abandoned Whether this is a detached checkout.
	 * @return array<string,mixed>
	 */
	private function cancel( array $checkout, $order, bool $is_abandoned ): array {
		$reason  = (string) ( $checkout['cancel_reason'] ?? '' );
		$message = self::cashier_message( 'CANCELED' );
		if ( '' !== $reason ) {
			$message = sprintf(
				/* translators: %s: Square cancellation reason code. */
				__( 'Terminal checkout canceled (%s).', 'square-terminal-for-woocommerce' ),
				$reason
			);
			$order->add_order_note( $message );
		}

		if ( $is_abandoned ) {
			$this->remove_abandoned_checkout( (string) $checkout['id'], $order );
		} else {
			OrderMeta::close_current_attempt( $order, 'CANCELED', $reason );
		}

		OrderMeta::append_log( $order, 'warning', $message );
		$order->save();
		Logger::info( 'Terminal checkout canceled', $this->log_context( $checkout, $order ) );

		return array(
			'applied'          => true,
			'status'           => 'CANCELED',
			'cashier_message'  => $message,
			'continue_polling' => false,
		);
	}

	/**
	 * Fetch and verify checkout payments.
	 *
	 * @param string[] $payment_ids  Square payment IDs.
	 * @param object   $order        WooCommerce order.
	 * @param bool     $require_final Whether every payment must be captured.
	 * @param array<string,mixed> $options SDK request options.
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_verified_payments( array $payment_ids, $order, bool $require_final, array $options = array() ): array {
		$verified = array();
		foreach ( $payment_ids as $payment_id ) {
			$payment = $this->terminal_adapter->get_payment( (string) $payment_id, $options );
			$status  = (string) ( $payment['status'] ?? '' );
			$captured = 'COMPLETED' === $status || ( 'APPROVED' === $status && 'CAPTURED' === (string) ( $payment['card_status'] ?? '' ) );

			if ( ! $captured ) {
				if ( $require_final ) {
					throw new RuntimeException( 'Terminal checkout payment is not captured.' );
				}
				continue;
			}

			if ( ! is_numeric( $payment['total_amount'] ?? null ) || $order->get_currency() !== (string) ( $payment['total_currency'] ?? '' ) ) {
				throw new RuntimeException( 'Terminal checkout payment amount or currency is invalid.' );
			}

			if ( (int) ( $payment['tip_amount'] ?? 0 ) > 0 && $order->get_currency() !== (string) ( $payment['tip_currency'] ?? '' ) ) {
				throw new RuntimeException( 'Terminal checkout tip currency is invalid.' );
			}

			$verified[] = $payment;
		}

		return $verified;
	}

	/**
	 * Cache provider state for the active attempt.
	 *
	 * @param array<string,mixed> $checkout Checkout state.
	 * @param object              $order    WooCommerce order.
	 */
	private function cache_state( array $checkout, $order ): void {
		$order->update_meta_data( '_sqtwc_checkout_status', (string) $checkout['status'] );
		if ( ! empty( $checkout['updated_at'] ) ) {
			$order->update_meta_data( '_sqtwc_checkout_updated_at', (string) $checkout['updated_at'] );
		}
	}

	/**
	 * Determine whether provider state predates this attempt.
	 *
	 * @param array<string,mixed> $checkout Checkout state.
	 * @param object              $order    WooCommerce order.
	 */
	private function predates_attempt( array $checkout, $order ): bool {
		$created = isset( $checkout['created_at'] ) ? strtotime( (string) $checkout['created_at'] ) : false;
		$started = (int) $order->get_meta( '_sqtwc_attempt_started', true );

		return false !== $created && $started > 0 && $created < $started;
	}

	/**
	 * Determine whether incoming state is older than the cached state.
	 *
	 * @param array<string,mixed> $checkout Checkout state.
	 * @param object              $order    WooCommerce order.
	 */
	private function is_stale( array $checkout, $order ): bool {
		$incoming = isset( $checkout['updated_at'] ) ? strtotime( (string) $checkout['updated_at'] ) : false;
		$cached   = strtotime( (string) $order->get_meta( '_sqtwc_checkout_updated_at', true ) );

		return false !== $incoming && false !== $cached && $incoming < $cached;
	}

	/**
	 * Remove a checkout from the abandoned reconciliation list.
	 *
	 * @param string $checkout_id Square checkout ID.
	 * @param object $order       WooCommerce order.
	 */
	private function remove_abandoned_checkout( string $checkout_id, $order ): void {
		$ids = $order->get_meta( '_sqtwc_abandoned_checkout_ids', true );
		$ids = is_array( $ids ) ? $ids : array();
		$order->update_meta_data( '_sqtwc_abandoned_checkout_ids', array_values( array_diff( $ids, array( $checkout_id ) ) ) );
	}

	/**
	 * Return a no-op reconciliation result.
	 *
	 * @param object $order  WooCommerce order.
	 * @param string $reason Rejection reason.
	 * @return array<string,mixed>
	 */
	private function ignored( $order, string $reason ): array {
		$status = (string) $order->get_meta( '_sqtwc_checkout_status', true );

		return array(
			'applied'          => false,
			'reason'           => $reason,
			'status'           => $status,
			'cashier_message'  => self::cashier_message( $status ),
			'continue_polling' => in_array( $status, array( 'PENDING', 'IN_PROGRESS', 'CANCEL_REQUESTED' ), true ),
		);
	}

	/**
	 * Build lifecycle log context.
	 *
	 * @param array<string,mixed> $checkout Checkout state.
	 * @param object              $order    WooCommerce order.
	 * @return array<string,mixed>
	 */
	private function log_context( array $checkout, $order ): array {
		return array(
			'order_id'      => $order->get_id(),
			'attempt_id'    => $order->get_meta( '_sqtwc_current_attempt_id', true ),
			'checkout_id'   => $checkout['id'] ?? '',
			'device_id'     => $order->get_meta( '_sqtwc_device_id', true ),
			'status'        => $checkout['status'] ?? '',
			'cancel_reason' => $checkout['cancel_reason'] ?? '',
		);
	}
}
