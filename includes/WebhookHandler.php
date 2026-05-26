<?php
/**
 * Square webhook handling.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

use WCPOS\WooCommercePOS\SquareTerminal\Services\WebhookSignatureVerifier;

/**
 * Processes verified Square webhooks.
 */
final class WebhookHandler {
	/**
	 * Signature verifier.
	 *
	 * @var WebhookSignatureVerifier|object
	 */
	private $verifier;

	/**
	 * Constructor.
	 *
	 * @param WebhookSignatureVerifier|object|null $verifier Signature verifier.
	 */
	public function __construct( $verifier = null ) {
		$this->verifier = $verifier ?? new WebhookSignatureVerifier();
	}

	/**
	 * Handle a Square webhook request.
	 *
	 * @param string               $body    Raw request body.
	 * @param array<string,string> $headers Request headers.
	 * @return array<string,mixed>
	 */
	public function handle( string $body, array $headers ): array {
		$signature = (string) ( $headers['x-square-hmacsha256-signature'] ?? $headers['X-Square-HmacSha256-Signature'] ?? '' );

		if ( ! $this->verifier->verify( $body, $signature, Settings::get_webhook_signature_key(), Settings::get_webhook_notification_url() ) ) {
			return array(
				'status' => 401,
				'error'  => 'Invalid signature.',
			);
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) ) {
			return array(
				'status' => 400,
				'error'  => 'Invalid JSON.',
			);
		}

		if ( 'terminal.checkout.updated' !== ( $event['type'] ?? '' ) ) {
			return array(
				'status' => 202,
				'result' => 'ignored',
			);
		}

		$checkout  = $event['data']['object']['checkout'] ?? null;
		$reference = is_array( $checkout ) ? (string) ( $checkout['reference_id'] ?? '' ) : '';
		if ( ! preg_match( '/^woocommerce_order_(\d+)$/', $reference, $matches ) ) {
			return array(
				'status' => 400,
				'error'  => 'Invalid reference_id.',
			);
		}

		$order = wc_get_order( (int) $matches[1] );
		if ( ! $order ) {
			return array(
				'status' => 404,
				'error'  => 'Order not found.',
			);
		}

		$event_id  = (string) ( $event['event_id'] ?? '' );
		$processed = $order->get_meta( '_sqtwc_processed_event_ids', true );
		$processed = is_array( $processed ) ? $processed : array();

		if ( $event_id && in_array( $event_id, $processed, true ) ) {
			return array(
				'status' => 200,
				'result' => 'duplicate',
			);
		}

		if ( $event_id ) {
			$processed[] = $event_id;
			$order->update_meta_data( '_sqtwc_processed_event_ids', $processed );
		}

		if ( 'COMPLETED' === ( $checkout['status'] ?? '' ) ) {
			$payment_ids = (array) ( $checkout['payment_ids'] ?? array() );
			$order->update_meta_data( '_sqtwc_payment_ids', $payment_ids );

			if ( ! $order->is_paid() ) {
				$transaction_id = (string) ( $payment_ids[0] ?? '' );
				$order->set_transaction_id( $transaction_id );
				$order->payment_complete( $transaction_id );
			}

			Logger::info( 'Verified Square webhook completed Terminal checkout', array( 'event_id' => $event_id ), $order );
		}

		$order->save();

		return array(
			'status' => 200,
			'result' => 'processed',
		);
	}
}
