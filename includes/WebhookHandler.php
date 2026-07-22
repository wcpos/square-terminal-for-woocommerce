<?php
/**
 * Square webhook handling.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

use Throwable;
use WCPOS\WooCommercePOS\SquareTerminal\Services\CheckoutReconciler;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock;
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

	/** @var OrderLock */
	private OrderLock $order_lock;

	/**
	 * Constructor.
	 *
	 * @param WebhookSignatureVerifier|object|null $verifier   Signature verifier.
	 * @param CheckoutReconciler|object|null       $reconciler Checkout reconciler.
	 * @param OrderLock|null                       $order_lock Per-order mutation lock.
	 */
	public function __construct( $verifier = null, $reconciler = null, ?OrderLock $order_lock = null ) {
		$this->verifier = $verifier ?? new WebhookSignatureVerifier();
		if ( null === $reconciler ) {
			$adapter    = new SquareTerminalAdapter( ( new SquareClientFactory() )->create() );
			$reconciler = new CheckoutReconciler( $adapter );
		}
		$this->reconciler = $reconciler;
		$this->order_lock = $order_lock ?? new OrderLock();
	}

	/** Option recording the most recent signature-verified webhook. */
	public const LAST_DELIVERY_OPTION = 'sqtwc_webhook_last_delivery';

	/**
	 * Record that a signature-verified webhook arrived.
	 *
	 * Only verified deliveries are recorded, because this route is public: an
	 * unauthenticated caller must not be able to write the health state that the
	 * settings screen reports.
	 */
	private static function record_verified_delivery(): void {
		update_option( self::LAST_DELIVERY_OPTION, time(), false );
	}

	/**
	 * Return when a signature-verified webhook last arrived.
	 *
	 * @return int|null Unix time, or null when none has ever verified.
	 */
	public static function last_verified_delivery(): ?int {
		$last = get_option( self::LAST_DELIVERY_OPTION, null );

		// Tolerates the 0.6.0 development shape, which stored an array.
		if ( is_array( $last ) ) {
			$last = ! empty( $last['verified'] ) ? ( $last['at'] ?? null ) : null;
		}

		return is_numeric( $last ) ? (int) $last : null;
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

		// Only verified deliveries are recorded. This route is public, so anyone
		// could otherwise post a bad signature and make the settings screen claim
		// webhooks are broken when they are not. A key mismatch still shows,
		// as the absence of any verified delivery.
		self::record_verified_delivery();

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

		$order_id = (int) $matches[1];
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'status' => 404,
				'error'  => __( 'Order not found.', 'square-terminal-for-woocommerce' ),
			);
		}

		$event_id = (string) ( $event['event_id'] ?? '' );
		try {
			return $this->order_lock->with_lock(
				$order_id,
				function () use ( $order_id, $event_id, $checkout ): array {
					$order = wc_get_order( $order_id );
					if ( ! $order ) {
						return array(
							'status' => 404,
							'error'  => __( 'Order not found.', 'square-terminal-for-woocommerce' ),
						);
					}

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

						throw $exception;
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
			);
		} catch ( Throwable $exception ) {
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
	}
}
