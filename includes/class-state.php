<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_State {
	const META_STATE    = '_ciwa_journey_state';
	const META_TOKEN    = '_ciwa_journey_token';
	const META_EVENT_AT = '_ciwa_journey_event_at';
	const META_ORDER_ID = '_ciwa_journey_order_id';

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function new_token() {
		return wp_generate_password(20, false, false);
	}

	public static function set_customer_state($customer_id, $state, $token = null, $order_id = 0) {
		$customer_id = absint($customer_id);
		if (!$customer_id) {
			return '';
		}
		$token = $token ? $token : self::new_token();
		update_user_meta($customer_id, self::META_STATE, sanitize_key($state));
		update_user_meta($customer_id, self::META_TOKEN, sanitize_text_field($token));
		update_user_meta($customer_id, self::META_EVENT_AT, gmdate('c'));
		if ($order_id) {
			update_user_meta($customer_id, self::META_ORDER_ID, absint($order_id));
		}
		return $token;
	}

	public static function set_order_state($order_id, $state, $token = null) {
		$order = wc_get_order($order_id);
		if (!$order) {
			return '';
		}
		$token = $token ? $token : self::new_token();
		$order->update_meta_data(self::META_STATE, sanitize_key($state));
		$order->update_meta_data(self::META_TOKEN, sanitize_text_field($token));
		$order->update_meta_data(self::META_EVENT_AT, gmdate('c'));
		$order->update_meta_data(self::META_ORDER_ID, absint($order_id));
		$order->save();
		if ($order->get_user_id()) {
			self::set_customer_state($order->get_user_id(), $state, $token, $order_id);
		}
		return $token;
	}

	public static function get_customer_state($customer_id) {
		return (string) get_user_meta(absint($customer_id), self::META_STATE, true);
	}

	public static function get_customer_token($customer_id) {
		return (string) get_user_meta(absint($customer_id), self::META_TOKEN, true);
	}
}
