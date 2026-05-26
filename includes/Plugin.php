<?php
/**
 * Main plugin bootstrap hooks.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

/**
 * Main plugin class.
 */
final class Plugin {
	/**
	 * Register WordPress and WooCommerce hooks.
	 */
	public function init(): void {
		add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );
	}
}
