<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;
use PHPUnit\Framework\TestCase; use WCPOS\WooCommercePOS\SquareTerminal\AjaxHandler;
final class CountingAdapter { public int $calls=0; public function create_checkout(array $d): array { $this->calls++; return ['id'=>'chk','status'=>'PENDING','reference_id'=>$d['reference_id'],'payment_ids'=>[]]; }}
final class AjaxHandlerTest extends TestCase { public function test_create_checkout_rejects_before_side_effect_without_access(): void { $order=new \SQTWC_Test_Order(99); $GLOBALS['sqtwc_orders'][99]=$order; $adapter=new CountingAdapter(); $result=(new AjaxHandler($adapter))->create_terminal_checkout(['order_id'=>99,'device_id'=>'D']); self::assertSame(403,$result['status']); self::assertSame(0,$adapter->calls); }
 public function test_create_checkout_requires_nonce_for_logged_in_staff(): void { $order=new \SQTWC_Test_Order(99); $GLOBALS['sqtwc_orders'][99]=$order; $GLOBALS['sqtwc_current_user_can']=true; $GLOBALS['sqtwc_nonce_valid']=false; $result=(new AjaxHandler(new CountingAdapter()))->create_terminal_checkout(['order_id'=>99,'device_id'=>'D','logged_in'=>true]); self::assertSame(403,$result['status']); }}
