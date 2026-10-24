<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_Abandoned_Cart {
	const HOOK = 'ciwa_send_abandoned_cart';

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('woocommerce_add_to_cart', array($this, 'schedule'), 20);
		add_action('woocommerce_cart_item_removed', array($this, 'schedule'), 20);
		add_action('woocommerce_after_cart_item_quantity_update', array($this, 'schedule'), 20);
		add_action(self::HOOK, array($this, 'send'), 10, 1);
		add_action('woocommerce_checkout_order_processed', array($this, 'clear_from_order'), 10, 1);
		add_action('woocommerce_thankyou', array($this, 'clear_from_order'), 10, 1);
	}

	private function is_active() {
		$setup = Ciwa_Settings::setup();
		$s     = Ciwa_Settings::get();
		if (empty($setup['production_active']) || empty($s['stages']['cart']['enabled'])) {
			return false;
		}
		if (Ciwa_Engine::is_internal()) {
			return true;
		}
		if (Ciwa_Engine::is_n8n()) {
			return (bool) Ciwa_Settings::active_n8n_url();
		}
		return false;
	}

	public function schedule() {
		if (!$this->is_active() || !is_user_logged_in()) {
			return;
		}
		if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
			$this->clear_for_user(get_current_user_id());
			return;
		}
		$user_id = get_current_user_id();
		$minutes = max(5, absint(Ciwa_Settings::get()['cart_abandon_minutes']));
		$this->clear_for_user($user_id);
		wp_schedule_single_event(time() + ($minutes * MINUTE_IN_SECONDS), self::HOOK, array($user_id));
	}

	public function clear_from_order($order_id) {
		$order = wc_get_order($order_id);
		if ($order && $order->get_user_id()) {
			$this->clear_for_user($order->get_user_id());
		}
	}

	public function clear_for_user($user_id) {
		$user_id = absint($user_id);
		if ($user_id) {
			wp_clear_scheduled_hook(self::HOOK, array($user_id));
		}
	}

	public function send($user_id) {
		$user_id = absint($user_id);
		if (!$user_id || !$this->is_active()) {
			return;
		}
		$user = get_user_by('id', $user_id);
		if (!$user) {
			return;
		}

		$paid = wc_get_orders(array(
			'customer_id' => $user_id,
			'status'      => array('processing', 'completed'),
			'limit'       => 1,
			'return'      => 'ids',
		));
		if (!empty($paid)) {
			return;
		}

		$state = Ciwa_State::get_customer_state($user_id);
		if (in_array($state, array('checkout_pending', 'payment_failed', 'purchased', 'exited_payment_failed'), true)) {
			return;
		}

		$phone = get_user_meta($user_id, 'billing_phone', true);
		$payload = array(
			'customer_id' => $user_id,
			'phone'       => $phone,
			'first_name'  => $user->first_name ? $user->first_name : get_user_meta($user_id, 'billing_first_name', true),
			'email'       => $user->user_email,
			'cart_url'    => wc_get_cart_url(),
			'event_at'    => gmdate('c'),
		);

		if (Ciwa_Engine::is_internal()) {
			Ciwa_Internal_Runner::instance()->start_cart_abandoned($user_id, $payload);
			Ciwa_Logger::info('cart', 'سبد رها‌شده در صف موتور داخلی قرار گرفت.', array('user_id' => $user_id));
			return;
		}

		$token = Ciwa_State::set_customer_state($user_id, 'cart_abandoned');
		$url   = Ciwa_Settings::n8n_webhook_url(Ciwa_Settings::webhook_paths()['cart']);
		$payload['token'] = $token;
		$payload['state'] = 'cart_abandoned';

		$response = wp_remote_post($url, array(
			'timeout' => 20,
			'headers' => array('Content-Type' => 'application/json'),
			'body'    => wp_json_encode($payload),
		));

		if (is_wp_error($response)) {
			Ciwa_Logger::error('cart', 'ارسال سبد رها‌شده به n8n ناموفق بود.', array('error' => $response->get_error_message()));
		} else {
			Ciwa_Logger::info('cart', 'سبد رها‌شده به n8n ارسال شد.', array('user_id' => $user_id));
		}
	}
}
