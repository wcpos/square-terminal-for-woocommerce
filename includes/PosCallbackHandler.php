<?php
/**
 * Square Point of Sale app callback handling.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

use Throwable;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock;
use WCPOS\WooCommercePOS\SquareTerminal\Utils\CurrencyConverter;

/**
 * Authorizes the returned order state and applies only server-verified payments.
 */
final class PosCallbackHandler {
	/** @var object POS transaction verifier. */
	private object $verifier;

	/** Order mutation lock. */
	private OrderLock $order_lock;

	/**
	 * @param object         $verifier   POS transaction verifier.
	 * @param OrderLock|null $order_lock Shared order lock.
	 */
	public function __construct( object $verifier, ?OrderLock $order_lock = null ) {
		$this->verifier   = $verifier;
		$this->order_lock = $order_lock ?? new OrderLock();
	}

	/**
	 * Normalize iOS data JSON or Android namespaced query parameters.
	 *
	 * @param array<string,mixed> $params Callback parameters.
	 * @return array{transaction_id:string,client_transaction_id:string,error_code:string,error_description:string,state:string}
	 */
	public static function parse_request( array $params ): array {
		$data = array();
		if ( isset( $params['data'] ) ) {
			$decoded = json_decode( (string) wp_unslash( $params['data'] ), true );
			$data    = is_array( $decoded ) ? $decoded : array();
		}

		return array(
			'transaction_id'        => sanitize_text_field( (string) ( $data['transaction_id'] ?? $params['com.squareup.pos.SERVER_TRANSACTION_ID'] ?? $params['com_squareup_pos_SERVER_TRANSACTION_ID'] ?? '' ) ),
			'client_transaction_id' => sanitize_text_field( (string) ( $data['client_transaction_id'] ?? $params['com.squareup.pos.CLIENT_TRANSACTION_ID'] ?? $params['com_squareup_pos_CLIENT_TRANSACTION_ID'] ?? '' ) ),
			'error_code'            => sanitize_text_field( (string) ( $data['error_code'] ?? $params['com.squareup.pos.ERROR_CODE'] ?? $params['com_squareup_pos_ERROR_CODE'] ?? '' ) ),
			'error_description'     => sanitize_text_field( (string) ( $data['error_description'] ?? $params['com.squareup.pos.ERROR_DESCRIPTION'] ?? $params['com_squareup_pos_ERROR_DESCRIPTION'] ?? '' ) ),
			'state'                 => sanitize_text_field( (string) ( $data['state'] ?? $params['com.squareup.pos.REQUEST_METADATA'] ?? $params['com_squareup_pos_REQUEST_METADATA'] ?? '' ) ),
		);
	}

	/**
	 * Handle a public POS app callback.
	 *
	 * @param array<string,mixed> $params Callback parameters.
	 */
	public function handle( array $params ): void {
		$callback = self::parse_request( $params );
		$state    = json_decode( $callback['state'], true );
		$order_id = absint( is_array( $state ) ? ( $state['o'] ?? 0 ) : 0 );
		$order_key = sanitize_text_field( (string) ( is_array( $state ) ? ( $state['k'] ?? '' ) : '' ) );
		$order     = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order || '' === $order_key || ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			wp_die(
				esc_html__( 'The payment return could not be verified.', 'square-terminal-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( '' !== $callback['error_code'] ) {
			if ( ! in_array( $callback['error_code'], array( 'payment_canceled', 'not_logged_in', 'no_network' ), true ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: Square POS app error code. */
						__( 'Square POS app returned an error (%s).', 'square-terminal-for-woocommerce' ),
						$callback['error_code']
					)
				);
				$order->save();
			}
			$this->redirect_to_payment( $order, 'error', $callback['error_code'] );
		}

		if ( '' === $callback['transaction_id'] && '' !== $callback['client_transaction_id'] ) {
			$order->update_meta_data( '_sqtwc_pos_client_transaction_id', $callback['client_transaction_id'] );
			$order->add_order_note(
				sprintf(
					/* translators: %s: Square client transaction ID. */
					__( 'Square POS app recorded an offline payment (client transaction %s). Manual verification is required.', 'square-terminal-for-woocommerce' ),
					$callback['client_transaction_id']
				)
			);
			$order->save();
			$this->redirect_to_payment( $order, 'offline' );
		}

		if ( '' === $callback['transaction_id'] ) {
			$this->redirect_to_payment( $order, 'error', 'verification_failed' );
		}

		if ( $order->is_paid() ) {
			$this->redirect_to_receipt( $order );
		}

		try {
			$verified = $this->verifier->verify( $callback['transaction_id'] );
			$completed = $this->order_lock->with_lock(
				$order_id,
				function () use ( $order_id, $callback, $verified ): bool {
					$locked_order = wc_get_order( $order_id );
					if ( ! $locked_order ) {
						throw new \RuntimeException( 'WooCommerce order disappeared during verification.' );
					}
					if ( $locked_order->is_paid() ) {
						return true;
					}
					if ( 'pos_app' !== Settings::get_collection_method() || 'production' !== Settings::get_environment() ) {
						throw new \RuntimeException( 'Square POS app handoff is not configured for production.' );
					}

					$duplicates = wc_get_orders(
						array(
							'limit'      => 1,
							'return'     => 'ids',
							'exclude'    => array( $order_id ),
							'meta_key'   => '_sqtwc_pos_transaction_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Single-use provider ID requires an order lookup.
							'meta_value' => $callback['transaction_id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Single-use provider ID requires an order lookup.
						)
					);
					if ( ! empty( $duplicates ) ) {
						throw new \RuntimeException( 'Square POS transaction is already assigned to another order.' );
					}

					$location_id = Settings::get_location_id();
					if ( $locked_order->get_currency() !== (string) ( $verified['currency'] ?? '' ) ) {
						throw new \RuntimeException( 'Square POS payment currency does not match the order.' );
					}
					if ( '' !== $location_id && (string) ( $verified['location_id'] ?? '' ) !== $location_id ) {
						throw new \RuntimeException( 'Square POS payment location does not match the configured location.' );
					}

					$payment_ids = array_values( array_map( 'strval', (array) ( $verified['payment_ids'] ?? array() ) ) );
					$collected   = (int) ( $verified['amount'] ?? 0 );
					$requested   = CurrencyConverter::to_minor_units( $locked_order->get_total(), $locked_order->get_currency() );
					$locked_order->update_meta_data( '_sqtwc_pos_transaction_id', $callback['transaction_id'] );
					$locked_order->update_meta_data( '_sqtwc_payment_ids', $payment_ids );

					if ( $collected < $requested ) {
						$locked_order->add_order_note(
							sprintf(
								/* translators: 1: collected amount, 2: order total. */
								__( 'Square POS app collected %1$s of %2$s. Verify the payment in Square Dashboard before fulfilling.', 'square-terminal-for-woocommerce' ),
								CurrencyConverter::format_minor_units( $collected, $locked_order->get_currency() ),
								CurrencyConverter::format_minor_units( $requested, $locked_order->get_currency() )
							)
						);
						$locked_order->update_status( 'on-hold' );
						$locked_order->save();

						return false;
					}

					$locked_order->add_order_note(
						sprintf(
							/* translators: 1: Square Order ID, 2: comma-separated Square payment IDs. */
							__( 'Square POS app payment verified (transaction %1$s, payment %2$s).', 'square-terminal-for-woocommerce' ),
							$callback['transaction_id'],
							implode( ', ', $payment_ids )
						)
					);
					$locked_order->payment_complete( (string) ( $payment_ids[0] ?? '' ) );
					$locked_order->save();

					return true;
				}
			);
		} catch ( Throwable $exception ) {
			Logger::error(
				'Square POS app callback verification failed',
				array(
					'order_id'        => $order_id,
					'transaction_id'  => $callback['transaction_id'],
					'exception_class' => get_class( $exception ),
					'detail'          => $exception->getMessage(),
				)
			);
			$order->add_order_note( __( 'Square POS app payment verification failed. Check the Square Dashboard and plugin logs.', 'square-terminal-for-woocommerce' ) );
			$order->save();
			$this->redirect_to_payment( $order, 'error', 'verification_failed' );
		}

		if ( ! $completed ) {
			$this->redirect_to_payment( $order, 'error', 'verification_failed' );
		}
		$this->redirect_to_receipt( $order );
	}

	/** Redirect back to the authenticated order-pay page. */
	private function redirect_to_payment( $order, string $result, string $code = '' ): void {
		$args = array( 'sqtwc_pos_result' => $result );
		if ( '' !== $code ) {
			$args['sqtwc_pos_code'] = $code;
		}
		wp_safe_redirect( add_query_arg( $args, $order->get_checkout_payment_url( true ) ) );
		exit;
	}

	/** Redirect to the WooCommerce order-received page. */
	private function redirect_to_receipt( $order ): void {
		wp_safe_redirect( ( new Gateway() )->get_return_url( $order ) );
		exit;
	}
}
