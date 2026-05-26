<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Utils;
final class CurrencyConverter { private const ZERO_DECIMAL = array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'); public static function to_minor_units($amount, string $currency): int { $factor = in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 1 : 100; return (int) round(((float)$amount) * $factor); } }
