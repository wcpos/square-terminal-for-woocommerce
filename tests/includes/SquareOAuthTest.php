<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareClientFactory;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareOAuth;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\ObtainTokenResponse;

final class OAuthTestClientFactory {
	public static ?object $response = null;
	public static ?RuntimeException $exception = null;
	public static array $requests = array();
	public static array $environments = array();

	public function create( ?string $access_token = null, ?string $environment = null ): object {
		unset( $access_token );
		self::$environments[] = $environment;

		return (object) array( 'oAuth' => new OAuthTestApi() );
	}
}

final class OAuthTestApi {
	public function obtainToken( object $request ): object {
		OAuthTestClientFactory::$requests[] = $request;
		if ( OAuthTestClientFactory::$exception ) {
			throw OAuthTestClientFactory::$exception;
		}

		return OAuthTestClientFactory::$response;
	}
}

final class SquareOAuthTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_options']    = array();
		$GLOBALS['sqtwc_transients'] = array();
	}

	public function test_verifier_satisfies_the_pkce_character_and_length_rules(): void {
		$verifier = SquareOAuth::create_verifier();

		// RFC 7636: 43-128 characters from the unreserved set.
		self::assertGreaterThanOrEqual( 43, strlen( $verifier ) );
		self::assertLessThanOrEqual( 128, strlen( $verifier ) );
		self::assertMatchesRegularExpression( '/^[A-Za-z0-9\-._~]+$/', $verifier );
	}

	public function test_verifiers_are_not_predictable(): void {
		self::assertNotSame( SquareOAuth::create_verifier(), SquareOAuth::create_verifier() );
	}

	public function test_challenge_is_the_unpadded_base64url_sha256_of_the_verifier(): void {
		$verifier  = 'test-verifier-value';
		$expected  = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$challenge = SquareOAuth::challenge_for( $verifier );

		self::assertSame( $expected, $challenge );
		self::assertStringNotContainsString( '=', $challenge );
		self::assertStringNotContainsString( '+', $challenge );
		self::assertStringNotContainsString( '/', $challenge );
	}

	public function test_challenge_is_stable_for_a_given_verifier(): void {
		$verifier = SquareOAuth::create_verifier();

		self::assertSame( SquareOAuth::challenge_for( $verifier ), SquareOAuth::challenge_for( $verifier ) );
	}

	public function test_not_connected_by_default(): void {
		self::assertFalse( SquareOAuth::is_connected() );
		self::assertSame( array(), SquareOAuth::connection() );
	}

	public function test_connected_when_an_access_token_is_stored(): void {
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array( 'access_token' => 'token' );

		self::assertTrue( SquareOAuth::is_connected() );
	}

	public function test_a_corrupt_connection_option_does_not_read_as_connected(): void {
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = 'not-an-array';

		self::assertSame( array(), SquareOAuth::connection() );
		self::assertFalse( SquareOAuth::is_connected() );
	}

	public function test_refresh_is_not_attempted_without_a_refresh_token(): void {
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array( 'access_token' => 'token' );

		self::assertFalse( SquareOAuth::needs_refresh() );
	}

	public function test_refresh_is_due_well_before_expiry(): void {
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array(
			'access_token'  => 'token',
			'refresh_token' => 'refresh',
			// Three days out, inside the one-week rotation margin.
			'expires_at'    => time() + ( 3 * DAY_IN_SECONDS ),
		);

		self::assertTrue( SquareOAuth::needs_refresh() );
	}

	public function test_refresh_is_not_due_when_the_token_is_fresh(): void {
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array(
			'access_token'  => 'token',
			'refresh_token' => 'refresh',
			'expires_at'    => time() + ( 25 * DAY_IN_SECONDS ),
		);

		self::assertFalse( SquareOAuth::needs_refresh() );
	}

	public function test_a_connection_with_no_expiry_is_treated_as_due(): void {
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array(
			'access_token'  => 'token',
			'refresh_token' => 'refresh',
			'expires_at'    => 0,
		);

		self::assertTrue( SquareOAuth::needs_refresh() );
	}

	public function test_completing_without_a_pending_attempt_is_refused(): void {
		$this->expectException( RuntimeException::class );

		( new SquareOAuth() )->complete( 'code', 'state' );
	}

	public function test_a_mismatched_state_is_refused_and_the_code_is_never_exchanged(): void {
		set_transient(
			'sqtwc_oauth_pending_' . hash( 'sha256', 'attacker-state' ),
			array(
				'verifier'    => 'verifier',
				'state'       => 'expected-state',
				'environment' => 'sandbox',
			),
			900
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'The connection response did not match this site.' );

		( new SquareOAuth() )->complete( 'code', 'attacker-state' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_concurrent_attempts_preserve_the_first_callback_and_store_its_connection(): void {
		class_alias( OAuthTestClientFactory::class, SquareClientFactory::class );
		OAuthTestClientFactory::$response = new ObtainTokenResponse(
			array(
				'accessToken'           => 'access-one',
				'tokenType'             => 'bearer',
				'expiresAt'             => '2030-01-02T03:04:05Z',
				'merchantId'            => 'merchant-one',
				'subscriptionId'        => null,
				'planId'                => null,
				'idToken'               => null,
				'refreshToken'          => 'refresh-one',
				'shortLived'            => false,
				'errors'                => null,
				'refreshTokenExpiresAt' => '2030-04-02T03:04:05Z',
			)
		);
		$oauth                           = new SquareOAuth( new OAuthTestClientFactory() );
		$GLOBALS['sqtwc_remote_post_response'] = array(
			'body' => wp_json_encode( array( 'authorize_url' => 'https://square.test/one', 'state' => 'state-one' ) ),
		);
		$oauth->begin( 'https://store.test/callback', 'sandbox' );
		$GLOBALS['sqtwc_remote_post_response'] = array(
			'body' => wp_json_encode( array( 'authorize_url' => 'https://square.test/two', 'state' => 'state-two' ) ),
		);
		$oauth->begin( 'https://store.test/callback', 'production' );

		$oauth->complete( 'authorization-code', 'state-one' );

		$connection = SquareOAuth::connection();
		self::assertSame( 'access-one', $connection['access_token'] );
		self::assertSame( 'refresh-one', $connection['refresh_token'] );
		self::assertSame( strtotime( '2030-01-02T03:04:05Z' ), $connection['expires_at'] );
		self::assertSame( 'merchant-one', $connection['merchant_id'] );
		self::assertSame( 'sandbox', $connection['environment'] );
		self::assertFalse( get_transient( 'sqtwc_oauth_pending_' . hash( 'sha256', 'state-one' ) ) );
		self::assertIsArray( get_transient( 'sqtwc_oauth_pending_' . hash( 'sha256', 'state-two' ) ) );
	}

	public function test_disconnect_clears_the_connection_and_any_pending_attempt(): void {
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array( 'access_token' => 'token' );
		set_transient( 'sqtwc_oauth_pending', array( 'verifier' => 'v', 'state' => 's' ), 900 );

		( new SquareOAuth() )->disconnect();

		self::assertFalse( SquareOAuth::is_connected() );
		self::assertFalse( get_transient( 'sqtwc_oauth_pending' ) );
	}

	public function test_refresh_without_a_connection_is_refused(): void {
		$this->expectException( RuntimeException::class );

		( new SquareOAuth() )->refresh();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_refresh_marks_the_connection_for_reconnection_before_the_request(): void {
		class_alias( OAuthTestClientFactory::class, SquareClientFactory::class );
		OAuthTestClientFactory::$exception = new RuntimeException( 'response lost' );
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array(
			'access_token'  => 'old-access',
			'refresh_token' => 'single-use-refresh',
			'environment'   => 'production',
		);

		try {
			( new SquareOAuth( new OAuthTestClientFactory() ) )->refresh();
			self::fail( 'Expected the refresh request to fail.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'response lost', $exception->getMessage() );
		}

		$connection = SquareOAuth::connection();
		self::assertSame( '', $connection['access_token'] );
		self::assertSame( '', $connection['refresh_token'] );
		self::assertTrue( $connection['reconnect_required'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_refresh_rejects_a_blank_rotated_refresh_token(): void {
		class_alias( OAuthTestClientFactory::class, SquareClientFactory::class );
		OAuthTestClientFactory::$response = new ObtainTokenResponse(
			array(
				'accessToken'           => 'new-access',
				'tokenType'             => 'bearer',
				'expiresAt'             => '2030-01-02T03:04:05Z',
				'merchantId'            => 'merchant',
				'subscriptionId'        => null,
				'planId'                => null,
				'idToken'               => null,
				'refreshToken'          => '',
				'shortLived'            => false,
				'errors'                => null,
				'refreshTokenExpiresAt' => null,
			)
		);
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array(
			'access_token'  => 'old-access',
			'refresh_token' => 'single-use-refresh',
			'environment'   => 'sandbox',
		);

		$exception = null;
		try {
			( new SquareOAuth( new OAuthTestClientFactory() ) )->refresh();
		} catch ( RuntimeException $caught ) {
			$exception = $caught;
		}

		self::assertInstanceOf( RuntimeException::class, $exception );
		self::assertSame( 'Square returned no refresh token.', $exception->getMessage() );
		self::assertTrue( SquareOAuth::connection()['reconnect_required'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_refresh_uses_the_client_id_for_the_connection_environment(): void {
		define( 'SQTWC_SQUARE_SANDBOX_CLIENT_ID', 'sandbox-client-id' );
		define( 'SQTWC_SQUARE_PRODUCTION_CLIENT_ID', 'production-client-id' );
		class_alias( OAuthTestClientFactory::class, SquareClientFactory::class );
		OAuthTestClientFactory::$response = new ObtainTokenResponse(
			array(
				'accessToken'           => 'rotated-access',
				'tokenType'             => 'bearer',
				'expiresAt'             => '2030-01-02T03:04:05Z',
				'merchantId'            => 'merchant',
				'subscriptionId'        => null,
				'planId'                => null,
				'idToken'               => null,
				'refreshToken'          => 'rotated-refresh',
				'shortLived'            => false,
				'errors'                => null,
				'refreshTokenExpiresAt' => '2030-04-02T03:04:05Z',
			)
		);
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array(
			'access_token'  => 'old-access',
			'refresh_token' => 'single-use-refresh',
			'environment'   => 'sandbox',
		);

		( new SquareOAuth( new OAuthTestClientFactory() ) )->refresh();

		self::assertSame( 'sandbox-client-id', OAuthTestClientFactory::$requests[0]->getClientId() );
		self::assertSame( array( 'sandbox' ), OAuthTestClientFactory::$environments );
		self::assertSame( 'rotated-access', SquareOAuth::connection()['access_token'] );
		self::assertSame( 'rotated-refresh', SquareOAuth::connection()['refresh_token'] );
	}

	public function test_an_oauth_token_is_used_when_the_environment_matches(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment'             => 'production',
			'production_access_token' => 'manual-token',
		);
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ]         = array(
			'access_token' => 'oauth-token',
			'environment'  => 'production',
		);
		Settings::reset_cache_for_tests();

		self::assertSame( 'oauth-token', Settings::get_access_token() );
	}

	public function test_a_sandbox_connection_never_authorizes_production_requests(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment'             => 'production',
			'production_access_token' => 'manual-token',
		);
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ]         = array(
			'access_token' => 'sandbox-oauth-token',
			'environment'  => 'sandbox',
		);
		Settings::reset_cache_for_tests();

		self::assertSame( 'manual-token', Settings::get_access_token() );
	}

	public function test_manual_tokens_are_untouched_without_a_connection(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'environment'          => 'sandbox',
			'sandbox_access_token' => 'manual-sandbox',
		);
		Settings::reset_cache_for_tests();

		self::assertSame( 'manual-sandbox', Settings::get_access_token() );
	}
}
