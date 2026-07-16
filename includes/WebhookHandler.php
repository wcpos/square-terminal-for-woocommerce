<?php
/**
 * Square webhook handling.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

use Throwable;
use WCPOS\WooCommercePOS\SquareTerminal\Services\CheckoutReconciler;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderMeta;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareClientFactory;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareTerminalAdapter;
use WCPOS\WooCommercePOS\SquareTerminal\Services\WebhookSignatureVerifier;

/**
 * Processes verified Square webhooks.
 */
final class WebhookHandler {
	/** @var WebhookSignatureVerifier|object */
	private $verifier;

	/** @var CheckoutReconciler|object */
	private $reconciler;

	/**
	 * Constructor.
	 *
	 * @param WebhookSignatureVerifier|object|null $verifier   Signature verifier.
	 * @param CheckoutReconciler|object|null       $reconciler Checkout reconciler.
	 */
	public function __construct( $verifier = null, $reconciler = null ) {
		$this->verifier = $verifier ?? new WebhookSignatureVerifier();
		if ( null === $reconciler ) {
			$adapter    = new SquareTerminalAdapter( ( new SquareClientFactory() )->create() );
			$reconciler = new CheckoutReconciler( $adapter );
		}
		$this->reconciler = $reconciler;
	}

	/**
	 * Handle a Square webhook request.
	 *
	 * @param string               $body    Raw request body.
	 * @param array<string,string> $headers Request headers.
	 * @return array<string,mixed>
	 */
	public function handle( string $body, array $headers ): array {
		$headers   = array_change_key_case( $headers, CASE_LOWER );
		$signature = (string) ( $headers['x-square-hmacsha256-signature'] ?? '' );

		if ( ! $this->verifier->verify( $body, $signature, Settings::get_webhook_signature_key(), Settings::get_webhook_notification_url() ) ) {
			return array(
				'status' => 401,
				'error'  => __( 'Invalid signature.', 'square-terminal-for-woocommerce' ),
			);
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) ) {
			return array(
				'status' => 400,
				'error'  => __( 'Invalid JSON.', 'square-terminal-for-woocommerce' ),
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
				'error'  => __( 'Invalid reference_id.', 'square-terminal-for-woocommerce' ),
			);
		}

		$order = wc_get_order( (int) $matches[1] );
		if ( ! $order ) {
			return array(
				'status' => 404,
				'error'  => __( 'Order not found.', 'square-terminal-for-woocommerce' ),
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
			OrderMeta::append_processed_event_id( $order, $event_id );
		}

		try {
			$result = $this->reconciler->reconcile( $checkout, $order );
		} catch ( Throwable $exception ) {
			$order->update_meta_data( '_sqtwc_processed_event_ids', $processed );
			$order->save();
			Logger::error(
				'Terminal checkout webhook reconciliation failed',
				array(
					'event_id'        => $event_id,
					'exception_class' => get_class( $exception ),
					'detail'          => $exception->getMessage(),
				)
			);

			return array(
				'status' => 500,
				'error'  => __( 'Terminal checkout reconciliation failed.', 'square-terminal-for-woocommerce' ),
			);
		}

		$order->save();

		if ( empty( $result['applied'] ) ) {
			return array(
				'status' => 202,
				'result' => 'ignored',
			);
		}

		return array(
			'status' => 200,
			'result' => 'processed',
		);
	}
}
