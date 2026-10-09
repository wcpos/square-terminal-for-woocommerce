<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;

/** Without a compatible WCPOS Pro the plugin registers a notice and nothing else. */
final class BootstrapGateTest extends TestCase {
	public function test_missing_pro_registers_only_the_notice_at_plugins_loaded_30(): void {
		// A separate PHP process: no Pro helpers, only the WordPress hook surface the file touches.
		$plugin = var_export( dirname( __DIR__, 2 ) . '/square-terminal-for-woocommerce.php', true );
		$code   = <<<'PHP_CODE'
define( 'ABSPATH', '/' );
$hooks = array();
function add_action( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $name ][] = $callback; $GLOBALS['priorities'][ $name ][] = $priority; }
function add_filter() { throw new LogicException( 'Registered a filter without Pro' ); }
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_basename( $file ) { return basename( $file ); }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/'; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function esc_html__( $text, $domain ) { return $text; }
PHP_CODE;
		$code  .= "\nrequire $plugin;\n";
		$code  .= <<<'PHP_CODE'
foreach ( $hooks['plugins_loaded'] as $callback ) { $callback(); }
ob_start(); foreach ( $hooks['admin_notices'] as $callback ) { $callback(); } $notice = ob_get_clean();
echo json_encode( array( 'hooks' => array_keys( $hooks ), 'notice' => $notice, 'gate_priority' => $GLOBALS['priorities']['plugins_loaded'][0], 'loaded' => class_exists( 'WCPOS\\WooCommercePOS\\SquareTerminal\\Plugin', false ) ) );
PHP_CODE;
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $output, $exit );
		$this->assertSame( 0, $exit, implode( "\n", $output ) );
		$data = json_decode( implode( "\n", $output ), true );
		$this->assertSame( array( 'before_woocommerce_init', 'plugins_loaded', 'admin_notices' ), $data['hooks'] );
		$this->assertFalse( $data['loaded'], 'Plugin::init() never ran' );
		// Pro defines its helpers from plugins_loaded at 20; the gate must run after that.
		$this->assertSame( 30, $data['gate_priority'] );
		$this->assertStringContainsString( 'WooCommerce POS Pro 2.0.0', $data['notice'] );
	}
}
