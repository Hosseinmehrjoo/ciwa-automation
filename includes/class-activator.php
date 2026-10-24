<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_Activator {

	public static function activate() {
		Ciwa_Logger::create_table();
		Ciwa_Queue::create_table();

		if (false === get_option(CIWA_OPTION, false)) {
			add_option(CIWA_OPTION, Ciwa_Settings::defaults());
		}
		if (false === get_option(CIWA_SETUP_OPTION, false)) {
			add_option(CIWA_SETUP_OPTION, array(
				'wizard_step'       => 1,
				'welcome_seen'      => 0,
				'engine_chosen'     => 0,
				'flow_built'        => 0,
				'test_ok'           => 0,
				'production_active' => 0,
				'activated_at'      => gmdate('c'),
			));
		}

		set_transient('ciwa_activation_redirect', 1, 60);
		// Keep log table lean.
		if (class_exists('Ciwa_Logger')) {
			Ciwa_Logger::clear_older_than(90);
		}
		Ciwa_Logger::info('plugin', 'افزونه CIWA Automation نصب/فعال شد.', array('version' => CIWA_VERSION));
		flush_rewrite_rules();
	}

	public static function deactivate() {
		if (class_exists('Ciwa_Webhook_Manager')) {
			Ciwa_Webhook_Manager::instance()->remove_webhooks();
		}
		if (class_exists('Ciwa_Internal_Runner')) {
			Ciwa_Internal_Runner::unschedule_cron();
		}
		$setup = get_option(CIWA_SETUP_OPTION, array());
		$setup['production_active'] = 0;
		update_option(CIWA_SETUP_OPTION, $setup);
		Ciwa_Logger::warning('plugin', 'افزونه غیرفعال شد.');
		flush_rewrite_rules();
	}
}
