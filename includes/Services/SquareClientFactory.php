<?php
/**
 * Square SDK client factory.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use WCPOS\WooCommercePOS\SquareTerminal\Settings;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\GuzzleHttp\Client as HttpClient;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\SquareClient;

/**
 * Creates configured Square SDK clients.
 */
final class SquareClientFactory {
	/**
	 * Request timeout in seconds.
	 *
	 * Bounded so a slow Square response cannot hold a checkout render open.
	 */
	private const TIMEOUT = 10;

	/**
	 * Create a Square SDK client.
	 *
	 * The PSR-18 client is passed explicitly. The Square SDK otherwise resolves
	 * one through php-http/discovery, which scans for well-known class names —
	 * php-scoper rewrites those names in the distributed build, so discovery
	 * finds nothing and every request throws before it is sent.
	 *
	 * @param string|null         $access_token Square access token override.
	 * @param string|null         $environment  Square environment override.
	 * @param array<string,mixed> $options      Further SDK client options (for example `maxRetries`).
	 */
	public function create( ?string $access_token = null, ?string $environment = null, array $options = array() ): SquareClient {
		$access_token = null === $access_token ? Settings::get_access_token() : $access_token;
		$environment  = null === $environment ? Settings::get_environment() : $environment;

		/**
		 * The PSR-18 client the Square SDK sends through. Tests script Square here; nothing
		 * else should.
		 *
		 * @param object|null $http        PSR-18 client to use, or null for the default.
		 * @param string               $environment Square environment the client is for.
		 */
		$http = apply_filters( 'sqtwc_square_http_client', null, $environment );
		// Duck-typed: the scoped interface name is an alias under the development vendor, and
		// `instanceof` never autoloads.
		if ( ! is_object( $http ) || ! method_exists( $http, 'sendRequest' ) ) {
			$http = new HttpClient( array( 'timeout' => self::TIMEOUT ) );
		}

		return new SquareClient(
			$access_token,
			null,
			$options + array(
				'baseUrl' => Settings::get_base_url_for( $environment ),
				'client'  => $http,
			)
		);
	}
}
