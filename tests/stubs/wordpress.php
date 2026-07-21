<?php
$GLOBALS['sqtwc_options'] = array();
$GLOBALS['sqtwc_filters'] = array();
$GLOBALS['sqtwc_actions'] = array();
$GLOBALS['sqtwc_rest_routes'] = array();
$GLOBALS['sqtwc_notices'] = array();
$GLOBALS['sqtwc_cron_events'] = array();
$GLOBALS['sqtwc_transients'] = array();
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data ) { return json_encode( $data ); } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $value ) { return $value; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $value ) { return is_string( $value ) ? trim( $value ) : $value; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $value ) { return is_string( $value ) ? trim( $value ) : $value; } }
if ( ! function_exists( 'absint' ) ) { function absint( $value ) { return abs( (int) $value ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $value ) { return (string) $value; } }
if ( ! function_exists( '__' ) ) { function __( $text ) { return $text; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text ) { return $text; } }
if ( ! function_exists( 'plugin_dir_path' ) ) { function plugin_dir_path( $file ) { return trailingslashit( dirname( $file ) ); } }
if ( ! function_exists( 'plugin_dir_url' ) ) { function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; } }
if ( ! function_exists( 'trailingslashit' ) ) { function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['sqtwc_actions'][$hook][] = $callback; return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['sqtwc_filters'][$hook][] = $callback; return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return $value; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $namespace, $route, $args = array() ) { $GLOBALS['sqtwc_rest_routes'][$namespace . $route] = $args; return true; } }
if ( ! function_exists( 'register_activation_hook' ) ) { function register_activation_hook( $file, $callback ) { $GLOBALS['sqtwc_activation_hook'] = array( $file, $callback ); } }
if ( ! function_exists( 'register_deactivation_hook' ) ) { function register_deactivation_hook( $file, $callback ) { $GLOBALS['sqtwc_deactivation_hook'] = array( $file, $callback ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { $GLOBALS['sqtwc_get_option_count'][$key] = ($GLOBALS['sqtwc_get_option_count'][$key] ?? 0) + 1; return $GLOBALS['sqtwc_options'][$key] ?? $default; } }
if ( ! function_exists( 'add_option' ) ) { function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) { if ( array_key_exists( $key, $GLOBALS['sqtwc_options'] ) ) { return false; } $GLOBALS['sqtwc_options'][$key] = $value; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $key ) { if ( ! array_key_exists( $key, $GLOBALS['sqtwc_options'] ) ) { return false; } unset( $GLOBALS['sqtwc_options'][$key] ); return true; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $key, $value ) { $GLOBALS['sqtwc_options'][$key] = $value; return true; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $key ) { return $GLOBALS['sqtwc_transients'][$key]['value'] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $key, $value, $expiration = 0 ) { $GLOBALS['sqtwc_transients'][$key] = array( 'value' => $value, 'expiration' => $expiration ); return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $key ) { unset( $GLOBALS['sqtwc_transients'][$key] ); return true; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return $GLOBALS['sqtwc_is_user_logged_in'] ?? false; } }
if ( ! function_exists( 'is_checkout' ) ) { function is_checkout() { return $GLOBALS['sqtwc_is_checkout'] ?? false; } }
if ( ! function_exists( 'is_checkout_pay_page' ) ) { function is_checkout_pay_page() { return $GLOBALS['sqtwc_is_checkout_pay_page'] ?? false; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return $GLOBALS['sqtwc_current_user_can'] ?? false; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $nonce, $action = -1 ) { return ! empty( $GLOBALS['sqtwc_nonce_valid'] ); } }
if ( ! function_exists( 'wp_send_json' ) ) { function wp_send_json( $response, $status_code = null ) { $GLOBALS['sqtwc_last_json_response'] = array( $response, $status_code ); } }
if ( ! function_exists( '__return_true' ) ) { function __return_true() { return true; } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'test-salt'; } }
if ( ! function_exists( 'time' ) ) { }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $path = '' ) { return 'https://wcpos.local/wp-json/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $action = -1 ) { return 'nonce'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://wcpos.local/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wc_add_notice' ) ) { function wc_add_notice( $message, $type = 'notice' ) { $GLOBALS['sqtwc_notices'][] = array($type, $message); } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $show = '' ) { return 'Test Store'; } }
if ( ! function_exists( 'get_current_blog_id' ) ) { function get_current_blog_id() { return $GLOBALS['sqtwc_blog_id'] ?? 1; } }
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook ) { return $GLOBALS['sqtwc_cron_events'][$hook] ?? false; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $timestamp, $recurrence, $hook ) { if ( ! isset( $GLOBALS['sqtwc_cron_events'][$hook] ) ) { $GLOBALS['sqtwc_cron_events'][$hook] = $timestamp; $GLOBALS['sqtwc_cron_schedules'][$hook] = $recurrence; } return true; } }
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['sqtwc_cron_events'][$hook], $GLOBALS['sqtwc_cron_schedules'][$hook] ); return true; } }
if ( ! function_exists( 'wp_register_script' ) ) { function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) { $GLOBALS['sqtwc_registered_scripts'][$handle] = array( 'src' => $src, 'ver' => $ver ); return true; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) { $GLOBALS['sqtwc_enqueued_scripts'][] = $handle; return true; } }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( $handle, $object_name, $l10n ) { $GLOBALS['sqtwc_localized_scripts'][$handle] = array( 'object' => $object_name, 'data' => $l10n ); return true; } }
if ( ! function_exists( 'wp_register_style' ) ) { function wp_register_style( $handle, $src = '', $deps = array(), $ver = false ) { $GLOBALS['sqtwc_registered_styles'][$handle] = $src; return true; } }
// Stands in for the official WooCommerce Square plugin's global accessor.
if ( ! function_exists( 'wc_square' ) ) { function wc_square() { if ( ! empty( $GLOBALS['sqtwc_wc_square_throws'] ) ) { throw new \RuntimeException( 'official plugin exploded' ); } return new SQTWC_Test_Square_Plugin(); } }
class SQTWC_Test_Square_Plugin { public function get_settings_handler() { return $GLOBALS['sqtwc_wc_square_handler'] ?? null; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) { $GLOBALS['sqtwc_enqueued_styles'][] = $handle; return true; } }
