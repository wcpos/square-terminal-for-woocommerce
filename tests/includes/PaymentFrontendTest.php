<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

final class PaymentFrontendTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_orders']                            = array();
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array();
		Settings::reset_cache_for_tests();
	}

	public function test_payment_ui_renders_device_selector_actions_and_live_status(): void {
		$html = Gateway::render_payment_ui( 0 );

		self::assertStringContainsString( 'id="sqtwc-payment"', $html );
		self::assertStringContainsString( 'Square Terminal Payment', $html );
		self::assertStringContainsString( 'id="sqtwc-device-id"', $html );
		self::assertStringContainsString( 'id="sqtwc-device-id-manual"', $html );
		self::assertStringContainsString( 'data-sqtwc-action="start"', $html );
		self::assertStringContainsString( 'data-sqtwc-action="cancel"', $html );
		self::assertStringContainsString( 'data-sqtwc-action="check"', $html );
		self::assertStringContainsString( 'data-sqtwc-action="detach"', $html );
		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( 'aria-live="polite"', $html );
	}

	public function test_payment_ui_emits_resume_attributes_for_open_unpaid_attempt(): void {
		$order = new \SQTWC_Test_Order( 99 );
		$order->update_meta_data( '_sqtwc_checkout_id', 'chk_open' );
		$order->update_meta_data( '_sqtwc_current_attempt_id', 'att_1' );
		$order->update_meta_data( '_sqtwc_device_id', 'dev_1' );
		$order->update_meta_data( '_sqtwc_checkout_status', 'IN_PROGRESS' );
		$GLOBALS['sqtwc_orders'][99] = $order;

		$html = Gateway::render_payment_ui( 99 );

		self::assertStringContainsString( 'data-resume="1"', $html );
		self::assertStringContainsString( 'data-checkout-id="chk_open"', $html );
		self::assertStringContainsString( 'data-device-id="dev_1"', $html );
		self::assertStringContainsString( 'data-order-key="key"', $html );
	}

	public function test_payment_ui_omits_resume_when_order_is_paid(): void {
		$order = new \SQTWC_Test_Order( 99 );
		$order->update_meta_data( '_sqtwc_checkout_id', 'chk_open' );
		$order->paid                 = true;
		$GLOBALS['sqtwc_orders'][99] = $order;

		$html = Gateway::render_payment_ui( 99 );

		self::assertStringNotContainsString( 'data-resume="1"', $html );
	}

	public function test_debug_log_panel_visibility_follows_setting(): void {
		$html = Gateway::render_payment_ui( 0 );
		self::assertStringNotContainsString( 'id="sqtwc-log-panel"', $html );

		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings'] = array( 'checkout_debug_logs' => 'yes' );
		Settings::reset_cache_for_tests();

		$html = Gateway::render_payment_ui( 0 );
		self::assertStringContainsString( 'id="sqtwc-log-panel"', $html );
		self::assertStringContainsString( 'data-sqtwc-action="log-copy"', $html );
		self::assertStringContainsString( 'data-sqtwc-action="log-clear"', $html );
	}

	public function test_payment_js_implements_chained_polling_not_setinterval(): void {
		$js = file_get_contents( \dirname( __DIR__, 2 ) . '/assets/js/payment.js' ) ?: '';

		self::assertStringContainsString( 'module.exports', $js );
		self::assertStringContainsString( 'createController', $js );
		self::assertStringContainsString( 'setTimeout', $js );
		self::assertStringNotContainsString( 'setInterval', $js );
		self::assertStringContainsString( 'textContent', $js );
		self::assertStringContainsString( "force: '1'", $js );
		self::assertStringContainsString( 'data-sqtwc-action', $js );
		self::assertStringContainsString( 'pagehide', $js );
	}
}
