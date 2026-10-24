<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Internal automation engine: WC hooks → queue → cron dispatch.
 */
class Ciwa_Internal_Runner {
	const CRON_HOOK = 'ciwa_process_queue';

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('user_register', array($this, 'on_register'), 20, 1);
		add_action('woocommerce_order_status_changed', array($this, 'on_order_status'), 20, 4);
		add_action('woocommerce_checkout_order_processed', array($this, 'on_order_created'), 20, 1);
		add_action('woocommerce_thankyou', array($this, 'on_paid_thankyou'), 5, 1);
		add_action(self::CRON_HOOK, array($this, 'process_queue'));
		add_action('init', array($this, 'ensure_cron'));
		add_filter('cron_schedules', array($this, 'schedules'));
	}

	public function schedules($schedules) {
		if (!isset($schedules['ciwa_every_minute'])) {
			$schedules['ciwa_every_minute'] = array(
				'interval' => 60,
				'display'  => 'CIWA every minute',
			);
		}
		return $schedules;
	}

	public function ensure_cron() {
		if (!Ciwa_Engine::is_internal() || !Ciwa_Engine::is_active()) {
			self::unschedule_cron();
			return;
		}
		// Avoid wp_next_scheduled() on every front request — check at most once per 10 minutes.
		if (get_transient('ciwa_cron_checked')) {
			return;
		}
		set_transient('ciwa_cron_checked', 1, 10 * MINUTE_IN_SECONDS);
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + 30, 'ciwa_every_minute', self::CRON_HOOK);
		}
	}

	public static function unschedule_cron() {
		$ts = wp_next_scheduled(self::CRON_HOOK);
		while ($ts) {
			wp_unschedule_event($ts, self::CRON_HOOK);
			$ts = wp_next_scheduled(self::CRON_HOOK);
		}
	}

	private function active() {
		return Ciwa_Engine::is_internal() && Ciwa_Engine::is_active();
	}

	public function on_register($user_id) {
		if (!$this->active()) {
			return;
		}
		$user_id = absint($user_id);
		$check = Ciwa_Segments::allows('register', array('user_id' => $user_id));
		if (is_wp_error($check)) {
			return;
		}
		$token = Ciwa_State::set_customer_state($user_id, 'registered');
		$this->schedule_journey('register', array(
			'user_id' => $user_id,
			'token'   => $token,
		));
	}

	public function on_order_created($order_id) {
		if (!$this->active()) {
			return;
		}
		$order = wc_get_order($order_id);
		if (!$order) {
			return;
		}
		$status = $order->get_status();
		if (in_array($status, array('pending', 'on-hold'), true)) {
			$this->start_pending($order);
		} elseif (in_array($status, array('failed'), true)) {
			$this->start_failed($order);
		} elseif (in_array($status, array('processing', 'completed'), true)) {
			$this->start_purchase($order);
		}
	}

	public function on_order_status($order_id, $old, $new, $order) {
		if (!$this->active() || !$order) {
			return;
		}
		if (in_array($new, array('pending', 'on-hold'), true) && !in_array($old, array('pending', 'on-hold'), true)) {
			$this->start_pending($order);
		} elseif ('failed' === $new) {
			$this->start_failed($order);
		} elseif (in_array($new, array('processing', 'completed'), true)) {
			$this->start_purchase($order);
		} elseif (in_array($new, array('cancelled', 'refunded'), true)) {
			$user_id = $order->get_user_id();
			if ($user_id) {
				$old_token = Ciwa_State::get_customer_token($user_id);
				if ($old_token) {
					Ciwa_Queue::cancel_by_token($old_token);
				}
			}
		}
	}

	public function on_paid_thankyou($order_id) {
		if (!$this->active()) {
			return;
		}
		$order = wc_get_order($order_id);
		if ($order && in_array($order->get_status(), array('processing', 'completed'), true)) {
			Ciwa_Roi::maybe_attribute_order($order_id);
		}
	}

	private function start_pending($order) {
		$check = Ciwa_Segments::allows('pending', array(
			'user_id'  => $order->get_user_id(),
			'order'    => $order,
			'order_id' => $order->get_id(),
		));
		if (is_wp_error($check)) {
			return;
		}
		$this->cancel_previous($order);
		$token = Ciwa_State::set_order_state($order->get_id(), 'checkout_pending');
		$this->schedule_journey('pending', array(
			'user_id'  => $order->get_user_id(),
			'order_id' => $order->get_id(),
			'token'    => $token,
		));
	}

	private function start_failed($order) {
		$check = Ciwa_Segments::allows('failed', array(
			'user_id'  => $order->get_user_id(),
			'order'    => $order,
			'order_id' => $order->get_id(),
		));
		if (is_wp_error($check)) {
			return;
		}
		$this->cancel_previous($order);
		$token = Ciwa_State::set_order_state($order->get_id(), 'payment_failed');
		$this->schedule_journey('failed', array(
			'user_id'  => $order->get_user_id(),
			'order_id' => $order->get_id(),
			'token'    => $token,
		));
	}

	private function start_purchase($order) {
		$check = Ciwa_Segments::allows('purchase', array(
			'user_id'  => $order->get_user_id(),
			'order'    => $order,
			'order_id' => $order->get_id(),
		));
		if (is_wp_error($check)) {
			return;
		}
		$this->cancel_previous($order);
		Ciwa_Roi::maybe_attribute_order($order->get_id());
		$token = Ciwa_State::set_order_state($order->get_id(), 'purchased');
		$this->schedule_journey('purchase', array(
			'user_id'  => $order->get_user_id(),
			'order_id' => $order->get_id(),
			'token'    => $token,
		));
	}

	private function cancel_previous($order) {
		$user_id = $order->get_user_id();
		if ($user_id) {
			$old = Ciwa_State::get_customer_token($user_id);
			if ($old) {
				Ciwa_Queue::cancel_by_token($old);
			}
		}
		$token = $order->get_meta(Ciwa_State::META_TOKEN);
		if ($token) {
			Ciwa_Queue::cancel_by_token($token);
		}
	}

	/**
	 * Called from abandoned cart when internal mode.
	 */
	public function start_cart_abandoned($user_id, $payload = array()) {
		if (!$this->active()) {
			return;
		}
		$user_id = absint($user_id);
		$check = Ciwa_Segments::allows('cart', array('user_id' => $user_id));
		if (is_wp_error($check)) {
			return;
		}
		$old = Ciwa_State::get_customer_token($user_id);
		if ($old) {
			Ciwa_Queue::cancel_by_token($old);
		}
		$token = Ciwa_State::set_customer_state($user_id, 'cart_abandoned');
		$this->schedule_journey('cart', array_merge($payload, array(
			'user_id' => $user_id,
			'token'   => $token,
		)));
	}

	/**
	 * Schedule all enabled steps for a journey.
	 */
	public function schedule_journey($journey_key, $ctx) {
		$settings = Ciwa_Settings::get();
		if (empty($settings['stages'][$journey_key]['enabled'])) {
			return;
		}
		$stage = $settings['stages'][$journey_key];
		$base  = current_time('timestamp');
		$cum   = 0;

		foreach ($stage['steps'] as $step) {
			if (empty($step['enabled'])) {
				continue;
			}
			$cum += self::delay_seconds($step);
			$when = Ciwa_Compliance::next_allowed_timestamp($base + $cum);
			$pick = Ciwa_Messages::pick_variant($step);
			$channel = isset($step['channel']) ? sanitize_key($step['channel']) : 'sms';
			if (!in_array($channel, array('sms', 'email', 'both'), true)) {
				$channel = 'sms';
			}

			$contacts = $this->resolve_contacts($ctx);
			$recipient = ('email' === $channel) ? $contacts['email'] : $contacts['phone'];
			if ('both' === $channel) {
				$recipient = $contacts['phone'] ? $contacts['phone'] : $contacts['email'];
			}

			Ciwa_Queue::enqueue(array(
				'scheduled_at' => wp_date('Y-m-d H:i:s', $when),
				'journey_key'  => $journey_key,
				'step_id'      => $step['id'],
				'channel'      => $channel,
				'recipient'    => $recipient,
				'token'        => isset($ctx['token']) ? $ctx['token'] : '',
				'ab_variant'   => $pick['variant'],
				'payload'      => array(
					'user_id'      => isset($ctx['user_id']) ? absint($ctx['user_id']) : 0,
					'order_id'     => isset($ctx['order_id']) ? absint($ctx['order_id']) : 0,
					'message'      => $pick['text'],
					'pattern_code' => isset($step['pattern_code']) ? $step['pattern_code'] : '',
					'phone'        => $contacts['phone'],
					'email'        => $contacts['email'],
					'first_name'   => $contacts['first_name'],
					'last_name'    => $contacts['last_name'],
					'cart_url'     => isset($ctx['cart_url']) ? $ctx['cart_url'] : (function_exists('wc_get_cart_url') ? wc_get_cart_url() : ''),
				),
			));
		}
		Ciwa_Logger::info('engine', 'سفر زمان‌بندی شد.', array(
			'journey' => $journey_key,
			'user_id' => isset($ctx['user_id']) ? $ctx['user_id'] : 0,
			'order'   => isset($ctx['order_id']) ? $ctx['order_id'] : 0,
		));
	}

	private function resolve_contacts($ctx) {
		$user_id  = isset($ctx['user_id']) ? absint($ctx['user_id']) : 0;
		$order_id = isset($ctx['order_id']) ? absint($ctx['order_id']) : 0;
		$phone = $email = $first = $last = '';

		if ($order_id) {
			$order = wc_get_order($order_id);
			if ($order) {
				$phone = $order->get_billing_phone();
				$email = $order->get_billing_email();
				$first = $order->get_billing_first_name();
				$last  = $order->get_billing_last_name();
				if (!$user_id) {
					$user_id = $order->get_user_id();
				}
			}
		}
		if ($user_id) {
			$user = get_user_by('id', $user_id);
			if ($user) {
				if (!$email) {
					$email = $user->user_email;
				}
				if (!$first) {
					$first = $user->first_name ? $user->first_name : get_user_meta($user_id, 'billing_first_name', true);
				}
				if (!$last) {
					$last = $user->last_name ? $user->last_name : get_user_meta($user_id, 'billing_last_name', true);
				}
				if (!$phone) {
					$phone = get_user_meta($user_id, 'billing_phone', true);
				}
			}
		}
		return array(
			'phone'      => $phone,
			'email'      => $email,
			'first_name' => $first,
			'last_name'  => $last,
			'user_id'    => $user_id,
		);
	}

	public static function delay_seconds($step) {
		$amount = isset($step['delay_amount']) ? absint($step['delay_amount']) : 0;
		$unit   = isset($step['delay_unit']) ? $step['delay_unit'] : 'minutes';
		switch ($unit) {
			case 'days':
				return $amount * DAY_IN_SECONDS;
			case 'hours':
				return $amount * HOUR_IN_SECONDS;
			default:
				return $amount * MINUTE_IN_SECONDS;
		}
	}

	public function process_queue() {
		if (!Ciwa_Engine::is_internal() || !Ciwa_Engine::is_active()) {
			return;
		}
		// Prevent overlapping cron/ajax runs.
		if (get_transient('ciwa_queue_lock')) {
			return;
		}
		set_transient('ciwa_queue_lock', 1, 50);
		try {
			$jobs = Ciwa_Queue::claim_due(10);
			foreach ($jobs as $job) {
				$this->run_job($job);
			}
		} finally {
			delete_transient('ciwa_queue_lock');
		}
	}

	private function run_job($job) {
		$payload = json_decode($job->payload, true);
		if (!is_array($payload)) {
			$payload = array();
		}

		// Token still valid?
		$token = (string) $job->token;
		if ($token) {
			$user_id = isset($payload['user_id']) ? absint($payload['user_id']) : 0;
			$order_id = isset($payload['order_id']) ? absint($payload['order_id']) : 0;
			$current = '';
			if ($order_id) {
				$order = wc_get_order($order_id);
				if ($order) {
					$current = (string) $order->get_meta(Ciwa_State::META_TOKEN);
					// Cancel journey if order already paid for pending/failed/cart
					if (in_array($job->journey_key, array('pending', 'failed', 'cart'), true)
						&& in_array($order->get_status(), array('processing', 'completed'), true)) {
						Ciwa_Queue::mark_failed($job->id, 'سفارش پرداخت شده — لغو', false);
						Ciwa_Queue::cancel_by_token($token);
						return;
					}
				}
			}
			if (!$current && $user_id) {
				$current = Ciwa_State::get_customer_token($user_id);
			}
			if ($current && $current !== $token) {
				Ciwa_Queue::mark_failed($job->id, 'توکن سفر منقضی شده', false);
				return;
			}
		}

		$phone = isset($payload['phone']) ? $payload['phone'] : $job->recipient;
		if (Ciwa_Compliance::is_blacklisted($phone)) {
			Ciwa_Queue::mark_failed($job->id, 'لیست سیاه', false);
			return;
		}

		// Quiet hours: reschedule
		$allowed = Ciwa_Compliance::next_allowed_timestamp(current_time('timestamp'));
		if ($allowed > current_time('timestamp') + 30) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				Ciwa_Queue::table(),
				array(
					'status'       => Ciwa_Queue::STATUS_PENDING,
					'scheduled_at' => wp_date('Y-m-d H:i:s', $allowed),
					'attempts'     => max(0, (int) $job->attempts - 1),
				),
				array('id' => (int) $job->id),
				array('%s', '%s', '%d'),
				array('%d')
			);
			return;
		}

		$ctx = $this->build_render_context($payload);
		$message = Ciwa_Messages::render(isset($payload['message']) ? $payload['message'] : '', $ctx);
		$pattern = !empty($payload['pattern_code']) ? $payload['pattern_code'] : null;

		$result = Ciwa_Channels::send($job->channel, array(
			'phone'   => $phone,
			'email'   => isset($payload['email']) ? $payload['email'] : '',
			'message' => $message,
			'pattern' => $pattern,
			'subject' => sprintf('پیام از %s', $ctx['site_name']),
		));

		if (!empty($result['ok'])) {
			Ciwa_Queue::mark_sent($job->id);
			Ciwa_Roi::track_sms_sent($job->journey_key);
			Ciwa_Roi::track_ab($job->ab_variant, $job->journey_key);
			if (in_array($job->journey_key, array('cart', 'failed', 'pending'), true)) {
				$user_id = isset($payload['user_id']) ? absint($payload['user_id']) : 0;
				if ($user_id) {
					Ciwa_Roi::mark_touch($user_id, $job->journey_key, isset($payload['order_id']) ? absint($payload['order_id']) : 0);
				}
			}
			Ciwa_Logger::success('engine', 'پیام ارسال شد.', array(
				'job'     => (int) $job->id,
				'journey' => $job->journey_key,
				'step'    => $job->step_id,
				'channel' => $job->channel,
				'ab'      => $job->ab_variant,
			));
		} else {
			Ciwa_Queue::mark_failed($job->id, $result['detail'], true);
			Ciwa_Logger::error('engine', 'ارسال پیام ناموفق.', array(
				'job'    => (int) $job->id,
				'detail' => $result['detail'],
			));
		}
	}

	private function build_render_context($payload) {
		$order_id = isset($payload['order_id']) ? absint($payload['order_id']) : 0;
		$ctx = array(
			'first_name'  => isset($payload['first_name']) ? $payload['first_name'] : '',
			'last_name'   => isset($payload['last_name']) ? $payload['last_name'] : '',
			'phone'       => isset($payload['phone']) ? $payload['phone'] : '',
			'email'       => isset($payload['email']) ? $payload['email'] : '',
			'order_id'    => $order_id ? (string) $order_id : '',
			'order_total' => '',
			'cart_url'    => isset($payload['cart_url']) ? $payload['cart_url'] : '',
			'pay_url'     => '',
		);
		if ($order_id) {
			$order = wc_get_order($order_id);
			if ($order) {
				$ctx['order_total'] = html_entity_decode(wp_strip_all_tags($order->get_formatted_order_total()), ENT_QUOTES, 'UTF-8');
				$ctx['pay_url']     = $order->get_checkout_payment_url();
				if (!$ctx['first_name']) {
					$ctx['first_name'] = $order->get_billing_first_name();
				}
			}
		}
		return $ctx;
	}
}
