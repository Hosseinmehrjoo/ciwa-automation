<?php
if (!defined('ABSPATH')) {
	exit;
}
/** @var array $settings */
/** @var array $setup */
$webhooks = class_exists('WooCommerce') ? Ciwa_Webhook_Manager::instance()->get_status() : array();
$set_sms_key = !empty($settings['sms_api_key']) ? $settings['sms_api_key'] : $settings['mediana_api_key'];
$set_provider = !empty($settings['sms_provider']) ? $settings['sms_provider'] : 'mediana';
?>
<section class="ciwa-settings">
	<div class="ciwa-accordion">

		<details class="ciwa-fold" open>
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">۱</span>
				<span class="ciwa-fold-text">
					<strong>حالت اجرا</strong>
					<small>موتور داخلی یا n8n — فعلی: <?php echo esc_html(Ciwa_Engine::label()); ?></small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<div class="ciwa-engine-grid">
					<button type="button" class="ciwa-engine-card <?php echo Ciwa_Engine::is_internal() ? 'is-selected' : ''; ?>" id="ciwa-set-mode-internal" data-mode="internal">
						<strong>موتور داخلی</strong>
						<p>صف و کرون داخل وردپرس</p>
					</button>
					<button type="button" class="ciwa-engine-card <?php echo Ciwa_Engine::is_n8n() ? 'is-selected' : ''; ?>" id="ciwa-set-mode-n8n" data-mode="n8n">
						<strong>n8n خارجی</strong>
						<p>وب‌هوک و ورکفلو جدا</p>
					</button>
				</div>
			</div>
		</details>

		<details class="ciwa-fold" open>
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">۲</span>
				<span class="ciwa-fold-text">
					<strong>اتصال‌ها</strong>
					<small>پیامک<?php echo Ciwa_Engine::is_n8n() ? ' و n8n' : ''; ?></small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<div class="ciwa-grid-2">
					<?php if (Ciwa_Engine::is_n8n()) : ?>
					<div class="ciwa-field">
						<label>n8n تست</label>
						<input type="url" class="ltr" id="ciwa-set-test" value="<?php echo esc_attr($settings['n8n_test_url']); ?>" />
					</div>
					<div class="ciwa-field">
						<label>n8n واقعی</label>
						<input type="url" class="ltr" id="ciwa-set-prod" value="<?php echo esc_attr($settings['n8n_prod_url']); ?>" />
					</div>
					<?php endif; ?>
					<div class="ciwa-field">
						<label>پنل پیامک</label>
						<select id="ciwa-set-provider">
							<?php foreach (Ciwa_Sms::providers() as $pid => $plabel) : ?>
								<option value="<?php echo esc_attr($pid); ?>" <?php selected($set_provider, $pid); ?>><?php echo esc_html($plabel); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="ciwa-field">
						<label>API Key / Token</label>
						<input type="password" class="ltr" id="ciwa-set-key" value="<?php echo esc_attr($set_sms_key); ?>" autocomplete="new-password" />
					</div>
					<div class="ciwa-field">
						<label>خط ارسال</label>
						<input type="text" class="ltr" id="ciwa-set-from" value="<?php echo esc_attr($settings['from_number']); ?>" />
					</div>
					<div class="ciwa-field">
						<label>موبایل مدیر</label>
						<input type="text" class="ltr" id="ciwa-set-phone" value="<?php echo esc_attr($settings['admin_phone']); ?>" />
					</div>
				</div>
				<div class="ciwa-fold-actions">
					<button type="button" class="ciwa-btn ciwa-btn-primary" id="ciwa-settings-save">ذخیره تنظیمات</button>
				</div>
			</div>
		</details>

		<?php if (Ciwa_Engine::is_n8n()) : ?>
		<details class="ciwa-fold">
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">۳</span>
				<span class="ciwa-fold-text">
					<strong>کلید REST ووکامرس</strong>
					<small>برای n8n</small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<div class="ciwa-grid-2">
					<div class="ciwa-field">
						<label>Consumer Key</label>
						<input type="text" class="ltr" id="ciwa-wc-ck" value="<?php echo esc_attr($settings['wc_ck']); ?>" placeholder="ck_..." autocomplete="off" />
					</div>
					<div class="ciwa-field">
						<label>Consumer Secret</label>
						<input type="text" class="ltr" id="ciwa-wc-cs" value="<?php echo esc_attr($settings['wc_cs']); ?>" placeholder="cs_..." autocomplete="off" />
					</div>
				</div>
				<div class="ciwa-fold-actions">
					<button type="button" class="ciwa-btn ciwa-btn-secondary" id="ciwa-wc-keys-save">ذخیره و تست کلید</button>
					<button type="button" class="ciwa-btn ciwa-btn-ghost" id="ciwa-wc-keys-regen">ساخت خودکار کلید جدید</button>
				</div>
				<p class="ciwa-muted-note" id="ciwa-wc-keys-status"></p>
			</div>
		</details>

		<details class="ciwa-fold">
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">۴</span>
				<span class="ciwa-fold-text">
					<strong>وبهوک‌ها و خروجی</strong>
					<small>وضعیت وبهوک و دانلود JSON</small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<?php if (!$webhooks) : ?>
					<p class="ciwa-muted-note">هنوز وبهوکی ساخته نشده.</p>
				<?php else : ?>
					<ul class="ciwa-syslist">
						<?php foreach ($webhooks as $k => $wh) : ?>
							<li>
								<span><?php echo esc_html($k); ?> (<?php echo esc_html($wh['topic']); ?>)</span>
								<code class="ltr"><?php echo esc_html($wh['status'] . ' — ' . $wh['url']); ?></code>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<div class="ciwa-fold-actions">
					<button type="button" class="ciwa-btn ciwa-btn-secondary" id="ciwa-redownload-workflow">دانلود مجدد JSON</button>
				</div>
			</div>
		</details>
		<?php endif; ?>

		<details class="ciwa-fold ciwa-danger-zone">
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">!</span>
				<span class="ciwa-fold-text">
					<strong>منطقه خطر</strong>
					<small>پاک کردن کامل اطلاعات افزونه</small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<p class="ciwa-muted-note">تمام تنظیمات، لاگ‌ها و متادیتا پاک می‌شود و افزونه مثل نصب تازه برمی‌گردد.</p>
				<div class="ciwa-field">
					<label>برای تأیید، کلمه <code class="ltr">RESET</code> را بنویسید</label>
					<input type="text" class="ltr" id="ciwa-reset-confirm" placeholder="RESET" autocomplete="off" />
				</div>
				<button type="button" class="ciwa-btn ciwa-btn-danger" id="ciwa-factory-reset">پاک کردن تمام اطلاعات افزونه</button>
			</div>
		</details>

	</div>
</section>
