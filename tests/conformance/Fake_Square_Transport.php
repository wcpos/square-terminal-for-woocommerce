<?php
/**
 * A scripted Square behind the SDK's PSR-18 client: device codes, Terminal checkouts (idempotent
 * on the key, refused with IDEMPOTENCY_KEY_REUSED under another body), the checkout read and
 * cancel, payments, and refunds, as Square's Terminal API reference describes them. A checkout has
 * no decline state: a decline leaves a FAILED payment in `payment_ids` and the checkout ends CANCELED.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Conformance;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Only the fixture knows provider internals; the suite observes the money path. */
final class Fake_Square_Transport implements ClientInterface {
	/** The token each host accepts: a sandbox token is refused by production and the other way round. */
	public const TOKENS = array(
		'connect.squareupsandbox.com' => 'sandbox-conformance',
		'connect.squareup.com'        => 'production-conformance',
	);

	/** The configured location. */
	public const LOCATION = 'LCONF';

	/** Every request as the SDK sent it (method, path, body), for debugging a transcript. */
	public $raw = array();

	/** The id of the latest checkout created or returned by its idempotency key. */
	public $current;

	private $checkouts = array();
	private $by_key    = array();
	private $payments  = array();
	private $refunds   = array();
	private $event_ids = array();
	private $devices;
	private $states        = array( 'PENDING' );
	private $scenario      = 'create_ok';
	private $refund_status = 'COMPLETED';
	private $cancel        = 'final';
	private $lost          = false;
	private $seq           = 0;

	public function __construct() {
		// Device codes report pairing, not connectivity: both read online to the adapter.
		$this->devices = array(
			array( 'id' => 'DC1', 'name' => 'Front counter', 'device_id' => 'DEV_FRONT', 'product_type' => 'TERMINAL_API', 'location_id' => self::LOCATION, 'status' => 'PAIRED' ),
			array( 'id' => 'DC2', 'name' => 'Back office', 'device_id' => 'DEV_BACK', 'product_type' => 'TERMINAL_API', 'location_id' => self::LOCATION, 'status' => 'PAIRED' ),
		);
	}

	/**
	 * Arm a scenario: the states successive adapter fetches observe, how a refund answers, and
	 * whether a cancel is applied at once (`final`) or only requested (`requested`).
	 */
	public function script( string $scenario, array $states, string $refund_status = 'COMPLETED', string $cancel = 'final' ): void {
		$this->scenario      = $scenario;
		$this->states        = $states;
		$this->refund_status = $refund_status;
		$this->cancel        = $cancel;
		$this->lost          = false;
	}

	/** Called by the recording adapter on entry to fetch(): the checkout moves to its next scripted state. */
	public function advance( string $id ): void {
		if ( ! isset( $this->checkouts[ $id ] ) || $this->is_final( $this->checkouts[ $id ] ) ) {
			return; // A checkout that ended stays ended.
		}
		$entry = &$this->checkouts[ $id ];
		$state = count( $entry['states'] ) > 1 ? array_shift( $entry['states'] ) : $entry['states'][0];
		$this->apply_state( $entry, $state );
	}

	/** A provider-side outcome (what a delivery reports), even over an ended checkout. */
	public function observe( string $id, string $state ): void {
		$this->apply_state( $this->checkouts[ $id ], $state );
	}

	/** The checkout a create keyed on this row id made, even when its response was lost. */
	public function checkout_for_key( string $key ): ?string {
		return $this->by_key[ $key ]['id'] ?? null;
	}

	/** A signed `terminal.checkout.updated` event body; a repeated event for one state shares its id. */
	public function event_body( string $id ): string {
		$view = $this->view_checkout( $id );
		$slot = $id . ':' . $view['status'] . ':' . implode( ',', $view['payment_ids'] );
		if ( ! isset( $this->event_ids[ $slot ] ) ) {
			$this->event_ids[ $slot ] = 'evt_' . ( count( $this->event_ids ) + 1 );
		}
		return wp_json_encode(
			array(
				'merchant_id' => 'MCONF',
				'type'        => 'terminal.checkout.updated',
				'event_id'    => $this->event_ids[ $slot ],
				'created_at'  => gmdate( 'c' ),
				'data'        => array( 'type' => 'checkout', 'id' => $id, 'object' => array( 'checkout' => $view ) ),
			)
		);
	}

	/**
	 * The PSR-18 entry point the SDK calls.
	 *
	 * @throws ConnectException When the scripted response is lost.
	 * @throws \LogicException On a request no scenario expects.
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$host   = $request->getUri()->getHost();
		$path   = $request->getUri()->getPath();
		$method = strtoupper( $request->getMethod() );
		$body   = json_decode( (string) $request->getBody(), true ) ?: array();
		$query  = array();
		parse_str( $request->getUri()->getQuery(), $query );
		$this->raw[] = array( 'method' => $method, 'host' => $host, 'path' => $path, 'body' => $body );
		if ( ! isset( self::TOKENS[ $host ] ) || 'Bearer ' . self::TOKENS[ $host ] !== $request->getHeaderLine( 'Authorization' ) ) {
			return self::error( 401, 'AUTHENTICATION_ERROR', 'UNAUTHORIZED', 'This request could not be authorized.' );
		}

		if ( '/v2/devices/codes' === $path && 'GET' === $method ) {
			$codes = array_filter( $this->devices, static function ( $code ) use ( $query ) {
				return ( empty( $query['status'] ) || $code['status'] === $query['status'] ) && ( empty( $query['location_id'] ) || $code['location_id'] === $query['location_id'] );
			} );
			return self::ok( array( 'device_codes' => array_values( $codes ) ) );
		}
		if ( '/v2/terminals/checkouts' === $path && 'POST' === $method ) {
			return $this->create( $body, $request );
		}
		if ( preg_match( '#^/v2/terminals/checkouts/([^/]+)$#', $path, $m ) && 'GET' === $method ) {
			return isset( $this->checkouts[ $m[1] ] ) ? self::ok( array( 'checkout' => $this->view_checkout( $m[1] ) ) ) : self::error( 404, 'INVALID_REQUEST_ERROR', 'NOT_FOUND', 'Could not find checkout with id: ' . $m[1] );
		}
		if ( preg_match( '#^/v2/terminals/checkouts/([^/]+)/cancel$#', $path, $m ) && 'POST' === $method ) {
			if ( ! isset( $this->checkouts[ $m[1] ] ) ) {
				return self::error( 404, 'INVALID_REQUEST_ERROR', 'NOT_FOUND', 'Could not find checkout with id: ' . $m[1] );
			}
			$entry = &$this->checkouts[ $m[1] ];
			if ( $this->is_final( $entry ) ) {
				return self::error( 400, 'INVALID_REQUEST_ERROR', 'BAD_REQUEST', 'Checkout is already in a terminal state.' );
			}
			$this->apply_state( $entry, 'final' === $entry['cancel'] ? 'CANCELED:seller' : 'CANCEL_REQUESTED' );
			return self::ok( array( 'checkout' => $this->view_checkout( $m[1] ) ) );
		}
		if ( preg_match( '#^/v2/payments/([^/]+)$#', $path, $m ) && 'GET' === $method ) {
			return isset( $this->payments[ $m[1] ] ) ? self::ok( array( 'payment' => $this->view_payment( $m[1] ) ) ) : self::error( 404, 'INVALID_REQUEST_ERROR', 'NOT_FOUND', 'Could not find payment with id: ' . $m[1] );
		}
		if ( '/v2/refunds' === $path && 'POST' === $method ) {
			return $this->refund( $body );
		}
		throw new \LogicException( 'Unexpected Square request: ' . $method . ' ' . $path );
	}

	private function create( array $body, RequestInterface $request ): ResponseInterface {
		$key      = (string) ( $body['idempotency_key'] ?? '' );
		$checkout = (array) ( $body['checkout'] ?? array() );
		$hash     = md5( wp_json_encode( $checkout ) );
		if ( '' !== $key && isset( $this->by_key[ $key ] ) ) {
			if ( $this->by_key[ $key ]['hash'] !== $hash ) {
				return self::error( 400, 'INVALID_REQUEST_ERROR', 'IDEMPOTENCY_KEY_REUSED', 'The idempotency key was used with a different request.' );
			}
			// Square replays the original response for a key it has seen.
			$this->current = $this->by_key[ $key ]['id'];
			return self::ok( array( 'checkout' => $this->view_checkout( $this->current ) ) );
		}
		$device = (string) ( $checkout['device_options']['device_id'] ?? '' );
		if ( ! in_array( $device, array_column( $this->devices, 'device_id' ), true ) ) {
			return self::error( 404, 'INVALID_REQUEST_ERROR', 'NOT_FOUND', 'Device not found: ' . $device );
		}
		$id                     = 'TC' . str_pad( (string) ( ++$this->seq ), 6, '0', STR_PAD_LEFT );
		$this->checkouts[ $id ] = array(
			'id'          => $id,
			'states'      => $this->states,
			'state'       => 'PENDING',
			'reason'      => null,
			'amount'      => (int) ( $checkout['amount_money']['amount'] ?? 0 ),
			'currency'    => (string) ( $checkout['amount_money']['currency'] ?? 'EUR' ),
			'device'      => $device,
			'reference'   => (string) ( $checkout['reference_id'] ?? '' ),
			'note'        => (string) ( $checkout['note'] ?? '' ),
			'payment_ids' => array(),
			'cancel'      => $this->cancel,
			'created'     => time(),
		);
		if ( '' !== $key ) {
			$this->by_key[ $key ] = array( 'id' => $id, 'hash' => $hash );
		}
		$this->current = $id;
		if ( 'create_indeterminate' === $this->scenario && ! $this->lost ) {
			$this->lost = true; // Created, on the terminal, and the response never arrives.
			throw new ConnectException( 'Response lost after creation', $request );
		}
		return self::ok( array( 'checkout' => $this->view_checkout( $id ) ) );
	}

	private function refund( array $body ): ResponseInterface {
		$key = (string) ( $body['idempotency_key'] ?? '' );
		if ( '' !== $key && isset( $this->refunds[ $key ] ) ) {
			return self::ok( array( 'refund' => $this->refunds[ $key ] ) );
		}
		$payment = $this->payments[ (string) ( $body['payment_id'] ?? '' ) ] ?? null;
		if ( ! $payment || 'COMPLETED' !== $payment['status'] ) {
			return self::error( 400, 'INVALID_REQUEST_ERROR', 'BAD_REQUEST', 'Payment is not refundable.' );
		}
		$refund = array(
			'id'           => 'RF' . ( ++$this->seq ),
			'status'       => $this->refund_status,
			'payment_id'   => $payment['id'],
			'amount_money' => $body['amount_money'],
			'reason'       => $body['reason'] ?? null,
			'created_at'   => gmdate( 'c' ),
		);
		$this->refunds[ '' !== $key ? $key : $refund['id'] ] = $refund;
		return self::ok( array( 'refund' => $refund ) );
	}

	private function is_final( array $entry ): bool {
		return in_array( $entry['state'], array( 'COMPLETED', 'CANCELED' ), true );
	}

	/**
	 * Move a checkout to a named state.
	 *
	 * @param array  $entry Checkout entry, by reference.
	 * @param string $state PENDING, IN_PROGRESS, CANCEL_REQUESTED, COMPLETED, COMPLETED:short (paid 1.00),
	 *                      COMPLETED:usd (paid in USD), CANCELED:timeout, CANCELED:seller, CANCELED:buyer,
	 *                      CANCELED:declined (a FAILED payment listed, buyer gave up).
	 */
	private function apply_state( array &$entry, string $state ): void {
		list( $status, $detail ) = array_pad( explode( ':', $state, 2 ), 2, '' );
		if ( ! in_array( $status, array( 'PENDING', 'IN_PROGRESS', 'CANCEL_REQUESTED', 'COMPLETED', 'CANCELED' ), true ) ) {
			throw new \OutOfBoundsException( 'Unknown checkout state: ' . $state );
		}
		$entry['state'] = $status;
		$entry['reason'] = 'CANCELED' === $status ? array( 'timeout' => 'TIMED_OUT', 'seller' => 'SELLER_CANCELED', 'buyer' => 'BUYER_CANCELED', 'declined' => 'BUYER_CANCELED' )[ $detail ?: 'seller' ] : null;
		if ( 'COMPLETED' === $status ) {
			$entry['payment_ids'] = array( $this->payment( $entry, 'COMPLETED', 'short' === $detail ? 100 : $entry['amount'], 'usd' === $detail ? 'USD' : $entry['currency'] ) );
		} elseif ( 'declined' === $detail ) {
			$entry['payment_ids'] = array( $this->payment( $entry, 'FAILED', $entry['amount'], $entry['currency'] ) );
		}
	}

	private function payment( array $entry, string $status, int $amount, string $currency ): string {
		$id                    = 'PAY' . ( ++$this->seq );
		$this->payments[ $id ] = array( 'id' => $id, 'status' => $status, 'amount' => $amount, 'currency' => $currency, 'reference' => $entry['reference'], 'checkout' => $entry['id'] );
		return $id;
	}

	/** The checkout as Square returns it. */
	private function view_checkout( string $id ): array {
		$entry = $this->checkouts[ $id ];
		return array_filter(
			array(
				'id'                => $id,
				'amount_money'      => array( 'amount' => $entry['amount'], 'currency' => $entry['currency'] ),
				'reference_id'      => $entry['reference'],
				'note'              => $entry['note'],
				'device_options'    => array( 'device_id' => $entry['device'], 'skip_receipt_screen' => false, 'collect_signature' => false ),
				'deadline_duration' => 'PT5M',
				'status'            => $entry['state'],
				'cancel_reason'     => $entry['reason'],
				'payment_ids'       => $entry['payment_ids'],
				'created_at'        => gmdate( 'c', $entry['created'] ),
				'updated_at'        => gmdate( 'c' ),
				'app_id'            => 'sq0idp-conformance',
				'location_id'       => self::LOCATION,
				'payment_type'      => 'CARD_PRESENT',
			),
			static function ( $value ) {
				return null !== $value;
			}
		);
	}

	/** The payment as Square returns it. */
	private function view_payment( string $id ): array {
		$payment = $this->payments[ $id ];
		$money   = array( 'amount' => $payment['amount'], 'currency' => $payment['currency'] );
		$card    = array(
			'status'       => 'COMPLETED' === $payment['status'] ? 'CAPTURED' : 'FAILED',
			'card'         => array( 'card_brand' => 'VISA', 'last_4' => '1111' ),
			'entry_method' => 'CONTACTLESS',
		);
		if ( 'FAILED' === $payment['status'] ) {
			$card['errors'] = array( array( 'category' => 'PAYMENT_METHOD_ERROR', 'code' => 'GENERIC_DECLINE', 'detail' => 'Card declined.' ) );
		} else {
			$card['auth_result_code'] = 'A1B2C3';
		}
		return array(
			'id'             => $id,
			'status'         => $payment['status'],
			'amount_money'   => $money,
			'tip_money'      => array( 'amount' => 0, 'currency' => $payment['currency'] ),
			'total_money'    => $money,
			'source_type'    => 'CARD',
			'card_details'   => $card,
			'reference_id'   => $payment['reference'],
			'receipt_number' => 'COMPLETED' === $payment['status'] ? 'RCPT' . substr( $id, 3 ) : null,
			'location_id'    => self::LOCATION,
		);
	}

	private static function ok( array $body ): ResponseInterface {
		return new Response( 200, array( 'Content-Type' => 'application/json' ), wp_json_encode( $body ) );
	}

	private static function error( int $status, string $category, string $code, string $detail ): ResponseInterface {
		return new Response( $status, array( 'Content-Type' => 'application/json' ), wp_json_encode( array( 'errors' => array( array( 'category' => $category, 'code' => $code, 'detail' => $detail ) ) ) ) );
	}
}
