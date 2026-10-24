<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Message templates, variables, A/B pick, character count helpers.
 */
class Ciwa_Messages {

	public static function variables() {
		return array(
			'{first_name}'  => 'نام مشتری',
			'{last_name}'   => 'نام خانوادگی',
			'{phone}'       => 'موبایل',
			'{email}'       => 'ایمیل',
			'{order_id}'    => 'شماره سفارش',
			'{order_total}' => 'مبلغ سفارش',
			'{cart_url}'    => 'لینک سبد',
			'{shop_url}'    => 'لینک فروشگاه',
			'{site_name}'   => 'نام سایت',
			'{pay_url}'     => 'لینک پرداخت',
		);
	}

	/**
	 * @param string $template
	 * @param array  $ctx
	 */
	public static function render($template, $ctx = array()) {
		$defaults = array(
			'first_name'  => '',
			'last_name'   => '',
			'phone'       => '',
			'email'       => '',
			'order_id'    => '',
			'order_total' => '',
			'cart_url'    => function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/'),
			'shop_url'    => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/'),
			'site_name'   => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
			'pay_url'     => '',
		);
		$ctx = array_merge($defaults, $ctx);
		$map = array();
		foreach ($ctx as $k => $v) {
			$map['{' . $k . '}'] = (string) $v;
		}
		return strtr((string) $template, $map);
	}

	public static function sms_length($text) {
		$len = mb_strlen((string) $text, 'UTF-8');
		$is_unicode = (bool) preg_match('/[^\x00-\x7F]/u', (string) $text);
		$per = $is_unicode ? 70 : 160;
		$parts = $len ? (int) ceil($len / $per) : 0;
		return array(
			'chars'     => $len,
			'parts'     => $parts,
			'per_part'  => $per,
			'unicode'   => $is_unicode,
		);
	}

	/**
	 * Pick A or B variant for a step.
	 *
	 * @return array{text:string,variant:string}
	 */
	public static function pick_variant($step) {
		$message_a = isset($step['message']) ? (string) $step['message'] : '';
		$message_b = isset($step['message_b']) ? (string) $step['message_b'] : '';
		$ab        = !empty($step['ab_enabled']) && '' !== trim($message_b);
		if (!$ab) {
			return array('text' => $message_a, 'variant' => 'a');
		}
		$variant = (wp_rand(0, 1) === 1) ? 'b' : 'a';
		return array(
			'text'    => ('b' === $variant) ? $message_b : $message_a,
			'variant' => $variant,
		);
	}

	public static function default_message($journey, $step_id) {
		$defaults = self::default_library();
		if (isset($defaults[$journey][$step_id])) {
			return $defaults[$journey][$step_id];
		}
		return 'سلام {first_name}، از {site_name} پیام دارید.';
	}

	public static function default_library() {
		return array(
			'register' => array(
				'nurture_24h' => 'سلام {first_name} عزیز 👋 به {site_name} خوش آمدید. همین حالا در فروشگاه بگردید: {shop_url}',
				'nurture_5d'  => '{first_name}، موجودی بعضی محصولات محدود است. قبل از اتمام، سری به فروشگاه بزنید: {shop_url}',
			),
			'cart' => array(
				'cart_60m' => '{first_name}، سبد خریدت هنوز منتظرته 🛒 تکمیلش کن: {cart_url}',
				'cart_12h' => 'فقط چند نفر این محصول رو دیدن — سبدت رو از دست نده: {cart_url}',
				'cart_24h' => 'آخرین یادآوری: سبدت هنوز فعال است. الان تکمیل کن: {cart_url}',
			),
			'pending' => array(
				'pending_30m' => '{first_name}، سفارش {order_id} هنوز پرداخت نشده. ادامه پرداخت: {pay_url}',
				'pending_6h'  => 'احتمالاً در پرداخت مشکلی بوده. سفارش {order_id} را کامل کنید: {pay_url}',
				'pending_1d'  => 'آخرین فرصت تکمیل سفارش {order_id}: {pay_url}',
			),
			'failed' => array(
				'failed_now'  => 'پرداخت سفارش {order_id} ناموفق بود. دوباره امتحان کنید: {pay_url}',
				'failed_1h'   => '{first_name}، هنوز می‌توانید سفارش {order_id} را پرداخت کنید: {pay_url}',
				'failed_24h'  => 'سفارش {order_id} به‌خاطر پرداخت ناموفق بسته می‌شود. اگر نیاز دارید دوباره سفارش دهید: {shop_url}',
			),
			'purchase' => array(
				'order_confirm'    => '✅ سفارش {order_id} با مبلغ {order_total} ثبت شد. ممنون از خریدت {first_name}!',
				'satisfaction_5d'  => '{first_name} عزیز، از خریدت راضی بودی؟ نظرت برای ما مهم است 💚 — {site_name}',
			),
		);
	}
}
