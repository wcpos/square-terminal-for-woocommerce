<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Legacy_Adoption;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

/** Checkouts the old panel left live are Pro's, under both locks, on a fresh read; Pro owns them while its row is live. */
final class LegacyAdoptionTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['sqtwc_options']           = array( 'woocommerce_sqtwc_settings' => array( 'environment' => 'sandbox', 'collection_method' => 'terminal' ) );
		$GLOBALS['sqtwc_orders']            = array();
		$GLOBALS['sqtwc_adopted']           = array();
		$GLOBALS['sqtwc_pro_adoptions']     = array();
		$GLOBALS['sqtwc_ledger_rows']       = array();
		$GLOBALS['sqtwc_free_lock_held']    = false;
		$GLOBALS['sqtwc_wc_get_orders_args'] = array();
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_uses_pro_panel'] = true;
		unset( $GLOBALS['sqtwc_pro_adopt_result'], $GLOBALS['sqtwc_order_query_results'] );
		Settings::reset_cache_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_uses_pro_panel'] = false;
		unset( $GLOBALS['sqtwc_pro_adopt_result'], $GLOBALS['sqtwc_free_lock_held'] );
	}

	private function order( int $id, string $checkout = 'TC1', string $status = 'IN_PROGRESS' ): \SQTWC_Test_Order {
		$order = new \SQTWC_Test_Order( $id );
		$order->meta['_sqtwc_checkout_id']     = $checkout;
		$order->meta['_sqtwc_checkout_status'] = $status;
		$order->meta['_sqtwc_current_attempt_id'] = 'att-1';
		$order->meta['_sqtwc_attempt_started']    = time() - 60;
		$GLOBALS['sqtwc_orders'][ $id ] = $order;
		return $order;
	}

	public function test_an_attempt_older_than_the_window_is_the_old_sweeps_not_pros(): void {
		$old = $this->order( 4 );
		$old->meta['_sqtwc_attempt_started'] = time() - Legacy_Adoption::ADOPTION_WINDOW - 1;
		self::assertSame( '', Legacy_Adoption::action_ref( $old ) );
		// Its outcome is unknown until the old sweep has read it: Pro's panel is held, not offered.
		$held = Legacy_Adoption::adopt_order( 4 );
		self::assertSame( 'sqtwc_adoption_stale_attempt', $held->get_error_code() );
		self::assertFalse( Legacy_Adoption::is_deferral( $held ), 'The upgrade pass drops it; the sweep closes it' );
		$old->meta['_sqtwc_checkout_status'] = 'CANCELED';
		self::assertNull( Legacy_Adoption::adopt_order( 4 ), 'Read and ended: nothing to hold' );
		// Live and recent when read before the locks; the copy under them shows the pointer aged out
		// (a retry of a lost create kept the first start time): held, not adopted.
		$aging = $this->order( 6 );
		$GLOBALS['sqtwc_wc_get_order_callback'] = static function ( $id ) use ( $aging ) {
			if ( \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$held ) {
				$fresh = clone $aging;
				$fresh->meta['_sqtwc_attempt_started'] = time() - Legacy_Adoption::ADOPTION_WINDOW - 1;
				return $fresh;
			}
			return $aging;
		};
		try {
			self::assertSame( 'sqtwc_adoption_stale_attempt', Legacy_Adoption::adopt_order( 6 )->get_error_code() );
		} finally {
			unset( $GLOBALS['sqtwc_wc_get_order_callback'] );
		}
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
		$unknown = $this->order( 5 );
		unset( $unknown->meta['_sqtwc_attempt_started'] );
		self::assertSame( '', Legacy_Adoption::action_ref( $unknown ), 'No start time, no adoption' );
	}

	public function test_a_deferred_order_stays_in_the_queue_and_the_pass_is_not_done(): void {
		$this->order( 300, 'TC300' );
		$this->order( 301, 'TC301' );
		$GLOBALS['sqtwc_order_query_results'] = array( 300, 301 );
		add_option( 'sqtwc_lock_300', 'till|' . time(), '', 'no' ); // A completion in flight on 300.
		Legacy_Adoption::upgrade();
		self::assertSame( array( 300 => 1 ), get_option( 'sqtwc_adoption_queue' ) );
		self::assertFalse( get_option( 'sqtwc_adoption_version' ) );
		self::assertCount( 1, $GLOBALS['sqtwc_pro_adoptions'] );
		delete_option( 'sqtwc_lock_300' );
		Legacy_Adoption::upgrade();
		self::assertCount( 2, $GLOBALS['sqtwc_pro_adoptions'] );
		self::assertSame( Legacy_Adoption::VERSION, get_option( 'sqtwc_adoption_version' ) );
	}

	public function test_a_live_checkout_is_the_action_in_the_current_environment_and_a_final_one_is_nothing(): void {
		self::assertSame( 'sandbox:TC1', Legacy_Adoption::action_ref( $this->order( 1 ) ) );
		self::assertSame( '', Legacy_Adoption::action_ref( $this->order( 2, 'TC2', 'COMPLETED' ) ) );
		self::assertSame( '', Legacy_Adoption::action_ref( $this->order( 3, '', 'PENDING' ) ), 'An unconfirmed create has no checkout to adopt' );
	}

	public function test_adopt_order_hands_the_live_checkout_to_pro_under_both_locks_and_keeps_the_reference(): void {
		$order  = $this->order( 7 );
		$result = Legacy_Adoption::adopt_order( 7 );
		self::assertSame( 'row-1', $result['id'] );
		self::assertSame( array( array( 7, 'sqtwc', 'sandbox:TC1', '12.34', 'USD' ) ), $GLOBALS['sqtwc_pro_adoptions'] );
		self::assertSame( 'sandbox:TC1', $order->meta[ Legacy_Adoption::META_ADOPTED ] );
		self::assertArrayNotHasKey( 'sqtwc_lock_7', $GLOBALS['sqtwc_options'], 'The old lock is released' );
		// Already Pro's: nothing more, and no second adoption.
		self::assertNull( Legacy_Adoption::adopt_order( 7 ) );
		self::assertCount( 1, $GLOBALS['sqtwc_pro_adoptions'] );
	}

	public function test_nothing_is_adopted_for_a_paid_order_a_final_checkout_or_under_the_carve_out(): void {
		$paid = $this->order( 8 );
		$paid->paid = true;
		self::assertNull( Legacy_Adoption::adopt_order( 8 ) );
		$this->order( 9, 'TC9', 'CANCELED' );
		self::assertNull( Legacy_Adoption::adopt_order( 9 ) );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
	}

	public function test_a_held_lock_defers_and_a_refusal_from_pro_is_final(): void {
		$this->order( 10 );
		$GLOBALS['sqtwc_free_lock_held'] = true;
		$deferred = Legacy_Adoption::adopt_order( 10 );
		self::assertInstanceOf( \WP_Error::class, $deferred );
		self::assertTrue( Legacy_Adoption::is_deferral( $deferred ) );
		$GLOBALS['sqtwc_free_lock_held'] = false;
		// The old paths' own lock held: a completion in flight.
		add_option( 'sqtwc_lock_10', 'someone|' . time(), '', 'no' );
		$completing = Legacy_Adoption::adopt_order( 10 );
		self::assertSame( 'sqtwc_adoption_completing', $completing->get_error_code() );
		self::assertTrue( Legacy_Adoption::is_deferral( $completing ) );
		delete_option( 'sqtwc_lock_10' );
		$GLOBALS['sqtwc_pro_adopt_result'] = new \WP_Error( 'wcpos_bad', 'refused' );
		$refused = Legacy_Adoption::adopt_order( 10 );
		self::assertSame( 'wcpos_bad', $refused->get_error_code() );
		self::assertFalse( Legacy_Adoption::is_deferral( $refused ) );
	}

	public function test_the_fresh_copy_under_the_lock_decides(): void {
		// The order read before the lock is live; the copy read under it is paid: nothing applies.
		$stale = $this->order( 11 );
		$GLOBALS['sqtwc_cleaned_post_caches']      = array();
		$GLOBALS['sqtwc_clean_post_cache_callback'] = static function ( int $id ): void {
			$GLOBALS['sqtwc_cleaned_post_caches'][] = $id;
		};
		$GLOBALS['sqtwc_wc_get_order_callback'] = static function ( $id ) use ( $stale ) {
			// Fresh only to a read made under both locks with the caches cleared first (the copy a
			// completion that just released the old lock wrote); a plain read returns the stale copy.
			if ( \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$held && '' !== (string) get_option( 'sqtwc_lock_11', '' ) && in_array( 11, $GLOBALS['sqtwc_cleaned_post_caches'] ?? array(), true ) ) {
				$fresh = clone $stale;
				$fresh->paid = true;
				return $fresh;
			}
			return $stale;
		};
		try {
			self::assertNull( Legacy_Adoption::adopt_order( 11 ) );
		} finally {
			unset( $GLOBALS['sqtwc_wc_get_order_callback'], $GLOBALS['sqtwc_clean_post_cache_callback'] );
		}
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
	}

	public function test_pro_owns_an_adopted_checkout_only_while_its_row_is_live(): void {
		$order = $this->order( 12 );
		Legacy_Adoption::adopt_order( 12 );
		self::assertTrue( Legacy_Adoption::owns_checkout( $order, 'TC1' ), 'A record whose row cannot be read counts as owned' );
		$GLOBALS['sqtwc_ledger_rows'][12] = array( array( 'id' => 'row-1', 'status' => 'pending' ) );
		self::assertTrue( Legacy_Adoption::owns_order( $order ) );
		$GLOBALS['sqtwc_ledger_rows'][12] = array( array( 'id' => 'row-1', 'status' => 'voided' ) );
		self::assertFalse( Legacy_Adoption::owns_checkout( $order, 'TC1' ), 'Once the leg ended without money the old paths act again' );
		self::assertFalse( Legacy_Adoption::owns_order( $order ) );
		self::assertTrue( Legacy_Adoption::is_adopted( 'sandbox:TC1' ), 'The record itself is never cleared' );
		self::assertFalse( Legacy_Adoption::owns_checkout( $order, 'TC_OTHER' ) );
	}

	public function test_the_upgrade_pass_snapshots_unpaid_orders_with_a_checkout_pointer_and_pages_them(): void {
		$ids = range( 100, 129 );
		foreach ( $ids as $id ) {
			$this->order( $id, 'TC' . $id );
		}
		$GLOBALS['sqtwc_order_query_results'] = $ids;
		Legacy_Adoption::upgrade();
		$args = $GLOBALS['sqtwc_wc_get_orders_args'][0];
		self::assertSame( 'ids', $args['return'] );
		self::assertSame( '_sqtwc_checkout_id', $args['meta_key'] );
		self::assertSame( 'EXISTS', $args['meta_compare'] );
		self::assertSame( Legacy_Adoption::UNPAID_STATUSES, $args['status'] );
		self::assertCount( 25, $GLOBALS['sqtwc_pro_adoptions'], 'One page per request' );
		self::assertCount( 5, get_option( 'sqtwc_adoption_queue' ) );
		Legacy_Adoption::upgrade();
		self::assertCount( 30, $GLOBALS['sqtwc_pro_adoptions'] );
		self::assertSame( Legacy_Adoption::VERSION, get_option( 'sqtwc_adoption_version' ) );
		self::assertCount( 1, $GLOBALS['sqtwc_wc_get_orders_args'], 'The snapshot is taken once' );
		Legacy_Adoption::upgrade();
		self::assertCount( 30, $GLOBALS['sqtwc_pro_adoptions'], 'Done is done' );
	}

	public function test_the_upgrade_pass_does_not_run_under_the_carve_out(): void {
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_uses_pro_panel'] = false;
		$this->order( 200 );
		$GLOBALS['sqtwc_order_query_results'] = array( 200 );
		Legacy_Adoption::upgrade();
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
		self::assertFalse( get_option( 'sqtwc_adoption_version' ) );
	}
}
