<?php
$finder_class = '_HumbugBoxc8f94d632dc5\\Symfony\\Component\\Finder\\Finder';
$finder = $finder_class::create()->files()->in( __DIR__ . '/vendor' )->exclude( array( 'bin' ) );

return array(
	'prefix' => 'WCPOS\\WooCommercePOS\\SquareTerminal\\Vendor',
	'finders' => array( $finder ),
	'exclude-files' => array(
		__DIR__ . '/vendor/autoload.php',
	),
);
