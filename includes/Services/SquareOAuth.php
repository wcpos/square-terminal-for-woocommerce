<?php
/**
 * Square OAuth (PKCE) connection handling.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use RuntimeException;
use WCPOS\WooCommercePOS\SquareTerminal\Logger;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\OAuth\Requests\ObtainTokenRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\SquareClient;

/**
 * Connects a site to Square using the PKCE authorization-code flow.
 *
 * PKCE is used so no application secret is needed to exchange the code. This
 * site performs the exchange itself and is the only holder of the resulting
 * tokens — the wcpos.com endpoint exists solely because Square permits one
 * registered redirect URL per application, and it never sees a token.
 *
 * PKCE refresh tokens are single-use and expire 90 days after issue. Rotation
 * is therefore the failure point to design around: the connection is refreshed
 * well ahead of expiry, and a rotation that cannot be completed is reported as
 * "reconnect required" rather than retried against a spent token.
 */
final class SquareOAuth {
	/** Option holding the current connection. */
	public const OPTION = 'sqtwc_oauth_connection';

	/** Transient holding an in-flight authorization attempt. */
	private const PENDING_TRANSIENT = 'sqtwc_oauth_pending';

	/** How long an authorization attempt may stay in flight, in seconds. */
	private const PENDING_TTL = 900;

	/** Refresh this many seconds before the access token expires. */
	private const REFRESH_MARGIN = 604800;

	/**
	 * Square SDK client factory.
	 *
	 * @var SquareClientFactory
	 */
	private SquareClientFactory $clients;

	/**
	 * Constructor.
	 *
	 * @param SquareClientFactory|null $clients Optional injected factory.
	 */
	public function __construct( ?SquareClientFactory $clients = null ) {
		$this->clients = $clients ?? new SquareClientFactory();
	}

	/**
	 * Return the URL of the wcpos.com endpoint that brokers the redirect.
	 *
	 * Square allows a single registered redirect URL per application, so a
	 * fixed endpoint receives the callback and forwards it to this site. It
	 * holds no secret and never receives a token.
	 */
	public static function broker_url(): string {
		/**
		 * Filter the Square connect endpoint.
		 *
		 * @param string $url Endpoint base URL.
		 */
		return (string) apply_filters( 'sqtwc_oauth_broker_url', 'https://wcpos.com/api/square' );
	}

	/**
	 * Generate a PKCE code verifier.
	 *
	 * RFC 7636 permits 43-128 unreserved characters; 64 bytes of randomness
	 * base64url-encoded sits comfortably inside that range.
	 */
	public static function create_verifier(): string {
		return self::base64url( random_bytes( 48 ) );
	}

	/**
	 * Derive the S256 code challenge for a verifier.
	 *
	 * @param string $verifier PKCE code verifier.
	 */
	public static function challenge_for( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Whether this site currently holds an OAuth connection.
	 */
	public static function is_connected(): bool {
		$connection = self::connection();

		return '' !== (string) ( $connection['access_token'] ?? '' );
	}

	/**
	 * Return the stored connection.
	 *
	 * @return array<string,mixed>
	 */
	public static function connection(): array {
		$connection = get_option( self::OPTION, array() );

		return is_array( $connection ) ? $connection : array();
	}

	/**
	 * Whether the stored access token is due for rotation.
	 */
	public static function needs_refresh(): bool {
		$connection = self::connection();
		if ( '' === (string) ( $connection['refresh_token'] ?? '' ) ) {
			return false;
		}

		$expires_at = (int) ( $connection['expires_at'] ?? 0 );

		return 0 === $expires_at || ( $expires_at - self::REFRESH_MARGIN ) <= time();
	}

	/**
	 * Begin an authorization attempt and return the URL to send the admin to.
	 *
	 * @param string $return_url Admin URL Square's response is forwarded back to.
	 * @param string $environment Square environment to authorize.
	 * @return string Square authorization URL.
	 * @throws RuntimeException When the endpoint cannot supply an authorization URL.
	 */
	public function begin( string $return_url, string $environment ): string {
		$verifier  = self::create_verifier();
		$challenge = self::challenge_for( $verifier );

		$response = wp_remote_post(
			self::broker_url() . '/session',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'callback_url'  => $return_url,
						'code_challenge' => $challenge,
						'environment'   => $environment,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Could not reach the Square connect service.' );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$url  = is_array( $body ) ? (string) ( $body['authorize_url'] ?? '' ) : '';
		$state = is_array( $body ) ? (string) ( $body['state'] ?? '' ) : '';

		if ( '' === $url || '' === $state ) {
			throw new RuntimeException( 'The Square connect service returned no authorization URL.' );
		}

		// The verifier never leaves this site. Without it the authorization code
		// is unusable, so the forwarding endpoint cannot exchange it even though
		// the code passes through.
		set_transient(
			self::PENDING_TRANSIENT . '_' . hash( 'sha256', $state ),
			array(
				'verifier'    => $verifier,
				'state'       => $state,
				'environment' => $environment,
			),
			self::PENDING_TTL
		);

		return $url;
	}

	/**
	 * Complete an authorization attempt.
	 *
	 * @param string $code  Authorization code from Square.
	 * @param string $state State echoed back by Square.
	 * @throws RuntimeException When the attempt cannot be completed.
	 */
	public function complete( string $code, string $state ): void {
		$pending = get_transient( self::PENDING_TRANSIENT . '_' . hash( 'sha256', $state ) );
		if ( ! is_array( $pending ) || '' === (string) ( $pending['verifier'] ?? '' ) ) {
			throw new RuntimeException( 'This connection attempt expired. Start again.' );
		}

		if ( ! hash_equals( (string) $pending['state'], $state ) ) {
			throw new RuntimeException( 'The connection response did not match this site.' );
		}

		delete_transient( self::PENDING_TRANSIENT . '_' . hash( 'sha256', $state ) );

		$environment = (string) ( $pending['environment'] ?? 'production' );
		$client      = $this->clients->create( '', $environment );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Square SDK property name.
		$response = $client->oAuth->obtainToken(
			new ObtainTokenRequest(
				array(
					'clientId'     => self::client_id( $environment ),
					'grantType'    => 'authorization_code',
					'code'         => $code,
					'codeVerifier' => (string) $pending['verifier'],
				)
			)
		);

		$this->store( $response, $environment );
	}

	/**
	 * Rotate the access token using the stored single-use refresh token.
	 *
	 * @throws RuntimeException When there is nothing to refresh.
	 */
	public function refresh(): void {
		$connection    = self::connection();
		$refresh_token = (string) ( $connection['refresh_token'] ?? '' );
		if ( '' === $refresh_token ) {
			throw new RuntimeException( 'This site is not connected to Square.' );
		}

		$environment = (string) ( $connection['environment'] ?? 'production' );
		$client      = $this->clients->create( '', $environment );
		$connection['access_token']       = '';
		$connection['refresh_token']      = '';
		$connection['reconnect_required'] = true;
		update_option( self::OPTION, $connection );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Square SDK property name.
		$response = $client->oAuth->obtainToken(
			new ObtainTokenRequest(
				array(
					'clientId'     => self::client_id( $environment ),
					'grantType'    => 'refresh_token',
					'refreshToken' => $refresh_token,
				)
			)
		);

		$this->store( $response, $environment );
	}

	/**
	 * Forget the stored connection.
	 *
	 * Square's RevokeToken requires the application secret, which this site
	 * deliberately does not hold, so the local credentials are discarded and the
	 * access token is left to expire.
	 */
	public function disconnect(): void {
		delete_option( self::OPTION );
		delete_transient( self::PENDING_TRANSIENT );
		Logger::info( 'Square OAuth connection removed' );
	}

	/**
	 * Return the WCPOS Square application ID.
	 *
	 * A client ID is public information; PKCE is what makes it safe to ship.
	 */
	public static function client_id( ?string $environment = null ): string {
		$environment = null === $environment ? Settings::get_environment() : $environment;
		$client_id   = 'production' === $environment
			? ( defined( 'SQTWC_SQUARE_PRODUCTION_CLIENT_ID' ) ? SQTWC_SQUARE_PRODUCTION_CLIENT_ID : '' )
			: ( defined( 'SQTWC_SQUARE_SANDBOX_CLIENT_ID' ) ? SQTWC_SQUARE_SANDBOX_CLIENT_ID : '' );

		/**
		 * Filter the WCPOS Square application ID.
		 *
		 * @param string $client_id Square application ID.
		 * @param string $environment Square environment.
		 */
		return (string) apply_filters( 'sqtwc_oauth_client_id', $client_id, $environment );
	}

	/**
	 * Persist a token response.
	 *
	 * @param object $response    Square ObtainToken response.
	 * @param string $environment Square environment.
	 * @throws RuntimeException When Square returns no usable token.
	 */
	private function store( object $response, string $environment ): void {
		$access_token = method_exists( $response, 'getAccessToken' ) ? (string) $response->getAccessToken() : '';
		if ( '' === $access_token ) {
			throw new RuntimeException( 'Square returned no access token.' );
		}

		$refresh_token = method_exists( $response, 'getRefreshToken' ) ? (string) $response->getRefreshToken() : '';
		if ( '' === $refresh_token ) {
			throw new RuntimeException( 'Square returned no refresh token.' );
		}
		$merchant_id   = method_exists( $response, 'getMerchantId' ) ? (string) $response->getMerchantId() : '';
		$expires_at    = 0;

		if ( method_exists( $response, 'getExpiresAt' ) ) {
			$parsed     = strtotime( (string) $response->getExpiresAt() );
			$expires_at = false === $parsed ? 0 : (int) $parsed;
		}

		update_option(
			self::OPTION,
			array(
				'access_token'  => $access_token,
				'refresh_token' => $refresh_token,
				'expires_at'    => $expires_at,
				'merchant_id'   => $merchant_id,
				'environment'   => $environment,
				'connected_at'  => time(),
			)
		);

		Logger::info(
			'Square OAuth connection stored',
			array(
				'environment' => $environment,
				'merchant_id' => $merchant_id,
				'expires_at'  => $expires_at,
			)
		);
	}

	/**
	 * Base64url-encode without padding.
	 *
	 * @param string $bytes Raw bytes.
	 */
	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
