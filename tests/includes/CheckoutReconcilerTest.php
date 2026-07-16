<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Services\CheckoutReconciler;

final class ReconcilerAdapter {
	public int $payment_calls = 0;
	public array $payments = array();
	public array $last_payment_options = array();

	public function get_payment( string $payment_id, array $options = array() ): array {
		++$this->payment_calls;
		$this->last_payment_options = $options;

		return $this->payments[ $payment_id ] ?? array(
			'id'             => $payment_id,
			'status'         => 'COMPLETED',
			'total_amount'   => 1480,
			'total_currency' => 'USD',
			'tip_amount'     => 246,
			'tip_currency'   => 'USD',
			'card_status'    => null,
		);
	}
}

final class CheckoutReconcilerTest extends TestCase {
	/**
	 * @return array<string,array{string,bool,bool,bool}>
	 */
	public static function transition_matrix(): array {
		$matrix = array();
		foreach ( array( 'PENDING', 'IN_PROGRESS', 'CANCEL_REQUESTED', 'CANCELED', 'COMPLETED' ) as $status ) {
			foreach ( array( false, true ) as $already_paid ) {
				foreach ( array( false, true ) as $stale ) {
					foreach ( array( false, true ) as $wrong_attempt ) {
						$key            = implode( '-', array( $status, $already_paid ? 'paid' : 'unpaid', $stale ? 'stale' : 'fresh', $wrong_attempt ? 'wrong' : 'current' ) );
						$matrix[ $key ] = array( $status, $already_paid, $stale, $wrong_attempt );
					}
				}
			}
		}

		return $matrix;
	}

	#[DataProvider( 'transition_matrix' )]
	public function test_transition_matrix( string $status, bool $already_paid, bool $stale, bool $wrong_attempt ): void {
		$order       = $this->open_order();
		$order->paid = $already_paid;
		$order->update_meta_data( '_sqtwc_checkout_updated_at', '2026-07-16T10:00:02Z' );
		$adapter    = new ReconcilerAdapter();
		$reconciler = new CheckoutReconciler( $adapter );
		$checkout   = array(
			'id'            => $wrong_attempt ? 'chk_other' : 'chk_current',
			'status'        => $status,
			'reference_id'  => 'woocommerce_order_99',
			'payment_ids'   => array( 'pay_1' ),
			'updated_at'    => $stale ? '2026-07-16T10:00:01Z' : '2026-07-16T10:00:03Z',
			'created_at'    => '2026-07-16T09:59:00Z',
			'cancel_reason' => 'SELLER_CANCELED',
		);

		$result = $reconciler->reconcile( $checkout, $order );

		if ( $stale || $wrong_attempt ) {
			self::assertFalse( $result['applied'] );
			self::assertSame( $already_paid, $order->paid );
			self::assertSame( 0, $adapter->payment_calls );
			self::assertSame( 'PENDING', $order->get_meta( '_sqtwc_checkout_status' ) );
			return;
		}

		self::assertTrue( $result['applied'] );
		self::assertSame( $status, $result['status'] );
		self::assertSame( $status, $order->get_meta( '_sqtwc_checkout_status' ) );
		if ( 'COMPLETED' === $status ) {
			self::assertTrue( $order->paid );
			self::assertSame( $already_paid ? 0 : 1, $order->payment_complete_calls );
			self::assertSame( 1480, $order->get_meta( '_sqtwc_collected_amount' ) );
			self::assertSame( 246, $order->get_meta( '_sqtwc_tip_amount' ) );
			self::assertSame( array( 'pay_1' ), $order->get_meta( '_sqtwc_payment_ids' ) );
		} elseif ( in_array( $status, array( 'PENDING', 'IN_PROGRESS', 'CANCEL_REQUESTED' ), true ) ) {
			self::assertSame( 'attempt_current', $order->get_meta( '_sqtwc_current_attempt_id' ) );
		} else {
			self::assertSame( '', $order->get_meta( '_sqtwc_current_attempt_id' ) );
			self::assertSame( 'SELLER_CANCELED', $order->get_meta( '_sqtwc_attempt_history' )[0]['cancel_reason'] );
		}
	}

	public function test_timed_out_checkout_with_captured_payment_completes_instead_of_closing(): void {
		$order   = $this->open_order();
		$adapter = new ReconcilerAdapter();

		$result = ( new CheckoutReconciler( $adapter ) )->reconcile(
			array(
				'id'            => 'chk_current',
				'status'        => 'CANCELED',
				'reference_id'  => 'woocommerce_order_99',
				'payment_ids'   => array( 'pay_boundary' ),
				'updated_at'    => '2026-07-16T10:00:03Z',
				'cancel_reason' => 'TIMED_OUT',
			),
			$order
		);

		self::assertSame( 'COMPLETED', $result['status'] );
		self::assertTrue( $order->paid );
		self::assertSame( array( 'pay_boundary' ), $order->get_meta( '_sqtwc_payment_ids' ) );
	}

	public function test_underpayment_places_order_on_hold_without_completing_payment(): void {
		$order        = $this->open_order();
		$order->total = '20.00';
		$adapter      = new ReconcilerAdapter();
		$adapter->payments['pay_under'] = array(
			'id'             => 'pay_under',
			'status'         => 'COMPLETED',
			'total_amount'   => 1950,
			'total_currency' => 'USD',
			'tip_amount'     => 0,
			'tip_currency'   => null,
			'card_status'    => null,
		);

		( new CheckoutReconciler( $adapter ) )->reconcile(
			array(
				'id'           => 'chk_current',
				'status'       => 'COMPLETED',
				'reference_id' => 'woocommerce_order_99',
				'payment_ids'  => array( 'pay_under' ),
				'updated_at'   => '2026-07-16T10:00:03Z',
			),
			$order
		);

		self::assertFalse( $order->paid );
		self::assertSame( 0, $order->payment_complete_calls );
		self::assertSame( 'on-hold', $order->status );
		self::assertStringContainsString( 'less than', $order->notes[0] );
	}

	public function test_abandoned_checkout_can_complete_after_current_attempt_changes(): void {
		$order = $this->open_order();
		$order->update_meta_data( '_sqtwc_abandoned_checkout_ids', array( 'chk_abandoned' ) );
		$adapter = new ReconcilerAdapter();

		$result = ( new CheckoutReconciler( $adapter ) )->reconcile(
			array(
				'id'           => 'chk_abandoned',
				'status'       => 'COMPLETED',
				'reference_id' => 'woocommerce_order_99',
				'payment_ids'  => array( 'pay_late' ),
				'updated_at'   => '2026-07-16T10:00:03Z',
			),
			$order
		);

		self::assertTrue( $result['applied'] );
		self::assertTrue( $order->paid );
		self::assertSame( array(), $order->get_meta( '_sqtwc_abandoned_checkout_ids' ) );
		self::assertSame( 'attempt_current', $order->get_meta( '_sqtwc_current_attempt_id' ) );
	}

	public function test_payment_log_is_structured_and_capped_at_one_hundred(): void {
		$order = $this->open_order();
		$log   = array();
		for ( $index = 0; $index < 100; ++$index ) {
			$log[] = array( 't' => $index, 'level' => 'info', 'msg' => 'old-' . $index );
		}
		$order->update_meta_data( '_sqtwc_payment_log', $log );

		( new CheckoutReconciler( new ReconcilerAdapter() ) )->reconcile(
			array(
				'id'           => 'chk_current',
				'status'       => 'IN_PROGRESS',
				'reference_id' => 'woocommerce_order_99',
				'payment_ids'  => array(),
				'updated_at'   => '2026-07-16T10:00:03Z',
			),
			$order
		);

		$stored = $order->get_meta( '_sqtwc_payment_log' );
		self::assertCount( 100, $stored );
		self::assertSame( 'old-1', $stored[0]['msg'] );
		self::assertSame( 'info', $stored[99]['level'] );
		self::assertArrayHasKey( 't', $stored[99] );
	}

	public function test_payment_fetch_receives_request_options_and_attempt_closes_before_payment_complete(): void {
		$order = new class( 99 ) extends \SQTWC_Test_Order {
			public string $attempt_during_completion = 'not-called';

			public function payment_complete( $id = '' ) {
				$this->attempt_during_completion = (string) $this->get_meta( '_sqtwc_current_attempt_id', true );
				parent::payment_complete( $id );
			}
		};
		$order->update_meta_data( '_sqtwc_current_attempt_id', 'attempt_current' );
		$order->update_meta_data( '_sqtwc_checkout_idempotency_key', 'idem_current' );
		$order->update_meta_data( '_sqtwc_checkout_id', 'chk_current' );
		$order->update_meta_data( '_sqtwc_checkout_status', 'PENDING' );
		$order->update_meta_data( '_sqtwc_device_id', 'device_current' );
		$order->update_meta_data( '_sqtwc_attempt_started', time() - 30 );
		$adapter = new ReconcilerAdapter();

		( new CheckoutReconciler( $adapter ) )->reconcile(
			array(
				'id'           => 'chk_current',
				'status'       => 'COMPLETED',
				'reference_id' => 'woocommerce_order_99',
				'payment_ids'  => array( 'pay_1' ),
				'updated_at'   => '2026-07-16T10:00:03Z',
			),
			$order,
			array( 'timeout' => 8.0 )
		);

		self::assertSame( array( 'timeout' => 8.0 ), $adapter->last_payment_options );
		self::assertSame( '', $order->attempt_during_completion );
	}

	private function open_order(): \SQTWC_Test_Order {
		$order = new \SQTWC_Test_Order( 99 );
		$order->update_meta_data( '_sqtwc_current_attempt_id', 'attempt_current' );
		$order->update_meta_data( '_sqtwc_checkout_idempotency_key', 'idem_current' );
		$order->update_meta_data( '_sqtwc_checkout_id', 'chk_current' );
		$order->update_meta_data( '_sqtwc_checkout_status', 'PENDING' );
		$order->update_meta_data( '_sqtwc_device_id', 'device_current' );
		$order->update_meta_data( '_sqtwc_attempt_started', 1784195940 );

		return $order;
	}
}
