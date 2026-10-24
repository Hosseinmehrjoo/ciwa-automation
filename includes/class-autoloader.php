<?php
if (!defined('ABSPATH')) {
	exit;
}

class Ciwa_Autoloader {
	public static function register() {
		spl_autoload_register(array(__CLASS__, 'load'));
	}

	public static function load($class) {
		if (0 !== strpos($class, 'Ciwa_')) {
			return;
		}
		$map = array(
			'Ciwa_Plugin'            => 'includes/class-plugin.php',
			'Ciwa_Activator'         => 'includes/class-activator.php',
			'Ciwa_Settings'          => 'includes/class-settings.php',
			'Ciwa_Logger'            => 'includes/class-logger.php',
			'Ciwa_State'             => 'includes/class-state.php',
			'Ciwa_Api_Keys'          => 'includes/class-api-keys.php',
			'Ciwa_Webhook_Manager'   => 'includes/class-webhook-manager.php',
			'Ciwa_Abandoned_Cart'    => 'includes/class-abandoned-cart.php',
			'Ciwa_Workflow_Exporter' => 'includes/class-workflow-exporter.php',
			'Ciwa_Connection_Tester' => 'includes/class-connection-tester.php',
			'Ciwa_Stats'             => 'includes/class-stats.php',
			'Ciwa_Reset'             => 'includes/class-reset.php',
			'Ciwa_Sms'               => 'includes/class-sms.php',
			'Ciwa_Engine'            => 'includes/class-engine.php',
			'Ciwa_Queue'             => 'includes/class-queue.php',
			'Ciwa_Messages'          => 'includes/class-messages.php',
			'Ciwa_Compliance'        => 'includes/class-compliance.php',
			'Ciwa_Segments'          => 'includes/class-segments.php',
			'Ciwa_Templates'         => 'includes/class-templates.php',
			'Ciwa_Channels'          => 'includes/class-channels.php',
			'Ciwa_Roi'               => 'includes/class-roi.php',
			'Ciwa_Internal_Runner'   => 'includes/class-internal-runner.php',
			'Ciwa_Security'          => 'includes/class-security.php',
			'Ciwa_Admin'             => 'admin/class-admin.php',
		);
		if (isset($map[$class])) {
			$file = CIWA_PATH . $map[$class];
			if (file_exists($file)) {
				require_once $file;
			}
		}
	}
}
