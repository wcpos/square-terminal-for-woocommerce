<?php
/**
 * Plugin Name:       Square Terminal for WooCommerce
 * Description:       Collect WooCommerce order payments on Square Terminal devices.
 * Version:           0.1.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            WCPOS
 * License:           GPL-2.0-or-later
 * Text Domain:       square-terminal-for-woocommerce
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

define( 'SQTWC_VERSION', '0.1.0' );
define( 'SQTWC_PLUGIN_FILE', __FILE__ );
define( 'SQTWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SQTWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

function php_requirement_notice(): void {
	$message = 'Square Terminal for WooCommerce requires PHP 8.1 or newer.';
	if ( function_exists( 'esc_html' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}
}

function activation_check(): void {
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
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
		$file = SQTWC_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

$scoped_autoload = SQTWC_PLUGIN_DIR . 'vendor_scoped/autoload.php';
$dev_autoload    = SQTWC_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $scoped_autoload ) ) {
	require_once $scoped_autoload;
} elseif ( file_exists( $dev_autoload ) ) {
	require_once $dev_autoload;
}

add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( Plugin::class ) ) {
			( new Plugin() )->init();
		}
	}
);
