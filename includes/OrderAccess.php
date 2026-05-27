<?php
/**
 * Order mutation access checks.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

/**
 * Validates order access for payment side-effect endpoints.
 */
final class OrderAccess {
	/**
	 * Determine whether a request may mutate payment state for an order.
	 *
	 * @param object              $order   WooCommerce order.
	 * @param array<string,mixed> $request Request data.
	 */
	public static function can_mutate_order( $order, array $request ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		if ( isset( $request['order_key'] ) && hash_equals( (string) $order->get_order_key(), (string) $request['order_key'] ) ) {
			return true;
		}

		if ( isset( $request['payment_request_token'] ) ) {
			return PaymentRequestToken::verify( (string) $request['payment_request_token'], (int) $order->get_id() );
		}

		return false;
	}
}
