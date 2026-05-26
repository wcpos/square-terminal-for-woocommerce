<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;
use PHPUnit\Framework\TestCase; use WCPOS\WooCommercePOS\SquareTerminal\Gateway;
final class GatewayTest extends TestCase { public function test_register_gateway_appends_class(): void { self::assertContains(Gateway::class, Gateway::register_gateway(['Other'])); }
 public function test_process_payment_redirects_unpaid_to_order_pay_and_paid_to_thank_you(): void { $order=new \SQTWC_Test_Order(99); $GLOBALS['sqtwc_orders'][99]=$order; $gateway=new Gateway(); $result=$gateway->process_payment(99); self::assertSame('success',$result['result']); self::assertStringContainsString('/checkout/order-pay/99/', $result['redirect']); $order->paid=true; $result=$gateway->process_payment(99); self::assertSame('/thank-you',$result['redirect']); }}
