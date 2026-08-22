<?php
/**
 * 自动云备份 OSS 客户端封装。
 *
 * @package ai1-oss-auto-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 提供 OSS 连接、上传与对象探测。
 */
class Ai1_Oss_Auto_Backup_Oss_Client {

	/**
	 * 分片大小：10MB。
	 *
	 * @var int
	 */
	const PART_SIZE = 10485760;

	/**
	 * 测试连接。
	 *
	 * @param array<string,mixed> $settings 备份配置。
	 * @return array<string,mixed>
	 */
	public static function test_connection( $settings ) {
		$validation = self::validate_required_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return array(
				'success' => false,
				'message' => $validation->get_error_message(),
			);
		}

		$client = self::build_client( $settings );
		if ( is_wp_error( $client ) ) {
			return array(
				'success' => false,
				'message' => $client->get_error_message(),
			);
		}

		try {
			$bucket = (string) $settings['oss_bucket'];
			$client->getBucketInfo( $bucket );

			return array(
				'success' => true,
				'message' => 'OSS 连接测试成功，已确认可访问该 Bucket。',
			);
		} catch ( Throwable $throwable ) {
			return array(
				'success' => false,
				'message' => 'OSS 连接失败：' . $throwable->getMessage(),
			);
		}
	}

	/**
	 * 校验必填配置。
	 *
	 * @param array<string,mixed> $settings 备份配置。
	 * @return true|\WP_Error
	 */
	public static function validate_required_settings( $settings ) {
		$required_map = array(
			'oss_access_key_id'     => '请填写 AccessKey ID。',
			'oss_access_key_secret' => '请填写 AccessKey Secret。',
			'oss_endpoint'          => '请填写 OSS Endpoint。',
			'oss_bucket'            => '请填写备份专用 Bucket。',
		);

		foreach ( $required_map as $key => $message ) {
			if ( empty( $settings[ $key ] ) ) {
				return new WP_Error( 'ai1_oss_auto_backup_missing_setting', $message );
			}
		}

		return true;
	}

	/**
	 * 拼装 Object Key。
	 *
	 * @param array<string,mixed> $settings 配置。
	 * @param string              $filename 文件名。
	 * @return string
	 */
	public static function build_object_key( $settings, $filename ) {
		$filename = sanitize_file_name( $filename );
		$prefix   = Ai1_Oss_Auto_Backup_Settings::sanitize_prefix( (string) ( $settings['oss_prefix'] ?? '' ) );

		return $prefix . $filename;
	}

	/**
	 * 上传本地文件到 OSS（自动 multipart）。
	 *
	 * @param array<string,mixed> $settings    配置。
	 * @param string              $local_path  本地路径。
	 * @param string              $object_key  Object Key。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function upload_file( $settings, $local_path, $object_key ) {
		$validation = self::validate_required_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! is_file( $local_path ) || ! is_readable( $local_path ) ) {
			return new WP_Error( 'ai1_oss_auto_backup_missing_file', '本地备份文件不存在或不可读。' );
		}

		$client = self::build_client( $settings );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$bucket = (string) $settings['oss_bucket'];

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 );
			}

			$result = $client->multiuploadFile(
				$bucket,
				$object_key,
				$local_path,
				array(
					\OSS\OssClient::OSS_PART_SIZE => self::PART_SIZE,
				)
			);

			$etag = '';
			if ( is_array( $result ) && ! empty( $result['etag'] ) ) {
				$etag = (string) $result['etag'];
			} elseif ( is_object( $result ) && method_exists( $result, 'getETag' ) ) {
				$etag = (string) $result->getETag();
			} else {
				try {
					$meta = $client->getObjectMeta( $bucket, $object_key );
					if ( is_array( $meta ) && ! empty( $meta['etag'] ) ) {
						$etag = (string) $meta['etag'];
					}
				} catch ( Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// 忽略 meta 探测失败，上传本身已成功。
				}
			}

			return array(
				'bucket'     => $bucket,
				'object_key' => $object_key,
				'etag'       => trim( $etag, '"' ),
				'size'       => (int) filesize( $local_path ),
			);
		} catch ( Throwable $throwable ) {
			// C1：失败后尽量 abort 该 key 上未完成的 multipart，避免孤儿分片。
			self::abort_orphan_multiparts( $settings, array( $object_key ) );

			return new WP_Error( 'ai1_oss_auto_backup_upload_failed', '上传失败：' . $throwable->getMessage() );
		}
	}

	/**
	 * 中止未完成的 multipart 上传（C1）。
	 *
	 * @param array<string,mixed>   $settings    配置。
	 * @param array<int,string>|null $object_keys 仅处理这些 key；null 表示当前前缀下全部 `.wpress`。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function abort_orphan_multiparts( $settings, $object_keys = null ) {
		$validation = self::validate_required_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$client = self::build_client( $settings );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$bucket = (string) $settings['oss_bucket'];
		$prefix = Ai1_Oss_Auto_Backup_Settings::sanitize_prefix( (string) ( $settings['oss_prefix'] ?? '' ) );
		$allow  = null;

		if ( is_array( $object_keys ) ) {
			$allow = array();
			foreach ( $object_keys as $key ) {
				$key = ltrim( str_replace( '\\', '/', (string) $key ), '/' );
				if ( '' !== $key ) {
					$allow[ $key ] = true;
				}
			}
			if ( empty( $allow ) ) {
				return array(
					'aborted' => 0,
					'message' => '无待中止的 multipart。',
				);
			}
		}

		$aborted = 0;
		$errors  = 0;

		try {
			$key_marker        = '';
			$upload_id_marker  = '';
			$safety            = 0;

			do {
				$options = array(
					'prefix'     => $prefix,
					'max-uploads'=> 100,
				);
				if ( '' !== $key_marker ) {
					$options['key-marker'] = $key_marker;
				}
				if ( '' !== $upload_id_marker ) {
					$options['upload-id-marker'] = $upload_id_marker;
				}

				$list = $client->listMultipartUploads( $bucket, $options );
				$uploads = ( $list && method_exists( $list, 'getUploads' ) ) ? (array) $list->getUploads() : array();

				foreach ( $uploads as $upload ) {
					$key      = method_exists( $upload, 'getKey' ) ? (string) $upload->getKey() : '';
					$upload_id = method_exists( $upload, 'getUploadId' ) ? (string) $upload->getUploadId() : '';
					if ( '' === $key || '' === $upload_id ) {
						continue;
					}
					if ( ! self::is_managed_object_key( $key ) ) {
						continue;
					}
					if ( is_array( $allow ) && empty( $allow[ $key ] ) ) {
						continue;
					}
					$allowed_key = self::assert_allowed_object_key( $settings, $key, true );
					if ( is_wp_error( $allowed_key ) ) {
						continue;
					}

					try {
						$client->abortMultipartUpload( $bucket, $key, $upload_id );
						$aborted++;
					} catch ( Throwable $ignored ) {
						$errors++;
					}
				}

				$truncated_raw = ( $list && method_exists( $list, 'getIsTruncated' ) ) ? $list->getIsTruncated() : false;
				if ( is_bool( $truncated_raw ) ) {
					$is_truncated = $truncated_raw;
				} else {
					$is_truncated = in_array( strtolower( (string) $truncated_raw ), array( 'true', '1' ), true );
				}

				if ( $is_truncated && method_exists( $list, 'getNextKeyMarker' ) && method_exists( $list, 'getNextUploadIdMarker' ) ) {
					$key_marker       = (string) $list->getNextKeyMarker();
					$upload_id_marker = (string) $list->getNextUploadIdMarker();
				} else {
					$is_truncated = false;
				}

				$safety++;
			} while ( $is_truncated && $safety < 20 );

			return array(
				'aborted' => $aborted,
				'errors'  => $errors,
				'message' => sprintf( '已中止 %d 个未完成 multipart。', $aborted ),
			);
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'ai1_oss_auto_backup_abort_failed', '中止 multipart 失败：' . $throwable->getMessage() );
		}
	}

	/**
	 * 判断对象是否存在。
	 *
	 * @param array<string,mixed> $settings   配置。
	 * @param string              $object_key Object Key。
	 * @return bool|\WP_Error true=存在，false=明确不存在，WP_Error=探测失败。
	 */
	public static function object_exists( $settings, $object_key ) {
		$client = self::build_client( $settings );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$exists = $client->doesObjectExist( (string) $settings['oss_bucket'], $object_key );

			return (bool) $exists;
		} catch ( Throwable $throwable ) {
			$message = $throwable->getMessage();
			if ( false !== stripos( $message, 'NoSuchKey' ) || false !== stripos( $message, '404' ) ) {
				return false;
			}

			return new WP_Error( 'ai1_oss_auto_backup_head_failed', '探测对象失败：' . $message );
		}
	}

	/**
	 * 删除 OSS 对象。
	 *
	 * @param array<string,mixed> $settings   配置。
	 * @param string              $object_key Object Key。
	 * @return true|\WP_Error
	 */
	public static function delete_object( $settings, $object_key ) {
		$allowed = self::assert_allowed_object_key( $settings, $object_key );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$client = self::build_client( $settings );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$client->deleteObject( (string) $settings['oss_bucket'], $object_key );
			return true;
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'ai1_oss_auto_backup_delete_failed', '删除对象失败：' . $throwable->getMessage() );
		}
	}

	/**
	 * 判断 Object Key 是否允许操作（前缀白名单 + .wpress）。
	 *
	 * @param array<string,mixed> $settings   配置。
	 * @param string              $object_key Object Key。
	 * @param bool                $allow_logged 是否允许「成功日志中出现过」的 key（前缀为空时的例外）。
	 * @return true|\WP_Error
	 */
	public static function assert_allowed_object_key( $settings, $object_key, $allow_logged = true ) {
		$key = ltrim( str_replace( '\\', '/', (string) $object_key ), '/' );
		if ( '' === $key || false !== strpos( $key, '..' ) ) {
			return new WP_Error( 'ai1_oss_auto_backup_bad_key', '非法的 Object Key。' );
		}

		if ( ! self::is_managed_object_key( $key ) ) {
			return new WP_Error( 'ai1_oss_auto_backup_bad_key', '仅允许操作 `.wpress` 对象。' );
		}

		$prefix = Ai1_Oss_Auto_Backup_Settings::sanitize_prefix( (string) ( $settings['oss_prefix'] ?? '' ) );
		if ( '' !== $prefix ) {
			if ( 0 !== strpos( $key, $prefix ) ) {
				return new WP_Error( 'ai1_oss_auto_backup_bad_key', 'Object Key 不在当前配置的前缀作用域内。' );
			}
			return true;
		}

		// 前缀为空：默认仅允许桶根文件名；若在成功日志中出现过则放行。
		if ( false === strpos( $key, '/' ) ) {
			return true;
		}

		if ( $allow_logged && Ai1_Oss_Auto_Backup_Logger::has_success_object_key( $key ) ) {
			return true;
		}

		return new WP_Error( 'ai1_oss_auto_backup_bad_key', '前缀为空时仅允许桶根备份对象，或日志中已记录的对象。' );
	}

	/**
	 * 是否为本工具管理的备份对象（`.wpress`）。
	 *
	 * @param string $object_key Object Key。
	 * @return bool
	 */
	public static function is_managed_object_key( $object_key ) {
		$key = ltrim( str_replace( '\\', '/', (string) $object_key ), '/' );

		return (bool) preg_match( '/\.wpress$/i', $key );
	}

	/**
	 * 读取对象大小（字节）；失败返回 0。
	 *
	 * @param array<string,mixed> $settings   配置。
	 * @param string              $object_key Object Key。
	 * @return int
	 */
	public static function get_object_size( $settings, $object_key ) {
		$client = self::build_client( $settings );
		if ( is_wp_error( $client ) ) {
			return 0;
		}

		try {
			$meta = $client->getObjectMeta( (string) $settings['oss_bucket'], $object_key );
			if ( ! is_array( $meta ) ) {
				return 0;
			}

			foreach ( array( 'content-length', 'Content-Length', 'content_length' ) as $header ) {
				if ( isset( $meta[ $header ] ) && is_numeric( $meta[ $header ] ) ) {
					return max( 0, (int) $meta[ $header ] );
				}
			}
		} catch ( Throwable $ignored ) {
			return 0;
		}

		return 0;
	}

	/**
	 * 下载对象到本地路径（流式写入，避免整包进内存）。
	 *
	 * @param array<string,mixed> $settings        配置。
	 * @param string              $object_key      Object Key。
	 * @param string              $local_path      本地目标路径。
	 * @param callable|null       $before_download 开始写入前回调 `function( string $tmp_path, int $expected_bytes )`。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function download_object( $settings, $object_key, $local_path, $before_download = null ) {
		$allowed = self::assert_allowed_object_key( $settings, $object_key );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$client = self::build_client( $settings );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$dir = dirname( $local_path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'ai1_oss_auto_backup_mkdir_failed', '无法创建本地备份目录。' );
		}

		$tmp = $local_path . '.part.' . wp_generate_password( 8, false );
		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 );
			}
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			$expected = self::get_object_size( $settings, $object_key );
			if ( is_callable( $before_download ) ) {
				$before_download( $tmp, $expected );
			}

			$client->getObject(
				(string) $settings['oss_bucket'],
				$object_key,
				array(
					\OSS\OssClient::OSS_FILE_DOWNLOAD => $tmp,
				)
			);

			if ( ! is_file( $tmp ) ) {
				return new WP_Error( 'ai1_oss_auto_backup_download_empty', '下载完成但未生成临时文件。' );
			}

			if ( file_exists( $local_path ) && ! unlink( $local_path ) ) {
				@unlink( $tmp );
				return new WP_Error( 'ai1_oss_auto_backup_replace_failed', '无法覆盖已有本地文件。' );
			}

			if ( ! rename( $tmp, $local_path ) ) {
				@unlink( $tmp );
				return new WP_Error( 'ai1_oss_auto_backup_rename_failed', '下载临时文件落盘失败。' );
			}

			return array(
				'local_path' => $local_path,
				'filename'   => wp_basename( $local_path ),
				'size'       => (int) filesize( $local_path ),
				'object_key' => $object_key,
			);
		} catch ( Throwable $throwable ) {
			if ( is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			return new WP_Error( 'ai1_oss_auto_backup_download_failed', '拉取失败：' . $throwable->getMessage() );
		}
	}

	/**
	 * 列举前缀作用域内的 `.wpress` 备份对象。
	 *
	 * @param array<string,mixed> $settings 配置。
	 * @param int                 $max_keys 最多返回条数。
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public static function list_wpress_objects( $settings, $max_keys = 100 ) {
		$client = self::build_client( $settings );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$prefix   = Ai1_Oss_Auto_Backup_Settings::sanitize_prefix( (string) ( $settings['oss_prefix'] ?? '' ) );
		$max_keys = max( 1, min( 200, (int) $max_keys ) );

		try {
			$list = $client->listObjects(
				(string) $settings['oss_bucket'],
				array(
					\OSS\OssClient::OSS_PREFIX   => $prefix,
					\OSS\OssClient::OSS_DELIMITER => '',
					\OSS\OssClient::OSS_MAX_KEYS  => $max_keys,
				)
			);

			$items = array();
			$object_list = method_exists( $list, 'getObjectList' ) ? $list->getObjectList() : array();
			foreach ( (array) $object_list as $object_info ) {
				$key = method_exists( $object_info, 'getKey' ) ? (string) $object_info->getKey() : '';
				if ( '' === $key || ! self::is_managed_object_key( $key ) ) {
					continue;
				}
				$allowed = self::assert_allowed_object_key( $settings, $key, true );
				if ( is_wp_error( $allowed ) ) {
					continue;
				}

				$filename = wp_basename( $key );
				$size     = method_exists( $object_info, 'getSize' ) ? (int) $object_info->getSize() : 0;
				$items[]  = array(
					'object_key'    => $key,
					'filename'      => $filename,
					'kind'          => 'wpress',
					'kind_label'    => '站点包',
					'size'          => $size,
					'size_label'    => size_format( $size ),
					'last_modified' => method_exists( $object_info, 'getLastModified' ) ? (string) $object_info->getLastModified() : '',
					'local_exists'  => Ai1_Oss_Auto_Backup_Ai1wm_Source::local_file_exists( $filename ),
				);
			}

			usort(
				$items,
				static function ( $left, $right ) {
					return strcasecmp( (string) $left['filename'], (string) $right['filename'] );
				}
			);

			return $items;
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'ai1_oss_auto_backup_list_failed', '列举 OSS 对象失败：' . $throwable->getMessage() );
		}
	}

	/**
	 * 构建 OSS 客户端。
	 *
	 * @param array<string,mixed> $settings 备份配置。
	 * @return object|\WP_Error
	 */
	public static function build_client( $settings ) {
		$autoload = WP_CONTENT_DIR . '/plugins/oss-aliyun/sdk/vendor/autoload.php';
		if ( ! class_exists( '\OSS\OssClient' ) ) {
			if ( file_exists( $autoload ) ) {
				require_once $autoload;
			}
		}

		if ( ! class_exists( '\OSS\OssClient' ) || ! class_exists( '\OSS\Credentials\StaticCredentialsProvider' ) ) {
			return new WP_Error( 'ai1_oss_auto_backup_missing_sdk', '未找到阿里云 OSS SDK，请确认 `oss-aliyun` 插件及其依赖可用。' );
		}

		try {
			$provider = new \OSS\Credentials\StaticCredentialsProvider(
				(string) $settings['oss_access_key_id'],
				(string) $settings['oss_access_key_secret']
			);

			$endpoint = self::normalize_endpoint_url( (string) $settings['oss_endpoint'], ! empty( $settings['oss_is_internal'] ) );
			$region   = self::infer_region_from_endpoint( $endpoint );
			$config   = array(
				'provider'         => $provider,
				'endpoint'         => $endpoint,
				'signatureVersion' => \OSS\OssClient::OSS_SIGNATURE_VERSION_V4,
				'region'           => $region,
			);

			return new \OSS\OssClient( $config );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'ai1_oss_auto_backup_build_client_failed', '初始化 OSS 客户端失败：' . $throwable->getMessage() );
		}
	}

	/**
	 * 标准化 endpoint URL。
	 *
	 * @param string $endpoint    Endpoint 主机或 URL。
	 * @param bool   $is_internal 是否内网。
	 * @return string
	 */
	private static function normalize_endpoint_url( $endpoint, $is_internal = false ) {
		$endpoint = Ai1_Oss_Auto_Backup_Settings::sanitize_endpoint( $endpoint );

		if ( $is_internal && false === strpos( $endpoint, '-internal.' ) ) {
			$endpoint = preg_replace( '/\.aliyuncs\.com$/', '-internal.aliyuncs.com', $endpoint );
		}

		return 'https://' . $endpoint;
	}

	/**
	 * 从 endpoint 推断 region。
	 *
	 * @param string $endpoint Endpoint URL。
	 * @return string
	 */
	private static function infer_region_from_endpoint( $endpoint ) {
		$host = (string) wp_parse_url( $endpoint, PHP_URL_HOST );

		if ( preg_match( '/^oss-([a-z0-9-]+?)(?:-internal)?\.aliyuncs\.com$/', $host, $matches ) ) {
			return $matches[1];
		}

		return 'cn-hangzhou';
	}
}
