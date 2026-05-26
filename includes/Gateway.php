<?php
/**
 * WooCommerce payment gateway integration.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal
 */

namespace WCPOS\WooCommercePOS\SquareTerminal;

/**
 * Square Terminal payment gateway.
 */
class Gateway extends \WC_Payment_Gateway {
	/**
	 * Gateway constructor.
	 */
	public function __construct() {
		$this->id                 = 'sqtwc';
		$this->method_title       = __( 'Square Terminal', 'square-terminal-for-woocommerce' );
		$this->method_description = __( 'Collect in-person payments with Square Terminal.', 'square-terminal-for-woocommerce' );
		$this->has_fields         = false;

		$this->init_form_fields();
		$this->init_settings();
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
		$this->form_fields = array(
			'enabled'                  => array(
				'title' => __( 'Enable', 'square-terminal-for-woocommerce' ),
				'type'  => 'checkbox',
			),
			'environment'              => array(
				'title'   => __( 'Environment', 'square-terminal-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'sandbox'    => __( 'Sandbox', 'square-terminal-for-woocommerce' ),
					'production' => __( 'Production', 'square-terminal-for-woocommerce' ),
				),
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
				'title' => __( 'Location ID', 'square-terminal-for-woocommerce' ),
				'type'  => 'text',
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
	 */
	public function payment_fields(): void {
		echo self::render_payment_ui( 0, array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped in render method.
	}

	/**
	 * Render the order-pay/POS payment UI.
	 *
	 * @param int      $order_id WooCommerce order ID.
	 * @param string[] $log      Existing payment log lines.
	 * @return string
	 */
	public static function render_payment_ui( int $order_id, array $log = array() ): string {
		$items = '';
		foreach ( $log as $line ) {
			$items .= '<li>' . esc_html( $line ) . '</li>';
		}

		return sprintf(
			'<div id="sqtwc-payment" data-order-id="%1$d"><h3>%2$s</h3><label>%3$s <input id="sqtwc-device-id" /></label><button id="sqtwc-start-payment">%4$s</button><button id="sqtwc-cancel-payment">%5$s</button><div id="sqtwc-status" role="status"></div><h4>%6$s</h4><ol id="sqtwc-payment-log">%7$s</ol></div>',
			$order_id,
			esc_html__( 'Square Terminal Payment', 'square-terminal-for-woocommerce' ),
			esc_html__( 'Terminal Device ID', 'square-terminal-for-woocommerce' ),
			esc_html__( 'Start Payment', 'square-terminal-for-woocommerce' ),
			esc_html__( 'Cancel Payment', 'square-terminal-for-woocommerce' ),
			esc_html__( 'Payment Log', 'square-terminal-for-woocommerce' ),
			$items
		);
	}

	/**
	 * Render admin helper controls.
	 *
	 * @return string
	 */
	public static function render_admin_fields(): string {
		return sprintf(
			'<p>%1$s</p><input class="sqtwc-webhook-url" name="webhook_notification_url" value="%2$s" /><button id="sqtwc-create-device-code">%3$s</button><button id="sqtwc-validate-settings">%4$s</button><p>%5$s</p>',
			esc_html__( 'Webhook notification URL must exactly match Square Developer Dashboard.', 'square-terminal-for-woocommerce' ),
			esc_attr( Settings::get_webhook_notification_url() ),
			esc_html__( 'Create Device Code', 'square-terminal-for-woocommerce' ),
			esc_html__( 'Validate Settings', 'square-terminal-for-woocommerce' ),
			esc_html__( 'Use dev-pro.wcpos.com for hosted Square Sandbox validation.', 'square-terminal-for-woocommerce' )
		);
	}
}
