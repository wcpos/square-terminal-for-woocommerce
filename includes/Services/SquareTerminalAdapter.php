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
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Refunds\Requests\RefundPaymentRequest;

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
				'note'          => isset( $data['note'] ) ? mb_substr( (string) $data['note'], 0, 500 ) : null, // Square allows 500 characters.
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
	 * Refund a Square payment.
	 *
	 * Square dedupes on the idempotency key: a replay of the same request hands back the refund
	 * the first request made, so the caller may safely ask again when a response was lost.
	 *
	 * @param array<string,mixed> $data    Refund data: idempotency_key, payment_id, amount, currency, reason.
	 * @param array<string,mixed> $options SDK request options.
	 * @return array<string,mixed>
	 */
	public function refund_payment( array $data, array $options = array() ): array {
		$request  = new RefundPaymentRequest(
			array(
				'idempotencyKey' => (string) $data['idempotency_key'],
				'paymentId'      => (string) $data['payment_id'],
				'amountMoney'    => new Money(
					array(
						'amount'   => (int) $data['amount'],
						'currency' => (string) $data['currency'],
					)
				),
				// Square allows 192 characters of reason.
				'reason'         => isset( $data['reason'] ) && '' !== $data['reason'] ? mb_substr( (string) $data['reason'], 0, 192 ) : null,
			)
		);
		$response = $this->client->refunds->refundPayment( $request, $options );
		$refund   = $response->getRefund();

		return array(
			'id'         => $refund ? $refund->getId() : null,
			'status'     => $refund ? $refund->getStatus() : null,
			'payment_id' => $refund ? $refund->getPaymentId() : null,
			'amount'     => $refund && $refund->getAmountMoney() ? $refund->getAmountMoney()->getAmount() : null,
			'currency'   => $refund && $refund->getAmountMoney() ? $refund->getAmountMoney()->getCurrency() : null,
		);
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
			'device_id'     => $checkout && $checkout->getDeviceOptions() ? $checkout->getDeviceOptions()->getDeviceId() : null,
		);
	}

	/**
	 * Normalize a Square payment object.
	 *
	 * @param Payment|null $payment Square payment object.
	 * @return array<string,mixed>
	 */
	private function normalize_payment( ?Payment $payment ): array {
		$total  = $payment ? $payment->getTotalMoney() : null;
		$tip    = $payment ? $payment->getTipMoney() : null;
		$card   = $payment ? $payment->getCardDetails() : null;
		$errors = $card ? (array) ( $card->getErrors() ?? array() ) : array();
		$error  = $errors ? $errors[0] : null;

		return array(
			'id'             => $payment ? $payment->getId() : null,
			'status'         => $payment ? $payment->getStatus() : null,
			'total_amount'   => $total ? $total->getAmount() : null,
			'total_currency' => $total ? $total->getCurrency() : null,
			'tip_amount'     => $tip ? $tip->getAmount() : 0,
			'tip_currency'   => $tip ? $tip->getCurrency() : null,
			'card_status'    => $card ? $card->getStatus() : null,
			'card_brand'     => $card && $card->getCard() ? $card->getCard()->getCardBrand() : null,
			'card_last4'     => $card && $card->getCard() ? $card->getCard()->getLast4() : null,
			'entry_method'   => $card ? $card->getEntryMethod() : null,
			'auth_code'      => $card ? $card->getAuthResultCode() : null,
			'error_code'     => $error && method_exists( $error, 'getCode' ) ? $error->getCode() : null,
			'receipt_number' => $payment ? $payment->getReceiptNumber() : null,
			'reference_id'   => $payment ? $payment->getReferenceId() : null,
		);
	}
}
