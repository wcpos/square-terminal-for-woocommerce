<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Legacy_Adoption;
use WCPOS\WooCommercePOS\SquareTerminal\Services\CheckoutReconciler;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

/** Under Pro's panel the page is Pro's; the old paths neither start nor settle a payment Pro owns. */
final class ProPanelTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_options']        = array( 'woocommerce_sqtwc_settings' => array( 'environment' => 'sandbox', 'collection_method' => 'terminal' ) );
		$GLOBALS['sqtwc_orders']         = array();
		$GLOBALS['sqtwc_adopted']        = array();
		$GLOBALS['sqtwc_pro_adoptions']  = array();
		$GLOBALS['sqtwc_pro_panels']     = array();
		$GLOBALS['sqtwc_pro_process']    = array();
		$GLOBALS['sqtwc_pro_refunds']    = array();
		$GLOBALS['sqtwc_ledger_rows']    = array();
		$GLOBALS['sqtwc_free_lock_held'] = false;
		$GLOBALS['sqtwc_current_user_can'] = true;
		$GLOBALS['sqtwc_nonce_valid']    = true;
		$GLOBALS['sqtwc_is_user_logged_in'] = true;
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_uses_pro_panel'] = true;
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 99 ) );
		unset( $GLOBALS['sqtwc_pro_adopt_result'], $GLOBALS['sqtwc_pro_refund_result'] );
		Settings::reset_cache_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_uses_pro_panel'] = false;
		unset( $GLOBALS['wp'], $GLOBALS['sqtwc_pro_adopt_result'], $GLOBALS['sqtwc_pro_refund_result'], $GLOBALS['sqtwc_free_lock_held'] );
	}

	private function order( int $id = 99, string $checkout = '', string $status = '' ): \SQTWC_Test_Order {
		$order = new \SQTWC_Test_Order( $id );
		if ( '' !== $checkout ) {
			$order->meta['_sqtwc_checkout_id']        = $checkout;
			$order->meta['_sqtwc_checkout_status']    = $status;
			$order->meta['_sqtwc_current_attempt_id'] = 'att-1';
			$order->meta['_sqtwc_device_id']          = 'DEV1';
			$order->meta['_sqtwc_attempt_started']    = time() - 60;
		}
		$GLOBALS['sqtwc_orders'][ $id ] = $order;
		return $order;
	}

	private function fields(): string {
		ob_start();
		( new Gateway() )->payment_fields();
		return (string) ob_get_clean();
	}

	public function test_the_order_pay_page_renders_pros_panel_after_adopting_a_live_checkout(): void {
		$order = $this->order( 99, 'TC1', 'IN_PROGRESS' );
		$html  = $this->fields();
		self::assertStringContainsString( 'wcpos-pro-order-pay-panel', $html );
		self::assertStringNotContainsString( 'id="sqtwc-payment"', $html );
		self::assertSame( array( 99 ), $GLOBALS['sqtwc_pro_panels'] );
		self::assertSame( 'sandbox:TC1', $GLOBALS['sqtwc_pro_adoptions'][0][2] );
		self::assertSame( 'sandbox:TC1', $order->meta[ Legacy_Adoption::META_ADOPTED ] );
	}

	public function test_a_refused_adoption_holds_the_panel_back_with_a_notice(): void {
		$this->order( 99, 'TC1', 'IN_PROGRESS' );
		$GLOBALS['sqtwc_free_lock_held'] = true;
		$html = $this->fields();
		self::assertStringContainsString( 'Reload the page in a moment', $html );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_panels'], 'No panel beside an attempt nobody owns' );
		$GLOBALS['sqtwc_free_lock_held'] = false;
		$GLOBALS['sqtwc_pro_adopt_result'] = new \WP_Error( 'wcpos_bad', 'refused' );
		$html = $this->fields();
		self::assertStringContainsString( 'could not be handed to WooCommerce POS', $html );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_panels'] );
	}

	public function test_the_carve_out_keeps_the_old_panel_and_adopts_nothing(): void {
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_uses_pro_panel'] = false;
		$this->order( 99, 'TC1', 'IN_PROGRESS' );
		$html = $this->fields();
		self::assertStringContainsString( 'id="sqtwc-payment"', $html );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_panels'] );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
	}

	public function test_process_payment_hands_an_unpaid_order_to_pro_and_short_circuits_a_paid_one(): void {
		$order = $this->order();
		self::assertSame( array( 'result' => 'success', 'redirect' => '/pro' ), ( new Gateway() )->process_payment( 99 ) );
		self::assertSame( array( 99 ), $GLOBALS['sqtwc_pro_process'] );
		$order->paid = true;
		self::assertSame( '/thank-you', ( new Gateway() )->process_payment( 99 )['redirect'] );
		self::assertCount( 1, $GLOBALS['sqtwc_pro_process'] );
	}

	public function test_refunds_go_through_pro_for_a_counting_leg_and_to_the_dashboard_otherwise(): void {
		$this->order();
		$gateway = new Gateway();
		self::assertContains( 'refunds', $gateway->supports );
		$GLOBALS['sqtwc_ledger_rows'][99] = array( array( 'id' => 'r1', 'method_id' => 'sqtwc', 'status' => 'captured', 'capture_mode' => 'server' ) );
		self::assertTrue( $gateway->process_refund( 99, 5.0, 'why' ) );
		self::assertSame( array( array( 99, 5.0, 'why' ) ), $GLOBALS['sqtwc_pro_refunds'] );
		// A webview row (an old-panel sale Free recorded) or a voided leg is not Pro's to refund.
		$GLOBALS['sqtwc_ledger_rows'][99] = array( array( 'id' => 'r2', 'method_id' => 'sqtwc', 'status' => 'captured', 'capture_mode' => 'webview' ), array( 'id' => 'r3', 'method_id' => 'sqtwc', 'status' => 'voided', 'capture_mode' => 'server' ) );
		$error = $gateway->process_refund( 99, 5.0 );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'sqtwc_refund_in_square', $error->get_error_code() );
		self::assertCount( 1, $GLOBALS['sqtwc_pro_refunds'] );
	}

	private function ajax(): AjaxHandler {
		return new AjaxHandler( new class() {
			public function get_checkout( string $id, array $o = array() ): array { throw new \LogicException( 'Square must not be called for a payment Pro owns' ); }
			public function cancel_checkout( string $id, array $o = array() ): array { throw new \LogicException( 'Square must not be called for a payment Pro owns' ); }
		} );
	}

	public function test_a_live_pro_row_blocks_an_old_panel_start_under_the_carve_out_too(): void {
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_uses_pro_panel'] = false;
		$this->order();
		$GLOBALS['sqtwc_ledger_rows'][99] = array( array( 'id' => 'r1', 'method_id' => 'sqtwc', 'status' => 'pending', 'capture_mode' => 'server' ) );
		$response = $this->ajax()->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'DEV1' ) );
		self::assertSame( 409, $response['status'] );
		self::assertTrue( $response['handled_by_pos'] );
		$GLOBALS['sqtwc_ledger_rows'][99] = array( array( 'id' => 'r1', 'method_id' => 'sqtwc', 'status' => 'voided', 'capture_mode' => 'server' ) );
		$response = $this->ajax()->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'DEV_GONE' ) );
		self::assertArrayNotHasKey( 'handled_by_pos', $response, 'An ended Pro leg blocks nothing' );
	}

	public function test_a_paid_adopted_order_answers_the_receipt_not_a_reload_notice(): void {
		$order = $this->order( 99, 'TC1', 'IN_PROGRESS' );
		Legacy_Adoption::adopt_order( 99 );
		$GLOBALS['sqtwc_ledger_rows'][99] = array( array( 'id' => 'row-1', 'status' => 'captured' ) );
		$order->paid = true;
		$response = $this->ajax()->get_terminal_status( array( 'order_id' => 99 ) );
		self::assertSame( 'COMPLETED', $response['status'] );
		self::assertArrayNotHasKey( 'handled_by_pos', $response );
	}

	public function test_under_pros_panel_no_old_panel_start_is_accepted(): void {
		$this->order();
		$response = $this->ajax()->create_terminal_checkout( array( 'order_id' => 99, 'device_id' => 'DEV1' ) );
		self::assertSame( 409, $response['status'] );
		self::assertTrue( $response['handled_by_pos'] );
		self::assertFalse( $response['continue_polling'] );
		self::assertArrayNotHasKey( '_sqtwc_current_attempt_id', $GLOBALS['sqtwc_orders'][99]->meta, 'No attempt is started' );
	}

	public function test_a_cancel_that_waited_while_adoption_ran_is_refused_under_the_lock(): void {
		// Not adopted when the request is authorised; adopted by the time the lock is taken.
		$order = $this->order( 99, 'TC1', 'IN_PROGRESS' );
		$GLOBALS['sqtwc_ledger_rows'][99] = array( array( 'id' => 'row-1', 'status' => 'pending' ) );
		$GLOBALS['sqtwc_wc_get_order_callback'] = static function ( $id ) use ( $order ) {
			if ( \WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock::class && '' !== (string) get_option( 'sqtwc_lock_99', '' ) ) {
				$GLOBALS['sqtwc_adopted']['sandbox:TC1'] = 'row-1';
				$order->meta[ Legacy_Adoption::META_ADOPTED ] = 'sandbox:TC1';
			}
			return $order;
		};
		try {
			$response = $this->ajax()->cancel_terminal_checkout( array( 'order_id' => 99, 'checkout_id' => 'TC1', 'device_id' => 'DEV1' ) );
		} finally {
			unset( $GLOBALS['sqtwc_wc_get_order_callback'] );
		}
		self::assertSame( 409, $response['status'] );
		self::assertTrue( $response['handled_by_pos'] );
	}

	public function test_the_order_status_cleanup_still_cancels_an_adopted_checkout(): void {
		$order = $this->order( 99, 'TC1', 'IN_PROGRESS' );
		Legacy_Adoption::adopt_order( 99 );
		$GLOBALS['sqtwc_ledger_rows'][99] = array( array( 'id' => 'row-1', 'status' => 'pending' ) );
		$adapter = new class() {
			public array $calls = array();
			public function get_checkout( string $id, array $o = array() ): array { $this->calls[] = 'get'; return array( 'id' => $id, 'status' => 'IN_PROGRESS', 'reference_id' => 'woocommerce_order_99', 'payment_ids' => array() ); }
			public function cancel_checkout( string $id, array $o = array() ): array { $this->calls[] = 'cancel'; return array( 'id' => $id, 'status' => 'CANCELED', 'cancel_reason' => 'SELLER_CANCELED', 'reference_id' => 'woocommerce_order_99', 'payment_ids' => array() ); }
		};
		( new AjaxHandler( $adapter ) )->cancel_terminal_checkout_for_order( $order, 'TC1', 'DEV1' );
		self::assertContains( 'cancel', $adapter->calls, 'The cleanup cancels the adopted checkout; Pro\'s poll then voids its leg' );
		// The cashier's own request for the same checkout is refused.
		$response = ( new AjaxHandler( $adapter ) )->cancel_terminal_checkout_for_order( $order, 'TC1', 'DEV1', array(), true );
		self::assertSame( 409, $response['status'] );
	}

	public function test_status_cancel_and_release_of_an_adopted_checkout_answer_409_without_calling_square(): void {
		$order = $this->order( 99, 'TC1', 'IN_PROGRESS' );
		Legacy_Adoption::adopt_order( 99 );
		$GLOBALS['sqtwc_ledger_rows'][99] = array( array( 'id' => 'row-1', 'status' => 'pending' ) );
		$ajax = $this->ajax();
		foreach ( array( 'get_terminal_status', 'cancel_terminal_checkout', 'detach_terminal_checkout' ) as $method ) {
			$response = $ajax->$method( array( 'order_id' => 99, 'checkout_id' => 'TC1', 'device_id' => 'DEV1' ) );
			self::assertSame( 409, $response['status'], $method );
			self::assertTrue( $response['handled_by_pos'], $method );
		}
		self::assertSame( 'TC1', $order->meta['_sqtwc_checkout_id'], 'Nothing released' );
	}

	public function test_the_reconciler_ignores_a_checkout_pro_owns_and_acts_again_once_pros_leg_ended(): void {
		$order = $this->order( 99, 'TC1', 'IN_PROGRESS' );
		Legacy_Adoption::adopt_order( 99 );
		$GLOBALS['sqtwc_ledger_rows'][99] = array( array( 'id' => 'row-1', 'status' => 'pending' ) );
		$reconciler = new CheckoutReconciler( new class() {
			public function get_payment( string $id, array $o = array() ): array { return array( 'id' => $id, 'status' => 'COMPLETED', 'total_amount' => 1234, 'total_currency' => 'USD', 'tip_amount' => 0, 'card_status' => 'CAPTURED' ); }
		} );
		$completed = array( 'id' => 'TC1', 'status' => 'COMPLETED', 'reference_id' => 'woocommerce_order_99', 'payment_ids' => array( 'PAY1' ) );
		$result    = $reconciler->reconcile( $completed, $order );
		self::assertFalse( $result['applied'] );
		self::assertSame( 'pro_owned', $result['reason'] );
		self::assertFalse( $order->paid, 'The old webhook, sweep and status check never complete a payment Pro settles' );
		// Pro captured and completed the order: the old attempt is closed so the sweep's index clears.
		$order->paid = true;
		$GLOBALS['sqtwc_ledger_rows'][99] = array( array( 'id' => 'row-1', 'status' => 'captured' ) );
		$result = $reconciler->reconcile( $completed, $order );
		self::assertSame( 'pro_owned', $result['reason'] );
		self::assertSame( '', $order->meta['_sqtwc_current_attempt_id'] );
		self::assertSame( '', $order->meta['_sqtwc_checkout_id'] );
		self::assertSame( 0, $order->payment_complete_calls, 'The old path never completes the order a second time' );
		self::assertArrayNotHasKey( 'sqtwc_reconcile_99', $GLOBALS['sqtwc_options'], 'Unindexed' );
		$order->paid = false;
		$order->meta['_sqtwc_checkout_id'] = 'TC1';
		$order->meta['_sqtwc_current_attempt_id'] = 'att-1';
		// Pro's leg ended without money (voided): the old paths act as before adoption.
		$GLOBALS['sqtwc_ledger_rows'][99] = array( array( 'id' => 'row-1', 'status' => 'voided' ) );
		$result = $reconciler->reconcile( $completed, $order );
		self::assertTrue( $result['applied'] );
		self::assertTrue( $order->paid );
	}

	public function test_old_cashier_assets_are_not_loaded_under_pros_panel(): void {
		$GLOBALS['sqtwc_is_checkout_pay_page'] = true;
		$GLOBALS['sqtwc_enqueued_scripts']     = array();
		( new Gateway() )->enqueue_payment_assets();
		self::assertSame( array(), $GLOBALS['sqtwc_enqueued_scripts'] );
		unset( $GLOBALS['sqtwc_is_checkout_pay_page'] );
	}
}
