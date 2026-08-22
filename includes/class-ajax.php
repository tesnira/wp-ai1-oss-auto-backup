<?php
/**
 * 自动云备份 AJAX 入口。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 注册后台 AJAX。
 */
class Ai1_Oss_Auto_Backup_Ajax {

	/**
	 * 注册 AJAX 路由。
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_ajax_ai1_oss_backup_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'wp_ajax_ai1_oss_backup_test_connection', array( __CLASS__, 'test_connection' ) );
		add_action( 'wp_ajax_ai1_oss_backup_run_now', array( __CLASS__, 'run_now' ) );
		add_action( 'wp_ajax_ai1_oss_backup_job_status', array( __CLASS__, 'job_status' ) );
		add_action( 'wp_ajax_ai1_oss_backup_list_logs', array( __CLASS__, 'list_logs' ) );
		add_action( 'wp_ajax_ai1_oss_backup_reupload', array( __CLASS__, 'reupload' ) );
		add_action( 'wp_ajax_ai1_oss_backup_clear_lock', array( __CLASS__, 'clear_lock' ) );
		add_action( 'wp_ajax_ai1_oss_backup_dismiss_notice', array( __CLASS__, 'dismiss_notice' ) );
		add_action( 'wp_ajax_ai1_oss_backup_pull_object', array( __CLASS__, 'pull_object' ) );
		add_action( 'wp_ajax_ai1_oss_backup_verify_remote', array( __CLASS__, 'verify_remote' ) );
		add_action( 'wp_ajax_ai1_oss_backup_list_oss_objects', array( __CLASS__, 'list_oss_objects' ) );
		add_action( 'wp_ajax_ai1_oss_backup_delete_local', array( __CLASS__, 'delete_local' ) );
		add_action( 'wp_ajax_ai1_oss_backup_delete_remote', array( __CLASS__, 'delete_remote' ) );
	}

	/**
	 * 保存设置。
	 *
	 * @return void
	 */
	public static function save_settings() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		$settings = Ai1_Oss_Auto_Backup_Settings::update_settings( wp_unslash( $_POST ) );

		wp_send_json_success(
			array(
				'message'  => '自动云备份设置已保存。',
				'settings' => Ai1_Oss_Auto_Backup_Settings::get_display_settings(),
				'schedule' => Ai1_Oss_Auto_Backup_Cron::get_schedule_display( $settings ),
			)
		);
	}

	/**
	 * 测试 OSS 连接。
	 *
	 * @return void
	 */
	public static function test_connection() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		$current  = Ai1_Oss_Auto_Backup_Settings::get_settings();
		$settings = Ai1_Oss_Auto_Backup_Settings::sanitize_settings( wp_unslash( $_POST ), $current );
		$result   = Ai1_Oss_Auto_Backup_Oss_Client::test_connection( $settings );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 400 );
	}

	/**
	 * 手动触发上传 Job。
	 *
	 * @return void
	 */
	public static function run_now() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		Ai1_Oss_Auto_Backup_Logger::maybe_install();
		$result = Ai1_Oss_Auto_Backup_Job_Runner::run( 'manual' );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 400 );
	}

	/**
	 * 查询 Job / 目录状态。
	 *
	 * @return void
	 */
	public static function job_status() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		wp_send_json_success( Ai1_Oss_Auto_Backup_Job_Runner::get_status() );
	}

	/**
	 * 分页日志。
	 *
	 * @return void
	 */
	public static function list_logs() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		Ai1_Oss_Auto_Backup_Logger::maybe_install();

		$page     = isset( $_POST['page'] ) ? (int) $_POST['page'] : 1;
		$per_page = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 20;

		wp_send_json_success( Ai1_Oss_Auto_Backup_Logger::list_logs( $page, $per_page ) );
	}

	/**
	 * 单包重新上传。
	 *
	 * @return void
	 */
	public static function reupload() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		$log_id = isset( $_POST['log_id'] ) ? (int) $_POST['log_id'] : 0;
		if ( $log_id <= 0 ) {
			wp_send_json_error( array( 'message' => '无效的日志 ID。' ), 400 );
		}

		$result = Ai1_Oss_Auto_Backup_Job_Runner::reupload( $log_id );
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 400 );
	}

	/**
	 * 清除任务锁。
	 *
	 * @return void
	 */
	public static function clear_lock() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		wp_send_json_success( Ai1_Oss_Auto_Backup_Job_Runner::clear_lock() );
	}

	/**
	 * Dismiss 全局红条。
	 *
	 * @return void
	 */
	public static function dismiss_notice() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';
		Ai1_Oss_Auto_Backup_Admin_Notices::dismiss( $signature );

		wp_send_json_success( array( 'message' => '已忽略当前告警。' ) );
	}

	/**
	 * 从 OSS 拉取到 ai1wm-backups。
	 *
	 * @return void
	 */
	public static function pull_object() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		$force  = ! empty( $_POST['force_overwrite'] );
		$log_id = isset( $_POST['log_id'] ) ? (int) $_POST['log_id'] : 0;
		$key    = isset( $_POST['object_key'] ) ? sanitize_text_field( wp_unslash( $_POST['object_key'] ) ) : '';

		if ( $log_id > 0 ) {
			$result = Ai1_Oss_Auto_Backup_Pull::pull_by_log_id( $log_id, $force );
		} elseif ( '' !== $key ) {
			$result = Ai1_Oss_Auto_Backup_Pull::pull_object_key( $key, $force, null );
		} else {
			wp_send_json_error( array( 'message' => '请提供日志 ID 或 Object Key。' ), 400 );
		}

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		$status = ! empty( $result['needs_overwrite'] ) ? 409 : 400;
		wp_send_json_error( $result, $status );
	}

	/**
	 * 删除 All-in-One 备份目录中的本地 `.wpress`（不删 OSS）。
	 *
	 * @return void
	 */
	public static function delete_local() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		$log_id   = isset( $_POST['log_id'] ) ? (int) $_POST['log_id'] : 0;
		if ( '' === $filename && isset( $_POST['object_key'] ) ) {
			$filename = sanitize_file_name( wp_basename( wp_unslash( $_POST['object_key'] ) ) );
		}

		$result = Ai1_Oss_Auto_Backup_Pull::delete_local_backup( $filename, $log_id );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 400 );
	}

	/**
	 * 删除 OSS 上的备份对象（不删本地）。
	 *
	 * @return void
	 */
	public static function delete_remote() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		$object_key = isset( $_POST['object_key'] ) ? sanitize_text_field( wp_unslash( $_POST['object_key'] ) ) : '';
		$result     = Ai1_Oss_Auto_Backup_Pull::delete_remote_object( $object_key );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 400 );
	}

	/**
	 * 校验远端对象并打标。
	 *
	 * @return void
	 */
	public static function verify_remote() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		$log_ids = null;
		if ( isset( $_POST['log_ids'] ) ) {
			$raw = wp_unslash( $_POST['log_ids'] );
			if ( is_array( $raw ) ) {
				$log_ids = array_map( 'intval', $raw );
			} elseif ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				$log_ids = is_array( $decoded ) ? array_map( 'intval', $decoded ) : array( (int) $raw );
			}
		}

		$limit  = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 50;
		$result = Ai1_Oss_Auto_Backup_Pull::verify_remote( $log_ids, $limit );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 400 );
	}

	/**
	 * 列举 OSS `.wpress` 对象。
	 *
	 * @return void
	 */
	public static function list_oss_objects() {
		Ai1_Oss_Auto_Backup_Security::ensure_manage_permission();
		Ai1_Oss_Auto_Backup_Security::ensure_ajax_nonce();

		$max    = isset( $_POST['max_keys'] ) ? (int) $_POST['max_keys'] : 100;
		$result = Ai1_Oss_Auto_Backup_Pull::list_objects( $max );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 400 );
	}
}
