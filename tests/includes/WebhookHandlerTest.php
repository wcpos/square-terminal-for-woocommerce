<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;
use WCPOS\WooCommercePOS\SquareTerminal\WebhookHandler;

final class FakeVerifier {
	public array $args = array();
	public bool $valid = false;

	public function verify( string $body, string $signature, string $key, string $url ): bool {
		$this->args = func_get_args();

		return $this->valid;
	}
}

final class RecordingReconciler {
	public int $calls = 0;
	public array $checkout = array();
	public bool $throw = false;
	public array $result = array(
		'applied'          => true,
		'status'           => 'IN_PROGRESS',
		'cashier_message'  => 'in progress',
		'continue_polling' => true,
	);

	public function reconcile( array $checkout, $order ): array {
		++$this->calls;
		$this->checkout = $checkout;
		if ( $this->throw ) {
			throw new \RuntimeException( 'transient provider detail' );
		}

		return $this->result;
	}
}

final class WebhookHandlerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_orders'] = array();
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'webhook_notification_url' => 'https://dev-pro.wcpos.com/wp-json/sqtwc/v1/webhook',
			'webhook_signature_key'    => 'secret',
		);
		Settings::reset_cache_for_tests();
	}

	public function test_invalid_signature_rejects_before_order_lookup_and_uses_explicit_url(): void {
		$verifier = new FakeVerifier();
		$result   = ( new WebhookHandler( $verifier, new RecordingReconciler() ) )->handle( '{}', array( 'X-Square-HmacSha256-Signature' => 'bad' ) );

		self::assertSame( 401, $result['status'] );
		self::assertSame( 'bad', $verifier->args[1] );
		self::assertSame( 'https://dev-pro.wcpos.com/wp-json/sqtwc/v1/webhook', $verifier->args[3] );
	}

	public function test_terminal_checkout_update_routes_all_statuses_through_reconciler_and_deduplicates(): void {
		$order                       = new \SQTWC_Test_Order( 99 );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$verifier                    = new FakeVerifier();
		$verifier->valid             = true;
		$reconciler                  = new RecordingReconciler();
		$checkout                    = array(
			'id'           => 'chk_1',
			'status'       => 'CANCEL_REQUESTED',
			'reference_id' => 'woocommerce_order_99',
			'payment_ids'  => array(),
			'updated_at'   => '2026-07-16T10:00:00Z',
		);
		$body                        = json_encode(
			array(
				'event_id' => 'evt_1',
				'type'     => 'terminal.checkout.updated',
				'data'     => array( 'object' => array( 'checkout' => $checkout ) ),
			)
		);
		$handler                     = new WebhookHandler( $verifier, $reconciler );

		$result = $handler->handle( $body, array( 'x-square-hmacsha256-signature' => 'sig' ) );
		self::assertSame( 200, $result['status'] );
		self::assertSame( $checkout, $reconciler->checkout );
		self::assertSame( array( 'evt_1' ), $order->get_meta( '_sqtwc_processed_event_ids' ) );

		$result = $handler->handle( $body, array( 'x-square-hmacsha256-signature' => 'sig' ) );
		self::assertSame( 'duplicate', $result['result'] );
		self::assertSame( 1, $reconciler->calls );
	}

	public function test_processed_event_ids_are_capped_at_fifty(): void {
		$order     = new \SQTWC_Test_Order( 99 );
		$processed = array();
		for ( $index = 0; $index < 50; ++$index ) {
			$processed[] = 'old_' . $index;
		}
		$order->update_meta_data( '_sqtwc_processed_event_ids', $processed );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$verifier                    = new FakeVerifier();
		$verifier->valid             = true;

		$result = ( new WebhookHandler( $verifier, new RecordingReconciler() ) )->handle( $this->body( 'evt_new' ), array( 'x-square-hmacsha256-signature' => 'sig' ) );

		self::assertSame( 200, $result['status'] );
		self::assertCount( 50, $order->get_meta( '_sqtwc_processed_event_ids' ) );
		self::assertSame( 'old_1', $order->get_meta( '_sqtwc_processed_event_ids' )[0] );
		self::assertSame( 'evt_new', $order->get_meta( '_sqtwc_processed_event_ids' )[49] );
	}

	public function test_transient_reconciliation_failure_returns_500_and_does_not_deduplicate_retry(): void {
		$order                       = new \SQTWC_Test_Order( 99 );
		$GLOBALS['sqtwc_orders'][99] = $order;
		$verifier                    = new FakeVerifier();
		$verifier->valid             = true;
		$reconciler                  = new RecordingReconciler();
		$reconciler->throw           = true;

		$result = ( new WebhookHandler( $verifier, $reconciler ) )->handle( $this->body( 'evt_retry' ), array( 'x-square-hmacsha256-signature' => 'sig' ) );

		self::assertSame( 500, $result['status'] );
		self::assertEmpty( $order->get_meta( '_sqtwc_processed_event_ids' ) );
		self::assertStringNotContainsString( 'provider detail', json_encode( $result ) );
	}

	public function test_wrong_reference_id_is_malformed_and_unknown_event_is_ignored(): void {
		$verifier        = new FakeVerifier();
		$verifier->valid = true;
		$handler         = new WebhookHandler( $verifier, new RecordingReconciler() );
		$bad             = json_encode(
			array(
				'event_id' => 'evt_bad',
				'type'     => 'terminal.checkout.updated',
				'data'     => array( 'object' => array( 'checkout' => array( 'reference_id' => 'bad' ) ) ),
			)
		);

		self::assertSame( 400, $handler->handle( $bad, array( 'x-square-hmacsha256-signature' => 'sig' ) )['status'] );
		self::assertSame( 202, $handler->handle( json_encode( array( 'type' => 'other.event' ) ), array( 'x-square-hmacsha256-signature' => 'sig' ) )['status'] );
	}

	private function body( string $event_id ): string {
		return json_encode(
			array(
				'event_id' => $event_id,
				'type'     => 'terminal.checkout.updated',
				'data'     => array(
					'object' => array(
						'checkout' => array(
							'id'           => 'chk_1',
							'status'       => 'IN_PROGRESS',
							'reference_id' => 'woocommerce_order_99',
						),
					),
				),
			)
		);
	}
}
