<?php
/**
 * Square Terminal adapter for the WCPOS Pro payments base.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Server;

use Throwable;
use WCPOS\WooCommercePOS\SquareTerminal\Logger;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareClientFactory;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareDeviceAdapter;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareTerminalAdapter;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Exceptions\SquareApiException;
use WCPOS\WooCommercePOSPro\Payments\Server\Abstract_Provider_Adapter;
use WCPOS\WooCommercePOSPro\Payments\Server\Money_Units;

/**
 * Square's Terminal API behind Pro's flow-blind contract; Free owns the ledger.
 *
 * Facts the mapping rests on (Square's Terminal API reference):
 * - A checkout is PENDING, IN_PROGRESS, CANCEL_REQUESTED, CANCELED or COMPLETED. It has no decline
 *   state: a declined card leaves the checkout IN_PROGRESS for the buyer to try again, and the
 *   checkout ends COMPLETED, or CANCELED with a reason (BUYER_CANCELED, SELLER_CANCELED, TIMED_OUT).
 * - `payment_ids` lists the payments the checkout created. Money is read from the payment, never
 *   from the checkout: a CANCELED checkout with a captured payment is money, and a COMPLETED
 *   checkout whose payment cannot be read is nothing yet.
 * - Creates are idempotent on `idempotency_key` (the ledger row id): a replay of the same request
 *   hands back the first checkout; the same key with another body is IDEMPOTENCY_KEY_REUSED, which
 *   says the first checkout exists and is treated as unanswered, never as a refusal.
 * - A reference is `<environment>:<checkout id>`, so a sandbox checkout is polled, cancelled and
 *   refunded with the sandbox token after the gateway moves to production.
 */
class Square_Server_Provider extends Abstract_Provider_Adapter {
	/** Provider family, and the webhook route's provider query argument. */
	public const PROVIDER = 'square';

	/** Square's maximum deadline; a PENDING checkout is CANCELED with TIMED_OUT when it passes. */
	public const DEADLINE = 'PT5M';

	/** The deadline in seconds, for the row's expiry. */
	public const DEADLINE_SECONDS = 300;

	/** Square's signature header. */
	public const SIGNATURE_HEADER = 'x-square-hmacsha256-signature';

	/**
	 * Client factory.
	 *
	 * @var SquareClientFactory
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @param SquareClientFactory|null $factory Client factory override for tests.
	 */
	public function __construct( ?SquareClientFactory $factory = null ) {
		$this->factory = $factory ?? new SquareClientFactory();
	}

	/**
	 * {@inheritDoc}
	 */
	public function provider(): string {
		return self::PROVIDER;
	}

	/**
	 * {@inheritDoc}
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		// Tipping is not enabled on the checkout, so the captured amount is the row's.
		return array(
			'capabilities'  => array(
				'tips'    => 'none',
				'refunds' => array(
					'via'     => 'provider',
					'partial' => true,
				),
				'void'    => true,
			),
			'provider_data' => array( 'environment' => Settings::get_environment() ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_readers() {
		try {
			$devices = new SquareDeviceAdapter( $this->factory->create( null, Settings::get_environment(), array( 'maxRetries' => 0 ) ) );
			$readers = array();
			foreach ( $devices->list_paired_devices( Settings::get_location_id() ) as $device ) {
				// A device code reports pairing, not connectivity.
				$readers[] = array(
					'id'     => $device['id'],
					'label'  => $device['label'],
					'status' => 'online',
				);
			}

			return $readers;
		} catch ( Throwable $e ) {
			return self::provider_error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_reader_action( array $row, string $reader_id ) {
		$environment = Settings::get_environment();
		$order       = wc_get_order( (int) $row['order_id'] );
		if ( ! $order ) {
			return new \WP_Error( 'wcpos_order_not_found', __( 'Order not found.', 'square-terminal-for-woocommerce' ), array( 'status' => 404 ) );
		}
		try {
			$checkout = $this->terminal( $environment )->create_checkout(
				array(
					'idempotency_key'   => (string) $row['id'],
					'amount'            => Money_Units::minor( (string) $row['amount'], (string) $row['currency'] ),
					'currency'          => strtoupper( (string) $row['currency'] ),
					'device_id'         => $reader_id,
					// Pro's row id (36 characters; Square allows 40), read back from the checkout and its payments.
					'reference_id'      => (string) $row['id'],
					/* translators: %s: order number. */
					'note'              => sprintf( __( 'Order #%s', 'square-terminal-for-woocommerce' ), $order->get_order_number() ),
					'deadline_duration' => self::DEADLINE,
				)
			);

			return array(
				'ref'        => self::ref( $environment, (string) $checkout['id'] ),
				'expires_at' => self::expires_at( $checkout ),
			);
		} catch ( SquareApiException $e ) {
			// IDEMPOTENCY_KEY_REUSED: a checkout under this row's key exists with another body (a
			// replay after the reader changed); the first checkout may be on a terminal.
			if ( self::unanswered_status( $e->getStatusCode() ) || 'IDEMPOTENCY_KEY_REUSED' === self::error_code( $e ) ) {
				return $this->indeterminate( 'square_unanswered', $e->getMessage() );
			}

			return self::provider_error( $e );
		} catch ( Throwable $e ) {
			// Transport loss: the checkout may exist. Free keeps the row pending; the replay carries the
			// same idempotency key, and Square hands back the checkout the lost response made.
			return $this->indeterminate( 'square_unanswered', $e->getMessage() );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch( string $ref ) {
		list( $environment, $checkout_id ) = self::parse_ref( $ref );
		try {
			$terminal = $this->terminal( $environment );

			return self::observe( $terminal->get_checkout( $checkout_id ), $terminal );
		} catch ( SquareApiException $e ) {
			return self::unanswered_status( $e->getStatusCode() ) ? $this->indeterminate( 'square_unanswered', $e->getMessage() ) : self::provider_error( $e );
		} catch ( Throwable $e ) {
			return $this->indeterminate( 'square_unanswered', $e->getMessage() ); // Nothing observed; the next poll asks again.
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function cancel( string $ref ) {
		list( $environment, $checkout_id ) = self::parse_ref( $ref );
		try {
			$terminal = $this->terminal( $environment );
			try {
				$checkout = $terminal->cancel_checkout( $checkout_id );
			} catch ( SquareApiException $e ) {
				if ( self::unanswered_status( $e->getStatusCode() ) ) {
					throw $e;
				}
				// Refused: the checkout has already ended one way or the other. Only the read says which.
				$checkout = $terminal->get_checkout( $checkout_id );
			}
			$status = (string) ( $checkout['status'] ?? '' );
			if ( 'CANCELED' !== $status ) {
				return 'requested'; // CANCEL_REQUESTED while the buyer may still be paying; COMPLETED is money polling will settle.
			}
			// A CANCELED checkout whose payment was captured is money, and one whose payment Square has
			// approved but not completed may yet be; only the read of the payments can say.
			$payment = self::settled_payment( $checkout, $terminal );
			return $payment && 'failed' !== $payment['outcome'] ? 'requested' : 'final';
		} catch ( Throwable $e ) {
			return self::provider_error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function refund( array $row, int $refund_id, string $amount ) {
		$refund = wc_get_order( $refund_id );
		// A historical webview row names no order; the refund record does.
		$order = wc_get_order( (int) ( $row['order_id'] ?? ( $refund ? $refund->get_parent_id() : 0 ) ) );
		if ( ! $order || ! $refund instanceof \WC_Order_Refund ) {
			return new \WP_Error( 'wcpos_refund_not_found', __( 'Order or refund not found.', 'square-terminal-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$action = (string) ( $row['provider_refs']['action'] ?? '' );
		list( $environment ) = '' !== $action ? self::parse_ref( $action ) : array( Settings::get_environment(), '' );
		try {
			// Square refunds a payment, not a checkout: the transaction id Free kept (a historical
			// webview row has only that), else the captured payment of the leg's checkout.
			$payment_id = (string) ( $row['provider_refs']['transaction_id'] ?? '' );
			if ( '' === $payment_id && '' !== $action ) {
				$terminal   = $this->terminal( $environment );
				$payment    = self::captured_payment( $terminal->get_checkout( self::parse_ref( $action )[1] ), $terminal );
				$payment_id = (string) ( $payment['id'] ?? '' );
			}
			if ( '' === $payment_id ) {
				return new \WP_Error( 'wcpos_provider_error', __( 'No Square payment found for refund.', 'square-terminal-for-woocommerce' ), array( 'status' => 400 ) );
			}
			$request = array(
				'payment_id'  => $payment_id,
				'amount'      => Money_Units::minor( $amount, (string) $row['currency'] ),
				'currency'    => strtoupper( (string) $row['currency'] ),
				'reason'      => (string) $refund->get_reason(),
				'environment' => $environment,
			);
			// Saved before the POST: a replay of the record asks under the same key.
			$key    = Refund_Reask::attempt_key( $refund, $request );
			$result = $this->refund_once( $request, $key );
			$map    = array(
				'COMPLETED' => 'succeeded',
				'PENDING'   => 'pending',
				'REJECTED'  => 'failed',
				'FAILED'    => 'failed',
			);

			return array(
				'status'       => $map[ (string) $result['status'] ] ?? 'pending',
				'provider_ref' => ! empty( $result['id'] ) ? (string) $result['id'] : null,
			);
		} catch ( SquareApiException $e ) {
			// IDEMPOTENCY_KEY_REUSED: a refund under this record's key exists already (made by a request
			// whose answer was lost); its outcome is asked for, never a refusal.
			if ( ! self::unanswered_status( $e->getStatusCode() ) && 'IDEMPOTENCY_KEY_REUSED' !== self::error_code( $e ) ) {
				return self::provider_error( $e ); // Refused: nothing was made, and the merchant sees why.
			}
			return isset( $key ) ? $this->refund_unanswered( $order, $refund_id, $e ) : $this->indeterminate( 'square_unanswered', $e->getMessage() );
		} catch ( Throwable $e ) {
			// A read before the POST that went unanswered made nothing; an unanswered POST may have.
			return isset( $key ) ? $this->refund_unanswered( $order, $refund_id, $e ) : $this->indeterminate( 'square_unanswered', $e->getMessage() );
		}
	}

	/**
	 * The POST itself went unanswered: the refund may exist. An error here would make WooCommerce
	 * delete the record, and a later refund would be a new record under a new key: a second refund.
	 * The record stands as pending (Free counts it against the refundable amount), the order says so,
	 * and the re-ask replays the identical request under the saved key.
	 *
	 * @param \WC_Order $order     Parent order.
	 * @param int       $refund_id Refund record id.
	 * @param Throwable $e         What Square did not answer with.
	 */
	private function refund_unanswered( $order, int $refund_id, Throwable $e ): array {
		Logger::warning(
			'Square did not answer a refund POST; the refund record stays pending and is asked about again',
			array(
				'refund_id' => $refund_id,
				'detail' => $e->getMessage(),
			)
		);
		Refund_Reask::unanswered( $order, $refund_id );

		return array(
			'status'       => 'pending',
			'provider_ref' => null,
		);
	}

	/**
	 * One refund POST under a given key; Square dedupes a replay of the same request.
	 *
	 * @param array<string,mixed> $request payment_id, amount (minor units), currency, reason, environment.
	 * @param string              $key     Idempotency key.
	 * @return array<string,mixed> Normalized refund.
	 * @throws SquareApiException|Throwable When Square refuses or does not answer.
	 */
	public function refund_once( array $request, string $key ): array {
		return $this->terminal( (string) $request['environment'] )->refund_payment( array( 'idempotency_key' => $key ) + $request );
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify_webhook( \WP_REST_Request $request ) {
		$body      = (string) $request->get_body();
		$signature = (string) $request->get_header( self::SIGNATURE_HEADER );
		$key       = Settings::get_webhook_signature_key();
		if ( '' === $key || '' === $signature || '' === $body || ! self::signed( $body, $signature, $key ) ) {
			return new \WP_Error( 'square_webhook_signature', __( 'Invalid Square webhook signature.', 'square-terminal-for-woocommerce' ), array( 'status' => 401 ) );
		}
		$event       = json_decode( $body, true );
		$checkout_id = (string) ( $event['data']['object']['checkout']['id'] ?? '' );
		if ( 'terminal.checkout.updated' !== ( $event['type'] ?? '' ) || '' === $checkout_id ) {
			return new \WP_Error( 'square_webhook_ignored', __( 'Not a terminal checkout event.', 'square-terminal-for-woocommerce' ), array( 'status' => 200 ) );
		}
		$environment = Settings::get_environment();
		// An attempt Pro adopted from the old panel carries no ledger id in its reference; Pro's
		// adoption record names its row. A local read, before any call to Square.
		$adopted = function_exists( 'wcpos_pro_payment_id_for_action' ) ? wcpos_pro_payment_id_for_action( $this->provider(), self::ref( $environment, $checkout_id ) ) : null;
		try {
			// The authenticated read, not the posted body, is the evidence.
			$terminal = $this->terminal( $environment );
			$checkout = $terminal->get_checkout( $checkout_id );
			$payment  = self::settled_payment( $checkout, $terminal );
		} catch ( SquareApiException $e ) {
			if ( 404 === $e->getStatusCode() ) {
				return new \WP_Error( 'square_webhook_unknown_checkout', __( 'Unknown Square checkout.', 'square-terminal-for-woocommerce' ), array( 'status' => 404 ) );
			}

			return self::provider_error( $e );
		} catch ( Throwable $e ) {
			return self::provider_error( $e );
		}
		$payment_id = null !== $adopted ? $adopted : (string) ( $checkout['reference_id'] ?? '' );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $payment_id ) ) {
			return new \WP_Error( 'square_webhook_ignored', __( 'Not a WCPOS checkout.', 'square-terminal-for-woocommerce' ), array( 'status' => 200 ) );
		}

		return array(
			'payment_id' => strtolower( $payment_id ),
			'patch'      => self::webhook_patch( $checkout, $payment, $environment ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function diagnostics( \WC_Payment_Gateway $gateway ): array {
		return array(
			'log_source'            => 'square-terminal-for-woocommerce',
			'environment'           => Settings::get_environment(),
			'location_configured'   => '' !== Settings::get_location_id(),
			'webhook_key_configured' => '' !== Settings::get_webhook_signature_key(),
		);
	}

	/**
	 * Whether Square signed this body for Pro's route: HMAC-SHA256 over the notification URL followed
	 * by the raw body, base64, compared in constant time.
	 *
	 * @param string $body      Raw request body.
	 * @param string $signature Signature header.
	 * @param string $key       The subscription's signature key.
	 */
	private static function signed( string $body, string $signature, string $key ): bool {
		return hash_equals( base64_encode( hash_hmac( 'sha256', Settings::get_pro_webhook_url() . $body, $key, true ) ), $signature );
	}

	/**
	 * Pure projection of a checkout read, not a ledger transition.
	 *
	 * @param array<string,mixed>   $checkout Normalized checkout.
	 * @param SquareTerminalAdapter $terminal Terminal adapter for the payment reads.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function observe( array $checkout, SquareTerminalAdapter $terminal ) {
		$status = (string) ( $checkout['status'] ?? '' );
		$refs   = array(
			'square_checkout' => (string) $checkout['id'],
			'reader'          => (string) ( $checkout['device_id'] ?? '' ),
		);
		if ( in_array( $status, array( 'PENDING', 'IN_PROGRESS', 'CANCEL_REQUESTED' ), true ) ) {
			return array(
				'status'        => 'PENDING' === $status ? 'pending' : 'in_progress',
				'amount'        => null,
				'currency'      => null,
				'provider_refs' => $refs,
			);
		}
		$payment = self::settled_payment( $checkout, $terminal );
		if ( $payment && 'captured' === $payment['outcome'] ) {
			return array(
				'status'        => 'completed',
				'amount'        => Money_Units::major( (int) $payment['total_amount'], (string) $payment['total_currency'] ),
				'currency'      => (string) $payment['total_currency'],
				// `transaction_id` is what Free copies into the order's transaction id on capture, as the
				// old panel did, so refunds and "Payment via" read the same Square payment id.
				'provider_refs' => $refs + array(
					'transaction_id' => (string) $payment['id'],
					'square_payment' => (string) $payment['id'],
				),
				'receipt'       => self::receipt( $payment ),
			);
		}
		if ( 'COMPLETED' === $status || ( $payment && 'undecided' === $payment['outcome'] ) ) {
			// Square says paid but no payment reads as captured, or a payment of an ended checkout is
			// approved and not yet completed: nothing is settled, and nothing is lost, until it is.
			return new \WP_Error(
				'square_unanswered',
				__( 'Square has not finished the payment of this checkout yet.', 'square-terminal-for-woocommerce' ),
				array(
					'indeterminate' => true,
					'status' => 502,
				)
			);
		}
		if ( 'CANCELED' !== $status ) {
			return new \WP_Error( 'wcpos_provider_error', __( 'Unknown Square checkout status.', 'square-terminal-for-woocommerce' ), array( 'status' => 502 ) );
		}
		$reason = (string) ( $checkout['cancel_reason'] ?? '' );
		if ( $payment && 'failed' === $payment['outcome'] ) {
			// The checkout ended after a decline: a failure, not the cashier's cancellation.
			return array(
				'status' => 'failed',
				'amount' => null,
				'currency' => null,
				'provider_refs' => $refs,
				'failure_reason' => strtolower( ! empty( $payment['error_code'] ) ? (string) $payment['error_code'] : 'card_declined' ),
			);
		}
		if ( 'TIMED_OUT' === $reason ) {
			return array(
				'status' => 'expired',
				'amount' => null,
				'currency' => null,
				'provider_refs' => $refs,
			);
		}

		return array(
			'status' => 'cancelled',
			'amount' => null,
			'currency' => null,
			'provider_refs' => $refs,
		);
	}

	/**
	 * The ledger patch a verified checkout event carries; ledger vocabulary, not an observation.
	 *
	 * @param array<string,mixed>      $checkout    Normalized checkout.
	 * @param array<string,mixed>|null $payment     The settled payment, if any.
	 * @param string                   $environment Square environment.
	 */
	public static function webhook_patch( array $checkout, ?array $payment, string $environment ): array {
		$status = (string) ( $checkout['status'] ?? '' );
		// Derived from the observation, so a retried delivery that arrives after the state moved on is
		// not deduped against the earlier one; an identical replay still is.
		$patch = array( 'event_id' => implode( ':', array( $checkout['id'], $status, (string) ( $checkout['cancel_reason'] ?? '' ), (string) ( $payment['id'] ?? '' ), (string) ( $payment['outcome'] ?? '' ) ) ) );
		if ( $payment && 'captured' === $payment['outcome'] ) {
			// Settlement replaces the row's refs with the patch's, so a capture carries the complete set.
			return $patch + array(
				'status'        => 'captured',
				'amount'        => Money_Units::major( (int) $payment['total_amount'], (string) $payment['total_currency'] ),
				'currency'      => (string) $payment['total_currency'],
				'provider_refs' => array(
					'action'          => self::ref( $environment, (string) $checkout['id'] ),
					'reader'          => (string) ( $checkout['device_id'] ?? '' ),
					'transaction_id'  => (string) $payment['id'],
					'square_payment'  => (string) $payment['id'],
					'square_checkout' => (string) $checkout['id'],
				),
				'receipt'       => self::receipt( $payment ),
			);
		}
		if ( 'CANCELED' === $status && ! ( $payment && 'undecided' === $payment['outcome'] ) ) {
			$declined = $payment && 'failed' === $payment['outcome'];
			$patch['status'] = $declined || 'TIMED_OUT' === (string) ( $checkout['cancel_reason'] ?? '' ) ? 'failed' : 'voided';
			return $patch;
		}
		$patch['status'] = 'pending'; // PENDING, IN_PROGRESS, CANCEL_REQUESTED; an ended checkout whose payment is approved, or unreadable, waits for polling.
		return $patch;
	}

	/**
	 * The payment that decides a checkout: the captured one; else, once every payment has ended, a
	 * failed one (a decline) or none; else undecided, because a payment Square has approved but not
	 * yet completed may still become money after the checkout itself ended.
	 *
	 * @param array<string,mixed>   $checkout Normalized checkout.
	 * @param SquareTerminalAdapter $terminal Terminal adapter.
	 * @return array<string,mixed>|null Payment with an `outcome` of captured, failed or undecided; null when none was listed.
	 */
	private static function settled_payment( array $checkout, SquareTerminalAdapter $terminal ): ?array {
		if ( ! in_array( (string) ( $checkout['status'] ?? '' ), array( 'COMPLETED', 'CANCELED' ), true ) ) {
			return null;
		}
		$failed    = null;
		$undecided = null;
		foreach ( (array) ( $checkout['payment_ids'] ?? array() ) as $payment_id ) {
			$payment = $terminal->get_payment( (string) $payment_id );
			$status  = (string) ( $payment['status'] ?? '' );
			if ( 'COMPLETED' === $status || ( 'APPROVED' === $status && 'CAPTURED' === (string) ( $payment['card_status'] ?? '' ) ) ) {
				return $payment + array( 'outcome' => 'captured' );
			}
			if ( 'FAILED' === $status ) {
				$failed = $failed ?? $payment + array( 'outcome' => 'failed' );
			} elseif ( 'CANCELED' !== $status ) {
				$undecided = $undecided ?? $payment + array( 'outcome' => 'undecided' ); // APPROVED or PENDING: Square may still complete it.
			}
		}

		return $undecided ?? $failed;
	}

	/**
	 * The captured payment of a checkout, if any.
	 *
	 * @param array<string,mixed>   $checkout Normalized checkout.
	 * @param SquareTerminalAdapter $terminal Terminal adapter.
	 */
	private static function captured_payment( array $checkout, SquareTerminalAdapter $terminal ): ?array {
		$payment = self::settled_payment( $checkout, $terminal );

		return $payment && 'captured' === $payment['outcome'] ? $payment : null;
	}

	/**
	 * Receipt facts for the cashier.
	 *
	 * @param array<string,mixed> $payment Normalized payment.
	 */
	private static function receipt( array $payment ): array {
		return array_filter(
			array(
				'card_label'     => $payment['card_brand'] ?? null,
				'card_last4'     => $payment['card_last4'] ?? null,
				'read_method'    => $payment['entry_method'] ?? null,
				'auth_code'      => $payment['auth_code'] ?? null,
				'receipt_number' => $payment['receipt_number'] ?? null,
				'square_payment' => $payment['id'] ?? null,
			),
			static function ( $value ) {
				return is_string( $value ) && '' !== $value;
			}
		);
	}

	/**
	 * The row's expiry: Square cancels a PENDING checkout once the deadline passes.
	 *
	 * @param array<string,mixed> $checkout Normalized checkout.
	 */
	private static function expires_at( array $checkout ): string {
		$created = isset( $checkout['created_at'] ) ? strtotime( (string) $checkout['created_at'] ) : false;

		return gmdate( 'c', ( false === $created ? time() : $created ) + self::DEADLINE_SECONDS );
	}

	/**
	 * A terminal adapter over the token of one environment.
	 *
	 * @param string $environment Square environment.
	 */
	private function terminal( string $environment ): SquareTerminalAdapter {
		// No SDK retries: a lost answer is reported as unanswered at once, and Free's own replay of
		// the row (under the same idempotency key) is the retry.
		return new SquareTerminalAdapter( $this->factory->create( Settings::get_access_token_for( $environment ), $environment, array( 'maxRetries' => 0 ) ) );
	}

	/**
	 * Encode an action reference with the environment that made it.
	 *
	 * @param string $environment Square environment.
	 * @param string $checkout_id Checkout id.
	 */
	public static function ref( string $environment, string $checkout_id ): string {
		return $environment . ':' . $checkout_id;
	}

	/**
	 * Decode an action reference; one without an environment belongs to the current one.
	 *
	 * @param string $ref Action reference.
	 * @return array{0:string,1:string} Environment and checkout id.
	 */
	public static function parse_ref( string $ref ): array {
		if ( preg_match( '/^(sandbox|production):(.+)$/', $ref, $m ) ) {
			return array( $m[1], $m[2] );
		}

		return array( Settings::get_environment(), $ref );
	}

	/**
	 * Whether an HTTP status leaves the request's outcome unknown (Square may have acted on it).
	 *
	 * @param int $status HTTP status.
	 */
	public static function unanswered_status( int $status ): bool {
		return $status >= 500 || 429 === $status || 0 === $status;
	}

	/**
	 * The first Square error code of an API exception.
	 *
	 * @param SquareApiException $e Exception.
	 */
	public static function error_code( SquareApiException $e ): string {
		$errors = (array) $e->getErrors();
		$first  = $errors ? reset( $errors ) : null;

		return is_object( $first ) && method_exists( $first, 'getCode' ) ? (string) $first->getCode() : '';
	}

	/**
	 * A definitive provider error.
	 *
	 * @param Throwable $e Exception.
	 */
	private static function provider_error( Throwable $e ): \WP_Error {
		$detail = array( 'message' => $e->getMessage() );
		if ( $e instanceof SquareApiException ) {
			$detail['http_status'] = $e->getStatusCode();
			$detail['error_code']  = self::error_code( $e );
		}

		return new \WP_Error(
			'wcpos_provider_error',
			$e->getMessage(),
			array(
				'status' => 502,
				'detail' => $detail,
			)
		);
	}
}
