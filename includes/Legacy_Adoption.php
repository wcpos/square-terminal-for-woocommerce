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

	/** The attempt id whose release note was written: one note per unconfirmed attempt. */
	public const META_UNCONFIRMED_NOTED = '_sqtwc_unconfirmed_noted';

	private const QUEUE_OPTION   = 'sqtwc_adoption_queue';
	private const VERSION_OPTION = 'sqtwc_adoption_version';

	/** Checkout statuses the old panel still polls. */
	private const LIVE_CHECKOUT_STATUSES = array( 'PENDING', 'IN_PROGRESS', 'CANCEL_REQUESTED' );

	/** Transient that pauses the upgrade pass after Square could not be read about an attempt's environment. */
	private const READ_PAUSE = 'sqtwc_adoption_read_pause';

	/** How long that pause lasts: no request pays Square's ten second timeout per order meanwhile. */
	private const READ_PAUSE_SECONDS = 60;

	/**
	 * How old an attempt may be and still be adopted: Square cancels an unpaid checkout five
	 * minutes after its create, and the old sweep reconciles a checkout once it is ten minutes
	 * old, so a pointer older than an hour names a checkout that has long ended (the sweep
	 * closes it). It also bounds how long an attempt saved without its environment, which Square
	 * could not be asked about, keeps being asked. The old sweep stays in charge of those; until it
	 * has read them, Pro's panel is held back (has_stale_live_pointer()).
	 */
	public const ADOPTION_WINDOW = HOUR_IN_SECONDS;

	/**
	 * The action reference Pro's provider uses for the attempt the old panel started: the
	 * order's current checkout while it is still live, in the environment it was made in
	 * (ref_for(): the reference kept at adoption, else the environment the attempt saved, else the
	 * current one, which adopt_order() confirms with Square). '' for a final checkout or none.
	 * An attempt whose create Square never confirmed has no checkout id
	 * and cannot be adopted; the old sweep keeps the order indexed but cannot resolve it, so Pro's
	 * panel is held for the adoption window from that attempt (has_unconfirmed_create()).
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

		return self::ref_for( $order, $checkout_id );
	}

	/**
	 * The environment the old panel saved with the order's current attempt, '' for none: the last
	 * stable release (0.8.7) saved its attempts without one.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function saved_environment( $order ): string {
		$request     = $order->get_meta( '_sqtwc_attempt_request', true );
		$environment = is_array( $request ) ? (string) ( $request['environment'] ?? '' ) : '';

		return in_array( $environment, array( 'sandbox', 'production' ), true ) ? $environment : '';
	}

	/**
	 * The action reference for a checkout of this order: the one kept at adoption when it names
	 * this checkout; else, for the current checkout, the environment its attempt saved; else the
	 * current environment's. An earlier checkout is not the current pointer, so the saved
	 * environment says nothing about it.
	 *
	 * @param \WC_Order $order       Order.
	 * @param string    $checkout_id Square checkout id.
	 */
	private static function ref_for( $order, string $checkout_id ): string {
		$adopted = (string) $order->get_meta( self::META_ADOPTED, true );
		if ( '' !== $adopted && Square_Server_Provider::parse_ref( $adopted )[1] === $checkout_id ) {
			return $adopted;
		}
		$saved = (string) $order->get_meta( '_sqtwc_checkout_id', true ) === $checkout_id ? self::saved_environment( $order ) : '';

		return Square_Server_Provider::ref( '' !== $saved ? $saved : Settings::get_environment(), $checkout_id );
	}

	/**
	 * Whether the order carries a checkout pointer the old panel still thinks live but too old to
	 * adopt: its outcome is unknown until the old sweep has read it from Square. Age alone does not
	 * say no money arrived, so Pro's panel must not offer a second charge beside it.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function has_stale_live_pointer( $order ): bool {
		$checkout_id = (string) $order->get_meta( '_sqtwc_checkout_id', true );
		$status      = (string) $order->get_meta( '_sqtwc_checkout_status', true );
		$started     = (int) $order->get_meta( '_sqtwc_attempt_started', true );

		return '' !== $checkout_id && in_array( $status, self::LIVE_CHECKOUT_STATUSES, true ) && ( $started <= 0 || $started < time() - self::ADOPTION_WINDOW );
	}

	/**
	 * Whether Pro's panel must be held back for this order: no checkout to adopt, the order unpaid,
	 * a pointer the old panel still marks live but too old to adopt, and no live Pro row for it.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $ref   The adoptable reference, '' for none.
	 */
	private static function is_held( $order, string $ref ): bool {
		return '' === $ref && ! $order->is_paid() && self::has_stale_live_pointer( $order ) && ! self::pro_row_live( $order, (string) $order->get_meta( '_sqtwc_checkout_id', true ) );
	}

	/**
	 * Whether the old panel started an attempt whose create Square never confirmed: an attempt id,
	 * no checkout id, and a status the old panel still polls. Square may have made that checkout.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function has_unconfirmed_create( $order ): bool {
		return '' !== (string) $order->get_meta( '_sqtwc_current_attempt_id', true ) && '' === (string) $order->get_meta( '_sqtwc_checkout_id', true ) && in_array( (string) $order->get_meta( '_sqtwc_checkout_status', true ), self::LIVE_CHECKOUT_STATUSES, true );
	}

	/**
	 * Whether an unconfirmed create is still inside the adoption window. An attempt with no start
	 * time counts as past it: a hold with no end would strand the order.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function unconfirmed_within_window( $order ): bool {
		$started = (int) $order->get_meta( '_sqtwc_attempt_started', true );
		return self::has_unconfirmed_create( $order ) && $started > 0 && $started >= time() - self::ADOPTION_WINDOW;
	}

	/**
	 * Whether Pro's panel is held beside an unconfirmed create: unpaid, inside the window, and no
	 * live Pro leg (one keeps its panel and controls).
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function is_unconfirmed_hold( $order ): bool {
		return ! $order->is_paid() && self::unconfirmed_within_window( $order ) && ! self::pro_has_live_row( $order );
	}

	/**
	 * Why a POS tile start must not put a checkout beside the old panel's attempt, '' when it may.
	 * Not is_unconfirmed_hold(): the row being started is a live Pro row, which lifts that hold.
	 *
	 * @param \WC_Order $order Order, read fresh.
	 */
	public static function tile_hold_message( $order ): string {
		if ( $order->is_paid() ) {
			return '';
		}
		if ( self::unconfirmed_within_window( $order ) ) {
			return __( 'Square never confirmed an earlier Terminal payment for this sale, and it may still be open. Check the Terminal and the Square dashboard. A new payment can start one hour after that attempt began.', 'square-terminal-for-woocommerce' );
		}
		$ref = self::action_ref( $order );
		if ( self::is_held( $order, $ref ) ) {
			return __( 'An earlier Square Terminal payment for this sale has not been confirmed yet. It is being checked. Try again in a few minutes, or check it in the Square dashboard.', 'square-terminal-for-woocommerce' );
		}
		if ( '' !== $ref && ! self::is_adopted( $ref ) ) {
			return __( 'An earlier Square Terminal payment for this sale is still open on the payment page. Finish or cancel it there first.', 'square-terminal-for-woocommerce' );
		}
		return '';
	}

	/**
	 * Whether an unconfirmed create past the window still needs its one release note.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function needs_unconfirmed_note( $order ): bool {
		return ! $order->is_paid() && self::has_unconfirmed_create( $order ) && ! self::unconfirmed_within_window( $order ) && (string) $order->get_meta( self::META_UNCONFIRMED_NOTED, true ) !== (string) $order->get_meta( '_sqtwc_current_attempt_id', true );
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
	 * Whether Pro's row for this checkout can be read and is live: the one case in which an old
	 * pointer is not held back (Pro's panel shows that leg and its controls). An adoption record
	 * whose row cannot be read counts as owned elsewhere, but not here: its outcome is unknown.
	 *
	 * @param \WC_Order $order       Order.
	 * @param string    $checkout_id Square checkout id.
	 */
	public static function pro_row_live( $order, string $checkout_id ): bool {
		if ( '' === $checkout_id || ! function_exists( 'wcpos_pro_payment_id_for_action' ) || ! class_exists( '\WCPOS\WooCommercePOS\Payments\Contract\Ledger' ) ) {
			return false;
		}
		$ref        = self::ref_for( $order, $checkout_id );
		$payment_id = wcpos_pro_payment_id_for_action( Square_Server_Provider::PROVIDER, $ref );
		if ( null === $payment_id ) {
			return false;
		}
		$row = \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance()->find( $order, (string) $payment_id );

		return null !== $row && in_array( $row['status'] ?? '', \WCPOS\WooCommercePOS\Payments\Contract\Ledger::LIVE_STATUSES, true );
	}

	/**
	 * Whether Pro owns a checkout of this order, by its id and the reference ref_for() gives it.
	 *
	 * @param \WC_Order $order       Order.
	 * @param string    $checkout_id Square checkout id.
	 */
	public static function owns_checkout( $order, string $checkout_id ): bool {
		if ( '' === $checkout_id || ! function_exists( 'wcpos_pro_payment_id_for_action' ) ) {
			return false;
		}
		return self::owned_by_pro( $order, self::ref_for( $order, $checkout_id ) );
	}

	/**
	 * Whether Pro captured an adopted checkout of this order: its row is `captured`, so Pro settled
	 * the money and the old attempt is finished too.
	 *
	 * @param \WC_Order $order       Order.
	 * @param string    $checkout_id Square checkout id.
	 */
	public static function captured_by_pro( $order, string $checkout_id ): bool {
		if ( '' === $checkout_id || ! function_exists( 'wcpos_pro_payment_id_for_action' ) || ! class_exists( '\WCPOS\WooCommercePOS\Payments\Contract\Ledger' ) ) {
			return false;
		}
		$ref        = self::ref_for( $order, $checkout_id );
		$payment_id = wcpos_pro_payment_id_for_action( Square_Server_Provider::PROVIDER, $ref );
		if ( null === $payment_id ) {
			return false;
		}
		$row = \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance()->find( $order, (string) $payment_id );

		return 'captured' === ( $row['status'] ?? '' );
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
		if ( version_compare( (string) get_option( self::VERSION_OPTION, '0' ), self::VERSION, '>=' ) || ! Settings::uses_pro_panel() || false !== get_transient( self::READ_PAUSE ) ) {
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
				if ( 'sqtwc_adoption_environment_unread' === $result->get_error_code() ) {
					// Square is not answering: the rest of the page would wait out the same timeout.
					set_transient( self::READ_PAUSE, 1, self::READ_PAUSE_SECONDS );
					break;
				}
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
	 * Whether a refusal is a passing one (a till at work, or Square not read), to be retried, rather than final.
	 *
	 * @param \WP_Error $error Refusal.
	 */
	public static function is_deferral( \WP_Error $error ): bool {
		return in_array( $error->get_error_code(), array( 'wcpos_payment_locked', 'sqtwc_adoption_no_lock', 'sqtwc_adoption_completing', 'sqtwc_adoption_environment_unread' ), true );
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
		// An old pointer with an unknown outcome is decided under the locks too (a parallel request may
		// have adopted it meanwhile); everything else that is nothing to adopt answers here.
		if ( ! self::is_held( $order, $ref ) && ! self::is_unconfirmed_hold( $order ) && ! self::needs_unconfirmed_note( $order ) && ( '' === $ref || self::is_adopted( $ref ) || $order->is_paid() || ! $order->needs_payment() ) ) {
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
					if ( self::is_unconfirmed_hold( $fresh ) ) {
						return new \WP_Error( 'sqtwc_adoption_unconfirmed_create', 'Square never confirmed an earlier checkout on this order.' );
					}
					if ( self::needs_unconfirmed_note( $fresh ) ) {
						$fresh->add_order_note( __( 'Square never confirmed an earlier Terminal checkout on this order, and it may have been paid. Check the Square dashboard before taking this payment again.', 'square-terminal-for-woocommerce' ) );
						$fresh->update_meta_data( self::META_UNCONFIRMED_NOTED, (string) $fresh->get_meta( '_sqtwc_current_attempt_id', true ) );
						$fresh->save();
					}
					$ref = self::action_ref( $fresh );
					if ( self::is_held( $fresh, $ref ) ) {
						// Unknown outcome: held. A checkout whose Pro row reads live renders Pro's panel,
						// whose controls poll and cancel it, however old the pointer is.
						return new \WP_Error( 'sqtwc_adoption_stale_attempt', 'An older Square checkout on this order has not been read from Square yet.' );
					}
					if ( '' === $ref || self::is_adopted( $ref ) || $fresh->is_paid() || ! $fresh->needs_payment() ) {
						return null;
					}
					if ( '' === self::saved_environment( $fresh ) ) {
						// Saved before the environment was kept: only Square's answer says the checkout is
						// this order's in the environment the reference names. Anything but an answer proves nothing.
						list( $environment, $checkout_id ) = Square_Server_Provider::parse_ref( $ref );
						$reference = ( new Square_Server_Provider() )->checkout_reference( $environment, $checkout_id );
						if ( is_wp_error( $reference ) ) {
							return new \WP_Error( 'sqtwc_adoption_environment_unread', 'Square could not be asked which environment this checkout was made in.' );
						}
						if ( 'woocommerce_order_' . $fresh->get_id() !== $reference ) {
							return new \WP_Error( 'sqtwc_adoption_other_environment', 'This checkout is not this order\'s in the current Square environment.' );
						}
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
