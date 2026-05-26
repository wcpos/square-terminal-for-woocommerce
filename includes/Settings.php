<?php
namespace WCPOS\WooCommercePOS\SquareTerminal;
final class Settings {
	private static ?array $settings = null;
	public static function reset_cache_for_tests(): void { self::$settings = null; }
	public static function get_gateway_settings(): array { if (null === self::$settings) { self::$settings = (array) get_option('woocommerce_sqtwc_settings', array()); } return self::$settings; }
	public static function get(string $key, $default = '') { $s = self::get_gateway_settings(); return $s[$key] ?? $default; }
	public static function get_environment(): string { return 'production' === self::get('environment', 'sandbox') ? 'production' : 'sandbox'; }
	public static function get_access_token(): string { return (string) ('production' === self::get_environment() ? self::get('production_access_token', '') : self::get('sandbox_access_token', '')); }
	public static function get_location_id(): string { return (string) self::get('location_id', ''); }
	public static function get_base_url(): string { return 'production' === self::get_environment() ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com'; }
	public static function get_webhook_signature_key(): string { return (string) self::get('webhook_signature_key', ''); }
	public static function get_webhook_notification_url(): string { return (string) self::get('webhook_notification_url', ''); }
}
