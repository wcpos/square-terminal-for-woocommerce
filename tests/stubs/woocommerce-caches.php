<?php
// WooCommerce's HPOS order cache, its opt-in HPOS data cache, and the
// container that serves them. Evictions are routed to test callbacks so a
// test can model the request cache.
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

namespace Automattic\WooCommerce\Utilities {
	if ( ! class_exists( OrderUtil::class ) ) {
		class OrderUtil {
			public static function custom_orders_table_datastore_cache_enabled(): bool {
				return ! empty( $GLOBALS['sqtwc_hpos_data_caching'] );
			}
		}
	}
}

namespace Automattic\WooCommerce\Internal\DataStores\Orders {
	if ( ! class_exists( OrdersTableDataStore::class ) ) {
		class OrdersTableDataStore {
			// Like WooCommerce, a no-op unless HPOS data caching is on.
			public function clear_cached_data( array $order_ids ): array {
				if ( empty( $GLOBALS['sqtwc_hpos_data_caching'] ) ) {
					return array_fill_keys( $order_ids, true );
				}
				foreach ( $order_ids as $order_id ) {
					if ( isset( $GLOBALS['sqtwc_hpos_data_cache_clear_callback'] ) ) {
						$GLOBALS['sqtwc_hpos_data_cache_clear_callback']( (int) $order_id );
					}
				}
				return array_fill_keys( $order_ids, true );
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
