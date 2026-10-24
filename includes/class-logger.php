<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_Logger {

	const LEVELS = array('debug', 'info', 'warning', 'error', 'success');

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ciwa_logs';
	}

	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			channel varchar(50) NOT NULL DEFAULT 'general',
			message text NOT NULL,
			context longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY level (level),
			KEY channel (channel)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	public static function log($level, $channel, $message, $context = array()) {
		global $wpdb;
		$level = in_array($level, self::LEVELS, true) ? $level : 'info';
		if (is_array($context) && $context) {
			$context = Ciwa_Security::redact_context($context);
		}
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => current_time('mysql'),
				'level'      => $level,
				'channel'    => sanitize_key($channel),
				'message'    => wp_strip_all_tags((string) $message),
				'context'    => $context ? wp_json_encode($context, JSON_UNESCAPED_UNICODE) : null,
			),
			array('%s', '%s', '%s', '%s', '%s')
		);
		return (int) $wpdb->insert_id;
	}

	public static function info($channel, $message, $context = array()) {
		return self::log('info', $channel, $message, $context);
	}

	public static function success($channel, $message, $context = array()) {
		return self::log('success', $channel, $message, $context);
	}

	public static function warning($channel, $message, $context = array()) {
		return self::log('warning', $channel, $message, $context);
	}

	public static function error($channel, $message, $context = array()) {
		return self::log('error', $channel, $message, $context);
	}

	public static function debug($channel, $message, $context = array()) {
		return self::log('debug', $channel, $message, $context);
	}

	/**
	 * @return array{items:array,total:int}
	 */
	public static function query($args = array()) {
		global $wpdb;
		$table = self::table();
		$args  = wp_parse_args($args, array(
			'page'     => 1,
			'per_page' => 50,
			'level'    => '',
			'channel'  => '',
			'search'   => '',
		));

		$where = array('1=1');
		$params = array();

		if ($args['level']) {
			$where[]  = 'level = %s';
			$params[] = $args['level'];
		}
		if ($args['channel']) {
			$where[]  = 'channel = %s';
			$params[] = $args['channel'];
		}
		if ($args['search']) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like($args['search']) . '%';
		}

		$sql_where = implode(' AND ', $where);
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$total     = $params ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);

		$page     = max(1, (int) $args['page']);
		$per_page = min(200, max(10, (int) $args['per_page']));
		$offset   = ($page - 1) * $per_page;

		$list_sql = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$list_params = array_merge($params, array($per_page, $offset));
		$items = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A);

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
			'page'  => $page,
			'pages' => (int) ceil($total / $per_page),
		);
	}

	public static function delete($ids) {
		global $wpdb;
		$ids = array_filter(array_map('absint', (array) $ids));
		if (!$ids) {
			return 0;
		}
		$in = implode(',', $ids);
		return (int) $wpdb->query("DELETE FROM {$wpdb->prefix}ciwa_logs WHERE id IN ({$in})"); // phpcs:ignore
	}

	public static function clear_all() {
		global $wpdb;
		return (int) $wpdb->query('TRUNCATE TABLE ' . self::table()); // phpcs:ignore
	}

	public static function clear_older_than($days = 30) {
		global $wpdb;
		$days = max(1, absint($days));
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)',
				current_time('mysql'),
				$days
			)
		);
	}

	public static function counts_by_level() {
		global $wpdb;
		$rows = $wpdb->get_results('SELECT level, COUNT(*) AS c FROM ' . self::table() . ' GROUP BY level', ARRAY_A);
		$out  = array_fill_keys(self::LEVELS, 0);
		if ($rows) {
			foreach ($rows as $r) {
				$out[$r['level']] = (int) $r['c'];
			}
		}
		return $out;
	}
}
