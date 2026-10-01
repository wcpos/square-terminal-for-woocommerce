<?php
// WooCommerce's HPOS order cache and the container that serves it.
// Removal is routed to a test callback so a test can model the request cache.
namespace Automattic\WooCommerce\Caches {
	if ( ! class_exists( OrderCache::class ) ) {
		class OrderCache {
			public function remove( $id ) {
				if ( isset( $GLOBALS['sqtwc_order_cache_remove_callback'] ) ) {
					$GLOBALS['sqtwc_order_cache_remove_callback']( (int) $id );
				}
				return true;
			}
		}
	}
}

namespace {
	if ( ! function_exists( 'wc_get_container' ) ) {
		function wc_get_container() {
			return new class() {
				public function get( $id ) {
					return new $id();
				}
			};
		}
	}
}
