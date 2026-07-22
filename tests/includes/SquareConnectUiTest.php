<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Plugin;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareOAuth;
use WCPOS\WooCommercePOS\SquareTerminal\Services\WooCommerceSquareHints;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

final class SquareConnectUiTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_options']          = array();
		$GLOBALS['sqtwc_transients']       = array();
		$GLOBALS['sqtwc_redirects']        = array();
		$GLOBALS['sqtwc_remote_posts']     = array();
		$GLOBALS['sqtwc_current_user_can'] = false;
		$GLOBALS['sqtwc_nonce_valid']      = false;
		$GLOBALS['sqtwc_filter_overrides'] = array();
		$_GET                              = array();
		Gateway::reset_device_memo();
		WooCommerceSquareHints::reset_cache_for_tests();
		Settings::reset_cache_for_tests();
	}

	private function gateway(): Gateway {
		return new Gateway();
	}

	/**
	 * Run a handler that ends in a redirect and return where it sent the admin.
	 *
	 * @param callable $handler Handler to invoke.
	 */
	private function capture_redirect( callable $handler ): string {
		try {
			$handler();
		} catch ( \SQTWC_Redirect $redirect ) {
			return $redirect->getMessage();
		}

		self::fail( 'Expected the handler to redirect.' );
	}

	public function test_connection_row_is_absent_until_an_application_is_configured(): void {
		// Without a WCPOS Square application there is nothing to connect to, so an
		// unfinished flow must never appear on a live settings screen.
		self::assertSame( '', SquareOAuth::client_id() );
		self::assertSame( '', $this->gateway()->generate_square_connection_html( 'square_connection', array( 'title' => 'Square connection' ) ) );
	}

	public function test_connect_button_is_offered_once_an_application_is_configured(): void {
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_oauth_client_id'] = 'sq0idp-test';

		$html = $this->gateway()->generate_square_connection_html( 'square_connection', array( 'title' => 'Square connection' ) );

		self::assertStringContainsString( 'Connect to Square', $html );
		self::assertStringContainsString( 'sqtwc_square_connect', $html );
		self::assertStringNotContainsString( 'Disconnect', $html );
	}

	public function test_connected_state_reports_environment_and_merchant(): void {
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_oauth_client_id'] = 'sq0idp-test';
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array(
			'access_token' => 'token',
			'environment'  => 'production',
			'merchant_id'  => 'MERCHANT123',
		);

		$html = $this->gateway()->generate_square_connection_html( 'square_connection', array( 'title' => 'Square connection' ) );

		self::assertStringContainsString( 'Connected to Square', $html );
		self::assertStringContainsString( 'MERCHANT123', $html );
		self::assertStringContainsString( 'sqtwc_square_disconnect', $html );
		self::assertStringNotContainsString( 'Connect to Square', $html );
	}

	public function test_the_access_token_is_never_rendered_into_the_settings_screen(): void {
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_oauth_client_id'] = 'sq0idp-test';
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array(
			'access_token'  => 'EAAA-super-secret',
			'refresh_token' => 'refresh-super-secret',
			'environment'   => 'production',
			'merchant_id'   => 'MERCHANT123',
		);

		$html = $this->gateway()->generate_square_connection_html( 'square_connection', array( 'title' => 'Square connection' ) );

		self::assertStringNotContainsString( 'super-secret', $html );
	}

	public function test_connect_is_refused_without_the_manage_capability(): void {
		$GLOBALS['sqtwc_nonce_valid'] = true;

		$this->expectExceptionMessageMatches( '/not allowed/' );

		( new Plugin() )->handle_square_connect();
	}

	public function test_connect_is_refused_without_a_valid_nonce(): void {
		$GLOBALS['sqtwc_current_user_can'] = true;
		$GLOBALS['sqtwc_nonce_valid']      = false;

		$this->expectExceptionMessageMatches( '/not allowed/' );

		( new Plugin() )->handle_square_connect();
	}

	public function test_connect_is_inactive_at_the_server_boundary_without_an_application_id(): void {
		$GLOBALS['sqtwc_current_user_can'] = true;
		$GLOBALS['sqtwc_nonce_valid']      = true;

		$location = $this->capture_redirect( static fn() => ( new Plugin() )->handle_square_connect() );

		self::assertStringContainsString( 'sqtwc_connect_failed', $location );
		self::assertSame( array(), $GLOBALS['sqtwc_remote_posts'] );
	}

	public function test_connection_result_notice_is_registered_and_allowlisted(): void {
		$GLOBALS['sqtwc_actions'] = array();
		$plugin                    = new Plugin();
		$plugin->init();

		self::assertArrayHasKey( 'admin_notices', $GLOBALS['sqtwc_actions'] );
		$_GET['sqtwc_notice'] = 'sqtwc_connected';
		ob_start();
		$GLOBALS['sqtwc_actions']['admin_notices'][0]();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice-success', $html );
		self::assertStringContainsString( 'Square account connected.', $html );

		$_GET['sqtwc_notice'] = '<script>alert(1)</script>';
		ob_start();
		$GLOBALS['sqtwc_actions']['admin_notices'][0]();
		self::assertSame( '', ob_get_clean() );
	}

	public function test_disconnect_clears_the_connection_and_returns_to_settings(): void {
		$GLOBALS['sqtwc_current_user_can']              = true;
		$GLOBALS['sqtwc_nonce_valid']                   = true;
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ] = array( 'access_token' => 'token' );

		$location = $this->capture_redirect( static fn() => ( new Plugin() )->handle_square_disconnect() );

		self::assertFalse( SquareOAuth::is_connected() );
		self::assertStringContainsString( 'sqtwc_disconnected', $location );
	}

	public function test_a_declined_authorization_returns_without_exchanging_anything(): void {
		$GLOBALS['sqtwc_current_user_can'] = true;
		$GLOBALS['sqtwc_nonce_valid']      = true;
		$_GET                              = array();

		$location = $this->capture_redirect( static fn() => ( new Plugin() )->handle_square_callback() );

		self::assertStringContainsString( 'sqtwc_connect_declined', $location );
		self::assertFalse( SquareOAuth::is_connected() );
	}

	public function test_a_lapsed_connection_explains_itself_instead_of_reverting_silently(): void {
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_oauth_client_id'] = 'sq0idp-test';
		$GLOBALS['sqtwc_options'][ SquareOAuth::OPTION ]            = array(
			'access_token'       => '',
			'refresh_token'      => '',
			'environment'        => 'production',
			'reconnect_required' => true,
		);

		$html = $this->gateway()->generate_square_connection_html( 'square_connection', array( 'title' => 'Square connection' ) );

		// A failed rotation clears the tokens, so without this the row would show
		// a bare "Connect to Square" and give no clue why the connection ended.
		self::assertStringContainsString( 'Reconnect to Square required', $html );
		self::assertStringContainsString( 'could not be renewed', $html );
	}

	public function test_the_callback_url_is_nonce_protected(): void {
		$url = Plugin::square_callback_url();

		self::assertStringContainsString( 'sqtwc_square_callback', $url );
		self::assertStringContainsString( '_wpnonce', $url );
	}
}
