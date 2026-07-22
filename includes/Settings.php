<?php
/**
 * Gateway settings accessors.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareOAuth;

/**
 * Memoized settings wrapper.
 */
final class Settings {
	/**
	 * Cached gateway settings for the current request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $settings = null;

	/**
	 * Reset the memoized settings after gateway options change.
	 */
	public static function reset_cache(): void {
		self::$settings = null;
	}

	/**
	 * Reset settings cache for tests.
	 */
	public static function reset_cache_for_tests(): void {
		self::reset_cache();
	}

	/**
	 * Return all gateway settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_gateway_settings(): array {
		if ( null === self::$settings ) {
			self::$settings = (array) get_option( 'woocommerce_sqtwc_settings', array() );
		}

		return self::$settings;
	}

	/**
	 * Read one gateway setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( string $key, $default = '' ) {
		$settings = self::get_gateway_settings();

		return $settings[ $key ] ?? $default;
	}

	/**
	 * Return active Square environment.
	 */
	public static function get_environment(): string {
		return 'production' === self::get( 'environment', 'sandbox' ) ? 'production' : 'sandbox';
	}

	/**
	 * Return the active access token for the configured environment.
	 *
	 * An OAuth connection takes precedence, but only for the environment it was
	 * authorized against — a sandbox connection must never be used to authorize
	 * production requests. Manually configured tokens continue to work exactly
	 * as before for sites that have not connected.
	 */
	public static function get_access_token(): string {
		$environment = self::get_environment();
		$connection  = SquareOAuth::connection();

		if (
			( $connection['environment'] ?? '' ) === $environment
			&& '' !== (string) ( $connection['access_token'] ?? '' )
		) {
			return (string) $connection['access_token'];
		}

		if ( 'production' === $environment ) {
			return (string) self::get( 'production_access_token', '' );
		}

		return (string) self::get( 'sandbox_access_token', '' );
	}

	/**
	 * Return Square Location ID.
	 */
	public static function get_location_id(): string {
		return (string) self::get( 'location_id', '' );
	}

	/**
	 * Return the configured payment collection method.
	 */
	public static function get_collection_method(): string {
		return 'pos_app' === self::get( 'collection_method', 'terminal' ) ? 'pos_app' : 'terminal';
	}

	/**
	 * Return the production Square application ID used by POS handoff.
	 */
	public static function get_pos_application_id(): string {
		return (string) self::get( 'pos_application_id', '' );
	}

	/**
	 * Return the POS app web callback route.
	 *
	 * Sites behind a proxy whose derived REST URL differs from the public
	 * HTTPS URL registered with Square can correct it via the filter.
	 */
	public static function get_pos_callback_url(): string {
		$url = function_exists( 'rest_url' ) ? (string) rest_url( 'sqtwc/v1/pos-callback' ) : '';

		return (string) apply_filters( 'sqtwc_pos_callback_url', $url );
	}

	/**
	 * Return Square API base URL for the configured environment.
	 */
	public static function get_base_url(): string {
		return self::get_base_url_for( self::get_environment() );
	}

	/**
	 * Return the Square API base URL for a specific environment.
	 *
	 * @param string $environment Square environment.
	 */
	public static function get_base_url_for( string $environment ): string {
		return 'production' === $environment ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
	}

	/**
	 * Return webhook signature key.
	 */
	public static function get_webhook_signature_key(): string {
		return (string) self::get( 'webhook_signature_key', '' );
	}

	/**
	 * Return explicitly configured webhook notification URL.
	 */
	public static function get_webhook_notification_url(): string {
		$configured = (string) self::get( 'webhook_notification_url', '' );

		// The plugin owns this route, so it knows the URL. Asking a merchant to
		// retype it only creates a way to get it wrong — and a mismatch breaks
		// signature verification silently, because Square signs over the
		// notification URL. The stored value remains an override for sites whose
		// public URL differs from what WordPress derives.
		return '' !== $configured ? $configured : self::get_default_webhook_url();
	}

	/**
	 * Return the webhook route this plugin actually serves.
	 */
	public static function get_default_webhook_url(): string {
		return function_exists( 'rest_url' ) ? (string) rest_url( 'sqtwc/v1/webhook' ) : '';
	}
}
