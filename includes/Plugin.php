<?php
/**
 * Main plugin bootstrap hooks.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareClientFactory;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareTerminalAdapter;

/**
 * Main plugin class.
 */
final class Plugin {
	/**
	 * Register WordPress and WooCommerce hooks.
	 */
	public function init(): void {
		add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );
		add_action( 'wp_ajax_sqtwc_create_terminal_checkout', array( $this, 'ajax_create_terminal_checkout' ) );
		add_action( 'wp_ajax_nopriv_sqtwc_create_terminal_checkout', array( $this, 'ajax_create_terminal_checkout' ) );
		add_action( 'wp_ajax_sqtwc_cancel_terminal_checkout', array( $this, 'ajax_cancel_terminal_checkout' ) );
		add_action( 'wp_ajax_nopriv_sqtwc_cancel_terminal_checkout', array( $this, 'ajax_cancel_terminal_checkout' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'sqtwc/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Create a Terminal Checkout from a WordPress AJAX request.
	 */
	public function ajax_create_terminal_checkout(): void {
		$this->send_ajax_response( $this->create_ajax_handler()->create_terminal_checkout( $this->get_ajax_request_data() ) );
	}

	/**
	 * Cancel a Terminal Checkout from a WordPress AJAX request.
	 */
	public function ajax_cancel_terminal_checkout(): void {
		$this->send_ajax_response( $this->create_ajax_handler()->cancel_terminal_checkout( $this->get_ajax_request_data() ) );
	}

	/**
	 * Handle the Square webhook REST request.
	 *
	 * @param object $request REST request.
	 * @return mixed
	 */
	public function handle_webhook( $request ) {
		$body    = method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
		$headers = method_exists( $request, 'get_headers' ) ? (array) $request->get_headers() : array();

		if ( method_exists( $request, 'get_header' ) ) {
			$headers['x-square-hmacsha256-signature'] = (string) $request->get_header( 'x-square-hmacsha256-signature' );
		}

		return $this->rest_response( ( new WebhookHandler() )->handle( $body, $headers ) );
	}

	/**
	 * Create the AJAX handler with a configured Square adapter.
	 */
	private function create_ajax_handler(): AjaxHandler {
		return new AjaxHandler( new SquareTerminalAdapter( ( new SquareClientFactory() )->create() ) );
	}

	/**
	 * Return unslashed AJAX request data.
	 *
	 * @return array<string,mixed>
	 */
	private function get_ajax_request_data(): array {
		return (array) wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by AjaxHandler for authenticated users.
	}

	/**
	 * Send an AJAX response with the handler status code.
	 *
	 * @param array<string,mixed> $response Response data.
	 */
	private function send_ajax_response( array $response ): void {
		wp_send_json( $response, (int) ( $response['status'] ?? 200 ) );
	}

	/**
	 * Build a REST response with the handler status code.
	 *
	 * @param array<string,mixed> $response Response data.
	 * @return mixed
	 */
	private function rest_response( array $response ) {
		if ( class_exists( '\WP_REST_Response' ) ) {
			return new \WP_REST_Response( $response, (int) ( $response['status'] ?? 200 ) );
		}

		return $response;
	}
}
