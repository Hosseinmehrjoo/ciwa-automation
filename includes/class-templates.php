<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ready-made industry scenario templates.
 */
class Ciwa_Templates {

	public static function all() {
		return array(
			'fashion' => array(
				'id'          => 'fashion',
				'label'       => 'فروشگاه پوشاک و مد',
				'description' => 'سبد رها‌شده سریع + FOMO موجودی + رضایت بعد از خرید',
			),
			'digital' => array(
				'id'          => 'digital',
				'label'       => 'محصولات دیجیتال',
				'description' => 'تأیید فوری خرید + nurture ثبت‌نام + پیگیری پرداخت ناموفق',
			),
			'b2b' => array(
				'id'          => 'b2b',
				'label'       => 'B2B / عمده',
				'description' => 'پیام‌های رسمی‌تر، تأخیر بیشتر، تمرکز روی تکمیل سفارش',
			),
			'general' => array(
				'id'          => 'general',
				'label'       => 'عمومی فروشگاهی',
				'description' => 'تعادل همه مراحل برای اکثر فروشگاه‌های ووکامرس',
			),
		);
	}

	/**
	 * Apply template onto settings stages + messages.
	 *
	 * @param string $id
	 * @return true|WP_Error
	 */
	public static function apply($id) {
		$id = sanitize_key($id);
		$all = self::all();
		if (!isset($all[$id])) {
			return new WP_Error('template', 'قالب یافت نشد.');
		}
		$stages = Ciwa_Settings::default_stages();
		$lib    = Ciwa_Messages::default_library();

		// Tune timings per industry.
		switch ($id) {
			case 'fashion':
				$stages['cart']['steps'][0]['delay_amount'] = 30;
				$stages['cart']['steps'][0]['delay_unit']   = 'minutes';
				$stages['cart']['steps'][1]['delay_amount'] = 6;
				$stages['cart']['steps'][1]['delay_unit']   = 'hours';
				$stages['register']['steps'][1]['delay_amount'] = 3;
				$stages['register']['steps'][1]['delay_unit']   = 'days';
				break;
			case 'digital':
				$stages['purchase']['steps'][1]['delay_amount'] = 2;
				$stages['purchase']['steps'][1]['delay_unit']   = 'days';
				$stages['failed']['steps'][0]['delay_amount'] = 1;
				$stages['failed']['steps'][0]['delay_unit']   = 'minutes';
				$stages['cart']['enabled'] = 0;
				break;
			case 'b2b':
				$stages['cart']['steps'][0]['delay_amount'] = 4;
				$stages['cart']['steps'][0]['delay_unit']   = 'hours';
				$stages['pending']['steps'][0]['delay_amount'] = 2;
				$stages['pending']['steps'][0]['delay_unit']   = 'hours';
				$stages['register']['enabled'] = 0;
				break;
			default:
				break;
		}

		foreach ($stages as $jk => &$stage) {
			foreach ($stage['steps'] as &$step) {
				$msg = isset($lib[$jk][$step['id']]) ? $lib[$jk][$step['id']] : Ciwa_Messages::default_message($jk, $step['id']);
				if ('b2b' === $id) {
					$msg = preg_replace('/👋|🛒|💚|✅/u', '', $msg);
					$msg = trim(preg_replace('/\s+/u', ' ', $msg));
				}
				$step['message']    = $msg;
				$step['message_b']  = '';
				$step['ab_enabled'] = 0;
				$step['channel']    = 'sms';
			}
			unset($step);
		}
		unset($stage);

		Ciwa_Settings::update(array(
			'stages'      => $stages,
			'template_id' => $id,
		));
		Ciwa_Logger::success('setup', 'قالب سناریو اعمال شد.', array('template' => $id));
		return true;
	}
}
