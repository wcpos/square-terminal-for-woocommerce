<?php
/**
 * Conformance bootstrap: WordPress, WooCommerce, Free and Pro from the sibling Pro checkout, then
 * this extension over its unscoped development vendor, then Pro's provider conformance case. Runs
 * under wp-env (see .wp-env.json); the plain unit tests in tests/includes are unaffected.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal\Tests\Conformance
 */

// The plugin names the php-scoper prefix the release build gives its vendors; the development
// vendor is unprefixed, so every prefixed name is an alias of the real one (as tests/bootstrap.php does).
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WCPOS\\WooCommercePOS\\SquareTerminal\\Vendor\\';
		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}
		$real = substr( $class, strlen( $prefix ) );
		if ( ( class_exists( $real ) || interface_exists( $real ) ) && ! class_exists( $class, false ) && ! interface_exists( $class, false ) ) {
			class_alias( $real, $class );
		}
	}
);

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: getenv( 'WP_PHPUNIT__DIR' ) ?: '/tmp/wordpress-tests-lib';
require_once $tests_dir . '/includes/functions.php';
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/square-terminal-for-woocommerce.php';
		// Neither a `catch` nor a parameter type autoloads its class, so the aliases the plugin names
		// in catches and signatures are made eagerly, once the SDK's own autoloader is registered.
		foreach ( array( 'Square\\Exceptions\\SquareApiException', 'Square\\Exceptions\\SquareException', 'Square\\Types\\Payment', 'Square\\Types\\TerminalCheckout', 'Square\\Types\\DeviceCode' ) as $sqtwc_class ) {
			class_exists( 'WCPOS\\WooCommercePOS\\SquareTerminal\\Vendor\\' . $sqtwc_class );
		}
	},
	11
);
$pro_dir = dirname( __DIR__, 2 ) . '/../woocommerce-pos-pro';
require $pro_dir . '/tests/bootstrap.php';
require_once $pro_dir . '/tests/includes/Conformance/Conformance_Fixture.php';
require_once $pro_dir . '/tests/includes/Conformance/Provider_Conformance_Test_Case.php';
