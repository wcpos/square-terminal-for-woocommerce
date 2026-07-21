<?php
/**
 * Square Device Code SDK adapter.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Devices\Codes\Requests\CreateDeviceCodeRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Devices\Codes\Requests\ListCodesRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Locations\Requests\GetLocationsRequest;
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
	 * List paired Terminal API devices for a Square location.
	 *
	 * The Device Code's deviceId is the identifier accepted by Terminal
	 * Checkout requests. It is intentionally not sourced from the separate
	 * Devices monitoring API.
	 *
	 * @param string $location_id Square location ID.
	 * @return array<int,array{id:string,label:string}>
	 */
	public function list_paired_devices( string $location_id ): array {
		$request = new ListCodesRequest(
			array(
				'locationId'  => $location_id,
				'productType' => 'TERMINAL_API',
				'status'      => 'PAIRED',
			)
		);
		$devices = array();

		foreach ( $this->client->devices->codes->list( $request ) as $code ) {
			$device_id = (string) $code->getDeviceId();
			if (
				'PAIRED' !== $code->getStatus()
				|| 'TERMINAL_API' !== $code->getProductType()
				|| $location_id !== $code->getLocationId()
				|| '' === $device_id
			) {
				continue;
			}

			$name      = $code->getName();
			$devices[] = array(
				'id'    => $device_id,
				'label' => null !== $name && '' !== $name ? $name : $device_id,
			);
		}

		return $devices;
	}

	/**
	 * Verify that the configured credentials can retrieve a Square location.
	 *
	 * @param string $location_id Square location ID.
	 */
	public function validate_location( string $location_id ): void {
		$this->client->locations->get( new GetLocationsRequest( array( 'locationId' => $location_id ) ) );
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
