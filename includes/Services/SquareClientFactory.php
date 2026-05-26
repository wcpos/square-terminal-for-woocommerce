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
	 */
	public function create(): SquareClient {
		return new SquareClient(
			Settings::get_access_token(),
			null,
			array( 'baseUrl' => Settings::get_base_url() )
		);
	}
}
