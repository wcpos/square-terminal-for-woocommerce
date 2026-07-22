<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;

use ArrayIterator;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareDeviceAdapter;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Devices\Codes\Requests\CreateDeviceCodeRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Devices\Codes\Requests\ListCodesRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Devices\Requests\ListDevicesRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Component;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\Device;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\DeviceAttributes;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\DeviceComponentDetailsApplicationDetails;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\DeviceStatus;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Locations\Requests\GetLocationsRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\CreateDeviceCodeResponse;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\DeviceCode;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\GetLocationResponse;

final class SpyCodesClient {
	public ?CreateDeviceCodeRequest $created_request = null;
	public ?ListCodesRequest $list_request = null;

	/** @var array<int,DeviceCode> */
	public array $listed_codes = array();

	public function create( CreateDeviceCodeRequest $request ): CreateDeviceCodeResponse {
		$this->created_request = $request;

		return new CreateDeviceCodeResponse(
			array(
				'deviceCode' => new DeviceCode(
					array(
						'id'          => 'dc_1',
						'name'        => 'Front',
						'code'        => 'ABCD',
						'deviceId'    => 'dev_1',
						'productType' => 'TERMINAL_API',
						'locationId'  => 'LOC',
						'status'      => 'PAIRED',
					)
				),
			)
		);
	}

	public function list( ListCodesRequest $request ): ArrayIterator {
		$this->list_request = $request;

		return new ArrayIterator( $this->listed_codes );
	}
}

final class SpyLocationsClient {
	public ?GetLocationsRequest $get_request = null;

	public function get( GetLocationsRequest $request ): GetLocationResponse {
		$this->get_request = $request;

		return new GetLocationResponse();
	}
}

final class SpyDevicesClient {
	public ?ListDevicesRequest $list_request = null;

	/** @var array<int,Device> */
	public array $listed_devices = array();

	public SpyCodesClient $codes;

	public function __construct( SpyCodesClient $codes ) {
		$this->codes = $codes;
	}

	public function list( ListDevicesRequest $request ): ArrayIterator {
		$this->list_request = $request;

		return new ArrayIterator( $this->listed_devices );
	}
}

final class SquareDeviceAdapterTest extends TestCase {
	public function test_list_account_devices_reports_monitoring_records(): void {
		$codes                  = new SpyCodesClient();
		$spy                    = new SpyDevicesClient( $codes );
		$spy->listed_devices    = array(
			new Device(
				array(
					'id'         => 'device:monitoring-1',
					'attributes' => new DeviceAttributes(
						array(
							'type'         => 'TERMINAL',
							'manufacturer' => 'Square',
							'model'        => 'Terminal API',
							'name'         => 'Front counter',
						)
					),
					'status'     => new DeviceStatus( array( 'category' => 'AVAILABLE' ) ),
				)
			),
		);
		$client = (object) array( 'devices' => $spy );

		$result = ( new SquareDeviceAdapter( $client ) )->list_account_devices( 'LOC' );

		self::assertSame( 'LOC', $spy->list_request->getLocationId() );
		self::assertCount( 1, $result );
		self::assertSame( 'Front counter', $result[0]['name'] );
		self::assertSame( 'AVAILABLE', $result[0]['status'] );

		// The monitoring API returns Handhelds and other hardware too; the type
		// travels with the record so nothing is presented as a Terminal blindly.
		self::assertSame( 'TERMINAL', $result[0]['type'] );

		// The monitoring identifier must never be offered as a checkout device
		// ID — sending it to Terminal Checkout fails every payment.
		self::assertArrayNotHasKey( 'id', $result[0] );
		self::assertSame( 'device:monitoring-1', $result[0]['monitoring_id'] );
	}

	public function test_list_account_devices_excludes_plugin_paired_device_codes(): void {
		$codes               = new SpyCodesClient();
		$codes->listed_codes = array(
			new DeviceCode( array( 'id' => 'code_paired', 'deviceId' => 'device_paired', 'productType' => 'TERMINAL_API', 'locationId' => 'LOC', 'status' => 'PAIRED' ) ),
		);
		$spy                 = new SpyDevicesClient( $codes );
		$spy->listed_devices = array(
			new Device(
				array(
					'id'         => 'device:paired',
					'attributes' => new DeviceAttributes( array( 'type' => 'TERMINAL', 'manufacturer' => 'Square', 'model' => 'Terminal', 'name' => 'Paired' ) ),
					'components' => array(
						new Component(
							array(
								'type'               => 'APPLICATION',
								'applicationDetails' => new DeviceComponentDetailsApplicationDetails( array( 'applicationType' => 'TERMINAL_API', 'deviceCodeId' => 'code_paired' ) ),
							)
						),
					),
				)
			),
			new Device(
				array(
					'id'         => 'device:other',
					'attributes' => new DeviceAttributes( array( 'type' => 'TERMINAL', 'manufacturer' => 'Square', 'model' => 'Terminal', 'name' => 'Other' ) ),
					'components' => array(
						new Component(
							array(
								'type'               => 'APPLICATION',
								'applicationDetails' => new DeviceComponentDetailsApplicationDetails( array( 'applicationType' => 'TERMINAL_API', 'deviceCodeId' => 'code_other' ) ),
							)
						),
					),
				)
			),
		);
		$client              = (object) array( 'devices' => $spy );

		$result = ( new SquareDeviceAdapter( $client ) )->list_account_devices( 'LOC' );

		self::assertCount( 1, $result );
		self::assertSame( 'device:other', $result[0]['monitoring_id'] );
	}

	public function test_create_device_code_uses_typed_request(): void {
		$spy     = new SpyCodesClient();
		$client  = (object) array( 'devices' => (object) array( 'codes' => $spy ) );
		$result  = ( new SquareDeviceAdapter( $client ) )->create_device_code(
			array(
				'name'            => 'Front',
				'location_id'     => 'LOC',
				'idempotency_key' => 'idem',
			)
		);
		$device = $spy->created_request->getDeviceCode();

		self::assertSame( 'TERMINAL_API', $device->getProductType() );
		self::assertSame( 'LOC', $device->getLocationId() );
		self::assertSame( 'Front', $device->getName() );
		self::assertSame( 'ABCD', $result['code'] );
	}

	public function test_list_paired_devices_uses_device_codes_pager_and_returns_checkout_ids(): void {
		$spy               = new SpyCodesClient();
		$spy->listed_codes = array(
			new DeviceCode( array( 'name' => 'Front', 'deviceId' => 'device_front', 'productType' => 'TERMINAL_API', 'locationId' => 'LOC', 'status' => 'PAIRED' ) ),
			new DeviceCode( array( 'deviceId' => 'device_fallback', 'productType' => 'TERMINAL_API', 'locationId' => 'LOC', 'status' => 'PAIRED' ) ),
			new DeviceCode( array( 'name' => 'Unpaired', 'deviceId' => 'device_unpaired', 'productType' => 'TERMINAL_API', 'locationId' => 'LOC', 'status' => 'UNPAIRED' ) ),
			new DeviceCode( array( 'name' => 'Wrong product', 'deviceId' => 'device_other', 'productType' => 'SQUARE', 'locationId' => 'LOC', 'status' => 'PAIRED' ) ),
			new DeviceCode( array( 'name' => 'Wrong location', 'deviceId' => 'device_elsewhere', 'productType' => 'TERMINAL_API', 'locationId' => 'OTHER', 'status' => 'PAIRED' ) ),
			new DeviceCode( array( 'name' => 'No checkout ID', 'productType' => 'TERMINAL_API', 'locationId' => 'LOC', 'status' => 'PAIRED' ) ),
		);
		$client            = (object) array( 'devices' => (object) array( 'codes' => $spy ) );

		$result = ( new SquareDeviceAdapter( $client ) )->list_paired_devices( 'LOC' );

		self::assertSame( 'LOC', $spy->list_request->getLocationId() );
		self::assertSame( 'TERMINAL_API', $spy->list_request->getProductType() );
		self::assertSame( 'PAIRED', $spy->list_request->getStatus() );
		self::assertSame(
			array(
				array( 'id' => 'device_front', 'label' => 'Front' ),
				array( 'id' => 'device_fallback', 'label' => 'device_fallback' ),
			),
			$result
		);
	}

	public function test_validate_location_makes_one_authenticated_location_call(): void {
		$locations = new SpyLocationsClient();
		$client    = (object) array( 'locations' => $locations );

		( new SquareDeviceAdapter( $client ) )->validate_location( 'LOC' );

		self::assertSame( 'LOC', $locations->get_request->getLocationId() );
	}
}
