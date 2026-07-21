<?php
/**
 * Square SDK client factory.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use WCPOS\WooCommercePOS\SquareTerminal\Settings;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\SquareClient;

/**
 * Creates configured Square SDK clients.
 */
final class SquareClientFactory {
	/**
	 * Create a Square SDK client.
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
			array( 'baseUrl' => 'production' === $environment ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com' )
		);
	}
}
