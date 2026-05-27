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
}
