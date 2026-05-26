<?php
namespace WCPOS\WooCommercePOS\SquareTerminal;
final class OrderAccess { public static function can_mutate_order($order, array $request): bool { if (current_user_can('manage_woocommerce')) return true; if (isset($request['order_key']) && hash_equals((string)$order->get_order_key(), (string)$request['order_key'])) return true; if (isset($request['payment_request_token']) && PaymentRequestToken::verify((string)$request['payment_request_token'], (int)$order->get_id())) return true; return false; }}
