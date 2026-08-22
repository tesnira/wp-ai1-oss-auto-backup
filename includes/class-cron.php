<?php
/**
 * 自动云备份定时调度。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Cron tick：日/周/月窗口判断与续传。
 */
class Ai1_Oss_Auto_Backup_Cron {

	/**
	 * Tick 钩子名。
	 *
	 * @var string
	 */
	const HOOK = 'ai1_oss_auto_backup_tick';

	/**
	 * 注册调度。
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'handle_tick' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	/**
	 * 确保 tick 已挂到 WP-Cron。
	 *
	 * @return void
	 */
	public static function ensure_schedule() {
		$settings = Ai1_Oss_Auto_Backup_Settings::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			self::clear_schedule();
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * 清除调度。
	 *
	 * @return void
	 */
	public static function clear_schedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * 保存设置后重调度。
	 *
	 * @return void
	 */
	public static function reschedule() {
		self::clear_schedule();
		self::ensure_schedule();
	}

	/**
	 * Tick 回调。
	 *
	 * @return void
	 */
	public static function handle_tick() {
		$settings = Ai1_Oss_Auto_Backup_Settings::get_settings();
		Ai1_Oss_Auto_Backup_Settings::patch_runtime(
			array(
				'cron_last_tick_at' => current_time( 'mysql' ),
			)
		);

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! self::is_due( $settings ) ) {
			return;
		}

		$period_key = self::get_period_key( $settings );

		// 本周期已收工且无剩余工作（含半包）：跳过。
		if (
			(string) ( $settings['cron_period_key'] ?? '' ) === $period_key
			&& ! empty( $settings['cron_period_completed'] )
			&& ! Ai1_Oss_Auto_Backup_Ai1wm_Source::has_upload_work_remaining()
		) {
			return;
		}

		if ( null !== Ai1_Oss_Auto_Backup_Job_Runner::get_lock() ) {
			return;
		}

		Ai1_Oss_Auto_Backup_Settings::patch_runtime(
			array(
				'cron_period_key'       => $period_key,
				'cron_period_completed' => false,
			)
		);

		$result = Ai1_Oss_Auto_Backup_Job_Runner::run( 'cron' );

		// 半包不算收工，后续 tick 继续重试。
		$completed = ! Ai1_Oss_Auto_Backup_Ai1wm_Source::has_upload_work_remaining();

		Ai1_Oss_Auto_Backup_Settings::patch_runtime(
			array(
				'cron_period_key'       => $period_key,
				'cron_period_completed' => $completed,
			)
		);

		unset( $result );
	}

	/**
	 * 当前是否已到本周期应执行时刻。
	 *
	 * @param array<string,mixed> $settings 设置。
	 * @return bool
	 */
	public static function is_due( $settings ) {
		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$time     = self::parse_cron_time( (string) $settings['cron_time'] );
		$mode     = (string) $settings['schedule_mode'];

		if ( 'daily' === $mode ) {
			$due_today = $now->setTime( $time['hour'], $time['minute'], 0 );
			return $now >= $due_today;
		}

		if ( 'weekly' === $mode ) {
			$weekday = (int) $settings['cron_weekday']; // 1=周一 … 7=周日
			$iso_day = (int) $now->format( 'N' );
			if ( $iso_day !== $weekday ) {
				return false;
			}
			$due = $now->setTime( $time['hour'], $time['minute'], 0 );
			return $now >= $due;
		}

		if ( 'monthly' === $mode ) {
			$configured = max( 1, min( 31, (int) $settings['cron_monthday'] ) );
			$days_in_month = (int) $now->format( 't' ); // 当月实际天数（28–31）
			$effective_day = min( $configured, $days_in_month );
			$today         = (int) $now->format( 'j' );

			if ( $today < $effective_day ) {
				return false;
			}
			if ( $today > $effective_day ) {
				return true;
			}

			$due = $now->setTime( $time['hour'], $time['minute'], 0 );
			return $now >= $due;
		}

		return false;
	}

	/**
	 * 生成本周期键。
	 *
	 * @param array<string,mixed> $settings 设置。
	 * @return string
	 */
	public static function get_period_key( $settings ) {
		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$mode     = (string) $settings['schedule_mode'];

		if ( 'daily' === $mode ) {
			return $now->format( 'Y-m-d' );
		}

		if ( 'weekly' === $mode ) {
			return $now->format( 'o-\WW' );
		}

		return $now->format( 'Y-m' );
	}

	/**
	 * 解析 H:i。
	 *
	 * @param string $cron_time 时间。
	 * @return array{hour:int,minute:int}
	 */
	public static function parse_cron_time( $cron_time ) {
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $cron_time, $matches ) ) {
			return array(
				'hour'   => 4,
				'minute' => 0,
			);
		}

		return array(
			'hour'   => (int) $matches[1],
			'minute' => (int) $matches[2],
		);
	}

	/**
	 * 估算下次应执行说明（展示用）。
	 *
	 * @param array<string,mixed>|null $settings 设置。
	 * @return string
	 */
	public static function describe_next_window( $settings = null ) {
		$settings = $settings ? $settings : Ai1_Oss_Auto_Backup_Settings::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return '已停用';
		}

		$mode = (string) $settings['schedule_mode'];
		$time = (string) $settings['cron_time'];

		if ( 'daily' === $mode ) {
			return '每天 ' . $time;
		}

		if ( 'weekly' === $mode ) {
			$labels = array( 1 => '周一', 2 => '周二', 3 => '周三', 4 => '周四', 5 => '周五', 6 => '周六', 7 => '周日' );
			$day    = (int) $settings['cron_weekday'];
			return '每' . ( $labels[ $day ] ?? ('周' . $day) ) . ' ' . $time;
		}

		return '每月 ' . (int) $settings['cron_monthday'] . ' 日 ' . $time . '（短月自动落到当月最后一天）';
	}

	/**
	 * 返回用于前端展示的调度摘要。
	 *
	 * @param array<string,mixed>|null $settings 设置。
	 * @return array<string,mixed>
	 */
	public static function get_schedule_display( $settings = null ) {
		$settings  = $settings ? $settings : Ai1_Oss_Auto_Backup_Settings::get_settings();
		$next_tick = self::next_tick_timestamp();

		return array(
			'description'     => self::describe_next_window( $settings ),
			'next_tick'         => $next_tick ? (int) $next_tick : 0,
			'next_tick_label'   => $next_tick ? date_i18n( 'Y-m-d H:i:s', $next_tick ) : '未注册（保存设置或启用后会自动挂载）',
			'period_key'        => (string) ( $settings['cron_period_key'] ?? '' ),
			'period_done'       => ! empty( $settings['cron_period_completed'] ),
			'timezone'          => wp_timezone_string() ?: 'UTC',
		);
	}

	/**
	 * 格式化调度状态一行文案。
	 *
	 * @param array<string,mixed>|null $display get_schedule_display 返回值。
	 * @return string
	 */
	public static function format_schedule_summary( $display = null ) {
		$display = $display ? $display : self::get_schedule_display();
		$parts   = array(
			'调度：' . (string) $display['description'],
			'WP-Cron 下次 tick：' . (string) $display['next_tick_label'],
		);

		$period_key = (string) ( $display['period_key'] ?? '' );
		$period     = '' !== $period_key ? $period_key : '—';
		if ( ! empty( $display['period_done'] ) ) {
			$period .= '（本周期已收工）';
		}
		$parts[] = '周期键：' . $period;

		return implode( '；', $parts ) . '；';
	}

	/**
	 * 返回下次调度时间戳（WP-Cron 层面的 next tick）。
	 *
	 * @return int|false
	 */
	public static function next_tick_timestamp() {
		return wp_next_scheduled( self::HOOK );
	}

	/**
	 * W1：是否超时未执行。
	 *
	 * @param array<string,mixed>|null $settings 设置。
	 * @return bool
	 */
	public static function is_schedule_overdue( $settings = null ) {
		$settings = $settings ? $settings : Ai1_Oss_Auto_Backup_Settings::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		$grace_hours = max( 1, (int) $settings['cron_watch_grace_hours'] );
		$interval    = self::get_nominal_interval_seconds( $settings );
		$deadline    = $interval + ( $grace_hours * HOUR_IN_SECONDS );

		$last_run = (string) $settings['last_run_at'];
		if ( '' === $last_run ) {
			// 从未跑过：若已启用超过宽限，也告警。
			$installed = (string) ( $settings['enabled_since'] ?? '' );
			if ( '' === $installed ) {
				return false;
			}
			$since_ts = strtotime( $installed );
			return $since_ts && ( time() - $since_ts ) > $deadline;
		}

		$last_ts = strtotime( $last_run );
		if ( ! $last_ts ) {
			return false;
		}

		return ( time() - $last_ts ) > $deadline;
	}

	/**
	 * 名义周期秒数（用于 W1）。
	 *
	 * @param array<string,mixed> $settings 设置。
	 * @return int
	 */
	public static function get_nominal_interval_seconds( $settings ) {
		$mode = (string) $settings['schedule_mode'];
		if ( 'daily' === $mode ) {
			return DAY_IN_SECONDS;
		}
		if ( 'monthly' === $mode ) {
			return 32 * DAY_IN_SECONDS;
		}

		return WEEK_IN_SECONDS;
	}
}
