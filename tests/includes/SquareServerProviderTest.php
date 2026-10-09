<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WCPOS\WooCommercePOS\SquareTerminal\Server\Refund_Reask;
use WCPOS\WooCommercePOS\SquareTerminal\Server\Square_Server_Provider;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

/** Queued Square answers; each request pops one. */
final class QueueTransport implements ClientInterface {
	/** @var array<int,ResponseInterface|\Throwable> */
	public array $queue = array();
	/** @var RequestInterface[] */
	public array $requests = array();

	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$this->requests[] = $request;
		if ( ! $this->queue ) {
			throw new \LogicException( 'Unexpected Square request: ' . $request->getMethod() . ' ' . $request->getUri() );
		}
		$next = array_shift( $this->queue );
		if ( $next instanceof \Throwable ) {
			throw $next;
		}
		return $next;
	}

	public function body( int $i ): array {
		return json_decode( (string) $this->requests[ $i ]->getBody(), true ) ?: array();
	}
}

final class SquareServerProviderTest extends TestCase {
	private QueueTransport $square;
	private const ROW = 'a3f1c2d4-1111-4222-8333-444455556666';

	protected function setUp(): void {
		$GLOBALS['sqtwc_options'] = array(
			'woocommerce_sqtwc_settings' => array(
				'environment'             => 'sandbox',
				'sandbox_access_token'    => 'sb-token',
				'production_access_token' => 'prod-token',
				'location_id'             => 'LOC1',
				'webhook_signature_key'   => 'whsec',
			),
		);
		$GLOBALS['sqtwc_orders'] = array( 99 => new \SQTWC_Test_Order( 99 ) );
		$GLOBALS['sqtwc_single_events'] = array();
		$GLOBALS['sqtwc_adopted'] = array();
		$GLOBALS['sqtwc_adoption_lookups'] = array();
		$GLOBALS['sqtwc_logs'] = array();
		Settings::reset_cache_for_tests();
		$this->square = new QueueTransport();
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_square_http_client'] = $this->square;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sqtwc_filter_overrides']['sqtwc_square_http_client'], $GLOBALS['sqtwc_orders'], $GLOBALS['sqtwc_adopted'] );
		Settings::reset_cache_for_tests();
	}

	private function adapter(): Square_Server_Provider {
		return new Square_Server_Provider();
	}

	private function row( array $refs = array( 'action' => 'sandbox:TC1', 'transaction_id' => 'PAY1' ) ): array {
		return array( 'id' => self::ROW, 'order_id' => 99, 'amount' => '92.95', 'currency' => 'EUR', 'provider_refs' => $refs );
	}

	private static function json( int $status, array $body ): Response {
		return new Response( $status, array( 'Content-Type' => 'application/json' ), json_encode( $body ) );
	}

	private static function error( int $status, string $code ): Response {
		return self::json( $status, array( 'errors' => array( array( 'category' => 'INVALID_REQUEST_ERROR', 'code' => $code, 'detail' => $code ) ) ) );
	}

	private static function lost(): ConnectException {
		return new ConnectException( 'lost', new \GuzzleHttp\Psr7\Request( 'POST', 'https://connect.squareupsandbox.com/v2/terminals/checkouts' ) );
	}

	private static function checkout( string $status, array $payment_ids = array(), ?string $reason = null, string $reference = self::ROW ): Response {
		$checkout = array(
			'id'             => 'TC1',
			'amount_money'   => array( 'amount' => 9295, 'currency' => 'EUR' ),
			'reference_id'   => $reference,
			'device_options' => array( 'device_id' => 'DEV1' ),
			'status'         => $status,
			'payment_ids'    => $payment_ids,
			'created_at'     => '2026-10-09T10:00:00Z',
		);
		if ( null !== $reason ) {
			$checkout['cancel_reason'] = $reason;
		}
		return self::json( 200, array( 'checkout' => $checkout ) );
	}

	private static function payment( string $status, int $amount = 9295, string $currency = 'EUR' ): Response {
		$card = array( 'status' => array( 'COMPLETED' => 'CAPTURED', 'APPROVED' => 'AUTHORIZED', 'CANCELED' => 'VOIDED' )[ $status ] ?? 'FAILED', 'card' => array( 'card_brand' => 'VISA', 'last_4' => '4242' ), 'entry_method' => 'CONTACTLESS', 'auth_result_code' => 'OK1' );
		if ( 'FAILED' === $status ) {
			$card['errors'] = array( array( 'category' => 'PAYMENT_METHOD_ERROR', 'code' => 'GENERIC_DECLINE' ) );
		}
		return self::json( 200, array( 'payment' => array( 'id' => 'PAY1', 'status' => $status, 'total_money' => array( 'amount' => $amount, 'currency' => $currency ), 'card_details' => $card, 'receipt_number' => 'R1', 'reference_id' => self::ROW ) ) );
	}

	private static function refund_response( string $status ): Response {
		return self::json( 200, array( 'refund' => array( 'id' => 'RF1', 'status' => $status, 'payment_id' => 'PAY1', 'amount_money' => array( 'amount' => 500, 'currency' => 'EUR' ) ) ) );
	}

	public function test_create_sends_the_row_as_idempotency_key_and_reference_and_names_the_deadline(): void {
		$this->square->queue[] = self::checkout( 'PENDING' );
		$result = $this->adapter()->create_reader_action( $this->row(), 'DEV1' );
		$this->assertSame( 'sandbox:TC1', $result['ref'] );
		$this->assertSame( '2026-10-09T10:05:00+00:00', $result['expires_at'] );
		$body = $this->square->body( 0 );
		$this->assertSame( self::ROW, $body['idempotency_key'] );
		$this->assertSame( self::ROW, $body['checkout']['reference_id'] );
		$this->assertSame( 9295, $body['checkout']['amount_money']['amount'] );
		$this->assertSame( 'EUR', $body['checkout']['amount_money']['currency'] );
		$this->assertSame( 'DEV1', $body['checkout']['device_options']['device_id'] );
		$this->assertSame( 'PT5M', $body['checkout']['deadline_duration'] );
		$this->assertSame( 'connect.squareupsandbox.com', $this->square->requests[0]->getUri()->getHost() );
		$this->assertSame( 'Bearer sb-token', $this->square->requests[0]->getHeaderLine( 'Authorization' ) );
	}

	/** @dataProvider unanswered_creates */
	public function test_an_unanswered_create_is_indeterminate_so_free_keeps_the_row( $answer ): void {
		$this->square->queue[] = $answer;
		$error = $this->adapter()->create_reader_action( $this->row(), 'DEV1' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertTrue( $error->get_error_data()['indeterminate'] );
	}
	public function unanswered_creates(): array {
		return array(
			'transport loss'          => array( self::lost() ),
			'503'                     => array( self::error( 503, 'SERVICE_UNAVAILABLE' ) ),
			'429'                     => array( self::error( 429, 'RATE_LIMITED' ) ),
			'key reused, other body'  => array( self::error( 400, 'IDEMPOTENCY_KEY_REUSED' ) ),
		);
	}

	public function test_a_refused_create_is_final_and_names_squares_code(): void {
		$this->square->queue[] = self::error( 404, 'NOT_FOUND' );
		$error = $this->adapter()->create_reader_action( $this->row(), 'DEV_GONE' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'wcpos_provider_error', $error->get_error_code() );
		$this->assertArrayNotHasKey( 'indeterminate', $error->get_error_data() );
		$this->assertSame( 'NOT_FOUND', $error->get_error_data()['detail']['error_code'] );
	}

	public function test_fetch_maps_live_checkout_states_without_reading_payments(): void {
		$this->square->queue = array( self::checkout( 'PENDING' ), self::checkout( 'IN_PROGRESS' ), self::checkout( 'CANCEL_REQUESTED' ) );
		$adapter = $this->adapter();
		$this->assertSame( 'pending', $adapter->fetch( 'sandbox:TC1' )['status'] );
		$this->assertSame( 'in_progress', $adapter->fetch( 'sandbox:TC1' )['status'] );
		$this->assertSame( 'in_progress', $adapter->fetch( 'sandbox:TC1' )['status'] );
		$this->assertCount( 3, $this->square->requests );
	}

	public function test_fetch_of_a_completed_checkout_reads_the_payment_for_money_and_the_transaction_id(): void {
		$this->square->queue = array( self::checkout( 'COMPLETED', array( 'PAY1' ) ), self::payment( 'COMPLETED' ) );
		$result = $this->adapter()->fetch( 'sandbox:TC1' );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( '92.95', $result['amount'] );
		$this->assertSame( 'EUR', $result['currency'] );
		$this->assertSame( 'PAY1', $result['provider_refs']['transaction_id'] );
		$this->assertSame( 'DEV1', $result['provider_refs']['reader'] );
		$this->assertSame( array( 'card_label' => 'VISA', 'card_last4' => '4242', 'read_method' => 'CONTACTLESS', 'auth_code' => 'OK1', 'receipt_number' => 'R1', 'square_payment' => 'PAY1' ), $result['receipt'] );
	}

	public function test_a_decline_keeps_the_checkout_in_progress_until_square_ends_it(): void {
		// Square leaves a declined checkout IN_PROGRESS for the buyer to try again: never a final failure.
		$this->square->queue = array( self::checkout( 'IN_PROGRESS', array( 'PAY1' ) ) );
		$this->assertSame( 'in_progress', $this->adapter()->fetch( 'sandbox:TC1' )['status'] );
		$this->assertCount( 1, $this->square->requests, 'A live checkout is not judged by its payments' );
		// Ended after the decline: a failure, with the card's reason, not the cashier's cancellation.
		$this->square->queue = array( self::checkout( 'CANCELED', array( 'PAY1' ), 'BUYER_CANCELED' ), self::payment( 'FAILED' ) );
		$result = $this->adapter()->fetch( 'sandbox:TC1' );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'generic_decline', $result['failure_reason'] );
	}

	public function test_cancelled_checkouts_are_expired_cancelled_or_money(): void {
		$this->square->queue = array( self::checkout( 'CANCELED', array(), 'TIMED_OUT' ) );
		$this->assertSame( 'expired', $this->adapter()->fetch( 'sandbox:TC1' )['status'] );
		$this->square->queue = array( self::checkout( 'CANCELED', array(), 'SELLER_CANCELED' ) );
		$this->assertSame( 'cancelled', $this->adapter()->fetch( 'sandbox:TC1' )['status'] );
		// A checkout that timed out after its payment was captured is money, not an expiry.
		$this->square->queue = array( self::checkout( 'CANCELED', array( 'PAY1' ), 'TIMED_OUT' ), self::payment( 'COMPLETED' ) );
		$result = $this->adapter()->fetch( 'sandbox:TC1' );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( '92.95', $result['amount'] );
	}

	public function test_an_approved_payment_on_an_ended_checkout_is_money_that_may_still_arrive(): void {
		// Square approved the payment, the checkout timed out at the receipt screen, and Square has not
		// completed the payment yet: nothing is settled, and the leg must not end on either side.
		$approved = self::payment( 'APPROVED' );
		$this->square->queue = array( self::checkout( 'CANCELED', array( 'PAY1' ), 'TIMED_OUT' ), $approved );
		$error = $this->adapter()->fetch( 'sandbox:TC1' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertTrue( $error->get_error_data()['indeterminate'] );
		$this->square->queue = array( self::checkout( 'CANCELED', array( 'PAY1' ), 'TIMED_OUT' ), self::payment( 'APPROVED' ) );
		$this->assertSame( 'requested', $this->adapter()->cancel( 'sandbox:TC1' ) );
		$this->square->queue = array( self::checkout( 'CANCELED', array( 'PAY1' ), 'TIMED_OUT' ), self::payment( 'APPROVED' ) );
		$this->assertSame( 'pending', $this->adapter()->verify_webhook( $this->webhook( self::event() ) )['patch']['status'] );
		// A payment Square itself cancelled ends nothing but the checkout: expired, as before.
		$this->square->queue = array( self::checkout( 'CANCELED', array( 'PAY1' ), 'TIMED_OUT' ), self::payment( 'CANCELED' ) );
		$this->assertSame( 'expired', $this->adapter()->fetch( 'sandbox:TC1' )['status'] );
	}

	public function test_an_approved_second_card_outranks_a_declined_first_one(): void {
		// First card declined, second card approved and not yet completed when the checkout timed out:
		// the decline must not end the leg while the second payment may still become money.
		$this->square->queue = array( self::checkout( 'CANCELED', array( 'PAY1', 'PAY2' ), 'TIMED_OUT' ), self::payment( 'FAILED' ), self::payment( 'APPROVED' ) );
		$error = $this->adapter()->fetch( 'sandbox:TC1' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertTrue( $error->get_error_data()['indeterminate'] );
		$this->assertCount( 3, $this->square->requests, 'Every listed payment is read before judging' );
	}

	public function test_a_completed_checkout_whose_payment_cannot_be_read_settles_nothing(): void {
		$this->square->queue = array( self::checkout( 'COMPLETED', array( 'PAY1' ) ), self::error( 503, 'SERVICE_UNAVAILABLE' ) );
		$error = $this->adapter()->fetch( 'sandbox:TC1' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertTrue( $error->get_error_data()['indeterminate'] );
	}

	public function test_a_reference_carries_its_environment_so_a_sandbox_checkout_is_never_read_with_the_live_token(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings']['environment'] = 'production';
		Settings::reset_cache_for_tests();
		$this->square->queue = array( self::checkout( 'PENDING' ), self::checkout( 'PENDING' ) );
		$adapter = $this->adapter();
		$adapter->fetch( 'sandbox:TC1' );
		$adapter->fetch( 'production:TC2' );
		$this->assertSame( 'connect.squareupsandbox.com', $this->square->requests[0]->getUri()->getHost() );
		$this->assertSame( 'Bearer sb-token', $this->square->requests[0]->getHeaderLine( 'Authorization' ) );
		$this->assertSame( 'connect.squareup.com', $this->square->requests[1]->getUri()->getHost() );
		$this->assertSame( 'Bearer prod-token', $this->square->requests[1]->getHeaderLine( 'Authorization' ) );
	}

	public function test_cancel_is_final_only_when_square_shows_a_cancelled_checkout_without_money(): void {
		$adapter = $this->adapter();
		$this->square->queue = array( self::checkout( 'CANCELED', array(), 'SELLER_CANCELED' ) );
		$this->assertSame( 'final', $adapter->cancel( 'sandbox:TC1' ) );
		$this->square->queue = array( self::checkout( 'CANCEL_REQUESTED' ) );
		$this->assertSame( 'requested', $adapter->cancel( 'sandbox:TC1' ) );
		// Refused because the checkout already ended: only the read says how.
		$this->square->queue = array( self::error( 400, 'BAD_REQUEST' ), self::checkout( 'COMPLETED', array( 'PAY1' ) ) );
		$this->assertSame( 'requested', $adapter->cancel( 'sandbox:TC1' ) );
		$this->square->queue = array( self::error( 400, 'BAD_REQUEST' ), self::checkout( 'CANCELED', array(), 'TIMED_OUT' ) );
		$this->assertSame( 'final', $adapter->cancel( 'sandbox:TC1' ) );
		// Cancelled, but its payment was captured first: money polling will settle.
		$this->square->queue = array( self::checkout( 'CANCELED', array( 'PAY1' ), 'TIMED_OUT' ), self::payment( 'COMPLETED' ) );
		$this->assertSame( 'requested', $adapter->cancel( 'sandbox:TC1' ) );
	}

	private function refund_record(): \SQTWC_Test_Refund {
		$refund = new \SQTWC_Test_Refund( 501, 99 );
		$GLOBALS['sqtwc_orders'][501] = $refund;
		return $refund;
	}

	public function test_refund_posts_the_payment_under_a_key_saved_on_the_record_before_the_post(): void {
		$refund = $this->refund_record();
		$this->square->queue = array( self::refund_response( 'COMPLETED' ) );
		$result = $this->adapter()->refund( $this->row(), 501, '5.00' );
		$this->assertSame( array( 'status' => 'succeeded', 'provider_ref' => 'RF1' ), $result );
		$body = $this->square->body( 0 );
		$this->assertSame( 'PAY1', $body['payment_id'] );
		$this->assertSame( 500, $body['amount_money']['amount'] );
		$key = $refund->get_meta( Refund_Reask::META_ATTEMPT );
		$this->assertNotEmpty( $key );
		$this->assertSame( $key, $body['idempotency_key'] );
		$this->assertGreaterThanOrEqual( 1, $refund->saves, 'The key is persisted before the POST' );
		$this->assertSame( array(), $GLOBALS['sqtwc_single_events'] );
	}

	public function test_refund_statuses_map_and_a_refusal_is_an_error_with_nothing_scheduled(): void {
		$this->refund_record();
		$this->square->queue = array( self::refund_response( 'PENDING' ) );
		$this->assertSame( 'pending', $this->adapter()->refund( $this->row(), 501, '5.00' )['status'] );
		$this->square->queue = array( self::refund_response( 'REJECTED' ) );
		$this->assertSame( 'failed', $this->adapter()->refund( $this->row(), 501, '5.00' )['status'] );
		$this->square->queue = array( self::error( 400, 'REFUND_AMOUNT_INVALID' ) );
		$error = $this->adapter()->refund( $this->row(), 501, '5.00' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertArrayNotHasKey( 'indeterminate', $error->get_error_data() );
		$this->assertSame( array(), $GLOBALS['sqtwc_single_events'] );
	}

	public function test_a_refund_key_square_has_seen_with_another_body_is_asked_about_not_refused(): void {
		$this->refund_record();
		$this->square->queue = array( self::error( 400, 'IDEMPOTENCY_KEY_REUSED' ) );
		$this->assertSame( array( 'status' => 'pending', 'provider_ref' => null ), $this->adapter()->refund( $this->row(), 501, '5.00' ) );
		$this->assertCount( 1, $GLOBALS['sqtwc_single_events'] );
	}

	public function test_a_record_deleted_before_square_answered_is_reported_on_its_order(): void {
		$this->refund_record();
		$this->square->queue = array( self::lost() );
		$this->adapter()->refund( $this->row(), 501, '5.00' );
		unset( $GLOBALS['sqtwc_orders'][501] );
		Refund_Reask::run( 501, 1, 99, $this->adapter() );
		$this->assertStringContainsString( 'Refund #501 was deleted before Square confirmed it', end( $GLOBALS['sqtwc_orders'][99]->notes ) );
		$this->assertCount( 1, $this->square->requests, 'Nothing is replayed for a record that is gone' );
	}

	public function test_an_unanswered_refund_post_keeps_the_record_pending_and_schedules_a_re_ask(): void {
		$refund = $this->refund_record();
		$this->square->queue = array( self::lost() );
		$result = $this->adapter()->refund( $this->row(), 501, '5.00' );
		$this->assertSame( array( 'status' => 'pending', 'provider_ref' => null ), $result );
		$this->assertCount( 1, $GLOBALS['sqtwc_single_events'] );
		$this->assertSame( Refund_Reask::HOOK, $GLOBALS['sqtwc_single_events'][0]['hook'] );
		$this->assertSame( array( 501, 1, 99 ), $GLOBALS['sqtwc_single_events'][0]['args'] );
		$this->assertStringContainsString( 'did not confirm refund #501', $GLOBALS['sqtwc_orders'][99]->notes[0] );
		$key = $refund->get_meta( Refund_Reask::META_ATTEMPT );
		// A second ask replays the identical request under the same key: Square dedupes, no second refund.
		$this->square->queue = array( self::refund_response( 'COMPLETED' ) );
		Refund_Reask::run( 501, 1, 99, $this->adapter() );
		$this->assertSame( $key, $this->square->body( 1 )['idempotency_key'] );
		$this->assertSame( 'PAY1', $this->square->body( 1 )['payment_id'] );
		$this->assertSame( 'RF1', $refund->get_meta( Refund_Reask::META_REFUND ) );
		$this->assertStringContainsString( 'confirmed refund #501 (RF1): COMPLETED', end( $GLOBALS['sqtwc_orders'][99]->notes ) );
		// Answered: a later ask is a no-op.
		Refund_Reask::run( 501, 2, 99, $this->adapter() );
		$this->assertCount( 2, $this->square->requests );
	}

	public function test_the_re_ask_waits_again_while_square_is_silent_and_then_tells_staff(): void {
		$refund = $this->refund_record();
		$this->square->queue = array( self::lost() );
		$this->adapter()->refund( $this->row(), 501, '5.00' );
		$this->square->queue = array( self::error( 503, 'SERVICE_UNAVAILABLE' ) );
		Refund_Reask::run( 501, 2, 99, $this->adapter() );
		$this->assertSame( array( 501, 3, 99 ), end( $GLOBALS['sqtwc_single_events'] )['args'] );
		$this->square->queue = array( self::lost() );
		Refund_Reask::run( 501, Refund_Reask::LIMIT, 99, $this->adapter() );
		$this->assertCount( 2, $GLOBALS['sqtwc_single_events'], 'The last try schedules nothing more' );
		$this->assertStringContainsString( 'Check the Square dashboard', end( $GLOBALS['sqtwc_orders'][99]->notes ) );
		$this->assertSame( '', $refund->get_meta( Refund_Reask::META_REFUND ) );
	}

	public function test_the_re_ask_records_a_refusal_as_no_refund_made(): void {
		$this->refund_record();
		$this->square->queue = array( self::lost() );
		$this->adapter()->refund( $this->row(), 501, '5.00' );
		$this->square->queue = array( self::error( 400, 'REFUND_AMOUNT_INVALID' ) );
		Refund_Reask::run( 501, 1, 99, $this->adapter() );
		$this->assertStringContainsString( 'refused refund #501: REFUND_AMOUNT_INVALID. No money was returned', end( $GLOBALS['sqtwc_orders'][99]->notes ) );
		$this->assertCount( 1, $GLOBALS['sqtwc_single_events'] );
	}

	public function test_the_re_ask_keeps_the_question_open_when_a_refusal_proves_nothing(): void {
		$this->refund_record();
		$this->square->queue = array( self::lost() );
		$this->adapter()->refund( $this->row(), 501, '5.00' );
		// 401 and IDEMPOTENCY_KEY_REUSED on the replay do not say the first request made nothing.
		foreach ( array( self::error( 401, 'UNAUTHORIZED' ), self::error( 400, 'IDEMPOTENCY_KEY_REUSED' ) ) as $try => $answer ) {
			$this->square->queue = array( $answer );
			Refund_Reask::run( 501, $try + 1, 99, $this->adapter() );
			$this->assertSame( array( 501, $try + 2, 99 ), end( $GLOBALS['sqtwc_single_events'] )['args'] );
		}
		$this->assertCount( 1, $GLOBALS['sqtwc_orders'][99]->notes, 'No "no money was returned" note while the question is open' );
	}

	public function test_cashiers_see_the_mapped_message_and_the_create_body_is_locale_independent(): void {
		$this->square->queue = array( self::error( 404, 'NOT_FOUND' ) );
		$error = $this->adapter()->create_reader_action( $this->row(), 'DEV_GONE' );
		$this->assertSame( 'This terminal is no longer paired. Choose another terminal or pair it again.', $error->get_error_message() );
		$this->assertStringContainsString( 'NOT_FOUND', $error->get_error_data()['detail']['message'] );
		$this->square->queue = array( self::lost() );
		$error = $this->adapter()->create_reader_action( $this->row(), 'DEV1' );
		$this->assertSame( 'Square did not answer. The payment is being checked.', $error->get_error_message() );
		$this->assertStringContainsString( 'lost', $error->get_error_data()['detail']['message'] );
		$this->assertSame( 'Order #99', $this->square->body( 1 )['checkout']['note'] );
	}

	public function test_a_lost_read_before_the_refund_post_is_an_error_with_nothing_made_or_scheduled(): void {
		$refund = $this->refund_record();
		// A leg whose capture carried no transaction id: the payment must be read from the checkout first.
		$this->square->queue = array( self::lost() );
		$error = $this->adapter()->refund( $this->row( array( 'action' => 'sandbox:TC1' ) ), 501, '5.00' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertTrue( $error->get_error_data()['indeterminate'] );
		$this->assertSame( array(), $GLOBALS['sqtwc_single_events'] );
		$this->assertSame( '', $refund->get_meta( Refund_Reask::META_ATTEMPT ) );
		// Read answered: the captured payment of the checkout is refunded.
		$this->square->queue = array( self::checkout( 'COMPLETED', array( 'PAY1' ) ), self::payment( 'COMPLETED' ), self::refund_response( 'COMPLETED' ) );
		$this->assertSame( 'succeeded', $this->adapter()->refund( $this->row( array( 'action' => 'sandbox:TC1' ) ), 501, '5.00' )['status'] );
		$this->assertSame( 'PAY1', $this->square->body( 3 )['payment_id'] );
	}

	private function webhook( array $event, string $key = 'whsec' ): \WP_REST_Request {
		$body    = json_encode( $event );
		$request = new \WP_REST_Request();
		$request->set_body( $body );
		$request->set_header( Square_Server_Provider::SIGNATURE_HEADER, base64_encode( hash_hmac( 'sha256', Settings::get_pro_webhook_url() . $body, $key, true ) ) );
		return $request;
	}

	private static function event( string $type = 'terminal.checkout.updated', string $id = 'TC1' ): array {
		return array( 'type' => $type, 'event_id' => 'evt1', 'data' => array( 'type' => 'checkout', 'id' => $id, 'object' => array( 'checkout' => array( 'id' => $id, 'status' => 'COMPLETED' ) ) ) );
	}

	public function test_a_webhook_with_a_bad_signature_is_refused_before_any_call_to_square(): void {
		$error = $this->adapter()->verify_webhook( $this->webhook( self::event(), 'wrong' ) );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 401, $error->get_error_data()['status'] );
		$this->assertSame( array(), $this->square->requests );
		$this->assertSame( array(), $GLOBALS['sqtwc_adoption_lookups'] );
	}

	public function test_a_verified_completed_checkout_settles_its_row_with_the_complete_refs_from_the_read(): void {
		$this->square->queue = array( self::checkout( 'COMPLETED', array( 'PAY1' ) ), self::payment( 'COMPLETED' ) );
		$result = $this->adapter()->verify_webhook( $this->webhook( self::event() ) );
		$this->assertSame( self::ROW, $result['payment_id'] );
		$this->assertSame( 'captured', $result['patch']['status'] );
		$this->assertSame( '92.95', $result['patch']['amount'] );
		$this->assertSame( array( 'action' => 'sandbox:TC1', 'reader' => 'DEV1', 'transaction_id' => 'PAY1', 'square_payment' => 'PAY1', 'square_checkout' => 'TC1' ), $result['patch']['provider_refs'] );
		$this->assertNotEmpty( $result['patch']['event_id'] );
	}

	public function test_an_adopted_checkout_resolves_through_pros_record_before_the_read_and_over_the_reference(): void {
		$GLOBALS['sqtwc_adopted']['sandbox:TC1'] = 'bbbbbbbb-1111-4222-8333-444455556666';
		$this->square->queue = array( self::checkout( 'COMPLETED', array( 'PAY1' ), null, 'woocommerce_order_99' ), self::payment( 'COMPLETED' ) );
		$result = $this->adapter()->verify_webhook( $this->webhook( self::event() ) );
		$this->assertSame( 'bbbbbbbb-1111-4222-8333-444455556666', $result['payment_id'] );
		$this->assertSame( array( array( 'square', 'sandbox:TC1' ) ), $GLOBALS['sqtwc_adoption_lookups'] );
	}

	public function test_a_checkout_that_is_not_wcpos_is_acknowledged_and_an_unknown_one_is_404(): void {
		$this->square->queue = array( self::checkout( 'COMPLETED', array( 'PAY1' ), null, 'someone-elses-order' ), self::payment( 'COMPLETED' ) );
		$ignored = $this->adapter()->verify_webhook( $this->webhook( self::event() ) );
		$this->assertSame( 200, $ignored->get_error_data()['status'] );
		$this->square->queue = array( self::error( 404, 'NOT_FOUND' ) );
		$unknown = $this->adapter()->verify_webhook( $this->webhook( self::event( 'terminal.checkout.updated', 'TC_FORGED' ) ) );
		$this->assertSame( 404, $unknown->get_error_data()['status'] );
		$other = $this->adapter()->verify_webhook( $this->webhook( self::event( 'payment.updated' ) ) );
		$this->assertSame( 200, $other->get_error_data()['status'] );
		$this->assertCount( 3, $this->square->requests, 'Another event type calls nothing' );
	}

	public function test_webhook_patches_use_ledger_vocabulary_with_ids_that_follow_the_outcome(): void {
		$adapter = $this->adapter();
		$this->square->queue = array( self::checkout( 'CANCELED', array(), 'TIMED_OUT' ) );
		$expired = $adapter->verify_webhook( $this->webhook( self::event() ) )['patch'];
		$this->assertSame( 'failed', $expired['status'] );
		$this->square->queue = array( self::checkout( 'CANCELED', array(), 'SELLER_CANCELED' ) );
		$voided = $adapter->verify_webhook( $this->webhook( self::event() ) )['patch'];
		$this->assertSame( 'voided', $voided['status'] );
		$this->square->queue = array( self::checkout( 'IN_PROGRESS' ) );
		$pending = $adapter->verify_webhook( $this->webhook( self::event() ) )['patch'];
		$this->assertSame( 'pending', $pending['status'] );
		$this->assertCount( 3, array_unique( array( $expired['event_id'], $voided['event_id'], $pending['event_id'] ) ) );
		$this->assertArrayNotHasKey( 'failure_reason', $expired );
	}
}
