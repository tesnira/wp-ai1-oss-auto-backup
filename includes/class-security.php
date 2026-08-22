<?php
/**
 * 自动云备份安全辅助。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 统一权限与 nonce 校验。
 */
class Ai1_Oss_Auto_Backup_Security {

	/**
	 * Nonce 动作名。
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'ai1_oss_auto_backup';

	/**
	 * 权限名。
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * 返回当前用户是否有权限。
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * 无权限时终止 AJAX。
	 *
	 * @return void
	 */
	public static function ensure_manage_permission() {
		if ( self::current_user_can_manage() ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => '仅管理员可操作自动云备份。',
			),
			403
		);
	}

	/**
	 * 校验 AJAX nonce。
	 *
	 * @return void
	 */
	public static function ensure_ajax_nonce() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * 获取新 nonce。
	 *
	 * @return string
	 */
	public static function create_nonce() {
		return wp_create_nonce( self::NONCE_ACTION );
	}
}
