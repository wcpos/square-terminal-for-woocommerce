<?php
/**
 * AJAX payment lifecycle handlers.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

use WCPOS\WooCommercePOS\SquareTerminal\Utils\CurrencyConverter;

/**
 * Handles Square Terminal payment AJAX requests.
 */
final class AjaxHandler {
	/**
	 * Square Terminal adapter.
	 *
	 * @var object
	 */
	private $terminal_adapter;

	/**
	 * Constructor.
	 *
	 * @param object $terminal_adapter Square Terminal adapter.
	 */
	public function __construct( $terminal_adapter ) {
		$this->terminal_adapter = $terminal_adapter;
	}

	/**
	 * Create a Square Terminal checkout for a WooCommerce order.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function create_terminal_checkout( array $request ): array {
		$order_id = absint( $request['order_id'] ?? 0 );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return array(
				'status' => 404,
				'error'  => 'Order not found.',
			);
		}

		if ( ! empty( $request['logged_in'] ) && ! wp_verify_nonce( $request['_wpnonce'] ?? '', 'sqtwc_payment' ) ) {
			return array(
				'status' => 403,
				'error'  => 'Invalid nonce.',
			);
		}

		if ( ! OrderAccess::can_mutate_order( $order, $request ) ) {
			return array(
				'status' => 403,
				'error'  => 'Order access denied.',
			);
		}

		$device_id = sanitize_text_field( $request['device_id'] ?? '' );
		if ( '' === $device_id ) {
			return array(
				'status' => 400,
				'error'  => 'Device ID is required.',
			);
		}

		$result = $this->terminal_adapter->create_checkout(
			array(
				'amount'          => CurrencyConverter::to_minor_units( $order->get_total(), $order->get_currency() ),
				'currency'        => $order->get_currency(),
				'device_id'       => $device_id,
				'reference_id'    => 'woocommerce_order_' . $order_id,
				'idempotency_key' => wp_generate_uuid4(),
			)
		);

		$order->update_meta_data( '_sqtwc_checkout_id', $result['id'] );
		$order->update_meta_data( '_sqtwc_payment_log', array( 'Terminal checkout created: ' . $result['id'] ) );
		$order->save();

		Logger::info( 'Terminal checkout created', array( 'checkout_id' => $result['id'] ), $order );

		return array(
			'status'   => 200,
			'checkout' => $result,
		);
	}
}
