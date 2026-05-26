<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;
use PHPUnit\Framework\TestCase;
final class ScopedDependencyTest extends TestCase {
 public function test_php_scoper_is_not_composer_dependency(): void { $json=json_decode(file_get_contents(dirname(__DIR__,2).'/composer.json'), true); self::assertArrayNotHasKey('humbug/php-scoper', $json['require-dev'] ?? array()); self::assertArrayNotHasKey('humbug/php-scoper', $json['require'] ?? array()); }
 public function test_build_script_uses_phar(): void { $json=file_get_contents(dirname(__DIR__,2).'/composer.json'); self::assertStringContainsString('php-scoper.phar', $json); }
 public function test_scoper_prefix(): void { $contents = file_get_contents(dirname(__DIR__,2).'/scoper.inc.php'); self::assertMatchesRegularExpression("/'prefix'\\s*=>\\s*'WCPOS\\\\\\\\WooCommercePOS\\\\\\\\SquareTerminal\\\\\\\\Vendor'/", $contents); }
 public function test_bootstrap_prefers_scoped_vendor(): void { $plugin=file_get_contents(dirname(__DIR__,2).'/square-terminal-for-woocommerce.php'); $scoped_pos=strpos($plugin,'vendor_scoped/autoload.php'); $unscoped_pos=strpos($plugin,'vendor/autoload.php'); self::assertNotFalse($scoped_pos); self::assertNotFalse($unscoped_pos); self::assertLessThan($unscoped_pos, $scoped_pos); }
}
