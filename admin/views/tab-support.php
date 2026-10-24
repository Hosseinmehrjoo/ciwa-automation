<?php
if (!defined('ABSPATH')) {
	exit;
}
?>
<section class="ciwa-support">
	<div class="ciwa-welcome-hero ciwa-support-hero">
		<div class="ciwa-welcome-inner">
			<span class="ciwa-eyebrow">پشتیبانی CIWA</span>
			<h2>اینجا کنار شماییم</h2>
			<p>برای آموزش، رفع اشکال و سفارشی‌سازی اتومیشن فروشگاه‌تان با سازنده در ارتباط باشید.</p>
			<div class="ciwa-actions-row">
				<a class="ciwa-btn ciwa-btn-primary" href="https://hosseinmehrjoo.ir" target="_blank" rel="noopener">hosseinmehrjoo.ir</a>
				<a class="ciwa-btn ciwa-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=ciwa-automation&tab=logs')); ?>">بررسی لاگ‌ها</a>
			</div>
		</div>
	</div>

	<div class="ciwa-grid-2">
		<article class="ciwa-card">
			<h3>اطلاعات افزونه</h3>
			<ul class="ciwa-syslist">
				<li><span>نام</span><strong>CIWA Automation</strong></li>
				<li><span>نسخه</span><strong><?php echo esc_html(CIWA_VERSION); ?></strong></li>
				<li><span>سازنده</span><strong>حسین مهرجو</strong></li>
				<li><span>خانه</span><a href="https://hosseinmehrjoo.ir" target="_blank" rel="noopener">hosseinmehrjoo.ir</a></li>
			</ul>
		</article>
		<article class="ciwa-card">
			<h3>چک‌لیست سریع</h3>
			<ol class="ciwa-checklist">
				<li>فلو و پترن‌ها را ذخیره کرده‌اید؟</li>
				<li>JSON را در n8n ایمپورت و Active کرده‌اید؟</li>
				<li>تست اتصال سبز شده است؟</li>
				<li>آدرس واقعی فعال شده است؟</li>
			</ol>
		</article>
	</div>
</section>
