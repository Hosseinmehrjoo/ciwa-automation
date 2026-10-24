<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_Api_Keys {
	const OPTION_KEY_ID = 'ciwa_wc_key_id';

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @return array{ck:string,cs:string}|WP_Error
	 */
	public function ensure_keys() {
		$settings = Ciwa_Settings::get();
		if (!empty($settings['wc_ck']) && !empty($settings['wc_cs'])) {
			$verify = $this->verify_keys($settings['wc_ck'], $settings['wc_cs']);
			if (!is_wp_error($verify)) {
				return array('ck' => $settings['wc_ck'], 'cs' => $settings['wc_cs']);
			}
			Ciwa_Logger::warning('api', 'کلید ذخیره‌شده معتبر نبود.', array(
				'error' => $verify->get_error_message(),
			));
		}

		$created = $this->create_keys();
		if (is_wp_error($created)) {
			return $created;
		}

		$verify = $this->verify_keys($created['ck'], $created['cs']);
		if (is_wp_error($verify)) {
			// Still return keys if they were inserted — verify can fail on odd hosts.
			Ciwa_Logger::warning('api', 'کلید ساخته شد ولی تأیید کامل نشد.', array(
				'error' => $verify->get_error_message(),
			));
		}

		return $created;
	}

	/**
	 * @return array{ck:string,cs:string}|WP_Error
	 */
	public function create_keys() {
		if (!class_exists('WooCommerce')) {
			return new WP_Error('no_wc', 'ووکامرس فعال نیست.');
		}
		if (!function_exists('wc_api_hash')) {
			return new WP_Error('no_hash', 'توابع API ووکامرس در دسترس نیست. ووکامرس را به‌روز کنید.');
		}

		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_api_keys';

		if (!$this->table_exists($table)) {
			return new WP_Error(
				'no_table',
				'جدول ' . $table . ' پیدا نشد. یک‌بار به ووکامرس → تنظیمات بروید تا جداول ساخته شوند، یا کلید را دستی بسازید.'
			);
		}

		$columns = $this->table_columns($table);
		$user_id = $this->resolve_api_user_id();

		$consumer_key    = 'ck_' . $this->rand_hash();
		$consumer_secret = 'cs_' . $this->rand_hash();

		// Keep description ASCII-only (latin1 tables break on unicode dashes).
		$description = 'CIWA Automation n8n ' . gmdate('Y-m-d H:i');

		$candidates = array();

		// Attempt A: full modern schema
		$row_a = array(
			'user_id'         => $user_id,
			'description'     => $description,
			'permissions'     => 'read_write',
			'consumer_key'    => wc_api_hash($consumer_key),
			'consumer_secret' => $consumer_secret,
		);
		if (in_array('truncated_key', $columns, true)) {
			$row_a['truncated_key'] = substr($consumer_key, -7);
		}
		$candidates[] = $row_a;

		// Attempt B: without description
		$row_b = $row_a;
		unset($row_b['description']);
		$candidates[] = $row_b;

		// Attempt C: permissions read only then — no, need write
		// Attempt C: shorter secret edge-case — regenerate with sha1 fallback length
		$row_c = $row_a;
		$consumer_key_c    = 'ck_' . sha1(wp_generate_password(32, false, false) . microtime(true));
		$consumer_secret_c = 'cs_' . substr(sha1(wp_generate_password(32, false, false)), 0, 40);
		// cs_ + 40 = 43 chars for CHAR(43)
		$row_c['consumer_key']    = wc_api_hash($consumer_key_c);
		$row_c['consumer_secret'] = $consumer_secret_c;
		if (isset($row_c['truncated_key'])) {
			$row_c['truncated_key'] = substr($consumer_key_c, -7);
		}
		$candidates[] = array('__keys' => array($consumer_key_c, $consumer_secret_c), 'row' => $row_c);

		$errors = array();
		foreach ($candidates as $idx => $candidate) {
			$row = $candidate;
			$ck  = $consumer_key;
			$cs  = $consumer_secret;
			if (isset($candidate['__keys'])) {
				$ck  = $candidate['__keys'][0];
				$cs  = $candidate['__keys'][1];
				$row = $candidate['row'];
			}

			// Drop unknown columns.
			foreach (array_keys($row) as $col) {
				if (!in_array($col, $columns, true)) {
					unset($row[$col]);
				}
			}

			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->insert($table, $row);

			if ($result) {
				$key_id = (int) $wpdb->insert_id;
				update_option(self::OPTION_KEY_ID, $key_id);
				Ciwa_Settings::update(array('wc_ck' => $ck, 'wc_cs' => $cs));
				Ciwa_Logger::success('api', 'کلید REST ووکامرس ساخته شد.', array(
					'key_id'  => $key_id,
					'attempt' => $idx + 1,
					'cols'    => array_keys($row),
				));
				return array('ck' => $ck, 'cs' => $cs);
			}

			$errors[] = 'try' . ($idx + 1) . ': ' . ($wpdb->last_error ? $wpdb->last_error : 'insert returned false');
		}

		// Last resort: raw SQL
		$raw = $this->create_keys_raw_sql($table, $columns, $user_id, $description);
		if (!is_wp_error($raw)) {
			return $raw;
		}
		$errors[] = 'raw: ' . $raw->get_error_message();

		$detail = implode(' | ', $errors);
		Ciwa_Logger::error('api', 'ساخت کلید API ووکامرس ناموفق بود.', array(
			'db_error' => $detail,
			'columns'  => $columns,
			'table'    => $table,
		));

		return new WP_Error(
			'key_fail',
			'ساخت کلید API ووکامرس ناموفق بود. جزئیات: ' . $detail .
			' — راه حل سریع: ووکامرس ← تنظیمات ← پیشرفته ← REST API ← افزودن کلید (خواندن/نوشتن)، بعد Consumer Key و Secret را در بخش زیر وارد کنید.'
		);
	}

	/**
	 * @return array{ck:string,cs:string}|WP_Error
	 */
	private function create_keys_raw_sql($table, $columns, $user_id, $description) {
		global $wpdb;

		$ck = 'ck_' . $this->rand_hash();
		$cs = 'cs_' . $this->rand_hash();
		$hash = wc_api_hash($ck);
		$trunc = substr($ck, -7);

		if (in_array('truncated_key', $columns, true)) {
			$sql = $wpdb->prepare(
				"INSERT INTO `{$table}` (`user_id`, `description`, `permissions`, `consumer_key`, `consumer_secret`, `truncated_key`) VALUES (%d, %s, %s, %s, %s, %s)",
				$user_id,
				$description,
				'read_write',
				$hash,
				$cs,
				$trunc
			);
		} else {
			$sql = $wpdb->prepare(
				"INSERT INTO `{$table}` (`user_id`, `description`, `permissions`, `consumer_key`, `consumer_secret`) VALUES (%d, %s, %s, %s, %s)",
				$user_id,
				$description,
				'read_write',
				$hash,
				$cs
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->query($sql);
		if (!$ok) {
			return new WP_Error('raw_fail', $wpdb->last_error ? $wpdb->last_error : 'raw insert failed');
		}

		update_option(self::OPTION_KEY_ID, (int) $wpdb->insert_id);
		Ciwa_Settings::update(array('wc_ck' => $ck, 'wc_cs' => $cs));
		Ciwa_Logger::success('api', 'کلید REST با SQL خام ساخته شد.', array('key_id' => (int) $wpdb->insert_id));
		return array('ck' => $ck, 'cs' => $cs);
	}

	private function rand_hash() {
		if (function_exists('wc_rand_hash')) {
			return wc_rand_hash();
		}
		if (function_exists('openssl_random_pseudo_bytes')) {
			return bin2hex(openssl_random_pseudo_bytes(20));
		}
		return sha1(uniqid((string) wp_rand(), true));
	}

	private function resolve_api_user_id() {
		$user_id = get_current_user_id();
		if ($user_id && user_can($user_id, 'manage_woocommerce')) {
			return $user_id;
		}
		$admins = get_users(array(
			'role__in' => array('administrator', 'shop_manager'),
			'number'   => 1,
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'fields'   => array('ID'),
		));
		if (!empty($admins[0]->ID)) {
			return (int) $admins[0]->ID;
		}
		return $user_id ? $user_id : 1;
	}

	private function table_exists($table) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		return !empty($found);
	}

	private function table_columns($table) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$cols = $wpdb->get_col("DESCRIBE `{$table}`");
		return is_array($cols) ? $cols : array();
	}

	/**
	 * @return true|WP_Error
	 */
	public function verify_keys($ck, $cs) {
		$ck = trim((string) $ck);
		$cs = trim((string) $cs);
		if ('' === $ck || '' === $cs) {
			return new WP_Error('empty', 'کلید Consumer Key/Secret خالی است.');
		}
		if (!function_exists('wc_api_hash')) {
			return new WP_Error('no_wc', 'ووکامرس در دسترس نیست.');
		}

		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_api_keys';
		if (!$this->table_exists($table)) {
			return new WP_Error('no_table', 'جدول کلیدهای API وجود ندارد.');
		}

		$hash = wc_api_hash($ck);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT key_id FROM {$table} WHERE consumer_key = %s AND consumer_secret = %s LIMIT 1",
				$hash,
				$cs
			)
		);

		if (!$row) {
			// Soft check: key hash exists (secret may be re-typed wrong)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$by_hash = $wpdb->get_var(
				$wpdb->prepare("SELECT key_id FROM {$table} WHERE consumer_key = %s LIMIT 1", $hash)
			);
			if ($by_hash) {
				return new WP_Error('bad_secret', 'Consumer Key پیدا شد ولی Secret اشتباه است.');
			}
			return new WP_Error('not_in_db', 'این کلید در ووکامرس ثبت نشده. از مسیر REST API ووکامرس یک کلید جدید بسازید.');
		}

		return true;
	}

	/**
	 * Save manual keys. If not in DB yet (user just created in WC UI same session), verify DB.
	 *
	 * @return true|WP_Error
	 */
	public function save_manual_keys($ck, $cs) {
		$ck = trim((string) $ck);
		$cs = trim((string) $cs);

		if (!preg_match('/^ck_/', $ck) || !preg_match('/^cs_/', $cs)) {
			return new WP_Error('format', 'فرمت کلید اشتباه است. باید با ck_ و cs_ شروع شود.');
		}

		$verify = $this->verify_keys($ck, $cs);
		if (is_wp_error($verify)) {
			return new WP_Error(
				'wp_api_fail',
				$verify->get_error_message()
			);
		}

		Ciwa_Settings::update(array(
			'wc_ck' => sanitize_text_field($ck),
			'wc_cs' => sanitize_text_field($cs),
		));
		Ciwa_Logger::success('api', 'کلید REST دستی ذخیره شد.');
		return true;
	}

	/**
	 * Allow build/export even when only manual keys are set; expose status for UI.
	 */
	public function status() {
		$s = Ciwa_Settings::get();
		$has = !empty($s['wc_ck']) && !empty($s['wc_cs']);
		$ok  = false;
		$msg = 'کلید تنظیم نشده';
		if ($has) {
			$v = $this->verify_keys($s['wc_ck'], $s['wc_cs']);
			if (is_wp_error($v)) {
				$msg = $v->get_error_message();
			} else {
				$ok  = true;
				$msg = 'کلید معتبر است';
			}
		}
		return array(
			'has_keys' => $has,
			'ok'       => $ok,
			'message'  => $msg,
			'ck_hint'  => $has ? substr($s['wc_ck'], 0, 10) . '…' : '',
		);
	}
}
