<?php
/**
 * 自动云备份全局红条告警。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * N1 + F1 + W1：管理员全站后台红条。
 */
class Ai1_Oss_Auto_Backup_Admin_Notices {

	/**
	 * 用户 dismiss 的 meta 键。
	 *
	 * @var string
	 */
	const USER_META_DISMISS = 'ai1_oss_auto_backup_notice_dismissed';

	/**
	 * 注册后台提示。
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_notices', array( __CLASS__, 'render_global_notice' ) );
	}

	/**
	 * 构建告警签名（用于 dismiss 比对）。
	 *
	 * @param array<string,mixed>|null $settings 设置。
	 * @return string
	 */
	public static function build_signature( $settings = null ) {
		$settings = $settings ? $settings : Ai1_Oss_Auto_Backup_Settings::get_settings();
		$c1       = Ai1_Oss_Auto_Backup_Logger::get_recent_c1_failure( 14 );
		$payload  = array(
			'fail'    => ! empty( $settings['last_job_had_failure'] ) ? 1 : 0,
			'status'  => (string) $settings['last_run_status'],
			'run_at'  => (string) $settings['last_run_at'],
			'overdue' => Ai1_Oss_Auto_Backup_Cron::is_schedule_overdue( $settings ) ? 1 : 0,
			'c1'      => $c1 ? (int) $c1->id : 0,
		);

		return md5( wp_json_encode( $payload ) );
	}

	/**
	 * 收集当前告警。
	 *
	 * @return array{items:array<int,string>,signature:string}|null
	 */
	public static function collect_alerts() {
		$settings = Ai1_Oss_Auto_Backup_Settings::get_settings();
		$items    = array();

		$c1 = Ai1_Oss_Auto_Backup_Logger::get_recent_c1_failure( 14 );
		if ( $c1 ) {
			$items[] = '检测到备份任务异常中断（C1），请打开「自动云备份」检查日志与任务锁。';
		}

		if ( ! empty( $settings['last_job_had_failure'] ) ) {
			$msg = (string) $settings['last_run_message'];
			$items[] = '最近一次自动云备份 Job 含失败' . ( $msg ? '：' . $msg : '。' );
		}

		if ( Ai1_Oss_Auto_Backup_Cron::is_schedule_overdue( $settings ) ) {
			$items[] = '自动云备份定时任务似乎未按时执行，请检查系统 Cron / WP-Cron 与启用状态。';
		}

		if ( empty( $items ) ) {
			return null;
		}

		return array(
			'items'     => array_values( array_unique( $items ) ),
			'signature' => self::build_signature( $settings ),
		);
	}

	/**
	 * 渲染全局红条。
	 *
	 * @return void
	 */
	public static function render_global_notice() {
		if ( ! Ai1_Oss_Auto_Backup_Security::current_user_can_manage() ) {
			return;
		}

		$alerts = self::collect_alerts();
		if ( ! $alerts ) {
			return;
		}

		$user_id   = get_current_user_id();
		$dismissed = (string) get_user_meta( $user_id, self::USER_META_DISMISS, true );
		if ( $dismissed && hash_equals( $dismissed, $alerts['signature'] ) ) {
			return;
		}

		$tool_url = admin_url( 'options-general.php?page=ai1-oss-auto-backup' );
		$nonce    = Ai1_Oss_Auto_Backup_Security::create_nonce();

		echo '<div class="notice notice-error" id="ai1-oss-auto-backup-global-notice">';
		echo '<p><strong>AI1 OSS 自动备份告警</strong></p><ul style="margin-left:1.2em;list-style:disc;">';
		foreach ( $alerts['items'] as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul>';
		echo '<p><a class="button button-small" href="' . esc_url( $tool_url ) . '">打开设置</a> ';
		echo '<button type="button" class="button button-small" id="ai1-oss-dismiss-notice" data-nonce="' . esc_attr( $nonce ) . '" data-signature="' . esc_attr( $alerts['signature'] ) . '">知道了</button></p>';
		echo '</div>';

		$ajax = esc_url( admin_url( 'admin-ajax.php' ) );
		echo '<script>(function(){var b=document.getElementById("ai1-oss-dismiss-notice");if(!b)return;b.addEventListener("click",function(){var fd=new FormData();fd.append("action","ai1_oss_backup_dismiss_notice");fd.append("nonce",b.getAttribute("data-nonce"));fd.append("signature",b.getAttribute("data-signature"));fetch(' . wp_json_encode( $ajax ) . ',{method:"POST",credentials:"same-origin",body:fd}).then(function(){var n=document.getElementById("ai1-oss-auto-backup-global-notice");if(n)n.style.display="none";});});})();</script>';
	}

	/**
	 * Dismiss 当前签名。
	 *
	 * @param string $signature 签名。
	 * @return void
	 */
	public static function dismiss( $signature ) {
		$signature = sanitize_text_field( $signature );
		if ( '' === $signature ) {
			return;
		}
		update_user_meta( get_current_user_id(), self::USER_META_DISMISS, $signature );
	}
}
