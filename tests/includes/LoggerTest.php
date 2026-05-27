<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;
use PHPUnit\Framework\TestCase; use WCPOS\WooCommercePOS\SquareTerminal\Logger;
final class LoggerTest extends TestCase { public function test_sanitizes_secret_context_recursively(): void { $data=['access_token'=>'abc','nested'=>['signature'=>'sig','Authorization'=>'Bearer x','ok'=>'yes']]; $clean=Logger::sanitize_context($data); self::assertSame('[redacted]',$clean['access_token']); self::assertSame('[redacted]',$clean['nested']['signature']); self::assertSame('[redacted]',$clean['nested']['Authorization']); self::assertSame('yes',$clean['nested']['ok']); }}
