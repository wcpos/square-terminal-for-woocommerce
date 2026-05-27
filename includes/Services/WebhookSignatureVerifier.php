<?php
/**
 * Square webhook signature verification.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\Utils\WebhooksHelper;

/**
 * Wraps the scoped Square SDK webhook helper.
 */
final class WebhookSignatureVerifier {
	/**
	 * Verify a Square webhook signature.
	 *
	 * @param string $body             Raw request body.
	 * @param string $signature        Square signature header value.
	 * @param string $key              Webhook signature key.
	 * @param string $notification_url Exact configured notification URL.
	 */
	public function verify( string $body, string $signature, string $key, string $notification_url ): bool {
		try {
			return (bool) WebhooksHelper::verifySignature( $body, $signature, $key, $notification_url );
		} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- invalid signatures should return false.
			return false;
		}
	}
}
