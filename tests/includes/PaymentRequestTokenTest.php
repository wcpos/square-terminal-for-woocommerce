<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;
use PHPUnit\Framework\TestCase; use WCPOS\WooCommercePOS\SquareTerminal\PaymentRequestToken;
final class PaymentRequestTokenTest extends TestCase { public function test_token_round_trip_wrong_order_and_expiry(): void { $t=PaymentRequestToken::create(99, time()+60); self::assertTrue(PaymentRequestToken::verify($t,99)); self::assertFalse(PaymentRequestToken::verify($t,100)); $expired=PaymentRequestToken::create(99,time()-1); self::assertFalse(PaymentRequestToken::verify($expired,99)); }}
