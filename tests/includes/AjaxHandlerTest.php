<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\SquareTerminal\Services\CheckoutReconciler;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Exceptions\SquareApiException;

final class LifecycleAdapter {
	public int $creates = 0;
	public int $gets = 0;
	public int $cancels = 0;
	public int $payment_gets = 0;
	public array $create_requests = array();
	public array $create_results = array();
	public array $get_results = array();
	public array $cancel_results = array();
	public array $payments = array();

	public function create_checkout( array $data ): array {
		++$this->creates;
		$this->create_requests[] = $data;
		$result = array_shift( $this->create_results );
		if ( $result instanceof \Throwable ) {
			throw $result;
		}

		return $result ?? $this->checkout( 'PENDING' );
	}

	public function get_checkout( string $id, array $options = array() ): array {
		++$this->gets;
		$result = array_shift( $this->get_results );
		if ( $result instanceof \Throwable ) {
			throw $result;
		}

		$result       = $result ?? $this->checkout( 'PENDING' );
		$result['id'] = $id;

		return $result;
	}

	public function cancel_checkout( string $id, array $options = array() ): array {
		++$this->cancels;
		$result = array_shift( $this->cancel_results );
		if ( $result instanceof \Throwable ) {
			throw $result;
		}

		$result       = $result ?? $this->checkout( 'CANCEL_REQUESTED' );
		$result['id'] = $id;

		return $result;
	}

	public function get_payment( string $id, array $options = array() ): array {
		++$this->payment_gets;

		return $this->payments[ $id ] ?? array(
			'id'             => $id,
			'status'         => 'COMPLETED',
			'total_amount'   => 1234,
			'total_currency' => 'USD',
			'tip_amount'     => 0,
			'tip_currency'   => null,
			'card_status'    => null,
		);
	}

	public function checkout( string $status, array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => 'chk_current',
				'status'        => $status,
				'reference_id'  => 'woocommerce_order_99',
				'payment_ids'   => array(),
				'updated_at'    => '2026-07-16T10:00:03Z',
				'created_at'    => null,
				'cancel_reason' => null,
			),
			$overrides
		);
	}
}

final class AjaxHandlerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_orders']            = array();
		$GLOBALS['sqtwc_current_user_can']  = false;
		$GLOBALS['sqtwc_is_user_logged_in'] = false;
		$GLOBALS['sqtwc_nonce_valid']       = false;
		$GLOBALS['sqtwc_options'] = array(
			'woocommerce_sqtwc_settings' => array(
				'skip_receipt_screen' => 'yes',
				'collect_signature'   => 'no',
			),
		);
		Settings::reset_cache_for_tests();
	}

	public function test_create_checkout_rejects_before_side_effect_without_access(): void {
		$order                       = new \SQTWC_Test_Order( 99 );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();

		$result = ( new AjaxHandler( $adapter ) )->create_terminal_checkout(
			array(
				'order_id'  => 99,
				'device_id' => 'D',
			)
		);

		self::assertSame( 403, $result['status'] );
		self::assertSame( 0, $adapter->creates );
	}

	public function test_create_checkout_requires_nonce_for_authenticated_staff_from_server_state(): void {
		$order                                = new \SQTWC_Test_Order( 99 );
		$GLOBALS['sqtwc_orders'][99]          = $order;
		$GLOBALS['sqtwc_current_user_can']    = true;
		$GLOBALS['sqtwc_is_user_logged_in']   = true;
		$GLOBALS['sqtwc_nonce_valid']         = false;

		$result = ( new AjaxHandler( new LifecycleAdapter() ) )->create_terminal_checkout(
			array(
				'order_id'  => 99,
				'device_id' => 'D',
			)
		);

		self::assertSame( 403, $result['status'] );
	}

	public function test_create_rejects_paid_order_and_active_checkout(): void {
		$paid                       = new \SQTWC_Test_Order( 99 );
		$paid->paid                 = true;
		$GLOBALS['sqtwc_orders'][99] = $paid;
		$adapter                    = new LifecycleAdapter();
		$result                     = ( new AjaxHandler( $adapter ) )->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'D', 'order_key' => 'key' ) );
		self::assertSame( 409, $result['status'] );

		$open = $this->open_order();
		$GLOBALS['sqtwc_orders'][99] = $open;
		$result = ( new AjaxHandler( $adapter ) )->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'D', 'order_key' => 'key' ) );
		self::assertSame( 409, $result['status'] );
		self::assertSame( 0, $adapter->creates );
	}

	public function test_create_blocks_when_square_already_captured_partial_funds(): void {
		$adapter = new LifecycleAdapter();
		foreach ( array( '_sqtwc_collected_amount' => 500, '_sqtwc_payment_ids' => array( 'pay_partial' ) ) as $meta_key => $meta_value ) {
			$order = new \SQTWC_Test_Order( 99 );
			$order->update_meta_data( $meta_key, $meta_value );
			$GLOBALS['sqtwc_orders'][99] = $order;

			$result = ( new AjaxHandler( $adapter ) )->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'D', 'order_key' => 'key' ) );

			self::assertSame( 409, $result['status'] );
			self::assertSame( 'Square already captured a partial payment for this order. Resolve it in Square Dashboard (refund or complete manually) before starting another Terminal payment.', $result['cashier_message'] );
		}
		self::assertSame( 0, $adapter->creates );
	}

	public function test_create_recomputes_amount_persists_attempt_and_retries_once_with_same_key(): void {
		$order                       = new \SQTWC_Test_Order( 99 );
		$order->total                = '19.87';
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();
		$adapter->create_results     = array(
			new \RuntimeException( 'network timeout with provider detail' ),
			$adapter->checkout( 'PENDING', array( 'id' => 'chk_created' ) ),
		);

		$result = ( new AjaxHandler( $adapter ) )->create_terminal_checkout(
			array(
				'order_id'  => 99,
				'device_id' => 'device_a',
				'amount'    => 1,
				'order_key' => 'key',
			)
		);

		self::assertSame( 200, $result['status'] );
		self::assertSame( 2, $adapter->creates );
		self::assertSame( 1987, $adapter->create_requests[0]['amount'] );
		self::assertSame( $adapter->create_requests[0]['idempotency_key'], $adapter->create_requests[1]['idempotency_key'] );
		self::assertSame( 'PT5M', $adapter->create_requests[0]['deadline_duration'] );
		self::assertSame( 'Test Store — Order #99', $adapter->create_requests[0]['note'] );
		self::assertTrue( $adapter->create_requests[0]['skip_receipt_screen'] );
		self::assertFalse( $adapter->create_requests[0]['collect_signature'] );
		self::assertSame( '00000000-0000-4000-8000-000000000000', $order->get_meta( '_sqtwc_current_attempt_id' ) );
		self::assertSame( 'chk_created', $order->get_meta( '_sqtwc_checkout_id' ) );
		self::assertSame( 'device_a', $order->get_meta( '_sqtwc_device_id' ) );
		self::assertSame( 'PENDING', $order->get_meta( '_sqtwc_checkout_status' ) );
		self::assertArrayHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_create_maps_non_retriable_provider_error_without_exposing_detail(): void {
		$order                       = new \SQTWC_Test_Order( 99 );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();
		$adapter->create_results[]   = new SquareApiException(
			'failed',
			400,
			array(
				'errors' => array(
					array(
						'category' => 'INVALID_REQUEST_ERROR',
						'code'     => 'INVALID_LOCATION',
						'detail'   => 'raw provider location detail',
					),
				),
			)
		);

		$result = ( new AjaxHandler( $adapter ) )->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'D', 'order_key' => 'key' ) );

		self::assertSame( 400, $result['status'] );
		self::assertSame( 'The configured Square location is invalid. Check plugin settings.', $result['cashier_message'] );
		self::assertStringNotContainsString( 'raw provider', json_encode( $result ) );
		self::assertSame( 1, $adapter->creates );
	}

	public function test_non_retriable_create_failure_closes_attempt_and_allows_second_create(): void {
		$order                       = new \SQTWC_Test_Order( 99 );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();
		$adapter->create_results     = array(
			new SquareApiException(
				'failed',
				400,
				array(
					'errors' => array(
						array(
							'category' => 'INVALID_REQUEST_ERROR',
							'code'     => 'INVALID_LOCATION',
						),
					),
				)
			),
			$adapter->checkout( 'PENDING', array( 'id' => 'chk_second' ) ),
		);
		$handler                     = new AjaxHandler( $adapter );

		$failed = $handler->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'device_a', 'order_key' => 'key' ) );

		self::assertSame( 400, $failed['status'] );
		self::assertSame( '', $order->get_meta( '_sqtwc_current_attempt_id' ) );
		self::assertSame( '', $order->get_meta( '_sqtwc_checkout_idempotency_key' ) );
		self::assertSame( '', $order->get_meta( '_sqtwc_device_id' ) );
		self::assertArrayNotHasKey( '_sqtwc_attempt_request', $order->meta );
		self::assertSame( 'failed', $order->get_meta( '_sqtwc_attempt_history' )[0]['status'] );
		self::assertArrayNotHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );

		$created = $handler->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'device_a', 'order_key' => 'key' ) );

		self::assertSame( 200, $created['status'] );
		self::assertSame( 'chk_second', $order->get_meta( '_sqtwc_checkout_id' ) );
		self::assertSame( 2, $adapter->creates );
	}

	public function test_ambiguous_create_failure_keeps_attempt_and_resume_reuses_identity(): void {
		$order                       = new \SQTWC_Test_Order( 99 );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();
		$adapter->create_results     = array(
			new \RuntimeException( 'first timeout' ),
			new \RuntimeException( 'second timeout' ),
			$adapter->checkout( 'PENDING', array( 'id' => 'chk_resumed' ) ),
		);
		$handler                     = new AjaxHandler( $adapter );

		$failed = $handler->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'device_a', 'order_key' => 'key' ) );

		self::assertSame( 502, $failed['status'] );
		self::assertSame( '00000000-0000-4000-8000-000000000000', $order->get_meta( '_sqtwc_current_attempt_id' ) );
		self::assertSame( $adapter->create_requests[0]['idempotency_key'], $order->get_meta( '_sqtwc_checkout_idempotency_key' ) );
		self::assertSame( 'device_a', $order->get_meta( '_sqtwc_device_id' ) );
		self::assertSame( '', $order->get_meta( '_sqtwc_checkout_id' ) );

		$resumed = $handler->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'device_a', 'order_key' => 'key' ) );

		self::assertSame( 200, $resumed['status'] );
		self::assertSame( 'chk_resumed', $order->get_meta( '_sqtwc_checkout_id' ) );
		self::assertSame( 3, $adapter->creates );
		self::assertSame( $adapter->create_requests[0]['idempotency_key'], $adapter->create_requests[2]['idempotency_key'] );
		self::assertSame( 'device_a', $adapter->create_requests[2]['device_id'] );
	}

	public function test_indeterminate_create_response_keeps_attempt_and_resume_recovers_checkout(): void {
		$order                       = new \SQTWC_Test_Order( 99 );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();
		$adapter->create_results     = array(
			array( 'id' => null, 'status' => null ),
			$adapter->checkout( 'PENDING', array( 'id' => 'chk_orphan' ) ),
		);
		$handler                     = new AjaxHandler( $adapter );

		$failed = $handler->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'device_a', 'order_key' => 'key' ) );

		self::assertSame( 502, $failed['status'] );
		self::assertNotSame( '', $order->get_meta( '_sqtwc_current_attempt_id' ), 'An indeterminate create response must keep the attempt: Square may have created the checkout.' );
		self::assertNotSame( '', $order->get_meta( '_sqtwc_checkout_idempotency_key' ) );

		$resumed = $handler->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'device_a', 'order_key' => 'key' ) );

		self::assertSame( 200, $resumed['status'] );
		self::assertSame( 'chk_orphan', $order->get_meta( '_sqtwc_checkout_id' ) );
		self::assertSame( $adapter->create_requests[0]['idempotency_key'], $adapter->create_requests[1]['idempotency_key'], 'Resume must replay the same idempotency key so an orphaned Square checkout is recovered, not duplicated.' );
	}

	public function test_resume_replays_stored_attempt_request_verbatim_after_order_changes(): void {
		$order                       = new \SQTWC_Test_Order( 99 );
		$order->total                = '19.87';
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();
		$adapter->create_results     = array(
			new \RuntimeException( 'first timeout' ),
			new \RuntimeException( 'second timeout' ),
			$adapter->checkout( 'PENDING', array( 'id' => 'chk_resumed' ) ),
		);
		$handler = new AjaxHandler( $adapter );

		$handler->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'device_a', 'order_key' => 'key' ) );
		$original_request = $adapter->create_requests[0];
		self::assertSame( $original_request, $order->get_meta( '_sqtwc_attempt_request' ) );

		$order->total = '99.99';
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'skip_receipt_screen' => 'no',
			'collect_signature'   => 'yes',
		);
		Settings::reset_cache_for_tests();

		$result = $handler->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'device_a', 'order_key' => 'key' ) );

		self::assertSame( 200, $result['status'] );
		self::assertSame( $original_request, $adapter->create_requests[2] );
	}

	public function test_resume_with_different_posted_device_returns_conflict(): void {
		$order                       = $this->checkoutless_order();
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();

		$result = ( new AjaxHandler( $adapter ) )->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'device_other', 'order_key' => 'key' ) );

		self::assertSame( 409, $result['status'] );
		self::assertSame( 'Retry on the original terminal or release the payment first.', $result['cashier_message'] );
		self::assertSame( 0, $adapter->creates );
	}

	public function test_idempotency_key_reused_create_failure_returns_conflict_and_keeps_attempt(): void {
		$order                       = new \SQTWC_Test_Order( 99 );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();
		$adapter->create_results[]   = new SquareApiException(
			'failed',
			400,
			array(
				'errors' => array(
					array(
						'category' => 'INVALID_REQUEST_ERROR',
						'code'     => 'IDEMPOTENCY_KEY_REUSED',
					),
				),
			)
		);

		$result = ( new AjaxHandler( $adapter ) )->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'device_a', 'order_key' => 'key' ) );

		self::assertSame( 409, $result['status'] );
		self::assertFalse( $result['retriable'] );
		self::assertSame( 'The previous payment request may still be active on the terminal. Check the terminal, then use Check Status or release the payment.', $result['cashier_message'] );
		self::assertSame( '00000000-0000-4000-8000-000000000000', $order->get_meta( '_sqtwc_current_attempt_id' ) );
		self::assertSame( $adapter->create_requests[0]['idempotency_key'], $order->get_meta( '_sqtwc_checkout_idempotency_key' ) );
		self::assertSame( array(), $order->get_meta( '_sqtwc_attempt_history', false ) );
	}

	public function test_status_endpoint_throttles_and_force_bypasses_cache(): void {
		$order = $this->open_order();
		$order->update_meta_data( '_sqtwc_square_checked_at', time() );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();
		$adapter->get_results[]      = $adapter->checkout( 'IN_PROGRESS' );
		$handler                     = new AjaxHandler( $adapter );

		$cached = $handler->get_terminal_status( array( 'order_id' => 99, 'order_key' => 'key' ) );
		self::assertSame( 'PENDING', $cached['status'] );
		self::assertSame( 0, $adapter->gets );

		$fresh = $handler->get_terminal_status( array( 'order_id' => 99, 'order_key' => 'key', 'force' => '1' ) );
		self::assertSame( 'IN_PROGRESS', $fresh['status'] );
		self::assertTrue( $fresh['continue_polling'] );
		self::assertArrayNotHasKey( 'applied', $fresh );
		self::assertSame( 1, $adapter->gets );
	}

	public function test_status_returns_thank_you_url_without_fetch_when_paid(): void {
		$order                       = $this->open_order();
		$order->paid                 = true;
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();

		$result = ( new AjaxHandler( $adapter ) )->get_terminal_status( array( 'order_id' => 99, 'order_key' => 'key', 'force' => 1 ) );

		self::assertSame( 'COMPLETED', $result['status'] );
		self::assertSame( '/checkout/order-received/99/?key=key', $result['redirect_url'] );
		self::assertFalse( $result['continue_polling'] );
		self::assertSame( 0, $adapter->gets );
	}

	public function test_cancel_already_completed_race_reconciles_without_cancel_request(): void {
		$order                       = $this->open_order();
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();
		$adapter->get_results[]      = $adapter->checkout( 'COMPLETED', array( 'payment_ids' => array( 'pay_1' ) ) );

		$result = ( new AjaxHandler( $adapter ) )->cancel_terminal_checkout( $this->cancel_request() );

		self::assertSame( 'COMPLETED', $result['status'] );
		self::assertTrue( $order->paid );
		self::assertSame( 0, $adapter->cancels );
		self::assertSame( '/checkout/order-received/99/?key=key', $result['redirect_url'] );
	}

	public function test_cancel_transport_failure_is_inconclusive_and_cancel_requested_keeps_polling(): void {
		$order                       = $this->open_order();
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();
		$adapter->get_results        = array( $adapter->checkout( 'PENDING' ), $adapter->checkout( 'CANCEL_REQUESTED' ) );
		$adapter->cancel_results[]   = new \RuntimeException( 'cancel transport failed' );

		$result = ( new AjaxHandler( $adapter ) )->cancel_terminal_checkout( $this->cancel_request() );

		self::assertSame( 'CANCEL_REQUESTED', $result['status'] );
		self::assertTrue( $result['continue_polling'] );
		self::assertSame( 1, $adapter->cancels );
		self::assertSame( 2, $adapter->gets );
	}

	public function test_cancel_requires_matching_checkout_and_device(): void {
		$order                       = $this->open_order();
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();

		$request              = $this->cancel_request();
		$request['device_id'] = 'other';
		$result               = ( new AjaxHandler( $adapter ) )->cancel_terminal_checkout( $request );

		self::assertSame( 409, $result['status'] );
		self::assertSame( 0, $adapter->gets );
		self::assertSame( 0, $adapter->cancels );
	}

	public function test_detach_archives_abandoned_attempt_and_clears_current_pointers(): void {
		$order                       = $this->open_order();
		$GLOBALS['sqtwc_orders'][99] = $order;

		$result = ( new AjaxHandler( new LifecycleAdapter() ) )->detach_terminal_checkout( $this->cancel_request() );

		self::assertSame( 'IDLE', $result['status'] );
		self::assertFalse( $result['continue_polling'] );
		self::assertSame( array( 'chk_current' ), $order->get_meta( '_sqtwc_abandoned_checkout_ids' ) );
		self::assertSame( 'abandoned', $order->get_meta( '_sqtwc_attempt_history' )[0]['status'] );
		self::assertSame( '', $order->get_meta( '_sqtwc_current_attempt_id' ) );
		self::assertSame( '', $order->get_meta( '_sqtwc_checkout_id' ) );
		self::assertSame( '', $order->get_meta( '_sqtwc_device_id' ) );
		self::assertArrayHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_detach_rejects_stale_register_identity(): void {
		$order                       = $this->open_order();
		$GLOBALS['sqtwc_orders'][99] = $order;
		$request                     = $this->cancel_request();
		$request['checkout_id']      = 'stale_checkout';

		$result = ( new AjaxHandler( new LifecycleAdapter() ) )->detach_terminal_checkout( $request );

		self::assertSame( 409, $result['status'] );
		self::assertEmpty( $order->get_meta( '_sqtwc_attempt_history', true ) );
	}

	public function test_detach_releases_checkoutless_attempt_with_matching_device_without_square_call(): void {
		$order = $this->checkoutless_order();
		$order->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_old' ) );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$adapter                     = new LifecycleAdapter();
		$request                     = $this->cancel_request();
		$request['checkout_id']      = '';

		$result = ( new AjaxHandler( $adapter ) )->detach_terminal_checkout( $request );

		self::assertSame( 'IDLE', $result['status'] );
		self::assertSame( array( 'chk_old' ), $order->get_meta( '_sqtwc_abandoned_checkout_ids' ) );
		self::assertSame( 'abandoned', $order->get_meta( '_sqtwc_attempt_history' )[0]['status'] );
		self::assertSame( '', $order->get_meta( '_sqtwc_current_attempt_id' ) );
		self::assertSame( 0, $adapter->creates );
		self::assertSame( 0, $adapter->gets );
		self::assertSame( 0, $adapter->cancels );
		self::assertSame( 0, $adapter->payment_gets );
	}

	private function open_order(): \SQTWC_Test_Order {
		$order = new \SQTWC_Test_Order( 99 );
		$order->update_meta_data( '_sqtwc_current_attempt_id', 'attempt_current' );
		$order->update_meta_data( '_sqtwc_checkout_idempotency_key', 'idem_current' );
		$order->update_meta_data( '_sqtwc_checkout_id', 'chk_current' );
		$order->update_meta_data( '_sqtwc_checkout_status', 'PENDING' );
		$order->update_meta_data( '_sqtwc_device_id', 'device_current' );
		$order->update_meta_data( '_sqtwc_attempt_started', time() - 30 );

		return $order;
	}

	private function checkoutless_order(): \SQTWC_Test_Order {
		$order = $this->open_order();
		$order->update_meta_data( '_sqtwc_checkout_id', '' );

		return $order;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cancel_request(): array {
		return array(
			'order_id'    => 99,
			'order_key'   => 'key',
			'checkout_id' => 'chk_current',
			'device_id'   => 'device_current',
		);
	}
}
