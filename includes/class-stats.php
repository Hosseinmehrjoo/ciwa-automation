<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_Stats {

	public static function dashboard() {
		$setup    = Ciwa_Settings::setup();
		$settings = Ciwa_Settings::get();
		$levels   = Ciwa_Logger::counts_by_level();

		return array(
			'active'          => !empty($setup['production_active']),
			'test_ok'         => !empty($setup['test_ok']),
			'flow_built'      => !empty($setup['flow_built']),
			'logs_total'      => array_sum($levels),
			'logs_error'      => $levels['error'],
			'logs_success'    => $levels['success'],
			'levels'          => $levels,
			'orders_7d'       => self::orders_last_days(7),
			'funnel'          => self::journey_funnel(),
			'enabled_stages'  => self::enabled_stage_count($settings),
			'n8n_test'        => (string) $settings['n8n_test_url'],
			'n8n_prod'        => (string) $settings['n8n_prod_url'],
			'engine_mode'     => Ciwa_Engine::mode(),
			'engine_label'    => Ciwa_Engine::label(),
			'queue'           => Ciwa_Engine::is_internal() ? Ciwa_Queue::counts() : array(),
			'roi'             => Ciwa_Roi::dashboard_block(),
			'chart_orders'    => self::orders_chart(14),
			'chart_logs'      => self::logs_chart(14),
		);
	}

	private static function enabled_stage_count($settings) {
		$c = 0;
		foreach ($settings['stages'] as $st) {
			if (!empty($st['enabled'])) {
				$c++;
			}
		}
		return $c;
	}

	/**
	 * Lightweight order counts via HPOS/posts SQL when possible.
	 */
	private static function orders_last_days($days) {
		$empty = array('total' => 0, 'paid' => 0, 'failed' => 0, 'pending' => 0);
		if (!function_exists('wc_get_orders')) {
			return $empty;
		}
		$after = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
		return array(
			'total'   => self::count_orders($after, array()),
			'paid'    => self::count_orders($after, array('wc-processing', 'wc-completed', 'processing', 'completed')),
			'failed'  => self::count_orders($after, array('wc-failed', 'failed')),
			'pending' => self::count_orders($after, array('wc-pending', 'wc-on-hold', 'pending', 'on-hold')),
		);
	}

	private static function count_orders($after, $statuses) {
		// Prefer cheap WC count API if available.
		$args = array(
			'limit'        => 1,
			'paginate'     => true,
			'return'       => 'ids',
			'date_created' => '>=' . $after,
		);
		if ($statuses) {
			$clean = array();
			foreach ($statuses as $st) {
				$clean[] = str_replace('wc-', '', $st);
			}
			$args['status'] = array_values(array_unique($clean));
		}
		$result = wc_get_orders($args);
		if (is_object($result) && isset($result->total)) {
			return (int) $result->total;
		}
		return is_array($result) ? count($result) : 0;
	}

	private static function journey_funnel() {
		global $wpdb;
		$keys = array('registered', 'cart_abandoned', 'checkout_pending', 'payment_failed', 'purchased', 'exited_payment_failed');
		$out  = array_fill_keys($keys, 0);
		// One query instead of N.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS state, COUNT(*) AS c
				FROM {$wpdb->usermeta}
				WHERE meta_key = %s
				GROUP BY meta_value",
				'_ciwa_journey_state'
			),
			ARRAY_A
		);
		if ($rows) {
			foreach ($rows as $r) {
				if (isset($out[$r['state']])) {
					$out[$r['state']] = (int) $r['c'];
				}
			}
		}
		return $out;
	}

	private static function orders_chart($days) {
		$labels = array();
		$paid   = array();
		$failed = array();
		for ($i = $days - 1; $i >= 0; $i--) {
			$day = gmdate('Y-m-d', time() - ($i * DAY_IN_SECONDS));
			$labels[] = $day;
			if (!function_exists('wc_get_orders')) {
				$paid[]   = 0;
				$failed[] = 0;
				continue;
			}
			$paid[] = self::count_orders_on_day($day, array('processing', 'completed'));
			$failed[] = self::count_orders_on_day($day, array('failed'));
		}
		return array('labels' => $labels, 'paid' => $paid, 'failed' => $failed);
	}

	private static function count_orders_on_day($day, $statuses) {
		$args = array(
			'limit'        => 1,
			'paginate'     => true,
			'return'       => 'ids',
			'status'       => $statuses,
			'date_created' => $day,
		);
		$result = wc_get_orders($args);
		if (is_object($result) && isset($result->total)) {
			return (int) $result->total;
		}
		return 0;
	}

	private static function logs_chart($days) {
		global $wpdb;
		$table  = Ciwa_Logger::table();
		$labels = array();
		$data   = array();
		$start  = gmdate('Y-m-d', time() - (($days - 1) * DAY_IN_SECONDS));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$table}
				WHERE created_at >= %s
				GROUP BY DATE(created_at)",
				$start . ' 00:00:00'
			)
		);
		$map = array();
		if ($rows) {
			foreach ($rows as $r) {
				$map[$r->d] = (int) $r->c;
			}
		}
		for ($i = $days - 1; $i >= 0; $i--) {
			$day      = gmdate('Y-m-d', time() - ($i * DAY_IN_SECONDS));
			$labels[] = $day;
			$data[]   = isset($map[$day]) ? $map[$day] : 0;
		}
		return array('labels' => $labels, 'counts' => $data);
	}
}
