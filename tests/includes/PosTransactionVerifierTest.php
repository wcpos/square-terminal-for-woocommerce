<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WCPOS\WooCommercePOS\SquareTerminal\Services\PosTransactionVerifier;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Orders\Requests\GetOrdersRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Payments\Requests\GetPaymentsRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\GetOrderResponse;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\GetPaymentResponse;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Money;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Order;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Payment;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Tender;

final class PosOrdersClient {
	public ?GetOrdersRequest $request = null;
	public array $tenders = array();
	public function get( GetOrdersRequest $request ): GetOrderResponse {
		$this->request = $request;
		return new GetOrderResponse( array( 'order' => new Order( array( 'id' => 'order_1', 'locationId' => 'LOC', 'tenders' => $this->tenders ) ) ) );
	}
}

final class PosPaymentsClient {
	/** @var array<string,array{status:string,amount:int,currency:string}> */
	public array $payments = array();
	public array $requests = array();
	public function get( GetPaymentsRequest $request ): GetPaymentResponse {
		$this->requests[] = $request;
		$data = $this->payments[ $request->getPaymentId() ];
		return new GetPaymentResponse( array( 'payment' => new Payment( array( 'id' => $request->getPaymentId(), 'status' => $data['status'], 'totalMoney' => new Money( array( 'amount' => $data['amount'], 'currency' => $data['currency'] ) ) ) ) ) );
	}
}

final class PosTransactionVerifierTest extends TestCase {
	public function test_card_tender_completed_payment_verifies(): void {
		$orders = new PosOrdersClient();
		$orders->tenders = array( new Tender( array( 'type' => 'CARD', 'paymentId' => 'pay_1' ) ) );
		$payments = new PosPaymentsClient();
		$payments->payments['pay_1'] = array( 'status' => 'COMPLETED', 'amount' => 1234, 'currency' => 'USD' );
		$result = ( new PosTransactionVerifier( (object) array( 'orders' => $orders, 'payments' => $payments ) ) )->verify( 'order_1' );
		self::assertSame( 'order_1', $orders->request->getOrderId() );
		self::assertSame( array( 'pay_1' ), $result['payment_ids'] );
		self::assertSame( 1234, $result['amount'] );
		self::assertSame( 'USD', $result['currency'] );
		self::assertSame( 'LOC', $result['location_id'] );
	}

	public function test_cash_only_order_fails_verification(): void {
		$orders = new PosOrdersClient();
		$orders->tenders = array( new Tender( array( 'type' => 'CASH' ) ) );
		$this->expectException( RuntimeException::class );
		( new PosTransactionVerifier( (object) array( 'orders' => $orders, 'payments' => new PosPaymentsClient() ) ) )->verify( 'order_1' );
	}

	public function test_non_card_tenders_are_ignored_even_with_payment_ids(): void {
		$orders = new PosOrdersClient();
		$orders->tenders = array( new Tender( array( 'type' => 'CASH', 'paymentId' => 'pay_cash' ) ), new Tender( array( 'type' => 'CARD', 'paymentId' => 'pay_1' ) ) );
		$payments = new PosPaymentsClient();
		$payments->payments['pay_1'] = array( 'status' => 'COMPLETED', 'amount' => 1234, 'currency' => 'USD' );
		$result = ( new PosTransactionVerifier( (object) array( 'orders' => $orders, 'payments' => $payments ) ) )->verify( 'order_1' );
		self::assertSame( array( 'pay_1' ), $result['payment_ids'] );
		self::assertSame( 1234, $result['amount'] );
		self::assertCount( 1, $payments->requests );
	}

	public function test_mixed_payment_currencies_fail_verification(): void {
		$orders = new PosOrdersClient();
		$orders->tenders = array( new Tender( array( 'type' => 'CARD', 'paymentId' => 'pay_1' ) ), new Tender( array( 'type' => 'CARD', 'paymentId' => 'pay_2' ) ) );
		$payments = new PosPaymentsClient();
		$payments->payments = array( 'pay_1' => array( 'status' => 'COMPLETED', 'amount' => 1000, 'currency' => 'USD' ), 'pay_2' => array( 'status' => 'COMPLETED', 'amount' => 1000, 'currency' => 'EUR' ) );
		$this->expectException( RuntimeException::class );
		( new PosTransactionVerifier( (object) array( 'orders' => $orders, 'payments' => $payments ) ) )->verify( 'order_1' );
	}
}
