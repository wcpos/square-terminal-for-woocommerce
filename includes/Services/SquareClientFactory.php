<?php
namespace WCPOS\WooCommercePOS\SquareTerminal\Services;
use WCPOS\WooCommercePOS\SquareTerminal\Settings;
use WCPOS\WooCommercePOS\SquareTerminal\Vendor\Square\SquareClient;
final class SquareClientFactory { public function create(): SquareClient { return new SquareClient(Settings::get_access_token(), null, array('baseUrl'=>Settings::get_base_url())); } }
