<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Services;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Utils\WebhooksHelper;
final class WebhookSignatureVerifier { public function verify(string $body, string $signature, string $key, string $notification_url): bool { try { return (bool) WebhooksHelper::verifySignature($body, $signature, $key, $notification_url); } catch (\Throwable) { return false; } } }
