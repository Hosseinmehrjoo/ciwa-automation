<?php
if (!defined('ABSPATH')) {
	exit;
}
/** @var string $tab */
/** @var array $setup */
/** @var array $settings */
/** @var array $stats */

$tabs = array(
	'welcome'   => array('label' => 'خوش‌آمد', 'icon' => '✨'),
	'dashboard' => array('label' => 'پیشخوان', 'icon' => '📊'),
	'setup'     => array('label' => 'پیکربندی', 'icon' => '⚙️'),
	'logs'      => array('label' => 'لاگ‌ها', 'icon' => '📋'),
	'settings'  => array('label' => 'تنظیمات', 'icon' => '🛠️'),
	'support'   => array('label' => 'پشتیبانی', 'icon' => '💬'),
);
if (!empty($setup['welcome_seen'])) {
	unset($tabs['welcome']);
}
$active_badge = !empty($setup['production_active']);
?>
<div class="ciwa-app" dir="rtl" id="ciwa-app" data-tab="<?php echo esc_attr($tab); ?>">
	<aside class="ciwa-sidebar">
		<div class="ciwa-brand">
			<div class="ciwa-logo">
				<img src="<?php echo esc_url(CIWA_URL . 'assets/img/ciwa-logo.png'); ?>" alt="CIWA Automation" />
			</div>
			<div>
				<strong>CIWA Automation</strong>
				<span>اتومیشن سیوا</span>
			</div>
		</div>

		<nav class="ciwa-nav">
			<?php foreach ($tabs as $key => $item) : ?>
				<a class="ciwa-nav-item <?php echo $tab === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=ciwa-automation&tab=' . $key)); ?>">
					<span class="ciwa-nav-ico"><?php echo esc_html($item['icon']); ?></span>
					<?php echo esc_html($item['label']); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<div class="ciwa-sidebar-foot">
			<div class="ciwa-status-pill <?php echo $active_badge ? 'is-on' : 'is-off'; ?>">
				<span class="dot"></span>
				<?php echo $active_badge ? 'اتومیشن فعال' : 'غیرفعال'; ?>
			</div>
			<a class="ciwa-author" href="https://hosseinmehrjoo.ir" target="_blank" rel="noopener">hosseinmehrjoo.ir</a>
		</div>
	</aside>

	<main class="ciwa-main">
		<header class="ciwa-topbar">
			<div>
				<h1>
					<?php
					echo esc_html(isset($tabs[$tab]) ? $tabs[$tab]['label'] : 'CIWA');
					?>
				</h1>
				<p class="ciwa-sub">ساخته‌شده توسط حسین مهرجو</p>
			</div>
			<div class="ciwa-top-actions">
				<a class="ciwa-btn ciwa-btn-ghost" href="https://hosseinmehrjoo.ir" target="_blank" rel="noopener">خانه افزونه</a>
			</div>
		</header>

		<div class="ciwa-content">
			<?php
			$tab  = Ciwa_Security::sanitize_tab($tab);
			$view = $tab ? CIWA_PATH . 'admin/views/tab-' . $tab . '.php' : '';
			if ($view && file_exists($view)) {
				include $view;
			} else {
				echo '<div class="ciwa-card"><p>صفحه یافت نشد.</p></div>';
			}
			?>
		</div>
	</main>

	<div class="ciwa-toast" id="ciwa-toast" hidden></div>
</div>
