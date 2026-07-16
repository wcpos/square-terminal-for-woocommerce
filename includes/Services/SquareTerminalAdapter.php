<?php
/**
 * Square Terminal checkout SDK adapter.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Terminal\Checkouts\Requests\CancelCheckoutsRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Terminal\Checkouts\Requests\CreateTerminalCheckoutRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Terminal\Checkouts\Requests\GetCheckoutsRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\DeviceCheckoutOptions;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Money;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Payment;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\PaymentOptions;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\TerminalCheckout;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Payments\Requests\GetPaymentsRequest;

/**
 * Translates plugin arrays to typed Square Terminal checkout requests.
 */
final class SquareTerminalAdapter {
	/**
	 * Square SDK client.
	 *
	 * @var object
	 */
	private object $client;

	/**
	 * Constructor.
	 *
	 * @param object $client Square SDK client.
	 */
	public function __construct( object $client ) {
		$this->client = $client;
	}

	/**
	 * Create a Terminal Checkout.
	 *
	 * @param array<string,mixed> $data Checkout data.
	 * @return array<string,mixed>
	 */
	public function create_checkout( array $data ): array {
		$checkout = new TerminalCheckout(
			array(
				'amountMoney'   => new Money(
					array(
						'amount'   => (int) $data['amount'],
						'currency' => (string) $data['currency'],
					)
				),
				'deviceOptions' => new DeviceCheckoutOptions(
					array(
						'deviceId'          => (string) $data['device_id'],
						'skipReceiptScreen' => (bool) ( $data['skip_receipt_screen'] ?? false ),
						'collectSignature'  => (bool) ( $data['collect_signature'] ?? false ),
					)
				),
				'referenceId'   => (string) $data['reference_id'],
				'note'          => isset( $data['note'] ) ? (string) $data['note'] : null,
				'deadlineDuration' => (string) ( $data['deadline_duration'] ?? 'PT5M' ),
				'paymentOptions'   => new PaymentOptions( array( 'autocomplete' => true ) ),
			)
		);

		$request  = new CreateTerminalCheckoutRequest(
			array(
				'idempotencyKey' => (string) $data['idempotency_key'],
				'checkout'       => $checkout,
			)
		);
		$response = $this->client->terminal->checkouts->create( $request );

		return $this->normalize_checkout( $response->getCheckout() );
	}

	/**
	 * Retrieve a Terminal Checkout.
	 *
	 * @param string $checkout_id Square checkout ID.
	 * @return array<string,mixed>
	 */
	public function get_checkout( string $checkout_id, array $options = array() ): array {
		$request  = new GetCheckoutsRequest( array( 'checkoutId' => $checkout_id ) );
		$response = $this->client->terminal->checkouts->get( $request, $options );

		return $this->normalize_checkout( $response->getCheckout() );
	}

	/**
	 * Cancel a Terminal Checkout.
	 *
	 * @param string $checkout_id Square checkout ID.
	 * @return array<string,mixed>
	 */
	public function cancel_checkout( string $checkout_id, array $options = array() ): array {
		$request  = new CancelCheckoutsRequest( array( 'checkoutId' => $checkout_id ) );
		$response = $this->client->terminal->checkouts->cancel( $request, $options );

		return $this->normalize_checkout( $response->getCheckout() );
	}

	/**
	 * Retrieve and normalize a Square Payment.
	 *
	 * @param string              $payment_id Square payment ID.
	 * @param array<string,mixed> $options    SDK request options.
	 * @return array<string,mixed>
	 */
	public function get_payment( string $payment_id, array $options = array() ): array {
		$request  = new GetPaymentsRequest( array( 'paymentId' => $payment_id ) );
		$response = $this->client->payments->get( $request, $options );

		return $this->normalize_payment( $response->getPayment() );
	}

	/**
	 * Normalize a Square checkout object.
	 *
	 * @param TerminalCheckout|null $checkout Square checkout object.
	 * @return array<string,mixed>
	 */
	private function normalize_checkout( ?TerminalCheckout $checkout ): array {
		return array(
			'id'            => $checkout ? $checkout->getId() : null,
			'status'        => $checkout ? $checkout->getStatus() : null,
			'reference_id'  => $checkout ? $checkout->getReferenceId() : null,
			'payment_ids'   => $checkout ? ( $checkout->getPaymentIds() ?? array() ) : array(),
			'updated_at'    => $checkout ? $checkout->getUpdatedAt() : null,
			'created_at'    => $checkout ? $checkout->getCreatedAt() : null,
			'cancel_reason' => $checkout ? $checkout->getCancelReason() : null,
		);
	}

	/**
	 * Normalize a Square payment object.
	 *
	 * @param Payment|null $payment Square payment object.
	 * @return array<string,mixed>
	 */
	private function normalize_payment( ?Payment $payment ): array {
		$total = $payment ? $payment->getTotalMoney() : null;
		$tip   = $payment ? $payment->getTipMoney() : null;
		$card  = $payment ? $payment->getCardDetails() : null;

		return array(
			'id'             => $payment ? $payment->getId() : null,
			'status'         => $payment ? $payment->getStatus() : null,
			'total_amount'   => $total ? $total->getAmount() : null,
			'total_currency' => $total ? $total->getCurrency() : null,
			'tip_amount'     => $tip ? $tip->getAmount() : 0,
			'tip_currency'   => $tip ? $tip->getCurrency() : null,
			'card_status'    => $card ? $card->getStatus() : null,
		);
	}
}
