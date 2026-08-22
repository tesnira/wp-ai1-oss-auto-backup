<?php
/**
 * 自动云备份任务执行器。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 编排上传 Job：锁、C1、选包、上传、删本地。
 */
class Ai1_Oss_Auto_Backup_Job_Runner {

	/**
	 * 锁 option 键。
	 *
	 * @var string
	 */
	const LOCK_OPTION = 'ai1_oss_auto_backup_lock';

	/**
	 * 锁 TTL（秒）：6 小时。
	 *
	 * @var int
	 */
	const LOCK_TTL = 21600;

	/**
	 * 执行一轮上传 Job。
	 *
	 * @param string $trigger cron|manual。
	 * @return array<string,mixed>
	 */
	public static function run( $trigger = 'manual' ) {
		$trigger  = in_array( $trigger, array( 'cron', 'manual' ), true ) ? $trigger : 'manual';
		$settings = Ai1_Oss_Auto_Backup_Settings::get_settings();

		$validation = Ai1_Oss_Auto_Backup_Oss_Client::validate_required_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return self::finish_job_result(
				false,
				$validation->get_error_message(),
				array(
					'success_count' => 0,
					'failed_count'  => 0,
					'skipped'       => true,
				)
			);
		}

		$stale_fixed = Ai1_Oss_Auto_Backup_Logger::fail_stale_running( self::LOCK_TTL );
		$lock        = self::acquire_lock( $trigger );
		if ( is_wp_error( $lock ) ) {
			return self::finish_job_result(
				false,
				$lock->get_error_message(),
				array(
					'success_count' => 0,
					'failed_count'  => 0,
					'locked'        => true,
					'stale_fixed'   => $stale_fixed,
				),
				false
			);
		}

		$limit   = max( 1, min( 10, (int) $settings['max_files_per_job'] ) );
		$pending = Ai1_Oss_Auto_Backup_Ai1wm_Source::get_pending_uploads( $limit );
		$result  = array(
			'success_count' => 0,
			'failed_count'  => 0,
			'processed'     => array(),
			'stale_fixed'   => $stale_fixed,
			'pending_left'  => 0,
		);

		if ( empty( $pending ) ) {
			$unstable = Ai1_Oss_Auto_Backup_Ai1wm_Source::count_unstable_wpress();
			$message  = $unstable > 0
				? sprintf( '有 %d 个备份包仍在写入，已跳过本轮，后续 tick 将重试。', $unstable )
				: '没有待上传的完整备份包。';

			self::release_lock();

			$result = array_merge(
				$result,
				array(
					'skipped'         => true,
					'pending_left'    => 0,
					'unstable_count'  => $unstable,
				)
			);
			$result['cleanup'] = self::maybe_run_cleanup( $settings, $result );

			return self::finish_job_result( true, $message, $result );
		}

		$total = count( $pending );
		$index = 0;

		foreach ( $pending as $file ) {
			$index++;
			self::update_lock_progress(
				sprintf( '正在上传 %d/%d：%s', $index, $total, $file['filename'] ),
				array(
					'current'  => $index,
					'total'    => $total,
					'filename' => $file['filename'],
				)
			);

			$item = self::upload_one_file( $file, $settings, $trigger );
			$result['processed'][] = $item;

			if ( ! empty( $item['skipped'] ) ) {
				continue;
			}

			if ( ! empty( $item['success'] ) ) {
				$result['success_count']++;
			} else {
				$result['failed_count']++;
			}
		}

		$left                   = Ai1_Oss_Auto_Backup_Ai1wm_Source::get_pending_uploads( null );
		$unstable               = Ai1_Oss_Auto_Backup_Ai1wm_Source::count_unstable_wpress();
		$result['pending_left'] = count( $left );
		$result['unstable_count'] = $unstable;
		$result['cleanup']      = self::maybe_run_cleanup( $settings, $result );

		self::release_lock();

		$ok      = $result['failed_count'] === 0;
		$message = sprintf(
			'完成：成功 %d，失败 %d，剩余待传 %d%s。',
			$result['success_count'],
			$result['failed_count'],
			$result['pending_left'],
			$unstable > 0 ? sprintf( '，另有 %d 个半包待稳定', $unstable ) : ''
		);

		return self::finish_job_result( $ok, $message, $result );
	}

	/**
	 * 对日志行对应本地包执行重新上传。
	 *
	 * @param int $log_id 日志 ID。
	 * @return array<string,mixed>
	 */
	public static function reupload( $log_id ) {
		$log = Ai1_Oss_Auto_Backup_Logger::get_by_id( (int) $log_id );
		if ( ! $log ) {
			return self::finish_job_result( false, '日志记录不存在。', array(), false );
		}

		$file = Ai1_Oss_Auto_Backup_Ai1wm_Source::find_local_file( (string) $log->local_filename );
		if ( ! $file ) {
			return self::finish_job_result( false, '本地不存在同名完整 `.wpress`，无法重新上传。', array(), false );
		}

		$settings   = Ai1_Oss_Auto_Backup_Settings::get_settings();
		$validation = Ai1_Oss_Auto_Backup_Oss_Client::validate_required_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return self::finish_job_result( false, $validation->get_error_message(), array(), false );
		}

		Ai1_Oss_Auto_Backup_Logger::fail_stale_running( self::LOCK_TTL );
		$lock = self::acquire_lock( 'reupload' );
		if ( is_wp_error( $lock ) ) {
			return self::finish_job_result( false, $lock->get_error_message(), array( 'locked' => true ), false );
		}

		self::update_lock_progress( '正在重新上传：' . $file['filename'], array( 'filename' => $file['filename'] ) );
		$item = self::upload_one_file( $file, $settings, 'reupload' );
		self::release_lock();

		if ( empty( $item['success'] ) ) {
			return self::finish_job_result(
				false,
				$item['message'] ?? '重新上传失败。',
				array(
					'success_count' => 0,
					'failed_count'  => 1,
					'processed'     => array( $item ),
				)
			);
		}

		$cleanup = self::maybe_run_cleanup( $settings, array( 'success_count' => 1, 'failed_count' => 0 ) );

		return self::finish_job_result(
			true,
			'重新上传成功：' . $file['filename'],
			array(
				'success_count' => 1,
				'failed_count'  => 0,
				'processed'     => array( $item ),
				'cleanup'       => $cleanup,
			)
		);
	}

	/**
	 * 按规则执行保留清理与 A3。
	 *
	 * @param array<string,mixed> $settings 设置。
	 * @param array<string,mixed> $result   Job 结果摘要。
	 * @return array<string,mixed>
	 */
	private static function maybe_run_cleanup( $settings, $result ) {
		$all_failed = ! empty( $result['failed_count'] )
			&& empty( $result['success_count'] )
			&& empty( $result['skipped'] );

		return Ai1_Oss_Auto_Backup_Retention::run( $settings, ! $all_failed );
	}

	/**
	 * 上传单个文件并写日志。
	 *
	 * @param array<string,mixed> $file     文件信息。
	 * @param array<string,mixed> $settings 配置。
	 * @param string              $trigger  触发来源。
	 * @return array<string,mixed>
	 */
	private static function upload_one_file( $file, $settings, $trigger ) {
		// 上传前再核一次完整性，避免长 Job 期间文件仍在写入。
		if ( empty( $file['path'] ) || ! Ai1_Oss_Auto_Backup_Ai1wm_Source::is_file_stable( $file['path'] ) ) {
			return array(
				'success'  => false,
				'skipped'  => true,
				'filename' => (string) ( $file['filename'] ?? '' ),
				'message'  => '文件仍在写入或为空，本轮跳过。',
			);
		}

		$object_key = Ai1_Oss_Auto_Backup_Oss_Client::build_object_key( $settings, $file['filename'] );
		$started_ms = (int) round( microtime( true ) * 1000 );

		$log_id = Ai1_Oss_Auto_Backup_Logger::insert_running(
			array(
				'trigger_type'   => $trigger,
				'local_filename' => $file['filename'],
				'local_path'     => $file['path'],
				'file_size'      => $file['size'],
				'file_mtime'     => $file['mtime_mysql'],
				'oss_bucket'     => (string) $settings['oss_bucket'],
				'oss_object_key' => $object_key,
			)
		);

		$upload = Ai1_Oss_Auto_Backup_Oss_Client::upload_file( $settings, $file['path'], $object_key );
		if ( is_wp_error( $upload ) ) {
			Ai1_Oss_Auto_Backup_Logger::mark_failed( $log_id, $upload->get_error_message() );

			return array(
				'success'  => false,
				'log_id'   => $log_id,
				'filename' => $file['filename'],
				'message'  => $upload->get_error_message(),
			);
		}

		$duration = max( 0, (int) round( microtime( true ) * 1000 ) - $started_ms );
		$meta     = array(
			'part_size' => Ai1_Oss_Auto_Backup_Oss_Client::PART_SIZE,
		);

		$marked = Ai1_Oss_Auto_Backup_Logger::mark_success(
			$log_id,
			array(
				'oss_bucket'     => $upload['bucket'],
				'oss_object_key' => $upload['object_key'],
				'oss_etag'       => $upload['etag'],
				'duration_ms'    => $duration,
				'meta_json'      => $meta,
			)
		);

		// 锁已被抢占或日志已收场：不删本地，避免双跑误删。
		if ( ! $marked ) {
			return array(
				'success'  => false,
				'log_id'   => $log_id,
				'filename' => $file['filename'],
				'message'  => '上传完成但日志未处于 running（可能已被 C1 收场），未删除本地文件。',
			);
		}

		Ai1_Oss_Auto_Backup_Logger::promote_monthly_anchor( $log_id );

		$deleted_local = false;
		if ( ! empty( $settings['delete_local_after_upload'] ) ) {
			$deleted_local = self::delete_local_wpress( $file['path'] );
		}

		return array(
			'success'        => true,
			'log_id'         => $log_id,
			'filename'       => $file['filename'],
			'oss_object_key' => $upload['object_key'],
			'duration_ms'    => $duration,
			'deleted_local'  => $deleted_local,
			'message'        => '上传成功',
		);
	}

	/**
	 * 仅删除 `.wpress` 本地文件。
	 *
	 * @param string $path 文件路径。
	 * @return bool
	 */
	private static function delete_local_wpress( $path ) {
		$path = (string) $path;
		if ( '' === $path || ! is_file( $path ) ) {
			return false;
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'wpress' !== $ext ) {
			return false;
		}

		$base = wp_normalize_path( Ai1_Oss_Auto_Backup_Ai1wm_Source::get_backups_path() );
		$real = wp_normalize_path( $path );
		if ( 0 !== strpos( $real, trailingslashit( $base ) ) ) {
			return false;
		}

		return unlink( $path );
	}

	/**
	 * 获取锁状态。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_lock() {
		$lock = get_option( self::LOCK_OPTION, null );
		if ( ! is_array( $lock ) || empty( $lock['started_at'] ) ) {
			return null;
		}

		$started = (int) $lock['started_at'];
		if ( ( time() - $started ) > self::LOCK_TTL ) {
			return null;
		}

		return self::enrich_lock_download_progress( $lock );
	}

	/**
	 * 用临时文件实际体积补全拉取进度（供另一请求轮询，不改写锁）。
	 *
	 * @param array<string,mixed> $lock 锁数据。
	 * @return array<string,mixed>
	 */
	private static function enrich_lock_download_progress( $lock ) {
		$progress = isset( $lock['progress'] ) && is_array( $lock['progress'] ) ? $lock['progress'] : array();
		if ( 'download' !== (string) ( $progress['phase'] ?? '' ) ) {
			return $lock;
		}

		$tmp = isset( $progress['tmp_path'] ) ? (string) $progress['tmp_path'] : '';
		if ( '' === $tmp || ! preg_match( '/\.part\.[A-Za-z0-9]+$/', $tmp ) ) {
			return $lock;
		}

		$backups = realpath( Ai1_Oss_Auto_Backup_Ai1wm_Source::get_backups_path() );
		$tmp_dir = realpath( dirname( $tmp ) );
		if ( ! $backups || ! $tmp_dir ) {
			return $lock;
		}

		$backups_slash = trailingslashit( wp_normalize_path( $backups ) );
		$tmp_dir_norm  = wp_normalize_path( $tmp_dir );
		if ( $tmp_dir_norm !== untrailingslashit( $backups_slash ) && 0 !== strpos( $tmp_dir_norm, $backups_slash ) ) {
			return $lock;
		}

		$downloaded = 0;
		if ( is_file( $tmp ) ) {
			clearstatcache( true, $tmp );
			$downloaded = max( 0, (int) filesize( $tmp ) );
		}

		$expected  = max( 0, (int) ( $progress['expected_bytes'] ?? 0 ) );
		$filename  = (string) ( $progress['filename'] ?? '备份包' );
		$percent   = 0;
		if ( $expected > 0 ) {
			$percent = (int) min( 99, floor( ( $downloaded * 100 ) / $expected ) );
		}

		$progress['downloaded_bytes'] = $downloaded;
		$progress['percent']          = $percent;
		$lock['progress']             = $progress;

		if ( $expected > 0 ) {
			$lock['message'] = sprintf(
				'正在从 OSS 拉取 %s：%s / %s（%d%%）。请勿关闭本页。',
				$filename,
				size_format( $downloaded, 2 ),
				size_format( $expected, 2 ),
				$percent
			);
		} elseif ( $downloaded > 0 ) {
			$lock['message'] = sprintf(
				'正在从 OSS 拉取 %s：已写入 %s，总大小未知，请稍候。',
				$filename,
				size_format( $downloaded, 2 )
			);
		}

		return $lock;
	}

	/**
	 * 抢占锁（add_option CAS，过期可抢）。
	 *
	 * @param string $trigger 触发来源。
	 * @return true|\WP_Error
	 */
	public static function acquire_lock( $trigger ) {
		$existing = get_option( self::LOCK_OPTION, null );
		if ( is_array( $existing ) && ! empty( $existing['started_at'] ) ) {
			$age = time() - (int) $existing['started_at'];
			if ( $age <= self::LOCK_TTL ) {
				return new WP_Error(
					'ai1_oss_auto_backup_locked',
					sprintf(
						'已有任务进行中（%s），请稍后再试或清除锁。',
						! empty( $existing['message'] ) ? $existing['message'] : '处理中'
					)
				);
			}

			// 锁过期：C1 允许抢占（收场 running + abort multipart）。
			Ai1_Oss_Auto_Backup_Logger::fail_stale_running( self::LOCK_TTL );
			delete_option( self::LOCK_OPTION );
		} elseif ( false !== $existing && null !== $existing ) {
			// 异常残留值，清掉以便 CAS。
			delete_option( self::LOCK_OPTION );
		}

		$payload = array(
			'started_at' => time(),
			'trigger'    => sanitize_key( $trigger ),
			'message'    => '任务启动中…',
			'progress'   => array(),
			'token'      => wp_generate_password( 12, false, false ),
		);

		// add_option 仅在键不存在时成功，降低双开竞态。
		$added = add_option( self::LOCK_OPTION, $payload, '', 'no' );
		if ( ! $added ) {
			return new WP_Error(
				'ai1_oss_auto_backup_locked',
				'已有任务刚刚启动，请稍后再试。'
			);
		}

		return true;
	}

	/**
	 * 更新锁进度。
	 *
	 * @param string              $message  文案。
	 * @param array<string,mixed> $progress 进度。
	 * @return void
	 */
	public static function update_lock_progress( $message, $progress = array() ) {
		$lock = get_option( self::LOCK_OPTION, array() );
		if ( ! is_array( $lock ) ) {
			$lock = array();
		}

		$lock['message']     = sanitize_text_field( $message );
		$lock['progress']    = $progress;
		$lock['updated_at']  = time();
		$lock['started_at']  = ! empty( $lock['started_at'] ) ? (int) $lock['started_at'] : time();

		update_option( self::LOCK_OPTION, $lock, false );
	}

	/**
	 * 释放锁。
	 *
	 * @return void
	 */
	public static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * 管理员强制清锁。
	 *
	 * @return array<string,mixed>
	 */
	public static function clear_lock() {
		$had = get_option( self::LOCK_OPTION, null );
		self::release_lock();

		global $wpdb;
		$table = Ai1_Oss_Auto_Backup_Logger::get_table_name();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, oss_object_key FROM {$table} WHERE status = %s", 'running' ) );
		$fixed = 0;
		$keys  = array();

		foreach ( (array) $rows as $row ) {
			if ( Ai1_Oss_Auto_Backup_Logger::mark_failed( (int) $row->id, '异常中断：管理员清除任务锁（C1）。' ) ) {
				$fixed++;
				if ( ! empty( $row->oss_object_key ) ) {
					$keys[] = (string) $row->oss_object_key;
				}
			}
		}

		$aborted = 0;
		$settings = Ai1_Oss_Auto_Backup_Settings::get_settings();
		$abort    = Ai1_Oss_Auto_Backup_Oss_Client::abort_orphan_multiparts(
			$settings,
			! empty( $keys ) ? $keys : null
		);
		if ( is_array( $abort ) && isset( $abort['aborted'] ) ) {
			$aborted = (int) $abort['aborted'];
		}

		$parts = array( '已清除任务锁' );
		if ( $fixed > 0 ) {
			$parts[] = "收场 {$fixed} 条 running 日志";
		}
		if ( $aborted > 0 ) {
			$parts[] = "中止 {$aborted} 个未完成 multipart";
		}

		return array(
			'cleared'     => true,
			'had_lock'    => is_array( $had ) && ! empty( $had['started_at'] ),
			'stale_fixed' => $fixed,
			'aborted'     => $aborted,
			'message'     => implode( '，', $parts ) . '。',
		);
	}

	/**
	 * 查询 Job 状态。
	 *
	 * @return array<string,mixed>
	 */
	public static function get_status() {
		$lock    = self::get_lock();
		$stats   = Ai1_Oss_Auto_Backup_Ai1wm_Source::get_directory_stats();
		$summary = Ai1_Oss_Auto_Backup_Logger::get_recent_summary();

		return array(
			'running'   => null !== $lock,
			'lock'      => $lock,
			'directory' => array(
				'path'            => $stats['path'],
				'total_wpress'    => $stats['total_wpress'],
				'pending_count'   => $stats['pending_count'],
				'unstable_count'  => $stats['unstable_count'],
			),
			'summary'   => $summary,
			'schedule'  => Ai1_Oss_Auto_Backup_Cron::get_schedule_display(),
		);
	}

	/**
	 * 写入 last_run 并返回统一结果。
	 *
	 * @param bool                $success        是否整体成功。
	 * @param string              $message        消息。
	 * @param array<string,mixed> $extra          附加数据。
	 * @param bool                $persist_summary 是否写入 last_run。
	 * @return array<string,mixed>
	 */
	private static function finish_job_result( $success, $message, $extra = array(), $persist_summary = true ) {
		if ( $persist_summary ) {
			$settings                         = Ai1_Oss_Auto_Backup_Settings::get_settings();
			$settings['last_run_at']          = current_time( 'mysql' );
			$settings['last_run_status']      = $success ? 'success' : 'failed';
			$settings['last_run_message']     = sanitize_text_field( $message );
			$settings['last_job_had_failure'] = ! $success || ! empty( $extra['failed_count'] );
			$settings['alert_signature']      = Ai1_Oss_Auto_Backup_Admin_Notices::build_signature( $settings );
			update_option( Ai1_Oss_Auto_Backup_Settings::OPTION_KEY, $settings, false );
		}

		return array_merge(
			array(
				'success' => (bool) $success,
				'message' => $message,
			),
			$extra
		);
	}
}
