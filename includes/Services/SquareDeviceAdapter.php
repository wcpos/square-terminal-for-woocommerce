<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Services;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Devices\Codes\Requests\CreateDeviceCodeRequest;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\DeviceCode;
final class SquareDeviceAdapter { public function __construct(private object $client) {} public function create_device_code(array $data): array { $device_code = new DeviceCode(array('productType'=>'TERMINAL_API','locationId'=>(string)$data['location_id'],'name'=>(string)$data['name'])); $request = new CreateDeviceCodeRequest(array('idempotencyKey'=>(string)$data['idempotency_key'],'deviceCode'=>$device_code)); return $this->normalize($this->client->devices->codes->create($request)->getDeviceCode()); } private function normalize($code): array { return array('id'=>$code?->getId(),'name'=>$code?->getName(),'code'=>$code?->getCode(),'device_id'=>$code?->getDeviceId(),'status'=>$code?->getStatus(),'location_id'=>$code?->getLocationId()); }}
