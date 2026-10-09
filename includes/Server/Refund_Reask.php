<?php
/**
 * A refund POST Square did not answer is asked about again.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Server;

use Throwable;
use WCPOS\WooCommercePOS\SquareTerminal\Logger;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Exceptions\SquareApiException;

/**
 * Keeps an unanswered refund honest without a second refund.
 *
 * An error from the adapter's refund() makes WooCommerce delete the refund record, and a later
 * refund is a new record under a new idempotency key: a second refund if the first POST did reach
 * Square. So the record keeps an attempt id, saved BEFORE the POST and sent as Square's
 * idempotency key; when the POST goes unanswered the record stands as pending, the order says so,
 * and this cron replays the identical request under the same key until Square answers (it hands
 * back the refund the first request made, or makes it now) or the tries run out with a note for
 * staff. Pro's ledger shows the refund pending throughout; nothing in Pro re-reads a pending refund.
 */
final class Refund_Reask {
	/** Cron hook; arguments are the refund id, the try number and the parent order id. */
	public const HOOK = 'sqtwc_reask_refund';

	/** Seconds between asks: long enough for Square to have settled the first request. */
	public const DELAY = 120;

	/** Silent tries before staff are told to check the Square dashboard (about ten minutes). */
	public const LIMIT = 5;

	/** The attempt id, Square's idempotency key for this record's refund; written before the POST. */
	public const META_ATTEMPT = '_sqtwc_refund_attempt';

	/** The request replayed on each ask: payment_id, amount, currency, reason, environment. */
	public const META_REQUEST = '_sqtwc_refund_request';

	/** The Square refund id once Square answered. */
	public const META_REFUND = '_sqtwc_refund_id';

	/**
	 * Hook the cron handler.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 3 );
	}

	/**
	 * The record's attempt id, minted and saved before the first POST so a replay reuses it.
	 *
	 * @param \WC_Order_Refund    $refund  Refund record.
	 * @param array<string,mixed> $request The request the id keys.
	 */
	public static function attempt_key( $refund, array $request ): string {
		$key = (string) $refund->get_meta( self::META_ATTEMPT, true );
		if ( '' === $key ) {
			$key = wp_generate_uuid4();
			$refund->update_meta_data( self::META_ATTEMPT, $key );
			$refund->update_meta_data( self::META_REQUEST, $request );
			$refund->save();
		}

		return $key;
	}

	/**
	 * The POST itself went unanswered: note the order and schedule the first ask.
	 *
	 * @param \WC_Order $order     Parent order.
	 * @param int       $refund_id Refund record id.
	 */
	public static function unanswered( $order, int $refund_id ): void {
		/* translators: %d: refund id. */
		$order->add_order_note( sprintf( __( 'Square did not confirm refund #%d. The refund is being checked again; do not refund it a second time.', 'square-terminal-for-woocommerce' ), $refund_id ) );
		$order->save();
		self::schedule( $refund_id, 1, (int) $order->get_id() );
	}

	/**
	 * Schedule one ask.
	 *
	 * @param int $refund_id Refund record id.
	 * @param int $try       Try number.
	 * @param int $order_id  Parent order id, so a deleted record can still be reported on its order.
	 */
	private static function schedule( int $refund_id, int $try, int $order_id ): void {
		wp_schedule_single_event( time() + self::DELAY, self::HOOK, array( $refund_id, $try, $order_id ) );
	}

	/**
	 * Replay the refund under its saved key and record Square's answer.
	 *
	 * @param int                         $refund_id Refund record id.
	 * @param int                         $try       Try number.
	 * @param int                         $order_id  Parent order id.
	 * @param Square_Server_Provider|null $adapter   Adapter override for tests.
	 */
	public static function run( int $refund_id, int $try = 1, int $order_id = 0, $adapter = null ): void {
		$refund = wc_get_order( $refund_id );
		if ( ! $refund instanceof \WC_Order_Refund ) {
			// The record was deleted while Square's answer was outstanding: the money may still have moved.
			$order = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order ) {
				/* translators: %d: refund id. */
				$order->add_order_note( sprintf( __( 'Refund #%d was deleted before Square confirmed it. Check the Square dashboard: the refund may have been made.', 'square-terminal-for-woocommerce' ), $refund_id ) );
				$order->save();
			}
			return;
		}
		$order = wc_get_order( (int) $refund->get_parent_id() );
		if ( ! $order || '' !== (string) $refund->get_meta( self::META_REFUND, true ) ) {
			return;
		}
		$key     = (string) $refund->get_meta( self::META_ATTEMPT, true );
		$request = (array) $refund->get_meta( self::META_REQUEST, true );
		if ( '' === $key || empty( $request['payment_id'] ) ) {
			return;
		}
		$adapter = $adapter ?? new Square_Server_Provider();
		try {
			$result = $adapter->refund_once( $request, $key );
		} catch ( SquareApiException $e ) {
			// Unanswered, refused for want of credentials, or a refund under this key exists with
			// another body: none proves the first request made nothing, so the question stays open.
			if ( Square_Server_Provider::unanswered_status( $e->getStatusCode() ) || in_array( $e->getStatusCode(), array( 401, 403 ), true ) || 'IDEMPOTENCY_KEY_REUSED' === Square_Server_Provider::error_code( $e ) ) {
				self::again( $order, $refund_id, $try );
				return;
			}
			// Square refused the identical replay: had a refund been made under this key, Square would
			// have handed it back instead, so no refund was made.
			/* translators: 1: refund id, 2: Square's error. */
			$order->add_order_note( sprintf( __( 'Square refused refund #%1$d: %2$s. No money was returned. The record still counts as refunded here: delete it, then refund from the Square dashboard if the money is owed.', 'square-terminal-for-woocommerce' ), $refund_id, Square_Server_Provider::error_code( $e ) ) );
			$order->save();
			return;
		} catch ( Throwable $e ) {
			self::again( $order, $refund_id, $try );
			return;
		}
		$refund->update_meta_data( self::META_REFUND, (string) $result['id'] );
		$refund->save();
		$failed = in_array( $result['status'], array( 'REJECTED', 'FAILED' ), true );
		/* translators: 1: refund id, 2: Square refund id, 3: Square refund status. */
		$order->add_order_note( sprintf( $failed ? __( 'Square reports refund #%1$d (%2$s) as %3$s: no money was returned. The record still counts as refunded here: delete it, then refund from the Square dashboard if the money is owed.', 'square-terminal-for-woocommerce' ) : __( 'Square confirmed refund #%1$d (%2$s): %3$s.', 'square-terminal-for-woocommerce' ), $refund_id, (string) $result['id'], (string) $result['status'] ) );
		$order->save();
	}

	/**
	 * Still unanswered: another ask, or the last word to staff.
	 *
	 * @param \WC_Order $order     Parent order.
	 * @param int       $refund_id Refund record id.
	 * @param int       $try       Try just made.
	 */
	private static function again( $order, int $refund_id, int $try ): void {
		if ( $try < self::LIMIT ) {
			self::schedule( $refund_id, $try + 1, (int) $order->get_id() );
			return;
		}
		Logger::warning( 'Square never answered a refund; staff asked to check the dashboard', array( 'refund_id' => $refund_id ) );
		/* translators: %d: refund id. */
		$order->add_order_note( sprintf( __( 'Square has not confirmed refund #%d. Check the Square dashboard: if the refund is there, nothing more is needed; if not, delete this refund record and refund from the dashboard.', 'square-terminal-for-woocommerce' ), $refund_id ) );
		$order->save();
	}
}
