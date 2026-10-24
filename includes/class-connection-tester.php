<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_Connection_Tester {

	/**
	 * Full test against n8n test URL + Mediana + local WooCommerce.
	 *
	 * @return array{ok:bool,steps:array,summary:string}
	 */
	public function run($n8n_url = '') {
		$settings = Ciwa_Settings::get();
		$raw_input = $n8n_url ? $n8n_url : $settings['n8n_test_url'];
		$n8n_url   = Ciwa_Settings::normalize_n8n_base_url($raw_input);

		// Persist normalized URL so next time UI shows the correct base.
		if ($n8n_url && $n8n_url !== untrailingslashit((string) $raw_input)) {
			Ciwa_Settings::update(array('n8n_test_url' => $n8n_url));
		}

		$steps  = array();
		$all_ok = true;
		$n8n_ok = true;

		// 0) WordPress / WooCommerce REST API
		$wp_api  = $this->test_wp_api();
		$steps[] = $this->step('wp_api', 'دریافت API از وردپرس', $wp_api);
		if (!$wp_api['ok']) {
			$all_ok = false;
		}

		// 1) URL format
		$url_check = $this->validate_url($n8n_url);
		if ($n8n_url && false !== stripos((string) $raw_input, '/webhook')) {
			$url_check['detail'] = $n8n_url . ' (مسیر /webhook از آدرس حذف و نرمال شد)';
		}
		$steps[] = $this->step('url_format', 'بررسی فرمت آدرس n8n', $url_check);
		if (!$url_check['ok']) {
			$all_ok = false;
			$n8n_ok = false;
		}

		// 2) Reachability (any HTTP response from host = reachable)
		$reach = $this->ping_host($n8n_url);
		$steps[] = $this->step('reachability', 'برقراری ارتباط با سرور n8n', $reach);
		if (!$reach['ok']) {
			$all_ok = false;
			$n8n_ok = false;
			$report = $this->finish(false, $steps, 'اتصال برقرار نشد. لطفاً از صحت آدرس وارد شده و یا روشن بودن سرویس n8n اطمینان حاصل کنید.');
			Ciwa_Settings::update_setup(array('test_ok' => 0, 'last_test_report' => $report));
			Ciwa_Logger::error('test', $report['summary'], $report);
			return $report;
		}

		// 3) Webhook ping — try webhook-test then production webhook
		$ping_path = Ciwa_Settings::webhook_paths()['ping'];
		$ping      = $this->try_n8n_webhook($n8n_url, $ping_path, array(
			'source'  => 'ciwa-automation',
			'type'    => 'ping',
			'site'    => home_url(),
			'time'    => gmdate('c'),
			'version' => CIWA_VERSION,
		));
		$steps[] = $this->step('webhook_ping', 'ارسال داده به وبهوک تست', $ping);
		if (!$ping['ok']) {
			$all_ok = false;
			$n8n_ok = false;
		}
		$channel = !empty($ping['channel']) ? $ping['channel'] : 'webhook';

		// 4) Create test order + order webhook
		// Note: n8n /webhook-test/ only listens on ONE node at a time (the one with "Listen for test event").
		// If ping already succeeded via webhook-test, a 404 on order webhook is expected and must not fail the test.
		$order_result = $this->create_test_order();
		$steps[]      = $this->step('test_order', 'سفارش تست ایجاد شد', $order_result);
		if (!$order_result['ok']) {
			$all_ok = false;
		} else {
			$order_path = Ciwa_Settings::webhook_paths()['order'];
			$order_url  = $n8n_url . '/' . $channel . '/' . $order_path;
			$owh        = $this->post_json($order_url, isset($order_result['payload']) ? $order_result['payload'] : array());
			$owh_ok     = !empty($owh['ok']) || in_array((int) $owh['code'], array(200, 201, 204), true);

			if (!$owh_ok && 'webhook-test' === $channel && !empty($ping['ok'])) {
				$steps[] = $this->step(
					'order_webhook',
					'ارسال سفارش تست به n8n',
					array(
						'ok'     => true,
						'detail' => 'رد شد به‌صورت اختیاری: در حالت webhook-test فقط نودی که Listen شده جواب می‌دهد (الان Ping). '
							. 'چون Ping موفق بود، اتصال n8n تأیید است. برای تست سفارش، ورکفلو را Active کنید یا روی WH Order Updated هم Listen بزنید.',
						'code'   => (int) $owh['code'],
					)
				);
			} else {
				$steps[] = $this->step(
					'order_webhook',
					'ارسال سفارش تست به n8n',
					array(
						'ok'     => $owh_ok,
						'detail' => $owh_ok
							? ('ارسال شد از طریق /' . $channel . '/ (HTTP ' . (int) $owh['code'] . ')')
							: $this->webhook_fail_detail($owh, $order_url),
						'code'   => (int) $owh['code'],
					)
				);
				if (!$owh_ok) {
					$all_ok = false;
					$n8n_ok = false;
				}
			}
		}

		// 5) Test SMS — soft failure (does not block n8n activation if n8n is OK)
		$sms = $this->send_test_sms();
		$steps[] = $this->step('sms', 'پیامک به شماره مدیر ارسال شد', $sms);
		$sms_ok = !empty($sms['ok']);

		if ($n8n_ok && $wp_api['ok'] && $sms_ok) {
			$summary = 'اتصال برقرار شد — همه بررسی‌ها با موفقیت انجام شد.';
			$pass    = true;
		} elseif ($n8n_ok && $wp_api['ok']) {
			$summary = 'اتصال n8n برقرار شد، اما ارسال پیامک تست ناموفق بود: ' . (isset($sms['detail']) ? $sms['detail'] : '');
			// Allow proceeding to production for n8n; SMS can be fixed separately.
			$pass = true;
			$all_ok = false;
		} else {
			$failed = array();
			foreach ($steps as $s) {
				if (empty($s['ok'])) {
					$failed[] = $s['label'];
				}
			}
			$summary = 'برخی بررسی‌ها ناموفق بودند: ' . implode('، ', $failed);
			$pass    = false;
		}

		$report = $this->finish($pass, $steps, $summary);
		$report['n8n_ok'] = $n8n_ok;
		$report['sms_ok'] = $sms_ok;
		$report['all_ok'] = $all_ok;

		Ciwa_Settings::update_setup(array(
			'test_ok'          => $pass ? 1 : 0,
			'last_test_report' => $report,
		));
		if ($pass) {
			Ciwa_Logger::success('test', $summary, $report);
		} else {
			Ciwa_Logger::warning('test', $summary, $report);
		}
		return $report;
	}

	/**
	 * Try /webhook-test/ then /webhook/.
	 */
	private function try_n8n_webhook($base, $path, $payload) {
		$urls = array(
			'webhook-test' => $base . '/webhook-test/' . $path,
			'webhook'      => $base . '/webhook/' . $path,
		);

		$last = array('ok' => false, 'detail' => '', 'code' => 0);
		foreach ($urls as $channel => $url) {
			$res = $this->post_json($url, $payload);
			if (!empty($res['ok']) || in_array((int) $res['code'], array(200, 201, 204), true)) {
				return array(
					'ok'      => true,
					'detail'  => 'اطلاعات ارسال شد از طریق /' . $channel . '/ (HTTP ' . (int) $res['code'] . ')',
					'code'    => (int) $res['code'],
					'channel' => $channel,
					'url'     => $url,
				);
			}
			$last = $res;
			$last['url'] = $url;
			$last['channel'] = $channel;
		}

		return array(
			'ok'      => false,
			'detail'  => $this->webhook_fail_detail($last, isset($last['url']) ? $last['url'] : ''),
			'code'    => isset($last['code']) ? (int) $last['code'] : 0,
			'channel' => 'webhook',
		);
	}

	private function webhook_fail_detail($res, $url) {
		$code = isset($res['code']) ? (int) $res['code'] : 0;
		$msg  = isset($res['detail']) ? $res['detail'] : '';
		if (404 === $code) {
			return 'وبهوک پیدا نشد (HTTP 404). این کارها را انجام دهید: '
				. '۱) فقط آدرس پایه را وارد کنید مثل https://xxx.runflare.cloud '
				. '۲) JSON را در n8n ایمپورت کنید '
				. '۳) برای تست: روی نود WH Ping دکمه Listen for test event را بزنید، یا ورکفلو را Active کنید. '
				. ($url ? 'URL تست‌شده: ' . $url : '');
		}
		if (0 === $code) {
			return 'ارتباط برقرار نشد. ' . $msg;
		}
		return 'HTTP ' . $code . ($msg ? ' — ' . $msg : '');
	}

	private function finish($ok, $steps, $summary) {
		return array(
			'ok'        => (bool) $ok,
			'steps'     => $steps,
			'summary'   => $summary,
			'tested_at' => current_time('mysql'),
		);
	}

	private function step($id, $label, $result) {
		return array(
			'id'     => $id,
			'label'  => $label,
			'ok'     => !empty($result['ok']),
			'detail' => isset($result['detail']) ? $result['detail'] : '',
			'code'   => isset($result['code']) ? $result['code'] : null,
		);
	}

	private function test_wp_api() {
		$keys = Ciwa_Api_Keys::instance()->ensure_keys();
		if (is_wp_error($keys)) {
			return array(
				'ok'     => false,
				'detail' => 'دریافت API از وردپرس ناموفق بود. ' . $keys->get_error_message(),
			);
		}
		$verify = Ciwa_Api_Keys::instance()->verify_keys($keys['ck'], $keys['cs']);
		if (is_wp_error($verify)) {
			return array(
				'ok'     => false,
				'detail' => 'دریافت API از وردپرس ناموفق بود. ' . $verify->get_error_message(),
			);
		}
		return array(
			'ok'     => true,
			'detail' => 'REST ووکامرس در دسترس است و کلید API معتبر است.',
		);
	}

	private function validate_url($url) {
		if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
			return array('ok' => false, 'detail' => 'آدرس معتبر نیست. فقط پایه را بگذارید؛ مثال: https://karan8n-f8t-auto.runflare.cloud');
		}
		$p = wp_parse_url($url);
		if (empty($p['scheme']) || !in_array($p['scheme'], array('http', 'https'), true)) {
			return array('ok' => false, 'detail' => 'آدرس باید با http یا https شروع شود.');
		}
		return array('ok' => true, 'detail' => $url);
	}

	private function ping_host($url) {
		$response = wp_remote_get($url, array(
			'timeout'     => 12,
			'redirection' => 2,
			'sslverify'   => false,
		));
		if (is_wp_error($response)) {
			return array(
				'ok'     => false,
				'detail' => $response->get_error_message(),
				'code'   => 0,
			);
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		// n8n root often returns 404 — that still means the server is up.
		return array(
			'ok'     => $code > 0,
			'detail' => 'سرور پاسخ داد (HTTP ' . $code . ')' . (404 === $code ? ' — برای ریشه n8n طبیعی است' : ''),
			'code'   => $code,
		);
	}

	private function post_json($url, $body) {
		$response = wp_remote_post($url, array(
			'timeout'   => 15,
			'sslverify' => false,
			'headers'   => array('Content-Type' => 'application/json'),
			'body'      => wp_json_encode($body),
		));
		if (is_wp_error($response)) {
			return array('ok' => false, 'detail' => $response->get_error_message(), 'code' => 0);
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		return array(
			'ok'     => $code >= 200 && $code < 300,
			'detail' => 'HTTP ' . $code,
			'code'   => $code,
		);
	}

	private function create_test_order() {
		if (!function_exists('wc_create_order')) {
			return array('ok' => false, 'detail' => 'ووکامرس در دسترس نیست.');
		}
		try {
			$order = wc_create_order();
			$order->set_status('pending');
			$order->set_billing_first_name('CIWA');
			$order->set_billing_last_name('Test');
			$phone = Ciwa_Settings::get()['admin_phone'];
			if ($phone) {
				$order->set_billing_phone($phone);
			}
			$order->set_created_via('ciwa-automation-test');
			$order->update_meta_data('_ciwa_test_order', 1);
			$order->calculate_totals();
			$order->save();

			$payload = array(
				'id'          => $order->get_id(),
				'status'      => $order->get_status(),
				'customer_id' => 0,
				'order_key'   => $order->get_order_key(),
				'billing'     => array(
					'first_name' => 'CIWA',
					'last_name'  => 'Test',
					'phone'      => $phone,
					'email'      => get_option('admin_email'),
				),
				'date_paid'   => null,
				'meta_data'   => array(array('key' => '_ciwa_test_order', 'value' => 1)),
				'ciwa_test'   => true,
			);

			$order->delete(true);

			return array(
				'ok'      => true,
				'detail'  => 'سفارش تست #' . $payload['id'] . ' ساخته و پاکسازی شد.',
				'payload' => $payload,
			);
		} catch (Exception $e) {
			return array('ok' => false, 'detail' => $e->getMessage());
		}
	}

	private function send_test_sms() {
		$s     = Ciwa_Settings::get();
		$phone = (string) $s['admin_phone'];
		if (!$phone) {
			return array('ok' => false, 'detail' => 'شماره موبایل مدیر وارد نشده است.');
		}

		$pattern = '';
		foreach ($s['stages'] as $stage) {
			foreach ($stage['steps'] as $step) {
				if (!empty($step['pattern_code'])) {
					$pattern = $step['pattern_code'];
					break 2;
				}
			}
		}

		$provider = Ciwa_Sms::current();
		$label    = isset(Ciwa_Sms::PROVIDERS[$provider]) ? Ciwa_Sms::PROVIDERS[$provider] : $provider;
		$result   = Ciwa_Sms::send(
			$phone,
			'CIWA Automation: تست اتصال موفق بود. — hosseinmehrjoo.ir',
			$pattern ? $pattern : null,
			array('name' => 'مدیر', 'order_id' => 'TEST')
		);

		if (!empty($result['ok'])) {
			$result['detail'] = 'پیامک تست به ' . $phone . ' از طریق ' . $label . ' ارسال شد.';
		} else {
			$result['detail'] = (isset($result['detail']) ? $result['detail'] : 'خطای ناشناخته') .
				' — پنل: ' . $label . ' | API Key و خط ارسال را بررسی کنید.';
		}
		return $result;
	}
}
