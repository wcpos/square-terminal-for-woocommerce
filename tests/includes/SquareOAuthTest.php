<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareOAuth;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

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
			'sqtwc_oauth_pending',
			array(
				'verifier'    => 'verifier',
				'state'       => 'expected-state',
				'environment' => 'sandbox',
			),
			900
		);

		$this->expectException( RuntimeException::class );

		( new SquareOAuth() )->complete( 'code', 'attacker-state' );
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
