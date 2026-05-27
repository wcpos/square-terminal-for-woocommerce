<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;
use PHPUnit\Framework\TestCase;
final class BootstrapGuardTest extends TestCase {
 private string $plugin;
 protected function setUp(): void { $this->plugin = file_get_contents(dirname(__DIR__,2).'/square-terminal-for-woocommerce.php') ?: ''; }
 public function test_header_requires_php_81(): void { self::assertStringContainsString('Requires PHP:      8.1', $this->plugin); }
 public function test_php_guard_precedes_vendor_autoload(): void { self::assertLessThan(strpos($this->plugin, 'vendor/autoload.php'), strpos($this->plugin, "version_compare( PHP_VERSION, '8.1', '<' )")); }
 public function test_registers_activation_hook(): void { self::assertStringContainsString('register_activation_hook', $this->plugin); }
 public function test_plugin_autoloader_precedes_vendor_autoload(): void { self::assertLessThan(strpos($this->plugin, 'vendor/autoload.php'), strpos($this->plugin, 'spl_autoload_register')); }
}
