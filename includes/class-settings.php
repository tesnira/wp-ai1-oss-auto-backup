<?php
/**
 * 自动云备份设置管理。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 处理自动云备份配置。
 */
class Ai1_Oss_Auto_Backup_Settings {

	/**
	 * 设置项键名。
	 *
	 * @var string
	 */
	const OPTION_KEY = 'ai1_oss_auto_backup_settings';

	/**
	 * 注册设置初始化。
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed_defaults' ) );
	}

	/**
	 * 补齐默认设置。
	 *
	 * @return void
	 */
	public static function maybe_seed_defaults() {
		$current = get_option( self::OPTION_KEY, null );

		if ( null === $current ) {
			add_option( self::OPTION_KEY, self::get_defaults(), '', 'no' );
			return;
		}

		$merged = wp_parse_args( is_array( $current ) ? $current : array(), self::get_defaults() );
		if ( $merged !== $current ) {
			update_option( self::OPTION_KEY, $merged, false );
		}
	}

	/**
	 * 返回默认值。
	 *
	 * @return array<string,mixed>
	 */
	public static function get_defaults() {
		return array(
			'enabled'                   => true,
			'enabled_since'             => '',
			'schedule_mode'             => 'weekly',
			'cron_weekday'              => 6,
			'cron_monthday'             => 1,
			'cron_time'                 => '04:00',
			'max_files_per_job'         => 3,
			'oss_access_key_id'         => '',
			'oss_access_key_secret'     => '',
			'oss_endpoint'              => '',
			'oss_is_internal'           => false,
			'oss_bucket'                => '',
			'oss_prefix'                => '',
			'keep_count'                => 10,
			'delete_local_after_upload' => true,
			'prune_oss_excess'          => true,
			'prune_local_excess'        => true,
			'monthly_retain_enabled'    => false,
			'monthly_keep_months'       => 12,
			'last_run_at'               => '',
			'last_run_status'           => '',
			'last_run_message'          => '',
			'last_job_had_failure'      => false,
			'cron_watch_grace_hours'    => 36,
			'cron_period_key'           => '',
			'cron_period_completed'     => false,
			'cron_last_tick_at'         => '',
			'alert_signature'           => '',
		);
	}

	/**
	 * 获取完整设置。
	 *
	 * @return array<string,mixed>
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );

		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::get_defaults() );
	}

	/**
	 * 获取掩码后的 Secret 展示值。
	 *
	 * @param string $secret 明文 Secret。
	 * @return string
	 */
	public static function get_masked_secret( $secret ) {
		$length = strlen( $secret );

		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		return str_repeat( '*', max( 0, $length - 4 ) ) . substr( $secret, -4 );
	}

	/**
	 * 获取用于展示的设置。
	 *
	 * @return array<string,mixed>
	 */
	public static function get_display_settings() {
		$settings                        = self::get_settings();
		$settings['oss_access_key_mask'] = self::get_masked_secret( (string) $settings['oss_access_key_secret'] );
		unset( $settings['oss_access_key_secret'] );

		return $settings;
	}

	/**
	 * 保存设置。
	 *
	 * @param array<string,mixed> $raw_settings 原始设置。
	 * @return array<string,mixed>
	 */
	public static function update_settings( $raw_settings ) {
		$current   = self::get_settings();
		$sanitized = self::sanitize_settings( $raw_settings, $current );

		$was_enabled = ! empty( $current['enabled'] );
		$now_enabled = ! empty( $sanitized['enabled'] );
		if ( $now_enabled && ( ! $was_enabled || empty( $current['enabled_since'] ) ) ) {
			$sanitized['enabled_since'] = current_time( 'mysql' );
		}

		// 调度参数变更时重置本周期收工标记，避免旧周期状态卡住。
		if (
			(string) $current['schedule_mode'] !== (string) $sanitized['schedule_mode']
			|| (string) $current['cron_time'] !== (string) $sanitized['cron_time']
			|| (int) $current['cron_weekday'] !== (int) $sanitized['cron_weekday']
			|| (int) $current['cron_monthday'] !== (int) $sanitized['cron_monthday']
		) {
			$sanitized['cron_period_key']       = '';
			$sanitized['cron_period_completed'] = false;
		}

		update_option( self::OPTION_KEY, $sanitized, false );
		Ai1_Oss_Auto_Backup_Cron::reschedule();

		if ( ! empty( $sanitized['monthly_retain_enabled'] ) ) {
			Ai1_Oss_Auto_Backup_Logger::rebuild_monthly_anchors();
		} else {
			Ai1_Oss_Auto_Backup_Logger::clear_all_monthly_anchors();
		}

		return self::get_display_settings();
	}

	/**
	 * 仅更新运行时字段（不经表单）。
	 *
	 * @param array<string,mixed> $partial 局部字段。
	 * @return array<string,mixed>
	 */
	public static function patch_runtime( $partial ) {
		$settings = self::get_settings();
		foreach ( $partial as $key => $value ) {
			$settings[ $key ] = $value;
		}
		update_option( self::OPTION_KEY, $settings, false );

		return $settings;
	}

	/**
	 * 规范化设置。
	 *
	 * @param array<string,mixed> $raw_settings 原始设置。
	 * @param array<string,mixed> $current      当前设置。
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $raw_settings, $current = array() ) {
		$defaults = self::get_defaults();
		$current  = wp_parse_args( is_array( $current ) ? $current : array(), $defaults );

		$schedule_mode = isset( $raw_settings['schedule_mode'] ) ? sanitize_key( (string) $raw_settings['schedule_mode'] ) : $current['schedule_mode'];
		if ( ! in_array( $schedule_mode, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			$schedule_mode = $defaults['schedule_mode'];
		}

		$cron_time = isset( $raw_settings['cron_time'] ) ? sanitize_text_field( (string) $raw_settings['cron_time'] ) : $current['cron_time'];
		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cron_time ) ) {
			$cron_time = $defaults['cron_time'];
		}

		$secret = $current['oss_access_key_secret'];
		if ( isset( $raw_settings['oss_access_key_secret'] ) ) {
			$new_secret = trim( (string) $raw_settings['oss_access_key_secret'] );
			if ( '' !== $new_secret ) {
				$secret = sanitize_text_field( $new_secret );
			}
		}

		return array(
			'enabled'                   => ! empty( $raw_settings['enabled'] ),
			'enabled_since'             => sanitize_text_field( (string) ( $current['enabled_since'] ?? '' ) ),
			'schedule_mode'             => $schedule_mode,
			'cron_weekday'              => max( 1, min( 7, (int) ( $raw_settings['cron_weekday'] ?? $current['cron_weekday'] ) ) ),
			'cron_monthday'             => max( 1, min( 31, (int) ( $raw_settings['cron_monthday'] ?? $current['cron_monthday'] ) ) ),
			'cron_time'                 => $cron_time,
			'max_files_per_job'         => max( 1, min( 10, (int) ( $raw_settings['max_files_per_job'] ?? $current['max_files_per_job'] ) ) ),
			'oss_access_key_id'         => sanitize_text_field( (string) ( $raw_settings['oss_access_key_id'] ?? $current['oss_access_key_id'] ) ),
			'oss_access_key_secret'     => $secret,
			'oss_endpoint'              => self::sanitize_endpoint( (string) ( $raw_settings['oss_endpoint'] ?? $current['oss_endpoint'] ) ),
			'oss_is_internal'           => ! empty( $raw_settings['oss_is_internal'] ),
			'oss_bucket'                => sanitize_text_field( (string) ( $raw_settings['oss_bucket'] ?? $current['oss_bucket'] ) ),
			'oss_prefix'                => self::sanitize_prefix( (string) ( $raw_settings['oss_prefix'] ?? $current['oss_prefix'] ) ),
			'keep_count'                => max( 1, (int) ( $raw_settings['keep_count'] ?? $current['keep_count'] ) ),
			'delete_local_after_upload' => ! empty( $raw_settings['delete_local_after_upload'] ),
			'prune_oss_excess'          => ! empty( $raw_settings['prune_oss_excess'] ),
			'prune_local_excess'        => ! empty( $raw_settings['prune_local_excess'] ),
			'monthly_retain_enabled'    => ! empty( $raw_settings['monthly_retain_enabled'] ),
			'monthly_keep_months'       => max( 1, min( 120, (int) ( $raw_settings['monthly_keep_months'] ?? $current['monthly_keep_months'] ) ) ),
			'last_run_at'               => sanitize_text_field( (string) $current['last_run_at'] ),
			'last_run_status'           => sanitize_text_field( (string) $current['last_run_status'] ),
			'last_run_message'          => sanitize_text_field( (string) $current['last_run_message'] ),
			'last_job_had_failure'      => ! empty( $current['last_job_had_failure'] ),
			'cron_watch_grace_hours'    => max( 1, min( 240, (int) ( $raw_settings['cron_watch_grace_hours'] ?? $current['cron_watch_grace_hours'] ) ) ),
			'cron_period_key'           => sanitize_text_field( (string) ( $current['cron_period_key'] ?? '' ) ),
			'cron_period_completed'     => ! empty( $current['cron_period_completed'] ),
			'cron_last_tick_at'         => sanitize_text_field( (string) ( $current['cron_last_tick_at'] ?? '' ) ),
			'alert_signature'           => sanitize_text_field( (string) ( $current['alert_signature'] ?? '' ) ),
		);
	}

	/**
	 * 规范化 Endpoint。
	 *
	 * @param string $endpoint 原始 endpoint。
	 * @return string
	 */
	public static function sanitize_endpoint( $endpoint ) {
		$endpoint = trim( $endpoint );
		$endpoint = preg_replace( '#^https?://#i', '', $endpoint );

		return rtrim( (string) $endpoint, '/' );
	}

	/**
	 * 规范化前缀。
	 *
	 * @param string $prefix 原始前缀。
	 * @return string
	 */
	public static function sanitize_prefix( $prefix ) {
		$prefix = trim( str_replace( '\\', '/', $prefix ) );
		$prefix = ltrim( $prefix, '/' );
		$prefix = preg_replace( '#/+#', '/', $prefix );

		if ( false !== strpos( (string) $prefix, '..' ) ) {
			return '';
		}

		if ( '' === $prefix ) {
			return '';
		}

		return trailingslashit( $prefix );
	}
}
