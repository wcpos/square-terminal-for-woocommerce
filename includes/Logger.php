<?php
/**
 * Sanitized WooCommerce logging helpers.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

/**
 * Logger helper.
 */
final class Logger {
	/**
	 * Context keys that must never be logged in clear text.
	 */
	private const SECRET_KEYS = array(
		'token',
		'access_token',
		'signature',
		'authorization',
		'webhook_signature_key',
		'signature_key',
		'device_code',
		'code',
		'hmac',
	);

	/**
	 * Sanitize log context recursively.
	 *
	 * @param array<string,mixed> $context Raw log context.
	 * @return array<string,mixed>
	 */
	public static function sanitize_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$is_secret = self::is_secret_key( (string) $key );

			if ( $is_secret ) {
				$clean[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_context( $value );
			} elseif ( is_string( $value ) && strlen( $value ) > 1000 ) {
				$clean[ $key ] = substr( $value, 0, 1000 );
			} else {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Write an info-level log entry.
	 *
	 * @param string              $message Log message.
	 * @param array<string,mixed> $context Additional context.
	 * @param object|null         $order   Optional WooCommerce order.
	 */
	public static function info( string $message, array $context = array(), $order = null ): void {
		wc_get_logger()->info(
			$message,
			array_merge(
				array( 'source' => 'square-terminal-for-woocommerce' ),
				self::sanitize_context( $context )
			)
		);

		if ( $order && method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( 'Square Terminal: ' . $message );
		}
	}

	/**
	 * Write an error-level log entry.
	 *
	 * @param string              $message Log message.
	 * @param array<string,mixed> $context Additional context.
	 * @param object|null         $order   Optional WooCommerce order.
	 */
	public static function error( string $message, array $context = array(), $order = null ): void {
		wc_get_logger()->error(
			$message,
			array_merge(
				array( 'source' => 'square-terminal-for-woocommerce' ),
				self::sanitize_context( $context )
			)
		);

		if ( $order && method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( 'Square Terminal error: ' . $message );
		}
	}

	/**
	 * Determine whether a context key contains sensitive data.
	 */
	private static function is_secret_key( string $key ): bool {
		$key = strtolower( $key );
		foreach ( self::SECRET_KEYS as $needle ) {
			if ( str_contains( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
