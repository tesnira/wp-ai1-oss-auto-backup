<?php
/**
 * 自动云备份：OSS 拉取与远端校验。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 拉取到 ai1wm-backups（D1）与远端存在性校验。
 */
class Ai1_Oss_Auto_Backup_Pull {

	/**
	 * 按日志 ID 拉取。
	 *
	 * @param int  $log_id 日志 ID。
	 * @param bool $force  是否覆盖本地同名文件。
	 * @return array<string,mixed>
	 */
	public static function pull_by_log_id( $log_id, $force = false ) {
		$log = Ai1_Oss_Auto_Backup_Logger::get_by_id( (int) $log_id );
		if ( ! $log ) {
			return array(
				'success' => false,
				'message' => '日志记录不存在。',
			);
		}

		if ( 'success' !== (string) $log->status || '' === (string) $log->oss_object_key ) {
			return array(
				'success' => false,
				'message' => '仅可拉取上传成功且含 OSS Key 的记录。',
			);
		}

		$wpress = self::pull_object_key( (string) $log->oss_object_key, $force, (int) $log->id );
		if ( empty( $wpress['success'] ) ) {
			return $wpress;
		}

		return $wpress;
	}

	/**
	 * 按 Object Key 拉取到 AI1WM 目录。
	 *
	 * @param string   $object_key Object Key。
	 * @param bool     $force      是否覆盖。
	 * @param int|null $log_id     关联日志（可选）。
	 * @return array<string,mixed>
	 */
	public static function pull_object_key( $object_key, $force = false, $log_id = null ) {
		$settings   = Ai1_Oss_Auto_Backup_Settings::get_settings();
		$validation = Ai1_Oss_Auto_Backup_Oss_Client::validate_required_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return array(
				'success' => false,
				'message' => $validation->get_error_message(),
			);
		}

		$allowed = Ai1_Oss_Auto_Backup_Oss_Client::assert_allowed_object_key( $settings, $object_key );
		if ( is_wp_error( $allowed ) ) {
			return array(
				'success' => false,
				'message' => $allowed->get_error_message(),
			);
		}

		$filename = sanitize_file_name( wp_basename( $object_key ) );
		if ( '' === $filename || ! preg_match( '/(?:\.wpress|\.uploads\.zip)$/i', $filename ) ) {
			return array(
				'success' => false,
				'message' => '无法从 Object Key 解析合法文件名。',
			);
		}

		$local_path = trailingslashit( Ai1_Oss_Auto_Backup_Ai1wm_Source::get_backups_path() ) . $filename;
		if ( is_file( $local_path ) && ! $force ) {
			return array(
				'success'          => false,
				'needs_overwrite'  => true,
				'message'          => '本地已存在同名文件，如需覆盖请确认后重试。',
				'filename'         => $filename,
				'local_path'       => $local_path,
			);
		}

		// 与上传共用锁，避免边传边拉。
		$lock = Ai1_Oss_Auto_Backup_Job_Runner::acquire_lock( 'pull' );
		if ( is_wp_error( $lock ) ) {
			return array(
				'success' => false,
				'message' => $lock->get_error_message(),
				'locked'  => true,
			);
		}

		Ai1_Oss_Auto_Backup_Job_Runner::update_lock_progress(
			'正在从 OSS 拉取：' . $filename . '，请勿关闭本页。',
			array(
				'phase'    => 'download',
				'filename' => $filename,
			)
		);

		$downloaded = Ai1_Oss_Auto_Backup_Oss_Client::download_object(
			$settings,
			$object_key,
			$local_path,
			static function ( $tmp_path, $expected_bytes ) use ( $filename ) {
				$expected_bytes = (int) $expected_bytes;
				$size_hint      = $expected_bytes > 0 ? size_format( $expected_bytes, 2 ) : '大小未知';
				Ai1_Oss_Auto_Backup_Job_Runner::update_lock_progress(
					sprintf( '正在从 OSS 拉取 %s（%s），请勿关闭本页。', $filename, $size_hint ),
					array(
						'phase'           => 'download',
						'filename'        => $filename,
						'tmp_path'        => (string) $tmp_path,
						'expected_bytes'  => $expected_bytes,
					)
				);
			}
		);
		Ai1_Oss_Auto_Backup_Job_Runner::release_lock();

		if ( is_wp_error( $downloaded ) ) {
			// 明确不存在则尝试打标。
			$exists = Ai1_Oss_Auto_Backup_Oss_Client::object_exists( $settings, $object_key );
			if ( false === $exists && $log_id ) {
				Ai1_Oss_Auto_Backup_Logger::mark_remote_missing( (int) $log_id );
			}

			return array(
				'success' => false,
				'message' => $downloaded->get_error_message(),
			);
		}

		if ( $log_id ) {
			Ai1_Oss_Auto_Backup_Logger::clear_remote_missing( (int) $log_id );
		}

		$size_label = size_format( (int) $downloaded['size'], 2 );

		return array(
			'success'              => true,
			'message'              => sprintf(
				'已拉取到 All-in-One 备份目录：%s（%s）。请到「All-in-One WP Migration → 备份」查看；若列表尚未出现，等待几秒后刷新该页即可。',
				$filename,
				$size_label ? $size_label : ( (int) $downloaded['size'] ) . ' B'
			),
			'filename'             => $filename,
			'local_path'           => $local_path,
			'size'                 => (int) $downloaded['size'],
			'size_label'           => $size_label ? $size_label : ( (int) $downloaded['size'] ) . ' B',
			'object_key'           => $object_key,
		);
	}

	/**
	 * 删除本地备份目录中的 `.wpress`，不删除 OSS 对象。
	 *
	 * @param string $filename 文件名。
	 * @param int    $log_id   日志 ID；大于 0 时删除该记录对应的站点包。
	 * @return array<string,mixed>
	 */
	public static function delete_local_backup( $filename, $log_id = 0 ) {
		$log_id   = (int) $log_id;
		$filename = sanitize_file_name( wp_basename( (string) $filename ) );
		$targets  = array();

		if ( $log_id > 0 ) {
			$log = Ai1_Oss_Auto_Backup_Logger::get_by_id( $log_id );
			if ( ! $log ) {
				return array(
					'success' => false,
					'message' => '日志记录不存在。',
				);
			}
			$wpress = sanitize_file_name( wp_basename( (string) $log->local_filename ) );
			if ( '' !== $wpress ) {
				$targets[] = $wpress;
			}
		} elseif ( '' !== $filename ) {
			$targets[] = $filename;
		}

		$targets = array_values( array_unique( array_filter( $targets ) ) );
		if ( empty( $targets ) ) {
			return array(
				'success' => false,
				'message' => '请提供要删除的本地文件名或日志 ID。',
			);
		}

		$deleted = array();
		foreach ( $targets as $name ) {
			$result = self::unlink_local_backup_file( $name );
			if ( true === $result ) {
				$deleted[] = $name;
			} elseif ( 'missing' !== $result ) {
				return array(
					'success' => false,
					'message' => is_string( $result ) ? $result : ( '删除本地文件失败：' . $name ),
				);
			}
		}

		if ( empty( $deleted ) ) {
			return array(
				'success' => false,
				'message' => '本地文件不存在，或路径不在 All-in-One 备份目录内。',
			);
		}

		return array(
			'success'  => true,
			'filename' => implode( ', ', $deleted ),
			'message'  => sprintf(
				'已删除本地文件 %s（OSS 对象未改动）。',
				implode( '、', $deleted )
			),
		);
	}

	/**
	 * 删除备份目录中的单个合法备份文件。
	 *
	 * @param string $filename 文件名。
	 * @return true|string 成功返回 true；不存在返回 missing；其他错误返回说明。
	 */
	private static function unlink_local_backup_file( $filename ) {
		$filename = sanitize_file_name( wp_basename( (string) $filename ) );
		if ( '' === $filename || ! preg_match( '/\.wpress$/i', $filename ) ) {
			return '仅允许删除本地 `.wpress`。';
		}

		$base      = wp_normalize_path( Ai1_Oss_Auto_Backup_Ai1wm_Source::get_backups_path() );
		$path      = wp_normalize_path( trailingslashit( $base ) . $filename );
		$real_base = realpath( $base );
		$real_path = is_file( $path ) ? realpath( $path ) : false;

		if ( ! $real_base || ! $real_path || 0 !== strpos( $real_path, trailingslashit( wp_normalize_path( $real_base ) ) ) ) {
			return 'missing';
		}

		if ( ! unlink( $real_path ) ) {
			return '删除本地文件失败：' . $filename;
		}

		return true;
	}

	/**
	 * 删除 OSS 上的备份对象（不删本地文件）。
	 *
	 * @param string $object_key Object Key。
	 * @return array<string,mixed>
	 */
	public static function delete_remote_object( $object_key ) {
		$settings   = Ai1_Oss_Auto_Backup_Settings::get_settings();
		$validation = Ai1_Oss_Auto_Backup_Oss_Client::validate_required_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return array(
				'success' => false,
				'message' => $validation->get_error_message(),
			);
		}

		$object_key = ltrim( str_replace( '\\', '/', (string) $object_key ), '/' );
		$deleted    = Ai1_Oss_Auto_Backup_Oss_Client::delete_object( $settings, $object_key );
		if ( is_wp_error( $deleted ) ) {
			return array(
				'success' => false,
				'message' => $deleted->get_error_message(),
			);
		}

		Ai1_Oss_Auto_Backup_Logger::mark_remote_missing_by_object_key( $object_key );

		return array(
			'success'    => true,
			'object_key' => $object_key,
			'message'    => sprintf( '已删除 OSS 对象 %s（本地文件未改动）。', $object_key ),
		);
	}

	/**
	 * 校验远端对象：存在则清除缺失标记，不存在则打标。
	 *
	 * @param array<int,int>|null $log_ids 指定日志；null 表示校验最近 success。
	 * @param int                 $limit   未指定 ID 时的上限。
	 * @return array<string,mixed>
	 */
	public static function verify_remote( $log_ids = null, $limit = 50 ) {
		$settings   = Ai1_Oss_Auto_Backup_Settings::get_settings();
		$validation = Ai1_Oss_Auto_Backup_Oss_Client::validate_required_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return array(
				'success' => false,
				'message' => $validation->get_error_message(),
			);
		}

		$rows = array();
		if ( is_array( $log_ids ) && ! empty( $log_ids ) ) {
			foreach ( $log_ids as $id ) {
				$row = Ai1_Oss_Auto_Backup_Logger::get_by_id( (int) $id );
				if ( $row && 'success' === $row->status && '' !== (string) $row->oss_object_key ) {
					$rows[] = $row;
				}
			}
		} else {
			$rows = array_slice( Ai1_Oss_Auto_Backup_Logger::get_success_candidates_including_missing( $limit ), 0, $limit );
		}

		$ok      = 0;
		$missing = 0;
		$errors  = 0;
		$details = array();

		foreach ( $rows as $row ) {
			$key    = (string) $row->oss_object_key;
			$exists = Ai1_Oss_Auto_Backup_Oss_Client::object_exists( $settings, $key );

			if ( is_wp_error( $exists ) ) {
				$errors++;
				$details[] = array(
					'log_id'  => (int) $row->id,
					'status'  => 'error',
					'message' => $exists->get_error_message(),
				);
				continue;
			}

			if ( $exists ) {
				Ai1_Oss_Auto_Backup_Logger::clear_remote_missing( (int) $row->id );
				$ok++;
				$details[] = array(
					'log_id' => (int) $row->id,
					'status' => 'ok',
				);
			} else {
				Ai1_Oss_Auto_Backup_Logger::mark_remote_missing( (int) $row->id );
				$missing++;
				$details[] = array(
					'log_id' => (int) $row->id,
					'status' => 'missing',
				);
			}
		}

		return array(
			'success' => true,
			'message' => sprintf( '远端校验完成：存在 %d，缺失 %d，探测失败 %d。', $ok, $missing, $errors ),
			'ok'      => $ok,
			'missing' => $missing,
			'errors'  => $errors,
			'details' => $details,
		);
	}

	/**
	 * 列举 OSS 备份对象。
	 *
	 * @param int $max_keys 上限。
	 * @return array<string,mixed>
	 */
	public static function list_objects( $max_keys = 100 ) {
		$settings   = Ai1_Oss_Auto_Backup_Settings::get_settings();
		$validation = Ai1_Oss_Auto_Backup_Oss_Client::validate_required_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return array(
				'success' => false,
				'message' => $validation->get_error_message(),
			);
		}

		$items = Ai1_Oss_Auto_Backup_Oss_Client::list_wpress_objects( $settings, $max_keys );
		if ( is_wp_error( $items ) ) {
			return array(
				'success' => false,
				'message' => $items->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf( '共列举到 %d 个 `.wpress` 对象。', count( $items ) ),
			'items'   => $items,
			'prefix'  => Ai1_Oss_Auto_Backup_Settings::sanitize_prefix( (string) ( $settings['oss_prefix'] ?? '' ) ),
		);
	}
}
