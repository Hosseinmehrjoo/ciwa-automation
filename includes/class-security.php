<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Security helpers: URL allowlist, rate limits, secret crypto, tab whitelist.
 */
class Ciwa_Security {

	const TABS = array('welcome', 'dashboard', 'setup', 'logs', 'settings', 'support');

	/**
	 * @param string $tab
	 * @return string
	 */
	public static function sanitize_tab($tab) {
		$tab = sanitize_key($tab);
		return in_array($tab, self::TABS, true) ? $tab : '';
	}

	/**
	 * Validate outbound HTTP(S) URL (n8n). Blocks non-http schemes.
	 *
	 * @param string $url
	 * @return string|WP_Error Normalized URL or error.
	 */
	public static function validate_outbound_url($url) {
		$url = esc_url_raw(trim((string) $url));
		if ('' === $url) {
			return '';
		}
		$parts = wp_parse_url($url);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return new WP_Error('bad_url', 'آدرس نامعتبر است.');
		}
		$scheme = strtolower($parts['scheme']);
		if (!in_array($scheme, array('http', 'https'), true)) {
			return new WP_Error('bad_scheme', 'فقط آدرس‌های http/https مجاز است.');
		}
		$host = strtolower($parts['host']);
		// Block obvious local/metadata targets that enable SSRF from shared hosting.
		$blocked = array('169.254.169.254', 'metadata.google.internal', 'metadata');
		if (in_array($host, $blocked, true)) {
			return new WP_Error('blocked_host', 'این میزبان مجاز نیست.');
		}
		// Note: private/local hosts are allowed (self-hosted n8n on LAN).
		return untrailingslashit($url);
	}

	/**
	 * Simple rate limit per user+action.
	 *
	 * @param string $action
	 * @param int    $max
	 * @param int    $window_seconds
	 * @return true|WP_Error
	 */
	public static function rate_limit($action, $max = 5, $window_seconds = 60) {
		$user = get_current_user_id();
		$key  = 'ciwa_rl_' . sanitize_key($action) . '_' . $user;
		$hits = (int) get_transient($key);
		if ($hits >= $max) {
			return new WP_Error('rate_limit', 'تعداد درخواست زیاد است. کمی صبر کنید.');
		}
		set_transient($key, $hits + 1, $window_seconds);
		return true;
	}

	/**
	 * Encrypt secret for option storage. Falls back to plaintext if OpenSSL missing.
	 *
	 * @param string $plain
	 * @return string
	 */
	public static function encrypt($plain) {
		$plain = (string) $plain;
		if ('' === $plain || 0 === strpos($plain, 'ciwa1:')) {
			return $plain;
		}
		if (!function_exists('openssl_encrypt') || !defined('AUTH_KEY') || !AUTH_KEY) {
			return $plain;
		}
		$key = hash('sha256', AUTH_KEY . 'ciwa', true);
		$iv  = substr(hash('sha256', (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : AUTH_KEY) . 'ciwa'), 0, 16);
		$enc = openssl_encrypt($plain, 'AES-256-CBC', $key, 0, $iv);
		return $enc ? ('ciwa1:' . $enc) : $plain;
	}

	/**
	 * @param string $stored
	 * @return string
	 */
	public static function decrypt($stored) {
		$stored = (string) $stored;
		if (0 !== strpos($stored, 'ciwa1:')) {
			return $stored;
		}
		if (!function_exists('openssl_decrypt') || !defined('AUTH_KEY') || !AUTH_KEY) {
			return '';
		}
		$raw = substr($stored, 6);
		$key = hash('sha256', AUTH_KEY . 'ciwa', true);
		$iv  = substr(hash('sha256', (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : AUTH_KEY) . 'ciwa'), 0, 16);
		$dec = openssl_decrypt($raw, 'AES-256-CBC', $key, 0, $iv);
		return false === $dec ? '' : $dec;
	}

	/**
	 * @param string $value HH:MM
	 * @param string $default
	 */
	public static function sanitize_hhmm($value, $default = '22:00') {
		$value = sanitize_text_field((string) $value);
		if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value)) {
			$parts = explode(':', $value);
			return sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
		}
		return $default;
	}

	/**
	 * Strip secrets from log context.
	 *
	 * @param array $context
	 * @return array
	 */
	public static function redact_context($context) {
		if (!is_array($context)) {
			return array();
		}
		$keys = array('sms_api_key', 'api_key', 'mediana_api_key', 'wc_cs', 'wc_ck', 'consumer_secret', 'token', 'password', 'Authorization', 'Api-Key');
		foreach ($context as $k => $v) {
			$lk = strtolower((string) $k);
			foreach ($keys as $secret) {
				if (false !== strpos($lk, strtolower($secret))) {
					$context[$k] = '[redacted]';
					continue 2;
				}
			}
			if (is_array($v)) {
				$context[$k] = self::redact_context($v);
			}
		}
		return $context;
	}
}
