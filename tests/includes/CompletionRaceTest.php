<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\SquareTerminal\PosCallbackHandler;
use WCPOS\WooCommercePOS\SquareTerminal\Services\CheckoutReconciler;
use WCPOS\WooCommercePOS\SquareTerminal\Services\PaymentSweeper;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;
use WCPOS\WooCommercePOS\SquareTerminal\WebhookHandler;

/**
 * One order row shared by several requests, each with its own order cache.
 *
 * wc_get_order() returns the copy a request already loaded until that request
 * evicts every cache layer of the store: clean_post_cache() on the posts
 * store, OrderCache::remove() on HPOS, and also
 * OrdersTableDataStore::clear_cached_data() when HPOS data caching is on.
 * On the posts store WC_Data also caches the order's meta per request; only
 * read_meta_data( true ) or a save in that request refreshes it. HPOS reads
 * meta with the order row, through the data cache when that is on.
 */
final class RaceStore {
	private const LAYERS = array(
		'posts'           => array( 'posts' ),
		'hpos'            => array( 'hpos' ),
		'hpos_data_cache' => array( 'hpos', 'hpos_data' ),
	);

	public array $rows = array();
	public array $caches = array();
	public array $evicted = array();
	public array $meta_caches = array();
	public string $request = '';
	public string $driver;
	public int $payment_complete_calls = 0;
	public int $stock_reductions = 0;

	public function __construct( string $driver ) {
		$this->driver = $driver;
	}

	public function load( int $id ) {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return null;
		}

		if ( ! isset( $this->caches[ $this->request ][ $id ] ) ) {
			$row = $this->rows[ $id ];
			if ( 'posts' === $this->driver ) {
				$row['meta'] = $this->meta_caches[ $this->request ][ $id ] ??= $row['meta'];
			}
			$this->caches[ $this->request ][ $id ] = RaceOrder::from_row( $this, $id, $row );
			unset( $this->evicted[ $this->request ][ $id ] );
		}

		return $this->caches[ $this->request ][ $id ];
	}

	public function evict( string $layer, int $id ): void {
		$this->evicted[ $this->request ][ $id ][ $layer ] = true;
		if ( array() === array_diff( self::LAYERS[ $this->driver ], array_keys( $this->evicted[ $this->request ][ $id ] ) ) ) {
			unset( $this->caches[ $this->request ][ $id ] );
		}
	}

	public function in_request( string $request, callable $callback ) {
		$previous      = $this->request;
		$this->request = $request;
		try {
			return $callback();
		} finally {
			$this->request = $previous;
		}
	}
}

final class RaceOrder extends \SQTWC_Test_Order {
	public RaceStore $store;
	public bool $stock_reduced = false;

	public static function from_row( RaceStore $store, int $id, array $row ): self {
		$order                       = new self( $id );
		$order->store                = $store;
		$order->paid                 = $row['paid'];
		$order->status               = $row['status'];
		$order->meta                 = $row['meta'];
		$order->notes                = $row['notes'];
		$order->stock_reduced        = $row['stock_reduced'];
		$order->payment_method       = $row['payment_method'];
		$order->payment_method_title = $row['payment_method_title'];
		$order->transaction_id       = $row['transaction_id'];

		return $order;
	}

	public function save() {
		$this->store->rows[ $this->id ] = array(
			'paid'                 => $this->paid,
			'status'               => $this->status,
			'meta'                 => $this->meta,
			'notes'                => $this->notes,
			'stock_reduced'        => $this->stock_reduced,
			'payment_method'       => $this->payment_method,
			'payment_method_title' => $this->payment_method_title,
			'transaction_id'       => $this->transaction_id,
		);
		unset( $this->store->meta_caches[ $this->store->request ][ $this->id ] );

		return $this->id;
	}

	// With HPOS data caching the data store serves meta from its cache, so a
	// forced read refreshes nothing until clear_cached_data() runs.
	public function read_meta_data( $force_read = false ) {
		if ( $force_read && 'hpos_data_cache' !== $this->store->driver ) {
			$this->meta = $this->store->rows[ $this->id ]['meta'];
			unset( $this->store->meta_caches[ $this->store->request ][ $this->id ] );
		}
	}

	// Mirrors WC_Order::payment_complete(): stock is reduced unless this copy
	// already records it, then the order is saved.
	public function payment_complete( $id = '' ) {
		++$this->store->payment_complete_calls;
		if ( ! $this->stock_reduced ) {
			++$this->store->stock_reductions;
			$this->stock_reduced = true;
		}
		parent::payment_complete( $id );
		$this->status = 'processing';
		$this->save();

		return true;
	}
}

final class RaceAdapter {
	public function get_checkout( string $id, array $options = array() ): array {
		return CompletionRaceTest::completed_checkout();
	}

	public function get_payment( string $id, array $options = array() ): array {
		return array(
			'id'             => $id,
			'status'         => 'COMPLETED',
			'total_amount'   => 1234,
			'total_currency' => 'USD',
			'tip_amount'     => 0,
			'tip_currency'   => null,
			'card_status'    => null,
		);
	}
}

final class RaceWebhookVerifier {
	public function verify( string $body, string $signature, string $key, string $url ): bool {
		return true;
	}
}

final class RacePosVerifier {
	public function verify( string $transaction_id ): array {
		return array(
			'payment_ids' => array( 'pay_1' ),
			'amount'      => 1234,
			'currency'    => 'USD',
			'location_id' => 'LOC',
		);
	}
}

final class CompletionRaceTest extends TestCase {
	private const ORDER_ID = 7;

	private RaceStore $store;

	public static function completed_checkout(): array {
		return array(
			'id'            => 'chk_1',
			'status'        => 'COMPLETED',
			'reference_id'  => 'woocommerce_order_' . self::ORDER_ID,
			'payment_ids'   => array( 'pay_1' ),
			'updated_at'    => '2026-10-01T10:00:05Z',
			'created_at'    => null,
			'cancel_reason' => null,
		);
	}

	public static function drivers(): array {
		return array(
			'posts'           => array( 'posts' ),
			'hpos'            => array( 'hpos' ),
			'hpos_data_cache' => array( 'hpos_data_cache' ),
		);
	}

	protected function setUp(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['sqtwc_orders']              = array();
		$GLOBALS['sqtwc_order_query_results'] = array();
		$GLOBALS['sqtwc_redirects']           = array();
		$GLOBALS['sqtwc_current_user_can']    = false;
		$GLOBALS['sqtwc_is_user_logged_in']   = false;
		$GLOBALS['sqtwc_nonce_valid']         = false;
		$GLOBALS['sqtwc_options']             = array(
			'woocommerce_sqtwc_settings' => array(
				'environment'              => 'production',
				'location_id'              => 'LOC',
				'webhook_notification_url' => 'https://store.test/wp-json/sqtwc/v1/webhook',
				'webhook_signature_key'    => 'secret',
			),
		);
		Settings::reset_cache_for_tests();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['sqtwc_wc_get_order_callback'],
			$GLOBALS['sqtwc_clean_post_cache_callback'],
			$GLOBALS['sqtwc_order_cache_remove_callback'],
			$GLOBALS['sqtwc_hpos_data_cache_clear_callback'],
			$GLOBALS['sqtwc_hpos_data_caching']
		);
	}

	private function open_store( string $driver ): void {
		$this->store                                = new RaceStore( $driver );
		$this->store->rows[ self::ORDER_ID ]        = array(
			'paid'                 => false,
			'status'               => 'pending',
			'meta'                 => array(
				'_sqtwc_current_attempt_id'       => 'attempt_1',
				'_sqtwc_checkout_idempotency_key' => 'idem_1',
				'_sqtwc_checkout_id'              => 'chk_1',
				'_sqtwc_checkout_status'          => 'IN_PROGRESS',
				'_sqtwc_checkout_updated_at'      => '2026-10-01T10:00:00Z',
				'_sqtwc_device_id'                => 'device_1',
				'_sqtwc_attempt_started'          => time() - 30,
			),
			'notes'                => array(),
			'stock_reduced'        => false,
			'payment_method'       => '',
			'payment_method_title' => '',
			'transaction_id'       => '',
		);
		$store                                      = $this->store;
		$GLOBALS['sqtwc_wc_get_order_callback']       = static fn( $id ) => $store->load( (int) $id );
		$GLOBALS['sqtwc_clean_post_cache_callback']   = static fn( int $id ) => $store->evict( 'posts', $id );
		$GLOBALS['sqtwc_order_cache_remove_callback'] = static fn( int $id ) => $store->evict( 'hpos', $id );
		$GLOBALS['sqtwc_hpos_data_cache_clear_callback'] = static fn( int $id ) => $store->evict( 'hpos_data', $id );
		$GLOBALS['sqtwc_hpos_data_caching']              = 'hpos_data_cache' === $driver;
	}

	/** The request loads the order before it waits on the lock, as each entry point does. */
	private function load_before_lock( string $request ): void {
		$order = $this->store->in_request( $request, fn() => wc_get_order( self::ORDER_ID ) );
		self::assertFalse( $order->is_paid() );
	}

	private function webhook( string $request ): array {
		$body = json_encode(
			array(
				'event_id' => 'evt_' . $request,
				'type'     => 'terminal.checkout.updated',
				'data'     => array( 'object' => array( 'checkout' => self::completed_checkout() ) ),
			)
		);
		$handler = new WebhookHandler( new RaceWebhookVerifier(), new CheckoutReconciler( new RaceAdapter() ) );

		return $this->store->in_request( $request, fn() => $handler->handle( $body, array( 'X-Square-HmacSha256-Signature' => 'sig' ) ) );
	}

	private function poll( string $request ): array {
		$handler = new AjaxHandler( new RaceAdapter() );

		return $this->store->in_request(
			$request,
			fn() => $handler->get_terminal_status( array( 'order_id' => self::ORDER_ID, 'order_key' => 'key', 'force' => '1' ) )
		);
	}

	private function pos_return( string $request ): string {
		$handler = new PosCallbackHandler( new RacePosVerifier() );
		$params  = array(
			'com.squareup.pos.SERVER_TRANSACTION_ID' => 'txn_1',
			'com.squareup.pos.REQUEST_METADATA'      => wp_json_encode( array( 'o' => self::ORDER_ID, 'k' => 'key' ) ),
		);

		return $this->store->in_request(
			$request,
			function () use ( $handler, $params ): string {
				try {
					$handler->handle( $params );
					self::fail( 'Expected a redirect.' );
				} catch ( \SQTWC_Redirect $redirect ) {
					return $redirect->getMessage();
				}
			}
		);
	}

	private function assert_completed_once(): void {
		self::assertSame( 1, $this->store->payment_complete_calls, 'payment_complete() calls' );
		self::assertSame( 1, $this->store->stock_reductions, 'stock reductions' );
		self::assertTrue( $this->store->rows[ self::ORDER_ID ]['paid'] );
		// A request that saw the paid status with stale meta would flag the one
		// payment as a duplicate capture and ask the merchant to refund it.
		self::assertArrayNotHasKey( '_sqtwc_duplicate_payment_ids', $this->store->rows[ self::ORDER_ID ]['meta'] );
	}

	/** @dataProvider drivers */
	public function test_poll_waiting_on_the_webhook_does_not_complete_the_order_again( string $driver ): void {
		$this->open_store( $driver );
		$this->load_before_lock( 'poll' );

		$webhook = $this->webhook( 'webhook' );
		self::assertSame( 200, $webhook['status'] );

		$poll = $this->poll( 'poll' );

		$this->assert_completed_once();
		self::assertSame( 'COMPLETED', $poll['status'] );
		self::assertFalse( $poll['continue_polling'] );
		self::assertSame( '/checkout/order-received/7/?key=key', $poll['redirect_url'] );
	}

	/** @dataProvider drivers */
	public function test_webhook_waiting_on_the_poll_does_not_complete_the_order_again( string $driver ): void {
		$this->open_store( $driver );
		$this->load_before_lock( 'webhook' );

		$poll = $this->poll( 'poll' );
		self::assertSame( 'COMPLETED', $poll['status'] );

		$webhook = $this->webhook( 'webhook' );

		$this->assert_completed_once();
		// The fresh order has closed the attempt, so the webhook's checkout no
		// longer matches it and is ignored.
		self::assertSame( 202, $webhook['status'] );
		self::assertSame( 'ignored', $webhook['result'] );
	}

	/** @dataProvider drivers */
	public function test_sweep_after_the_webhook_does_not_complete_the_order_again( string $driver ): void {
		$this->open_store( $driver );
		// Old enough for the sweep to re-fetch the current checkout.
		$this->store->rows[ self::ORDER_ID ]['meta']['_sqtwc_attempt_started'] = time() - 900;
		$GLOBALS['sqtwc_options']['sqtwc_reconcile_seeded']                    = '18446744073709551615';
		// The sweep's request loaded the order earlier, before the webhook finished.
		$this->load_before_lock( 'sweep' );

		$webhook = $this->webhook( 'webhook' );
		self::assertSame( 200, $webhook['status'] );

		// The sweep read its queue before the webhook unindexed the order.
		$GLOBALS['sqtwc_options'][ 'sqtwc_reconcile_' . self::ORDER_ID ] = (string) ( time() - 900 );
		$adapter = new RaceAdapter();
		$this->store->in_request(
			'sweep',
			fn() => ( new PaymentSweeper( $adapter, new CheckoutReconciler( $adapter ) ) )->sweep()
		);

		$this->assert_completed_once();
	}

	/** @dataProvider drivers */
	public function test_repeated_pos_app_return_does_not_complete_the_order_again( string $driver ): void {
		$GLOBALS['sqtwc_options']['woocommerce_sqtwc_settings']['collection_method'] = 'pos_app';
		Settings::reset_cache_for_tests();
		$this->open_store( $driver );
		$this->load_before_lock( 'second' );

		$first = $this->pos_return( 'first' );
		self::assertStringNotContainsString( 'sqtwc_pos_result', $first );

		$second = $this->pos_return( 'second' );

		$this->assert_completed_once();
		self::assertStringNotContainsString( 'sqtwc_pos_result', $second );
	}
}
