<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Services;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Terminal\Checkouts\Requests\{CreateTerminalCheckoutRequest, GetCheckoutsRequest, CancelCheckoutsRequest};
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Types\{Money, DeviceCheckoutOptions, TerminalCheckout};
final class SquareTerminalAdapter { public function __construct(private object $client) {}
 public function create_checkout(array $data): array { $checkout = new TerminalCheckout(array('amountMoney'=>new Money(array('amount'=>(int)$data['amount'], 'currency'=>(string)$data['currency'])), 'deviceOptions'=>new DeviceCheckoutOptions(array('deviceId'=>(string)$data['device_id'])), 'referenceId'=>(string)$data['reference_id'], 'note'=>$data['note'] ?? null)); $request = new CreateTerminalCheckoutRequest(array('idempotencyKey'=>(string)$data['idempotency_key'], 'checkout'=>$checkout)); return $this->normalize($this->client->terminal->checkouts->create($request)->getCheckout()); }
 public function get_checkout(string $checkout_id): array { return $this->normalize($this->client->terminal->checkouts->get(new GetCheckoutsRequest(array('checkoutId'=>$checkout_id)))->getCheckout()); }
 public function cancel_checkout(string $checkout_id): array { return $this->normalize($this->client->terminal->checkouts->cancel(new CancelCheckoutsRequest(array('checkoutId'=>$checkout_id)))->getCheckout()); }
 private function normalize($checkout): array { return array('id'=>$checkout?->getId(), 'status'=>$checkout?->getStatus(), 'reference_id'=>$checkout?->getReferenceId(), 'payment_ids'=>$checkout?->getPaymentIds() ?? array()); }}
