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
	 * @param string|null $access_token Square access token override.
	 * @param string|null $environment  Square environment override.
	 */
	public function create( ?string $access_token = null, ?string $environment = null ): SquareClient {
		$access_token = null === $access_token ? Settings::get_access_token() : $access_token;
		$environment  = null === $environment ? Settings::get_environment() : $environment;

		return new SquareClient(
			$access_token,
			null,
			array(
				'baseUrl' => Settings::get_base_url_for( $environment ),
				'client'  => new HttpClient( array( 'timeout' => self::TIMEOUT ) ),
			)
		);
	}
}
