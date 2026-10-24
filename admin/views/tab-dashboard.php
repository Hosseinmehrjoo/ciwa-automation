<?php
if (!defined('ABSPATH')) {
	exit;
}
/** @var array $stats */
/** @var array $setup */
$roi   = isset($stats['roi']) ? $stats['roi'] : array();
$queue = isset($stats['queue']) ? $stats['queue'] : array();
?>
<section class="ciwa-dashboard">
	<?php if (empty($setup['production_active'])) : ?>
		<div class="ciwa-alert">
			اتومیشن هنوز فعال نیست.
			<a href="<?php echo esc_url(admin_url('admin.php?page=ciwa-automation&tab=setup')); ?>">رفتن به پیکربندی</a>
		</div>
	<?php endif; ?>

	<div class="ciwa-kpi-grid">
		<div class="ciwa-kpi">
			<span>وضعیت</span>
			<strong><?php echo !empty($stats['active']) ? 'فعال' : 'خاموش'; ?></strong>
		</div>
		<div class="ciwa-kpi">
			<span>موتور</span>
			<strong><?php echo esc_html(isset($stats['engine_label']) ? $stats['engine_label'] : '—'); ?></strong>
		</div>
		<div class="ciwa-kpi">
			<span>سفارش ۷ روز</span>
			<strong><?php echo (int) $stats['orders_7d']['total']; ?></strong>
		</div>
		<div class="ciwa-kpi">
			<span>پرداخت موفق</span>
			<strong class="ok"><?php echo (int) $stats['orders_7d']['paid']; ?></strong>
		</div>
		<div class="ciwa-kpi">
			<span>پیامک ارسال‌شده</span>
			<strong><?php echo (int) ($roi['sms_sent'] ?? 0); ?></strong>
		</div>
		<div class="ciwa-kpi">
			<span>سفارش بازیابی‌شده</span>
			<strong class="ok"><?php echo (int) ($roi['recovered_orders'] ?? 0); ?></strong>
		</div>
		<div class="ciwa-kpi">
			<span>درآمد بازیابی</span>
			<strong class="ok"><?php echo esc_html(number_format_i18n((float) ($roi['recovered_revenue'] ?? 0))); ?></strong>
		</div>
		<div class="ciwa-kpi">
			<span>خطاهای لاگ</span>
			<strong class="err"><?php echo (int) $stats['logs_error']; ?></strong>
		</div>
	</div>

	<?php if (Ciwa_Engine::is_internal()) : ?>
	<div class="ciwa-card">
		<div class="ciwa-card-head">
			<h2>صف ارسال (موتور داخلی)</h2>
			<p>کارهای زمان‌بندی‌شده، ارسال‌شده و ناموفق.</p>
		</div>
		<div class="ciwa-kpi-grid ciwa-kpi-grid-sm">
			<div class="ciwa-kpi"><span>در انتظار</span><strong><?php echo (int) ($queue['pending'] ?? 0); ?></strong></div>
			<div class="ciwa-kpi"><span>ارسال‌شده</span><strong class="ok"><?php echo (int) ($queue['sent'] ?? 0); ?></strong></div>
			<div class="ciwa-kpi"><span>ناموفق</span><strong class="err"><?php echo (int) ($queue['failed'] ?? 0); ?></strong></div>
			<div class="ciwa-kpi"><span>لغوشده</span><strong><?php echo (int) ($queue['cancelled'] ?? 0); ?></strong></div>
		</div>
		<div class="ciwa-actions-row" style="margin-top:12px">
			<button type="button" class="ciwa-btn ciwa-btn-secondary" id="ciwa-refresh-queue">بروزرسانی صف</button>
			<button type="button" class="ciwa-btn ciwa-btn-ghost" id="ciwa-run-queue-now">اجرای فوری صف</button>
		</div>
		<div id="ciwa-queue-table" class="ciwa-queue-wrap">
			<p class="ciwa-muted-note">برای دیدن جزئیات، بروزرسانی صف را بزنید.</p>
		</div>
	</div>
	<?php endif; ?>

	<div class="ciwa-grid-2">
		<div class="ciwa-card">
			<div class="ciwa-card-head"><h2>سفارش‌های ۱۴ روز اخیر</h2></div>
			<canvas id="ciwa-chart-orders" height="140"></canvas>
		</div>
		<div class="ciwa-card">
			<div class="ciwa-card-head"><h2>فعالیت لاگ‌ها</h2></div>
			<canvas id="ciwa-chart-logs" height="140"></canvas>
		</div>
	</div>

	<div class="ciwa-grid-2">
		<div class="ciwa-card">
			<div class="ciwa-card-head"><h2>قیف سفر مشتری</h2></div>
			<ul class="ciwa-funnel">
				<?php
				$labels = array(
					'registered'             => 'ثبت‌نام',
					'cart_abandoned'         => 'سبد رها‌شده',
					'checkout_pending'       => 'درگاه',
					'payment_failed'         => 'پرداخت ناموفق',
					'purchased'              => 'خرید موفق',
					'exited_payment_failed'  => 'خروج از ناموفق',
				);
				$max = max(1, max(array_map('intval', $stats['funnel'])));
				foreach ($labels as $k => $label) :
					$v = isset($stats['funnel'][$k]) ? (int) $stats['funnel'][$k] : 0;
					$w = round(($v / $max) * 100);
					?>
					<li>
						<div class="ciwa-funnel-meta">
							<span><?php echo esc_html($label); ?></span>
							<strong><?php echo (int) $v; ?></strong>
						</div>
						<div class="ciwa-funnel-bar"><i style="width:<?php echo (int) $w; ?>%"></i></div>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<div class="ciwa-card">
			<div class="ciwa-card-head"><h2>وضعیت سیستم</h2></div>
			<ul class="ciwa-syslist">
				<li><span>موتور</span><strong><?php echo esc_html($stats['engine_label'] ?? '—'); ?></strong></li>
				<li><span>فلو ساخته شده</span><strong><?php echo !empty($stats['flow_built']) ? '✓' : '—'; ?></strong></li>
				<li><span>تست اتصال</span><strong><?php echo !empty($stats['test_ok']) ? '✓' : '—'; ?></strong></li>
				<li><span>مراحل فعال</span><strong><?php echo (int) $stats['enabled_stages']; ?></strong></li>
				<?php if (Ciwa_Engine::is_n8n()) : ?>
					<li><span>n8n تست</span><code class="ltr"><?php echo esc_html($stats['n8n_test'] ?: '—'); ?></code></li>
					<li><span>n8n واقعی</span><code class="ltr"><?php echo esc_html($stats['n8n_prod'] ?: '—'); ?></code></li>
				<?php endif; ?>
			</ul>
			<?php if (!empty($setup['production_active'])) : ?>
				<button type="button" class="ciwa-btn ciwa-btn-danger" id="ciwa-deactivate">توقف اتومیشن</button>
			<?php endif; ?>
		</div>
	</div>
</section>
<script type="application/json" id="ciwa-dashboard-data"><?php echo wp_json_encode(array(
	'orders' => $stats['chart_orders'],
	'logs'   => $stats['chart_logs'],
), JSON_UNESCAPED_UNICODE); ?></script>
