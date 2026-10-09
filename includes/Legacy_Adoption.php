<?php
/**
 * Fold Terminal checkouts the old order-pay panel left mid-flight into WCPOS Pro's ledger.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

use Throwable;
use WCPOS\WooCommercePOS\SquareTerminal\Server\Square_Server_Provider;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderMeta;

/**
 * One pass on upgrade, 25 orders per request, from a snapshot of order ids taken when the pass
 * begins, and again for one order whenever Pro's panel renders it.
 *
 * Only while the order-pay page is Pro's panel (a paired Terminal, not the Square POS app
 * hand-off): under the carve-out the old panel owns its attempts. The action reference Pro's
 * provider polls and cancels is the Terminal checkout itself, so an adopted attempt needs nothing
 * beyond the ledger row: Square times an unpaid checkout out on its own and
 * `Square_Server_Provider::fetch()` reads it directly. Pro owns the checkout while its row is live.
 */
final class Legacy_Adoption {
	/** The version that introduced adoption; its pass runs once, and this marks it done. */
	public const VERSION = '1.0.0';

	/** Candidates per `init` request; keeps the request that triggers it short. */
	public const PAGE_SIZE = 25;

	/**
	 * Order statuses that still need payment: WooCommerce's own two plus the two Free adds (POS
	 * open and partially paid orders). `needs_payment()` on the rows read stays the truth.
	 */
	public const UNPAID_STATUSES = array( 'pending', 'failed', 'pos-open', 'pos-partial' );

	/** The action reference Pro adopted, kept on the order for good. */
	public const META_ADOPTED = '_sqtwc_adopted_ref';

	private const QUEUE_OPTION   = 'sqtwc_adoption_queue';
	private const VERSION_OPTION = 'sqtwc_adoption_version';

	/** Checkout statuses the old panel still polls. */
	private const LIVE_CHECKOUT_STATUSES = array( 'PENDING', 'IN_PROGRESS', 'CANCEL_REQUESTED' );

	/**
	 * How old an attempt may be and still be adopted: Square cancels an unpaid checkout five
	 * minutes after its create, and the old sweep reconciles a checkout once it is ten minutes
	 * old, so a pointer older than an hour names a checkout that has long ended (the sweep
	 * closes it) or one made under settings since changed (another environment), which Pro could
	 * neither poll nor cancel. The old sweep stays in charge of those.
	 */
	public const ADOPTION_WINDOW = HOUR_IN_SECONDS;

	/**
	 * The action reference Pro's provider uses for the attempt the old panel started: the
	 * order's current checkout while it is still live, in the current environment. '' for a
	 * final checkout or none (an attempt whose create Square never confirmed has no checkout id
	 * and cannot be adopted; the old sweep keeps the order indexed, as before).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function action_ref( $order ): string {
		$checkout_id = (string) $order->get_meta( '_sqtwc_checkout_id', true );
		$status      = (string) $order->get_meta( '_sqtwc_checkout_status', true );
		$started     = (int) $order->get_meta( '_sqtwc_attempt_started', true );
		if ( '' === $checkout_id || ! in_array( $status, self::LIVE_CHECKOUT_STATUSES, true ) || $started <= 0 || $started < time() - self::ADOPTION_WINDOW ) {
			return '';
		}

		return Square_Server_Provider::ref( Settings::get_environment(), $checkout_id );
	}

	/**
	 * Whether Pro adopted this checkout from the old panel (its record exists, whatever became of the leg).
	 *
	 * @param string $ref Action reference.
	 */
	public static function is_adopted( string $ref ): bool {
		return '' !== $ref && function_exists( 'wcpos_pro_payment_id_for_action' ) && null !== wcpos_pro_payment_id_for_action( Square_Server_Provider::PROVIDER, $ref );
	}

	/**
	 * Whether Pro owns this checkout now: adopted, and its ledger row still live (pending,
	 * authorized or captured). Once Pro's leg has ended without money the old paths act on the
	 * checkout again, as before adoption: a late result Square delivers to the old webhook must
	 * still reach the order. The adoption record itself is never cleared; the row's status is
	 * the truth. A record whose row cannot be read counts as owned.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $ref   Action reference.
	 */
	public static function owned_by_pro( $order, string $ref ): bool {
		if ( ! self::is_adopted( $ref ) ) {
			return false;
		}
		if ( ! class_exists( '\WCPOS\WooCommercePOS\Payments\Contract\Ledger' ) ) {
			return true;
		}
		$row = \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance()->find( $order, (string) wcpos_pro_payment_id_for_action( Square_Server_Provider::PROVIDER, $ref ) );

		return null === $row || in_array( $row['status'] ?? '', \WCPOS\WooCommercePOS\Payments\Contract\Ledger::LIVE_STATUSES, true );
	}

	/**
	 * Whether Pro owns a checkout of this order, by its id: the reference kept at adoption when
	 * it names this checkout, else the current environment's.
	 *
	 * @param \WC_Order $order       Order.
	 * @param string    $checkout_id Square checkout id.
	 */
	public static function owns_checkout( $order, string $checkout_id ): bool {
		if ( '' === $checkout_id || ! function_exists( 'wcpos_pro_payment_id_for_action' ) ) {
			return false;
		}
		$adopted = (string) $order->get_meta( self::META_ADOPTED, true );
		$ref     = '' !== $adopted && Square_Server_Provider::parse_ref( $adopted )[1] === $checkout_id ? $adopted : Square_Server_Provider::ref( Settings::get_environment(), $checkout_id );

		return self::owned_by_pro( $order, $ref );
	}

	/**
	 * Whether Pro's ledger holds a live row (pending, authorized or captured) for this gateway on
	 * the order: a leg Pro is driving now, adopted or its own. While one exists the old panel must
	 * not start a checkout beside it, whichever collection method the settings name today.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function pro_has_live_row( $order ): bool {
		if ( ! class_exists( '\WCPOS\WooCommercePOS\Payments\Contract\Ledger' ) ) {
			return false;
		}
		foreach ( \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance()->read( $order ) as $row ) {
			if ( Gateway::ID === ( $row['method_id'] ?? null ) && in_array( $row['status'] ?? '', \WCPOS\WooCommercePOS\Payments\Contract\Ledger::LIVE_STATUSES, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether Pro owns the attempt on this order: by its current checkout, or by the reference
	 * kept at adoption, which outlives the current pointer.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function owns_order( $order ): bool {
		if ( ! function_exists( 'wcpos_pro_payment_id_for_action' ) ) {
			return false;
		}

		return self::owns_checkout( $order, (string) $order->get_meta( '_sqtwc_checkout_id', true ) ) || self::owned_by_pro( $order, (string) $order->get_meta( self::META_ADOPTED, true ) );
	}

	/**
	 * Run the next page of adoption, until every candidate snapshotted at the start has been seen.
	 *
	 * Only while the order-pay page runs through Pro's panel: under the Square POS app carve-out
	 * the old panel stays whole and owns its attempts, so nothing is adopted and the pass is not
	 * marked done; it runs when the merchant switches to a paired Terminal.
	 */
	public static function upgrade(): void {
		if ( version_compare( (string) get_option( self::VERSION_OPTION, '0' ), self::VERSION, '>=' ) || ! Settings::uses_pro_panel() ) {
			return;
		}
		// The candidates are snapshotted once, as order ids, when the pass begins: every order
		// still waiting for payment that carries a checkout pointer. Ids only, so the one request
		// that takes the snapshot loads no order objects; each order is read, and judged, on its
		// own page below. Paging a live filter by offset would skip rows as webhooks move orders
		// out of it, and an attempt the old panel starts later is never a candidate (under Pro's
		// panel the old panel can start none).
		$queue = get_option( self::QUEUE_OPTION, null );
		if ( ! is_array( $queue ) ) {
			$ids   = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => self::UNPAID_STATUSES,
					'limit'        => -1,
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'return'       => 'ids',
					// The shortcut both order stores honour; `meta_query` is dropped by the posts store.
					'meta_key'     => '_sqtwc_checkout_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off upgrade pass.
					'meta_compare' => 'EXISTS',
				)
			);
			$queue = array_fill_keys( array_map( 'intval', is_array( $ids ) ? $ids : array() ), 1 );
			update_option( self::QUEUE_OPTION, $queue, false );
		}
		$page = array_slice( $queue, 0, self::PAGE_SIZE, true );
		foreach ( $page as $order_id => $unused ) {
			$result = self::adopt_order( (int) $order_id );
			if ( is_wp_error( $result ) && self::is_deferral( $result ) ) {
				// A held lock is a till at work on that order: it stays in the queue for the next request.
				Logger::info(
					'Legacy Square adoption deferred',
					array(
						'order_id' => (int) $order_id,
						'code' => $result->get_error_code(),
					)
				);
				continue;
			}
			if ( is_wp_error( $result ) ) {
				Logger::error(
					'Legacy Square adoption failed',
					array(
						'order_id' => (int) $order_id,
						'code' => $result->get_error_code(),
					)
				);
			}
			unset( $queue[ $order_id ] );
		}
		update_option( self::QUEUE_OPTION, $queue, false );
		if ( array() === $queue ) {
			delete_option( self::QUEUE_OPTION );
			update_option( self::VERSION_OPTION, self::VERSION, false );
		}
	}

	/**
	 * Whether a refusal is a passing one (a till at work), to be retried, rather than final.
	 *
	 * @param \WP_Error $error Refusal.
	 */
	public static function is_deferral( \WP_Error $error ): bool {
		return in_array( $error->get_error_code(), array( 'wcpos_payment_locked', 'sqtwc_adoption_no_lock', 'sqtwc_adoption_completing' ), true );
	}

	/**
	 * Adopt the order's live checkout, if it has one Pro does not own yet: under Free's per-order
	 * lock, on a fresh read, and under the old paths' own per-order lock (the completion claim).
	 * Run by the upgrade pass for each snapshotted order, and by Pro's panel before it renders, so
	 * an attempt the pass has not reached yet is Pro's before the page can offer a second charge.
	 *
	 * @param int $order_id Order id.
	 * @return array|null|\WP_Error The row, null when nothing applied, or a refusal.
	 */
	public static function adopt_order( int $order_id ) {
		// Nothing to adopt (no live checkout, or one Pro already has) is the common page load:
		// answer without taking the order lock, which a till may hold for a moment.
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}
		$ref = self::action_ref( $order );
		if ( '' === $ref || self::is_adopted( $ref ) || $order->is_paid() || ! $order->needs_payment() ) {
			return null;
		}
		if ( ! class_exists( '\WCPOS\WooCommercePOS\Payments\Contract\Order_Lock' ) ) {
			return new \WP_Error( 'sqtwc_adoption_no_lock', 'The POS order lock is unavailable.' );
		}

		return \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::instance()->with_lock(
			$order_id,
			static function () use ( $order_id ) {
				// The old paths complete a paid checkout under their own per-order lock; while one
				// holds it this attempt is mid-completion, and the caller tries again later. Anything
				// Pro refuses or throws is final for this order: logged, never retried on every request.
				$lock = new OrderLock();
				if ( ! $lock->acquire( $order_id ) ) {
					return new \WP_Error( 'sqtwc_adoption_completing', 'A completion of this payment is in progress.' );
				}
				try {
					// Read the order under BOTH locks, caches cleared, and judge that copy: a completion
					// that held the old lock a moment ago has written its result by now.
					$fresh = OrderMeta::reload_order( $order_id );
					if ( ! $fresh ) {
						return null;
					}
					$ref = self::action_ref( $fresh );
					if ( '' === $ref || self::is_adopted( $ref ) || $fresh->is_paid() || ! $fresh->needs_payment() ) {
						return null;
					}
					$row = wcpos_pro_adopt_legacy_attempt( $fresh, Gateway::ID, $ref, (string) $fresh->get_total(), $fresh->get_currency() );
					if ( is_array( $row ) ) {
						$fresh->update_meta_data( self::META_ADOPTED, $ref );
						$fresh->save();
					}

					return $row;
				} catch ( Throwable $e ) {
					return new \WP_Error( 'sqtwc_adoption_failed', $e->getMessage() );
				} finally {
					$lock->release( $order_id );
				}
			}
		);
	}
}
