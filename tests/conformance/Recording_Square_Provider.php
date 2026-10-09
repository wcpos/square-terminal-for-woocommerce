<?php
/**
 * The real adapter with each operation recorded on the fixture's transcript: the lessons count
 * what Pro asked the adapter to do, not the wire messages behind it.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\SquareTerminal\Server\Square_Server_Provider;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

/**
 * Registered in place of Square_Server_Provider for the suite. The adapter's own client factory is
 * used; the scripted Square sits behind the `sqtwc_square_http_client` filter. Every op records
 * the mode (test = sandbox, live = production) the credentials in use belong to.
 */
final class Recording_Square_Provider extends Square_Server_Provider {
	/**
	 * The installed fixture; set before Pro constructs the adapter.
	 *
	 * @var Square_Conformance_Fixture|null
	 */
	public static $fixture;

	/**
	 * {@inheritDoc}
	 *
	 * Recorded once the call has been made: the action a create names is the checkout Square made
	 * for the row's idempotency key (the fake knows it even when the response was lost), which does
	 * not exist before the call. A create Square refused names no action.
	 */
	public function create_reader_action( array $row, string $reader_id ) {
		$mode   = self::mode();
		$result = parent::create_reader_action( $row, $reader_id );
		self::$fixture->record( 'create', self::$fixture->transport->checkout_for_key( (string) $row['id'] ) ?? '', 'amount=' . $row['amount'] . ' currency=' . $row['currency'] . ' reader=' . $reader_id . ' mode=' . $mode );
		if ( 'test_live_isolation' === self::$fixture->scenario ) {
			self::$fixture->environment( 'production' ); // The merchant goes live; the sandbox checkout stays sandbox.
		}
		return $result;
	}

	/** {@inheritDoc} */
	public function fetch( string $ref ) {
		list( $environment, $checkout_id ) = self::parse_ref( $ref );
		self::$fixture->record( 'fetch', $checkout_id, 'mode=' . self::mode_of( $environment ) );
		self::$fixture->transport->advance( $checkout_id );
		return parent::fetch( $ref );
	}

	/** {@inheritDoc} */
	public function cancel( string $ref ) {
		list( $environment, $checkout_id ) = self::parse_ref( $ref );
		self::$fixture->record( 'cancel', $checkout_id, 'mode=' . self::mode_of( $environment ) );
		return parent::cancel( $ref );
	}

	/** {@inheritDoc} */
	public function refund( array $row, int $refund_id, string $amount ) {
		$payment = (string) ( $row['provider_refs']['transaction_id'] ?? '' );
		$action  = (string) ( $row['provider_refs']['action'] ?? '' );
		list( $environment, $checkout_id ) = '' !== $action ? self::parse_ref( $action ) : array( Settings::get_environment(), $payment );
		self::$fixture->record( 'refund', $checkout_id, 'amount=' . $amount . ' currency=' . $row['currency'] . ' mode=' . self::mode_of( $environment ) . ' transaction_id=' . self::$fixture->alias( $payment, 'payment' ) );
		return parent::refund( $row, $refund_id, $amount );
	}

	/** {@inheritDoc} */
	public function verify_webhook( \WP_REST_Request $request ) {
		$event = json_decode( (string) $request->get_body(), true );
		self::$fixture->record( 'webhook', (string) ( $event['data']['object']['checkout']['id'] ?? '' ), 'event=' . (string) ( $event['type'] ?? '' ) );
		return parent::verify_webhook( $request );
	}

	/** The mode the configured credentials belong to. */
	private static function mode(): string {
		return self::mode_of( Settings::get_environment() );
	}

	private static function mode_of( string $environment ): string {
		return 'production' === $environment ? 'live' : 'test';
	}
}
