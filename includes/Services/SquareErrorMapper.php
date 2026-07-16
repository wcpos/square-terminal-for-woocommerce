<?php
/**
 * Square error taxonomy mapper.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Services;

use Throwable;

/**
 * Converts provider errors into safe cashier messages and private log context.
 */
final class SquareErrorMapper {
	/**
	 * Map a Square error array, SDK exception, or transport exception.
	 *
	 * @param array<string,mixed>|Throwable $error Provider error.
	 * @return array{retriable:bool,http_status:int,cashier_message:string,log_context:array<string,mixed>}
	 */
	public function map( $error ): array {
		$normalized = $this->normalize( $error );
		$category   = $normalized['category'];
		$code       = $normalized['code'];

		if ( 'IDEMPOTENCY_KEY_REUSED' === $code ) {
			return $this->result( false, __( 'The previous payment request may still be active on the terminal. Check the terminal, then use Check Status or release the payment.', 'square-terminal-for-woocommerce' ), $normalized, 409 );
		}

		if ( 'AUTHENTICATION_ERROR' === $category ) {
			return $this->result( false, __( 'Square connection failed — check plugin settings.', 'square-terminal-for-woocommerce' ), $normalized );
		}

		if ( in_array( $category, array( 'RATE_LIMIT_ERROR', 'RATE_LIMITED' ), true ) || 'RATE_LIMITED' === $code ) {
			return $this->result( true, __( 'Square is temporarily busy. Please try again.', 'square-terminal-for-woocommerce' ), $normalized );
		}

		if ( 'API_ERROR' === $category ) {
			return $this->result( true, __( 'Square is temporarily unavailable. Please try again.', 'square-terminal-for-woocommerce' ), $normalized );
		}

		if ( 'INVALID_REQUEST_ERROR' === $category ) {
			if ( in_array( $code, array( 'NOT_FOUND', 'DEVICE_NOT_FOUND' ), true ) ) {
				return $this->result( false, __( 'This terminal is no longer paired. Choose another terminal or pair it again.', 'square-terminal-for-woocommerce' ), $normalized );
			}

			if ( in_array( $code, array( 'INVALID_LOCATION', 'LOCATION_MISMATCH' ), true ) ) {
				return $this->result( false, __( 'The configured Square location is invalid. Check plugin settings.', 'square-terminal-for-woocommerce' ), $normalized );
			}

			return $this->result( false, __( 'Square rejected the payment request. Check the terminal and plugin settings.', 'square-terminal-for-woocommerce' ), $normalized );
		}

		if ( 'PAYMENT_METHOD_ERROR' === $category ) {
			return $this->result( false, __( 'The payment was declined. Ask the buyer to use another payment method.', 'square-terminal-for-woocommerce' ), $normalized );
		}

		if ( $normalized['transport'] ) {
			return $this->result( true, __( 'Unable to reach Square. Please try again.', 'square-terminal-for-woocommerce' ), $normalized );
		}

		return $this->result( false, __( 'Square could not process the request. Please check the payment status and try again.', 'square-terminal-for-woocommerce' ), $normalized );
	}

	/**
	 * Normalize supported error inputs.
	 *
	 * @param array<string,mixed>|Throwable $error Provider error.
	 * @return array{category:string,code:string,detail:string,field:string,status_code:int,exception_class:string,transport:bool}
	 */
	private function normalize( $error ): array {
		$normalized = array(
			'category'        => '',
			'code'            => '',
			'detail'          => '',
			'field'           => '',
			'status_code'     => 0,
			'exception_class' => '',
			'transport'       => false,
		);

		if ( is_array( $error ) ) {
			$normalized['category'] = (string) ( $error['category'] ?? '' );
			$normalized['code']     = (string) ( $error['code'] ?? '' );
			$normalized['detail']   = (string) ( $error['detail'] ?? '' );
			$normalized['field']    = (string) ( $error['field'] ?? '' );

			return $normalized;
		}

		$normalized['detail']          = $error->getMessage();
		$normalized['exception_class'] = get_class( $error );
		$normalized['transport']       = true;

		if ( method_exists( $error, 'getStatusCode' ) ) {
			$normalized['status_code'] = (int) $error->getStatusCode();
		}

		if ( method_exists( $error, 'getErrors' ) ) {
			$errors = (array) $error->getErrors();
			$first  = $errors[0] ?? null;
			if ( is_object( $first ) && method_exists( $first, 'getCategory' ) ) {
				$normalized['category']  = (string) $first->getCategory();
				$normalized['code']      = method_exists( $first, 'getCode' ) ? (string) $first->getCode() : '';
				$normalized['detail']    = method_exists( $first, 'getDetail' ) ? (string) $first->getDetail() : $normalized['detail'];
				$normalized['field']     = method_exists( $first, 'getField' ) ? (string) $first->getField() : '';
				$normalized['transport'] = false;
			}
		}

		return $normalized;
	}

	/**
	 * Build a mapper result.
	 *
	 * @param bool                $retriable Retriable flag.
	 * @param string              $message   Safe cashier message.
	 * @param array<string,mixed> $context   Private provider context.
	 * @param int|null            $http_status HTTP status override.
	 * @return array{retriable:bool,http_status:int,cashier_message:string,log_context:array<string,mixed>}
	 */
	private function result( bool $retriable, string $message, array $context, ?int $http_status = null ): array {
		unset( $context['transport'] );

		return array(
			'retriable'       => $retriable,
			'http_status'     => $http_status ?? ( $retriable ? 502 : 400 ),
			'cashier_message' => $message,
			'log_context'     => $context,
		);
	}
}
