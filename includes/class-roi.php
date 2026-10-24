<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Simple ROI / recovery attribution for abandoned cart & failed payment.
 */
class Ciwa_Roi {
	const META_RECOVERY = '_ciwa_recovery_source';
	const OPTION_STATS  = 'ciwa_roi_stats';

	public static function mark_touch($user_id, $source, $order_id = 0) {
		$user_id = absint($user_id);
		if (!$user_id) {
			return;
		}
		update_user_meta($user_id, self::META_RECOVERY, array(
			'source'  => sanitize_key($source),
			'at'      => gmdate('c'),
			'order_id'=> absint($order_id),
		));
	}

	public static function maybe_attribute_order($order_id) {
		$order = wc_get_order($order_id);
		if (!$order || !$order->get_user_id()) {
			return;
		}
		$touch = get_user_meta($order->get_user_id(), self::META_RECOVERY, true);
		if (!is_array($touch) || empty($touch['source']) || empty($touch['at'])) {
			return;
		}
		$at = strtotime($touch['at']);
		if (!$at || (time() - $at) > (7 * DAY_IN_SECONDS)) {
			return;
		}
		$stats = self::stats();
		$stats['recovered_orders'] = (int) $stats['recovered_orders'] + 1;
		$stats['recovered_revenue'] = (float) $stats['recovered_revenue'] + (float) $order->get_total();
		$src = $touch['source'];
		if (!isset($stats['by_source'][$src])) {
			$stats['by_source'][$src] = array('orders' => 0, 'revenue' => 0);
		}
		$stats['by_source'][$src]['orders']++;
		$stats['by_source'][$src]['revenue'] += (float) $order->get_total();
		$order->update_meta_data(self::META_RECOVERY, $src);
		$order->save();
		delete_user_meta($order->get_user_id(), self::META_RECOVERY);
		update_option(self::OPTION_STATS, $stats, false);
		Ciwa_Logger::success('roi', 'سفارش بازیابی‌شده ثبت شد.', array(
			'order_id' => $order_id,
			'source'   => $src,
			'total'    => $order->get_total(),
		));
	}

	public static function track_sms_sent($journey) {
		$stats = self::stats();
		$stats['sms_sent'] = (int) $stats['sms_sent'] + 1;
		if (!isset($stats['sms_by_journey'][$journey])) {
			$stats['sms_by_journey'][$journey] = 0;
		}
		$stats['sms_by_journey'][$journey]++;
		update_option(self::OPTION_STATS, $stats, false);
	}

	public static function track_ab($variant, $journey) {
		$stats = self::stats();
		$key = $variant . '_' . $journey;
		if (!isset($stats['ab'][$key])) {
			$stats['ab'][$key] = 0;
		}
		$stats['ab'][$key]++;
		update_option(self::OPTION_STATS, $stats, false);
	}

	public static function stats() {
		$s = get_option(self::OPTION_STATS, array());
		return wp_parse_args(is_array($s) ? $s : array(), array(
			'sms_sent'          => 0,
			'recovered_orders'  => 0,
			'recovered_revenue' => 0,
			'by_source'         => array(),
			'sms_by_journey'    => array(),
			'ab'                => array(),
		));
	}

	public static function reset() {
		delete_option(self::OPTION_STATS);
	}

	public static function dashboard_block() {
		$s = self::stats();
		$settings = Ciwa_Settings::get();
		$avg = isset($settings['roi_avg_order']) ? (float) $settings['roi_avg_order'] : 0;
		$est = $avg > 0 ? ($avg * (int) $s['recovered_orders']) : (float) $s['recovered_revenue'];
		return array(
			'sms_sent'          => (int) $s['sms_sent'],
			'recovered_orders'  => (int) $s['recovered_orders'],
			'recovered_revenue' => (float) $s['recovered_revenue'],
			'estimated_revenue' => $est,
			'by_source'         => $s['by_source'],
			'ab'                => $s['ab'],
		);
	}
}
