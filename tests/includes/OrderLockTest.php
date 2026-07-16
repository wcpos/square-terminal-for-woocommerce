<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock;

final class OrderLockWpdb {
	public string $options = 'wp_options';
	public bool $replace_before_delete = false;
	private array $prepared_args = array();

	public function prepare( string $query, ...$args ): string {
		$this->prepared_args = $args;

		return $query;
	}

	public function get_var( string $query ) {
		unset( $query );

		return null;
	}

	public function query( string $query ): int {
		unset( $query );
		$option_name = (string) ( $this->prepared_args[0] ?? '' );
		$lock_value  = (string) ( $this->prepared_args[1] ?? '' );
		if ( $this->replace_before_delete ) {
			$GLOBALS['sqtwc_options'][ $option_name ] = 'replacement-owner|' . time();
			$this->replace_before_delete = false;
		}

		if ( (string) ( $GLOBALS['sqtwc_options'][ $option_name ] ?? '' ) !== $lock_value ) {
			return 0;
		}

		unset( $GLOBALS['sqtwc_options'][ $option_name ] );

		return 1;
	}
}

final class OrderLockTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['sqtwc_options'] = array();
		$GLOBALS['sqtwc_logs']    = array();
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
		$created = time() - 301;
		$GLOBALS['wpdb'] = new OrderLockWpdb();
		$GLOBALS['sqtwc_options']['sqtwc_lock_99'] = 'old-owner|' . $created;
		$lock = new OrderLock();

		self::assertTrue( $lock->acquire( 99 ) );
		self::assertMatchesRegularExpression( '/^[^|]+\\|[0-9]+$/', $GLOBALS['sqtwc_options']['sqtwc_lock_99'] );
		self::assertNotSame( 'old-owner|' . $created, $GLOBALS['sqtwc_options']['sqtwc_lock_99'] );
		self::assertCount( 1, $GLOBALS['sqtwc_logs'] );
		self::assertSame( 'warning', $GLOBALS['sqtwc_logs'][0][0] );
		self::assertSame( 'Square Terminal option lock stale lease taken over', $GLOBALS['sqtwc_logs'][0][1] );
		self::assertSame( 99, $GLOBALS['sqtwc_logs'][0][2]['order_id'] );
		self::assertGreaterThanOrEqual( 301, $GLOBALS['sqtwc_logs'][0][2]['lease_age_seconds'] );

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

	public function test_stale_takeover_does_not_delete_a_replacement_lock(): void {
		$wpdb = new OrderLockWpdb();
		$wpdb->replace_before_delete = true;
		$GLOBALS['wpdb'] = $wpdb;
		$GLOBALS['sqtwc_options']['sqtwc_lock_99'] = 'expired-owner|' . ( time() - 301 );

		self::assertFalse( ( new OrderLock() )->acquire( 99 ) );
		self::assertStringStartsWith( 'replacement-owner|', $GLOBALS['sqtwc_options']['sqtwc_lock_99'] );
	}
}
