<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;
use PHPUnit\Framework\TestCase; use WCPOS\WooCommercePOS\SquareTerminal\{OrderAccess,PaymentRequestToken};
final class OrderAccessTest extends TestCase { protected function setUp(): void { $GLOBALS['sqtwc_current_user_can']=false; }
 public function test_allows_staff_order_key_or_valid_token_and_rejects_id_only(): void { $order=new \SQTWC_Test_Order(99); $order->key='abc'; self::assertFalse(OrderAccess::can_mutate_order($order, [])); $GLOBALS['sqtwc_current_user_can']=true; self::assertTrue(OrderAccess::can_mutate_order($order, [])); $GLOBALS['sqtwc_current_user_can']=false; self::assertTrue(OrderAccess::can_mutate_order($order, ['order_key'=>'abc'])); self::assertTrue(OrderAccess::can_mutate_order($order, ['payment_request_token'=>PaymentRequestToken::create(99,time()+60)])); }}
