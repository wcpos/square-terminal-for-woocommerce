<?php
/**
 * Registration with the WCPOS Pro payments base.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Server;

use WCPOS\WooCommercePOS\SquareTerminal\Gateway;

/**
 * Registers the server adapter with Pro when a compatible Pro is active.
 *
 * Pro defines its helpers from its own `plugins_loaded` hook at priority 20, so this runs at 30.
 */
final class Registration {
	/** First Pro release the adapter runs on: the shared payments base and its conformance suite. */
	public const REQUIRED_PRO_VERSION = '2.0.0';

	/**
	 * Whether a Pro the adapter can register with is active.
	 */
	public static function pro_supported(): bool {
		return function_exists( 'wcpos_pro_register_server_provider' )
			&& function_exists( 'wcpos_pro_requires' )
			&& wcpos_pro_requires( self::REQUIRED_PRO_VERSION );
	}

	/**
	 * Register the adapter and its refund re-ask; false when Pro is absent or too old.
	 */
	public static function register(): bool {
		if ( ! self::pro_supported() ) {
			return false;
		}

		wcpos_pro_register_server_provider( Gateway::ID, Square_Server_Provider::class );
		Refund_Reask::register();

		return true;
	}
}
