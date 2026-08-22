<?php
/**
 * AI1WM 备份源扫描与完整性检查。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 定位并筛选 All-in-One 备份包。
 */
class Ai1_Oss_Auto_Backup_Ai1wm_Source {

	/**
	 * 完整性检查：两次 filesize 间隔（微秒）。
	 *
	 * @var int
	 */
	const STABILITY_SLEEP_US = 800000;

	/**
	 * mtime 距今超过该秒数也视为稳定。
	 *
	 * @var int
	 */
	const STABILITY_MTIME_SECONDS = 60;

	/**
	 * 返回 AI1WM 备份目录。
	 *
	 * @return string
	 */
	public static function get_backups_path() {
		if ( defined( 'AI1WM_BACKUPS_PATH' ) ) {
			return AI1WM_BACKUPS_PATH;
		}

		return WP_CONTENT_DIR . '/ai1wm-backups';
	}

	/**
	 * 列出目录内全部 `.wpress` 文件（mtime 升序）。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_wpress_files() {
		$dir = self::get_backups_path();
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( trailingslashit( $dir ) . '*.wpress' );
		if ( empty( $files ) || ! is_array( $files ) ) {
			return array();
		}

		$items = array();
		foreach ( $files as $path ) {
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			$mtime = (int) filemtime( $path );
			$size  = (int) filesize( $path );

			$items[] = array(
				'path'      => $path,
				'filename'  => wp_basename( $path ),
				'size'      => $size,
				'mtime'     => $mtime,
				'mtime_mysql' => date_i18n( 'Y-m-d H:i:s', $mtime ),
			);
		}

		usort(
			$items,
			static function ( $left, $right ) {
				if ( $left['mtime'] === $right['mtime'] ) {
					return strcmp( $left['filename'], $right['filename'] );
				}

				return $left['mtime'] <=> $right['mtime'];
			}
		);

		return $items;
	}

	/**
	 * 判断文件是否已写完（避免半包）。
	 *
	 * @param string $path 文件路径。
	 * @return bool
	 */
	public static function is_file_stable( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$size = (int) filesize( $path );
		if ( $size <= 0 ) {
			return false;
		}

		$mtime = (int) filemtime( $path );
		if ( ( time() - $mtime ) >= self::STABILITY_MTIME_SECONDS ) {
			return true;
		}

		$size1 = $size;
		usleep( self::STABILITY_SLEEP_US );
		clearstatcache( true, $path );
		$size2 = (int) filesize( $path );

		return $size2 > 0 && $size1 === $size2;
	}

	/**
	 * 统计仍在写入中的 `.wpress`（半包，且尚未有有效 success）。
	 *
	 * @return int
	 */
	public static function count_unstable_wpress() {
		$count = 0;

		foreach ( self::list_wpress_files() as $file ) {
			if ( Ai1_Oss_Auto_Backup_Logger::has_active_success( $file['filename'] ) ) {
				continue;
			}

			if ( ! self::is_file_stable( $file['path'] ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * 是否还有待传工作（完整待传或半包待稳定）。
	 *
	 * @return bool
	 */
	public static function has_upload_work_remaining() {
		if ( ! empty( self::get_pending_uploads( null ) ) ) {
			return true;
		}

		return self::count_unstable_wpress() > 0;
	}

	/**
	 * 返回待上传包列表（升序，排除已有效 success）。
	 *
	 * @param int|null $limit 最多返回条数；null 表示不截断。
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_pending_uploads( $limit = null ) {
		$pending = array();

		foreach ( self::list_wpress_files() as $file ) {
			if ( Ai1_Oss_Auto_Backup_Logger::has_active_success( $file['filename'] ) ) {
				continue;
			}

			if ( ! self::is_file_stable( $file['path'] ) ) {
				$file['skip_reason'] = 'incomplete';
				continue;
			}

			$pending[] = $file;
		}

		if ( null !== $limit ) {
			$pending = array_slice( $pending, 0, max( 0, (int) $limit ) );
		}

		return $pending;
	}

	/**
	 * 统计待传与目录概况。
	 *
	 * @return array<string,mixed>
	 */
	public static function get_directory_stats() {
		$all      = self::list_wpress_files();
		$pending  = self::get_pending_uploads( null );
		$unstable = self::count_unstable_wpress();

		return array(
			'path'            => self::get_backups_path(),
			'total_wpress'    => count( $all ),
			'pending_count'   => count( $pending ),
			'unstable_count'  => $unstable,
			'pending'         => $pending,
		);
	}

	/**
	 * 按文件名查找本地完整包。
	 *
	 * @param string $filename 文件名。
	 * @return array<string,mixed>|null
	 */
	public static function find_local_file( $filename ) {
		$filename = sanitize_file_name( $filename );
		$path     = trailingslashit( self::get_backups_path() ) . $filename;

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		if ( ! self::is_file_stable( $path ) ) {
			return null;
		}

		$mtime = (int) filemtime( $path );

		return array(
			'path'        => $path,
			'filename'    => $filename,
			'size'        => (int) filesize( $path ),
			'mtime'       => $mtime,
			'mtime_mysql' => date_i18n( 'Y-m-d H:i:s', $mtime ),
		);
	}

	/**
	 * 本地是否存在同名文件（不要求稳定，仅用于 UI 按钮）。
	 *
	 * @param string $filename 文件名。
	 * @return bool
	 */
	public static function local_file_exists( $filename ) {
		$filename = sanitize_file_name( $filename );
		$path     = trailingslashit( self::get_backups_path() ) . $filename;

		return is_file( $path ) && is_readable( $path );
	}
}
