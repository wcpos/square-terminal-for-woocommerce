<?php
namespace WCPOS\WooCommercePOS\SquareTerminal;
final class Logger {
	private const SECRET_KEYS = array('token','access_token','signature','authorization','webhook_signature_key');
	public static function sanitize_context(array $context): array { $clean = array(); foreach ($context as $key=>$value) { $secret = self::is_secret((string)$key); if (is_array($value)) { $clean[$key] = $secret ? '[redacted]' : self::sanitize_context($value); } else { $clean[$key] = $secret ? '[redacted]' : $value; } } return $clean; }
	private static function is_secret(string $key): bool { $key=strtolower($key); foreach (self::SECRET_KEYS as $needle) { if (str_contains($key, $needle)) return true; } return false; }
	public static function info(string $message, array $context = array(), $order = null): void { wc_get_logger()->info($message, array('source'=>'square-terminal-for-woocommerce') + self::sanitize_context($context)); if ($order && method_exists($order,'add_order_note')) { $order->add_order_note('Square Terminal: '.$message); } }
	public static function error(string $message, array $context = array(), $order = null): void { wc_get_logger()->error($message, array('source'=>'square-terminal-for-woocommerce') + self::sanitize_context($context)); if ($order && method_exists($order,'add_order_note')) { $order->add_order_note('Square Terminal error: '.$message); } }
}
