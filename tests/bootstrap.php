<?php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/wordpress.php';
require_once __DIR__ . '/stubs/woocommerce.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'WCPOS\\WooCommercePOS\\SquareTerminal\\Vendor\\Square\\';
    if (0 === strncmp($prefix, $class, strlen($prefix))) {
        $square = 'Square\\' . substr($class, strlen($prefix));
        if (!class_exists($square) && !interface_exists($square)) {
            class_exists($square);
        }
        if ((class_exists($square) || interface_exists($square)) && !class_exists($class, false) && !interface_exists($class, false)) {
            class_alias($square, $class);
        }
    }
});
require_once dirname(__DIR__) . '/square-terminal-for-woocommerce.php';
