<?php
/**
 * 自动云备份上传日志。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 日志表 CRUD 与建表。
 */
class Ai1_Oss_Auto_Backup_Logger {

	/**
	 * 数据库版本号。
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0';

	/**
	 * 版本 option 键。
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'ai1_oss_auto_backup_db_version';

	/**
	 * 注册建表检查。
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
	}

	/**
	 * 返回表名。
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'ai1_oss_backup_logs';
	}

	/**
	 * 按需建表/升级。
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * 创建或更新日志表。
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table           = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			finished_at datetime NULL,
			trigger_type varchar(16) NOT NULL DEFAULT 'manual',
			status varchar(16) NOT NULL DEFAULT 'running',
			local_filename varchar(255) NOT NULL DEFAULT '',
			local_path text NULL,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			file_mtime datetime NULL,
			oss_bucket varchar(128) NOT NULL DEFAULT '',
			oss_object_key varchar(512) NOT NULL DEFAULT '',
			oss_etag varchar(128) NULL,
			is_monthly_anchor tinyint(1) unsigned NOT NULL DEFAULT 0,
			remote_missing tinyint(1) unsigned NOT NULL DEFAULT 0,
			duration_ms int(10) unsigned NULL,
			error_message text NULL,
			meta_json longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status_created (status, created_at),
			KEY oss_object_key (oss_object_key(191)),
			KEY local_filename (local_filename),
			KEY is_monthly_anchor (is_monthly_anchor)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * 写入 running 日志。
	 *
	 * @param array<string,mixed> $data 日志字段。
	 * @return int
	 */
	public static function insert_running( $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$wpdb->insert(
			self::get_table_name(),
			array(
				'created_at'        => $now,
				'trigger_type'      => sanitize_key( (string) ( $data['trigger_type'] ?? 'manual' ) ),
				'status'            => 'running',
				'local_filename'    => sanitize_file_name( (string) ( $data['local_filename'] ?? '' ) ),
				'local_path'        => (string) ( $data['local_path'] ?? '' ),
				'file_size'         => (int) ( $data['file_size'] ?? 0 ),
				'file_mtime'        => ! empty( $data['file_mtime'] ) ? (string) $data['file_mtime'] : null,
				'oss_bucket'        => sanitize_text_field( (string) ( $data['oss_bucket'] ?? '' ) ),
				'oss_object_key'    => sanitize_text_field( (string) ( $data['oss_object_key'] ?? '' ) ),
				'is_monthly_anchor' => 0,
				'remote_missing'    => 0,
				'meta_json'         => ! empty( $data['meta_json'] ) ? wp_json_encode( $data['meta_json'] ) : null,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * 标记成功。
	 *
	 * @param int                 $id   日志 ID。
	 * @param array<string,mixed> $data 更新字段。
	 * @return bool
	 */
	public static function mark_success( $id, $data ) {
		global $wpdb;

		$updated = $wpdb->update(
			self::get_table_name(),
			array(
				'status'         => 'success',
				'finished_at'    => current_time( 'mysql' ),
				'oss_bucket'     => sanitize_text_field( (string) ( $data['oss_bucket'] ?? '' ) ),
				'oss_object_key' => sanitize_text_field( (string) ( $data['oss_object_key'] ?? '' ) ),
				'oss_etag'       => sanitize_text_field( (string) ( $data['oss_etag'] ?? '' ) ),
				'duration_ms'    => isset( $data['duration_ms'] ) ? (int) $data['duration_ms'] : null,
				'remote_missing' => 0,
				'error_message'  => null,
				'meta_json'      => isset( $data['meta_json'] ) ? wp_json_encode( $data['meta_json'] ) : null,
			),
			array(
				'id'     => (int) $id,
				'status' => 'running',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		return false !== $updated && (int) $updated > 0;
	}

	/**
	 * 标记失败。
	 *
	 * @param int    $id      日志 ID。
	 * @param string $message 错误信息。
	 * @param array  $meta    附加元数据。
	 * @return bool
	 */
	public static function mark_failed( $id, $message, $meta = array() ) {
		global $wpdb;

		$updated = $wpdb->update(
			self::get_table_name(),
			array(
				'status'        => 'failed',
				'finished_at'   => current_time( 'mysql' ),
				'error_message' => sanitize_textarea_field( (string) $message ),
				'meta_json'     => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
			),
			array(
				'id'     => (int) $id,
				'status' => 'running',
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		return false !== $updated && (int) $updated > 0;
	}

	/**
	 * 将超时仍为 running 的记录收场为 failed，并中止对应 multipart。
	 *
	 * @param int $older_than_seconds 超时秒数。
	 * @return int 收场条数。
	 */
	public static function fail_stale_running( $older_than_seconds = 21600 ) {
		global $wpdb;

		$table           = self::get_table_name();
		$threshold_local = date_i18n( 'Y-m-d H:i:s', time() - (int) $older_than_seconds );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, oss_object_key, meta_json FROM {$table} WHERE status = %s AND created_at < %s",
				'running',
				$threshold_local
			)
		);

		$count = 0;
		$keys  = array();

		foreach ( (array) $rows as $row ) {
			if ( self::mark_failed( (int) $row->id, '异常中断：任务超时或进程被终止（C1 自动收场）。' ) ) {
				$count++;
				if ( ! empty( $row->oss_object_key ) ) {
					$keys[] = (string) $row->oss_object_key;
				}
			}
		}

		if ( ! empty( $keys ) ) {
			$settings = Ai1_Oss_Auto_Backup_Settings::get_settings();
			Ai1_Oss_Auto_Backup_Oss_Client::abort_orphan_multiparts( $settings, $keys );
		}

		return $count;
	}

	/**
	 * 按 ID 获取日志。
	 *
	 * @param int $id 日志 ID。
	 * @return object|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE id = %d',
				(int) $id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * 判断文件名是否已有有效成功上传（远端未标记缺失）。
	 *
	 * @param string $filename 本地文件名。
	 * @return bool
	 */
	public static function has_active_success( $filename ) {
		global $wpdb;

		$filename = sanitize_file_name( $filename );
		$count    = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM ' . self::get_table_name() . ' WHERE local_filename = %s AND status = %s AND remote_missing = 0',
				$filename,
				'success'
			)
		);

		return $count > 0;
	}

	/**
	 * 分页列出日志。
	 *
	 * @param int $page     页码（从 1 开始）。
	 * @param int $per_page 每页条数。
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
	 */
	public static function list_logs( $page = 1, $per_page = 20 ) {
		global $wpdb;

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = self::get_table_name();

		$total = (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$table}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::format_log_row( $row );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * 格式化日志行供前端使用。
	 *
	 * @param object $row 数据库行。
	 * @return array<string,mixed>
	 */
	public static function format_log_row( $row ) {
		$filename     = (string) $row->local_filename;
		$local_exists = Ai1_Oss_Auto_Backup_Ai1wm_Source::local_file_exists( $filename );
		$oss_uri      = '';

		if ( ! empty( $row->oss_bucket ) && ! empty( $row->oss_object_key ) ) {
			$oss_uri = 'oss://' . $row->oss_bucket . '/' . $row->oss_object_key;
		}

		$is_success = 'success' === (string) $row->status;

		return array(
			'id'                      => (int) $row->id,
			'created_at'              => (string) $row->created_at,
			'finished_at'             => (string) $row->finished_at,
			'trigger_type'            => (string) $row->trigger_type,
			'status'                  => (string) $row->status,
			'local_filename'          => $filename,
			'file_size'               => (int) $row->file_size,
			'file_size_label'         => size_format( (int) $row->file_size ),
			'oss_bucket'              => (string) $row->oss_bucket,
			'oss_object_key'          => (string) $row->oss_object_key,
			'oss_uri'                 => $oss_uri,
			'oss_etag'                => (string) $row->oss_etag,
			'is_monthly_anchor'       => (int) $row->is_monthly_anchor,
			'remote_missing'          => (int) $row->remote_missing,
			'duration_ms'             => null !== $row->duration_ms ? (int) $row->duration_ms : null,
			'error_message'           => (string) $row->error_message,
			'local_exists'            => $local_exists,
			'can_reupload'            => $local_exists && in_array( (string) $row->status, array( 'success', 'failed' ), true ),
			'can_pull'                => $is_success && '' !== (string) $row->oss_object_key,
			'can_delete_local'        => $local_exists,
		);
	}

	/**
	 * 解析日志 meta_json。
	 *
	 * @param object $row 日志行。
	 * @return array<string,mixed>
	 */
	public static function decode_meta( $row ) {
		$raw = is_object( $row ) ? (string) ( $row->meta_json ?? '' ) : '';
		if ( '' === $raw ) {
			return array();
		}
		$meta = json_decode( $raw, true );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * 从备份文件名解析 YYYYMMDD（如 `site-20260823-020154-xxx.wpress`）。
	 *
	 * @param string $filename 本地文件名。
	 * @return string 八位日期，解析失败为空。
	 */
	public static function parse_backup_ymd_from_filename( $filename ) {
		$filename = (string) $filename;
		if ( '' === $filename ) {
			return '';
		}

		if ( ! preg_match_all( '/(?<!\d)(\d{8})(?!\d)/', $filename, $matches ) ) {
			return '';
		}

		foreach ( $matches[1] as $ymd ) {
			$year  = (int) substr( $ymd, 0, 4 );
			$month = (int) substr( $ymd, 4, 2 );
			$day   = (int) substr( $ymd, 6, 2 );
			if ( $year >= 2000 && $year <= 2100 && checkdate( $month, $day, $year ) ) {
				return $ymd;
			}
		}

		return '';
	}

	/**
	 * 备份内容月份 YYYY-MM：优先文件名日期，否则回退上传完成时间。
	 *
	 * @param object $row 日志行。
	 * @return string
	 */
	public static function get_backup_month_key_from_row( $row ) {
		$ymd = self::parse_backup_ymd_from_filename( isset( $row->local_filename ) ? (string) $row->local_filename : '' );
		if ( '' !== $ymd ) {
			return substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 );
		}

		$finished = ! empty( $row->finished_at ) ? $row->finished_at : ( isset( $row->created_at ) ? $row->created_at : '' );
		$month    = substr( (string) $finished, 0, 7 );

		return preg_match( '/^\d{4}-\d{2}$/', $month ) ? $month : '';
	}

	/**
	 * 同月内比较用的日期键：优先文件名 YYYYMMDD。
	 *
	 * @param object $row 日志行。
	 * @return string
	 */
	public static function get_backup_ymd_sort_key( $row ) {
		$ymd = self::parse_backup_ymd_from_filename( isset( $row->local_filename ) ? (string) $row->local_filename : '' );
		if ( '' !== $ymd ) {
			return $ymd;
		}

		$finished = ! empty( $row->finished_at ) ? $row->finished_at : ( isset( $row->created_at ) ? $row->created_at : '' );
		$digits   = preg_replace( '/\D/', '', (string) $finished );

		return substr( (string) $digits, 0, 8 );
	}

	/**
	 * 按备份文件名月份，将每月最后一个成功包标为月度锚点。
	 *
	 * @return void
	 */
	public static function rebuild_monthly_anchors() {
		global $wpdb;

		$settings = Ai1_Oss_Auto_Backup_Settings::get_settings();
		if ( empty( $settings['monthly_retain_enabled'] ) ) {
			self::clear_all_monthly_anchors();
			return;
		}

		$rows = self::get_success_candidates();
		if ( empty( $rows ) ) {
			self::clear_all_monthly_anchors();
			return;
		}

		$by_month = array();
		foreach ( $rows as $row ) {
			$month_key = self::get_backup_month_key_from_row( $row );
			if ( '' === $month_key ) {
				continue;
			}
			$by_month[ $month_key ][] = $row;
		}

		$anchor_ids = array();
		foreach ( $by_month as $group ) {
			usort(
				$group,
				static function ( $a, $b ) {
					$ya = Ai1_Oss_Auto_Backup_Logger::get_backup_ymd_sort_key( $a );
					$yb = Ai1_Oss_Auto_Backup_Logger::get_backup_ymd_sort_key( $b );
					if ( $ya !== $yb ) {
						return strcmp( $yb, $ya );
					}
					$ta = (string) ( $a->finished_at ?: $a->created_at );
					$tb = (string) ( $b->finished_at ?: $b->created_at );
					if ( $ta !== $tb ) {
						return strcmp( $tb, $ta );
					}
					return (int) $b->id - (int) $a->id;
				}
			);
			$anchor_ids[] = (int) $group[0]->id;
		}

		self::clear_all_monthly_anchors();

		$table = self::get_table_name();
		foreach ( $anchor_ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			$wpdb->update(
				$table,
				array( 'is_monthly_anchor' => 1 ),
				array( 'id' => $id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * 清除全部月度锚点。
	 *
	 * @return void
	 */
	public static function clear_all_monthly_anchors() {
		global $wpdb;

		$wpdb->query( 'UPDATE ' . self::get_table_name() . ' SET is_monthly_anchor = 0 WHERE is_monthly_anchor = 1' );
	}

	/**
	 * 上传成功后重算月度锚点（按文件名 YYYYMMDD 定月）。
	 *
	 * @param int $log_id 新成功日志 ID。
	 * @return void
	 */
	public static function promote_monthly_anchor( $log_id ) {
		$row = self::get_by_id( $log_id );
		if ( ! $row || 'success' !== $row->status ) {
			return;
		}

		self::rebuild_monthly_anchors();
	}

	/**
	 * 标记远端缺失。
	 *
	 * @param int $id 日志 ID。
	 * @return bool
	 */
	public static function mark_remote_missing( $id ) {
		global $wpdb;

		$updated = $wpdb->update(
			self::get_table_name(),
			array( 'remote_missing' => 1 ),
			array( 'id' => (int) $id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * 清除远端缺失标记。
	 *
	 * @param int $id 日志 ID。
	 * @return bool
	 */
	public static function clear_remote_missing( $id ) {
		global $wpdb;

		$updated = $wpdb->update(
			self::get_table_name(),
			array( 'remote_missing' => 0 ),
			array( 'id' => (int) $id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * 是否存在该 object key 的成功日志。
	 *
	 * @param string $object_key Object Key。
	 * @return bool
	 */
	public static function has_success_object_key( $object_key ) {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM ' . self::get_table_name() . ' WHERE status = %s AND oss_object_key = %s',
				'success',
				(string) $object_key
			)
		);

		return $count > 0;
	}

	/**
	 * 将引用该 Object Key 的成功日志标为远端缺失。
	 *
	 * @param string $object_key Object Key。
	 * @return int 更新条数。
	 */
	public static function mark_remote_missing_by_object_key( $object_key ) {
		global $wpdb;

		$object_key = (string) $object_key;
		if ( '' === $object_key ) {
			return 0;
		}

		$table = self::get_table_name();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s AND oss_object_key = %s",
				'success',
				$object_key
			)
		);

		$count = 0;
		foreach ( (array) $ids as $id ) {
			if ( self::mark_remote_missing( (int) $id ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * 成功候选（含已标缺失，用于校验）。
	 *
	 * @param int $limit 上限。
	 * @return array<int,object>
	 */
	public static function get_success_candidates_including_missing( $limit = 50 ) {
		global $wpdb;

		$table = self::get_table_name();
		$limit = max( 1, min( 200, (int) $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE status = %s AND oss_object_key <> ''
				ORDER BY COALESCE(finished_at, created_at) DESC, id DESC
				LIMIT %d",
				'success',
				$limit
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * 清除月度锚点标记。
	 *
	 * @param int $id 日志 ID。
	 * @return void
	 */
	public static function clear_monthly_anchor( $id ) {
		global $wpdb;

		$wpdb->update(
			self::get_table_name(),
			array( 'is_monthly_anchor' => 0 ),
			array( 'id' => (int) $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * 获取可作为清理候选的 success 日志（未标缺失）。
	 *
	 * @return array<int,object>
	 */
	public static function get_success_candidates() {
		global $wpdb;

		$table = self::get_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE status = %s AND remote_missing = 0 AND oss_object_key <> ''
				ORDER BY COALESCE(finished_at, created_at) DESC, id DESC",
				'success'
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A3：清理超过保留天数的日志，但跳过仍属保留范围的 success。
	 *
	 * @param array<string,mixed>|null $settings 设置。
	 * @return array<string,mixed>
	 */
	public static function cleanup_old_logs_a3( $settings = null ) {
		global $wpdb;

		$settings  = $settings ? $settings : Ai1_Oss_Auto_Backup_Settings::get_settings();
		$days      = defined( 'AI1_OSS_BACKUP_LOG_RETENTION_DAYS' ) ? (int) AI1_OSS_BACKUP_LOG_RETENTION_DAYS : 180;
		$cutoff    = date_i18n( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$protected = self::get_protected_log_ids_for_a3( $settings );
		$table     = self::get_table_name();

		if ( empty( $protected ) ) {
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s",
					$cutoff
				)
			);
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $protected ), '%d' ) );
			$sql          = "DELETE FROM {$table} WHERE created_at < %s AND id NOT IN ({$placeholders})";
			$params       = array_merge( array( $cutoff ), array_map( 'intval', $protected ) );
			$deleted      = (int) $wpdb->query( $wpdb->prepare( $sql, $params ) );
		}

		return array(
			'deleted' => max( 0, $deleted ),
			'message' => sprintf( '日志 A3 清理：删除 %d 条（保留仍属滚动/月度/地板范围的成功记录）。', max( 0, $deleted ) ),
		);
	}

	/**
	 * 计算 A3 保护 ID 集。
	 *
	 * @param array<string,mixed> $settings 设置。
	 * @return array<int,int>
	 */
	public static function get_protected_log_ids_for_a3( $settings ) {
		$candidates = self::get_success_candidates();
		$keep_count = max( 1, (int) $settings['keep_count'] );
		$protected  = array();

		$anchors = array();
		$rolling = array();

		$months     = max( 1, (int) $settings['monthly_keep_months'] );
		$cutoff     = new DateTimeImmutable( 'first day of -' . ( $months - 1 ) . ' months', wp_timezone() );
		$cutoff_key = $cutoff->format( 'Y-m' );

		foreach ( $candidates as $row ) {
			$id = (int) $row->id;
			if ( ! empty( $settings['monthly_retain_enabled'] ) && ! empty( $row->is_monthly_anchor ) ) {
				$month_key = self::get_backup_month_key_from_row( $row );
				if ( '' !== $month_key && $month_key >= $cutoff_key ) {
					$anchors[ $id ] = $row;
					$protected[ $id ] = $id;
					continue;
				}
			}
			$rolling[] = $row;
		}

		$kept_rolling = 0;
		foreach ( $rolling as $row ) {
			if ( $kept_rolling >= $keep_count ) {
				break;
			}
			$id = (int) $row->id;
			$protected[ $id ] = $id;
			$kept_rolling++;
		}

		// 地板：总数不足 keep_count 时，再按时间纳入。
		if ( count( $protected ) < $keep_count ) {
			foreach ( $candidates as $row ) {
				$id = (int) $row->id;
				if ( isset( $protected[ $id ] ) ) {
					continue;
				}
				$protected[ $id ] = $id;
				if ( count( $protected ) >= $keep_count ) {
					break;
				}
			}
		}

		return array_values( $protected );
	}

	/**
	 * 是否存在近期 C1 异常中断失败。
	 *
	 * @param int $within_days 天数。
	 * @return object|null
	 */
	public static function get_recent_c1_failure( $within_days = 7 ) {
		global $wpdb;

		$table  = self::get_table_name();
		$cutoff = date_i18n( 'Y-m-d H:i:s', time() - ( $within_days * DAY_IN_SECONDS ) );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE status = %s
					AND created_at >= %s
					AND error_message LIKE %s
				ORDER BY id DESC
				LIMIT 1",
				'failed',
				$cutoff,
				'%异常中断%'
			)
		);

		return $row ? $row : null;
	}

	/**
	 * 最近一次 Job 相关摘要（供状态展示）。
	 *
	 * @return array<string,mixed>
	 */
	public static function get_recent_summary() {
		$settings = Ai1_Oss_Auto_Backup_Settings::get_settings();

		return array(
			'last_run_at'          => (string) $settings['last_run_at'],
			'last_run_status'      => (string) $settings['last_run_status'],
			'last_run_message'     => (string) $settings['last_run_message'],
			'last_job_had_failure' => ! empty( $settings['last_job_had_failure'] ),
		);
	}
}
