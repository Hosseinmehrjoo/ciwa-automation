<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Outbound channels: SMS + Email.
 */
class Ciwa_Channels {

	/**
	 * @param string $channel sms|email|both
	 * @param array  $args phone, email, subject, message, pattern, params
	 * @return array{ok:bool,detail:string,results:array}
	 */
	public static function send($channel, $args) {
		$channel = sanitize_key($channel);
		$results = array();
		$ok      = true;
		$details = array();

		if (in_array($channel, array('sms', 'both'), true)) {
			$phone = isset($args['phone']) ? $args['phone'] : '';
			if (Ciwa_Compliance::is_blacklisted($phone)) {
				$results['sms'] = array('ok' => false, 'detail' => 'شماره در لیست سیاه است.');
				$ok = false;
				$details[] = 'sms:blacklist';
			} else {
				$sms = Ciwa_Sms::send(
					$phone,
					isset($args['message']) ? $args['message'] : '',
					isset($args['pattern']) ? $args['pattern'] : null,
					isset($args['params']) ? $args['params'] : array()
				);
				$results['sms'] = $sms;
				if (empty($sms['ok'])) {
					$ok = false;
				}
				$details[] = 'sms:' . (isset($sms['detail']) ? $sms['detail'] : '');
			}
		}

		if (in_array($channel, array('email', 'both'), true)) {
			$s = Ciwa_Settings::get();
			if (empty($s['email_enabled'])) {
				$results['email'] = array('ok' => false, 'detail' => 'کانال ایمیل غیرفعال است.');
				if ('email' === $channel) {
					$ok = false;
				}
				$details[] = 'email:disabled';
			} else {
				$email = isset($args['email']) ? sanitize_email($args['email']) : '';
				if (!$email || !is_email($email)) {
					$results['email'] = array('ok' => false, 'detail' => 'ایمیل نامعتبر است.');
					$ok = false;
					$details[] = 'email:invalid';
				} else {
					$subject = isset($args['subject']) && $args['subject']
						? $args['subject']
						: wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
					$body = isset($args['message']) ? $args['message'] : '';
					$headers = array('Content-Type: text/plain; charset=UTF-8');
					$sent = wp_mail($email, $subject, $body, $headers);
					$results['email'] = array(
						'ok'     => (bool) $sent,
						'detail' => $sent ? 'ارسال شد' : 'wp_mail ناموفق بود',
					);
					if (!$sent) {
						$ok = false;
					}
					$details[] = 'email:' . $results['email']['detail'];
				}
			}
		}

		return array(
			'ok'      => $ok,
			'detail'  => implode(' | ', $details),
			'results' => $results,
		);
	}
}
