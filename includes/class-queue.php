<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Persistent job queue for the internal automation engine.
 */
class Ciwa_Queue {

	const STATUS_PENDING    = 'pending';
	const STATUS_PROCESSING = 'processing';
	const STATUS_SENT       = 'sent';
	const STATUS_FAILED     = 'failed';
	const STATUS_CANCELLED  = 'cancelled';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ciwa_queue';
	}

	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			scheduled_at datetime NOT NULL,
			sent_at datetime NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			max_attempts tinyint(3) unsigned NOT NULL DEFAULT 3,
			journey_key varchar(40) NOT NULL DEFAULT '',
			step_id varchar(60) NOT NULL DEFAULT '',
			channel varchar(20) NOT NULL DEFAULT 'sms',
			recipient varchar(120) NOT NULL DEFAULT '',
			token varchar(64) NOT NULL DEFAULT '',
			ab_variant varchar(1) NOT NULL DEFAULT 'a',
			payload longtext NULL,
			last_error text NULL,
			PRIMARY KEY  (id),
			KEY status_sched (status, scheduled_at),
			KEY token (token),
			KEY journey (journey_key, step_id)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	/**
	 * @param array $job
	 * @return int|false
	 */
	public static function enqueue($job) {
		global $wpdb;
		$now = current_time('mysql');
		$scheduled = isset($job['scheduled_at']) ? (string) $job['scheduled_at'] : $now;
		if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $scheduled)) {
			$scheduled = $now;
		}
		$row = array(
			'created_at'   => $now,
			'scheduled_at' => $scheduled,
			'sent_at'      => null,
			'status'       => self::STATUS_PENDING,
			'attempts'     => 0,
			'max_attempts' => isset($job['max_attempts']) ? absint($job['max_attempts']) : 3,
			'journey_key'  => isset($job['journey_key']) ? sanitize_key($job['journey_key']) : '',
			'step_id'      => isset($job['step_id']) ? sanitize_key($job['step_id']) : '',
			'channel'      => isset($job['channel']) ? sanitize_key($job['channel']) : 'sms',
			'recipient'    => isset($job['recipient']) ? sanitize_text_field($job['recipient']) : '',
			'token'        => isset($job['token']) ? sanitize_text_field($job['token']) : '',
			'ab_variant'   => isset($job['ab_variant']) && 'b' === $job['ab_variant'] ? 'b' : 'a',
			'payload'      => isset($job['payload']) ? wp_json_encode($job['payload'], JSON_UNESCAPED_UNICODE) : null,
			'last_error'   => null,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(self::table(), $row);
		return $ok ? (int) $wpdb->insert_id : false;
	}

	public static function cancel_by_token($token) {
		global $wpdb;
		$token = sanitize_text_field($token);
		if ('' === $token) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET status = %s WHERE token = %s AND status IN (%s, %s)",
				self::STATUS_CANCELLED,
				$token,
				self::STATUS_PENDING,
				self::STATUS_FAILED
			)
		);
	}

	public static function cancel_journey_for_user($user_id, $journey_keys = array()) {
		$user_id = absint($user_id);
		if (!$user_id) {
			return 0;
		}
		$token = Ciwa_State::get_customer_token($user_id);
		return $token ? self::cancel_by_token($token) : 0;
	}

	/**
	 * @return array<int,object>
	 */
	public static function claim_due($limit = 20) {
		global $wpdb;
		$table = self::table();
		$now   = current_time('mysql');
		$limit = max(1, min(50, absint($limit)));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE status = %s AND scheduled_at <= %s
				ORDER BY scheduled_at ASC
				LIMIT %d",
				self::STATUS_PENDING,
				$now,
				$limit
			)
		);
		if (!$rows) {
			return array();
		}
		$claimed = array();
		foreach ($rows as $row) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$updated = $wpdb->update(
				$table,
				array(
					'status'   => self::STATUS_PROCESSING,
					'attempts' => (int) $row->attempts + 1,
				),
				array(
					'id'     => (int) $row->id,
					'status' => self::STATUS_PENDING,
				),
				array('%s', '%d'),
				array('%d', '%s')
			);
			if ($updated) {
				$row->attempts = (int) $row->attempts + 1;
				$row->status   = self::STATUS_PROCESSING;
				$claimed[]     = $row;
			}
		}
		return $claimed;
	}

	public static function mark_sent($id) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'status'  => self::STATUS_SENT,
				'sent_at' => current_time('mysql'),
				'last_error' => null,
			),
			array('id' => absint($id)),
			array('%s', '%s', '%s'),
			array('%d')
		);
	}

	public static function mark_failed($id, $error, $retry = true) {
		global $wpdb;
		$id  = absint($id);
		$row = self::get($id);
		if (!$row) {
			return;
		}
		$attempts = (int) $row->attempts;
		$max      = (int) $row->max_attempts;
		if ($retry && $attempts < $max) {
			$delay = min(3600, 60 * pow(2, max(0, $attempts - 1)));
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				self::table(),
				array(
					'status'       => self::STATUS_PENDING,
					'scheduled_at' => gmdate('Y-m-d H:i:s', current_time('timestamp') + $delay),
					'last_error'   => wp_strip_all_tags((string) $error),
				),
				array('id' => $id),
				array('%s', '%s', '%s'),
				array('%d')
			);
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'status'     => self::STATUS_FAILED,
				'last_error' => wp_strip_all_tags((string) $error),
			),
			array('id' => $id),
			array('%s', '%s'),
			array('%d')
		);
	}

	public static function get($id) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', absint($id)));
	}

	public static function retry($id) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->update(
			self::table(),
			array(
				'status'       => self::STATUS_PENDING,
				'scheduled_at' => current_time('mysql'),
				'last_error'   => null,
			),
			array('id' => absint($id)),
			array('%s', '%s', '%s'),
			array('%d')
		);
	}

	/**
	 * @return array{items:array,total:int}
	 */
	public static function query($args = array()) {
		global $wpdb;
		$table = self::table();
		$args  = wp_parse_args($args, array(
			'page'     => 1,
			'per_page' => 30,
			'status'   => '',
		));
		$where = '1=1';
		$params = array();
		if ($args['status']) {
			$where   .= ' AND status = %s';
			$params[] = sanitize_key($args['status']);
		}
		$per   = max(1, min(100, absint($args['per_page'])));
		$page  = max(1, absint($args['page']));
		$offset = ($page - 1) * $per;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var($params ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params) : "SELECT COUNT(*) FROM {$table} WHERE {$where}");
		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$qparams = array_merge($params, array($per, $offset));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$items = $wpdb->get_results($wpdb->prepare($sql, $qparams));
		return array('items' => $items ? $items : array(), 'total' => $total);
	}

	public static function counts() {
		global $wpdb;
		$table = self::table();
		$out = array(
			'pending'    => 0,
			'processing' => 0,
			'sent'       => 0,
			'failed'     => 0,
			'cancelled'  => 0,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results("SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status");
		if ($rows) {
			foreach ($rows as $r) {
				if (isset($out[$r->status])) {
					$out[$r->status] = (int) $r->c;
				}
			}
		}
		return $out;
	}

	public static function truncate() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query("TRUNCATE TABLE `{$table}`");
	}
}
