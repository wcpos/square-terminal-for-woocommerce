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

	public function test_the_wcpos_application_ids_ship_with_the_plugin(): void {
		// Every install shares one WCPOS Square application, so the IDs ship
		// rather than being configured per site. PKCE is what makes publishing
		// them safe — the ID alone authorizes nothing.
		self::assertStringStartsWith( 'sq0idp-', SquareOAuth::client_id( 'production' ) );
		self::assertStringStartsWith( 'sandbox-sq0idb-', SquareOAuth::client_id( 'sandbox' ) );
	}

	public function test_connection_row_can_still_be_disabled_by_filter(): void {
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_oauth_client_id'] = '';

		// The escape hatch matters for a site running its own Square application.
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
		$GLOBALS['sqtwc_current_user_can']                          = true;
		$GLOBALS['sqtwc_nonce_valid']                               = true;
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_oauth_client_id'] = '';

		$location = $this->capture_redirect( static fn() => ( new Plugin() )->handle_square_connect() );

		// A site that has deliberately cleared the application ID must not reach
		// the relay at all, rather than starting an authorization it cannot finish.
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

	public function test_enable_setting_says_it_governs_web_checkout_only(): void {
		$fields = $this->gateway()->form_fields['enabled'];

		// The POS uses the gateway once configured regardless of this setting, so
		// a bare "Enable" read as though the POS needed it too. Matches the
		// wording already used by the Stripe and SumUp Terminal plugins.
		self::assertSame( 'Enable/Disable', $fields['title'] );
		self::assertStringContainsString( 'web checkout', $fields['label'] );
		self::assertStringContainsString( 'not necessary for', $fields['label'] );
		self::assertStringContainsString( 'wcpos.com', $fields['label'] );
		self::assertStringContainsString( 'WCPOS uses this gateway automatically', $fields['description'] );
		// Branded WCPOS, not "WooCommerce POS".
		self::assertStringContainsString( '>WCPOS<', $fields['label'] );
		self::assertStringNotContainsString( 'WooCommerce POS', $fields['label'] );
	}

	public function test_connecting_honours_the_environment_chosen_on_screen(): void {
		$GLOBALS['sqtwc_current_user_can']                      = true;
		$GLOBALS['sqtwc_nonce_valid']                           = true;
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'environment' => 'sandbox' );
		$_GET['environment']                                    = 'production';
		Settings::reset_cache_for_tests();

		$this->capture_redirect( static fn() => ( new Plugin() )->handle_square_connect() );

		// Connect is a link, so an unsaved dropdown change was previously ignored
		// and someone selecting Production silently authorized the sandbox app.
		self::assertSame( 'production', Settings::get_environment() );
		$posted = json_decode( (string) ( $GLOBALS['sqtwc_remote_posts'][0]['args']['body'] ?? '{}' ), true );
		self::assertSame( 'production', $posted['environment'] ?? null );
	}

	public function test_connecting_honours_the_device_chosen_on_screen(): void {
		$GLOBALS['sqtwc_current_user_can']                      = true;
		$GLOBALS['sqtwc_nonce_valid']                           = true;
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'environment' => 'production' );
		$_GET['environment']                                    = 'production';
		$_GET['collection_method']                              = 'pos_app';
		Settings::reset_cache_for_tests();

		$this->capture_redirect( static fn() => ( new Plugin() )->handle_square_connect() );

		// The Reader checklist points at Connect before the form is saved; the
		// link carries the unsaved radio choice so OAuth doesn't discard it.
		self::assertSame( 'pos_app', Settings::get_collection_method() );
	}

	public function test_an_unrecognised_collection_method_leaves_the_saved_one_alone(): void {
		$GLOBALS['sqtwc_current_user_can']                      = true;
		$GLOBALS['sqtwc_nonce_valid']                           = true;
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'collection_method' => 'pos_app' );
		$_GET['collection_method']                              = 'both-please';
		Settings::reset_cache_for_tests();

		$this->capture_redirect( static fn() => ( new Plugin() )->handle_square_connect() );

		self::assertSame( 'pos_app', Settings::get_collection_method() );
	}

	public function test_an_unrecognised_environment_leaves_the_saved_one_alone(): void {
		$GLOBALS['sqtwc_current_user_can']                      = true;
		$GLOBALS['sqtwc_nonce_valid']                           = true;
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'environment' => 'sandbox' );
		$_GET['environment']                                    = 'staging';
		Settings::reset_cache_for_tests();

		$this->capture_redirect( static fn() => ( new Plugin() )->handle_square_connect() );

		self::assertSame( 'sandbox', Settings::get_environment() );
	}

	public function test_the_connect_button_names_the_environment_it_will_use(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'environment' => 'production' );
		Settings::reset_cache_for_tests();

		$html = $this->gateway()->generate_square_connection_html( 'square_connection', array( 'title' => 'Square connection' ) );

		self::assertStringContainsString( 'Connect to Square (production)', $html );
		self::assertStringContainsString( 'sqtwc-connect-link', $html );
	}

	public function test_the_callback_url_is_nonce_protected(): void {
		$url = Plugin::square_callback_url();

		self::assertStringContainsString( 'sqtwc_square_callback', $url );
		self::assertStringContainsString( '_wpnonce', $url );
	}
}
