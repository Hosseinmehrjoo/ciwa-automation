<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Factory-reset plugin data (options, logs, webhooks, journey meta).
 */
class Ciwa_Reset {

	/**
	 * Wipe plugin state as if freshly installed.
	 *
	 * @return array{ok:bool,details:array}
	 */
	public static function run() {
		$details = array();

		// 1) Remove WooCommerce webhooks created by CIWA
		if (class_exists('WooCommerce') && class_exists('Ciwa_Webhook_Manager')) {
			Ciwa_Webhook_Manager::instance()->remove_webhooks();
			$details[] = 'webhooks_removed';
		}

		// 2) Clear abandoned-cart cron hooks (best-effort for known users is heavy; clear option only)
		$details[] = 'cron_note';

		// 3) Truncate / recreate logs + queue tables
		global $wpdb;
		$table = Ciwa_Logger::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		if ($exists) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query("TRUNCATE TABLE `{$table}`");
			$details[] = 'logs_truncated';
		} else {
			Ciwa_Logger::create_table();
			$details[] = 'logs_created';
		}
		Ciwa_Queue::create_table();
		Ciwa_Queue::truncate();
		Ciwa_Roi::reset();
		Ciwa_Internal_Runner::unschedule_cron();
		$details[] = 'queue_reset';

		// 4) Delete journey meta from users
		$meta_keys = array(
			Ciwa_State::META_STATE,
			Ciwa_State::META_TOKEN,
			Ciwa_State::META_EVENT_AT,
			Ciwa_State::META_ORDER_ID,
		);
		foreach ($meta_keys as $key) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete($wpdb->usermeta, array('meta_key' => $key), array('%s'));
		}
		$details[] = 'user_meta_cleared';

		// 5) Delete journey meta from orders (postmeta + HPOS if present)
		foreach ($meta_keys as $key) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete($wpdb->postmeta, array('meta_key' => $key), array('%s'));
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete($wpdb->postmeta, array('meta_key' => '_ciwa_test_order'), array('%s'));
		$details[] = 'order_postmeta_cleared';

		// HPOS orders meta table
		$orders_meta = $wpdb->prefix . 'wc_orders_meta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_meta)) === $orders_meta) {
			foreach (array_merge($meta_keys, array('_ciwa_test_order')) as $key) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete($orders_meta, array('meta_key' => $key), array('%s'));
			}
			$details[] = 'hpos_meta_cleared';
		}

		// 6) Delete all plugin options
		$options = array(
			CIWA_OPTION,
			CIWA_SETUP_OPTION,
			Ciwa_Api_Keys::OPTION_KEY_ID,
			Ciwa_Webhook_Manager::OPTION_IDS,
			'ciwa_activation_redirect',
		);
		foreach ($options as $opt) {
			delete_option($opt);
		}
		delete_transient('ciwa_activation_redirect');
		$details[] = 'options_deleted';

		// 7) Recreate fresh defaults (like first install)
		add_option(CIWA_OPTION, Ciwa_Settings::defaults());
		add_option(CIWA_SETUP_OPTION, array(
			'wizard_step'       => 1,
			'welcome_seen'      => 0,
			'engine_chosen'     => 0,
			'flow_built'        => 0,
			'test_ok'           => 0,
			'production_active' => 0,
			'activated_at'      => gmdate('c'),
			'last_test_report'  => array(),
		));
		$details[] = 'defaults_restored';

		// Log after table exists (will be first log after reset)
		Ciwa_Logger::info('plugin', 'تمام اطلاعات افزونه پاک و به حالت نصب اولیه برگشت.');

		return array(
			'ok'      => true,
			'details' => $details,
		);
	}
}
