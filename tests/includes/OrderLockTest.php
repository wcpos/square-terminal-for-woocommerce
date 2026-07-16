<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock;

final class OrderLockTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['sqtwc_options'] = array();
	}

	public function test_add_option_fallback_rejects_a_held_lock(): void {
		$first  = new OrderLock();
		$second = new OrderLock();

		self::assertTrue( $first->acquire( 99 ) );
		self::assertFalse( $second->acquire( 99 ) );

		$first->release( 99 );
		self::assertTrue( $second->acquire( 99 ) );
		$second->release( 99 );
	}

	public function test_add_option_fallback_takes_over_a_stale_lock(): void {
		$GLOBALS['sqtwc_options']['sqtwc_lock_99'] = time() - 61;
		$lock = new OrderLock();

		self::assertTrue( $lock->acquire( 99 ) );
		self::assertGreaterThan( time() - 5, $GLOBALS['sqtwc_options']['sqtwc_lock_99'] );

		$lock->release( 99 );
		self::assertArrayNotHasKey( 'sqtwc_lock_99', $GLOBALS['sqtwc_options'] );
	}
}
