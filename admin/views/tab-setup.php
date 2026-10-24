<?php
if (!defined('ABSPATH')) {
	exit;
}
/** @var array $settings */
/** @var array $setup */
$report    = isset($setup['last_test_report']) && is_array($setup['last_test_report']) ? $setup['last_test_report'] : null;
$units     = array('minutes' => 'دقیقه', 'hours' => 'ساعت', 'days' => 'روز');
$is_n8n    = Ciwa_Engine::is_n8n();
$is_int    = Ciwa_Engine::is_internal();
$vars      = Ciwa_Messages::variables();
$templates = Ciwa_Templates::all();
$sms_key   = !empty($settings['sms_api_key']) ? $settings['sms_api_key'] : $settings['mediana_api_key'];
$sms_provider = !empty($settings['sms_provider']) ? $settings['sms_provider'] : 'mediana';
$seg = isset($settings['segment']) && is_array($settings['segment']) ? $settings['segment'] : array();
$channel_labels = array('sms' => 'پیامک', 'email' => 'ایمیل', 'both' => 'هر دو');
?>
<section class="ciwa-setup" id="ciwa-setup" data-engine="<?php echo esc_attr(Ciwa_Engine::mode()); ?>">
	<div class="ciwa-wizard-steps">
		<div class="ciwa-wstep is-done">حالت اجرا</div>
		<div class="ciwa-wstep <?php echo (int) $setup['wizard_step'] >= 1 ? 'is-done' : ''; ?>">سناریو</div>
		<div class="ciwa-wstep <?php echo (int) $setup['wizard_step'] >= 2 ? 'is-done' : ''; ?>">پیامک</div>
		<?php if ($is_n8n) : ?>
			<div class="ciwa-wstep <?php echo !empty($setup['flow_built']) ? 'is-done' : ''; ?>">فلو n8n</div>
			<div class="ciwa-wstep <?php echo !empty($setup['test_ok']) ? 'is-done' : ''; ?>">اتصال</div>
		<?php else : ?>
			<div class="ciwa-wstep <?php echo (int) $setup['wizard_step'] >= 3 ? 'is-done' : ''; ?>">قوانین</div>
		<?php endif; ?>
		<div class="ciwa-wstep <?php echo !empty($setup['production_active']) ? 'is-done' : ''; ?>">فعال‌سازی</div>
	</div>

	<div class="ciwa-alert ciwa-alert-info">
		موتور فعلی: <strong><?php echo esc_html(Ciwa_Engine::label()); ?></strong>
		— <?php echo $is_int ? 'ارسال از صف داخلی' : 'ارسال از طریق n8n'; ?>
	</div>

	<div class="ciwa-accordion" id="ciwa-setup-accordion">

		<details class="ciwa-fold" open>
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">۱</span>
				<span class="ciwa-fold-text">
					<strong>قالب سناریو</strong>
					<small>پوشاک، دیجیتال، B2B یا عمومی</small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<div class="ciwa-template-grid">
					<?php foreach ($templates as $tpl) : ?>
						<button type="button" class="ciwa-template-card <?php echo ($settings['template_id'] === $tpl['id']) ? 'is-selected' : ''; ?>" data-template="<?php echo esc_attr($tpl['id']); ?>">
							<strong><?php echo esc_html($tpl['label']); ?></strong>
							<span><?php echo esc_html($tpl['description']); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
		</details>

		<details class="ciwa-fold" open>
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">۲</span>
				<span class="ciwa-fold-text">
					<strong>فلوها و متن پیام</strong>
					<small>روی هر مرحله کلیک کنید تا متن و زمان‌بندی باز شود</small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<div class="ciwa-vars-bar">
					<span>متغیرها:</span>
					<?php foreach ($vars as $vk => $vl) : ?>
						<code class="ciwa-var" title="<?php echo esc_attr($vl); ?>"><?php echo esc_html($vk); ?></code>
					<?php endforeach; ?>
				</div>

				<div class="ciwa-inline-bar">
					<label for="ciwa-cart-minutes">سبد رها‌شده پس از</label>
					<input type="number" min="5" id="ciwa-cart-minutes" value="<?php echo esc_attr((int) $settings['cart_abandon_minutes']); ?>" />
					<span>دقیقه</span>
				</div>

				<div class="ciwa-flows" id="ciwa-flows">
					<?php foreach ($settings['stages'] as $stage_key => $stage) : ?>
						<details class="ciwa-flow ciwa-flow-fold" data-stage="<?php echo esc_attr($stage_key); ?>">
							<summary class="ciwa-flow-sum">
								<label class="ciwa-check ciwa-flow-toggle" onclick="event.stopPropagation()">
									<input type="checkbox" class="ciwa-stage-enabled" <?php checked(!empty($stage['enabled'])); ?> />
								</label>
								<span class="ciwa-flow-sum-title"><?php echo esc_html($stage['label']); ?></span>
								<span class="ciwa-flow-sum-meta"><?php echo count($stage['steps']); ?> مرحله</span>
								<span class="ciwa-fold-chevron" aria-hidden="true"></span>
							</summary>
							<div class="ciwa-flow-steps">
								<?php foreach ($stage['steps'] as $i => $step) :
									$ch = isset($step['channel']) ? $step['channel'] : 'sms';
									$delay_txt = (int) $step['delay_amount'] . ' ' . (isset($units[$step['delay_unit']]) ? $units[$step['delay_unit']] : $step['delay_unit']);
									?>
									<details class="ciwa-step-fold ciwa-step-row" data-index="<?php echo (int) $i; ?>">
										<summary class="ciwa-step-sum">
											<label class="ciwa-check" onclick="event.stopPropagation()">
												<input type="checkbox" class="ciwa-step-enabled" <?php checked(!empty($step['enabled'])); ?> />
											</label>
											<span class="ciwa-step-label"><?php echo esc_html($step['label']); ?></span>
											<span class="ciwa-chip"><?php echo esc_html($delay_txt); ?></span>
											<span class="ciwa-chip ciwa-chip-teal"><?php echo esc_html(isset($channel_labels[$ch]) ? $channel_labels[$ch] : $ch); ?></span>
											<span class="ciwa-fold-chevron" aria-hidden="true"></span>
										</summary>
										<div class="ciwa-step-panel">
											<div class="ciwa-step-grid">
												<div class="ciwa-field">
													<label>تأخیر</label>
													<div class="ciwa-inline-bar">
														<input type="number" min="0" class="ciwa-delay" value="<?php echo esc_attr((int) $step['delay_amount']); ?>" />
														<select class="ciwa-unit">
															<?php foreach ($units as $uk => $ul) : ?>
																<option value="<?php echo esc_attr($uk); ?>" <?php selected($step['delay_unit'], $uk); ?>><?php echo esc_html($ul); ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="ciwa-field">
													<label>کانال</label>
													<select class="ciwa-channel">
														<option value="sms" <?php selected($ch, 'sms'); ?>>پیامک</option>
														<option value="email" <?php selected($ch, 'email'); ?>>ایمیل</option>
														<option value="both" <?php selected($ch, 'both'); ?>>هر دو</option>
													</select>
												</div>
												<div class="ciwa-field">
													<label>کد پترن</label>
													<input type="text" class="ciwa-pattern ltr" placeholder="اختیاری" value="<?php echo esc_attr($step['pattern_code']); ?>" />
												</div>
											</div>
											<div class="ciwa-field">
												<label>متن پیام (نسخه A)</label>
												<textarea class="ciwa-message" rows="3" placeholder="متن پیام…"><?php echo esc_textarea($step['message']); ?></textarea>
											</div>
											<div class="ciwa-ab-box">
												<label class="ciwa-check">
													<input type="checkbox" class="ciwa-ab-enabled" <?php checked(!empty($step['ab_enabled'])); ?> />
													فعال‌سازی تست A/B
												</label>
												<textarea class="ciwa-message-b" rows="2" placeholder="متن جایگزین (نسخه B)" <?php disabled(empty($step['ab_enabled'])); ?>><?php echo esc_textarea($step['message_b']); ?></textarea>
											</div>
											<div class="ciwa-msg-meta">
												<span class="ciwa-char-count">۰ کاراکتر</span>
												<button type="button" class="ciwa-btn ciwa-btn-ghost ciwa-preview-msg">پیش‌نمایش</button>
											</div>
										</div>
									</details>
								<?php endforeach; ?>
							</div>
						</details>
					<?php endforeach; ?>
				</div>

				<div class="ciwa-fold-actions">
					<button type="button" class="ciwa-btn ciwa-btn-secondary" id="ciwa-save-flows">ذخیره فلوها و پیام‌ها</button>
				</div>
			</div>
		</details>

		<details class="ciwa-fold">
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">۳</span>
				<span class="ciwa-fold-text">
					<strong>پنل پیامک و ایمیل</strong>
					<small>API، خط ارسال، تست</small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<div class="ciwa-grid-2">
					<div class="ciwa-field">
						<label>پنل پیامک</label>
						<select id="ciwa-sms-provider">
							<?php foreach (Ciwa_Sms::providers() as $pid => $plabel) : ?>
								<option value="<?php echo esc_attr($pid); ?>" <?php selected($sms_provider, $pid); ?>><?php echo esc_html($plabel); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="ciwa-field">
						<label>API Key / Access Token</label>
						<input type="password" class="ltr" id="ciwa-api-key" value="<?php echo esc_attr($sms_key); ?>" autocomplete="new-password" />
					</div>
					<div class="ciwa-field">
						<label>خط ارسال</label>
						<input type="text" class="ltr" id="ciwa-from" value="<?php echo esc_attr($settings['from_number']); ?>" placeholder="+983000505 یا 9000..." />
					</div>
					<div class="ciwa-field">
						<label>موبایل مدیر (تست)</label>
						<input type="text" class="ltr" id="ciwa-admin-phone" value="<?php echo esc_attr($settings['admin_phone']); ?>" placeholder="09xxxxxxxxx" />
					</div>
					<div class="ciwa-field ciwa-field-span">
						<label class="ciwa-check">
							<input type="checkbox" id="ciwa-email-enabled" <?php checked(!empty($settings['email_enabled'])); ?> />
							فعال‌سازی کانال ایمیل (wp_mail)
						</label>
					</div>
				</div>
				<div class="ciwa-fold-actions">
					<button type="button" class="ciwa-btn ciwa-btn-secondary" id="ciwa-save-sms">ذخیره کانال‌ها</button>
					<button type="button" class="ciwa-btn ciwa-btn-ghost" id="ciwa-test-sms">ارسال پیامک تست</button>
				</div>
			</div>
		</details>

		<?php if ($is_int) : ?>
		<details class="ciwa-fold">
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">۴</span>
				<span class="ciwa-fold-text">
					<strong>قوانین ارسال و سگمنت</strong>
					<small>ساعات سکوت، لیست سیاه، فیلتر</small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<div class="ciwa-grid-2">
					<div class="ciwa-field">
						<label class="ciwa-check">
							<input type="checkbox" id="ciwa-quiet-enabled" <?php checked(!empty($settings['quiet_hours_enabled'])); ?> />
							ساعات سکوت (ارسال نکن)
						</label>
					</div>
					<div class="ciwa-field">
						<label>بازه سکوت</label>
						<div class="ciwa-inline-bar">
							<input type="time" id="ciwa-quiet-start" value="<?php echo esc_attr($settings['quiet_hours_start']); ?>" />
							<span>تا</span>
							<input type="time" id="ciwa-quiet-end" value="<?php echo esc_attr($settings['quiet_hours_end']); ?>" />
						</div>
					</div>
					<div class="ciwa-field">
						<label>کلمات لغو</label>
						<input type="text" id="ciwa-stop-keywords" value="<?php echo esc_attr($settings['stop_keywords']); ?>" />
					</div>
					<div class="ciwa-field">
						<label>حداقل مبلغ سفارش</label>
						<input type="number" min="0" id="ciwa-min-total" value="<?php echo esc_attr((float) ($seg['min_order_total'] ?? 0)); ?>" />
					</div>
					<div class="ciwa-field ciwa-field-span">
						<label>لیست سیاه (هر خط یک شماره)</label>
						<textarea id="ciwa-blacklist" rows="3" class="ltr"><?php echo esc_textarea(implode("\n", (array) $settings['blacklist'])); ?></textarea>
					</div>
					<div class="ciwa-field ciwa-field-span">
						<label class="ciwa-check">
							<input type="checkbox" id="ciwa-first-purchase" <?php checked(!empty($seg['first_purchase_only'])); ?> />
							فقط اولین خرید / ثبت‌نام بدون خرید قبلی
						</label>
					</div>
				</div>
				<div class="ciwa-fold-actions">
					<button type="button" class="ciwa-btn ciwa-btn-secondary" id="ciwa-save-rules">ذخیره قوانین</button>
				</div>
			</div>
		</details>

		<details class="ciwa-fold" <?php echo empty($setup['production_active']) ? 'open' : ''; ?>>
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">۵</span>
				<span class="ciwa-fold-text">
					<strong>فعال‌سازی</strong>
					<small>روشن کردن موتور داخلی</small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<p class="ciwa-muted-note">پس از ذخیره فلو و پیامک، اتومیشن را روشن کنید. صف هر دقیقه پردازش می‌شود.</p>
				<button type="button" class="ciwa-btn ciwa-btn-success ciwa-btn-xl" id="ciwa-activate-internal">
					<?php echo !empty($setup['production_active']) ? 'اتومیشن فعال است ✓' : 'فعال‌سازی اتومیشن داخلی'; ?>
				</button>
			</div>
		</details>
		<?php endif; ?>

		<?php if ($is_n8n) : ?>
		<?php
		$wc_status   = Ciwa_Api_Keys::instance()->status();
		$wc_rest_url = admin_url('admin.php?page=wc-settings&tab=advanced&section=keys');
		?>
		<details class="ciwa-fold" id="ciwa-wc-keys-card">
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">۴</span>
				<span class="ciwa-fold-text">
					<strong>کلید REST ووکامرس</strong>
					<small>برای ساخت فلو n8n</small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<div class="ciwa-grid-2">
					<div class="ciwa-field">
						<label>Consumer Key</label>
						<input type="text" class="ltr" id="ciwa-setup-wc-ck" value="<?php echo esc_attr($settings['wc_ck']); ?>" placeholder="ck_..." autocomplete="off" />
					</div>
					<div class="ciwa-field">
						<label>Consumer Secret</label>
						<input type="text" class="ltr" id="ciwa-setup-wc-cs" value="<?php echo esc_attr($settings['wc_cs']); ?>" placeholder="cs_..." autocomplete="off" />
					</div>
				</div>
				<div class="ciwa-fold-actions">
					<button type="button" class="ciwa-btn ciwa-btn-secondary" id="ciwa-setup-wc-save">ذخیره کلید دستی</button>
					<button type="button" class="ciwa-btn ciwa-btn-ghost" id="ciwa-setup-wc-regen">ساخت خودکار</button>
					<a class="ciwa-btn ciwa-btn-ghost" href="<?php echo esc_url($wc_rest_url); ?>" target="_blank" rel="noopener">صفحه ووکامرس</a>
				</div>
				<p id="ciwa-setup-wc-status" class="ciwa-muted-note" style="color:<?php echo !empty($wc_status['ok']) ? '#1fad63' : '#e5a000'; ?>">
					<?php echo esc_html($wc_status['message']); ?>
				</p>
			</div>
		</details>

		<details class="ciwa-fold ciwa-build-card" open>
			<summary class="ciwa-fold-sum">
				<span class="ciwa-fold-num">۵</span>
				<span class="ciwa-fold-text">
					<strong>ساخت و اتصال n8n</strong>
					<small>JSON، تست، فعال‌سازی</small>
				</span>
				<span class="ciwa-fold-chevron" aria-hidden="true"></span>
			</summary>
			<div class="ciwa-fold-body">
				<button type="button" class="ciwa-btn ciwa-btn-primary" id="ciwa-build-flow">ساخت فلو JSON</button>
				<div id="ciwa-build-result" class="ciwa-build-result" hidden>
					<p>فلو آماده است.</p>
					<button type="button" class="ciwa-btn ciwa-btn-secondary" id="ciwa-download-link">دانلود JSON</button>
				</div>

				<div class="ciwa-n8n-panel <?php echo empty($setup['flow_built']) ? 'is-locked' : ''; ?>" id="ciwa-n8n-panel" style="margin-top:16px">
					<div class="ciwa-grid-2">
						<div class="ciwa-field">
							<label>آدرس n8n تست</label>
							<input type="url" class="ltr" id="ciwa-n8n-test" value="<?php echo esc_attr(Ciwa_Settings::normalize_n8n_base_url($settings['n8n_test_url'])); ?>" placeholder="https://your-n8n.example.com" />
						</div>
						<div class="ciwa-field">
							<label>آدرس n8n واقعی</label>
							<input type="url" class="ltr" id="ciwa-n8n-prod" value="<?php echo esc_attr(Ciwa_Settings::normalize_n8n_base_url($settings['n8n_prod_url'])); ?>" placeholder="https://your-n8n.example.com" />
						</div>
					</div>
					<div class="ciwa-fold-actions">
						<button type="button" class="ciwa-btn ciwa-btn-secondary" id="ciwa-save-n8n">ذخیره آدرس‌ها</button>
						<button type="button" class="ciwa-btn ciwa-btn-primary" id="ciwa-test-conn">تست اتصال</button>
						<button type="button" class="ciwa-btn ciwa-btn-success" id="ciwa-activate-prod" <?php disabled(empty($setup['test_ok'])); ?>>فعال‌سازی واقعی</button>
					</div>
					<div class="ciwa-test-report" id="ciwa-test-report" <?php echo $report ? '' : 'hidden'; ?>>
						<?php if ($report) : ?>
							<div class="ciwa-report-summary <?php echo !empty($report['ok']) ? 'is-ok' : 'is-fail'; ?>">
								<?php echo esc_html($report['summary']); ?>
							</div>
							<ul class="ciwa-report-steps">
								<?php foreach ((array) $report['steps'] as $st) : ?>
									<li class="<?php echo !empty($st['ok']) ? 'ok' : 'fail'; ?>">
										<span class="mark"><?php echo !empty($st['ok']) ? '✓' : '✕'; ?></span>
										<div>
											<strong><?php echo esc_html($st['label']); ?></strong>
											<?php if (!empty($st['detail'])) : ?>
												<small><?php echo esc_html($st['detail']); ?></small>
											<?php endif; ?>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</details>
		<?php endif; ?>

	</div>
</section>

<div class="ciwa-modal" id="ciwa-preview-modal" hidden>
	<div class="ciwa-modal-card">
		<h3>پیش‌نمایش پیام</h3>
		<pre id="ciwa-preview-body" class="ciwa-preview-body"></pre>
		<p id="ciwa-preview-meta" class="ciwa-muted-note"></p>
		<button type="button" class="ciwa-btn ciwa-btn-secondary" id="ciwa-preview-close">بستن</button>
	</div>
</div>
