<?php
/**
 * Currency conversion helpers.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Utils;

/**
 * Converts decimal WooCommerce totals to Square minor units.
 */
final class CurrencyConverter {
	/**
	 * ISO currency codes without decimal minor units.
	 */
	private const ZERO_DECIMAL = array(
		'BIF',
		'CLP',
		'DJF',
		'GNF',
		'JPY',
		'KMF',
		'KRW',
		'MGA',
		'PYG',
		'RWF',
		'UGX',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	);

	/**
	 * Convert a decimal amount to minor units.
	 *
	 * @param mixed  $amount   Decimal amount.
	 * @param string $currency ISO currency code.
	 */
	public static function to_minor_units( $amount, string $currency ): int {
		$factor = in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? 1 : 100;

		return (int) round( ( (float) $amount ) * $factor );
	}

	/**
	 * Format a minor-unit amount as a human-readable decimal with currency code.
	 *
	 * @param int    $amount   Amount in minor units.
	 * @param string $currency ISO currency code.
	 */
	public static function format_minor_units( int $amount, string $currency ): string {
		$currency = strtoupper( $currency );
		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return sprintf( '%d %s', $amount, $currency );
		}

		return sprintf( '%s %s', number_format( $amount / 100, 2, '.', '' ), $currency );
	}
}
