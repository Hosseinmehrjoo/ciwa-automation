<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_Admin {

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', array($this, 'menu'));
		add_action('admin_enqueue_scripts', array($this, 'assets'));
		add_action('wp_ajax_ciwa_save_flows', array($this, 'ajax_save_flows'));
		add_action('wp_ajax_ciwa_save_sms', array($this, 'ajax_save_sms'));
		add_action('wp_ajax_ciwa_build_flow', array($this, 'ajax_build_flow'));
		add_action('wp_ajax_ciwa_save_n8n', array($this, 'ajax_save_n8n'));
		add_action('wp_ajax_ciwa_test_connection', array($this, 'ajax_test_connection'));
		add_action('wp_ajax_ciwa_activate_prod', array($this, 'ajax_activate_prod'));
		add_action('wp_ajax_ciwa_deactivate', array($this, 'ajax_deactivate'));
		add_action('wp_ajax_ciwa_logs_query', array($this, 'ajax_logs_query'));
		add_action('wp_ajax_ciwa_logs_delete', array($this, 'ajax_logs_delete'));
		add_action('wp_ajax_ciwa_logs_clear', array($this, 'ajax_logs_clear'));
		add_action('wp_ajax_ciwa_dismiss_welcome', array($this, 'ajax_dismiss_welcome'));
		add_action('wp_ajax_ciwa_save_wc_keys', array($this, 'ajax_save_wc_keys'));
		add_action('wp_ajax_ciwa_regen_wc_keys', array($this, 'ajax_regen_wc_keys'));
		add_action('wp_ajax_ciwa_download_workflow', array($this, 'ajax_download_workflow'));
		add_action('wp_ajax_ciwa_factory_reset', array($this, 'ajax_factory_reset'));
		add_action('wp_ajax_ciwa_choose_engine', array($this, 'ajax_choose_engine'));
		add_action('wp_ajax_ciwa_apply_template', array($this, 'ajax_apply_template'));
		add_action('wp_ajax_ciwa_save_rules', array($this, 'ajax_save_rules'));
		add_action('wp_ajax_ciwa_activate_internal', array($this, 'ajax_activate_internal'));
		add_action('wp_ajax_ciwa_test_sms', array($this, 'ajax_test_sms'));
		add_action('wp_ajax_ciwa_preview_message', array($this, 'ajax_preview_message'));
		add_action('wp_ajax_ciwa_queue_query', array($this, 'ajax_queue_query'));
		add_action('wp_ajax_ciwa_queue_retry', array($this, 'ajax_queue_retry'));
		add_action('wp_ajax_ciwa_queue_run', array($this, 'ajax_queue_run'));
		add_action('admin_post_ciwa_download_workflow', array($this, 'download_workflow'));
	}

	public function menu() {
		add_menu_page(
			'CIWA Automation',
			'CIWA Automation',
			'manage_woocommerce',
			'ciwa-automation',
			array($this, 'render'),
			CIWA_URL . 'assets/img/ciwa-menu.png',
			56
		);
	}

	public function assets($hook) {
		// Tiny menu icon CSS only (no external assets on every admin page).
		wp_register_style('ciwa-admin-menu', false, array(), CIWA_VERSION);
		wp_enqueue_style('ciwa-admin-menu');
		wp_add_inline_style(
			'ciwa-admin-menu',
			'#adminmenu #toplevel_page_ciwa-automation .wp-menu-image img{width:20px;height:20px;padding:6px 0 0;opacity:1!important;}'
		);

		if (false === strpos($hook, 'ciwa-automation')) {
			return;
		}

		$deps_css = array();
		// System/Vazirmatn stack in CSS — skip remote font CDN for speed & privacy.
		wp_enqueue_style('ciwa-admin', CIWA_URL . 'admin/css/admin.css', $deps_css, CIWA_VERSION);

		$tab = isset($_GET['tab']) ? Ciwa_Security::sanitize_tab(wp_unslash($_GET['tab'])) : '';
		$js_deps = array('jquery');
		if ('dashboard' === $tab || ('' === $tab && !empty(Ciwa_Settings::setup()['production_active']))) {
			wp_enqueue_script('chart-js', CIWA_URL . 'assets/js/chart.umd.min.js', array(), '4.4.1', true);
			$js_deps[] = 'chart-js';
		}

		wp_enqueue_script('ciwa-admin', CIWA_URL . 'admin/js/admin.js', $js_deps, CIWA_VERSION, true);
		wp_localize_script('ciwa-admin', 'CiwaAdmin', array(
			'ajax'   => admin_url('admin-ajax.php'),
			'nonce'  => wp_create_nonce('ciwa_admin'),
			'home'   => 'https://hosseinmehrjoo.ir',
			'engine' => Ciwa_Engine::mode(),
			'i18n'   => array(
				'saving'       => 'در حال ذخیره…',
				'success'      => 'انجام شد',
				'error'        => 'خطا',
				'testing'      => 'در حال تست اتصال…',
				'confirmClear' => 'همه لاگ‌ها حذف شوند؟',
				'chooseEngine' => 'ابتدا نحوه اجرا را انتخاب کنید.',
			),
		));
	}

	public function render() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('دسترسی ندارید.', 'ciwa-automation'));
		}
		$setup    = Ciwa_Settings::setup();
		$settings = Ciwa_Settings::get();
		$tab      = isset($_GET['tab']) ? Ciwa_Security::sanitize_tab(wp_unslash($_GET['tab'])) : '';

		if ((empty($setup['welcome_seen']) || empty($setup['engine_chosen']) || !Ciwa_Engine::is_chosen()) && 'welcome' !== $tab) {
			$tab = 'welcome';
		}
		if (!$tab) {
			$tab = !empty($setup['production_active']) ? 'dashboard' : 'setup';
		}
		$tab = Ciwa_Security::sanitize_tab($tab);
		if (!$tab) {
			$tab = 'setup';
		}

		$stats = ('dashboard' === $tab) ? Ciwa_Stats::dashboard() : array();
		include CIWA_PATH . 'admin/views/shell.php';
	}

	private function guard_ajax() {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'دسترسی ندارید.'), 403);
		}
		check_ajax_referer('ciwa_admin', 'nonce');
	}

	public function ajax_dismiss_welcome() {
		$this->guard_ajax();
		if (!Ciwa_Engine::is_chosen()) {
			wp_send_json_error(array('message' => 'ابتدا نحوه اجرا را انتخاب کنید.'));
		}
		Ciwa_Settings::update_setup(array('welcome_seen' => 1, 'wizard_step' => 1));
		wp_send_json_success(array('redirect' => admin_url('admin.php?page=ciwa-automation&tab=setup')));
	}

	public function ajax_choose_engine() {
		$this->guard_ajax();
		$mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
		$result = Ciwa_Engine::set_mode($mode);
		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}
		if (Ciwa_Engine::MODE_INTERNAL === $mode) {
			Ciwa_Queue::create_table();
			delete_transient('ciwa_cron_checked');
		} else {
			Ciwa_Internal_Runner::unschedule_cron();
			delete_transient('ciwa_cron_checked');
			delete_transient('ciwa_queue_lock');
		}
		wp_send_json_success(array(
			'message' => 'حالت اجرا ذخیره شد: ' . Ciwa_Engine::label(),
			'mode'    => $mode,
			'reload'  => true,
		));
	}

	public function ajax_apply_template() {
		$this->guard_ajax();
		$id = isset($_POST['template']) ? sanitize_key(wp_unslash($_POST['template'])) : '';
		$result = Ciwa_Templates::apply($id);
		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}
		wp_send_json_success(array(
			'message'  => 'قالب اعمال شد. صفحه تازه می‌شود.',
			'redirect' => admin_url('admin.php?page=ciwa-automation&tab=setup'),
		));
	}

	public function ajax_save_flows() {
		$this->guard_ajax();
		$raw = isset($_POST['stages']) ? json_decode(wp_unslash($_POST['stages']), true) : null;
		if (!is_array($raw)) {
			wp_send_json_error(array('message' => 'داده نامعتبر است.'));
		}
		$stages   = Ciwa_Settings::normalize_stages($raw);
		$cart_min = isset($_POST['cart_abandon_minutes']) ? max(5, absint($_POST['cart_abandon_minutes'])) : 45;
		Ciwa_Settings::update(array('stages' => $stages, 'cart_abandon_minutes' => $cart_min));
		Ciwa_Settings::update_setup(array('wizard_step' => max(1, (int) Ciwa_Settings::setup()['wizard_step'])));
		Ciwa_Logger::info('setup', 'فلوها و پیام‌ها ذخیره شد.');
		wp_send_json_success(array('message' => 'فلوها و متن پیام‌ها ذخیره شد.'));
	}

	public function ajax_save_sms() {
		$this->guard_ajax();
		$provider = isset($_POST['sms_provider']) ? sanitize_key(wp_unslash($_POST['sms_provider'])) : 'mediana';
		if (!isset(Ciwa_Sms::PROVIDERS[$provider])) {
			$provider = 'mediana';
		}
		$api_key = '';
		if (isset($_POST['sms_api_key'])) {
			$api_key = sanitize_text_field(wp_unslash($_POST['sms_api_key']));
		} elseif (isset($_POST['mediana_api_key'])) {
			$api_key = sanitize_text_field(wp_unslash($_POST['mediana_api_key']));
		}
		Ciwa_Settings::update(array(
			'sms_provider'    => $provider,
			'sms_api_key'     => $api_key,
			'mediana_api_key' => $api_key,
			'from_number'     => isset($_POST['from_number']) ? sanitize_text_field(wp_unslash($_POST['from_number'])) : '',
			'admin_phone'     => isset($_POST['admin_phone']) ? sanitize_text_field(wp_unslash($_POST['admin_phone'])) : '',
			'email_enabled'   => !empty($_POST['email_enabled']) ? 1 : 0,
		));
		Ciwa_Settings::update_setup(array('wizard_step' => 2));
		Ciwa_Logger::info('setup', 'اطلاعات پنل پیامک ذخیره شد.', array('provider' => $provider));
		wp_send_json_success(array('message' => 'اطلاعات پیامک ذخیره شد (' . Ciwa_Sms::PROVIDERS[$provider] . ').'));
	}

	public function ajax_save_rules() {
		$this->guard_ajax();
		$blacklist_raw = isset($_POST['blacklist']) ? (string) wp_unslash($_POST['blacklist']) : '';
		$lines = preg_split('/\R+/', $blacklist_raw);
		$blacklist = array();
		foreach ((array) $lines as $line) {
			$n = Ciwa_Sms::normalize_phone(trim($line), 'local09');
			if ($n) {
				$blacklist[] = $n;
			}
		}
		$seg = Ciwa_Settings::get()['segment'];
		if (!is_array($seg)) {
			$seg = array();
		}
		$seg['min_order_total']     = isset($_POST['min_order_total']) ? (float) $_POST['min_order_total'] : 0;
		$seg['first_purchase_only'] = !empty($_POST['first_purchase_only']) ? 1 : 0;

		Ciwa_Settings::update(array(
			'quiet_hours_enabled' => !empty($_POST['quiet_hours_enabled']) ? 1 : 0,
			'quiet_hours_start'   => Ciwa_Security::sanitize_hhmm(isset($_POST['quiet_hours_start']) ? wp_unslash($_POST['quiet_hours_start']) : '22:00', '22:00'),
			'quiet_hours_end'     => Ciwa_Security::sanitize_hhmm(isset($_POST['quiet_hours_end']) ? wp_unslash($_POST['quiet_hours_end']) : '08:00', '08:00'),
			'stop_keywords'       => isset($_POST['stop_keywords']) ? sanitize_text_field(wp_unslash($_POST['stop_keywords'])) : 'لغو,stop',
			'blacklist'           => array_values(array_unique($blacklist)),
			'segment'             => $seg,
		));
		Ciwa_Settings::update_setup(array('wizard_step' => 3));
		wp_send_json_success(array('message' => 'قوانین ارسال ذخیره شد.'));
	}

	public function ajax_activate_internal() {
		$this->guard_ajax();
		$rl = Ciwa_Security::rate_limit('activate', 10, 120);
		if (is_wp_error($rl)) {
			wp_send_json_error(array('message' => $rl->get_error_message()));
		}
		if (!Ciwa_Engine::is_internal()) {
			wp_send_json_error(array('message' => 'موتور فعلی داخلی نیست.'));
		}
		$s = Ciwa_Settings::get();
		if (!Ciwa_Sms::api_key() || empty($s['from_number'])) {
			wp_send_json_error(array('message' => 'ابتدا API Key و خط ارسال پیامک را ذخیره کنید.'));
		}
		$enabled = 0;
		foreach ($s['stages'] as $stage) {
			if (!empty($stage['enabled'])) {
				foreach ($stage['steps'] as $step) {
					if (!empty($step['enabled'])) {
						$enabled++;
					}
				}
			}
		}
		if ($enabled < 1) {
			wp_send_json_error(array('message' => 'حداقل یک مرحله فلو را فعال کنید.'));
		}
		Ciwa_Queue::create_table();
		delete_transient('ciwa_cron_checked');
		Ciwa_Internal_Runner::instance()->ensure_cron();
		Ciwa_Settings::update_setup(array(
			'production_active' => 1,
			'test_ok'           => 1,
			'flow_built'        => 1,
			'wizard_step'       => 4,
			'activated_at'      => gmdate('c'),
		));
		Ciwa_Logger::success('setup', 'موتور داخلی فعال شد.');
		wp_send_json_success(array(
			'message'  => 'اتومیشن داخلی فعال شد.',
			'redirect' => admin_url('admin.php?page=ciwa-automation&tab=dashboard'),
		));
	}

	public function ajax_test_sms() {
		$this->guard_ajax();
		$rl = Ciwa_Security::rate_limit('test_sms', 3, 120);
		if (is_wp_error($rl)) {
			wp_send_json_error(array('message' => $rl->get_error_message()));
		}
		$s = Ciwa_Settings::get();
		$phone = !empty($s['admin_phone']) ? $s['admin_phone'] : '';
		if (!$phone) {
			wp_send_json_error(array('message' => 'موبایل مدیر را وارد و ذخیره کنید.'));
		}
		$msg = 'تست CIWA Automation — ' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
		$result = Ciwa_Sms::send($phone, $msg);
		if (empty($result['ok'])) {
			wp_send_json_error(array('message' => isset($result['detail']) ? $result['detail'] : 'ارسال ناموفق'));
		}
		wp_send_json_success(array('message' => 'پیامک تست ارسال شد.'));
	}

	public function ajax_preview_message() {
		$this->guard_ajax();
		$text = isset($_POST['message']) ? wp_unslash($_POST['message']) : '';
		if (mb_strlen($text) > 2000) {
			$text = mb_substr($text, 0, 2000);
		}
		$rendered = Ciwa_Messages::render(sanitize_textarea_field($text), array(
			'first_name'  => 'علی',
			'last_name'   => 'رضایی',
			'phone'       => '09121234567',
			'email'       => 'ali@example.com',
			'order_id'    => '1042',
			'order_total' => '1,250,000 تومان',
			'pay_url'     => home_url('/checkout/order-pay/1042/'),
		));
		$meta = Ciwa_Messages::sms_length($rendered);
		wp_send_json_success(array(
			'preview' => $rendered,
			'meta'    => $meta,
		));
	}

	public function ajax_queue_query() {
		$this->guard_ajax();
		$result = Ciwa_Queue::query(array(
			'page'     => isset($_POST['page']) ? absint($_POST['page']) : 1,
			'per_page' => 20,
			'status'   => isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '',
		));
		wp_send_json_success($result);
	}

	public function ajax_queue_retry() {
		$this->guard_ajax();
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		if (!$id || !Ciwa_Queue::retry($id)) {
			wp_send_json_error(array('message' => 'کار یافت نشد.'));
		}
		wp_send_json_success(array('message' => 'کار برای ارسال مجدد در صف قرار گرفت.'));
	}

	public function ajax_queue_run() {
		$this->guard_ajax();
		$rl = Ciwa_Security::rate_limit('queue_run', 10, 60);
		if (is_wp_error($rl)) {
			wp_send_json_error(array('message' => $rl->get_error_message()));
		}
		Ciwa_Internal_Runner::instance()->process_queue();
		wp_send_json_success(array(
			'message' => 'صف پردازش شد.',
			'counts'  => Ciwa_Queue::counts(),
		));
	}

	public function ajax_save_wc_keys() {
		$this->guard_ajax();
		$ck = isset($_POST['wc_ck']) ? sanitize_text_field(wp_unslash($_POST['wc_ck'])) : '';
		$cs = isset($_POST['wc_cs']) ? sanitize_text_field(wp_unslash($_POST['wc_cs'])) : '';
		$result = Ciwa_Api_Keys::instance()->save_manual_keys($ck, $cs);
		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}
		wp_send_json_success(array('message' => 'کلید ذخیره و تأیید شد.'));
	}

	public function ajax_regen_wc_keys() {
		$this->guard_ajax();
		Ciwa_Settings::update(array('wc_ck' => '', 'wc_cs' => ''));
		$keys = Ciwa_Api_Keys::instance()->ensure_keys();
		if (is_wp_error($keys)) {
			wp_send_json_error(array('message' => $keys->get_error_message()));
		}
		wp_send_json_success(array(
			'message' => 'کلید جدید ساخته و تأیید شد.',
			'wc_ck'   => $keys['ck'],
			'wc_cs'   => $keys['cs'],
		));
	}

	public function ajax_build_flow() {
		$this->guard_ajax();
		$s = Ciwa_Settings::get();
		if (!Ciwa_Sms::api_key() || empty($s['from_number'])) {
			wp_send_json_error(array('message' => 'ابتدا پنل پیامک، API Key و خط ارسال را تکمیل کنید.'));
		}

		$enabled_stages = 0;
		$enabled_steps  = 0;
		foreach ($s['stages'] as $stage) {
			if (empty($stage['enabled'])) {
				continue;
			}
			$enabled_stages++;
			foreach ($stage['steps'] as $step) {
				if (!empty($step['enabled'])) {
					$enabled_steps++;
				}
			}
		}
		if ($enabled_stages < 1 || $enabled_steps < 1) {
			wp_send_json_error(array(
				'message' => 'هیچ مرحله‌ای از فلوها فعال نیست. در بخش «تعیین فلوها» حداقل یک مرحله (مثلاً ثبت‌نام یا پرداخت) را فعال و ذخیره کنید، بعد دوباره ساخت فلو بزنید.',
			));
		}

		// Prefer existing valid keys; only auto-create if missing.
		$keys = null;
		if (!empty($s['wc_ck']) && !empty($s['wc_cs'])) {
			$verify = Ciwa_Api_Keys::instance()->verify_keys($s['wc_ck'], $s['wc_cs']);
			if (!is_wp_error($verify)) {
				$keys = array('ck' => $s['wc_ck'], 'cs' => $s['wc_cs']);
			}
		}
		if (!$keys) {
			$keys = Ciwa_Api_Keys::instance()->ensure_keys();
		}
		if (is_wp_error($keys)) {
			wp_send_json_error(array(
				'message' => $keys->get_error_message(),
				'need_wc_keys' => true,
			));
		}
		$exporter = new Ciwa_Workflow_Exporter(Ciwa_Settings::get());
		$json     = $exporter->export_json();
		$filename = 'ciwa-automation-n8n-' . gmdate('Ymd-His') . '.json';

		Ciwa_Settings::update_setup(array('flow_built' => 1, 'wizard_step' => 3));
		Ciwa_Logger::success('setup', 'فلو آماده شد. مرحله اتصال n8n.');
		wp_send_json_success(array(
			'message'       => 'فلو ساخته شد. فایل JSON آماده دانلود است.',
			'filename'      => $filename,
			'workflow_json' => $json,
		));
	}

	public function ajax_factory_reset() {
		$this->guard_ajax();
		$rl = Ciwa_Security::rate_limit('factory_reset', 2, 300);
		if (is_wp_error($rl)) {
			wp_send_json_error(array('message' => $rl->get_error_message()));
		}
		$confirm = isset($_POST['confirm']) ? sanitize_text_field(wp_unslash($_POST['confirm'])) : '';
		if ('RESET' !== $confirm) {
			wp_send_json_error(array('message' => 'برای تأیید، عبارت RESET را وارد کنید.'));
		}
		$result = Ciwa_Reset::run();
		wp_send_json_success(array(
			'message'  => 'همه اطلاعات پاک شد. افزونه مثل نصب تازه است.',
			'redirect' => admin_url('admin.php?page=ciwa-automation&tab=welcome'),
			'details'  => $result['details'],
		));
	}

	public function ajax_download_workflow() {
		$this->guard_ajax();
		$s = Ciwa_Settings::get();
		if (empty($s['wc_ck']) || empty($s['wc_cs'])) {
			$keys = Ciwa_Api_Keys::instance()->ensure_keys();
			if (is_wp_error($keys)) {
				wp_send_json_error(array('message' => $keys->get_error_message()));
			}
		}
		$exporter = new Ciwa_Workflow_Exporter(Ciwa_Settings::get());
		$json     = $exporter->export_json();
		$filename = 'ciwa-automation-n8n-' . gmdate('Ymd-His') . '.json';
		Ciwa_Logger::info('setup', 'دانلود مجدد ورکفلو JSON.');
		wp_send_json_success(array(
			'filename'      => $filename,
			'workflow_json' => $json,
		));
	}

	public function ajax_save_n8n() {
		$this->guard_ajax();
		$test_raw = isset($_POST['n8n_test_url']) ? trim(wp_unslash($_POST['n8n_test_url'])) : '';
		$prod_raw = isset($_POST['n8n_prod_url']) ? trim(wp_unslash($_POST['n8n_prod_url'])) : '';
		$test = '' === $test_raw ? '' : Ciwa_Settings::normalize_n8n_base_url($test_raw);
		$prod = '' === $prod_raw ? '' : Ciwa_Settings::normalize_n8n_base_url($prod_raw);
		if ($test_raw && '' === $test) {
			wp_send_json_error(array('message' => 'آدرس تست n8n نامعتبر است (فقط http/https).'));
		}
		if ($prod_raw && '' === $prod) {
			wp_send_json_error(array('message' => 'آدرس واقعی n8n نامعتبر است (فقط http/https).'));
		}
		Ciwa_Settings::update(array(
			'n8n_test_url' => $test,
			'n8n_prod_url' => $prod,
		));
		Ciwa_Logger::info('setup', 'آدرس‌های n8n ذخیره شد.', array('test' => $test, 'prod' => $prod));
		wp_send_json_success(array(
			'message'      => 'آدرس‌ها ذخیره شد.',
			'n8n_test_url' => $test,
			'n8n_prod_url' => $prod,
		));
	}

	public function ajax_test_connection() {
		$this->guard_ajax();
		$test = isset($_POST['n8n_test_url']) ? esc_url_raw(trim(wp_unslash($_POST['n8n_test_url']))) : '';
		if ($test) {
			Ciwa_Settings::update(array('n8n_test_url' => Ciwa_Settings::normalize_n8n_base_url($test)));
		}
		$tester = new Ciwa_Connection_Tester();
		$report = $tester->run();
		wp_send_json_success(array(
			'report'       => $report,
			'n8n_test_url' => Ciwa_Settings::get()['n8n_test_url'],
		));
	}

	public function ajax_activate_prod() {
		$this->guard_ajax();
		if (!Ciwa_Engine::is_n8n()) {
			wp_send_json_error(array('message' => 'برای فعال‌سازی n8n، حالت اجرا باید n8n باشد.'));
		}
		$prod = isset($_POST['n8n_prod_url']) ? esc_url_raw(trim(wp_unslash($_POST['n8n_prod_url']))) : '';
		if ($prod) {
			Ciwa_Settings::update(array('n8n_prod_url' => Ciwa_Settings::normalize_n8n_base_url($prod)));
		}
		$s = Ciwa_Settings::get();
		if (empty($s['n8n_prod_url'])) {
			wp_send_json_error(array('message' => 'آدرس واقعی n8n را وارد کنید.'));
		}
		$setup = Ciwa_Settings::setup();
		if (empty($setup['test_ok'])) {
			wp_send_json_error(array('message' => 'ابتدا اتصال تست را با موفقیت انجام دهید.'));
		}
		$keys = Ciwa_Api_Keys::instance()->ensure_keys();
		if (is_wp_error($keys)) {
			wp_send_json_error(array('message' => $keys->get_error_message()));
		}
		$sync = Ciwa_Webhook_Manager::instance()->sync_webhooks('prod');
		if (is_wp_error($sync)) {
			wp_send_json_error(array('message' => $sync->get_error_message()));
		}
		Ciwa_Settings::update_setup(array('production_active' => 1, 'wizard_step' => 4));
		Ciwa_Logger::success('setup', 'اتومیشن واقعی فعال شد.', array('url' => $s['n8n_prod_url']));
		wp_send_json_success(array(
			'message'  => 'اتومیشن فعال شد.',
			'redirect' => admin_url('admin.php?page=ciwa-automation&tab=dashboard'),
		));
	}

	public function ajax_deactivate() {
		$this->guard_ajax();
		if (Ciwa_Engine::is_n8n()) {
			Ciwa_Webhook_Manager::instance()->remove_webhooks();
		}
		if (Ciwa_Engine::is_internal()) {
			Ciwa_Internal_Runner::unschedule_cron();
			delete_transient('ciwa_cron_checked');
			delete_transient('ciwa_queue_lock');
		}
		Ciwa_Settings::update_setup(array('production_active' => 0));
		Ciwa_Logger::warning('setup', 'اتومیشن متوقف شد.');
		wp_send_json_success(array('message' => 'متوقف شد.'));
	}

	public function ajax_logs_query() {
		$this->guard_ajax();
		$result = Ciwa_Logger::query(array(
			'page'     => isset($_POST['page']) ? absint($_POST['page']) : 1,
			'per_page' => isset($_POST['per_page']) ? absint($_POST['per_page']) : 40,
			'level'    => isset($_POST['level']) ? sanitize_key(wp_unslash($_POST['level'])) : '',
			'channel'  => isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : '',
			'search'   => isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
		));
		wp_send_json_success($result);
	}

	public function ajax_logs_delete() {
		$this->guard_ajax();
		$ids = isset($_POST['ids']) ? (array) $_POST['ids'] : array();
		$n   = Ciwa_Logger::delete($ids);
		Ciwa_Logger::info('logs', "{$n} لاگ حذف شد.");
		wp_send_json_success(array('deleted' => $n));
	}

	public function ajax_logs_clear() {
		$this->guard_ajax();
		$mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'all';
		if ('old' === $mode) {
			$n = Ciwa_Logger::clear_older_than(30);
		} else {
			$n = Ciwa_Logger::clear_all();
		}
		wp_send_json_success(array('cleared' => $n));
	}

	public function download_workflow() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('دسترسی ندارید.');
		}
		// Prefer $_REQUEST nonce (works for GET download links).
		$nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
		if (!$nonce || !wp_verify_nonce($nonce, 'ciwa_download_workflow')) {
			wp_die('نشست امنیتی منقضی شده. لطفاً به صفحه CIWA برگردید و دوباره دانلود کنید.');
		}
		$keys = Ciwa_Api_Keys::instance()->ensure_keys();
		if (is_wp_error($keys)) {
			wp_die(esc_html($keys->get_error_message()));
		}
		$exporter = new Ciwa_Workflow_Exporter(Ciwa_Settings::get());
		$json     = $exporter->export_json();
		$filename = 'ciwa-automation-n8n-' . gmdate('Ymd-His') . '.json';
		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		echo $json; // phpcs:ignore
		exit;
	}
}
