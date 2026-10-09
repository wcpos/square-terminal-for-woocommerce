<?php
/**
 * The slice of WCPOS Pro's payments base the server adapter builds on, for tests without WordPress.
 * Shapes mirror woocommerce-pos-pro `next`: Abstract_Provider_Adapter, Money_Units, the adoption lookup.
 */

namespace WCPOS\WooCommercePOSPro\Payments\Server {
	if ( ! class_exists( Abstract_Provider_Adapter::class ) ) {
		abstract class Abstract_Provider_Adapter {
			public function diagnostics( \WC_Payment_Gateway $gateway ): array { return array(); }
			public function describe( \WC_Payment_Gateway $gateway ): array { return array(); }
			public function answer( string $ref, string $prompt_id, string $button_id ) { return $this->unsupported(); }
			public function capture( string $ref ) { return $this->unsupported(); }
			public function refund( array $row, int $refund_id, string $amount ) { return $this->unsupported(); }
			public function verify_webhook( \WP_REST_Request $request ) { return $this->unsupported(); }
			protected function indeterminate( string $code, string $message ): \WP_Error {
				return new \WP_Error( $code, $message, array( 'indeterminate' => true, 'status' => 502 ) );
			}
			private function unsupported(): \WP_Error {
				return new \WP_Error( 'wcpos_capture_mode_unsupported', 'Unsupported.', array( 'status' => 501 ) );
			}
		}
	}
	if ( ! class_exists( Money_Units::class ) ) {
		final class Money_Units {
			private const EXPONENTS = array( 'JPY' => 0, 'KRW' => 0, 'KWD' => 3 );
			public static function minor( string $amount, string $currency ): int {
				$decimals = self::EXPONENTS[ strtoupper( $currency ) ] ?? 2;
				$parts    = explode( '.', ltrim( $amount, '+-' ), 2 );
				$fraction = str_pad( $parts[1] ?? '', $decimals + 1, '0' );
				$minor    = (int) $parts[0] * ( 10 ** $decimals ) + (int) substr( $fraction, 0, $decimals );
				$minor   += (int) $fraction[ $decimals ] >= 5 ? 1 : 0;
				return '-' === substr( $amount, 0, 1 ) ? -$minor : $minor;
			}
			public static function major( int $minor, string $currency ): string {
				$decimals = self::EXPONENTS[ strtoupper( $currency ) ] ?? 2;
				$digits   = str_pad( ltrim( (string) $minor, '-' ), $decimals + 1, '0', STR_PAD_LEFT );
				$major    = $decimals ? substr( $digits, 0, -$decimals ) . '.' . substr( $digits, -$decimals ) : $digits;
				return ( $minor < 0 ? '-' : '' ) . $major;
			}
		}
	}
}

namespace WCPOS\WooCommercePOS\Payments\Contract {
	if ( ! class_exists( Order_Lock::class ) ) {
		/** Free's per-order lock: `$GLOBALS['sqtwc_free_lock_held']` makes with_lock() refuse. */
		final class Order_Lock {
			public static $held = false;
			public static function instance(): self { return new self(); }
			public function with_lock( int $order_id, callable $callback ) {
				if ( ! empty( $GLOBALS['sqtwc_free_lock_held'] ) ) { return new \WP_Error( 'wcpos_payment_locked', 'locked', array( 'status' => 409 ) ); }
				self::$held = true;
				try { return $callback(); } finally { self::$held = false; }
			}
		}
	}
	if ( ! class_exists( Ledger::class ) ) {
		/** Free's ledger: rows come from `$GLOBALS['sqtwc_ledger_rows'][ order id ]`. */
		final class Ledger {
			public const LIVE_STATUSES     = array( 'pending', 'authorized', 'captured' );
			public const COUNTING_STATUSES = array( 'authorized', 'captured' );
			public static function instance(): self { return new self(); }
			public function read( $order ): array { return $GLOBALS['sqtwc_ledger_rows'][ $order->get_id() ] ?? array(); }
			public function find( $order, string $id ): ?array {
				foreach ( $this->read( $order ) as $row ) { if ( ( $row['id'] ?? '' ) === $id ) { return $row; } }
				return null;
			}
		}
	}
}

namespace {
	if ( ! function_exists( 'wcpos_pro_payment_id_for_action' ) ) {
		function wcpos_pro_payment_id_for_action( string $provider, string $ref ): ?string {
			$GLOBALS['sqtwc_adoption_lookups'][] = array( $provider, $ref );
			return $GLOBALS['sqtwc_adopted'][ $ref ] ?? null;
		}
	}
	if ( ! function_exists( 'wcpos_pro_order_pay_panel' ) ) {
		function wcpos_pro_order_pay_panel( $gateway, $order ): void { $GLOBALS['sqtwc_pro_panels'][] = $order->get_id(); echo '<div id="wcpos-pro-order-pay-panel"></div>'; }
		function wcpos_pro_order_pay_process( $order ): array { $GLOBALS['sqtwc_pro_process'][] = $order->get_id(); return $GLOBALS['sqtwc_pro_process_result'] ?? array( 'result' => 'success', 'redirect' => '/pro' ); }
		function wcpos_pro_order_pay_refund( $order, $amount, string $reason = '' ) { $GLOBALS['sqtwc_pro_refunds'][] = array( $order->get_id(), $amount, $reason ); return $GLOBALS['sqtwc_pro_refund_result'] ?? true; }
		function wcpos_pro_adopt_legacy_attempt( $order, string $gateway_id, string $ref, string $amount, string $currency ) {
			$GLOBALS['sqtwc_pro_adoptions'][] = array( $order->get_id(), $gateway_id, $ref, $amount, $currency );
			if ( isset( $GLOBALS['sqtwc_pro_adopt_result'] ) ) { return $GLOBALS['sqtwc_pro_adopt_result']; }
			$id = 'row-' . count( $GLOBALS['sqtwc_pro_adoptions'] );
			$GLOBALS['sqtwc_adopted'][ $ref ] = $id;
			return array( 'id' => $id, 'status' => 'pending' );
		}
	}
	if ( ! function_exists( 'wp_schedule_single_event' ) ) {
		function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
			$GLOBALS['sqtwc_single_events'][] = array( 'at' => $timestamp, 'hook' => $hook, 'args' => $args );
			return true;
		}
	}
	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {
			private $body = '';
			private $headers = array();
			public function set_body( $body ) { $this->body = (string) $body; }
			public function get_body() { return $this->body; }
			public function set_header( $key, $value ) { $this->headers[ strtolower( $key ) ] = $value; }
			public function get_header( $key ) { return $this->headers[ strtolower( $key ) ] ?? null; }
		}
	}
	if ( ! class_exists( 'WC_Order_Refund' ) ) {
		class WC_Order_Refund extends SQTWC_Test_Order {}
	}
	if ( ! class_exists( 'SQTWC_Test_Refund' ) ) {
		class SQTWC_Test_Refund extends WC_Order_Refund {
			public $parent_id;
			public $reason = '';
			public $saves = 0;
			public function __construct( $id, $parent_id ) { parent::__construct( $id ); $this->parent_id = $parent_id; }
			public function get_parent_id() { return $this->parent_id; }
			public function get_reason() { return $this->reason; }
			public function save() { $this->saves++; }
		}
	}
}
