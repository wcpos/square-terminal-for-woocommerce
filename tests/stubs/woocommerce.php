<?php
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public string $id = '';
		public string $method_title = '';
		public string $method_description = '';
		public bool $has_fields = false;
		public array $form_fields = array();
		protected array $settings = array();
		public function init_form_fields(): void {}
		public function init_settings(): void {}
		public function get_option( $key, $default = '' ) { return $this->settings[ $key ] ?? $default; }
		public function process_admin_options() { return true; }
		public function get_return_url( $order = null ) { return '/thank-you'; }
	}
}
class SQTWC_Test_Order {
	public array $meta = array(); public array $notes = array(); public bool $paid = false; public int $id; public string $key = 'key'; public string $transaction_id = ''; public int $payment_complete_calls = 0; public string $status = 'pending'; public string $total = '12.34'; public string $currency = 'USD';
	public function __construct($id=99){$this->id=$id;}
	public function get_id(){return $this->id;} public function is_paid(){return $this->paid;} public function get_order_key(){return $this->key;}
	public function get_checkout_payment_url($on_checkout=false){return '/checkout/order-pay/'.$this->id.'/?pay_for_order=true&key='.$this->key;}
	public function get_checkout_order_received_url(){return '/checkout/order-received/'.$this->id.'/?key='.$this->key;} public function get_order_number(){return (string) $this->id;}
	public function add_order_note($note){$this->notes[]=$note;} public function update_meta_data($k,$v){$this->meta[$k]=$v;} public function get_meta($k,$single=true){return $this->meta[$k] ?? ($single ? '' : array());}
	public function save(){} public function set_transaction_id($id){$this->transaction_id=$id;} public function update_status($status){$this->status=$status;} public function payment_complete($id=''){ $this->payment_complete_calls++; $this->paid=true; if($id){$this->transaction_id=$id;} }
	public function get_currency(){return $this->currency;} public function get_total(){return $this->total;}
}
if ( ! function_exists( 'wc_get_order' ) ) { function wc_get_order( $id ) { return $GLOBALS['sqtwc_orders'][$id] ?? null; } }
if ( ! function_exists( 'wc_get_logger' ) ) { function wc_get_logger() { return new class { public array $logs = array(); public function info($m,$c=array()){$this->logs[]=array('info',$m,$c);} public function error($m,$c=array()){$this->logs[]=array('error',$m,$c);} }; } }
