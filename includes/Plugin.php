<?php
namespace WCPOS\WooCommercePOS\SquareTerminal;
final class Plugin { public function init(): void { add_filter('woocommerce_payment_gateways', array(Gateway::class, 'register_gateway')); } }
