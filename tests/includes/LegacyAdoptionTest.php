<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Legacy_Adoption;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;

require_once __DIR__ . '/SquareServerProviderTest.php'; // QueueTransport.

/** Checkouts the old panel left live are Pro's, under both locks, on a fresh read; Pro owns them while its row is live. */
final class LegacyAdoptionTest extends TestCase {
	private QueueTransport $square;

	protected function setUp(): void {
		$GLOBALS['sqtwc_options']           = array( 'woocommerce_sqtwc_settings' => array( 'environment' => 'sandbox', 'collection_method' => 'terminal', 'sandbox_access_token' => 'sb-token', 'production_access_token' => 'prod-token' ) );
		$GLOBALS['sqtwc_transients']        = array();
		$GLOBALS['sqtwc_orders']            = array();
		$GLOBALS['sqtwc_adopted']           = array();
		$GLOBALS['sqtwc_pro_adoptions']     = array();
		$GLOBALS['sqtwc_ledger_rows']       = array();
		$GLOBALS['sqtwc_free_lock_held']    = false;
		$GLOBALS['sqtwc_wc_get_orders_args'] = array();
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_uses_pro_panel'] = true;
		unset( $GLOBALS['sqtwc_pro_adopt_result'], $GLOBALS['sqtwc_order_query_results'] );
		Settings::reset_cache_for_tests();
		$this->square = new QueueTransport();
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_square_http_client'] = $this->square;
	}

	protected function tearDown(): void {
		$GLOBALS['sqtwc_filter_overrides']['sqtwc_uses_pro_panel'] = false;
		unset( $GLOBALS['sqtwc_pro_adopt_result'], $GLOBALS['sqtwc_free_lock_held'], $GLOBALS['sqtwc_filter_overrides']['sqtwc_square_http_client'], $GLOBALS['sqtwc_transients'] );
		Settings::reset_cache_for_tests();
	}

	private function order( int $id, string $checkout = 'TC1', string $status = 'IN_PROGRESS' ): \SQTWC_Test_Order {
		$order = new \SQTWC_Test_Order( $id );
		$order->meta['_sqtwc_checkout_id']     = $checkout;
		$order->meta['_sqtwc_checkout_status'] = $status;
		$order->meta['_sqtwc_current_attempt_id'] = 'att-1';
		$order->meta['_sqtwc_attempt_started']    = time() - 60;
		$order->meta['_sqtwc_attempt_request']    = array( 'environment' => 'sandbox' );
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
		// An adopted checkout Pro still owns is never held, however old: Pro's panel has its controls.
		$owned = $this->order( 44, 'TC44' );
		Legacy_Adoption::adopt_order( 44 );
		$GLOBALS['sqtwc_ledger_rows'][44] = array( array( 'id' => 'row-' . count( $GLOBALS['sqtwc_pro_adoptions'] ), 'status' => 'pending' ) );
		$owned->meta['_sqtwc_attempt_started'] = time() - Legacy_Adoption::ADOPTION_WINDOW - 1;
		self::assertNull( Legacy_Adoption::adopt_order( 44 ), 'Owned by Pro: the panel renders' );
		$GLOBALS['sqtwc_ledger_rows'][44] = array();
		self::assertSame( 'sqtwc_adoption_stale_attempt', Legacy_Adoption::adopt_order( 44 )->get_error_code(), 'A row that cannot be read is an unknown outcome: held' );
		$GLOBALS['sqtwc_ledger_rows'][44] = array( array( 'id' => 'row-' . count( $GLOBALS['sqtwc_pro_adoptions'] ), 'status' => 'voided' ) );
		self::assertSame( 'sqtwc_adoption_stale_attempt', Legacy_Adoption::adopt_order( 44 )->get_error_code(), 'Ended without money: held until the sweep closes the pointer' );
		// Not owned before the locks, owned (adopted by a parallel request) under them: the panel renders.
		$late = $this->order( 45, 'TC45' );
		$late->meta['_sqtwc_attempt_started'] = time() - Legacy_Adoption::ADOPTION_WINDOW - 1;
		$GLOBALS['sqtwc_wc_get_order_callback'] = static function ( $id ) use ( $late ) {
			if ( \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$held ) {
				$GLOBALS['sqtwc_adopted']['sandbox:TC45'] = 'row-late';
				$GLOBALS['sqtwc_ledger_rows'][45]         = array( array( 'id' => 'row-late', 'status' => 'pending' ) );
			}
			return $late;
		};
		try {
			self::assertNull( Legacy_Adoption::adopt_order( 45 ) );
		} finally {
			unset( $GLOBALS['sqtwc_wc_get_order_callback'] );
		}
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
		self::assertCount( 1, $GLOBALS['sqtwc_pro_adoptions'], 'Only the owned order 44 was ever adopted here' );
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

	private const UNCONFIRMED_NOTE = 'Square never confirmed an earlier Terminal checkout on this order, and it may have been paid. Check the Square dashboard before taking this payment again.';

	public function test_a_create_square_never_confirmed_holds_the_panel(): void {
		$order = $this->order( 400, '', 'PENDING' );
		$held  = Legacy_Adoption::adopt_order( 400 );
		self::assertSame( 'sqtwc_adoption_unconfirmed_create', $held->get_error_code() );
		self::assertFalse( Legacy_Adoption::is_deferral( $held ) );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
		self::assertSame( array(), $order->notes );
	}

	public function test_the_hold_is_decided_on_the_fresh_copy(): void {
		// The order read before the lock shows the unconfirmed attempt; the copy read under it shows it closed.
		$stale = $this->order( 401, '', 'PENDING' );
		$GLOBALS['sqtwc_cleaned_post_caches']      = array();
		$GLOBALS['sqtwc_clean_post_cache_callback'] = static function ( int $id ): void {
			$GLOBALS['sqtwc_cleaned_post_caches'][] = $id;
		};
		$GLOBALS['sqtwc_wc_get_order_callback'] = static function ( $id ) use ( $stale ) {
			if ( \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$held && '' !== (string) get_option( 'sqtwc_lock_401', '' ) && in_array( 401, $GLOBALS['sqtwc_cleaned_post_caches'] ?? array(), true ) ) {
				$fresh = clone $stale;
				$fresh->meta['_sqtwc_current_attempt_id'] = '';
				return $fresh;
			}
			return $stale;
		};
		try {
			self::assertNull( Legacy_Adoption::adopt_order( 401 ) );
		} finally {
			unset( $GLOBALS['sqtwc_wc_get_order_callback'], $GLOBALS['sqtwc_clean_post_cache_callback'] );
		}
	}

	public function test_a_live_pro_leg_is_not_held_by_an_unconfirmed_create(): void {
		$this->order( 402, '', 'PENDING' );
		$GLOBALS['sqtwc_ledger_rows'][402] = array( array( 'id' => 'row-pro', 'method_id' => 'sqtwc', 'status' => 'pending' ) );
		self::assertNull( Legacy_Adoption::adopt_order( 402 ) );
	}

	public function test_an_unconfirmed_create_is_released_after_the_window_with_one_note(): void {
		$order = $this->order( 403, '', 'PENDING' );
		$order->meta['_sqtwc_attempt_started'] = time() - Legacy_Adoption::ADOPTION_WINDOW - 1;
		self::assertNull( Legacy_Adoption::adopt_order( 403 ) );
		self::assertSame( array( self::UNCONFIRMED_NOTE ), $order->notes );
		self::assertSame( 'att-1', $order->meta[ Legacy_Adoption::META_UNCONFIRMED_NOTED ] );
		self::assertNull( Legacy_Adoption::adopt_order( 403 ) );
		self::assertCount( 1, $order->notes, 'One note per attempt' );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
	}

	public function test_an_unconfirmed_create_with_no_start_time_is_released(): void {
		$order = $this->order( 404, '', 'PENDING' );
		$order->meta['_sqtwc_attempt_started'] = 0;
		self::assertNull( Legacy_Adoption::adopt_order( 404 ) );
		self::assertSame( array( self::UNCONFIRMED_NOTE ), $order->notes );
	}

	public function test_a_new_attempt_past_the_window_gets_its_own_note(): void {
		$order = $this->order( 405, '', 'PENDING' );
		$order->meta['_sqtwc_attempt_started']                = time() - Legacy_Adoption::ADOPTION_WINDOW - 1;
		$order->meta[ Legacy_Adoption::META_UNCONFIRMED_NOTED ] = 'att-0';
		self::assertNull( Legacy_Adoption::adopt_order( 405 ) );
		self::assertSame( array( self::UNCONFIRMED_NOTE ), $order->notes );
		self::assertSame( 'att-1', $order->meta[ Legacy_Adoption::META_UNCONFIRMED_NOTED ] );
	}

	public function test_a_paid_order_is_neither_held_nor_noted(): void {
		$recent = $this->order( 406, '', 'PENDING' );
		$recent->paid = true;
		self::assertNull( Legacy_Adoption::adopt_order( 406 ) );
		$old = $this->order( 407, '', 'PENDING' );
		$old->paid = true;
		$old->meta['_sqtwc_attempt_started'] = time() - Legacy_Adoption::ADOPTION_WINDOW - 1;
		self::assertNull( Legacy_Adoption::adopt_order( 407 ) );
		self::assertSame( array(), $old->notes );
	}

	public function test_a_closed_attempt_is_neither_held_nor_noted(): void {
		$recent = $this->order( 408, '', 'PENDING' );
		$recent->meta['_sqtwc_current_attempt_id'] = '';
		self::assertNull( Legacy_Adoption::adopt_order( 408 ) );
		$old = $this->order( 409, '', 'PENDING' );
		$old->meta['_sqtwc_current_attempt_id'] = '';
		$old->meta['_sqtwc_attempt_started']    = time() - Legacy_Adoption::ADOPTION_WINDOW - 1;
		self::assertNull( Legacy_Adoption::adopt_order( 409 ) );
		self::assertSame( array(), $old->notes );
	}

	public function test_a_final_status_is_neither_held_nor_noted(): void {
		$this->order( 410, '', 'CANCELED' );
		self::assertNull( Legacy_Adoption::adopt_order( 410 ) );
		$old = $this->order( 411, '', 'CANCELED' );
		$old->meta['_sqtwc_attempt_started'] = time() - Legacy_Adoption::ADOPTION_WINDOW - 1;
		self::assertNull( Legacy_Adoption::adopt_order( 411 ) );
		self::assertSame( array(), $old->notes );
	}

	public function test_a_held_lock_defers_the_release_note(): void {
		$order = $this->order( 412, '', 'PENDING' );
		$order->meta['_sqtwc_attempt_started'] = time() - Legacy_Adoption::ADOPTION_WINDOW - 1;
		$GLOBALS['sqtwc_free_lock_held'] = true;
		$deferred = Legacy_Adoption::adopt_order( 412 );
		self::assertTrue( Legacy_Adoption::is_deferral( $deferred ) );
		self::assertSame( array(), $order->notes );
		self::assertArrayNotHasKey( Legacy_Adoption::META_UNCONFIRMED_NOTED, $order->meta );
	}

	public function test_the_upgrade_pass_drops_a_held_unconfirmed_order_from_the_queue(): void {
		$this->order( 413, '', 'PENDING' );
		$GLOBALS['sqtwc_order_query_results'] = array( 413 );
		Legacy_Adoption::upgrade();
		self::assertFalse( get_option( 'sqtwc_adoption_queue' ), 'Dropped: the panel render carries the hold from here' );
		self::assertSame( Legacy_Adoption::VERSION, get_option( 'sqtwc_adoption_version' ) );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
	}

	public function test_the_release_note_is_saved(): void {
		$order = new class( 414 ) extends \SQTWC_Test_Order {
			public int $saves = 0;
			public function save() {
				++$this->saves;
			}
		};
		$order->meta = array( '_sqtwc_checkout_id' => '', '_sqtwc_checkout_status' => 'PENDING', '_sqtwc_current_attempt_id' => 'att-1', '_sqtwc_attempt_started' => time() - Legacy_Adoption::ADOPTION_WINDOW - 1 );
		$GLOBALS['sqtwc_orders'][414] = $order;
		self::assertNull( Legacy_Adoption::adopt_order( 414 ) );
		self::assertSame( 1, $order->saves );
		self::assertNull( Legacy_Adoption::adopt_order( 414 ) );
		self::assertSame( 1, $order->saves, 'Noted once, saved once' );
	}

	public function test_the_release_note_is_decided_on_the_fresh_copy(): void {
		// The order read before the lock needs its note; the copy read under it shows a parallel request wrote it.
		$stale = $this->order( 415, '', 'PENDING' );
		$stale->meta['_sqtwc_attempt_started'] = time() - Legacy_Adoption::ADOPTION_WINDOW - 1;
		$fresh = clone $stale;
		$fresh->meta[ Legacy_Adoption::META_UNCONFIRMED_NOTED ] = 'att-1';
		$GLOBALS['sqtwc_cleaned_post_caches']      = array();
		$GLOBALS['sqtwc_clean_post_cache_callback'] = static function ( int $id ): void {
			$GLOBALS['sqtwc_cleaned_post_caches'][] = $id;
		};
		$GLOBALS['sqtwc_wc_get_order_callback'] = static function ( $id ) use ( $stale, $fresh ) {
			if ( \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$held && '' !== (string) get_option( 'sqtwc_lock_415', '' ) && in_array( 415, $GLOBALS['sqtwc_cleaned_post_caches'] ?? array(), true ) ) {
				return $fresh;
			}
			return $stale;
		};
		try {
			self::assertNull( Legacy_Adoption::adopt_order( 415 ) );
		} finally {
			unset( $GLOBALS['sqtwc_wc_get_order_callback'], $GLOBALS['sqtwc_clean_post_cache_callback'] );
		}
		self::assertSame( array(), $stale->notes );
		self::assertSame( array(), $fresh->notes, 'The parallel request already noted it' );
	}

	/** An attempt the last stable release (0.8.7) saved: no environment with it. */
	private function unsaved( int $id, string $checkout = 'TC1' ): \SQTWC_Test_Order {
		$order = $this->order( $id, $checkout );
		$order->meta['_sqtwc_attempt_request'] = array( 'device_id' => 'DEV1' );
		return $order;
	}

	private function use_environment( string $environment ): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings']['environment'] = $environment;
		Settings::reset_cache_for_tests();
	}

	/** Square's answer to the read: a 200 with this reference_id (none for null), or an error status. */
	private static function answer( int $status, ?string $reference = null ): Response {
		$body = 200 === $status
			? array( 'checkout' => array( 'id' => 'TC1', 'amount_money' => array( 'amount' => 1234, 'currency' => 'USD' ), 'status' => 'IN_PROGRESS', 'device_options' => array( 'device_id' => 'DEV1' ) ) + ( null === $reference ? array() : array( 'reference_id' => $reference ) ) )
			: array( 'errors' => array( array( 'category' => 'API_ERROR', 'code' => 'ERR', 'detail' => 'err' ) ) );
		return new Response( $status, array( 'Content-Type' => 'application/json' ), (string) json_encode( $body ) );
	}

	public function test_a_saved_environment_names_the_reference_with_no_read(): void {
		$order = $this->order( 20 );
		$order->meta['_sqtwc_attempt_request'] = array( 'environment' => 'production' );
		self::assertSame( 'production:TC1', Legacy_Adoption::action_ref( $order ) );
		self::assertSame( 'row-1', Legacy_Adoption::adopt_order( 20 )['id'] );
		self::assertSame( 'production:TC1', $GLOBALS['sqtwc_pro_adoptions'][0][2], 'Made in production, adopted in production while the setting says sandbox' );
		self::assertSame( 'production:TC1', $order->meta[ Legacy_Adoption::META_ADOPTED ] );
		$this->order( 21 );
		Legacy_Adoption::adopt_order( 21 );
		self::assertSame( 'sandbox:TC1', $GLOBALS['sqtwc_pro_adoptions'][1][2] );
		self::assertCount( 0, $this->square->requests, 'A saved environment needs no read' );
	}

	public function test_an_unrecognised_saved_environment_counts_as_none_and_is_read(): void {
		$order = $this->order( 22 );
		$order->meta['_sqtwc_attempt_request'] = array( 'environment' => 'live' );
		self::assertSame( 'sandbox:TC1', Legacy_Adoption::action_ref( $order ) );
		$this->square->queue[] = self::answer( 200, 'woocommerce_order_22' );
		self::assertSame( 'row-1', Legacy_Adoption::adopt_order( 22 )['id'] );
		self::assertCount( 1, $this->square->requests );
	}

	public function test_an_attempt_saved_without_its_environment_is_confirmed_in_the_current_one(): void {
		$this->use_environment( 'production' );
		$order = $this->unsaved( 23 );
		$this->square->queue[] = self::answer( 200, 'woocommerce_order_23' );
		self::assertSame( 'row-1', Legacy_Adoption::adopt_order( 23 )['id'] );
		self::assertSame( array( array( 23, 'sqtwc', 'production:TC1', '12.34', 'USD' ) ), $GLOBALS['sqtwc_pro_adoptions'] );
		self::assertSame( 'production:TC1', $order->meta[ Legacy_Adoption::META_ADOPTED ] );
		self::assertCount( 1, $this->square->requests, 'Exactly one read' );
		$request = $this->square->requests[0];
		self::assertSame( 'GET', $request->getMethod() );
		self::assertSame( 'https://connect.squareup.com/v2/terminals/checkouts/TC1', (string) $request->getUri() );
		self::assertSame( 'Bearer prod-token', $request->getHeaderLine( 'Authorization' ) );
	}

	public static function other_environment_answers(): array {
		return array(
			'404'                   => array( 404, null ),
			'another order'         => array( 200, 'woocommerce_order_999' ),
			'no reference'          => array( 200, null ),
		);
	}

	#[DataProvider( 'other_environment_answers' )]
	public function test_a_checkout_square_does_not_place_on_this_order_is_refused_and_dropped( int $status, ?string $reference ): void {
		$this->unsaved( 24 );
		$this->square->queue[] = self::answer( $status, $reference );
		$refused = Legacy_Adoption::adopt_order( 24 );
		self::assertSame( 'sqtwc_adoption_other_environment', $refused->get_error_code() );
		self::assertFalse( Legacy_Adoption::is_deferral( $refused ) );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
		self::assertArrayNotHasKey( Legacy_Adoption::META_ADOPTED, $GLOBALS['sqtwc_orders'][24]->meta );
		$GLOBALS['sqtwc_order_query_results'] = array( 24 );
		$this->square->queue[] = self::answer( $status, $reference );
		Legacy_Adoption::upgrade();
		self::assertFalse( get_option( 'sqtwc_adoption_queue' ), 'Final for the pass: dropped' );
		self::assertSame( Legacy_Adoption::VERSION, get_option( 'sqtwc_adoption_version' ) );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
		self::assertCount( 2, $this->square->requests );
	}

	public static function unread_answers(): array {
		return array(
			'500'       => array( '500' ),
			'429'       => array( '429' ),
			'401'       => array( '401' ),
			'403'       => array( '403' ),
			'transport' => array( 'lost' ),
			'no token'  => array( 'no token' ),
		);
	}

	private function script_unread( string $kind ): void {
		if ( 'no token' === $kind ) {
			$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings']['sandbox_access_token'] = '';
			Settings::reset_cache_for_tests();
			return;
		}
		$this->square->queue[] = 'lost' === $kind ? new ConnectException( 'lost', new \GuzzleHttp\Psr7\Request( 'GET', 'https://connect.squareupsandbox.com/v2/terminals/checkouts/TC1' ) ) : self::answer( (int) $kind );
	}

	#[DataProvider( 'unread_answers' )]
	public function test_a_read_square_did_not_answer_defers_and_keeps_the_order_queued( string $kind ): void {
		$this->unsaved( 25 );
		$this->script_unread( $kind );
		$deferred = Legacy_Adoption::adopt_order( 25 );
		self::assertSame( 'sqtwc_adoption_environment_unread', $deferred->get_error_code() );
		self::assertTrue( Legacy_Adoption::is_deferral( $deferred ) );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
		$GLOBALS['sqtwc_order_query_results'] = array( 25 );
		$this->script_unread( $kind );
		Legacy_Adoption::upgrade();
		self::assertSame( array( 25 => 1 ), get_option( 'sqtwc_adoption_queue' ) );
		self::assertFalse( get_option( 'sqtwc_adoption_version' ) );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
		self::assertCount( 'no token' === $kind ? 0 : 2, $this->square->requests, 'No token: nothing is sent' );
	}

	public function test_a_failed_read_pauses_the_pass_for_a_minute(): void {
		foreach ( array( 50, 51, 52 ) as $id ) {
			$this->unsaved( $id, 'TC' . $id );
		}
		$GLOBALS['sqtwc_order_query_results'] = array( 50, 51, 52 );
		$this->square->queue[] = self::answer( 500 );
		Legacy_Adoption::upgrade();
		self::assertCount( 1, $this->square->requests, 'The page stops at the first failed read: 51 and 52 are not read' );
		self::assertSame( array( 50 => 1, 51 => 1, 52 => 1 ), get_option( 'sqtwc_adoption_queue' ) );
		self::assertSame( 60, $GLOBALS['sqtwc_transients']['sqtwc_adoption_read_pause']['expiration'] );
		Legacy_Adoption::upgrade();
		self::assertCount( 1, $this->square->requests, 'Paused: no request' );
		self::assertSame( array( 50 => 1, 51 => 1, 52 => 1 ), get_option( 'sqtwc_adoption_queue' ) );
		delete_transient( 'sqtwc_adoption_read_pause' );
		foreach ( array( 50, 51, 52 ) as $id ) {
			$this->square->queue[] = self::answer( 200, 'woocommerce_order_' . $id );
		}
		Legacy_Adoption::upgrade();
		self::assertCount( 4, $this->square->requests );
		self::assertCount( 3, $GLOBALS['sqtwc_pro_adoptions'] );
		self::assertSame( Legacy_Adoption::VERSION, get_option( 'sqtwc_adoption_version' ) );
	}

	public function test_an_adopted_checkout_keeps_its_reference_after_the_setting_changes(): void {
		$order = $this->unsaved( 26 );
		$this->square->queue[] = self::answer( 200, 'woocommerce_order_26' );
		Legacy_Adoption::adopt_order( 26 );
		self::assertSame( 'sandbox:TC1', $order->meta[ Legacy_Adoption::META_ADOPTED ] );
		$GLOBALS['sqtwc_ledger_rows'][26] = array( array( 'id' => 'row-1', 'method_id' => 'sqtwc', 'status' => 'pending' ) );
		$this->use_environment( 'production' );
		self::assertSame( 'sandbox:TC1', Legacy_Adoption::action_ref( $order ) );
		self::assertNull( Legacy_Adoption::adopt_order( 26 ), 'Still adopted' );
		self::assertCount( 1, $this->square->requests, 'No second read' );
		self::assertCount( 1, $GLOBALS['sqtwc_pro_adoptions'] );
		self::assertTrue( Legacy_Adoption::owns_checkout( $order, 'TC1' ) );
		self::assertTrue( Legacy_Adoption::pro_row_live( $order, 'TC1' ) );
		self::assertSame( '', Legacy_Adoption::tile_hold_message( $order ), 'Pro has it: the tile is not held as the old panel\'s' );
		self::assertFalse( Legacy_Adoption::captured_by_pro( $order, 'TC1' ) );
		$GLOBALS['sqtwc_ledger_rows'][26][0]['status'] = 'captured';
		self::assertTrue( Legacy_Adoption::captured_by_pro( $order, 'TC1' ) );
	}

	public function test_a_pro_row_under_the_saved_environment_is_found_before_the_reference_is_kept(): void {
		// Pro adopted under production; the save of the kept reference did not land.
		$order = $this->order( 27 );
		$order->meta['_sqtwc_attempt_request'] = array( 'environment' => 'production' );
		$GLOBALS['sqtwc_adopted']['production:TC1'] = 'row-p';
		$GLOBALS['sqtwc_ledger_rows'][27]           = array( array( 'id' => 'row-p', 'status' => 'pending' ) );
		self::assertTrue( Legacy_Adoption::owns_checkout( $order, 'TC1' ) );
		self::assertTrue( Legacy_Adoption::pro_row_live( $order, 'TC1' ) );
	}

	public function test_an_abandoned_checkout_uses_the_current_environment(): void {
		// The saved environment belongs to the current checkout, TC_NEW, not to the earlier TC_OLD.
		$order = $this->order( 28, 'TC_NEW' );
		$order->meta['_sqtwc_attempt_request'] = array( 'environment' => 'production' );
		$GLOBALS['sqtwc_adopted']['sandbox:TC_OLD'] = 'row-o';
		$GLOBALS['sqtwc_ledger_rows'][28]           = array( array( 'id' => 'row-o', 'status' => 'pending' ) );
		$GLOBALS['sqtwc_adoption_lookups']          = array();
		self::assertTrue( Legacy_Adoption::owns_checkout( $order, 'TC_OLD' ) );
		self::assertContains( array( 'square', 'sandbox:TC_OLD' ), $GLOBALS['sqtwc_adoption_lookups'] );
		self::assertNotContains( array( 'square', 'production:TC_OLD' ), $GLOBALS['sqtwc_adoption_lookups'] );
	}

	public function test_a_read_that_throws_an_error_defers_like_any_other_failed_read(): void {
		$this->unsaved( 29 );
		$this->square->queue[] = new \Error( 'boom' );
		$deferred = Legacy_Adoption::adopt_order( 29 );
		self::assertInstanceOf( \WP_Error::class, $deferred );
		self::assertSame( 'sqtwc_adoption_environment_unread', $deferred->get_error_code() );
		self::assertTrue( Legacy_Adoption::is_deferral( $deferred ) );
		self::assertSame( array(), $GLOBALS['sqtwc_pro_adoptions'] );
	}

	public function test_a_kept_reference_with_no_pro_record_is_read_in_its_own_environment(): void {
		$order = $this->unsaved( 30 );
		$order->meta[ Legacy_Adoption::META_ADOPTED ] = 'sandbox:TC1';
		$this->use_environment( 'production' );
		$this->square->queue[] = self::answer( 200, 'woocommerce_order_30' );
		self::assertSame( 'row-1', Legacy_Adoption::adopt_order( 30 )['id'] );
		self::assertCount( 1, $this->square->requests );
		self::assertSame( 'https://connect.squareupsandbox.com/v2/terminals/checkouts/TC1', (string) $this->square->requests[0]->getUri() );
		self::assertSame( 'sandbox:TC1', $GLOBALS['sqtwc_pro_adoptions'][0][2] );
	}

	public function test_a_kept_reference_for_an_earlier_checkout_does_not_name_the_current_one(): void {
		$order = $this->unsaved( 31, 'TC_NEW' );
		$order->meta[ Legacy_Adoption::META_ADOPTED ] = 'sandbox:TC_OLD';
		self::assertSame( 'sandbox:TC_NEW', Legacy_Adoption::action_ref( $order ) );
		$this->use_environment( 'production' );
		self::assertSame( 'production:TC_NEW', Legacy_Adoption::action_ref( $order ) );
	}
}
