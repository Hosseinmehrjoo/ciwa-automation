<?php
if (!defined('ABSPATH')) {
	exit;
}

final class Ciwa_Plugin {

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('init', array($this, 'boot'), 5);
		add_action('admin_init', array($this, 'maybe_redirect'));
	}

	public function boot() {
		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', static function () {
				echo '<div class="notice notice-error"><p><strong>CIWA Automation:</strong> برای کار کردن این افزونه باید ووکامرس نصب و فعال باشد.</p></div>';
			});
			if (is_admin()) {
				Ciwa_Admin::instance();
			}
			return;
		}

		// Lightweight runtime: only modules that register front/shop hooks.
		Ciwa_Abandoned_Cart::instance();

		if (Ciwa_Engine::is_internal()) {
			Ciwa_Internal_Runner::instance();
		}

		if (is_admin()) {
			Ciwa_Admin::instance();
		}
	}

	public function maybe_redirect() {
		if (!get_transient('ciwa_activation_redirect')) {
			return;
		}
		delete_transient('ciwa_activation_redirect');
		if (wp_doing_ajax() || (isset($_GET['activate-multi']))) {
			return;
		}
		if (!current_user_can('manage_woocommerce') && !current_user_can('activate_plugins')) {
			return;
		}
		wp_safe_redirect(admin_url('admin.php?page=ciwa-automation&tab=welcome'));
		exit;
	}
}
