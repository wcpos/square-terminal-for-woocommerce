<?php
/**
 * PHP-Scoper configuration for release vendor dependencies.
 *
 * This scopes runtime Composer dependencies only. Development tools such as
 * PHPUnit, PHPCS, WPCS, and their transitive dependencies are excluded from
 * distributable builds.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

$finder_class = Isolated\Symfony\Component\Finder\Finder::class;
$finder       = $finder_class::create()
	->files()
	->in( __DIR__ . '/vendor' )
	->exclude(
		array(
			'bin',
			'dealerdirect',
			'myclabs',
			'nikic',
			'phar-io',
			'phpcompatibility',
			'phpcsstandards',
			'phpunit',
			'sebastian',
			'sirbrillig',
			'squizlabs',
			'theseer',
			'woocommerce',
			'wp-coding-standards',
		)
	);

return array(
	'prefix'        => 'WCPOS\\WooCommercePOS\\SquareTerminal\\Vendor',
	'finders'       => array( $finder ),
	'exclude-files' => array(
		__DIR__ . '/vendor/autoload.php',
	),
);
