<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;
use PHPUnit\Framework\TestCase; use WCPOS\WooCommercePOS\SquareTerminal\Utils\CurrencyConverter;
final class CurrencyConverterTest extends TestCase { public function test_converts_decimal_to_minor_units(): void { self::assertSame(1234, CurrencyConverter::to_minor_units('12.34','USD')); self::assertSame(1234, CurrencyConverter::to_minor_units('1234','JPY')); }}
