<?php
/**
 * Main plugin bootstrap hooks.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

use Throwable;
use WCPOS\WooCommercePOS\SquareTerminal\Services\OrderLock;
use WCPOS\WooCommercePOS\SquareTerminal\Services\PaymentSweeper;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareClientFactory;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareDeviceAdapter;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareErrorMapper;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareOAuth;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareTerminalAdapter;

/**
 * Main plugin class.
 */
final class Plugin {
	/** @var AjaxHandler|object|null */
	private $ajax_handler;

	/** @var WebhookHandler|object|null */
	private $webhook_handler;

	/** @var PaymentSweeper|object|null */
	private $payment_sweeper;

	/** @var SquareDeviceAdapter|object|null */
	private $device_adapter;

	/** @var OrderLock */
	private OrderLock $order_lock;

	/**
	 * Constructor.
	 *
	 * @param AjaxHandler|object|null   $ajax_handler    Optional injected AJAX handler.
	 * @param WebhookHandler|object|null  $webhook_handler  Optional injected webhook handler.
	 * @param PaymentSweeper|object|null  $payment_sweeper  Optional injected payment sweeper.
	 * @param OrderLock|null              $order_lock       Optional shared order lock.
	 * @param SquareDeviceAdapter|object|null $device_adapter Optional injected Device Code adapter.
	 */
	public function __construct( $ajax_handler = null, $webhook_handler = null, $payment_sweeper = null, ?OrderLock $order_lock = null, $device_adapter = null ) {
		$this->ajax_handler    = $ajax_handler;
		$this->webhook_handler = $webhook_handler;
		$this->payment_sweeper = $payment_sweeper;
		$this->order_lock      = $order_lock ?? new OrderLock();
		$this->device_adapter  = $device_adapter;
	}

	/**
	 * Register WordPress and WooCommerce hooks.
	 */
	public function init(): void {
		add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );
		add_action( 'admin_enqueue_scripts', array( Gateway::class, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_sqtwc_create_terminal_checkout', array( $this, 'ajax_create_terminal_checkout' ) );
		add_action( 'wp_ajax_nopriv_sqtwc_create_terminal_checkout', array( $this, 'ajax_create_terminal_checkout' ) );
		add_action( 'wp_ajax_sqtwc_get_terminal_status', array( $this, 'ajax_get_terminal_status' ) );
		add_action( 'wp_ajax_nopriv_sqtwc_get_terminal_status', array( $this, 'ajax_get_terminal_status' ) );
		add_action( 'wp_ajax_sqtwc_cancel_terminal_checkout', array( $this, 'ajax_cancel_terminal_checkout' ) );
		add_action( 'wp_ajax_nopriv_sqtwc_cancel_terminal_checkout', array( $this, 'ajax_cancel_terminal_checkout' ) );
		add_action( 'wp_ajax_sqtwc_detach_terminal_checkout', array( $this, 'ajax_detach_terminal_checkout' ) );
		add_action( 'wp_ajax_nopriv_sqtwc_detach_terminal_checkout', array( $this, 'ajax_detach_terminal_checkout' ) );
		add_action( 'wp_ajax_sqtwc_create_device_code', array( $this, 'ajax_create_device_code' ) );
		add_action( 'wp_ajax_sqtwc_validate_settings', array( $this, 'ajax_validate_settings' ) );
		add_action( 'wp_ajax_sqtwc_list_devices', array( $this, 'ajax_list_devices' ) );
		add_action( 'admin_post_sqtwc_square_connect', array( $this, 'handle_square_connect' ) );
		add_action( 'admin_post_sqtwc_square_callback', array( $this, 'handle_square_callback' ) );
		add_action( 'admin_post_sqtwc_square_disconnect', array( $this, 'handle_square_disconnect' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'cancel_open_attempt_on_order_status_change' ), 10, 4 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		$this->create_payment_sweeper()->register();
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
	 * Return Terminal checkout status from a WordPress AJAX request.
	 */
	public function ajax_get_terminal_status(): void {
		$this->send_ajax_response( $this->create_ajax_handler()->get_terminal_status( $this->get_ajax_request_data() ) );
	}

	/**
	 * Cancel a Terminal Checkout from a WordPress AJAX request.
	 */
	public function ajax_cancel_terminal_checkout(): void {
		$this->send_ajax_response( $this->create_ajax_handler()->cancel_terminal_checkout( $this->get_ajax_request_data() ) );
	}

	/**
	 * Detach a Terminal Checkout from a WordPress AJAX request.
	 */
	public function ajax_detach_terminal_checkout(): void {
		$this->send_ajax_response( $this->create_ajax_handler()->detach_terminal_checkout( $this->get_ajax_request_data() ) );
	}

	/**
	 * Create a Terminal pairing code from an authenticated admin request.
	 */
	public function ajax_create_device_code(): void {
		$request = $this->get_ajax_request_data();
		$error   = $this->authorize_admin_request( $request );
		if ( null !== $error ) {
			$this->send_ajax_response( $error );
			return;
		}

		$credentials = $this->resolve_admin_credentials( $request );
		if ( '' === $credentials['location_id'] ) {
			$this->send_ajax_response( $this->admin_error_response( 400, __( 'Square location is required.', 'square-terminal-for-woocommerce' ) ) );
			return;
		}

		$name = sanitize_text_field( $request['name'] ?? '' );
		if ( '' === $name ) {
			/* translators: %s: Store name. */
			$name = sprintf( __( '%s Terminal', 'square-terminal-for-woocommerce' ), get_bloginfo( 'name' ) );
		}

		try {
			$result = $this->create_device_adapter( $credentials['access_token'], $credentials['environment'] )->create_device_code(
				array(
					'location_id'     => $credentials['location_id'],
					'name'            => $name,
					'idempotency_key' => wp_generate_uuid4(),
				)
			);
			if ( empty( $result['code'] ) ) {
				throw new \UnexpectedValueException( 'Square returned an empty device code.' );
			}

			Gateway::delete_device_cache( $credentials['environment'], $credentials['location_id'] );
			$this->send_ajax_response(
				array(
					'status'  => 200,
					'success' => true,
					'code'    => (string) $result['code'],
				)
			);
		} catch ( Throwable $exception ) {
			$this->send_ajax_response( $this->mapped_admin_error_response( $exception ) );
		}
	}

	/**
	 * Start a Square OAuth authorization.
	 */
	public function handle_square_connect(): void {
		$this->guard_admin_redirect( 'sqtwc_square_connect' );

		try {
			$url = ( new SquareOAuth() )->begin( self::square_callback_url(), Settings::get_environment() );
		} catch ( Throwable $exception ) {
			$this->redirect_to_settings( 'sqtwc_connect_failed' );
			return;
		}

		wp_redirect( $url );
		exit;
	}

	/**
	 * Receive the authorization response forwarded back to this site.
	 */
	public function handle_square_callback(): void {
		$this->guard_admin_redirect( 'sqtwc_square_callback' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified above; these are Square's response parameters.
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $code ) {
			$this->redirect_to_settings( 'sqtwc_connect_declined' );
			return;
		}

		try {
			( new SquareOAuth() )->complete( $code, $state );
		} catch ( Throwable $exception ) {
			Logger::error( 'Square OAuth exchange failed', array( 'detail' => $exception->getMessage() ) );
			$this->redirect_to_settings( 'sqtwc_connect_failed' );
			return;
		}

		$this->redirect_to_settings( 'sqtwc_connected' );
	}

	/**
	 * Forget the stored Square connection.
	 */
	public function handle_square_disconnect(): void {
		$this->guard_admin_redirect( 'sqtwc_square_disconnect' );

		( new SquareOAuth() )->disconnect();
		$this->redirect_to_settings( 'sqtwc_disconnected' );
	}

	/**
	 * Return the admin URL Square's response is forwarded back to.
	 */
	public static function square_callback_url(): string {
		return add_query_arg(
			array(
				'action'   => 'sqtwc_square_callback',
				'_wpnonce' => wp_create_nonce( 'sqtwc_square_callback' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Refuse an admin redirect request that is not authorized.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard_admin_redirect( string $action ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'square-terminal-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Return to the gateway settings screen with a result notice.
	 *
	 * @param string $notice Notice key.
	 */
	private function redirect_to_settings( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'wc-settings',
					'tab'           => 'checkout',
					'section'       => 'sqtwc',
					'sqtwc_notice'  => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * List Terminals for the settings screen.
	 *
	 * Returns both the Device Codes paired through this plugin (selectable at
	 * checkout) and the Terminals Square reports on the account however they
	 * were paired (informational). Reporting both is what makes an empty
	 * selectable list explicable instead of looking like a broken plugin.
	 */
	public function ajax_list_devices(): void {
		$request = $this->get_ajax_request_data();
		$error   = $this->authorize_admin_request( $request );
		if ( null !== $error ) {
			$this->send_ajax_response( $error );
			return;
		}

		$credentials = $this->resolve_admin_credentials( $request );
		if ( '' === $credentials['location_id'] ) {
			$this->send_ajax_response( $this->admin_error_response( 400, __( 'Square location is required.', 'square-terminal-for-woocommerce' ) ) );
			return;
		}

		try {
			$adapter = $this->create_device_adapter( $credentials['access_token'], $credentials['environment'] );
			$paired  = $adapter->list_paired_devices( $credentials['location_id'] );
		} catch ( Throwable $exception ) {
			$this->send_ajax_response( $this->mapped_admin_error_response( $exception ) );
			return;
		}

		// The account listing is informational. Losing it must never discard the
		// paired list, which is the answer the administrator actually needs.
		$account       = array();
		$account_error = '';
		try {
			$account = $adapter->list_account_devices( $credentials['location_id'] );
		} catch ( Throwable $exception ) {
			$mapped        = ( new SquareErrorMapper() )->map( $exception );
			$account_error = (string) $mapped['cashier_message'];
			Logger::warning( 'Square account device lookup failed', $mapped['log_context'] );
		}

		Logger::info(
			'Square reader lookup completed',
			array(
				'environment'   => $credentials['environment'],
				'location_id'   => $credentials['location_id'],
				'paired_count'  => count( $paired ),
				'account_count' => count( $account ),
				'account_error' => '' !== $account_error,
			)
		);

		Gateway::delete_device_cache( $credentials['environment'], $credentials['location_id'] );

		$this->send_ajax_response(
			array(
				'status'        => 200,
				'success'       => true,
				'paired'        => $paired,
				'account'       => $account,
				'account_error' => $account_error,
			)
		);
	}

	/**
	 * Verify configured Square credentials and location.
	 */
	public function ajax_validate_settings(): void {
		$request = $this->get_ajax_request_data();
		$error   = $this->authorize_admin_request( $request );
		if ( null !== $error ) {
			$this->send_ajax_response( $error );
			return;
		}

		$credentials = $this->resolve_admin_credentials( $request );
		if ( '' === $credentials['location_id'] ) {
			$this->send_ajax_response( $this->admin_error_response( 400, __( 'Square location is required.', 'square-terminal-for-woocommerce' ) ) );
			return;
		}

		try {
			$this->create_device_adapter( $credentials['access_token'], $credentials['environment'] )->validate_location( $credentials['location_id'] );
			$this->send_ajax_response(
				array(
					'status'  => 200,
					'success' => true,
				)
			);
		} catch ( Throwable $exception ) {
			$this->send_ajax_response( $this->mapped_admin_error_response( $exception ) );
		}
	}

	/**
	 * Best-effort cancel an open checkout when another path finalizes the order.
	 *
	 * @param int         $order_id Order ID.
	 * @param string      $from     Previous order status.
	 * @param string      $to       New order status.
	 * @param object|null $order    WooCommerce order.
	 */
	public function cancel_open_attempt_on_order_status_change( $order_id, $from, $to, $order = null ): void {
		unset( $from, $order );
		if ( ! in_array( $to, array( 'processing', 'completed', 'cancelled', 'failed' ), true ) ) {
			return;
		}

		try {
			$this->order_lock->with_lock(
				absint( $order_id ),
				function () use ( $order_id ): void {
					$order = wc_get_order( $order_id );
					if ( ! $order || '' === (string) $order->get_meta( '_sqtwc_current_attempt_id', true ) ) {
						return;
					}

					$checkout_id = (string) $order->get_meta( '_sqtwc_checkout_id', true );
					$device_id   = (string) $order->get_meta( '_sqtwc_device_id', true );
					if ( '' === $checkout_id || '' === $device_id ) {
						return;
					}

					$this->create_ajax_handler()->cancel_terminal_checkout_for_order(
						$order,
						$checkout_id,
						$device_id,
						array(
							'timeout'    => 8.0,
							'maxRetries' => 0,
						)
					);
				}
			);
		} catch ( Throwable $exception ) {
			Logger::error(
				'Order status transition Terminal cancel failed',
				array(
					'order_id'        => $order_id,
					'exception_class' => get_class( $exception ),
					'detail'          => $exception->getMessage(),
				)
			);
		}
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

		return $this->rest_response( $this->create_webhook_handler()->handle( $body, $headers ) );
	}

	/**
	 * Create the AJAX handler with a configured Square adapter.
	 */
	private function create_ajax_handler() {
		if ( null === $this->ajax_handler ) {
			$this->ajax_handler = new AjaxHandler( new SquareTerminalAdapter( ( new SquareClientFactory() )->create() ), null, null, $this->order_lock );
		}

		return $this->ajax_handler;
	}

	/**
	 * Create or return the configured Device Code adapter.
	 *
	 * @return SquareDeviceAdapter|object
	 */
	private function create_device_adapter( ?string $access_token = null, ?string $environment = null ) {
		if ( null !== $this->device_adapter ) {
			return $this->device_adapter;
		}

		// Deliberately not memoized: callers pass per-request credentials, and a
		// cached adapter would answer a later call with the first call's client.
		return new SquareDeviceAdapter( ( new SquareClientFactory() )->create( $access_token, $environment ) );
	}

	/**
	 * Resolve Square credentials for an admin request.
	 *
	 * The settings form posts its current values so the pairing controls act on
	 * what the administrator can see, falling back to the saved settings when a
	 * value is absent or blank.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array{environment:string,access_token:string,location_id:string}
	 */
	private function resolve_admin_credentials( array $request ): array {
		$posted_environment = sanitize_text_field( (string) ( $request['environment'] ?? '' ) );
		$environment        = in_array( $posted_environment, array( 'sandbox', 'production' ), true )
			? $posted_environment
			: Settings::get_environment();

		$access_token = sanitize_text_field( (string) ( $request['access_token'] ?? '' ) );
		$location_id  = sanitize_text_field( (string) ( $request['location_id'] ?? '' ) );

		return array(
			'environment'  => $environment,
			'access_token' => '' !== $access_token ? $access_token : (string) Settings::get( $environment . '_access_token', '' ),
			'location_id'  => '' !== $location_id ? $location_id : Settings::get_location_id(),
		);
	}

	/**
	 * Create or return the configured webhook handler.
	 *
	 * @return WebhookHandler|object
	 */
	private function create_webhook_handler() {
		if ( null === $this->webhook_handler ) {
			$this->webhook_handler = new WebhookHandler( null, null, $this->order_lock );
		}

		return $this->webhook_handler;
	}

	/**
	 * Create or return the configured payment sweeper.
	 *
	 * @return PaymentSweeper|object
	 */
	private function create_payment_sweeper() {
		if ( null === $this->payment_sweeper ) {
			$this->payment_sweeper = new PaymentSweeper( null, null, $this->order_lock );
		}

		return $this->payment_sweeper;
	}

	/**
	 * Return unslashed AJAX request data.
	 *
	 * @return array<string,mixed>
	 */
	private function get_ajax_request_data(): array {
		return (array) wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by the receiving handler.
	}

	/**
	 * Authorize an admin-only AJAX request.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>|null
	 */
	private function authorize_admin_request( array $request ): ?array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $this->admin_error_response( 403, __( 'You are not allowed to manage WooCommerce settings.', 'square-terminal-for-woocommerce' ) );
		}

		if ( ! wp_verify_nonce( $request['_wpnonce'] ?? '', 'sqtwc_admin' ) ) {
			return $this->admin_error_response( 403, __( 'Invalid nonce.', 'square-terminal-for-woocommerce' ) );
		}

		return null;
	}

	/**
	 * Map a Square exception into a safe admin response.
	 *
	 * @return array<string,mixed>
	 */
	private function mapped_admin_error_response( Throwable $exception ): array {
		$mapped = ( new SquareErrorMapper() )->map( $exception );
		Logger::error( 'Square admin request failed', $mapped['log_context'] );

		$error_code = (string) $mapped['log_context']['code'];

		return array(
			'status'          => $mapped['http_status'],
			'error_code'      => '' !== $error_code ? $error_code : 'square_error',
			'cashier_message' => $mapped['cashier_message'],
			'retriable'       => $mapped['retriable'],
		);
	}

	/**
	 * Build a local admin error response.
	 *
	 * @return array<string,mixed>
	 */
	private function admin_error_response( int $status, string $message ): array {
		return array(
			'status'          => $status,
			'cashier_message' => $message,
			'retriable'       => false,
		);
	}

	/**
	 * Send an AJAX response with the handler status code.
	 *
	 * @param array<string,mixed> $response Response data.
	 */
	private function send_ajax_response( array $response ): void {
		$status = $response['http_status'] ?? ( is_int( $response['status'] ?? null ) ? $response['status'] : 200 );
		wp_send_json( $response, (int) $status );
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
