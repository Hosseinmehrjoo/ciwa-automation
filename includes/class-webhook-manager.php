<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_Webhook_Manager {
	const OPTION_IDS = 'ciwa_webhook_ids';

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function sync_webhooks($mode = 'prod') {
		$base = ('test' === $mode)
			? untrailingslashit((string) Ciwa_Settings::get()['n8n_test_url'])
			: untrailingslashit((string) Ciwa_Settings::get()['n8n_prod_url']);

		if ('' === $base) {
			return new WP_Error('no_n8n', 'آدرس n8n تنظیم نشده است.');
		}

		$paths = Ciwa_Settings::webhook_paths();
		$map   = array(
			'customer' => array(
				'name'  => 'CIWA — Customer Created',
				'topic' => 'customer.created',
				'url'   => $base . '/webhook/' . $paths['customer'],
			),
			'order' => array(
				'name'  => 'CIWA — Order Updated',
				'topic' => 'order.updated',
				'url'   => $base . '/webhook/' . $paths['order'],
			),
		);

		$existing = get_option(self::OPTION_IDS, array());
		if (!is_array($existing)) {
			$existing = array();
		}

		foreach ($map as $key => $cfg) {
			$id      = isset($existing[$key]) ? absint($existing[$key]) : 0;
			$webhook = $id ? new WC_Webhook($id) : new WC_Webhook();
			if ($id && !$webhook->get_id()) {
				$webhook = new WC_Webhook();
			}
			$webhook->set_name($cfg['name']);
			$webhook->set_topic($cfg['topic']);
			$webhook->set_delivery_url($cfg['url']);
			$webhook->set_status('active');
			$webhook->set_api_version(3);
			$webhook->set_user_id(get_current_user_id() ? get_current_user_id() : 1);
			$webhook->save();
			$existing[$key] = $webhook->get_id();
		}

		update_option(self::OPTION_IDS, $existing);
		Ciwa_Logger::success('webhook', 'وبهوک‌های ووکامرس همگام‌سازی شدند.', array('mode' => $mode, 'base' => $base));
		return true;
	}

	public function remove_webhooks() {
		$existing = get_option(self::OPTION_IDS, array());
		if (!is_array($existing)) {
			return;
		}
		foreach ($existing as $id) {
			$id = absint($id);
			if (!$id) {
				continue;
			}
			$webhook = new WC_Webhook($id);
			if ($webhook->get_id()) {
				$webhook->delete(true);
			}
		}
		delete_option(self::OPTION_IDS);
		Ciwa_Logger::warning('webhook', 'وبهوک‌های CIWA حذف شدند.');
	}

	public function get_status() {
		$existing = get_option(self::OPTION_IDS, array());
		$out      = array();
		if (!is_array($existing)) {
			return $out;
		}
		foreach ($existing as $key => $id) {
			$webhook   = new WC_Webhook(absint($id));
			$out[$key] = array(
				'id'     => absint($id),
				'status' => $webhook->get_id() ? $webhook->get_status() : 'missing',
				'url'    => $webhook->get_id() ? $webhook->get_delivery_url() : '',
				'topic'  => $webhook->get_id() ? $webhook->get_topic() : '',
			);
		}
		return $out;
	}
}
