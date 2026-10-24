<?php
if (!defined('ABSPATH')) {
	exit;
}
$engine = Ciwa_Engine::mode();
?>
<section class="ciwa-welcome">
	<div class="ciwa-welcome-hero">
		<div class="ciwa-welcome-glow"></div>
		<div class="ciwa-welcome-inner">
			<div class="ciwa-welcome-brand">
				<img src="<?php echo esc_url(CIWA_URL . 'assets/img/ciwa-logo-welcome.png'); ?>" alt="CIWA Automation" />
			</div>
			<span class="ciwa-eyebrow">به خانواده سیوا خوش آمدید</span>
			<h2>CIWA Automation</h2>
			<p>
				اتومیشن حرفه‌ای سفر مشتری فروشگاه‌تان را در چند دقیقه راه‌اندازی کنید.
				اول مشخص کنید اجرا روی <strong>موتور داخلی</strong> باشد یا با <strong>n8n</strong> روی سرور جدا —
				بعد سناریو، پیامک و فعال‌سازی.
			</p>
		</div>
	</div>

	<div class="ciwa-card ciwa-card-accent" id="ciwa-engine-picker">
		<div class="ciwa-card-head">
			<h2>انتخاب نحوه اجرا</h2>
			<p>این انتخاب را بعداً از تنظیمات هم می‌توانید عوض کنید، ولی مسیر راه‌اندازی را مشخص می‌کند.</p>
		</div>
		<div class="ciwa-engine-grid">
			<button type="button" class="ciwa-engine-card <?php echo 'internal' === $engine ? 'is-selected' : ''; ?>" data-mode="internal">
				<span class="ciwa-engine-badge">پیشنهادی فروشگاه‌های کوچک و متوسط</span>
				<strong>موتور داخلی</strong>
				<p>صف و کرون داخل وردپرس — بدون n8n. نصب سریع، مناسب شروع فروش.</p>
				<ul>
					<li>بدون سرور اضافه</li>
					<li>ویرایشگر پیامک و ایمیل داخل افزونه</li>
					<li>صف ارسال و تلاش مجدد</li>
				</ul>
			</button>
			<button type="button" class="ciwa-engine-card <?php echo 'n8n' === $engine ? 'is-selected' : ''; ?>" data-mode="n8n">
				<span class="ciwa-engine-badge">مخصوص فروشگاه‌های پرترافیک</span>
				<strong>n8n خارجی</strong>
				<p>فشار پردازش روی سرور جدا — ورکفلو JSON و وب‌هوک. مناسب مقیاس بزرگ.</p>
				<ul>
					<li>کاهش بار روی هاست فروشگاه</li>
					<li>خروجی ورکفلو n8n</li>
					<li>تست و محیط واقعی جدا</li>
				</ul>
			</button>
		</div>
		<button type="button" class="ciwa-btn ciwa-btn-primary ciwa-btn-xl" id="ciwa-start-setup" <?php disabled(!Ciwa_Engine::is_chosen()); ?>>
			ادامه پیکربندی
		</button>
	</div>

	<div class="ciwa-welcome-grid">
		<article class="ciwa-card">
			<h3>۱. قالب سناریو</h3>
			<p>پوشاک، دیجیتال، B2B یا عمومی — با متن پیامک آماده.</p>
		</article>
		<article class="ciwa-card">
			<h3>۲. پیام و کانال</h3>
			<p>پیامک و ایمیل، متغیرها، A/B و ساعات ارسال مجاز.</p>
		</article>
		<article class="ciwa-card">
			<h3>۳. نظارت و ROI</h3>
			<p>صف ارسال، لاگ، بازیابی سبد و درآمد تخمینی.</p>
		</article>
	</div>
</section>
