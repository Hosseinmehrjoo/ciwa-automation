<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_Compliance {

	public static function is_blacklisted($phone) {
		$phone = Ciwa_Sms::normalize_phone($phone, 'local09');
		if ('' === $phone) {
			return false;
		}
		$list = Ciwa_Settings::get()['blacklist'];
		if (!is_array($list)) {
			return false;
		}
		foreach ($list as $item) {
			$n = Ciwa_Sms::normalize_phone($item, 'local09');
			if ($n && $n === $phone) {
				return true;
			}
		}
		return false;
	}

	public static function add_blacklist($phone) {
		$phone = Ciwa_Sms::normalize_phone($phone, 'local09');
		if ('' === $phone) {
			return false;
		}
		$s    = Ciwa_Settings::get();
		$list = isset($s['blacklist']) && is_array($s['blacklist']) ? $s['blacklist'] : array();
		if (!in_array($phone, $list, true)) {
			$list[] = $phone;
			Ciwa_Settings::update(array('blacklist' => array_values($list)));
			Ciwa_Logger::info('compliance', 'شماره به لیست سیاه اضافه شد.', array('phone' => $phone));
		}
		return true;
	}

	public static function remove_blacklist($phone) {
		$phone = Ciwa_Sms::normalize_phone($phone, 'local09');
		$s     = Ciwa_Settings::get();
		$list  = isset($s['blacklist']) && is_array($s['blacklist']) ? $s['blacklist'] : array();
		$list  = array_values(array_filter($list, static function ($p) use ($phone) {
			return Ciwa_Sms::normalize_phone($p, 'local09') !== $phone;
		}));
		Ciwa_Settings::update(array('blacklist' => $list));
		return true;
	}

	/**
	 * If quiet hours are active, return next allowed timestamp (site timezone).
	 * Otherwise return current timestamp.
	 */
	public static function next_allowed_timestamp($from_ts = null) {
		$s = Ciwa_Settings::get();
		$ts = $from_ts ? (int) $from_ts : current_time('timestamp');
		if (empty($s['quiet_hours_enabled'])) {
			return $ts;
		}
		$start = self::parse_hhmm(isset($s['quiet_hours_start']) ? $s['quiet_hours_start'] : '22:00');
		$end   = self::parse_hhmm(isset($s['quiet_hours_end']) ? $s['quiet_hours_end'] : '08:00');
		if (null === $start || null === $end) {
			return $ts;
		}

		$day_start = strtotime(gmdate('Y-m-d', $ts) . ' 00:00:00');
		// Work in local time via current_time offset already in $ts when using current_time('timestamp').
		$minutes = ((int) gmdate('G', $ts) * 60) + (int) gmdate('i', $ts);
		// Better: use wp timezone
		$dt = new DateTime('@' . $ts);
		$dt->setTimezone(wp_timezone());
		$minutes = ((int) $dt->format('G') * 60) + (int) $dt->format('i');

		$in_quiet = false;
		if ($start > $end) {
			// e.g. 22:00 → 08:00
			$in_quiet = ($minutes >= $start || $minutes < $end);
		} else {
			$in_quiet = ($minutes >= $start && $minutes < $end);
		}
		if (!$in_quiet) {
			return $ts;
		}

		// Move to end of quiet window.
		$local = new DateTime('now', wp_timezone());
		$local->setTimestamp($ts);
		$end_h = (int) floor($end / 60);
		$end_m = (int) ($end % 60);
		$local->setTime($end_h, $end_m, 0);
		if ($start > $end && $minutes >= $start) {
			$local->modify('+1 day');
		} elseif ($start <= $end && $minutes >= $start) {
			// same day end already set; if past end somehow, +0
		}
		return $local->getTimestamp();
	}

	public static function message_requests_stop($text) {
		$s = Ciwa_Settings::get();
		$raw = isset($s['stop_keywords']) ? (string) $s['stop_keywords'] : 'لغو,stop,STOP';
		$parts = array_filter(array_map('trim', preg_split('/[,،]+/u', $raw)));
		$text = trim((string) $text);
		foreach ($parts as $kw) {
			if ('' !== $kw && mb_strtolower($text, 'UTF-8') === mb_strtolower($kw, 'UTF-8')) {
				return true;
			}
		}
		return false;
	}

	private static function parse_hhmm($val) {
		if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string) $val, $m)) {
			return null;
		}
		$h = (int) $m[1];
		$i = (int) $m[2];
		if ($h > 23 || $i > 59) {
			return null;
		}
		return ($h * 60) + $i;
	}
}
