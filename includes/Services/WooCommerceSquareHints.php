<?php
/**
 * Non-secret setup hints read from the official WooCommerce Square plugin.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use Throwable;

/**
 * Reads environment and location hints from the official WooCommerce Square plugin.
 *
 * Only non-secret setup values are read: the environment and the selected
 * location ID. Access and refresh tokens are never read, copied, stored, or
 * logged — the two plugins keep entirely separate Square connections.
 *
 * Every failure here is silent. A hint is a convenience, and a change or fault
 * in another plugin must never block this one's setup.
 */
final class WooCommerceSquareHints {
	/**
	 * Memoized hints for the current request.
	 *
	 * @var array{environment:string,location_id:string}|null
	 */
	private static ?array $hints = null;

	/**
	 * Reset memoization for tests.
	 */
	public static function reset_cache_for_tests(): void {
		self::$hints = null;
	}

	/**
	 * Return non-secret hints from the official plugin.
	 *
	 * @return array{environment:string,location_id:string} Empty strings when unavailable.
	 */
	public static function detect(): array {
		if ( null !== self::$hints ) {
			return self::$hints;
		}

		$hints = self::from_public_api();

		if ( '' === $hints['location_id'] ) {
			$hints = self::from_option( $hints );
		}

		self::$hints = $hints;

		return self::$hints;
	}

	/**
	 * Whether the official plugin supplied anything usable.
	 */
	public static function has_hints(): bool {
		$hints = self::detect();

		return '' !== $hints['location_id'] || '' !== $hints['environment'];
	}

	/**
	 * Read hints through the official plugin's public settings handler.
	 *
	 * @return array{environment:string,location_id:string}
	 */
	private static function from_public_api(): array {
		$hints = array(
			'environment' => '',
			'location_id' => '',
		);

		if ( ! function_exists( 'wc_square' ) ) {
			return $hints;
		}

		try {
			$plugin = wc_square();
			if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_settings_handler' ) ) {
				return $hints;
			}

			$settings = $plugin->get_settings_handler();
			if ( ! is_object( $settings ) ) {
				return $hints;
			}

			if ( method_exists( $settings, 'get_environment' ) ) {
				$hints['environment'] = self::normalize_environment( (string) $settings->get_environment() );
			}

			if ( method_exists( $settings, 'get_location_id' ) ) {
				$hints['location_id'] = self::normalize_location( (string) $settings->get_location_id() );
			}
		} catch ( Throwable $exception ) {
			return array(
				'environment' => '',
				'location_id' => '',
			);
		}

		return $hints;
	}

	/**
	 * Read hints straight from the official plugin's settings option.
	 *
	 * Used when the plugin's classes are not loaded yet, so a hint is still
	 * available during early gateway construction.
	 *
	 * @param array{environment:string,location_id:string} $hints Hints resolved so far.
	 * @return array{environment:string,location_id:string}
	 */
	private static function from_option( array $hints ): array {
		if ( ! function_exists( 'get_option' ) ) {
			return $hints;
		}

		$settings = get_option( 'wc_square_settings', array() );

		// An absent or empty option means the official plugin is not configured.
		// Inferring an environment from missing keys would default a fresh
		// install to production, which is worse than offering no hint at all.
		if ( ! is_array( $settings ) || array() === $settings ) {
			return $hints;
		}

		$environment = 'yes' === ( $settings['enable_sandbox'] ?? '' ) ? 'sandbox' : 'production';

		if ( '' === $hints['environment'] ) {
			$hints['environment'] = $environment;
		}

		$hints['location_id'] = self::normalize_location( (string) ( $settings[ $environment . '_location_id' ] ?? '' ) );

		return $hints;
	}

	/**
	 * Constrain an environment hint to a value this plugin understands.
	 *
	 * @param string $environment Raw environment.
	 */
	private static function normalize_environment( string $environment ): string {
		return in_array( $environment, array( 'sandbox', 'production' ), true ) ? $environment : '';
	}

	/**
	 * Constrain a location hint to the Square ID character set.
	 *
	 * @param string $location_id Raw location ID.
	 */
	private static function normalize_location( string $location_id ): string {
		$location_id = trim( $location_id );

		return (bool) preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $location_id ) ? $location_id : '';
	}
}
