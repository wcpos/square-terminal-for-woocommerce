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

namespace {
	if ( ! function_exists( 'wcpos_pro_payment_id_for_action' ) ) {
		function wcpos_pro_payment_id_for_action( string $provider, string $ref ): ?string {
			$GLOBALS['sqtwc_adoption_lookups'][] = array( $provider, $ref );
			return $GLOBALS['sqtwc_adopted'][ $ref ] ?? null;
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
	if ( ! class_exists( 'SQTWC_Test_Refund' ) ) {
		class SQTWC_Test_Refund extends SQTWC_Test_Order {
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
