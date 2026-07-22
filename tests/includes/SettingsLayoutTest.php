<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Services\WooCommerceSquareHints;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;
use WCPOS\WooCommercePOS\SquareTerminal\WebhookHandler;

final class SettingsLayoutTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_options']          = array();
		$GLOBALS['sqtwc_transients']       = array();
		$GLOBALS['sqtwc_filter_overrides'] = array();
		Gateway::reset_device_memo();
		WooCommerceSquareHints::reset_cache_for_tests();
		Settings::reset_cache_for_tests();
	}

	/**
	 * @return array<int,string>
	 */
	private function field_order(): array {
		return array_keys( ( new Gateway() )->form_fields );
	}

	private function position( string $key ): int {
		$position = array_search( $key, $this->field_order(), true );
		self::assertNotFalse( $position, "Missing settings field: {$key}" );

		return (int) $position;
	}

	public function test_connecting_comes_before_being_asked_for_credentials(): void {
		// The previous layout asked for an access token above the button that
		// makes one unnecessary, which is what made the screen confusing.
		self::assertLessThan( $this->position( 'sandbox_access_token' ), $this->position( 'square_connection' ) );
		self::assertLessThan( $this->position( 'production_access_token' ), $this->position( 'square_connection' ) );
	}

	public function test_setup_reads_in_the_order_it_is_performed(): void {
		self::assertLessThan( $this->position( 'square_connection' ), $this->position( 'environment' ) );
		self::assertLessThan( $this->position( 'terminal_pairing' ), $this->position( 'square_connection' ) );
		self::assertLessThan( $this->position( 'skip_receipt_screen' ), $this->position( 'terminal_pairing' ) );
	}

	public function test_credentials_and_webhook_plumbing_sit_inside_the_advanced_section(): void {
		$start = $this->position( 'advanced_start' );
		$end   = $this->position( 'advanced_end' );

		foreach ( array( 'sandbox_access_token', 'production_access_token', 'webhook_signature_key', 'webhook_notification_url' ) as $key ) {
			self::assertGreaterThan( $start, $this->position( $key ), "{$key} should be inside Advanced" );
			self::assertLessThan( $end, $this->position( $key ), "{$key} should be inside Advanced" );
		}
	}

	public function test_the_advanced_section_opens_and_closes_balanced_markup(): void {
		$gateway = new Gateway();
		$open    = $gateway->generate_advanced_start_html( 'advanced_start', array( 'title' => 'Advanced settings' ) );
		$close   = $gateway->generate_advanced_end_html( 'advanced_end', array() );

		// WooCommerce wraps every field in one form table, so the section must
		// close and reopen it or the rest of the screen collapses.
		self::assertStringContainsString( '</table><details', $open );
		self::assertStringContainsString( '<table class="form-table">', $open );
		self::assertSame( 1, substr_count( $open, '<details' ) );
		self::assertSame( 1, substr_count( $close, '</details>' ) );
		self::assertStringEndsWith( '<table class="form-table">', $close );
	}

	public function test_the_rendered_screen_has_balanced_markup(): void {
		$gateway = new Gateway();

		// WooCommerce opens one <table class="form-table"> before rendering fields
		// and closes it afterwards, and every section heading closes and reopens
		// it. If the advanced accordion gets that wrong the entire settings screen
		// collapses — a failure no field-ordering assertion would catch, and one
		// that only shows up in a browser.
		$html = '<table class="form-table">';
		foreach ( $gateway->form_fields as $key => $field ) {
			$method = 'generate_' . $field['type'] . '_html';
			if ( method_exists( $gateway, $method ) ) {
				$html .= $gateway->{$method}( $key, $field );
			}
		}
		$html .= '</table>';

		self::assertSame(
			substr_count( $html, '<table' ),
			substr_count( $html, '</table>' ),
			'Unbalanced <table> tags would collapse the settings screen'
		);
		self::assertSame(
			substr_count( $html, '<details' ),
			substr_count( $html, '</details>' ),
			'Unbalanced <details> tags would swallow the fields after Advanced'
		);
		self::assertSame( 1, substr_count( $html, '<details' ) );

		$open  = strpos( $html, '<details' );
		$close = strpos( $html, '</details>' );
		self::assertNotFalse( $open );
		self::assertNotFalse( $close );
		self::assertLessThan( $close, $open );

		// The accordion must come last, so it cannot swallow the rows above it.
		// Checked against rows this gateway renders itself — the WooCommerce stub
		// does not implement the built-in text and password field types.
		foreach ( array( 'sqtwc_square_connect', 'sqtwc-check-readers', 'sqtwc-webhook' ) as $marker ) {
			$position = strpos( $html, $marker );
			self::assertNotFalse( $position, "Missing rendered row: {$marker}" );
			self::assertLessThan( $open, $position, "{$marker} must render before the Advanced accordion" );
		}
	}

	public function test_webhook_status_reports_that_nothing_has_arrived_yet(): void {
		$html = Gateway::render_webhook_status();

		self::assertStringContainsString( 'No verified webhook received yet', $html );
		self::assertStringContainsString( 'sqtwc-webhook--info', $html );
	}

	public function test_webhook_status_reports_a_verified_delivery(): void {
		$GLOBALS['sqtwc_options'][ WebhookHandler::LAST_DELIVERY_OPTION ] = time() - 120;

		$html = Gateway::render_webhook_status();

		self::assertStringContainsString( 'received and verified', $html );
		self::assertStringContainsString( 'sqtwc-webhook--ok', $html );
	}

	public function test_an_unverified_caller_cannot_make_the_screen_claim_webhooks_are_broken(): void {
		// The webhook route is public, so only verified deliveries are recorded.
		// Anything an unauthenticated caller could write must not be reportable.
		$GLOBALS['sqtwc_options'][ WebhookHandler::LAST_DELIVERY_OPTION ] = array(
			'at'       => time() - 60,
			'verified' => false,
		);

		self::assertNull( WebhookHandler::last_verified_delivery() );
		self::assertStringContainsString( 'No verified webhook received yet', Gateway::render_webhook_status() );
	}

	public function test_no_verified_delivery_names_the_signature_key(): void {
		$html = Gateway::render_webhook_status();

		// A key mismatch shows up as the absence of any verified delivery, so the
		// actionable hint belongs in that state.
		self::assertStringContainsString( 'Webhook Signature Key', $html );
	}

	public function test_the_webhook_url_is_shown_read_only_rather_than_asked_for(): void {
		$html = Gateway::render_webhook_status();

		self::assertStringContainsString( 'readonly', $html );
		self::assertStringContainsString( 'wp-json/sqtwc/v1/webhook', $html );
	}

	public function test_the_webhook_url_defaults_to_the_route_the_plugin_serves(): void {
		// Nothing configured: the plugin knows its own route, so signature
		// verification must work without the merchant typing anything.
		self::assertStringContainsString( 'sqtwc/v1/webhook', Settings::get_webhook_notification_url() );
	}

	public function test_a_configured_override_still_wins(): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array(
			'webhook_notification_url' => 'https://proxy.example/wp-json/sqtwc/v1/webhook',
		);
		Settings::reset_cache_for_tests();

		// Sites behind a proxy have a public URL WordPress cannot derive, and
		// Square signs over the exact URL it was given.
		self::assertSame( 'https://proxy.example/wp-json/sqtwc/v1/webhook', Settings::get_webhook_notification_url() );
	}
}
