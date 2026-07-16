<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareTerminalAdapter;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Payments\Requests\GetPaymentsRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Terminal\Checkouts\Requests\CreateTerminalCheckoutRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\CreateTerminalCheckoutResponse;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\GetPaymentResponse;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Money;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Payment;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\TerminalCheckout;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\DeviceCheckoutOptions;

final class SpyCheckoutsClient {
	public ?CreateTerminalCheckoutRequest $created_request = null;

	public function create( CreateTerminalCheckoutRequest $request ): CreateTerminalCheckoutResponse {
		$this->created_request = $request;

		return new CreateTerminalCheckoutResponse(
			array(
				'checkout' => new TerminalCheckout(
					array(
						'id'            => 'chk_123',
						'amountMoney'   => new Money( array( 'amount' => 1234, 'currency' => 'USD' ) ),
						'deviceOptions' => new DeviceCheckoutOptions( array( 'deviceId' => 'DEVICE123' ) ),
						'referenceId'   => 'woocommerce_order_99',
						'status'        => 'PENDING',
						'paymentIds'    => array( 'pay_1' ),
						'updatedAt'     => '2026-07-16T10:00:00Z',
					)
				),
			)
		);
	}
}

final class SpyPaymentsClient {
	public ?GetPaymentsRequest $get_request = null;

	public function get( GetPaymentsRequest $request ): GetPaymentResponse {
		$this->get_request = $request;

		return new GetPaymentResponse(
			array(
				'payment' => new Payment(
					array(
						'id'         => 'pay_1',
						'status'     => 'COMPLETED',
						'totalMoney' => new Money( array( 'amount' => 1480, 'currency' => 'USD' ) ),
						'tipMoney'   => new Money( array( 'amount' => 246, 'currency' => 'USD' ) ),
					)
				),
			)
		);
	}
}

final class SquareTerminalAdapterTest extends TestCase {
	public function test_create_checkout_sets_reliability_and_device_options_and_normalizes_response(): void {
		$checkouts = new SpyCheckoutsClient();
		$client    = (object) array(
			'terminal' => (object) array( 'checkouts' => $checkouts ),
			'payments' => new SpyPaymentsClient(),
		);
		$result    = ( new SquareTerminalAdapter( $client ) )->create_checkout(
			array(
				'amount'              => 1234,
				'currency'            => 'USD',
				'device_id'           => 'DEVICE123',
				'reference_id'        => 'woocommerce_order_99',
				'idempotency_key'     => 'idem',
				'note'                => 'Test Store — Order #99',
				'deadline_duration'   => 'PT5M',
				'skip_receipt_screen' => true,
				'collect_signature'   => false,
			)
		);

		self::assertInstanceOf( CreateTerminalCheckoutRequest::class, $checkouts->created_request );
		$checkout = $checkouts->created_request->getCheckout();
		self::assertSame( 1234, $checkout->getAmountMoney()->getAmount() );
		self::assertSame( 'USD', $checkout->getAmountMoney()->getCurrency() );
		self::assertSame( 'DEVICE123', $checkout->getDeviceOptions()->getDeviceId() );
		self::assertTrue( $checkout->getDeviceOptions()->getSkipReceiptScreen() );
		self::assertFalse( $checkout->getDeviceOptions()->getCollectSignature() );
		self::assertTrue( $checkout->getPaymentOptions()->getAutocomplete() );
		self::assertSame( 'PT5M', $checkout->getDeadlineDuration() );
		self::assertSame( 'Test Store — Order #99', $checkout->getNote() );
		self::assertSame(
			array(
				'id'            => 'chk_123',
				'status'        => 'PENDING',
				'reference_id'  => 'woocommerce_order_99',
				'payment_ids'   => array( 'pay_1' ),
				'updated_at'    => '2026-07-16T10:00:00Z',
				'created_at'    => null,
				'cancel_reason' => null,
			),
			$result
		);
	}

	public function test_get_payment_uses_typed_request_and_normalizes_money(): void {
		$payments = new SpyPaymentsClient();
		$client   = (object) array(
			'terminal' => (object) array( 'checkouts' => new SpyCheckoutsClient() ),
			'payments' => $payments,
		);

		$result = ( new SquareTerminalAdapter( $client ) )->get_payment( 'pay_1' );

		self::assertSame( 'pay_1', $payments->get_request->getPaymentId() );
		self::assertSame(
			array(
				'id'             => 'pay_1',
				'status'         => 'COMPLETED',
				'total_amount'   => 1480,
				'total_currency' => 'USD',
				'tip_amount'     => 246,
				'tip_currency'   => 'USD',
				'card_status'    => null,
			),
			$result
		);
	}
}
