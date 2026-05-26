<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;
use PHPUnit\Framework\TestCase; use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
final class PaymentFrontendTest extends TestCase { public function test_payment_ui_is_order_pay_centered_and_includes_payment_log(): void { $html=Gateway::render_payment_ui(99,['Created']); self::assertStringContainsString('Square Terminal Payment',$html); self::assertStringContainsString('Payment Log',$html); self::assertStringContainsString('button type="button" id="sqtwc-start-payment"',$html); }
 public function test_payment_js_logs_lifecycle_messages(): void { $js=file_get_contents(dirname(__DIR__,2).'/assets/js/payment.js') ?: ''; self::assertStringContainsString('closest',$js); self::assertStringContainsString('Start Payment',$js); self::assertStringContainsString('Cancel Payment',$js); self::assertStringContainsString('error',$js); }}
