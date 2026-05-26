<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Includes;
use PHPUnit\Framework\TestCase; use WCPOS\WooCommercePOS\SquareTerminal\Services\WebhookSignatureVerifier;
final class WebhookSignatureVerifierTest extends TestCase { public function test_returns_false_for_invalid_signature(): void { self::assertFalse((new WebhookSignatureVerifier())->verify('{"ok":true}', 'bad', 'key', 'https://example.test/webhook')); }}
