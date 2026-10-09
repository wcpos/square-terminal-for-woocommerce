<?php
/**
 * Extension-owned fixture for Pro's provider conformance suite: the real gateway, adapter and
 * SDK client, with only Square's HTTP transport faked.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Server\Square_Server_Provider;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareOAuth;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;
use WCPOS\WooCommercePOSPro\API\V2\Payments_Webhook_Controller;
use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;
use WCPOS\WooCommercePOSPro\Payments\Server\Server_Providers;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;

require_once __DIR__ . '/Fake_Square_Transport.php';
require_once __DIR__ . '/Recording_Square_Provider.php';

/**
 * Capabilities claimed beyond the floor, and why:
 * - `cancel`, `cancel_final`, `cancel_requested_then_completed`: Square cancels a PENDING checkout
 *   at once (CANCELED, SELLER_CANCELED); one the buyer is paying becomes CANCEL_REQUESTED and the
 *   payment can still win, so the adapter reports `requested` and polling decides.
 * - `webhook`: Square signs every delivery (HMAC-SHA256 over the notification URL and body), so a
 *   verified failure is reported as such and a stale one meets Free's transition guard.
 * - `refund`, `partial_refund`: a refund answers PENDING, COMPLETED, REJECTED or FAILED, so
 *   `refund_pending` is a real state.
 * - `expiry`: Square cancels an unpaid checkout itself (TIMED_OUT) at the deadline the create names.
 * - `test_live_isolation`: a reference carries its environment; a sandbox checkout is polled with the
 *   sandbox token after the gateway moves to production.
 * - `legacy_adoption`, `historical_webview_refund`: the webhook resolves an adopted checkout through
 *   Pro's record before reading the reference id, and a refund falls back to the transaction id.
 * Not claimed: `cancel_unsupported`, `manual_capture` (Terminal checkouts autocomplete), `prompt`,
 * `non_idempotent_create` (creates are idempotent on the key), `webhook_money_only` (deliveries are
 * signed), `refund_synchronous` (refunds may stay PENDING).
 */
final class Square_Conformance_Fixture implements Conformance_Fixture {
	/**
	 * Scripted Square.
	 *
	 * @var Fake_Square_Transport
	 */
	public $transport;

	/** The armed scenario. */
	public $scenario = '';

	private $registry_property;
	private $old_registry;
	private $old_gateways;
	private $old_options;
	private $old_connection;
	private $old_currency;
	private $calls   = array();
	private $aliases = array();

	public function gateway_id(): string {
		return Gateway::ID;
	}

	public function install(): void {
		$this->old_options    = get_option( 'woocommerce_' . Gateway::ID . '_settings', array() );
		$this->old_connection = get_option( SquareOAuth::OPTION, array() );
		$this->old_currency   = get_option( 'woocommerce_currency' );
		update_option( 'woocommerce_currency', 'EUR' );
		delete_option( SquareOAuth::OPTION ); // A stored OAuth connection would outrank the tokens below.
		$this->environment( 'sandbox' );
		$this->transport                    = new Fake_Square_Transport();
		Recording_Square_Provider::$fixture = $this;
		add_filter( 'sqtwc_square_http_client', array( $this, 'http_client' ) );
		$this->registry_property = new \ReflectionProperty( Server_Providers::class, 'instance' );
		$this->registry_property->setAccessible( true );
		$this->old_registry = $this->registry_property->getValue();
		$this->registry_property->setValue( null, null );
		wcpos_pro_register_server_provider( Gateway::ID, Recording_Square_Provider::class );
		$this->old_gateways = WC()->payment_gateways;
		add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );
		WC()->payment_gateways = new \WC_Payment_Gateways();
		Reader_Curation::forget( Gateway::ID );
		delete_option( 'wcpos_pro_readers_lkg_' . Gateway::ID );
	}

	public function uninstall(): void {
		remove_filter( 'sqtwc_square_http_client', array( $this, 'http_client' ) );
		Recording_Square_Provider::$fixture = null;
		Reader_Curation::forget( Gateway::ID );
		delete_option( 'wcpos_pro_readers_lkg_' . Gateway::ID );
		$this->registry_property->setValue( null, $this->old_registry );
		WC()->payment_gateways = $this->old_gateways;
		update_option( 'woocommerce_' . Gateway::ID . '_settings', $this->old_options );
		update_option( SquareOAuth::OPTION, $this->old_connection );
		update_option( 'woocommerce_currency', $this->old_currency );
		Settings::reset_cache();
	}

	/** The SDK's PSR-18 client is the scripted Square. */
	public function http_client() {
		return $this->transport;
	}

	/** Point the gateway at one Square environment; both environments keep their tokens. */
	public function environment( string $environment ): void {
		update_option(
			'woocommerce_' . Gateway::ID . '_settings',
			array(
				'enabled'                 => 'yes',
				'environment'             => $environment,
				'sandbox_access_token'    => Fake_Square_Transport::TOKENS['connect.squareupsandbox.com'],
				'production_access_token' => Fake_Square_Transport::TOKENS['connect.squareup.com'],
				'location_id'             => Fake_Square_Transport::LOCATION,
				'webhook_signature_key'   => 'conformance-signature-key',
				'collection_method'       => 'terminal',
			)
		);
		Settings::reset_cache(); // Settings memoizes per request; the suite changes them mid-request.
	}

	public function supports( string $capability ): bool {
		return in_array( $capability, array( 'cancel', 'cancel_final', 'cancel_requested_then_completed', 'webhook', 'refund', 'partial_refund', 'expiry', 'test_live_isolation', 'legacy_adoption', 'historical_webview_refund' ), true );
	}

	public function script( string $scenario ): void {
		// Scenario => states successive fetches observe, refund answer, cancel behaviour.
		$scripts = array(
			'create_ok'                       => array( array( 'PENDING' ) ),
			'create_indeterminate'            => array( array( 'PENDING' ) ),
			'webhook_replay'                  => array( array( 'PENDING' ) ),
			'webhook_out_of_order'            => array( array( 'PENDING' ) ),
			'test_live_isolation'             => array( array( 'PENDING' ) ),
			'pending_then_completed'          => array( array( 'IN_PROGRESS', 'COMPLETED' ) ),
			'declined'                        => array( array( 'CANCELED:declined' ) ),
			'cancel_requested_then_cancelled' => array( array( 'CANCELED:seller' ), 'COMPLETED', 'requested' ),
			'cancel_requested_then_completed' => array( array( 'COMPLETED' ), 'COMPLETED', 'requested' ),
			'cancel_final'                    => array( array( 'PENDING' ), 'COMPLETED', 'final' ),
			'amount_mismatch'                 => array( array( 'COMPLETED:short' ) ),
			'currency_mismatch'               => array( array( 'COMPLETED:usd' ) ),
			'expired'                         => array( array( 'CANCELED:timeout' ) ),
			'refund_ok'                       => array( array( 'COMPLETED' ), 'COMPLETED' ),
			'refund_pending'                  => array( array( 'COMPLETED' ), 'PENDING' ),
			'refund_failed'                   => array( array( 'COMPLETED' ), 'REJECTED' ),
		);
		if ( ! isset( $scripts[ $scenario ] ) ) {
			throw new \OutOfBoundsException( 'Unknown conformance scenario: ' . $scenario );
		}
		$this->scenario = $scenario;
		$this->transport->script( $scenario, ...$scripts[ $scenario ] );
	}

	public function webhook_request( string $event ): \WP_REST_Request {
		$tampered = 'tampered' === $event;
		$event    = $tampered ? 'completed' : $event;
		$states   = array( 'completed' => 'COMPLETED', 'failed' => 'CANCELED:declined', 'cancelled' => 'CANCELED:seller' );
		if ( ! isset( $states[ $event ] ) ) {
			throw new \OutOfBoundsException( 'Unknown webhook event: ' . $event );
		}
		$id = (string) $this->transport->current;
		$this->transport->observe( $id, $states[ $event ] );
		$body = $this->transport->event_body( $id );
		// Square signs HMAC-SHA256 over the registered notification URL followed by the raw body.
		$key       = $tampered ? 'not-the-signature-key' : 'conformance-signature-key';
		$signature = base64_encode( hash_hmac( 'sha256', Settings::get_pro_webhook_url() . $body, $key, true ) );
		$request   = new \WP_REST_Request( 'POST', Payments_Webhook_Controller::ROUTE );
		$request->set_query_params( array( 'provider' => Square_Server_Provider::PROVIDER ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( Square_Server_Provider::SIGNATURE_HEADER, $signature );
		$request->set_body( $body );
		return $request;
	}

	/** One stable alias per Square id; no id (a refused create) is `none`. */
	public function alias( string $ref, string $kind = 'action' ): string {
		if ( '' === $ref ) {
			return 'none';
		}
		if ( ! isset( $this->aliases[ $ref ] ) ) {
			$this->aliases[ $ref ] = $kind . '_' . ( count( $this->aliases ) + 1 );
		}
		return $this->aliases[ $ref ];
	}

	/** Append an adapter operation to the transcript. */
	public function record( string $op, string $ref, string $details ): void {
		$this->calls[] = array( 'op' => $op, 'request' => 'action=' . $this->alias( $ref ) . ' ' . $details );
	}

	public function transcript(): array {
		return $this->calls;
	}

	public function reset_transcript(): void {
		$this->calls   = array();
		$this->aliases = array();
	}

	public function transcript_dir(): ?string {
		return __DIR__ . '/transcripts';
	}
}
