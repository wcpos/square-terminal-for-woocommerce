<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Logger;

final class LoggerTest extends TestCase {
	public function test_sanitizes_secret_context_recursively(): void {
		$data = array(
			'access_token' => 'abc',
			'nested'       => array(
				'signature' => 'sig',
				'Authorization' => 'Bearer x',
				'signature_key' => 'key',
				'device_code' => 'pair-code',
				'hmac' => 'hash',
				'ok' => 'yes',
			),
		);
		$clean = Logger::sanitize_context( $data );

		self::assertSame( '[redacted]', $clean['access_token'] );
		self::assertSame( '[redacted]', $clean['nested']['signature'] );
		self::assertSame( '[redacted]', $clean['nested']['Authorization'] );
		self::assertSame( '[redacted]', $clean['nested']['signature_key'] );
		self::assertSame( '[redacted]', $clean['nested']['device_code'] );
		self::assertSame( '[redacted]', $clean['nested']['hmac'] );
		self::assertSame( 'yes', $clean['nested']['ok'] );
	}

	public function test_truncates_provider_detail_values_to_one_thousand_characters(): void {
		$clean = Logger::sanitize_context( array( 'detail' => str_repeat( 'x', 1200 ) ) );

		self::assertSame( 1000, strlen( $clean['detail'] ) );
	}
}
