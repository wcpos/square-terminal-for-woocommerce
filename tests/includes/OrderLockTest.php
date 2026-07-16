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

	public function test_add_option_fallback_takes_over_a_lock_stale_for_more_than_300_seconds(): void {
		$GLOBALS['sqtwc_options']['sqtwc_lock_99'] = 'old-owner|' . ( time() - 301 );
		$lock = new OrderLock();

		self::assertTrue( $lock->acquire( 99 ) );
		self::assertMatchesRegularExpression( '/^[^|]+\\|[0-9]+$/', $GLOBALS['sqtwc_options']['sqtwc_lock_99'] );
		self::assertNotSame( 'old-owner|' . ( time() - 301 ), $GLOBALS['sqtwc_options']['sqtwc_lock_99'] );

		$lock->release( 99 );
		self::assertArrayNotHasKey( 'sqtwc_lock_99', $GLOBALS['sqtwc_options'] );
	}

	public function test_option_release_does_not_delete_lock_owned_by_another_token(): void {
		$lock = new OrderLock();
		self::assertTrue( $lock->acquire( 99 ) );
		$replacement = 'new-owner|' . time();
		$GLOBALS['sqtwc_options']['sqtwc_lock_99'] = $replacement;

		$lock->release( 99 );

		self::assertSame( $replacement, $GLOBALS['sqtwc_options']['sqtwc_lock_99'] );
	}
}
