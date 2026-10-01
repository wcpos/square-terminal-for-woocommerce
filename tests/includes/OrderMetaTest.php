<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderMeta;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

final class OrderMetaTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_options'] = array();
		Settings::reset_cache_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['sqtwc_options'] = array();
		Settings::reset_cache_for_tests();
	}

	public function test_reload_order_evicts_post_and_hpos_caches_before_loading(): void {
		$log = array();
		$GLOBALS['sqtwc_clean_post_cache_callback'] = static function ( $id ) use ( &$log ) {
			$log[] = 'posts:' . $id;
		};
		$GLOBALS['sqtwc_order_cache_remove_callback'] = static function ( $id ) use ( &$log ) {
			$log[] = 'hpos:' . $id;
		};
		$GLOBALS['sqtwc_wc_get_order_callback'] = static function ( $id ) use ( &$log ) {
			$log[] = 'load:' . $id;
			return new \SQTWC_Test_Order( 42 );
		};

		try {
			$order = OrderMeta::reload_order( 42 );

			self::assertSame( array( 'posts:42', 'hpos:42', 'load:42' ), $log );
			self::assertSame( 42, $order->get_id() );
		} finally {
			unset(
				$GLOBALS['sqtwc_clean_post_cache_callback'],
				$GLOBALS['sqtwc_order_cache_remove_callback'],
				$GLOBALS['sqtwc_wc_get_order_callback']
			);
		}
	}

	public static function gateway_titles(): array {
		return array(
			'default' => array( '', 'Square Terminal' ),
			'configured' => array( 'Pay by Square', 'Pay by Square' ),
		);
	}

	#[DataProvider( 'gateway_titles' )]
	public function test_claim_sets_gateway_and_title_without_saving( string $configured, string $expected ): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'title' => $configured );
		$order = new class() extends \SQTWC_Test_Order {
			public function save() {
				throw new \LogicException( 'The helper must not save the order.' );
			}
		};

		OrderMeta::claim_order_gateway( $order );

		self::assertSame( 'sqtwc', $order->get_payment_method() );
		self::assertSame( $expected, $order->payment_method_title );
	}

	public function test_claim_keeps_existing_gateway_and_custom_title(): void {
		$order = new class() extends \SQTWC_Test_Order {
			public function set_payment_method( $method ) {
				throw new \LogicException( 'An already claimed gateway must not be set again.' );
			}
		};
		$order->payment_method = 'sqtwc';
		$order->payment_method_title = 'Original custom title';

		OrderMeta::claim_order_gateway( $order );

		self::assertSame( 'sqtwc', $order->get_payment_method() );
		self::assertSame( 'Original custom title', $order->payment_method_title );
	}

	#[DataProvider( 'gateway_titles' )]
	public function test_claim_fills_empty_title_on_existing_gateway( string $configured, string $expected ): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'title' => $configured );
		$order = new \SQTWC_Test_Order();
		$order->set_payment_method( 'sqtwc' );

		OrderMeta::claim_order_gateway( $order );

		self::assertSame( 'sqtwc', $order->get_payment_method() );
		self::assertSame( $expected, $order->get_payment_method_title() );
	}

	public function test_start_attempt_leaves_payment_method_untouched(): void {
		$order = new class() extends \SQTWC_Test_Order {
			public string $saved_method = '';
			public function save() {
				$this->saved_method = $this->get_payment_method();
			}
		};
		$order->set_payment_method( 'pos_cash' );

		OrderMeta::start_attempt( $order, 'attempt', 'idem', 'device', array() );

		self::assertSame( 'pos_cash', $order->saved_method );
		self::assertSame( 'pos_cash', $order->get_payment_method() );
	}
}
