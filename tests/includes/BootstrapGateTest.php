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

	/** The same process shape with Pro's helpers present: the gate passes only when Pro says so. */
	public function test_pro_present_registers_the_runtime_and_the_provider_only_when_compatible(): void {
		foreach ( array( true, false ) as $compatible ) {
			$data = $this->boot_with_pro( $compatible );
			if ( $compatible ) {
				$this->assertNotContains( 'WCPOS\\WooCommercePOS\\SquareTerminal\\pro_requirement_notice', $data['notices'], 'No requirement notice' );
				$this->assertTrue( $data['plugin_loaded'], 'Plugin::init() ran' );
				$this->assertContains( 'woocommerce_payment_gateways', $data['filters'], 'The gateway is registered' );
				$this->assertSame( array( array( 'sqtwc', 'WCPOS\\WooCommercePOS\\SquareTerminal\\Server\\Square_Server_Provider' ) ), $data['providers'] );
				$this->assertContains( 'rest_api_init', $data['actions'] );
			} else {
				$this->assertFalse( $data['plugin_loaded'], 'An incompatible Pro registers nothing' );
				$this->assertSame( array(), $data['filters'] );
				$this->assertSame( array(), $data['providers'] );
				$this->assertSame( array( 'WCPOS\\WooCommercePOS\\SquareTerminal\\pro_requirement_notice' ), $data['notices'] );
			}
		}
	}

	private function boot_with_pro( bool $compatible ): array {
		$plugin = var_export( dirname( __DIR__, 2 ) . '/square-terminal-for-woocommerce.php', true );
		$code   = 'define( "SQTWC_TEST_PRO_COMPATIBLE", ' . var_export( $compatible, true ) . " );\n";
		$code  .= <<<'PHP_CODE'
define( 'ABSPATH', '/' );
$GLOBALS['hooks'] = array(); $GLOBALS['filters'] = array(); $GLOBALS['providers'] = array();
function add_action( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $name ][] = $callback; $GLOBALS['priorities'][ $name ][] = $priority; }
function add_filter( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['filters'][] = $name; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_basename( $file ) { return basename( $file ); }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/'; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function esc_html__( $text, $domain ) { return $text; }
function wp_next_scheduled( $hook ) { return time(); }
class WC_Payment_Gateway {}
PHP_CODE;
		$code  .= "\nrequire $plugin;\n";
		$code  .= <<<'PHP_CODE'
// Pro defines its helpers from its own plugins_loaded hook at priority 20.
add_action( 'plugins_loaded', static function () {
	function wcpos_pro_requires( $version, $file = '' ) { return SQTWC_TEST_PRO_COMPATIBLE; }
	function wcpos_pro_register_server_provider( $id, $class ) { $GLOBALS['providers'][] = array( $id, $class ); }
}, 20 );
$callbacks = $GLOBALS['hooks']['plugins_loaded']; $priorities = $GLOBALS['priorities']['plugins_loaded'];
array_multisort( $priorities, $callbacks );
foreach ( $callbacks as $callback ) { $callback(); }
echo json_encode( array( 'notices' => array_map( static function ( $c ) { return is_string( $c ) ? $c : 'other'; }, $GLOBALS['hooks']['admin_notices'] ?? array() ), 'plugin_loaded' => class_exists( 'WCPOS\WooCommercePOS\SquareTerminal\Plugin', false ), 'filters' => array_values( array_unique( $GLOBALS['filters'] ) ), 'actions' => array_keys( $GLOBALS['hooks'] ), 'providers' => $GLOBALS['providers'] ) );
PHP_CODE;
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $output, $exit );
		$this->assertSame( 0, $exit, implode( "\n", $output ) );

		return json_decode( implode( "\n", $output ), true );
	}
}
