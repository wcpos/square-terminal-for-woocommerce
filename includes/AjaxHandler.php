<?php
/**
 * AJAX payment lifecycle handlers.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

use Throwable;
use WCPOS\WooCommercePOS\SquareTerminal\Services\CheckoutReconciler;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderMeta;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareErrorMapper;
use WCPOS\WooCommercePOS\SquareTerminal\Utils\CurrencyConverter;

/**
 * Handles Square Terminal payment AJAX requests.
 */
final class AjaxHandler {
	/** @var object */
	private $terminal_adapter;

	/** @var CheckoutReconciler */
	private CheckoutReconciler $reconciler;

	/** @var SquareErrorMapper */
	private SquareErrorMapper $error_mapper;

	/** @var OrderLock */
	private OrderLock $order_lock;

	/**
	 * Constructor.
	 *
	 * @param object                  $terminal_adapter Square Terminal adapter.
	 * @param CheckoutReconciler|null $reconciler       Checkout reconciler.
	 * @param SquareErrorMapper|null  $error_mapper     Square error mapper.
	 * @param OrderLock|null          $order_lock       Per-order mutation lock.
	 */
	public function __construct( $terminal_adapter, ?CheckoutReconciler $reconciler = null, ?SquareErrorMapper $error_mapper = null, ?OrderLock $order_lock = null ) {
		$this->terminal_adapter = $terminal_adapter;
		$this->reconciler       = $reconciler ?? new CheckoutReconciler( $terminal_adapter );
		$this->error_mapper     = $error_mapper ?? new SquareErrorMapper();
		$this->order_lock       = $order_lock ?? new OrderLock();
	}

	/**
	 * Create a Square Terminal checkout for a WooCommerce order.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function create_terminal_checkout( array $request ): array {
		$authorized = $this->authorized_order( $request );
		if ( isset( $authorized['error'] ) ) {
			return $authorized['error'];
		}
		$device_id = sanitize_text_field( $request['device_id'] ?? '' );
		if ( '' === $device_id ) {
			return $this->error_response( 400, __( 'Device ID is required.', 'square-terminal-for-woocommerce' ) );
		}

		return $this->with_fresh_order_lock(
			$authorized['order']->get_id(),
			fn( $order ) => $this->create_terminal_checkout_for_order( $order, $device_id )
		);
	}

	/**
	 * Create a checkout after acquiring the order lock and reloading the order.
	 *
	 * @param object $order     Fresh WooCommerce order.
	 * @param string $device_id Square device ID.
	 * @return array<string,mixed>
	 */
	private function create_terminal_checkout_for_order( $order, string $device_id ): array {
		if ( $order->is_paid() ) {
			return $this->error_response( 409, __( 'This order is already paid.', 'square-terminal-for-woocommerce' ) );
		}

		$recorded_payment_ids = $order->get_meta( '_sqtwc_payment_ids', true );
		if ( (int) $order->get_meta( '_sqtwc_collected_amount', true ) > 0 || ( is_array( $recorded_payment_ids ) && ! empty( $recorded_payment_ids ) ) ) {
			return $this->error_response( 409, __( 'Square already captured a partial payment for this order. Resolve it in Square Dashboard (refund or complete manually) before starting another Terminal payment.', 'square-terminal-for-woocommerce' ) );
		}

		$current_checkout = (string) $order->get_meta( '_sqtwc_checkout_id', true );
		$current_attempt  = (string) $order->get_meta( '_sqtwc_current_attempt_id', true );
		$current_device   = (string) $order->get_meta( '_sqtwc_device_id', true );
		if ( '' !== $current_checkout ) {
			return $this->error_response( 409, __( 'A Terminal payment attempt is already active for this order.', 'square-terminal-for-woocommerce' ) );
		}

		$idempotency_key = (string) $order->get_meta( '_sqtwc_checkout_idempotency_key', true );
		if ( '' !== $current_attempt ) {
			if ( $device_id !== $current_device ) {
				return $this->error_response( 409, __( 'Retry on the original terminal or release the payment first.', 'square-terminal-for-woocommerce' ) );
			}

			$device_id = $current_device;
			$create_data = $order->get_meta( '_sqtwc_attempt_request', true );
			if ( ! is_array( $create_data ) || empty( $create_data ) ) {
				return $this->error_response( 409, __( 'The saved Terminal payment request is unavailable. Release the payment and start again.', 'square-terminal-for-woocommerce' ) );
			}
		} else {
			$current_attempt  = wp_generate_uuid4();
			$idempotency_key = wp_generate_uuid4();
			$create_data = array(
				'amount'              => CurrencyConverter::to_minor_units( $order->get_total(), $order->get_currency() ),
				'currency'            => $order->get_currency(),
				'device_id'           => $device_id,
				'reference_id'        => 'woocommerce_order_' . $order->get_id(),
				'idempotency_key'     => $idempotency_key,
				'deadline_duration'   => 'PT5M',
				'note'                => $this->checkout_note( $order ),
				'skip_receipt_screen' => 'yes' === Settings::get( 'skip_receipt_screen', 'no' ),
				'collect_signature'   => 'yes' === Settings::get( 'collect_signature', 'no' ),
			);
			OrderMeta::start_attempt( $order, $current_attempt, $idempotency_key, $device_id, $create_data );
		}

		$result = null;
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			try {
				$result = $this->terminal_adapter->create_checkout( $create_data );
				break;
			} catch ( Throwable $exception ) {
				$mapped = $this->error_mapper->map( $exception );
				Logger::error( 'Square Terminal checkout creation failed', $mapped['log_context'] );
				if ( ! $mapped['retriable'] || 1 === $attempt ) {
					OrderMeta::append_log( $order, 'error', $mapped['cashier_message'] );
					if ( ! $mapped['retriable'] && 'IDEMPOTENCY_KEY_REUSED' !== (string) ( $mapped['log_context']['code'] ?? '' ) ) {
						// Terminal failure: close the attempt so a fresh create is allowed.
						// Retriable exhaustion keeps the attempt so a later retry resumes
						// with the same idempotency key (Square may have created the checkout).
						OrderMeta::close_current_attempt( $order, 'failed' );
					}
					$order->save();

					return $this->mapped_error_response( $mapped );
				}
			}
		}

		if ( ! is_array( $result ) || empty( $result['id'] ) ) {
			// Indeterminate: Square may have created the checkout even though the
			// response was unusable. Keep the attempt so a retry resumes with the
			// same idempotency key (recovering the orphaned checkout's identity)
			// and the cashier can still release it via detach.
			OrderMeta::append_log( $order, 'error', __( 'Square did not confirm the Terminal checkout. Retry to check, or release the payment.', 'square-terminal-for-woocommerce' ) );
			$order->save();

			return $this->error_response( 502, __( 'Square did not confirm the Terminal checkout. Retry to check, or release the payment.', 'square-terminal-for-woocommerce' ) );
		}

		$order->update_meta_data( '_sqtwc_checkout_id', (string) $result['id'] );
		$order->update_meta_data( '_sqtwc_checkout_status', (string) ( $result['status'] ?? 'PENDING' ) );
		if ( ! empty( $result['updated_at'] ) ) {
			$order->update_meta_data( '_sqtwc_checkout_updated_at', (string) $result['updated_at'] );
		}
		OrderMeta::append_log( $order, 'info', __( 'Terminal checkout created.', 'square-terminal-for-woocommerce' ) );
		$order->save();
		OrderMeta::index_order( (int) $order->get_id() );

		Logger::info(
			'Terminal checkout created',
			array(
				'order_id'    => $order->get_id(),
				'attempt_id'  => $current_attempt,
				'checkout_id' => $result['id'],
				'device_id'   => $device_id,
				'status'      => $result['status'] ?? 'PENDING',
			)
		);

		return array(
			'status'   => 200,
			'checkout' => $result,
		);
	}

	/**
	 * Return the provider-verified or throttled cached Terminal status.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function get_terminal_status( array $request ): array {
		$authorized = $this->authorized_order( $request );
		if ( isset( $authorized['error'] ) ) {
			return $authorized['error'];
		}
		return $this->with_fresh_order_lock(
			$authorized['order']->get_id(),
			function ( $order ) use ( $request ): array {
				if ( $order->is_paid() ) {
					return $this->with_redirect(
						array(
							'status'           => 'COMPLETED',
							'cashier_message'  => CheckoutReconciler::cashier_message( 'COMPLETED' ),
							'continue_polling' => false,
						),
						$order
					);
				}

				$cached_status = (string) $order->get_meta( '_sqtwc_checkout_status', true );
				$checkout_id   = (string) $order->get_meta( '_sqtwc_checkout_id', true );
				if ( in_array( $cached_status, array( 'COMPLETED', 'CANCELED' ), true ) || '' === $checkout_id ) {
					return $this->cached_status_response( $order, $cached_status, $checkout_id );
				}

				$force        = '1' === (string) ( $request['force'] ?? '' );
				$last_checked = (int) $order->get_meta( '_sqtwc_square_checked_at', true );
				if ( ! $force && $last_checked > 0 && ( time() - $last_checked ) < 5 ) {
					return $this->cached_status_response( $order, $cached_status, $checkout_id );
				}

				try {
					$result = $this->fetch_and_reconcile( $order, $checkout_id );
				} catch ( Throwable $exception ) {
					return $this->fetch_error_response( $exception, $order );
				}

				return $this->with_redirect( $result, $order );
			}
		);
	}

	/**
	 * Execute compare-before-cancel for an authorized AJAX request.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function cancel_terminal_checkout( array $request ): array {
		$authorized = $this->authorized_order( $request );
		if ( isset( $authorized['error'] ) ) {
			return $authorized['error'];
		}

		return $this->cancel_terminal_checkout_for_order(
			$authorized['order'],
			(string) $request['checkout_id'],
			(string) $request['device_id']
		);
	}

	/**
	 * Execute compare-before-cancel for an already-authorized order.
	 *
	 * @param object              $order       WooCommerce order.
	 * @param string              $checkout_id Square checkout ID.
	 * @param string              $device_id   Square device ID.
	 * @param array<string,mixed> $options     SDK request options.
	 * @return array<string,mixed>
	 */
	public function cancel_terminal_checkout_for_order( $order, string $checkout_id, string $device_id, array $options = array() ): array {
		return $this->with_fresh_order_lock(
			$order->get_id(),
			fn( $fresh_order ) => $this->cancel_terminal_checkout_locked( $fresh_order, $checkout_id, $device_id, $options )
		);
	}

	/**
	 * Execute compare-before-cancel while the order lock is held.
	 *
	 * @param object              $order       Fresh WooCommerce order.
	 * @param string              $checkout_id Square checkout ID.
	 * @param string              $device_id   Square device ID.
	 * @param array<string,mixed> $options     SDK request options.
	 * @return array<string,mixed>
	 */
	private function cancel_terminal_checkout_locked( $order, string $checkout_id, string $device_id, array $options ): array {
		if ( $checkout_id !== (string) $order->get_meta( '_sqtwc_checkout_id', true ) || $device_id !== (string) $order->get_meta( '_sqtwc_device_id', true ) ) {
			return $this->error_response( 409, __( 'The Terminal checkout no longer matches this payment attempt.', 'square-terminal-for-woocommerce' ) );
		}

		try {
			$before = $this->fetch_and_reconcile( $order, $checkout_id, $options );
		} catch ( Throwable $exception ) {
			return $this->fetch_error_response( $exception, $order );
		}

		if ( in_array( $before['status'] ?? '', array( 'COMPLETED', 'CANCELED' ), true ) ) {
			return $this->with_redirect( $before, $order );
		}

		$cancel_exception = null;
		try {
			$this->terminal_adapter->cancel_checkout( $checkout_id, $options );
		} catch ( Throwable $exception ) {
			$cancel_exception = $exception;
			$mapped           = $this->error_mapper->map( $exception );
			Logger::error( 'Terminal cancel request was inconclusive', $mapped['log_context'] );
		}

		try {
			$after = $this->fetch_and_reconcile( $order, $checkout_id, $options );
		} catch ( Throwable $exception ) {
			$mapped = $this->error_mapper->map( $cancel_exception ?? $exception );

			return array(
				'status'           => (string) $order->get_meta( '_sqtwc_checkout_status', true ),
				'cashier_message'  => __( 'Cancellation could not be confirmed. Keep checking the Terminal payment status.', 'square-terminal-for-woocommerce' ),
				'continue_polling' => true,
				'retriable'        => $mapped['retriable'],
			);
		}

		return $this->with_redirect( $after, $order );
	}

	/**
	 * Detach a stuck checkout from the current attempt without forgetting it.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function detach_terminal_checkout( array $request ): array {
		$authorized = $this->authorized_order( $request );
		if ( isset( $authorized['error'] ) ) {
			return $authorized['error'];
		}
		return $this->with_fresh_order_lock(
			$authorized['order']->get_id(),
			function ( $order ) use ( $request ): array {
				$checkout_id = (string) $order->get_meta( '_sqtwc_checkout_id', true );
				if ( '' === $checkout_id ) {
					$posted_device = sanitize_text_field( $request['device_id'] ?? '' );
					$stored_device = (string) $order->get_meta( '_sqtwc_device_id', true );
					$identity_error = '' === $posted_device || $posted_device !== $stored_device
						? $this->error_response( 409, __( 'The Terminal checkout no longer matches this payment attempt.', 'square-terminal-for-woocommerce' ) )
						: null;
				} else {
					$identity_error = $this->validate_current_identity( $order, $request );
				}
				if ( null !== $identity_error ) {
					return $identity_error;
				}

				if ( '' !== $checkout_id ) {
					$abandoned = $order->get_meta( '_sqtwc_abandoned_checkout_ids', true );
					$abandoned = is_array( $abandoned ) ? $abandoned : array();
					if ( ! in_array( $checkout_id, $abandoned, true ) ) {
						$abandoned[] = $checkout_id;
					}
					$order->update_meta_data( '_sqtwc_abandoned_checkout_ids', $abandoned );
				}
				OrderMeta::close_current_attempt( $order, 'abandoned' );
				$order->update_meta_data( '_sqtwc_checkout_status', '' );
				$order->update_meta_data( '_sqtwc_checkout_updated_at', '' );
				OrderMeta::append_log( $order, 'warning', __( 'Terminal checkout released. Its final Square status will still be reconciled.', 'square-terminal-for-woocommerce' ) );
				$order->save();

				return array(
					'status'           => 'IDLE',
					'cashier_message'  => __( 'Terminal payment released. You can start another payment.', 'square-terminal-for-woocommerce' ),
					'continue_polling' => false,
				);
			}
		);
	}

	/**
	 * Fetch a checkout, update the throttle timestamp, and reconcile it.
	 *
	 * @param object              $order       WooCommerce order.
	 * @param string              $checkout_id Square checkout ID.
	 * @param array<string,mixed> $options     SDK request options.
	 * @return array<string,mixed>
	 */
	private function fetch_and_reconcile( $order, string $checkout_id, array $options = array() ): array {
		$order->update_meta_data( '_sqtwc_square_checked_at', time() );
		$order->save();
		$checkout = $this->terminal_adapter->get_checkout( $checkout_id, $options );

		return $this->reconciler->reconcile( $checkout, $order, $options );
	}

	/**
	 * Acquire the per-order lock and pass a freshly loaded order to a callback.
	 *
	 * @param int      $order_id Order ID.
	 * @param callable $callback Locked callback.
	 * @return array<string,mixed>
	 */
	private function with_fresh_order_lock( int $order_id, callable $callback ): array {
		try {
			return $this->order_lock->with_lock(
				$order_id,
				function () use ( $order_id, $callback ): array {
					$order = wc_get_order( $order_id );
					if ( ! $order ) {
						return $this->error_response( 404, __( 'Order not found.', 'square-terminal-for-woocommerce' ) );
					}

					return $callback( $order );
				}
			);
		} catch ( Throwable $exception ) {
			Logger::error(
				'Square Terminal order mutation lock failed',
				array(
					'order_id'        => $order_id,
					'exception_class' => get_class( $exception ),
					'detail'          => $exception->getMessage(),
				)
			);

			return $this->error_response( 503, __( 'This order is busy. Please try again.', 'square-terminal-for-woocommerce' ) );
		}
	}

	/**
	 * Resolve and authorize an order using the unchanged OrderAccess model.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	private function authorized_order( array $request ): array {
		$order = wc_get_order( absint( $request['order_id'] ?? 0 ) );
		if ( ! $order ) {
			return array( 'error' => $this->error_response( 404, __( 'Order not found.', 'square-terminal-for-woocommerce' ) ) );
		}

		$has_order_key = isset( $request['order_key'] )
			&& '' !== (string) $request['order_key']
			&& hash_equals( (string) $order->get_order_key(), (string) $request['order_key'] );
		$has_payment_token = isset( $request['payment_request_token'] )
			&& PaymentRequestToken::verify( (string) $request['payment_request_token'], (int) $order->get_id() );

		if ( $this->requires_nonce() && ! $has_order_key && ! $has_payment_token && ! wp_verify_nonce( $request['_wpnonce'] ?? '', 'sqtwc_payment' ) ) {
			return array( 'error' => $this->error_response( 403, __( 'Invalid nonce.', 'square-terminal-for-woocommerce' ) ) );
		}

		if ( ! OrderAccess::can_mutate_order( $order, $request ) ) {
			return array( 'error' => $this->error_response( 403, __( 'Order access denied.', 'square-terminal-for-woocommerce' ) ) );
		}

		return array( 'order' => $order );
	}

	/**
	 * Verify compare-before-cancel/detach identity fields.
	 *
	 * @param object              $order   WooCommerce order.
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>|null
	 */
	private function validate_current_identity( $order, array $request ): ?array {
		$checkout_id = sanitize_text_field( $request['checkout_id'] ?? '' );
		$device_id   = sanitize_text_field( $request['device_id'] ?? '' );
		if ( '' === $checkout_id || '' === $device_id || $checkout_id !== (string) $order->get_meta( '_sqtwc_checkout_id', true ) || $device_id !== (string) $order->get_meta( '_sqtwc_device_id', true ) ) {
			return $this->error_response( 409, __( 'The Terminal checkout no longer matches this payment attempt.', 'square-terminal-for-woocommerce' ) );
		}

		return null;
	}

	/**
	 * Build a cached lifecycle response.
	 *
	 * @param object $order         WooCommerce order.
	 * @param string $cached_status Cached Square status.
	 * @param string $checkout_id   Active checkout ID.
	 * @return array<string,mixed>
	 */
	private function cached_status_response( $order, string $cached_status, string $checkout_id ): array {
		if ( '' === $checkout_id && '' === $cached_status ) {
			return array(
				'status'           => 'IDLE',
				'cashier_message'  => __( 'No active Terminal payment.', 'square-terminal-for-woocommerce' ),
				'continue_polling' => false,
			);
		}

		$response_status = '' !== $cached_status ? $cached_status : 'PENDING';

		return $this->with_redirect(
			array(
				'status'           => $response_status,
				'cashier_message'  => CheckoutReconciler::cashier_message( $response_status ),
				'continue_polling' => in_array( $cached_status, array( 'PENDING', 'IN_PROGRESS', 'CANCEL_REQUESTED' ), true ),
			),
			$order
		);
	}

	/**
	 * Map a status-fetch exception without exposing provider detail.
	 *
	 * @param Throwable $exception Provider or transport exception.
	 * @param object    $order     WooCommerce order.
	 * @return array<string,mixed>
	 */
	private function fetch_error_response( Throwable $exception, $order ): array {
		$mapped = $this->error_mapper->map( $exception );
		Logger::error( 'Square Terminal status fetch failed', $mapped['log_context'] );

		return array(
			'http_status'      => $mapped['retriable'] ? 502 : 400,
			'status'           => (string) $order->get_meta( '_sqtwc_checkout_status', true ),
			'cashier_message'  => $mapped['cashier_message'],
			'continue_polling' => true,
			'retriable'        => $mapped['retriable'],
			'error_code'       => '' !== $mapped['log_context']['code'] ? $mapped['log_context']['code'] : 'square_error',
		);
	}

	/**
	 * Add a thank-you redirect when the order is paid.
	 *
	 * @param array<string,mixed> $response Lifecycle response.
	 * @param object              $order    WooCommerce order.
	 * @return array<string,mixed>
	 */
	private function with_redirect( array $response, $order ): array {
		unset( $response['applied'], $response['reason'] );
		if ( $order->is_paid() ) {
			$response['redirect_url'] = $order->get_checkout_order_received_url();
		}

		return $response;
	}

	/**
	 * Build a provider-safe mapped error response.
	 *
	 * @param array<string,mixed> $mapped Mapped Square error.
	 * @return array<string,mixed>
	 */
	private function mapped_error_response( array $mapped ): array {
		return array(
			'status'           => $mapped['http_status'] ?? ( $mapped['retriable'] ? 502 : 400 ),
			'error_code'       => '' !== $mapped['log_context']['code'] ? $mapped['log_context']['code'] : 'square_error',
			'cashier_message'  => $mapped['cashier_message'],
			'retriable'        => $mapped['retriable'],
			'continue_polling' => false,
		);
	}

	/**
	 * Build a translated local error response.
	 *
	 * @return array<string,mixed>
	 */
	private function error_response( int $status, string $message ): array {
		return array(
			'status'           => $status,
			'cashier_message'  => $message,
			'continue_polling' => false,
		);
	}

	/**
	 * Build the Square checkout note, capped at 500 characters.
	 *
	 * @param object $order WooCommerce order.
	 */
	private function checkout_note( $order ): string {
		$note = sprintf(
			/* translators: 1: store name, 2: WooCommerce order number. */
			__( '%1$s — Order #%2$s', 'square-terminal-for-woocommerce' ),
			get_bloginfo( 'name' ),
			$order->get_order_number()
		);

		return mb_substr( $note, 0, 500 );
	}

	/**
	 * Return whether the current server-side auth state requires a nonce.
	 */
	private function requires_nonce(): bool {
		return is_user_logged_in() || current_user_can( 'manage_woocommerce' );
	}
}
