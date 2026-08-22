<?php
/**
 * 自动云备份后台页面。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 渲染后台设置页。
 */
class Ai1_Oss_Auto_Backup_Admin_Page {

	/**
	 * 注册菜单与资源。
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * 注册设置菜单。
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_options_page(
			'AI1 OSS 自动备份',
			'AI1 OSS 自动备份',
			Ai1_Oss_Auto_Backup_Security::CAPABILITY,
			'ai1-oss-auto-backup',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * 渲染页面。
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! Ai1_Oss_Auto_Backup_Security::current_user_can_manage() ) {
			return;
		}

		$settings              = Ai1_Oss_Auto_Backup_Settings::get_display_settings();
		$nonce                 = Ai1_Oss_Auto_Backup_Security::create_nonce();
		$ajax_url              = admin_url( 'admin-ajax.php' );
		$timezones_string      = wp_timezone_string();
		$timezones_string      = '' !== $timezones_string ? $timezones_string : 'UTC';
		$cron_examples         = self::get_cron_examples();
		$weekday_options       = self::get_weekday_options();
		$schedule_mode_options = self::get_schedule_mode_options();

		Ai1_Oss_Auto_Backup_Logger::maybe_install();
		$job_status = Ai1_Oss_Auto_Backup_Job_Runner::get_status();
		$logs       = Ai1_Oss_Auto_Backup_Logger::list_logs( 1, 20 );

		require AI1_OSS_AUTO_BACKUP_INCLUDES . '/views/admin-page.php';
	}

	/**
	 * 返回调度模式选项。
	 *
	 * @return array<string,string>
	 */
	public static function get_schedule_mode_options() {
		return array(
			'daily'   => '每天',
			'weekly'  => '每周',
			'monthly' => '每月',
		);
	}

	/**
	 * 返回星期选项。
	 *
	 * @return array<int,string>
	 */
	public static function get_weekday_options() {
		return array(
			1 => '周一',
			2 => '周二',
			3 => '周三',
			4 => '周四',
			5 => '周五',
			6 => '周六',
			7 => '周日',
		);
	}

	/**
	 * 返回系统 Cron 示例。
	 *
	 * @return array<string,string>
	 */
	public static function get_cron_examples() {
		$cron_url = esc_url_raw( site_url( '/wp-cron.php?doing_wp_cron' ) );

		return array(
			'curl'  => '*/5 * * * * curl -fsS -o /dev/null "' . $cron_url . '"',
			'wget'  => '*/5 * * * * wget -q -O - "' . $cron_url . "\" >/dev/null 2>&1",
			'wpcli' => '*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now',
		);
	}
}
