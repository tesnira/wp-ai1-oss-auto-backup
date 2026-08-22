<?php
/**
 * 自动云备份保留与清理策略。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OSS 策略 A / 月度 M2 / 地板；本地仅按 keep_count 清理 `.wpress`。
 */
class Ai1_Oss_Auto_Backup_Retention {

	/**
	 * 执行一轮清理（OSS + 本地 + A3 日志）。
	 *
	 * @param array<string,mixed>|null $settings 设置。
	 * @param bool                     $allow_prune 是否允许超额清理（全部失败时为 false）。
	 * @return array<string,mixed>
	 */
	public static function run( $settings = null, $allow_prune = true ) {
		$settings = $settings ? $settings : Ai1_Oss_Auto_Backup_Settings::get_settings();
		$report   = array(
			'oss_deleted'     => 0,
			'local_deleted'   => 0,
			'logs_deleted'    => 0,
			'marked_missing'  => 0,
			'messages'        => array(),
		);

		if ( $allow_prune ) {
			if ( ! empty( $settings['prune_oss_excess'] ) ) {
				$oss = self::prune_oss( $settings );
				$report['oss_deleted']    = (int) $oss['deleted'];
				$report['marked_missing'] = (int) $oss['marked_missing'];
				$report['messages'][]     = $oss['message'];
			}

			if ( ! empty( $settings['prune_local_excess'] ) ) {
				$local = self::prune_local( $settings );
				$report['local_deleted'] = (int) $local['deleted'];
				$report['messages'][]    = $local['message'];
			}
		}

		$logs = Ai1_Oss_Auto_Backup_Logger::cleanup_old_logs_a3( $settings );
		$report['logs_deleted'] = (int) $logs['deleted'];
		$report['messages'][]   = $logs['message'];

		return $report;
	}

	/**
	 * OSS 超额清理（策略 A + 月度 + 地板）。
	 *
	 * @param array<string,mixed> $settings 设置。
	 * @return array<string,mixed>
	 */
	public static function prune_oss( $settings ) {
		$managed = self::collect_managed_remote_logs( $settings );
		$marked  = (int) $managed['marked_missing'];
		$alive   = $managed['logs'];

		$keep_count = max( 1, (int) $settings['keep_count'] );
		$deleted    = 0;

		// 先处理过期月度锚点（M2），再处理滚动超额；全程遵守地板。
		if ( ! empty( $settings['monthly_retain_enabled'] ) ) {
			$deleted += self::delete_expired_monthly_anchors( $alive, $settings, $keep_count );
			// 重新收集，避免使用已删对象。
			$managed = self::collect_managed_remote_logs( $settings );
			$alive   = $managed['logs'];
		}

		$deleted += self::delete_excess_rolling( $alive, $settings, $keep_count );

		return array(
			'deleted'         => $deleted,
			'marked_missing'  => $marked,
			'message'         => sprintf( 'OSS 清理：删除 %d 套备份，标记缺失 %d 条。', $deleted, $marked ),
		);
	}

	/**
	 * 收集远端仍在的 success 日志，并对缺失打标。
	 *
	 * @param array<string,mixed> $settings 设置。
	 * @return array{logs:array<int,object>,marked_missing:int}
	 */
	public static function collect_managed_remote_logs( $settings ) {
		$rows   = Ai1_Oss_Auto_Backup_Logger::get_success_candidates();
		$alive  = array();
		$marked = 0;

		foreach ( $rows as $row ) {
			if ( empty( $row->oss_object_key ) ) {
				continue;
			}

			$exists = Ai1_Oss_Auto_Backup_Oss_Client::object_exists( $settings, (string) $row->oss_object_key );
			if ( is_wp_error( $exists ) ) {
				// 探测失败不删、不标缺失，避免误伤。
				continue;
			}

			if ( ! $exists ) {
				Ai1_Oss_Auto_Backup_Logger::mark_remote_missing( (int) $row->id );
				$marked++;
				continue;
			}

			$alive[] = $row;
		}

		return array(
			'logs'            => $alive,
			'marked_missing'  => $marked,
		);
	}

	/**
	 * 删除超出保留月数的月度锚点（仍受地板约束）。
	 *
	 * @param array<int,object>   $alive      远端仍在的日志。
	 * @param array<string,mixed> $settings   设置。
	 * @param int                 $keep_count 地板。
	 * @return int 删除数。
	 */
	private static function delete_expired_monthly_anchors( $alive, $settings, $keep_count ) {
		$months = max( 1, (int) $settings['monthly_keep_months'] );
		$cutoff = new DateTimeImmutable( 'first day of -' . ( $months - 1 ) . ' months', wp_timezone() );
		$cutoff_key = $cutoff->format( 'Y-m' );

		$anchors = array();
		foreach ( $alive as $row ) {
			if ( empty( $row->is_monthly_anchor ) ) {
				continue;
			}
			$anchors[] = $row;
		}

		usort(
			$anchors,
			static function ( $a, $b ) {
				$ta = (string) ( $a->finished_at ?: $a->created_at );
				$tb = (string) ( $b->finished_at ?: $b->created_at );
				return strcmp( $ta, $tb );
			}
		);

		$deleted     = 0;
		$total_alive = count( $alive );

		foreach ( $anchors as $row ) {
			$month_key = substr( (string) ( $row->finished_at ?: $row->created_at ), 0, 7 );
			if ( $month_key >= $cutoff_key ) {
				continue;
			}
			if ( ( $total_alive - 1 ) < $keep_count ) {
				break;
			}
			if ( self::delete_remote_and_log( $settings, $row ) ) {
				$deleted++;
				$total_alive--;
			}
		}

		return $deleted;
	}

	/**
	 * 删除滚动包超额部分。计数单位是上传日志中的一条成功备份（一个 `.wpress`）。
	 *
	 * @param array<int,object>   $alive      远端仍在的日志。
	 * @param array<string,mixed> $settings   设置。
	 * @param int                 $keep_count 滚动保留数兼地板。
	 * @return int
	 */
	private static function delete_excess_rolling( $alive, $settings, $keep_count ) {
		$rolling = array();
		foreach ( $alive as $row ) {
			if ( ! empty( $row->is_monthly_anchor ) && ! empty( $settings['monthly_retain_enabled'] ) ) {
				continue;
			}
			$rolling[] = $row;
		}

		usort(
			$rolling,
			static function ( $a, $b ) {
				$ta = (string) ( $a->finished_at ?: $a->created_at );
				$tb = (string) ( $b->finished_at ?: $b->created_at );
				return strcmp( $tb, $ta ); // 新→旧
			}
		);

		if ( count( $rolling ) <= $keep_count ) {
			return 0;
		}

		$to_delete   = array_slice( $rolling, $keep_count );
		$total_alive = count( $alive );
		$deleted     = 0;

		// 从最旧开始删。
		$to_delete = array_reverse( $to_delete );
		foreach ( $to_delete as $row ) {
			if ( ( $total_alive - 1 ) < $keep_count ) {
				break;
			}
			if ( self::delete_remote_and_log( $settings, $row ) ) {
				$deleted++;
				$total_alive--;
			}
		}

		return $deleted;
	}

	/**
	 * 删除 OSS 对象，并在成功后更新日志（标记缺失，避免再被保留计算）。
	 *
	 * @param array<string,mixed> $settings 设置。
	 * @param object              $row      日志行。
	 * @return bool
	 */
	private static function delete_remote_and_log( $settings, $row ) {
		$result = Ai1_Oss_Auto_Backup_Oss_Client::delete_object( $settings, (string) $row->oss_object_key );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		Ai1_Oss_Auto_Backup_Logger::mark_remote_missing( (int) $row->id );
		Ai1_Oss_Auto_Backup_Logger::clear_monthly_anchor( (int) $row->id );

		return true;
	}

	/**
	 * 本地超额清理：只删 `.wpress`，按 mtime 保留最新 keep_count 个。
	 *
	 * @param array<string,mixed> $settings 设置。
	 * @return array<string,mixed>
	 */
	public static function prune_local( $settings ) {
		$keep  = max( 1, (int) $settings['keep_count'] );
		$files = Ai1_Oss_Auto_Backup_Ai1wm_Source::list_wpress_files();

		// list 是升序；转为降序后保留前 keep。
		$files = array_reverse( $files );
		if ( count( $files ) <= $keep ) {
			return array(
				'deleted' => 0,
				'message' => '本地无需清理。',
			);
		}

		$to_delete = array_slice( $files, $keep );
		$deleted   = 0;
		$base      = wp_normalize_path( Ai1_Oss_Auto_Backup_Ai1wm_Source::get_backups_path() );

		foreach ( $to_delete as $file ) {
			$path = wp_normalize_path( (string) $file['path'] );
			if ( 0 !== strpos( $path, trailingslashit( $base ) ) ) {
				continue;
			}
			if ( 'wpress' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				continue;
			}
			if ( is_file( $path ) && unlink( $path ) ) {
				$deleted++;
			}
		}

		return array(
			'deleted' => $deleted,
			'message' => sprintf( '本地清理：删除 %d 个 `.wpress`。', $deleted ),
		);
	}
}
