<?php
/**
 * Square POS transaction verification.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use RuntimeException;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Orders\Requests\GetOrdersRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Payments\Requests\GetPaymentsRequest;

/**
 * Resolves a POS callback's Square Order ID to captured Payments.
 */
final class PosTransactionVerifier {
	/** Square SDK client. */
	private object $client;

	/**
	 * @param object $client Square SDK client.
	 */
	public function __construct( object $client ) {
		$this->client = $client;
	}

	/**
	 * Verify completed card payments belonging to a Square Order.
	 *
	 * @return array{payment_ids:array<int,string>,amount:int,currency:string,location_id:string}
	 */
	public function verify( string $transaction_id ): array {
		$response = $this->client->orders->get( new GetOrdersRequest( array( 'orderId' => $transaction_id ) ) );
		$order    = $response->getOrder();
		if ( ! $order ) {
			throw new RuntimeException( 'Square POS order was not found.' );
		}

		$payment_ids = array();
		$amount      = 0;
		$currency    = '';
		foreach ( $order->getTenders() ?? array() as $tender ) {
			$payment_id = (string) $tender->getPaymentId();
			if ( '' === $payment_id ) {
				continue;
			}

			$payment = $this->client->payments->get( new GetPaymentsRequest( array( 'paymentId' => $payment_id ) ) )->getPayment();
			$money   = $payment ? $payment->getTotalMoney() : null;
			if ( ! $payment || 'COMPLETED' !== $payment->getStatus() || ! $money || ! is_numeric( $money->getAmount() ) ) {
				continue;
			}

			$payment_currency = (string) $money->getCurrency();
			if ( '' !== $currency && $currency !== $payment_currency ) {
				throw new RuntimeException( 'Square POS payments use different currencies.' );
			}
			$currency      = $payment_currency;
			$amount       += (int) $money->getAmount();
			$payment_ids[] = $payment_id;
		}

		if ( empty( $payment_ids ) ) {
			throw new RuntimeException( 'Square POS order has no completed verifiable payments.' );
		}

		return array(
			'payment_ids' => $payment_ids,
			'amount'      => $amount,
			'currency'    => $currency,
			'location_id' => (string) $order->getLocationId(),
		);
	}
}
