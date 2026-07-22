<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\PosCallbackHandler;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

final class StubPosVerifier {
	public int $calls = 0;
	public array $result = array(
		'payment_ids' => array( 'pay_1' ),
		'amount'      => 1234,
		'currency'    => 'USD',
		'location_id' => 'LOC',
	);
	public function verify( string $transaction_id ): array {
		++$this->calls;
		if ( ! empty( $this->result['throw'] ) ) {
			throw new \RuntimeException( 'Square failed' );
		}
		return $this->result;
	}
}

final class PosCallbackHandlerTest extends TestCase {
	private \SQTWC_Test_Order $order;
	private StubPosVerifier $verifier;

	protected function setUp(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['sqtwc_options'] = array(
			'woocommerce_sqtwc_settings' => array(
				'collection_method' => 'pos_app',
				'environment'       => 'production',
				'location_id'       => 'LOC',
			),
		);
		$GLOBALS['sqtwc_orders']              = array();
		$GLOBALS['sqtwc_order_query_results'] = array();
		$GLOBALS['sqtwc_redirects']           = array();
		$this->order                          = new \SQTWC_Test_Order( 99 );
		$this->order->key                     = 'order-key';
		$GLOBALS['sqtwc_orders'][99]          = $this->order;
		$this->verifier                       = new StubPosVerifier();
		Settings::reset_cache_for_tests();
	}

	private function state(): string {
		return wp_json_encode( array( 'o' => 99, 'k' => 'order-key' ) );
	}

	private function handler(): PosCallbackHandler {
		return new PosCallbackHandler( $this->verifier, new OrderLock() );
	}

	private function handle_redirect( array $params ): string {
		try {
			$this->handler()->handle( $params );
			self::fail( 'Expected redirect.' );
		} catch ( \SQTWC_Redirect $redirect ) {
			return $redirect->getMessage();
		}
	}

	public function test_parses_ios_callback_data(): void {
		$parsed = PosCallbackHandler::parse_request( array( 'data' => wp_json_encode( array( 'transaction_id' => 'sq-order', 'client_transaction_id' => 'client-1', 'status' => 'ok', 'state' => $this->state() ) ) ) );
		self::assertSame( 'sq-order', $parsed['transaction_id'] );
		self::assertSame( 'client-1', $parsed['client_transaction_id'] );
		self::assertSame( $this->state(), $parsed['state'] );
	}

	public function test_parses_android_callback_params(): void {
		$parsed = PosCallbackHandler::parse_request( array(
			'com.squareup.pos.SERVER_TRANSACTION_ID' => 'sq-order',
			'com.squareup.pos.CLIENT_TRANSACTION_ID' => 'client-1',
			'com.squareup.pos.REQUEST_METADATA'       => $this->state(),
		) );
		self::assertSame( 'sq-order', $parsed['transaction_id'] );
		self::assertSame( 'client-1', $parsed['client_transaction_id'] );
	}

	public function test_parses_android_params_after_php_normalizes_dots(): void {
		$parsed = PosCallbackHandler::parse_request( array(
			'com_squareup_pos_SERVER_TRANSACTION_ID' => 'sq-order',
			'com_squareup_pos_REQUEST_METADATA'       => $this->state(),
		) );
		self::assertSame( 'sq-order', $parsed['transaction_id'] );
		self::assertSame( $this->state(), $parsed['state'] );
	}

	public function test_error_code_redirects_without_modifying_order(): void {
		$url = $this->handle_redirect( array( 'data' => wp_json_encode( array( 'status' => 'error', 'error_code' => 'payment_canceled', 'state' => $this->state() ) ) ) );
		self::assertStringContainsString( 'sqtwc_pos_result=error', $url );
		self::assertStringContainsString( 'sqtwc_pos_code=payment_canceled', $url );
		self::assertFalse( $this->order->paid );
		self::assertSame( array(), $this->order->notes );
	}

	public function test_offline_payment_is_recorded_without_completing_order(): void {
		$url = $this->handle_redirect( array( 'data' => wp_json_encode( array( 'status' => 'ok', 'client_transaction_id' => 'offline-1', 'state' => $this->state() ) ) ) );
		self::assertStringContainsString( 'sqtwc_pos_result=offline', $url );
		self::assertSame( 'offline-1', $this->order->get_meta( '_sqtwc_pos_client_transaction_id', true ) );
		self::assertFalse( $this->order->paid );
	}

	public function test_bad_order_key_is_rejected_before_verification(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->handler()->handle( array( 'data' => wp_json_encode( array( 'transaction_id' => 'sq-order', 'state' => wp_json_encode( array( 'o' => 99, 'k' => 'wrong' ) ) ) ) ) );
	}

	public function test_verified_payment_completes_order_and_redirects_to_receipt(): void {
		$url = $this->handle_redirect( array( 'data' => wp_json_encode( array( 'transaction_id' => 'sq-order', 'status' => 'ok', 'state' => $this->state() ) ) ) );
		self::assertSame( '/thank-you', $url );
		self::assertTrue( $this->order->paid );
		self::assertSame( 'pay_1', $this->order->transaction_id );
		self::assertSame( 'sq-order', $this->order->get_meta( '_sqtwc_pos_transaction_id', true ) );
	}

	public function test_under_collection_places_order_on_hold_with_terminal_partial_result(): void {
		$this->verifier->result['amount'] = 1000;
		$url = $this->handle_redirect( array( 'data' => wp_json_encode( array( 'transaction_id' => 'sq-order', 'state' => $this->state() ) ) ) );
		self::assertStringContainsString( 'sqtwc_pos_result=partial', $url );
		self::assertStringNotContainsString( 'verification_failed', $url );
		self::assertSame( 'on-hold', $this->order->status );
		self::assertFalse( $this->order->paid );
	}

	public function test_missing_location_setting_fails_closed(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings']['location_id'] = '';
		Settings::reset_cache_for_tests();
		$url = $this->handle_redirect( array( 'data' => wp_json_encode( array( 'transaction_id' => 'sq-order', 'state' => $this->state() ) ) ) );
		self::assertStringContainsString( 'verification_failed', $url );
		self::assertFalse( $this->order->paid );
	}

	public function test_transaction_claim_is_atomic_across_orders(): void {
		$GLOBALS['sqtwc_options'][ 'sqtwc_pos_txn_' . md5( 'sq-order' ) ] = '42';
		$url = $this->handle_redirect( array( 'data' => wp_json_encode( array( 'transaction_id' => 'sq-order', 'state' => $this->state() ) ) ) );
		self::assertStringContainsString( 'verification_failed', $url );
		self::assertFalse( $this->order->paid );
	}

	public function test_verified_payment_records_transaction_claim(): void {
		$this->handle_redirect( array( 'data' => wp_json_encode( array( 'transaction_id' => 'sq-order', 'status' => 'ok', 'state' => $this->state() ) ) ) );
		self::assertSame( '99', $GLOBALS['sqtwc_options'][ 'sqtwc_pos_txn_' . md5( 'sq-order' ) ] );
	}

	public function test_verification_tolerates_collection_method_drift(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings']['collection_method'] = 'terminal';
		Settings::reset_cache_for_tests();
		$url = $this->handle_redirect( array( 'data' => wp_json_encode( array( 'transaction_id' => 'sq-order', 'status' => 'ok', 'state' => $this->state() ) ) ) );
		self::assertSame( '/thank-you', $url );
		self::assertTrue( $this->order->paid );
	}

	public function test_duplicate_transaction_id_is_rejected(): void {
		$GLOBALS['sqtwc_order_query_results'] = array( 42 );
		$url = $this->handle_redirect( array( 'data' => wp_json_encode( array( 'transaction_id' => 'sq-order', 'state' => $this->state() ) ) ) );
		self::assertStringContainsString( 'verification_failed', $url );
		self::assertFalse( $this->order->paid );
	}

	public function test_already_paid_order_redirects_to_receipt_without_recompletion(): void {
		$this->order->paid = true;
		$url = $this->handle_redirect( array( 'data' => wp_json_encode( array( 'transaction_id' => 'attacker-value', 'state' => $this->state() ) ) ) );
		self::assertSame( '/thank-you', $url );
		self::assertSame( 0, $this->order->payment_complete_calls );
		self::assertSame( 0, $this->verifier->calls );
	}
}
