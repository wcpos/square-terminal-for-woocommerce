<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;

final class ScopedDependencyTest extends TestCase {
	private const VENDOR_PREFIX = 'WCPOS\\WooCommercePOS\\SquareTerminal\\Vendor\\';

	private function scoped_autoload(): string {
		return dirname( __DIR__, 2 ) . '/vendor_scoped/autoload.php';
	}

	/**
	 * Run a snippet against the scoped autoloader in a clean subprocess.
	 *
	 * The scoped classes cannot be exercised in the test process, which already
	 * has the unscoped SDK aliased into the Vendor namespace by bootstrap.php.
	 *
	 * @param string $snippet PHP to execute after the autoloader is required.
	 * @return array{status:int,output:string}
	 */
	private function run_scoped( string $snippet ): array {
		$code   = 'require ' . var_export( $this->scoped_autoload(), true ) . ';' . $snippet;
		$output = array();
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $output, $status );

		return array(
			'status' => $status,
			'output' => implode( "\n", $output ),
		);
	}

	private function require_scoped_build(): void {
		if ( ! is_file( $this->scoped_autoload() ) ) {
			self::markTestSkipped( 'vendor_scoped not built; run composer build:scoped-vendor' );
		}
	}

	public function test_php_scoper_is_not_composer_dependency(): void {
		$json = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), true );

		self::assertArrayNotHasKey( 'humbug/php-scoper', $json['require-dev'] ?? array() );
		self::assertArrayNotHasKey( 'humbug/php-scoper', $json['require'] ?? array() );
	}

	public function test_build_script_uses_phar(): void {
		$json = file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' );

		self::assertStringContainsString( 'php-scoper.phar', $json );
	}

	public function test_a_concrete_psr18_client_is_a_runtime_dependency(): void {
		$json = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), true );

		// square/square only requires the virtual psr/http-client-implementation,
		// which Composer never satisfies on its own. Without a concrete client
		// every Square request throws before it is sent.
		self::assertArrayHasKey( 'guzzlehttp/guzzle', $json['require'] ?? array() );
	}

	public function test_client_factory_passes_an_explicit_http_client(): void {
		$factory = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Services/SquareClientFactory.php' );

		// php-http/discovery resolves a client by scanning for well-known class
		// names, which php-scoper rewrites — so the client is handed to the SDK
		// directly rather than discovered.
		self::assertStringContainsString( "'client'", $factory );
	}

	public function test_scoped_square_client_is_loadable(): void {
		$this->require_scoped_build();

		$result = $this->run_scoped(
			'exit(class_exists(' . var_export( self::VENDOR_PREFIX . 'Square\\SquareClient', true ) . ') ? 0 : 1);'
		);

		self::assertSame( 0, $result['status'], 'Scoped Square SDK is not loadable: ' . $result['output'] );
	}

	public function test_scoped_build_ships_a_loadable_psr18_client(): void {
		$this->require_scoped_build();

		$result = $this->run_scoped(
			'$cls = ' . var_export( self::VENDOR_PREFIX . 'GuzzleHttp\\Client', true ) . ';'
			. '$iface = ' . var_export( self::VENDOR_PREFIX . 'Psr\\Http\\Client\\ClientInterface', true ) . ';'
			. 'if (!class_exists($cls)) { echo "client class missing"; exit(1); }'
			. 'exit((new $cls()) instanceof $iface ? 0 : 1);'
		);

		self::assertSame( 0, $result['status'], 'No loadable PSR-18 client in the scoped build: ' . $result['output'] );
	}

	public function test_scoper_prefix(): void {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/scoper.inc.php' );

		self::assertMatchesRegularExpression( "/'prefix'\\s*=>\\s*'WCPOS\\\\\\\\WooCommercePOS\\\\\\\\SquareTerminal\\\\\\\\Vendor'/", $contents );
	}

	public function test_bootstrap_prefers_scoped_vendor(): void {
		$plugin       = file_get_contents( dirname( __DIR__, 2 ) . '/square-terminal-for-woocommerce.php' );
		$scoped_pos   = strpos( $plugin, 'vendor_scoped/autoload.php' );
		$unscoped_pos = strpos( $plugin, 'vendor/autoload.php' );

		self::assertNotFalse( $scoped_pos );
		self::assertNotFalse( $unscoped_pos );
		self::assertLessThan( $unscoped_pos, $scoped_pos );
	}
}
