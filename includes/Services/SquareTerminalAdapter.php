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
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\TerminalCheckout;

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
						'deviceId' => (string) $data['device_id'],
					)
				),
				'referenceId'   => (string) $data['reference_id'],
				'note'          => $data['note'] ?? null,
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
	public function get_checkout( string $checkout_id ): array {
		$request  = new GetCheckoutsRequest( array( 'checkoutId' => $checkout_id ) );
		$response = $this->client->terminal->checkouts->get( $request );

		return $this->normalize_checkout( $response->getCheckout() );
	}

	/**
	 * Cancel a Terminal Checkout.
	 *
	 * @param string $checkout_id Square checkout ID.
	 * @return array<string,mixed>
	 */
	public function cancel_checkout( string $checkout_id ): array {
		$request  = new CancelCheckoutsRequest( array( 'checkoutId' => $checkout_id ) );
		$response = $this->client->terminal->checkouts->cancel( $request );

		return $this->normalize_checkout( $response->getCheckout() );
	}

	/**
	 * Normalize a Square checkout object.
	 *
	 * @param TerminalCheckout|null $checkout Square checkout object.
	 * @return array<string,mixed>
	 */
	private function normalize_checkout( ?TerminalCheckout $checkout ): array {
		return array(
			'id'          => $checkout ? $checkout->getId() : null,
			'status'      => $checkout ? $checkout->getStatus() : null,
			'reference_id' => $checkout ? $checkout->getReferenceId() : null,
			'payment_ids' => $checkout ? ( $checkout->getPaymentIds() ?? array() ) : array(),
		);
	}
}
