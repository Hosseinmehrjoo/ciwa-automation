<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Facade for execution mode: internal WordPress engine vs external n8n.
 */
class Ciwa_Engine {

	const MODE_INTERNAL = 'internal';
	const MODE_N8N      = 'n8n';

	public static function mode() {
		$s = Ciwa_Settings::get();
		$mode = isset($s['engine_mode']) ? sanitize_key($s['engine_mode']) : '';
		if (self::MODE_INTERNAL === $mode || self::MODE_N8N === $mode) {
			return $mode;
		}
		return '';
	}

	public static function is_chosen() {
		return '' !== self::mode();
	}

	public static function is_internal() {
		return self::MODE_INTERNAL === self::mode();
	}

	public static function is_n8n() {
		return self::MODE_N8N === self::mode();
	}

	public static function is_active() {
		$setup = Ciwa_Settings::setup();
		return !empty($setup['production_active']) && self::is_chosen();
	}

	public static function label() {
		if (self::is_internal()) {
			return 'موتور داخلی';
		}
		if (self::is_n8n()) {
			return 'n8n خارجی';
		}
		return 'انتخاب نشده';
	}

	/**
	 * @param string $mode internal|n8n
	 */
	public static function set_mode($mode) {
		$mode = sanitize_key($mode);
		if (self::MODE_INTERNAL !== $mode && self::MODE_N8N !== $mode) {
			return new WP_Error('bad_mode', 'حالت اجرای نامعتبر است.');
		}
		Ciwa_Settings::update(array('engine_mode' => $mode));
		Ciwa_Settings::update_setup(array(
			'engine_chosen' => 1,
			'wizard_step'   => 1,
		));
		Ciwa_Logger::info('setup', 'حالت اجرا انتخاب شد: ' . $mode);
		return true;
	}
}
