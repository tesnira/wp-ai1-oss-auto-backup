<?php
/**
 * Plugin Name: WP AI1 OSS Auto Backup
 * Plugin URI: https://wordpress.org/plugins/wp-ai1-oss-auto-backup/
 * Description: 将 All-in-One WP Migration 生成的 .wpress 备份自动上传到独立的阿里云 OSS 私有桶，支持定时调度、保留策略、日志、拉取与远端校验。
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: biancaplus
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai1-oss-auto-backup
 *
 * @package wp-ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AI1_OSS_AUTO_BACKUP_VERSION' ) ) {
	define( 'AI1_OSS_AUTO_BACKUP_VERSION', '1.0.0' );
}

if ( ! defined( 'AI1_OSS_AUTO_BACKUP_FILE' ) ) {
	define( 'AI1_OSS_AUTO_BACKUP_FILE', __FILE__ );
}

if ( ! defined( 'AI1_OSS_AUTO_BACKUP_DIR' ) ) {
	define( 'AI1_OSS_AUTO_BACKUP_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'AI1_OSS_AUTO_BACKUP_INCLUDES' ) ) {
	define( 'AI1_OSS_AUTO_BACKUP_INCLUDES', AI1_OSS_AUTO_BACKUP_DIR . 'includes' );
}

if ( ! defined( 'AI1_OSS_AUTO_BACKUP_URL' ) ) {
	define( 'AI1_OSS_AUTO_BACKUP_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'AI1_OSS_BACKUP_LOG_RETENTION_DAYS' ) ) {
	define( 'AI1_OSS_BACKUP_LOG_RETENTION_DAYS', 180 );
}

require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-settings.php';
require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-security.php';
require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-oss-client.php';
require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-admin-notices.php';
require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-admin-page.php';
require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-ajax.php';
require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-cron.php';
require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-ai1wm-source.php';
require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-retention.php';
require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-logger.php';
require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-job-runner.php';
require_once AI1_OSS_AUTO_BACKUP_INCLUDES . '/class-pull.php';

/**
 * 启动插件。
 *
 * @return void
 */
function ai1_oss_auto_backup_bootstrap() {
	static $booted = false;

	if ( $booted ) {
		return;
	}

	$booted = true;

	Ai1_Oss_Auto_Backup_Settings::register();
	Ai1_Oss_Auto_Backup_Logger::register();
	Ai1_Oss_Auto_Backup_Admin_Notices::register();
	Ai1_Oss_Auto_Backup_Admin_Page::register();
	Ai1_Oss_Auto_Backup_Ajax::register();
	Ai1_Oss_Auto_Backup_Cron::register();
}

/**
 * 插件激活：建表并挂载调度。
 *
 * @return void
 */
function ai1_oss_auto_backup_activate() {
	Ai1_Oss_Auto_Backup_Logger::maybe_install();
	Ai1_Oss_Auto_Backup_Settings::maybe_seed_defaults();
	Ai1_Oss_Auto_Backup_Cron::reschedule();
}

/**
 * 插件停用：清除 WP-Cron 钩子（保留设置与日志）。
 *
 * @return void
 */
function ai1_oss_auto_backup_deactivate() {
	Ai1_Oss_Auto_Backup_Cron::clear_schedule();
}

register_activation_hook( __FILE__, 'ai1_oss_auto_backup_activate' );
register_deactivation_hook( __FILE__, 'ai1_oss_auto_backup_deactivate' );

ai1_oss_auto_backup_bootstrap();
