<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareTerminalAdapter;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Payments\Requests\GetPaymentsRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Terminal\Checkouts\Requests\CreateTerminalCheckoutRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Terminal\Checkouts\Requests\SearchTerminalCheckoutsRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Error;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\SearchTerminalCheckoutsResponse;
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

final class SpySearchCheckoutsClient {
	/** @var array<int,SearchTerminalCheckoutsRequest> */
	public array $requests = array();

	/** @var array<int,string|null> Cursor each request carried when it was sent. */
	public array $cursors = array();

	/** @var array<int,SearchTerminalCheckoutsResponse> */
	public array $responses = array();

	public function search( SearchTerminalCheckoutsRequest $request ): SearchTerminalCheckoutsResponse {
		$this->requests[] = $request;
		$this->cursors[]  = $request->getCursor();

		return array_shift( $this->responses ) ?? new SearchTerminalCheckoutsResponse();
	}

	public static function checkout( string $id, ?Money $money = null ): TerminalCheckout {
		return new TerminalCheckout(
			array(
				'id'            => $id,
				'amountMoney'   => $money ?? new Money( array( 'amount' => 1234, 'currency' => 'USD' ) ),
				'deviceOptions' => new DeviceCheckoutOptions( array( 'deviceId' => 'DEVICE123' ) ),
				'referenceId'   => 'woocommerce_order_99',
				'status'        => 'IN_PROGRESS',
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
				'device_id'     => 'DEVICE123',
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
				'card_brand'     => null,
				'card_last4'     => null,
				'entry_method'   => null,
				'auth_code'      => null,
				'error_code'     => null,
				'receipt_number' => null,
				'reference_id'   => null,
			),
			$result
		);
	}

	private function search_adapter( SpySearchCheckoutsClient $checkouts ): SquareTerminalAdapter {
		return new SquareTerminalAdapter( (object) array( 'terminal' => (object) array( 'checkouts' => $checkouts ) ) );
	}

	public function test_search_request_carries_device_start_no_end_and_limit_100(): void {
		$checkouts = new SpySearchCheckoutsClient();

		$this->search_adapter( $checkouts )->search_checkouts( 'DEVICE123', '2026-11-06T10:00:00Z' );

		self::assertCount( 1, $checkouts->requests );
		$request = $checkouts->requests[0];
		$filter  = $request->getQuery()->getFilter();
		self::assertSame( 'DEVICE123', $filter->getDeviceId() );
		self::assertSame( '2026-11-06T10:00:00Z', $filter->getCreatedAt()->getStartAt() );
		self::assertNull( $filter->getCreatedAt()->getEndAt() );
		self::assertNull( $filter->getStatus() );
		self::assertSame( 100, $request->getLimit() );
		self::assertNull( $request->getCursor() );
		$body = json_decode( $request->toJson(), true );
		self::assertSame( array( 'start_at' => '2026-11-06T10:00:00Z' ), $body['query']['filter']['created_at'] );
		self::assertSame( 'DEVICE123', $body['query']['filter']['device_id'] );
		self::assertSame( 100, $body['limit'] );
	}

	public function test_search_joins_pages_and_passes_the_cursor_on(): void {
		$checkouts            = new SpySearchCheckoutsClient();
		$checkouts->responses = array(
			new SearchTerminalCheckoutsResponse(
				array(
					'checkouts' => array( SpySearchCheckoutsClient::checkout( 'chk_1' ) ),
					'cursor'    => 'page_2',
				)
			),
			new SearchTerminalCheckoutsResponse( array( 'checkouts' => array( SpySearchCheckoutsClient::checkout( 'chk_2' ) ) ) ),
		);

		$rows = $this->search_adapter( $checkouts )->search_checkouts( 'DEVICE123', '2026-11-06T10:00:00Z' );

		self::assertSame( array( 'chk_1', 'chk_2' ), array_column( $rows, 'id' ) );
		self::assertSame( array( null, 'page_2' ), $checkouts->cursors );
		self::assertSame( 'DEVICE123', $checkouts->requests[1]->getQuery()->getFilter()->getDeviceId() );
		self::assertSame( '2026-11-06T10:00:00Z', $checkouts->requests[1]->getQuery()->getFilter()->getCreatedAt()->getStartAt() );
	}

	public function test_search_throws_when_a_cursor_is_left_after_the_fifth_page(): void {
		$checkouts = new SpySearchCheckoutsClient();
		for ( $page = 1; $page <= 6; ++$page ) {
			$checkouts->responses[] = new SearchTerminalCheckoutsResponse(
				array(
					'checkouts' => array( SpySearchCheckoutsClient::checkout( 'chk_' . $page ) ),
					'cursor'    => 'after_' . $page,
				)
			);
		}

		try {
			$this->search_adapter( $checkouts )->search_checkouts( 'DEVICE123', '2026-11-06T10:00:00Z' );
			self::fail( 'A cursor left after the fifth page must throw.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Square has more checkouts than one search reads.', $exception->getMessage() );
		}
		self::assertSame( array( null, 'after_1', 'after_2', 'after_3', 'after_4' ), $checkouts->cursors );
	}

	public function test_search_throws_when_square_answers_with_errors(): void {
		$checkouts            = new SpySearchCheckoutsClient();
		$checkouts->responses = array(
			new SearchTerminalCheckoutsResponse(
				array(
					'errors'    => array(
						new Error(
							array(
								'category' => 'API_ERROR',
								'code'     => 'INTERNAL_SERVER_ERROR',
							)
						),
					),
					'checkouts' => array( SpySearchCheckoutsClient::checkout( 'chk_1' ) ),
				)
			),
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Square answered the checkout search with errors.' );

		$this->search_adapter( $checkouts )->search_checkouts( 'DEVICE123', '2026-11-06T10:00:00Z' );
	}

	public function test_search_reads_null_checkouts_as_an_empty_list(): void {
		$checkouts            = new SpySearchCheckoutsClient();
		$checkouts->responses = array( new SearchTerminalCheckoutsResponse( array() ) );

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): bool {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$rows = $this->search_adapter( $checkouts )->search_checkouts( 'DEVICE123', '2026-11-06T10:00:00Z' );
		} finally {
			restore_error_handler();
		}

		self::assertSame( array(), $rows );
		self::assertCount( 1, $checkouts->requests );
	}

	public function test_search_ends_after_one_page_on_an_empty_cursor(): void {
		$checkouts            = new SpySearchCheckoutsClient();
		$checkouts->responses = array(
			new SearchTerminalCheckoutsResponse(
				array(
					'checkouts' => array( SpySearchCheckoutsClient::checkout( 'chk_1' ) ),
					'cursor'    => '',
				)
			),
		);

		$rows = $this->search_adapter( $checkouts )->search_checkouts( 'DEVICE123', '2026-11-06T10:00:00Z' );

		self::assertSame( array( 'chk_1' ), array_column( $rows, 'id' ) );
		self::assertCount( 1, $checkouts->requests );
	}

	public function test_search_rows_carry_amount_and_currency(): void {
		$checkouts            = new SpySearchCheckoutsClient();
		$checkouts->responses = array(
			new SearchTerminalCheckoutsResponse(
				array(
					'checkouts' => array( SpySearchCheckoutsClient::checkout( 'chk_1', new Money( array( 'amount' => 1987, 'currency' => 'AUD' ) ) ) ),
				)
			),
		);

		$rows = $this->search_adapter( $checkouts )->search_checkouts( 'DEVICE123', '2026-11-06T10:00:00Z' );

		self::assertSame(
			array(
				'id'            => 'chk_1',
				'status'        => 'IN_PROGRESS',
				'reference_id'  => 'woocommerce_order_99',
				'payment_ids'   => array(),
				'updated_at'    => null,
				'created_at'    => null,
				'cancel_reason' => null,
				'device_id'     => 'DEVICE123',
				'amount'        => 1987,
				'currency'      => 'AUD',
			),
			$rows[0]
		);
		self::assertCount( 1, $rows );
	}
}
