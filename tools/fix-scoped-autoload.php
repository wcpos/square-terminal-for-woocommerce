<?php
$autoload = <<<'PHP_AUTLOAD'
<?php
spl_autoload_register(static function (string $class): void {
    $prefix = 'WCPOS\\WooCommercePOS\\SquareTerminal\\Vendor\\';
    if (0 !== strncmp($class, $prefix, strlen($prefix))) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $maps = array(
        'Square\\' => __DIR__ . '/square/square/src/',
        'CoreInterfaces\\' => __DIR__ . '/apimatic/core-interfaces/src/',
        'Core\\' => __DIR__ . '/apimatic/core/src/',
        'Unirest\\' => __DIR__ . '/apimatic/unirest-php/src/',
        'apimatic\\jsonmapper\\' => __DIR__ . '/apimatic/jsonmapper/src/',
        'Psr\\Http\\Client\\' => __DIR__ . '/psr/http-client/src/',
        'Psr\\Http\\Message\\' => __DIR__ . '/psr/http-message/src/',
        'Psr\\Log\\' => __DIR__ . '/psr/log/src/',
        'Http\\Discovery\\' => __DIR__ . '/php-http/discovery/src/',
        'Http\\Message\\MultipartStream\\' => __DIR__ . '/php-http/multipart-stream-builder/src/',
        'Symfony\\Component\\HttpFoundation\\' => __DIR__ . '/symfony/http-foundation/',
    );
    foreach ($maps as $classPrefix => $dir) {
        if (0 === strncmp($relative, $classPrefix, strlen($classPrefix))) {
            $file = $dir . str_replace('\\', '/', substr($relative, strlen($classPrefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
PHP_AUTLOAD;
$target = __DIR__ . '/../vendor_scoped/autoload.php';
if ( false === file_put_contents( $target, $autoload ) ) {
	$error = error_get_last();
	fwrite( STDERR, 'Failed writing scoped autoload: ' . $target . ' ' . ( $error['message'] ?? '' ) . PHP_EOL );
	exit( 1 );
}
