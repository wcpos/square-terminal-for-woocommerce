<?php
/**
 * WooCommerce payment gateway integration.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

use Throwable;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareClientFactory;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareDeviceAdapter;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareErrorMapper;
use WCPOS\WooCommercePOS\SquareTerminal\Services\SquareOAuth;
use WCPOS\WooCommercePOS\SquareTerminal\Services\WooCommerceSquareHints;

/**
 * Square Terminal payment gateway.
 */
class Gateway extends \WC_Payment_Gateway {
	/** Device discovery transient lifetime in seconds. */
	private const DEVICE_CACHE_TTL = 300;

	/** Empty device discovery transient lifetime in seconds. */
	private const EMPTY_DEVICE_CACHE_TTL = 30;

	/**
	 * Last-known-good device list lifetime in seconds.
	 *
	 * Bounded rather than permanent: WordPress autoloads transients stored with
	 * no expiry, so an unbounded fallback would sit in memory on every request.
	 */
	private const LAST_KNOWN_GOOD_TTL = 86400;

	/**
	 * Per-request device lists, keyed by environment and location.
	 *
	 * @var array<string,array<int,array<string,string>>>
	 */
	private static array $device_memo = array();

	/**
	 * Gateway constructor.
	 */
	public function __construct() {
		$this->id                 = 'sqtwc';
		$this->method_title       = __( 'Square Terminal', 'square-terminal-for-woocommerce' );
		$this->method_description = __( 'Collect in-person payments with Square Terminal.', 'square-terminal-for-woocommerce' );
		$this->has_fields         = true;

		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_payment_assets' ) );
	}

	/**
	 * Enqueue the pairing controls script on this gateway's settings screen.
	 *
	 * The script is dependency-free and receives the AJAX contract, the admin
	 * nonce, and every dynamic string through wp_localize_script.
	 */
	public static function enqueue_admin_assets(): void {
		if ( ! self::is_gateway_settings_screen() ) {
			return;
		}

		wp_register_script(
			'sqtwc-admin',
			PLUGIN_URL . 'assets/js/admin.js',
			array(),
			VERSION,
			true
		);

		wp_localize_script(
			'sqtwc-admin',
			'sqtwcAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sqtwc_admin' ),
				'strings' => array(
					'working'      => __( 'Working…', 'square-terminal-for-woocommerce' ),
					/* translators: %s: Square Terminal pairing code. */
					'pairingCode'  => __( 'Pairing code: %s — enter it on the Terminal within 5 minutes.', 'square-terminal-for-woocommerce' ),
					'settingsOk'   => __( 'Square credentials and location verified.', 'square-terminal-for-woocommerce' ),
					'requestError' => __( 'The request could not be completed.', 'square-terminal-for-woocommerce' ),
					'pairedTitle'  => __( 'Paired with this plugin — selectable at checkout', 'square-terminal-for-woocommerce' ),
					'accountTitle' => __( 'Other devices Square can see at this location', 'square-terminal-for-woocommerce' ),
					'nonePaired'   => __( 'No Terminals are paired with this plugin yet. Use Create Device Code to pair one.', 'square-terminal-for-woocommerce' ),
					'noneAtAll'    => __( 'Square reports no Terminal API devices here. A Terminal running Square POS is invisible to this API until it has been paired with a device code, so an empty list is expected before pairing.', 'square-terminal-for-woocommerce' ),
					'accountNote'  => __( 'Square only reports Terminals that have been set up for Terminal API use. These were set up by another application, so they cannot be selected at checkout until paired with this plugin.', 'square-terminal-for-woocommerce' ),
					/* translators: 1: number of Terminals paired with this plugin, 2: number of other Terminals on the account. */
					'readersFound' => __( 'Found %1$d paired and %2$d other Terminal(s).', 'square-terminal-for-woocommerce' ),
				),
			)
		);

		wp_enqueue_script( 'sqtwc-admin' );
	}

	/**
	 * Whether the current admin request is this gateway's settings section.
	 */
	private static function is_gateway_settings_screen(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen detection, no state change.
		$page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page && 'checkout' === $tab && 'sqtwc' === $section;
	}

	/**
	 * Save gateway settings and invalidate device discovery for old and new keys.
	 *
	 * @return mixed
	 */
	public function process_admin_options() {
		$old_environment = Settings::get_environment();
		$old_location_id = Settings::get_location_id();
		$result          = parent::process_admin_options();

		Settings::reset_cache();
		self::delete_device_cache( $old_environment, $old_location_id );
		self::delete_device_cache( Settings::get_environment(), Settings::get_location_id() );

		return $result;
	}

	/**
	 * Whether the current request has interactive payment fields.
	 *
	 * Evaluate this at render time because WooCommerce may instantiate its
	 * gateway singleton before WordPress has parsed the order-pay query.
	 */
	public function has_fields() {
		return 0 < self::current_pay_order_id();
	}

	/**
	 * Register and enqueue the cashier payment assets with localized data.
	 *
	 * Enqueued on the order-pay page and checkout, and anywhere a POS context
	 * opts in via the `sqtwc_enqueue_payment_assets` filter. The localized data
	 * carries every dynamic string, the AJAX contract, and the device list so
	 * the JavaScript stays dependency-free and fully translatable.
	 */
	public function enqueue_payment_assets(): void {
		if ( ! $this->should_enqueue_payment_assets() ) {
			return;
		}

		wp_register_style(
			'sqtwc-payment',
			PLUGIN_URL . 'assets/css/payment.css',
			array(),
			VERSION
		);
		wp_register_script(
			'sqtwc-payment',
			PLUGIN_URL . 'assets/js/payment.js',
			array(),
			VERSION,
			true
		);

		wp_localize_script( 'sqtwc-payment', 'sqtwcPayment', self::get_localized_payment_data() );

		wp_enqueue_style( 'sqtwc-payment' );
		wp_enqueue_script( 'sqtwc-payment' );
	}

	/**
	 * Decide whether the current request should load the cashier assets.
	 */
	private function should_enqueue_payment_assets(): bool {
		// Both branches are load-bearing: 0.2.2 restored the cashier controls on
		// WCPOS checkouts, which are not always is_checkout_pay_page().
		$should = ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() );

		/**
		 * Filter whether the Square Terminal cashier assets should load.
		 *
		 * POS front-ends that render the gateway outside the standard checkout
		 * pages can opt in here.
		 *
		 * @param bool $should Whether to enqueue the assets.
		 */
		return (bool) apply_filters( 'sqtwc_enqueue_payment_assets', $should );
	}

	/**
	 * Build the data passed to the cashier JavaScript via wp_localize_script.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_localized_payment_data(): array {
		$environment = Settings::get_environment();

		return array(
			'ajaxUrl'         => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
			'nonce'           => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'sqtwc_payment' ) : '',
			'actions'         => array(
				'create' => 'sqtwc_create_terminal_checkout',
				'cancel' => 'sqtwc_cancel_terminal_checkout',
				'status' => 'sqtwc_get_terminal_status',
				'detach' => 'sqtwc_detach_terminal_checkout',
			),
			'environment'     => $environment,
			'devices'         => self::get_available_devices( $environment ),
			'defaultDeviceId' => (string) Settings::get( 'default_device_id', '' ),
			'debugLog'        => 'yes' === Settings::get( 'checkout_debug_logs', 'no' ),
			'poll'            => array(
				'cadenceMs'      => 2000,
				'backoffStartMs' => 2000,
				'backoffCapMs'   => 15000,
				'unstableAfter'  => 3,
				'deadlineMs'     => 330000,
			),
			'strings'         => self::get_localized_strings(),
		);
	}

	/**
	 * Return the list of selectable devices for the active environment.
	 *
	 * In sandbox the list is Square's documented magic test device IDs labeled
	 * by scenario so every UI state can be exercised without hardware. In
	 * production the list is discovered from paired Terminal API Device Codes.
	 * The JavaScript retains its manual-entry fallback when none are available.
	 *
	 * @param string $environment Active Square environment.
	 * @return array<int,array<string,string>>
	 */
	public static function get_available_devices( string $environment ): array {
		// WooCommerce builds the localized payment data more than once per page
		// render, which repeated both the transient reads and the log lines.
		$memo_key = $environment . '|' . Settings::get_location_id();
		if ( array_key_exists( $memo_key, self::$device_memo ) ) {
			return self::$device_memo[ $memo_key ];
		}

		$devices                        = self::resolve_available_devices( $environment );
		self::$device_memo[ $memo_key ] = $devices;

		return $devices;
	}

	/**
	 * Reset the per-request device memo.
	 */
	public static function reset_device_memo(): void {
		self::$device_memo = array();
	}

	/**
	 * Resolve the selectable device list for an environment.
	 *
	 * @param string $environment Active Square environment.
	 * @return array<int,array<string,string>>
	 */
	private static function resolve_available_devices( string $environment ): array {
		if ( 'sandbox' === $environment ) {
			return array(
				array(
					'id'    => '9fa747a2-25ff-48ee-b078-04381f7c828f',
					'label' => __( 'Sandbox: Success (charge $25 or less)', 'square-terminal-for-woocommerce' ),
				),
				array(
					'id'    => '22cd266c-6246-4c06-9983-67f0c26346b0',
					'label' => __( 'Sandbox: Success with 20% tip', 'square-terminal-for-woocommerce' ),
				),
				array(
					'id'    => '841100b9-ee60-4537-9bcf-e30b2ba5e215',
					'label' => __( 'Sandbox: Buyer cancels on terminal', 'square-terminal-for-woocommerce' ),
				),
				array(
					'id'    => '0a956d49-619a-4530-8e5e-8eac603ffc5e',
					'label' => __( 'Sandbox: Immediate timeout', 'square-terminal-for-woocommerce' ),
				),
				array(
					'id'    => 'da40d603-c2ea-4a65-8cfd-f42e36dab0c7',
					'label' => __( 'Sandbox: Offline terminal (stays pending)', 'square-terminal-for-woocommerce' ),
				),
			);
		}

		$location_id = Settings::get_location_id();
		$devices     = array();

		if ( '' === $location_id || '' === Settings::get_access_token() ) {
			// Silence here is indistinguishable from a check that ran and found
			// nothing, which is exactly what made an empty selector impossible
			// to diagnose. Every outcome of discovery is logged.
			Logger::info(
				'Square device discovery skipped',
				array(
					'environment'  => $environment,
					'reason'       => '' === $location_id ? 'no_location_id' : 'no_access_token',
				)
			);
		} else {
			$cache_key       = self::get_device_cache_key( $environment, $location_id );
			$stale_cache_key = $cache_key . '_last_known_good';
			$cached          = get_transient( $cache_key );
			if ( false !== $cached ) {
				$devices = (array) $cached;
				Logger::info(
					'Square device discovery served from cache',
					array(
						'environment' => $environment,
						'location_id' => $location_id,
						'count'       => count( $devices ),
					)
				);
			} else {
				$stale_devices = get_transient( $stale_cache_key );
				$devices       = false === $stale_devices ? array() : (array) $stale_devices;
				set_transient( $cache_key, $devices, empty( $devices ) ? self::EMPTY_DEVICE_CACHE_TTL : self::DEVICE_CACHE_TTL );

				try {
					$devices = ( new SquareDeviceAdapter( ( new SquareClientFactory() )->create() ) )->list_paired_devices( $location_id );
					set_transient( $cache_key, $devices, empty( $devices ) ? self::EMPTY_DEVICE_CACHE_TTL : self::DEVICE_CACHE_TTL );
					set_transient( $stale_cache_key, $devices, self::LAST_KNOWN_GOOD_TTL );
					Logger::info(
						'Square device discovery completed',
						array(
							'environment' => $environment,
							'location_id' => $location_id,
							'count'       => count( $devices ),
						)
					);
				} catch ( Throwable $exception ) {
					$mapped = ( new SquareErrorMapper() )->map( $exception );
					Logger::error( 'Square device discovery failed', $mapped['log_context'] );
				}
			}
		}

		/**
		 * Filter the production device list.
		 *
		 * @param array<int,array<string,string>> $devices Paired devices.
		 */
		return (array) apply_filters( 'sqtwc_available_devices', $devices );
	}

	/**
	 * Build the environment- and location-specific device cache key.
	 *
	 * @param string $environment Active Square environment.
	 * @param string $location_id Square location ID.
	 */
	public static function get_device_cache_key( string $environment, string $location_id ): string {
		return 'sqtwc_devices_' . md5( $environment . '|' . $location_id );
	}

	/**
	 * Delete a cached device discovery result.
	 *
	 * @param string $environment Active Square environment.
	 * @param string $location_id Square location ID.
	 */
	public static function delete_device_cache( string $environment, string $location_id ): void {
		// The per-request memo must not outlive an invalidation, or a lookup made
		// straight after pairing would still answer from the pre-pairing list.
		self::reset_device_memo();

		$cache_key = self::get_device_cache_key( $environment, $location_id );
		delete_transient( $cache_key );
		delete_transient( $cache_key . '_last_known_good' );
	}

	/**
	 * Translatable strings handed to the cashier JavaScript.
	 *
	 * @return array<string,string>
	 */
	public static function get_localized_strings(): array {
		return array(
			'heading'            => __( 'Square Terminal Payment', 'square-terminal-for-woocommerce' ),
			'deviceLabel'        => __( 'Terminal Device', 'square-terminal-for-woocommerce' ),
			'devicePlaceholder'  => __( 'Choose a terminal…', 'square-terminal-for-woocommerce' ),
			'deviceManualLabel'  => __( 'Terminal Device ID', 'square-terminal-for-woocommerce' ),
			'deviceManualHint'   => __( 'Temporary: enter the Square device ID manually until terminal pairing arrives.', 'square-terminal-for-woocommerce' ),
			'startPayment'       => __( 'Start Payment', 'square-terminal-for-woocommerce' ),
			'retryPayment'       => __( 'Retry Payment', 'square-terminal-for-woocommerce' ),
			'cancelPayment'      => __( 'Cancel Payment', 'square-terminal-for-woocommerce' ),
			'checkStatus'        => __( 'Check Status', 'square-terminal-for-woocommerce' ),
			'detachPayment'      => __( 'Terminal not responding — release this payment', 'square-terminal-for-woocommerce' ),
			'statusChooseDevice' => __( 'Choose a terminal to begin.', 'square-terminal-for-woocommerce' ),
			'statusCreating'     => __( 'Sending payment to the terminal…', 'square-terminal-for-woocommerce' ),
			'statusWaiting'      => __( 'Waiting for the customer to tap or insert their card…', 'square-terminal-for-woocommerce' ),
			'statusInProgress'   => __( 'Payment in progress on the terminal…', 'square-terminal-for-woocommerce' ),
			'statusCancelling'   => __( 'Cancelling the payment…', 'square-terminal-for-woocommerce' ),
			'statusCancelled'    => __( 'Payment cancelled.', 'square-terminal-for-woocommerce' ),
			'statusCompleted'    => __( 'Payment complete.', 'square-terminal-for-woocommerce' ),
			'statusTimeout'      => __( 'The terminal did not respond in time.', 'square-terminal-for-woocommerce' ),
			'statusCheckingNow'  => __( 'Checking payment status…', 'square-terminal-for-woocommerce' ),
			'statusReleased'     => __( 'Payment released. You can collect payment another way.', 'square-terminal-for-woocommerce' ),
			'connectionUnstable' => __( 'Connection unstable — still trying to reach the terminal…', 'square-terminal-for-woocommerce' ),
			'errorGeneric'       => __( 'Something went wrong. Please try again.', 'square-terminal-for-woocommerce' ),
			'errorNoDevice'      => __( 'Please choose a terminal first.', 'square-terminal-for-woocommerce' ),
			'errorNetwork'       => __( 'Could not reach the store. Check your connection and try again.', 'square-terminal-for-woocommerce' ),
			'detachHint'         => __( 'The terminal is not responding. You can release this payment and collect another way.', 'square-terminal-for-woocommerce' ),
			'logHeading'         => __( 'Checkout debug log', 'square-terminal-for-woocommerce' ),
			'logShow'            => __( 'Show debug log', 'square-terminal-for-woocommerce' ),
			'logHide'            => __( 'Hide debug log', 'square-terminal-for-woocommerce' ),
			'logCopy'            => __( 'Copy', 'square-terminal-for-woocommerce' ),
			'logClear'           => __( 'Clear', 'square-terminal-for-woocommerce' ),
			'logCopied'          => __( 'Copied.', 'square-terminal-for-woocommerce' ),
		);
	}

	/**
	 * Explain where a pre-filled setting came from.
	 *
	 * Shown only on fields this plugin pre-fills from the official WooCommerce
	 * Square plugin, so a merchant is never left wondering why a value appeared.
	 */
	private static function hint_description(): string {
		return __( 'Pre-filled from your WooCommerce Square settings. Save to apply it. Square credentials are not shared between the two plugins — enter the access token above.', 'square-terminal-for-woocommerce' );
	}

	/**
	 * Register this gateway with WooCommerce.
	 *
	 * @param array<class-string> $gateways Payment gateway class names.
	 * @return array<class-string>
	 */
	public static function register_gateway( array $gateways ): array {
		$gateways[] = self::class;

		return $gateways;
	}

	/**
	 * Define WooCommerce gateway settings fields.
	 */
	public function init_form_fields(): void {
		$hints = WooCommerceSquareHints::detect();

		$this->form_fields = array(
			'enabled'                  => array(
				'title'       => __( 'Enable/Disable', 'square-terminal-for-woocommerce' ),
				'type'        => 'checkbox',
				// This setting governs the online store only. WooCommerce POS uses
				// the gateway once it is configured, whether or not it is enabled
				// here, and a bare "Enable" implied the POS needed it too.
				'label'       => sprintf(
					/* translators: %s: link to WooCommerce POS. */
					__( 'Enable Square Terminal for web checkout (not necessary for %s)', 'square-terminal-for-woocommerce' ),
					'<a href="https://wcpos.com" target="_blank">WooCommerce POS</a>'
				),
				'description' => __( 'This enables the gateway for online store checkout. The POS uses this gateway automatically when configured.', 'square-terminal-for-woocommerce' ),
				'default'     => 'no',
			),
			'environment'              => array(
				'title'       => __( 'Environment', 'square-terminal-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'sandbox'    => __( 'Sandbox', 'square-terminal-for-woocommerce' ),
					'production' => __( 'Production', 'square-terminal-for-woocommerce' ),
				),
				'default'     => '' !== $hints['environment'] ? $hints['environment'] : 'sandbox',
				'description' => '' !== $hints['environment'] ? self::hint_description() : '',
			),
			'sandbox_access_token'     => array(
				'title' => __( 'Sandbox Access Token', 'square-terminal-for-woocommerce' ),
				'type'  => 'password',
			),
			'production_access_token'  => array(
				'title' => __( 'Production Access Token', 'square-terminal-for-woocommerce' ),
				'type'  => 'password',
			),
			'location_id'              => array(
				'title'       => __( 'Location ID', 'square-terminal-for-woocommerce' ),
				'type'        => 'text',
				'default'     => $hints['location_id'],
				'description' => '' !== $hints['location_id'] ? self::hint_description() : '',
			),
			'skip_receipt_screen'      => array(
				'title'   => __( 'Skip receipt screen', 'square-terminal-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Return the Terminal to idle without showing the receipt screen.', 'square-terminal-for-woocommerce' ),
				'default' => 'no',
			),
			'collect_signature'        => array(
				'title'   => __( 'Collect signature', 'square-terminal-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Ask the buyer for a signature on supported Terminal payments.', 'square-terminal-for-woocommerce' ),
				'default' => 'no',
			),
			'webhook_signature_key'    => array(
				'title' => __( 'Webhook Signature Key', 'square-terminal-for-woocommerce' ),
				'type'  => 'password',
			),
			'webhook_notification_url' => array(
				'title'       => __( 'Webhook Notification URL', 'square-terminal-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Must exactly match the URL configured in Square Developer Dashboard.', 'square-terminal-for-woocommerce' ),
			),
			'square_connection'        => array(
				'title' => __( 'Square connection', 'square-terminal-for-woocommerce' ),
				'type'  => 'square_connection',
			),
			'terminal_pairing'         => array(
				'title' => __( 'Terminal pairing', 'square-terminal-for-woocommerce' ),
				'type'  => 'terminal_pairing',
			),

			// --- Workstream B (cashier frontend) settings. Kept in a distinct block to minimize merge conflicts with parallel workstreams. ---
			'checkout_debug_logs'      => array(
				'title'       => __( 'Checkout debug logs', 'square-terminal-for-woocommerce' ),
				'label'       => __( 'Show a copyable debug log panel on the Terminal payment screen', 'square-terminal-for-woocommerce' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __( 'Adds a collapsible, copyable log of Terminal payment steps for the cashier. Useful for support; safe to leave off.', 'square-terminal-for-woocommerce' ),
			),
			// --- End Workstream B settings. ---
		);
	}

	/**
	 * Redirect selected unpaid checkout orders to order-pay for Terminal collection.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array<string,string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'square-terminal-for-woocommerce' ), 'error' );

			return array( 'result' => 'failure' );
		}

		if ( $order->is_paid() ) {
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Render payment fields.
	 *
	 * WooCommerce calls this both on classic checkout and on the order-pay
	 * form. On order-pay we resolve the real order ID so resume/auth attributes
	 * render and a reloaded page re-attaches to any in-flight attempt.
	 */
	public function payment_fields(): void {
		$order_id = self::current_pay_order_id();
		if ( ! $order_id ) {
			return;
		}

		echo self::render_payment_ui( $order_id, array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped in render method.
	}

	/**
	 * Resolve the order ID of the current order-pay request, if any.
	 */
	private static function current_pay_order_id(): int {
		if ( function_exists( 'get_query_var' ) ) {
			$id = absint( get_query_var( 'order-pay' ) );
			if ( $id ) {
				return $id;
			}
		}

		if ( isset( $GLOBALS['wp']->query_vars['order-pay'] ) ) {
			return absint( $GLOBALS['wp']->query_vars['order-pay'] );
		}

		return 0;
	}

	/**
	 * Render the order-pay/POS payment UI.
	 *
	 * The device options and every dynamic string are supplied to the
	 * JavaScript via wp_localize_script; this markup is intentionally static
	 * so no untrusted value is interpolated server-side either. When the order
	 * has an open Terminal Checkout that is not yet paid, resume attributes are
	 * emitted so the cashier JavaScript re-attaches to the in-flight attempt on
	 * page load.
	 *
	 * @param int      $order_id WooCommerce order ID.
	 * @param string[] $log      Existing payment log lines (unused; kept for BC).
	 * @return string
	 */
	public static function render_payment_ui( int $order_id, array $log = array() ): string {
		unset( $log );

		$order        = $order_id ? wc_get_order( $order_id ) : null;
		$resume_attrs = self::render_resume_attributes( $order );
		$debug_logs   = 'yes' === Settings::get( 'checkout_debug_logs', 'no' );

		$parts   = array();
		$parts[] = sprintf(
			'<div id="sqtwc-payment" class="sqtwc-payment" data-order-id="%1$d"%2$s>',
			$order_id,
			$resume_attrs
		);
		$parts[] = sprintf( '<h3 class="sqtwc-payment__heading">%s</h3>', esc_html__( 'Square Terminal Payment', 'square-terminal-for-woocommerce' ) );

		// Device chooser: a dropdown populated by JavaScript from the localized
		// device list, with a temporary manual-entry fallback for production
		// until Phase 2 pairing lands. Never free-text in sandbox.
		$parts[] = '<div class="sqtwc-payment__field sqtwc-payment__device">';
		$parts[] = sprintf( '<label for="sqtwc-device-id">%s</label>', esc_html__( 'Terminal Device', 'square-terminal-for-woocommerce' ) );
		$parts[] = '<select id="sqtwc-device-id" class="sqtwc-payment__device-select"></select>';
		$parts[] = sprintf(
			'<label for="sqtwc-device-id-manual" class="sqtwc-payment__device-manual-label" hidden>%s</label>',
			esc_html__( 'Terminal Device ID', 'square-terminal-for-woocommerce' )
		);
		$parts[] = '<input type="text" id="sqtwc-device-id-manual" class="sqtwc-payment__device-manual" autocomplete="off" hidden />';
		$parts[] = '</div>';

		$parts[] = '<div class="sqtwc-payment__actions">';
		$parts[] = sprintf(
			'<button type="button" id="sqtwc-start-payment" class="button sqtwc-payment__start" data-sqtwc-action="start">%s</button>',
			esc_html__( 'Start Payment', 'square-terminal-for-woocommerce' )
		);
		$parts[] = sprintf(
			'<button type="button" id="sqtwc-cancel-payment" class="button sqtwc-payment__cancel" data-sqtwc-action="cancel" hidden>%s</button>',
			esc_html__( 'Cancel Payment', 'square-terminal-for-woocommerce' )
		);
		$parts[] = sprintf(
			'<button type="button" id="sqtwc-check-status" class="button sqtwc-payment__check" data-sqtwc-action="check">%s</button>',
			esc_html__( 'Check Status', 'square-terminal-for-woocommerce' )
		);
		$parts[] = sprintf(
			'<button type="button" id="sqtwc-detach-payment" class="button sqtwc-payment__detach" data-sqtwc-action="detach" hidden>%s</button>',
			esc_html__( 'Terminal not responding — release this payment', 'square-terminal-for-woocommerce' )
		);
		$parts[] = '</div>';

		$parts[] = '<div id="sqtwc-status" class="sqtwc-payment__status" role="status" aria-live="polite"></div>';

		if ( $debug_logs ) {
			$parts[] = '<div id="sqtwc-log-panel" class="sqtwc-payment__log" hidden>';
			$parts[] = sprintf(
				'<button type="button" class="sqtwc-payment__log-toggle" data-sqtwc-action="log-toggle" aria-expanded="false">%s</button>',
				esc_html__( 'Show debug log', 'square-terminal-for-woocommerce' )
			);
			$parts[] = '<div class="sqtwc-payment__log-body" hidden>';
			$parts[] = sprintf(
				'<label class="screen-reader-text" for="sqtwc-log">%s</label>',
				esc_html__( 'Checkout debug log', 'square-terminal-for-woocommerce' )
			);
			$parts[] = '<textarea id="sqtwc-log" class="sqtwc-payment__log-output" rows="8" readonly></textarea>';
			$parts[] = '<div class="sqtwc-payment__log-actions">';
			$parts[] = sprintf(
				'<button type="button" class="button sqtwc-payment__log-copy" data-sqtwc-action="log-copy">%s</button>',
				esc_html__( 'Copy', 'square-terminal-for-woocommerce' )
			);
			$parts[] = sprintf(
				'<button type="button" class="button sqtwc-payment__log-clear" data-sqtwc-action="log-clear">%s</button>',
				esc_html__( 'Clear', 'square-terminal-for-woocommerce' )
			);
			$parts[] = '</div></div></div>';
		}

		$parts[] = '</div>';

		return implode( '', $parts );
	}

	/**
	 * Build resume/auth data attributes for an order with an open attempt.
	 *
	 * @param object|null $order WooCommerce order or null.
	 * @return string
	 */
	private static function render_resume_attributes( $order ): string {
		if ( ! $order ) {
			return '';
		}

		$attrs = sprintf( ' data-order-key="%s"', esc_attr( (string) $order->get_order_key() ) );

		$checkout_id = (string) $order->get_meta( '_sqtwc_checkout_id', true );
		if ( '' === $checkout_id || $order->is_paid() ) {
			return $attrs;
		}

		return $attrs . sprintf(
			' data-resume="1" data-checkout-id="%1$s" data-attempt-id="%2$s" data-device-id="%3$s" data-status="%4$s"',
			esc_attr( $checkout_id ),
			esc_attr( (string) $order->get_meta( '_sqtwc_current_attempt_id', true ) ),
			esc_attr( (string) $order->get_meta( '_sqtwc_device_id', true ) ),
			esc_attr( (string) $order->get_meta( '_sqtwc_checkout_status', true ) )
		);
	}

	/**
	 * Render admin helper controls.
	 *
	 * @return string
	 */
	public static function render_admin_fields(): string {
		return sprintf(
			'<button type="button" class="button" id="sqtwc-check-readers">%1$s</button> '
			. '<button type="button" class="button" id="sqtwc-create-device-code">%2$s</button> '
			. '<button type="button" class="button" id="sqtwc-validate-settings">%3$s</button>'
			. '<p id="sqtwc-admin-status" class="sqtwc-admin__status" role="status" aria-live="polite"></p>'
			. '<div id="sqtwc-reader-list" class="sqtwc-admin__readers"></div>'
			. '<p class="description">%4$s</p>',
			esc_html__( 'Check for readers', 'square-terminal-for-woocommerce' ),
			esc_html__( 'Create Device Code', 'square-terminal-for-woocommerce' ),
			esc_html__( 'Validate Settings', 'square-terminal-for-woocommerce' ),
			esc_html__( 'Square can only see a Terminal once it has been paired with a device code — a Terminal running Square POS is invisible to the API until then. Create Device Code returns a code to enter on the Terminal; Check for readers lists what Square can see; Validate Settings checks the credentials and location above.', 'square-terminal-for-woocommerce' )
		);
	}

	/**
	 * Render the pairing controls as a WooCommerce settings row.
	 *
	 * WooCommerce resolves a field's `type` to `generate_{type}_html()`, so this
	 * is what puts render_admin_fields() on the gateway settings screen.
	 *
	 * @param string              $key  Field key.
	 * @param array<string,mixed> $data Field definition.
	 * @return string
	 */
	public function generate_square_connection_html( $key, $data ): string {
		unset( $key );

		// The row is absent entirely until a WCPOS Square application is
		// configured, so an unfinished flow can never appear on a live site.
		if ( '' === SquareOAuth::client_id() ) {
			return '';
		}

		return sprintf(
			'<tr valign="top"><th scope="row" class="titledesc">%1$s</th><td class="forminp">%2$s</td></tr>',
			esc_html( isset( $data['title'] ) ? (string) $data['title'] : '' ),
			self::render_connection_controls()
		);
	}

	/**
	 * Render the Connect / Disconnect controls and current connection state.
	 */
	public static function render_connection_controls(): string {
		$connection = SquareOAuth::connection();

		// A rotation that could not be completed clears the stored tokens, since
		// Square's PKCE refresh tokens are single use and retrying a spent one
		// cannot succeed. Without this the row would silently revert to "Connect"
		// and leave the merchant with no idea why the connection lapsed.
		if ( ! empty( $connection['reconnect_required'] ) ) {
			return sprintf(
				'<p><strong>%1$s</strong></p><p class="description">%2$s</p>'
				. '<p><a class="button button-primary" href="%3$s">%4$s</a></p>',
				esc_html__( 'Reconnect to Square required', 'square-terminal-for-woocommerce' ),
				esc_html__( 'The Square authorization could not be renewed, so it was ended rather than left in an unusable state. Terminal payments will not work until this site is reconnected.', 'square-terminal-for-woocommerce' ),
				esc_url( self::connection_action_url( 'sqtwc_square_connect' ) ),
				esc_html__( 'Reconnect to Square', 'square-terminal-for-woocommerce' )
			);
		}

		if ( SquareOAuth::is_connected() ) {
			$merchant = (string) ( $connection['merchant_id'] ?? '' );

			return sprintf(
				'<p><strong>%1$s</strong></p><p class="description">%2$s</p>'
				. '<p><a class="button" href="%3$s">%4$s</a></p>',
				esc_html__( 'Connected to Square', 'square-terminal-for-woocommerce' ),
				esc_html(
					sprintf(
						/* translators: 1: Square environment, 2: Square merchant ID. */
						__( 'Environment: %1$s. Merchant: %2$s.', 'square-terminal-for-woocommerce' ),
						(string) ( $connection['environment'] ?? '' ),
						'' !== $merchant ? $merchant : __( 'unknown', 'square-terminal-for-woocommerce' )
					)
				),
				esc_url( self::connection_action_url( 'sqtwc_square_disconnect' ) ),
				esc_html__( 'Disconnect', 'square-terminal-for-woocommerce' )
			);
		}

		return sprintf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p><p class="description">%3$s</p>',
			esc_url( self::connection_action_url( 'sqtwc_square_connect' ) ),
			esc_html__( 'Connect to Square', 'square-terminal-for-woocommerce' ),
			esc_html__( 'Authorize this site with Square instead of pasting an access token. The access token below is only needed if you are not connected.', 'square-terminal-for-woocommerce' )
		);
	}

	/**
	 * Build a nonce-protected admin-post URL for a connection action.
	 *
	 * @param string $action Admin-post action name.
	 */
	private static function connection_action_url( string $action ): string {
		return add_query_arg(
			array(
				'action'   => $action,
				'_wpnonce' => wp_create_nonce( $action ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Render the pairing controls as a WooCommerce settings row.
	 *
	 * @param string              $key  Field key.
	 * @param array<string,mixed> $data Field definition.
	 * @return string
	 */
	public function generate_terminal_pairing_html( $key, $data ): string {
		$title = isset( $data['title'] ) ? (string) $data['title'] : '';

		return sprintf(
			'<tr valign="top"><th scope="row" class="titledesc">%1$s</th><td class="forminp">%2$s</td></tr>',
			esc_html( $title ),
			self::render_admin_fields()
		);
	}
}
