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

		self::assertStringContainsString( 'Not verified yet', $html );
		self::assertStringContainsString( 'sqtwc-webhook--info', $html );
	}

	/**
	 * Store a verified delivery recorded under the current configuration.
	 */
	private function record_verified_delivery( int $at ): void {
		( new \ReflectionMethod( WebhookHandler::class, 'record_verified_delivery' ) )->invoke( null );
		$stored         = $GLOBALS['sqtwc_options'][ WebhookHandler::LAST_DELIVERY_OPTION ];
		$stored['at']   = $at;
		$GLOBALS['sqtwc_options'][ WebhookHandler::LAST_DELIVERY_OPTION ] = $stored;
	}

	public function test_webhook_status_reports_a_verified_delivery(): void {
		$this->record_verified_delivery( time() - 120 );

		$html = Gateway::render_webhook_status();

		self::assertStringContainsString( 'Verified', $html );
		self::assertStringContainsString( 'sqtwc-webhook--ok', $html );
	}

	public function test_health_does_not_survive_a_change_of_signature_key(): void {
		$this->record_verified_delivery( time() - 120 );
		self::assertNotNull( WebhookHandler::last_verified_delivery() );

		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'webhook_signature_key' => 'a-different-key' );
		Settings::reset_cache_for_tests();

		// A delivery verified under the old key says nothing about the new one,
		// and would mask exactly the broken setup this row exists to reveal.
		self::assertNull( WebhookHandler::last_verified_delivery() );
		self::assertStringContainsString( 'Not verified yet', Gateway::render_webhook_status() );
	}

	public function test_a_legacy_health_record_is_not_treated_as_current(): void {
		// 0.6.0 stored a bare timestamp with no fingerprint, so it cannot be
		// attributed to any configuration. Trusting it would carry stale health
		// across the upgrade and mask a broken key or URL.
		$GLOBALS['sqtwc_options'][ WebhookHandler::LAST_DELIVERY_OPTION ] = time() - 60;

		self::assertNull( WebhookHandler::last_verified_delivery() );
		self::assertStringContainsString( 'Not verified yet', Gateway::render_webhook_status() );
	}

	public function test_health_does_not_survive_a_change_of_environment(): void {
		$this->record_verified_delivery( time() - 120 );

		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'environment' => 'production' );
		Settings::reset_cache_for_tests();

		self::assertNull( WebhookHandler::last_verified_delivery() );
	}

	public function test_an_unverified_caller_cannot_make_the_screen_claim_webhooks_are_broken(): void {
		// The webhook route is public, so only verified deliveries are recorded.
		// Anything an unauthenticated caller could write must not be reportable.
		$GLOBALS['sqtwc_options'][ WebhookHandler::LAST_DELIVERY_OPTION ] = array(
			'at'       => time() - 60,
			'verified' => false,
		);

		self::assertNull( WebhookHandler::last_verified_delivery() );
		self::assertStringContainsString( 'Not verified yet', Gateway::render_webhook_status() );
	}

	public function test_the_webhook_url_is_fully_visible_and_copyable(): void {
		$html = Gateway::render_webhook_status();

		// The URL is the point of this row. A box too narrow to show it, that the
		// merchant has to scroll inside and select by hand, is worse than none.
		self::assertStringContainsString( 'sqtwc-webhook-copy', $html );
		self::assertStringContainsString( 'id="sqtwc-copy-webhook"', $html );
		self::assertStringContainsString( 'readonly', $html );
		self::assertStringNotContainsString( 'large-text', $html );
	}

	public function test_the_webhook_row_stays_short(): void {
		$text = trim( wp_strip_all_tags( Gateway::render_webhook_status() ) );

		// Diagnosis detail belongs in the guide, not on the settings screen.
		self::assertLessThan( 220, strlen( $text ), 'The webhook row has grown wordy again' );
	}

	public function test_sections_link_to_the_documentation_instead_of_explaining_inline(): void {
		$fields = ( new Gateway() )->form_fields;

		// Rows say what a field is; the guide says how to use it. Repeating the
		// guide inline made the screen long enough to stop being read.
		self::assertStringContainsString( 'docs.wcpos.com/payment/gateways/square-terminal', $fields['section_account']['description'] );
		self::assertStringContainsString( 'docs.wcpos.com/payment/gateways/square-terminal', $fields['section_terminal']['description'] );
		self::assertStringContainsString( 'docs.wcpos.com/payment/gateways/square-terminal', Gateway::render_webhook_status() );
	}

	public function test_settings_copy_stays_short_enough_to_read(): void {
		$fields = ( new Gateway() )->form_fields;

		foreach ( $fields as $key => $field ) {
			if ( 'pos_setup_checklist' === $field['type'] ) {
				continue;
			}
			$description = wp_strip_all_tags( (string) ( $field['description'] ?? '' ) );
			self::assertLessThan(
				180,
				strlen( $description ),
				"Settings description for {$key} is long enough that merchants stop reading it"
			);
		}
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
