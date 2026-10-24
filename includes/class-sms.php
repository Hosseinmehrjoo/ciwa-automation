<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Multi-provider SMS: mediana, ippanel, farazsms.
 */
class Ciwa_Sms {

	const PROVIDERS = array(
		'mediana'  => 'مدیانا (Mediana)',
		'ippanel'  => 'آی‌پی پنل (IPPanel)',
		'farazsms' => 'فراز اس‌ام‌اس (FarazSMS)',
	);

	public static function providers() {
		return self::PROVIDERS;
	}

	public static function current() {
		$s = Ciwa_Settings::get();
		$p = isset($s['sms_provider']) ? $s['sms_provider'] : 'mediana';
		return isset(self::PROVIDERS[$p]) ? $p : 'mediana';
	}

	public static function api_key() {
		$s = Ciwa_Settings::get();
		if (!empty($s['sms_api_key'])) {
			return trim((string) $s['sms_api_key']);
		}
		// Backward compatibility
		return trim((string) (isset($s['mediana_api_key']) ? $s['mediana_api_key'] : ''));
	}

	public static function from_number() {
		return trim((string) Ciwa_Settings::get()['from_number']);
	}

	/**
	 * Normalize Iranian mobile for each provider.
	 *
	 * @param string $raw
	 * @param string $format e164_plus|e164|local09
	 */
	public static function normalize_phone($raw, $format = 'e164') {
		$p = preg_replace('/\D+/', '', (string) $raw);
		if (!$p) {
			return '';
		}
		if (0 === strpos($p, '98') && strlen($p) >= 12) {
			$local = '0' . substr($p, 2);
		} elseif (0 === strpos($p, '9') && 10 === strlen($p)) {
			$local = '0' . $p;
		} elseif (0 === strpos($p, '0') && 11 === strlen($p)) {
			$local = $p;
		} else {
			$local = $p;
		}
		if (!preg_match('/^09\d{9}$/', $local)) {
			return '';
		}
		if ('local09' === $format) {
			return $local;
		}
		$e164 = '98' . substr($local, 1);
		if ('e164_plus' === $format) {
			return '+' . $e164;
		}
		return $e164;
	}

	/**
	 * Send pattern or fallback plain SMS for connection test.
	 *
	 * @param string      $phone
	 * @param string      $message_plain used when no pattern
	 * @param string|null $pattern
	 * @param array       $params
	 * @return array{ok:bool,detail:string,code?:int,provider?:string}
	 */
	public static function send($phone, $message_plain, $pattern = null, $params = array()) {
		$provider = self::current();
		$key      = self::api_key();
		$from     = self::from_number();

		if (!$key) {
			return array('ok' => false, 'detail' => 'API Key پنل پیامک وارد نشده است.', 'provider' => $provider);
		}
		if (!$from) {
			return array('ok' => false, 'detail' => 'خط / شماره ارسال وارد نشده است.', 'provider' => $provider);
		}

		switch ($provider) {
			case 'ippanel':
				return self::send_ippanel($phone, $message_plain, $pattern, $params, $key, $from);
			case 'farazsms':
				return self::send_farazsms($phone, $message_plain, $pattern, $params, $key, $from);
			case 'mediana':
			default:
				return self::send_mediana($phone, $message_plain, $pattern, $params, $key, $from);
		}
	}

	/**
	 * Config embedded into n8n workflow for HTTP SMS nodes.
	 */
	public static function n8n_http_config() {
		$provider = self::current();
		$key      = self::api_key();
		$from     = self::from_number();

		switch ($provider) {
			case 'ippanel':
				return array(
					'provider'   => 'ippanel',
					'url'        => 'https://edge.ippanel.com/v1/api/send',
					'headers'    => array(
						array('name' => 'Authorization', 'value' => $key),
						array('name' => 'Content-Type', 'value' => 'application/json'),
					),
					// n8n expression — phone as +98...
					'jsonBody'   => '={{ JSON.stringify({ sending_type: "pattern", from_number: $json.from_number, code: $json.patternCode, recipients: [$json.phone.startsWith("+") ? $json.phone : ("+" + String($json.phone).replace(/^0/, "98"))], params: $json.patternParams || { name: $json.firstName || "دوست" } }) }}',
					'sms_api_key'=> $key,
					'from_number'=> $from,
				);
			case 'farazsms':
				return array(
					'provider'   => 'farazsms',
					'url'        => 'https://api.iranpayamak.com/ws/v1/sms/pattern',
					'headers'    => array(
						array('name' => 'Api-Key', 'value' => $key),
						array('name' => 'Content-Type', 'value' => 'application/json'),
					),
					'jsonBody'   => '={{ JSON.stringify({ code: $json.patternCode, attributes: $json.patternParams || { name: $json.firstName || "دوست" }, recipient: String($json.phone).replace("+98","0").replace(/^98/,"0"), line_number: $json.from_number, number_format: "english" }) }}',
					'sms_api_key'=> $key,
					'from_number'=> $from,
				);
			case 'mediana':
			default:
				return array(
					'provider'   => 'mediana',
					'url'        => 'https://api.mediana.ir/v1/messages/patterns/send',
					'headers'    => array(
						array('name' => 'X-API-KEY', 'value' => $key),
						array('name' => 'Content-Type', 'value' => 'application/json'),
					),
					'jsonBody'   => '={{ JSON.stringify({ code: $json.patternCode, from_number: $json.from_number, recipients: [String($json.phone).replace("+","")], params: $json.patternParams || { name: $json.firstName || "دوست" } }) }}',
					'sms_api_key'=> $key,
					'from_number'=> $from,
				);
		}
	}

	private static function send_mediana($phone, $plain, $pattern, $params, $key, $from) {
		$recipient = self::normalize_phone($phone, 'e164');
		if (!$recipient) {
			return array('ok' => false, 'detail' => 'شماره موبایل نامعتبر است.', 'provider' => 'mediana');
		}

		if ($pattern) {
			$url  = 'https://api.mediana.ir/v1/messages/patterns/send';
			$body = array(
				'code'        => $pattern,
				'from_number' => $from,
				'recipients'  => array($recipient),
				'params'      => $params ? $params : array('name' => 'مدیر'),
			);
		} else {
			$url  = 'https://api.mediana.ir/v1/messages';
			$body = array(
				'sending_type' => 'webservice',
				'from_number'  => $from,
				'recipients'   => array($recipient),
				'message'      => $plain,
			);
		}

		return self::http_json($url, $body, array(
			'Content-Type' => 'application/json',
			'X-API-KEY'    => $key,
		), 'mediana');
	}

	private static function send_ippanel($phone, $plain, $pattern, $params, $key, $from) {
		$recipient = self::normalize_phone($phone, 'e164_plus');
		if (!$recipient) {
			return array('ok' => false, 'detail' => 'شماره موبایل نامعتبر است.', 'provider' => 'ippanel');
		}
		$from_fmt = $from;
		if ($from_fmt && '+' !== substr($from_fmt, 0, 1) && preg_match('/^\d+$/', $from_fmt)) {
			$from_fmt = '+' . ltrim($from_fmt, '+');
		}

		if ($pattern) {
			$url  = 'https://edge.ippanel.com/v1/api/send';
			$body = array(
				'sending_type' => 'pattern',
				'from_number'  => $from_fmt,
				'code'         => $pattern,
				'recipients'   => array($recipient),
				'params'       => $params ? $params : array('name' => 'مدیر'),
			);
			return self::http_json($url, $body, array(
				'Content-Type'  => 'application/json',
				'Authorization' => $key,
			), 'ippanel');
		}

		// Fallback: api2 pattern-less webservice is limited; try edge webservice-like
		$url  = 'https://edge.ippanel.com/v1/api/send';
		$body = array(
			'sending_type' => 'webservice',
			'from_number'  => $from_fmt,
			'message'      => $plain,
			'params'       => array(
				'recipients' => array($recipient),
			),
		);
		$res = self::http_json($url, $body, array(
			'Content-Type'  => 'application/json',
			'Authorization' => $key,
		), 'ippanel');
		if (!$res['ok']) {
			$res['detail'] .= ' — برای تست IPPanel بهتر است حداقل یک کد پترن در فلوها وارد کنید.';
		}
		return $res;
	}

	private static function send_farazsms($phone, $plain, $pattern, $params, $key, $from) {
		$recipient = self::normalize_phone($phone, 'local09');
		if (!$recipient) {
			return array('ok' => false, 'detail' => 'شماره موبایل نامعتبر است.', 'provider' => 'farazsms');
		}

		if ($pattern) {
			$url  = 'https://api.iranpayamak.com/ws/v1/sms/pattern';
			$body = array(
				'code'          => $pattern,
				'attributes'    => $params ? $params : array('name' => 'مدیر'),
				'recipient'     => $recipient,
				'line_number'   => $from,
				'number_format' => 'english',
			);
			return self::http_json($url, $body, array(
				'Content-Type' => 'application/json',
				'Api-Key'      => $key,
			), 'farazsms', array(200, 201));
		}

		$url  = 'https://api.iranpayamak.com/ws/v1/sms/simple';
		$body = array(
			'message'       => $plain,
			'recipient'     => array($recipient),
			'line_number'   => $from,
			'number_format' => 'english',
		);
		return self::http_json($url, $body, array(
			'Content-Type' => 'application/json',
			'Api-Key'      => $key,
		), 'farazsms', array(200, 201));
	}

	/**
	 * @param int[] $ok_codes
	 */
	private static function http_json($url, $body, $headers, $provider, $ok_codes = null) {
		if (null === $ok_codes) {
			$ok_codes = range(200, 299);
		}
		$response = wp_remote_post($url, array(
			'timeout'     => 12,
			'redirection' => 2,
			'headers'     => $headers,
			'body'        => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
		));
		if (is_wp_error($response)) {
			return array(
				'ok'       => false,
				'detail'   => $response->get_error_message(),
				'provider' => $provider,
				'code'     => 0,
			);
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		$raw  = wp_remote_retrieve_body($response);
		$data = json_decode($raw, true);
		$ok   = in_array($code, $ok_codes, true);

		$msg = '';
		if (is_array($data)) {
			if (isset($data['meta']['message'])) {
				$msg = $data['meta']['message'];
			} elseif (isset($data['message'])) {
				$msg = is_array($data['message']) ? wp_json_encode($data['message']) : $data['message'];
			} elseif (isset($data['error'])) {
				$msg = is_array($data['error']) ? wp_json_encode($data['error']) : $data['error'];
			} elseif (isset($data['data']['message'])) {
				$msg = $data['data']['message'];
			}
		}
		if (!$msg) {
			$msg = 'HTTP ' . $code;
		}

		// IPPanel / panels often reject identical test SMS as "duplicate" — credentials are fine.
		$duplicate = (false !== strpos($msg, 'تکراری'))
			|| (false !== stripos($msg, 'duplicate'))
			|| (false !== stripos($raw, 'تکراری'));

		if ($ok || $duplicate) {
			return array(
				'ok'       => true,
				'detail'   => $duplicate
					? ('اتصال پنل OK — پاسخ: درخواست تکراری (یعنی API وصل است، پیامک مشابه اخیراً ارسال شده)')
					: ('ارسال موفق از طریق ' . (isset(self::PROVIDERS[$provider]) ? self::PROVIDERS[$provider] : $provider)),
				'provider' => $provider,
				'code'     => $code,
			);
		}

		return array(
			'ok'       => false,
			'detail'   => $msg,
			'provider' => $provider,
			'code'     => $code,
		);
	}
}
