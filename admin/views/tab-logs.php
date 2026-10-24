<?php
if (!defined('ABSPATH')) {
	exit;
}
$levels = Ciwa_Logger::LEVELS;
?>
<section class="ciwa-logs">
	<div class="ciwa-card">
		<div class="ciwa-card-head">
			<h2>لاگ تخصصی</h2>
			<p>نمایش، فیلتر، حذف انتخابی و پاکسازی لاگ‌های سیستم.</p>
		</div>

		<div class="ciwa-logs-toolbar">
			<input type="search" id="ciwa-log-search" placeholder="جستجو در پیام…" />
			<select id="ciwa-log-level">
				<option value="">همه سطوح</option>
				<?php foreach ($levels as $lv) : ?>
					<option value="<?php echo esc_attr($lv); ?>"><?php echo esc_html($lv); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" id="ciwa-log-channel" placeholder="کانال (مثلاً webhook)" />
			<button type="button" class="ciwa-btn ciwa-btn-secondary" id="ciwa-log-refresh">به‌روزرسانی</button>
			<button type="button" class="ciwa-btn ciwa-btn-danger" id="ciwa-log-delete">حذف انتخاب‌شده‌ها</button>
			<button type="button" class="ciwa-btn ciwa-btn-ghost" id="ciwa-log-clear-old">پاکسازی ۳۰ روز+</button>
			<button type="button" class="ciwa-btn ciwa-btn-danger" id="ciwa-log-clear-all">حذف همه</button>
		</div>

		<div class="ciwa-table-wrap">
			<table class="ciwa-table" id="ciwa-logs-table">
				<thead>
					<tr>
						<th><input type="checkbox" id="ciwa-log-check-all" /></th>
						<th>زمان</th>
						<th>سطح</th>
						<th>کانال</th>
						<th>پیام</th>
						<th>جزئیات</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
		<div class="ciwa-logs-pager">
			<button type="button" class="ciwa-btn ciwa-btn-ghost" id="ciwa-log-prev">قبلی</button>
			<span id="ciwa-log-page-info">—</span>
			<button type="button" class="ciwa-btn ciwa-btn-ghost" id="ciwa-log-next">بعدی</button>
		</div>
	</div>
</section>
