<?php
/**
 * 插件卸载清理。
 *
 * @package wp-ai1-oss-auto-backup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * 卸载当前站点数据（默认保留设置与日志表）。
 *
 * @return void
 */
function wpai1oss_uninstall_site_data() {
	// 默认保留 ai1_oss_auto_backup_settings、日志表与 db_version；仅清除任务锁。
	delete_option( 'ai1_oss_auto_backup_lock' );
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		wpai1oss_uninstall_site_data();
		restore_current_blog();
	}
} else {
	wpai1oss_uninstall_site_data();
}
