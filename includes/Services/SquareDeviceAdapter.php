<?php
/**
 * Square Device Code SDK adapter.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Devices\Codes\Requests\CreateDeviceCodeRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\DeviceCode;

/**
 * Translates plugin arrays to typed Square Device Code requests.
 */
final class SquareDeviceAdapter {
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
	 * Create a Terminal API Device Code.
	 *
	 * @param array<string,mixed> $data Device code data.
	 * @return array<string,mixed>
	 */
	public function create_device_code( array $data ): array {
		$device_code = new DeviceCode(
			array(
				'productType' => 'TERMINAL_API',
				'locationId'  => (string) $data['location_id'],
				'name'        => (string) $data['name'],
			)
		);
		$request     = new CreateDeviceCodeRequest(
			array(
				'idempotencyKey' => (string) $data['idempotency_key'],
				'deviceCode'     => $device_code,
			)
		);
		$response    = $this->client->devices->codes->create( $request );

		return $this->normalize_device_code( $response->getDeviceCode() );
	}

	/**
	 * Normalize a Square device code object.
	 *
	 * @param DeviceCode|null $code Square device code object.
	 * @return array<string,mixed>
	 */
	private function normalize_device_code( ?DeviceCode $code ): array {
		return array(
			'id'          => $code ? $code->getId() : null,
			'name'        => $code ? $code->getName() : null,
			'code'        => $code ? $code->getCode() : null,
			'device_id'   => $code ? $code->getDeviceId() : null,
			'status'      => $code ? $code->getStatus() : null,
			'location_id' => $code ? $code->getLocationId() : null,
		);
	}
}
