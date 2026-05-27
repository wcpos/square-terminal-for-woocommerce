<?php
/**
 * Short-lived signed payment request tokens.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

/**
 * Payment request token service.
 */
final class PaymentRequestToken {
	/**
	 * Create an order-bound token.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @param int $expires  Unix expiration timestamp.
	 */
	public static function create( int $order_id, int $expires ): string {
		$payload = base64_encode(
			wp_json_encode(
				array(
					'order_id' => $order_id,
					'expires'  => $expires,
				)
			)
		);
		$signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );

		return $payload . '.' . $signature;
	}

	/**
	 * Verify an order-bound token.
	 *
	 * @param string $token    Signed token.
	 * @param int    $order_id Expected order ID.
	 */
	public static function verify( string $token, int $order_id ): bool {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}

		list( $payload, $signature ) = $parts;
		$expected_signature         = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return false;
		}

		$decoded = base64_decode( $payload, true );
		if ( false === $decoded ) {
			return false;
		}

		$data = json_decode( $decoded, true );

		return is_array( $data )
			&& (int) ( $data['order_id'] ?? 0 ) === $order_id
			&& (int) ( $data['expires'] ?? 0 ) >= time();
	}
}
