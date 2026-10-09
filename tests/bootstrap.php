<?php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/wordpress.php';
require_once __DIR__ . '/stubs/woocommerce.php';
require_once __DIR__ . '/stubs/woocommerce-caches.php';
require_once __DIR__ . '/stubs/wcpos-pro.php';

// The plugin file returns early without ABSPATH, so its namespaced constants
// are never defined under PHPUnit. Asset registration needs them.
if ( ! defined( 'WCPOS\\WooCommercePOS\\SquareTerminal\\VERSION' ) ) {
    define( 'WCPOS\\WooCommercePOS\\SquareTerminal\\VERSION', '0.0.0-test' );
}
if ( ! defined( 'WCPOS\\WooCommercePOS\\SquareTerminal\\PLUGIN_URL' ) ) {
    define( 'WCPOS\\WooCommercePOS\\SquareTerminal\\PLUGIN_URL', 'https://wcpos.local/wp-content/plugins/square-terminal-for-woocommerce/' );
}

// The plugin names the php-scoper prefix the release build gives its vendors (Square, Guzzle,
// PSR); the development vendor is unprefixed, so every prefixed name is an alias of the real one.
spl_autoload_register(static function (string $class): void {
    $prefix = 'WCPOS\\WooCommercePOS\\SquareTerminal\\Vendor\\';
    if (0 !== strncmp($prefix, $class, strlen($prefix))) {
        return;
    }
    $real = substr($class, strlen($prefix));
    if ((class_exists($real) || interface_exists($real)) && !class_exists($class, false) && !interface_exists($class, false)) {
        class_alias($real, $class);
    }
});
// Neither a `catch` nor a parameter type autoloads its class, so the aliases the plugin names in
// catches and signatures are made eagerly.
foreach ( array( 'Square\\Exceptions\\SquareApiException', 'Square\\Exceptions\\SquareException', 'Square\\Types\\Payment', 'Square\\Types\\TerminalCheckout', 'Square\\Types\\DeviceCode' ) as $sqtwc_exception ) {
    class_exists( 'WCPOS\\WooCommercePOS\\SquareTerminal\\Vendor\\' . $sqtwc_exception );
}
require_once dirname(__DIR__) . '/square-terminal-for-woocommerce.php';
