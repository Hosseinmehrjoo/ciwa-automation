<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_Settings {

	/** @var self|null */
	private static $instance = null;

	/** @var array|null Request-level cache */
	private static $cache_settings = null;

	/** @var array|null */
	private static $cache_setup = null;

	const SECRET_KEYS = array('sms_api_key', 'mediana_api_key', 'wc_cs', 'wc_ck');

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function flush_cache() {
		self::$cache_settings = null;
		self::$cache_setup    = null;
	}

	public static function defaults() {
		return array(
			'engine_mode'          => '',
			'n8n_test_url'         => '',
			'n8n_prod_url'         => '',
			'sms_provider'         => 'mediana',
			'sms_api_key'          => '',
			'mediana_api_key'      => '',
			'from_number'          => '',
			'admin_phone'          => '',
			'wc_ck'                => '',
			'wc_cs'                => '',
			'cart_abandon_minutes' => 45,
			'email_enabled'        => 0,
			'quiet_hours_enabled'  => 0,
			'quiet_hours_start'    => '22:00',
			'quiet_hours_end'      => '08:00',
			'stop_keywords'        => 'لغو,stop,STOP',
			'blacklist'            => array(),
			'template_id'          => '',
			'roi_avg_order'        => 0,
			'segment'              => array(
				'min_order_total'    => 0,
				'first_purchase_only'=> 0,
				'include_categories' => array(),
				'exclude_categories' => array(),
			),
			'stages'               => self::default_stages(),
		);
	}

	public static function get() {
		if (null !== self::$cache_settings) {
			return self::$cache_settings;
		}
		$saved  = get_option(CIWA_OPTION, array());
		$merged = self::merge_recursive(self::defaults(), is_array($saved) ? $saved : array());
		foreach (self::SECRET_KEYS as $sk) {
			if (!empty($merged[$sk])) {
				$merged[$sk] = Ciwa_Security::decrypt($merged[$sk]);
			}
		}
		if (empty($merged['sms_api_key']) && !empty($merged['mediana_api_key'])) {
			$merged['sms_api_key'] = $merged['mediana_api_key'];
		}
		if (empty($merged['sms_provider'])) {
			$merged['sms_provider'] = 'mediana';
		}
		$merged['stages'] = self::normalize_stages(isset($merged['stages']) ? $merged['stages'] : array());
		self::$cache_settings = $merged;
		return $merged;
	}

	public static function default_stages() {
		$lib = class_exists('Ciwa_Messages') ? Ciwa_Messages::default_library() : array();
		$stages = array(
			'register' => array(
				'enabled' => 1,
				'label'   => 'ثبت‌نام کاربر',
				'icon'    => 'user-plus',
				'steps'   => array(
					array('id' => 'nurture_24h', 'label' => 'مزایای خرید', 'delay_amount' => 24, 'delay_unit' => 'hours', 'pattern_code' => '', 'enabled' => 1),
					array('id' => 'nurture_5d', 'label' => 'پیام FOMO', 'delay_amount' => 5, 'delay_unit' => 'days', 'pattern_code' => '', 'enabled' => 1),
				),
			),
			'cart' => array(
				'enabled' => 1,
				'label'   => 'سبد خرید رها‌شده',
				'icon'    => 'cart',
				'steps'   => array(
					array('id' => 'cart_60m', 'label' => 'یادآوری عادی', 'delay_amount' => 60, 'delay_unit' => 'minutes', 'pattern_code' => '', 'enabled' => 1),
					array('id' => 'cart_12h', 'label' => 'پیام FOMO', 'delay_amount' => 12, 'delay_unit' => 'hours', 'pattern_code' => '', 'enabled' => 1),
					array('id' => 'cart_24h', 'label' => 'یادآوری نهایی', 'delay_amount' => 24, 'delay_unit' => 'hours', 'pattern_code' => '', 'enabled' => 1),
				),
			),
			'pending' => array(
				'enabled' => 1,
				'label'   => 'درگاه بدون پرداخت',
				'icon'    => 'clock',
				'steps'   => array(
					array('id' => 'pending_30m', 'label' => 'سفارش تکمیل نشده', 'delay_amount' => 30, 'delay_unit' => 'minutes', 'pattern_code' => '', 'enabled' => 1),
					array('id' => 'pending_6h', 'label' => 'مشکل احتمالی پرداخت', 'delay_amount' => 6, 'delay_unit' => 'hours', 'pattern_code' => '', 'enabled' => 1),
					array('id' => 'pending_1d', 'label' => 'آخرین یادآوری', 'delay_amount' => 1, 'delay_unit' => 'days', 'pattern_code' => '', 'enabled' => 1),
				),
			),
			'failed' => array(
				'enabled' => 1,
				'label'   => 'پرداخت ناموفق',
				'icon'    => 'alert',
				'steps'   => array(
					array('id' => 'failed_now', 'label' => 'اطلاع + بازگشت به پرداخت', 'delay_amount' => 3, 'delay_unit' => 'minutes', 'pattern_code' => '', 'enabled' => 1),
					array('id' => 'failed_1h', 'label' => 'یادآوری ناموفق', 'delay_amount' => 1, 'delay_unit' => 'hours', 'pattern_code' => '', 'enabled' => 1),
					array('id' => 'failed_24h', 'label' => 'آخرین پیام و خروج', 'delay_amount' => 24, 'delay_unit' => 'hours', 'pattern_code' => '', 'enabled' => 1),
				),
			),
			'purchase' => array(
				'enabled' => 1,
				'label'   => 'بعد از خرید موفق',
				'icon'    => 'check',
				'steps'   => array(
					array('id' => 'order_confirm', 'label' => 'تأیید سفارش (فوری)', 'delay_amount' => 0, 'delay_unit' => 'minutes', 'pattern_code' => '', 'enabled' => 1),
					array('id' => 'satisfaction_5d', 'label' => 'درخواست رضایت', 'delay_amount' => 5, 'delay_unit' => 'days', 'pattern_code' => '', 'enabled' => 1),
				),
			),
		);
		foreach ($stages as $jk => &$stage) {
			foreach ($stage['steps'] as &$step) {
				$step['message']    = isset($lib[$jk][$step['id']]) ? $lib[$jk][$step['id']] : '';
				$step['message_b']  = '';
				$step['ab_enabled'] = 0;
				$step['channel']    = 'sms';
			}
			unset($step);
		}
		unset($stage);
		return $stages;
	}

	/**
	 * Ensure each step has message/channel fields after upgrades.
	 */
	public static function normalize_stages($stages) {
		$defaults = self::default_stages();
		$out      = array();
		foreach ($defaults as $key => $def) {
			$posted = isset($stages[$key]) && is_array($stages[$key]) ? $stages[$key] : array();
			$out[$key] = array(
				'enabled' => isset($posted['enabled']) ? (!empty($posted['enabled']) ? 1 : 0) : $def['enabled'],
				'label'   => $def['label'],
				'icon'    => $def['icon'],
				'steps'   => array(),
			);
			foreach ($def['steps'] as $i => $step_def) {
				$ps = isset($posted['steps'][$i]) && is_array($posted['steps'][$i]) ? $posted['steps'][$i] : array();
				$unit = isset($ps['delay_unit']) ? sanitize_key($ps['delay_unit']) : $step_def['delay_unit'];
				if (!in_array($unit, array('minutes', 'hours', 'days'), true)) {
					$unit = $step_def['delay_unit'];
				}
				$channel = isset($ps['channel']) ? sanitize_key($ps['channel']) : $step_def['channel'];
				if (!in_array($channel, array('sms', 'email', 'both'), true)) {
					$channel = 'sms';
				}
				$msg_a = isset($ps['message']) ? sanitize_textarea_field($ps['message']) : $step_def['message'];
				$msg_b = isset($ps['message_b']) ? sanitize_textarea_field($ps['message_b']) : '';
				if (mb_strlen($msg_a) > 1000) {
					$msg_a = mb_substr($msg_a, 0, 1000);
				}
				if (mb_strlen($msg_b) > 1000) {
					$msg_b = mb_substr($msg_b, 0, 1000);
				}
				$out[$key]['steps'][] = array(
					'id'           => $step_def['id'],
					'label'        => $step_def['label'],
					'delay_amount' => isset($ps['delay_amount']) ? min(9999, absint($ps['delay_amount'])) : (int) $step_def['delay_amount'],
					'delay_unit'   => $unit,
					'pattern_code' => isset($ps['pattern_code']) ? sanitize_text_field($ps['pattern_code']) : '',
					'enabled'      => isset($ps['enabled']) ? (!empty($ps['enabled']) ? 1 : 0) : $step_def['enabled'],
					'message'      => $msg_a,
					'message_b'    => $msg_b,
					'ab_enabled'   => !empty($ps['ab_enabled']) ? 1 : 0,
					'channel'      => $channel,
				);
			}
		}
		return $out;
	}

	public static function update($data) {
		$merged = self::merge_recursive(self::get(), $data);
		if (isset($data['stages'])) {
			$merged['stages'] = self::normalize_stages($data['stages']);
		}
		$to_store = $merged;
		foreach (self::SECRET_KEYS as $sk) {
			if (!empty($to_store[$sk])) {
				$to_store[$sk] = Ciwa_Security::encrypt($to_store[$sk]);
			}
		}
		update_option(CIWA_OPTION, $to_store, false);
		self::$cache_settings = $merged;
		return $merged;
	}

	public static function setup() {
		if (null !== self::$cache_setup) {
			return self::$cache_setup;
		}
		$s = get_option(CIWA_SETUP_OPTION, array());
		self::$cache_setup = wp_parse_args(is_array($s) ? $s : array(), array(
			'wizard_step'       => 1,
			'welcome_seen'      => 0,
			'engine_chosen'     => 0,
			'flow_built'        => 0,
			'test_ok'           => 0,
			'production_active' => 0,
			'activated_at'      => '',
			'last_test_report'  => array(),
		));
		return self::$cache_setup;
	}

	public static function update_setup($data) {
		$merged = array_merge(self::setup(), $data);
		update_option(CIWA_SETUP_OPTION, $merged, false);
		self::$cache_setup = $merged;
		return $merged;
	}

	public static function normalize_n8n_base_url($url) {
		$url = trim((string) $url);
		if ('' === $url) {
			return '';
		}
		$url = untrailingslashit($url);
		$url = preg_replace('#/(webhook-test|webhook-waiting|webhook)(/.*)?$#i', '', $url);
		$url = untrailingslashit($url);

		$parts = wp_parse_url($url);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return '';
		}

		$base = $parts['scheme'] . '://' . $parts['host'];
		if (!empty($parts['port'])) {
			$base .= ':' . (int) $parts['port'];
		}
		if (!empty($parts['path']) && '/' !== $parts['path']) {
			$path = untrailingslashit($parts['path']);
			if ($path && !preg_match('#/(webhook|webhook-test)#i', $path)) {
				$base .= $path;
			}
		}
		$validated = Ciwa_Security::validate_outbound_url($base);
		return is_wp_error($validated) ? '' : $validated;
	}

	public static function active_n8n_url() {
		$s = self::get();
		$setup = self::setup();
		if (!empty($setup['production_active']) && !empty($s['n8n_prod_url'])) {
			return self::normalize_n8n_base_url($s['n8n_prod_url']);
		}
		return self::normalize_n8n_base_url((string) $s['n8n_test_url']);
	}

	public static function webhook_paths() {
		return array(
			'customer' => 'ciwa-customer-created',
			'order'    => 'ciwa-order-updated',
			'cart'     => 'ciwa-cart-abandoned',
			'ping'     => 'ciwa-ping',
		);
	}

	public static function n8n_webhook_url($path, $mode = 'active', $channel = 'webhook') {
		$s = self::get();
		if ('test' === $mode) {
			$base = self::normalize_n8n_base_url((string) $s['n8n_test_url']);
		} elseif ('prod' === $mode) {
			$base = self::normalize_n8n_base_url((string) $s['n8n_prod_url']);
		} else {
			$base = self::active_n8n_url();
		}
		if ('' === $base) {
			return '';
		}
		$channel = ('webhook-test' === $channel) ? 'webhook-test' : 'webhook';
		return $base . '/' . $channel . '/' . ltrim($path, '/');
	}

	private static function merge_recursive($base, $over) {
		foreach ($over as $k => $v) {
			if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && self::is_assoc($v)) {
				$base[$k] = self::merge_recursive($base[$k], $v);
			} else {
				$base[$k] = $v;
			}
		}
		return $base;
	}

	private static function is_assoc($arr) {
		return is_array($arr) && array() !== $arr && array_keys($arr) !== range(0, count($arr) - 1);
	}
}
