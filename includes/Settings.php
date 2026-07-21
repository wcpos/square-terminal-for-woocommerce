<?php
/**
 * Gateway settings accessors.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

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
	 */
	public static function get_access_token(): string {
		if ( 'production' === self::get_environment() ) {
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
	 * Return Square API base URL.
	 */
	public static function get_base_url(): string {
		return 'production' === self::get_environment() ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
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
		return (string) self::get( 'webhook_notification_url', '' );
	}
}
