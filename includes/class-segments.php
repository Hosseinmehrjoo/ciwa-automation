<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Conditional targeting for journeys.
 */
class Ciwa_Segments {

	/**
	 * @param string     $journey register|cart|pending|failed|purchase
	 * @param array      $ctx user_id, order_id, order (WC_Order|null)
	 * @return true|WP_Error
	 */
	public static function allows($journey, $ctx = array()) {
		$s = Ciwa_Settings::get();
		$seg = isset($s['segment']) && is_array($s['segment']) ? $s['segment'] : array();
		$user_id  = isset($ctx['user_id']) ? absint($ctx['user_id']) : 0;
		$order    = isset($ctx['order']) ? $ctx['order'] : null;
		$order_id = isset($ctx['order_id']) ? absint($ctx['order_id']) : 0;
		if (!$order && $order_id && function_exists('wc_get_order')) {
			$order = wc_get_order($order_id);
		}

		$min_total = isset($seg['min_order_total']) ? (float) $seg['min_order_total'] : 0;
		if ($min_total > 0 && $order && (float) $order->get_total() < $min_total) {
			return new WP_Error('segment', 'مبلغ سفارش کمتر از حداقل سگمنت است.');
		}

		if (!empty($seg['first_purchase_only']) && in_array($journey, array('register', 'purchase'), true) && $user_id) {
			$paid = wc_get_orders(array(
				'customer_id' => $user_id,
				'status'      => array('processing', 'completed'),
				'limit'       => 2,
				'return'      => 'ids',
			));
			if (count($paid) > 1 || ('purchase' === $journey && count($paid) > 1)) {
				// For purchase: allow first paid order only.
			}
			if ('register' === $journey && !empty($paid)) {
				return new WP_Error('segment', 'کاربر قبلاً خرید داشته است.');
			}
			if ('purchase' === $journey && count($paid) > 1) {
				return new WP_Error('segment', 'فقط اولین خرید مشمول این فلو است.');
			}
		}

		$include = isset($seg['include_categories']) ? array_filter(array_map('absint', (array) $seg['include_categories'])) : array();
		$exclude = isset($seg['exclude_categories']) ? array_filter(array_map('absint', (array) $seg['exclude_categories'])) : array();
		if (($include || $exclude) && $order) {
			$cats = self::order_category_ids($order);
			if ($include && !array_intersect($include, $cats)) {
				return new WP_Error('segment', 'سفارش در دسته‌های مجاز نیست.');
			}
			if ($exclude && array_intersect($exclude, $cats)) {
				return new WP_Error('segment', 'سفارش در دسته مستثنی است.');
			}
		}

		return true;
	}

	private static function order_category_ids($order) {
		$ids = array();
		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			if (!$product) {
				continue;
			}
			$terms = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'ids'));
			if (!is_wp_error($terms)) {
				$ids = array_merge($ids, $terms);
			}
		}
		return array_values(array_unique(array_map('absint', $ids)));
	}
}
