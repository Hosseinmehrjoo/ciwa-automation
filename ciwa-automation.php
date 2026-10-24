<?php
/**
 * Plugin Name:       CIWA Automation — اتومیشن سیوا
 * Plugin URI:        https://hosseinmehrjoo.ir
 * Description:       اتومیشن حرفه‌ای سفر مشتری ووکامرس با n8n و پنل پیامک — ساخت فلو، تست اتصال، گزارش و لاگ تخصصی
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            حسین مهرجو
 * Author URI:        https://hosseinmehrjoo.ir
 * Text Domain:       ciwa-automation
 * Domain Path:       /languages
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) {
	exit;
}

define('CIWA_VERSION', '1.3.0');
define('CIWA_FILE', __FILE__);
define('CIWA_PATH', plugin_dir_path(__FILE__));
define('CIWA_URL', plugin_dir_url(__FILE__));
define('CIWA_OPTION', 'ciwa_automation_settings');
define('CIWA_SETUP_OPTION', 'ciwa_automation_setup');

require_once CIWA_PATH . 'includes/class-autoloader.php';
Ciwa_Autoloader::register();

register_activation_hook(__FILE__, array('Ciwa_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('Ciwa_Activator', 'deactivate'));

add_action('plugins_loaded', static function () {
	Ciwa_Plugin::instance();
});
