<?php
/**
 * Plugin Name:       Square Terminal for WooCommerce
 * Plugin URI:        https://github.com/kilbot/square-terminal-for-woocommerce
 * Description:       Collect WooCommerce order payments on Square Terminal devices.
 * Version:           0.2.2
 * Author:            kilbot
 * Author URI:        https://wcpos.com
 * Text Domain:       square-terminal-for-woocommerce
 * License:           GPL-3.0+
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Tested up to:      6.9
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   10.8.0
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

if ( ! \defined( 'ABSPATH' ) ) {
	return;
}

if ( ! \defined( __NAMESPACE__ . '\VERSION' ) ) {
	\define( __NAMESPACE__ . '\VERSION', '0.2.2' );
}
if ( ! \defined( __NAMESPACE__ . '\PLUGIN_NAME' ) ) {
	\define( __NAMESPACE__ . '\PLUGIN_NAME', 'square-terminal-for-woocommerce' );
}
if ( ! \defined( __NAMESPACE__ . '\SHORT_NAME' ) ) {
	\define( __NAMESPACE__ . '\SHORT_NAME', 'sqtwc' );
}
if ( ! \defined( __NAMESPACE__ . '\PLUGIN_FILE' ) ) {
	\define( __NAMESPACE__ . '\PLUGIN_FILE', plugin_basename( __FILE__ ) );
}
if ( ! \defined( __NAMESPACE__ . '\PLUGIN_PATH' ) ) {
	\define( __NAMESPACE__ . '\PLUGIN_PATH', trailingslashit( plugin_dir_path( __FILE__ ) ) );
}
if ( ! \defined( __NAMESPACE__ . '\PLUGIN_URL' ) ) {
	\define( __NAMESPACE__ . '\PLUGIN_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );
}
if ( ! \defined( __NAMESPACE__ . '\PHP_MIN_VERSION' ) ) {
	\define( __NAMESPACE__ . '\PHP_MIN_VERSION', '8.1' );
}

/**
 * Show an admin notice when PHP is too old to load the plugin safely.
 */
function php_requirement_notice(): void {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Square Terminal for WooCommerce requires PHP 8.1 or newer.', 'square-terminal-for-woocommerce' ); ?></p>
	</div>
	<?php
}

/**
 * Stop activation on unsupported PHP versions before Composer or Square SDK code loads.
 */
function activation_check(): void {
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		deactivate_plugins( PLUGIN_FILE );
		wp_die( esc_html__( 'Square Terminal for WooCommerce requires PHP 8.1 or newer.', 'square-terminal-for-woocommerce' ) );
	}
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activation_check' );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action( 'admin_notices', __NAMESPACE__ . '\\php_requirement_notice' );
	return;
}

spl_autoload_register(
	function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$file = PLUGIN_PATH . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

register_deactivation_hook( __FILE__, array( Services\PaymentSweeper::class, 'unschedule' ) );

$sqtwc_scoped_autoload = PLUGIN_PATH . 'vendor_scoped/autoload.php';
$sqtwc_dev_autoload    = PLUGIN_PATH . 'vendor/autoload.php';

if ( file_exists( $sqtwc_scoped_autoload ) ) {
	require_once $sqtwc_scoped_autoload;
} elseif ( file_exists( $sqtwc_dev_autoload ) ) {
	require_once $sqtwc_dev_autoload;
}

add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function (): void {
		if ( class_exists( Plugin::class ) ) {
			( new Plugin() )->init();
		}
	}
);
