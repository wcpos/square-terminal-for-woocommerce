<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareErrorMapper;

final class SquareErrorMapperTest extends TestCase {
	/**
	 * @return array<string,array{string,string,bool,string}>
	 */
	public static function mapping_provider(): array {
		return array(
			'authentication'  => array( 'AUTHENTICATION_ERROR', 'UNAUTHORIZED', false, 'Square connection failed — check plugin settings.' ),
			'rate category'   => array( 'RATE_LIMIT_ERROR', 'RATE_LIMITED', true, 'Square is temporarily busy. Please try again.' ),
			'rate literal'    => array( 'RATE_LIMITED', 'UNKNOWN', true, 'Square is temporarily busy. Please try again.' ),
			'api error'       => array( 'API_ERROR', 'INTERNAL_SERVER_ERROR', true, 'Square is temporarily unavailable. Please try again.' ),
			'unpaired device' => array( 'INVALID_REQUEST_ERROR', 'NOT_FOUND', false, 'This terminal is no longer paired. Choose another terminal or pair it again.' ),
			'bad location'    => array( 'INVALID_REQUEST_ERROR', 'INVALID_LOCATION', false, 'The configured Square location is invalid. Check plugin settings.' ),
			'invalid request' => array( 'INVALID_REQUEST_ERROR', 'BAD_REQUEST', false, 'Square rejected the payment request. Check the terminal and plugin settings.' ),
			'decline'         => array( 'PAYMENT_METHOD_ERROR', 'CARD_DECLINED', false, 'The payment was declined. Ask the buyer to use another payment method.' ),
		);
	}

	#[DataProvider( 'mapping_provider' )]
	public function test_maps_square_error_taxonomy( string $category, string $code, bool $retriable, string $message ): void {
		$result = ( new SquareErrorMapper() )->map(
			array(
				'category' => $category,
				'code'     => $code,
				'detail'   => 'provider-only secret detail',
			)
		);

		self::assertSame( $retriable, $result['retriable'] );
		self::assertSame( $message, $result['cashier_message'] );
		self::assertStringNotContainsString( 'provider-only', $result['cashier_message'] );
		self::assertSame( 'provider-only secret detail', $result['log_context']['detail'] );
	}

	public function test_timeout_exception_is_retriable_without_exposing_raw_message(): void {
		$result = ( new SquareErrorMapper() )->map( new \RuntimeException( 'cURL timeout containing provider detail' ) );

		self::assertTrue( $result['retriable'] );
		self::assertSame( 'Unable to reach Square. Please try again.', $result['cashier_message'] );
		self::assertStringNotContainsString( 'provider detail', $result['cashier_message'] );
		self::assertSame( 'cURL timeout containing provider detail', $result['log_context']['detail'] );
	}
}
