<?php
/**
 * 自动云备份后台页面模板。
 *
 * @var array<string,mixed>          $settings
 * @var string                       $nonce
 * @var string                       $ajax_url
 * @var string                       $timezones_string
 * @var array<string,string>         $cron_examples
 * @var array<int,string>            $weekday_options
 * @var array<string,string>         $schedule_mode_options
 * @var array<string,mixed>          $job_status
 * @var array<string,mixed>          $logs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pending_count  = isset( $job_status['directory']['pending_count'] ) ? (int) $job_status['directory']['pending_count'] : 0;
$total_wpress   = isset( $job_status['directory']['total_wpress'] ) ? (int) $job_status['directory']['total_wpress'] : 0;
$unstable_count = isset( $job_status['directory']['unstable_count'] ) ? (int) $job_status['directory']['unstable_count'] : 0;
$is_running     = ! empty( $job_status['running'] );
$lock_message   = $is_running && ! empty( $job_status['lock']['message'] ) ? (string) $job_status['lock']['message'] : '';
$schedule_display = Ai1_Oss_Auto_Backup_Cron::get_schedule_display( $settings );
$schedule_summary = Ai1_Oss_Auto_Backup_Cron::format_schedule_summary( $schedule_display );
$schedule_mode    = (string) ( $settings['schedule_mode'] ?? 'weekly' );
$show_cron_weekday  = 'weekly' === $schedule_mode;
$show_cron_monthday = 'monthly' === $schedule_mode;
?>
<div class="wrap ai1-oss-auto-backup-wrap">
	<h1>AI1 OSS 自动备份</h1>
	<p style="max-width: 60rem; color: #50575e;">
		扫描 <code>ai1wm-backups</code> 中的完整 <code>.wpress</code> 包并上传到独立 OSS Bucket。
		请将本工具执行时间安排在 All-in-One 导出完成之后。上传日志固定保留半年（180 天，A3 规则），到期自动清理；仍属滚动/月度/地板范围的成功记录不会被删。
	</p>

	<div id="ai1-oss-auto-backup-message" style="display:none;"></div>

	<nav class="nav-tab-wrapper wp-clearfix" aria-label="自动云备份设置分区">
		<button type="button" class="nav-tab nav-tab-active" data-tab="operations">上传与日志</button>
		<button type="button" class="nav-tab" data-tab="schedule">调度与保留</button>
		<button type="button" class="nav-tab" data-tab="connection">连接与运维</button>
	</nav>

	<form id="ai1-oss-auto-backup-form" onsubmit="return false;">
		<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />

	<div class="ai1-oss-tab-panel" data-tab-panel="operations">
		<div id="ai1-oss-pull-banner" class="ai1-oss-pull-banner" hidden role="status" aria-live="polite">
			<p id="ai1-oss-pull-banner-text">正在从 OSS 拉取…请勿关闭或刷新本页。</p>
			<div class="ai1-oss-pull-bar" aria-hidden="true"><span id="ai1-oss-pull-bar-fill"></span></div>
		</div>
		<h2>手动上传</h2>
	<p>
		目录 <code><?php echo esc_html( (string) $job_status['directory']['path'] ); ?></code>：
		共 <?php echo esc_html( (string) $total_wpress ); ?> 个 `.wpress`，
		待上传 <strong id="ai1-oss-pending-count"><?php echo esc_html( (string) $pending_count ); ?></strong> 个<span id="ai1-oss-unstable-wrap"<?php echo $unstable_count > 0 ? '' : ' style="display:none;"'; ?>>，另有 <strong id="ai1-oss-unstable-count"><?php echo esc_html( (string) $unstable_count ); ?></strong> 个仍在写入（半包，不会标记本周期收工）</span>。
	</p>
	<p id="ai1-oss-job-progress" style="<?php echo $is_running ? '' : 'display:none;'; ?>color:#646970;">
		<?php echo esc_html( $lock_message ? $lock_message : '任务进行中…' ); ?>
	</p>
	<p>
		<button type="button" class="button button-primary" id="ai1-oss-run-now" <?php disabled( $is_running ); ?>>立即执行上传</button>
		<button type="button" class="button" id="ai1-oss-refresh-status">刷新状态</button>
		<button type="button" class="button" id="ai1-oss-clear-lock">清除任务锁</button>
	</p>
	<p class="description">上次运行：<code id="ai1-oss-last-run"><?php echo esc_html( (string) ( $settings['last_run_status'] ?: '尚未执行' ) ); ?><?php echo ! empty( $settings['last_run_at'] ) ? ' @ ' . esc_html( (string) $settings['last_run_at'] ) : ''; ?></code>
		— <span id="ai1-oss-last-message"><?php echo esc_html( (string) $settings['last_run_message'] ); ?></span>
	</p>
	<p class="description" id="ai1-oss-schedule-summary"><?php echo esc_html( $schedule_summary ); ?></p>

	<hr />

	<h2>上传日志</h2>
	<p class="description">上传日志固定保留半年（180 天）。成功记录可「拉取到本地」。本地已有 <code>.wpress</code> 时可「删除本地」（不删 OSS）。本地仍有同名完整包时可「重新上传」。拉取默认不覆盖本地同名文件（D1）。</p>
	<p>
		<button type="button" class="button" id="ai1-oss-refresh-logs">刷新日志</button>
		<button type="button" class="button" id="ai1-oss-verify-remote">校验远端对象</button>
	</p>
	<table class="widefat striped" id="ai1-oss-logs-table">
		<thead>
			<tr>
				<th>时间</th>
				<th>触发</th>
				<th>文件</th>
				<th>大小</th>
				<th>状态</th>
				<th>OSS Key</th>
				<th>耗时</th>
				<th>操作</th>
			</tr>
		</thead>
		<tbody id="ai1-oss-logs-body">
			<?php if ( empty( $logs['items'] ) ) : ?>
				<tr class="ai1-oss-empty"><td colspan="8">暂无日志</td></tr>
			<?php else : ?>
				<?php foreach ( $logs['items'] as $item ) : ?>
					<tr data-log-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
						<td><?php echo esc_html( (string) $item['created_at'] ); ?></td>
						<td><?php echo esc_html( (string) $item['trigger_type'] ); ?></td>
						<td><code><?php echo esc_html( (string) $item['local_filename'] ); ?></code></td>
						<td><?php echo esc_html( (string) $item['file_size_label'] ); ?></td>
						<td>
							<?php echo esc_html( (string) $item['status'] ); ?>
							<?php if ( ! empty( $item['is_monthly_anchor'] ) ) : ?>
								<span class="dashicons dashicons-calendar-alt" title="月度锚点"></span>
							<?php endif; ?>
							<?php if ( ! empty( $item['remote_missing'] ) ) : ?>
								<span style="color:#b32d2e;">缺失</span>
							<?php endif; ?>
							<?php if ( ! empty( $item['error_message'] ) ) : ?>
								<br /><small style="color:#b32d2e;"><?php echo esc_html( (string) $item['error_message'] ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $item['oss_object_key'] ) ) : ?>
								<code class="ai1-oss-key"><?php echo esc_html( (string) $item['oss_object_key'] ); ?></code>
								<button type="button" class="button-link ai1-oss-copy-key" data-key="<?php echo esc_attr( (string) $item['oss_object_key'] ); ?>">复制</button>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo null !== $item['duration_ms'] ? esc_html( (string) $item['duration_ms'] ) . ' ms' : '—'; ?></td>
						<td>
							<?php
							$log_actions = array();
							if ( ! empty( $item['can_pull'] ) ) {
								$log_actions[] = sprintf(
									'<button type="button" class="button button-small ai1-oss-pull" data-log-id="%s">拉取到本地</button>',
									esc_attr( (string) $item['id'] )
								);
							}
							if ( ! empty( $item['can_delete_local'] ) ) {
								$log_actions[] = sprintf(
									'<button type="button" class="button button-small ai1-oss-delete-local" data-log-id="%s">删除本地</button>',
									esc_attr( (string) $item['id'] )
								);
							}
							if ( ! empty( $item['can_reupload'] ) ) {
								$log_actions[] = sprintf(
									'<button type="button" class="button button-small ai1-oss-reupload" data-log-id="%s">重新上传</button>',
									esc_attr( (string) $item['id'] )
								);
							}
							if ( empty( $log_actions ) ) {
								echo '—';
							} else {
								echo '<div class="ai1-oss-row-actions">' . implode( '', $log_actions ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	<p id="ai1-oss-logs-meta" class="description">共 <?php echo esc_html( (string) $logs['total'] ); ?> 条</p>

	<hr />

	<h2>OSS 对象列表</h2>
	<p class="description">列举当前前缀作用域内的 <code>.wpress</code>。可从列表拉取到 All-in-One 备份目录。「删除本地」只清磁盘；「删除 OSS」只删线上对象，不会动本地文件。大文件下载可能需数分钟，本页顶部会显示进度。</p>
	<p>
		<button type="button" class="button" id="ai1-oss-list-objects">获取线上OSS 对象最新列表</button>
	</p>
	<table class="widefat striped" id="ai1-oss-objects-table">
		<thead>
			<tr>
				<th>类型</th>
				<th>文件名</th>
				<th>大小</th>
				<th>最后修改</th>
				<th>Object Key</th>
				<th>本地</th>
				<th>操作</th>
			</tr>
		</thead>
		<tbody id="ai1-oss-objects-body">
			<tr class="ai1-oss-empty"><td colspan="7">点击「获取线上OSS 对象最新列表」加载</td></tr>
		</tbody>
	</table>
	<p id="ai1-oss-objects-meta" class="description"></p>
	</div>

		<div class="ai1-oss-tab-panel" data-tab-panel="schedule" hidden style="max-width: 72rem;">
			<h2>调度与保留预设</h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="ai1-schedule-mode">调度模式</label></th>
					<td>
						<select id="ai1-schedule-mode" name="schedule_mode">
							<?php foreach ( $schedule_mode_options as $mode_key => $mode_label ) : ?>
								<option value="<?php echo esc_attr( $mode_key ); ?>" <?php selected( (string) $settings['schedule_mode'], $mode_key ); ?>><?php echo esc_html( $mode_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">时区使用站点配置：<code><?php echo esc_html( $timezones_string ); ?></code>。当前窗口：<strong id="ai1-oss-schedule-window"><?php echo esc_html( $schedule_display['description'] ); ?></strong></p>
					</td>
				</tr>
				<tr id="ai1-oss-row-cron-weekday"<?php echo $show_cron_weekday ? '' : ' style="display:none;"'; ?>>
					<th scope="row"><label for="ai1-cron-weekday">每周执行日</label></th>
					<td>
						<select id="ai1-cron-weekday" name="cron_weekday">
							<?php foreach ( $weekday_options as $weekday_key => $weekday_label ) : ?>
								<option value="<?php echo esc_attr( (string) $weekday_key ); ?>" <?php selected( (int) $settings['cron_weekday'], $weekday_key ); ?>><?php echo esc_html( $weekday_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr id="ai1-oss-row-cron-monthday"<?php echo $show_cron_monthday ? '' : ' style="display:none;"'; ?>>
					<th scope="row"><label for="ai1-cron-monthday">每月执行日</label></th>
					<td>
						<input id="ai1-cron-monthday" type="number" min="1" max="31" name="cron_monthday" value="<?php echo esc_attr( (string) $settings['cron_monthday'] ); ?>" class="small-text" />
						<p class="description">按自然月计算，可选 1–31。若当月没有该日（如 2 月 31 日），则在<strong>当月最后一天</strong>执行。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai1-cron-time">执行时间</label></th>
					<td>
						<input id="ai1-cron-time" type="time" name="cron_time" value="<?php echo esc_attr( (string) $settings['cron_time'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai1-max-files-per-job">单次 Job 上限</label></th>
					<td>
						<input id="ai1-max-files-per-job" type="number" min="1" max="10" name="max_files_per_job" value="<?php echo esc_attr( (string) $settings['max_files_per_job'] ); ?>" class="small-text" />
						<p class="description">本轮最多上传几个包；剩余待传会由定时 tick 或再次手动触发续传。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai1-keep-count">OSS 滚动保留数</label></th>
					<td>
						<input id="ai1-keep-count" type="number" min="1" name="keep_count" value="<?php echo esc_attr( (string) $settings['keep_count'] ); ?>" class="small-text" />
						<p class="description">按<strong>一份 <code>.wpress</code></strong>计数（对应一条上传日志）。兼作总地板。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">清理开关</th>
					<td>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="delete_local_after_upload" value="1" <?php checked( ! empty( $settings['delete_local_after_upload'] ) ); ?> /> 上传成功后删除本地 `.wpress`</label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="prune_oss_excess" value="1" <?php checked( ! empty( $settings['prune_oss_excess'] ) ); ?> /> 清理 OSS 超额滚动包（策略 A + 地板）</label>
						<label style="display:block;"><input type="checkbox" name="prune_local_excess" value="1" <?php checked( ! empty( $settings['prune_local_excess'] ) ); ?> /> 清理本地超额 `.wpress`（仅按保留数，无月度）</label>
					</td>
				</tr>
				<tr>
					<th scope="row">月度保留</th>
					<td>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="monthly_retain_enabled" value="1" <?php checked( ! empty( $settings['monthly_retain_enabled'] ) ); ?> /> 启用月度锚点：按备份文件名 YYYYMMDD 定月，每月保留该月最后一个包（不计入滚动数，受地板保护）</label>
						<input type="number" min="1" max="120" name="monthly_keep_months" value="<?php echo esc_attr( (string) $settings['monthly_keep_months'] ); ?>" class="small-text" />
						<span>个月</span>
					</td>
				</tr>
			</tbody>
		</table>

			<p>
				<button type="button" class="button button-primary ai1-oss-save-settings">保存设置</button>
			</p>
		</div>

		<div class="ai1-oss-tab-panel" data-tab-panel="connection" hidden>
			<h2>OSS 连接配置</h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">启用自动云备份</th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							开启后才会参与后续定时与上传流程
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai1-oss-access-key-id">AccessKey ID</label></th>
					<td><input id="ai1-oss-access-key-id" type="text" name="oss_access_key_id" value="<?php echo esc_attr( (string) $settings['oss_access_key_id'] ); ?>" class="regular-text" autocomplete="off" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ai1-oss-access-key-secret">AccessKey Secret</label></th>
					<td>
						<input id="ai1-oss-access-key-secret" type="password" name="oss_access_key_secret" value="" class="regular-text" autocomplete="new-password" />
						<p class="description">
							<?php if ( ! empty( $settings['oss_access_key_mask'] ) ) : ?>
								已保存：<code><?php echo esc_html( (string) $settings['oss_access_key_mask'] ); ?></code>。留空表示保持不变。
							<?php else : ?>
								尚未保存 Secret。
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai1-oss-endpoint">Endpoint</label></th>
					<td>
						<input id="ai1-oss-endpoint" type="text" name="oss_endpoint" value="<?php echo esc_attr( (string) $settings['oss_endpoint'] ); ?>" class="regular-text" placeholder="oss-cn-hangzhou.aliyuncs.com" />
						<p class="description">请输入 Bucket 对应的 Endpoint 主机名，不需要额外带协议。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai1-oss-bucket">备份 Bucket</label></th>
					<td>
						<input id="ai1-oss-bucket" type="text" name="oss_bucket" value="<?php echo esc_attr( (string) $settings['oss_bucket'] ); ?>" class="regular-text" />
						<p class="description">必须使用备份专用独立 Bucket，不与媒体资源桶混用。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai1-oss-prefix">Object 路径前缀</label></th>
					<td>
						<input id="ai1-oss-prefix" type="text" name="oss_prefix" value="<?php echo esc_attr( (string) $settings['oss_prefix'] ); ?>" class="regular-text" placeholder="可选，例如 backups/" />
						<p class="description">可留空。留空时对象直接上传到桶根目录；填写后会自动规范成以 <code>/</code> 结尾的路径前缀。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">内网 Endpoint</th>
					<td>
						<label>
							<input type="checkbox" name="oss_is_internal" value="1" <?php checked( ! empty( $settings['oss_is_internal'] ) ); ?> />
							使用内网 Endpoint（自动追加 <code>-internal</code>）
						</label>
					</td>
				</tr>
			</tbody>
		</table>

			<h2>生产环境调用说明</h2>
			<p>默认由 WordPress 的 WP-Cron 定义任务；生产环境建议再用系统 Cron 每 5 分钟唤醒一次 <code>wp-cron.php</code>。</p>
			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div class="notice notice-warning inline"><p><strong>当前已开启 <code>DISABLE_WP_CRON</code>。</strong> 请务必在服务器配置系统 Cron，否则定时任务不会自动运行。</p></div>
			<?php endif; ?>
			<ul>
				<li><code><?php echo esc_html( $cron_examples['curl'] ); ?></code></li>
				<li><code><?php echo esc_html( $cron_examples['wget'] ); ?></code></li>
				<li><code><?php echo esc_html( $cron_examples['wpcli'] ); ?></code></li>
			</ul>

			<h3>运维边界说明</h3>
			<ul style="max-width: 60rem;">
				<li><strong>半包：</strong>写入中的 <code>.wpress</code> 不会上传；本周期不会因此标记「收工」，后续 hourly tick 会继续重试。</li>
				<li><strong>锁 / C1：</strong>锁 TTL 6 小时；过期可抢；超时或清锁会将 <code>running</code> 收场为失败，并尽量 abort 未完成的 multipart。</li>
				<li><strong>地板：</strong>OSS 清理后，远端仍在的备份套数不会低于「滚动保留数」；低频备份也不会被删光。</li>
				<li><strong>拉取：</strong>默认不覆盖本地同名文件；覆盖需二次确认。远端缺失只打标，不进全站红条。</li>
			</ul>

			<p>
				<button type="button" class="button button-primary ai1-oss-save-settings">保存设置</button>
				<button type="button" class="button" id="ai1-oss-test-connection">测试 OSS 连接</button>
			</p>
		</div>
	</form>

	<style>
		.ai1-oss-auto-backup-wrap .ai1-oss-tab-panel { margin-top: 12px; }
		.ai1-oss-auto-backup-wrap .ai1-oss-tab-panel[hidden] { display: none; }
		.ai1-oss-auto-backup-wrap .nav-tab { cursor: pointer; }
		.ai1-oss-auto-backup-wrap #ai1-oss-logs-table,
		.ai1-oss-auto-backup-wrap #ai1-oss-objects-table {
			width: 100%;
		}
		.ai1-oss-auto-backup-wrap #ai1-oss-objects-table .ai1-oss-row-actions,
		.ai1-oss-auto-backup-wrap #ai1-oss-logs-table .ai1-oss-row-actions {
			display: flex;
			flex-direction: row;
			flex-wrap: wrap;
			gap: 16px;
			align-items: center;
		}
		.ai1-oss-pull-banner {
			position: sticky;
			top: 32px;
			z-index: 20;
			margin: 0 0 16px;
			padding: 12px 16px;
			background: #fff;
			border: 1px solid #c3c4c7;
			border-left: 4px solid #2271b1;
			box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
		}
		.ai1-oss-pull-banner.is-success { border-left-color: #00a32a; }
		.ai1-oss-pull-banner.is-error { border-left-color: #d63638; }
		.ai1-oss-pull-banner p { margin: 0; }
		.ai1-oss-pull-bar {
			height: 10px;
			margin-top: 10px;
			background: #dcdcde;
			border-radius: 999px;
			overflow: hidden;
		}
		.ai1-oss-pull-bar > span {
			display: block;
			height: 100%;
			width: 0;
			background: #2271b1;
			transition: width 0.35s ease;
		}
		.ai1-oss-pull-banner.is-indeterminate .ai1-oss-pull-bar > span {
			width: 35%;
			animation: ai1-oss-pull-indeterminate 1.2s ease-in-out infinite;
		}
		@keyframes ai1-oss-pull-indeterminate {
			0% { transform: translateX(-120%); }
			100% { transform: translateX(320%); }
		}
	</style>

	<script>
	(function () {
		var form = document.getElementById('ai1-oss-auto-backup-form');
		var message = document.getElementById('ai1-oss-auto-backup-message');
		var saveButtons = document.querySelectorAll('.ai1-oss-save-settings');
		var testButton = document.getElementById('ai1-oss-test-connection');
		var runButton = document.getElementById('ai1-oss-run-now');
		var refreshStatusButton = document.getElementById('ai1-oss-refresh-status');
		var clearLockButton = document.getElementById('ai1-oss-clear-lock');
		var refreshLogsButton = document.getElementById('ai1-oss-refresh-logs');
		var verifyRemoteButton = document.getElementById('ai1-oss-verify-remote');
		var listObjectsButton = document.getElementById('ai1-oss-list-objects');
		var tabButtons = document.querySelectorAll('.ai1-oss-auto-backup-wrap .nav-tab');
		var tabPanels = document.querySelectorAll('.ai1-oss-tab-panel');
		var progressEl = document.getElementById('ai1-oss-job-progress');
		var pendingEl = document.getElementById('ai1-oss-pending-count');
		var unstableWrap = document.getElementById('ai1-oss-unstable-wrap');
		var unstableEl = document.getElementById('ai1-oss-unstable-count');
		var lastRunEl = document.getElementById('ai1-oss-last-run');
		var lastMsgEl = document.getElementById('ai1-oss-last-message');
		var scheduleWindowEl = document.getElementById('ai1-oss-schedule-window');
		var scheduleSummaryEl = document.getElementById('ai1-oss-schedule-summary');
		var logsBody = document.getElementById('ai1-oss-logs-body');
		var logsMeta = document.getElementById('ai1-oss-logs-meta');
		var objectsBody = document.getElementById('ai1-oss-objects-body');
		var objectsMeta = document.getElementById('ai1-oss-objects-meta');
		var scheduleModeSelect = document.getElementById('ai1-schedule-mode');
		var cronWeekdayRow = document.getElementById('ai1-oss-row-cron-weekday');
		var cronMonthdayRow = document.getElementById('ai1-oss-row-cron-monthday');
		var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var tabStorageKey = 'ai1_oss_auto_backup_admin_tab';
		var pullBanner = document.getElementById('ai1-oss-pull-banner');
		var pullBannerText = document.getElementById('ai1-oss-pull-banner-text');
		var pullBarFill = document.getElementById('ai1-oss-pull-bar-fill');
		var pullPollTimer = null;
		var pullInFlight = false;
		var pullActiveButton = null;
		var pullActiveButtonLabel = '';

		/**
		 * 切换 Tab。
		 *
		 * @param {string} tabName Tab 标识。
		 */
		function activateTab(tabName) {
			tabButtons.forEach(function (btn) {
				var active = btn.getAttribute('data-tab') === tabName;
				btn.classList.toggle('nav-tab-active', active);
			});
			tabPanels.forEach(function (panel) {
				var show = panel.getAttribute('data-tab-panel') === tabName;
				if (show) {
					panel.removeAttribute('hidden');
				} else {
					panel.setAttribute('hidden', 'hidden');
				}
			});
			try {
				localStorage.setItem(tabStorageKey, tabName);
			} catch (ignored) {}
		}

		tabButtons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				activateTab(btn.getAttribute('data-tab') || 'operations');
			});
		});

		try {
			var savedTab = localStorage.getItem(tabStorageKey);
			if (savedTab && document.querySelector('[data-tab-panel="' + savedTab + '"]')) {
				activateTab(savedTab);
			}
		} catch (ignored) {}

		/**
		 * 按调度模式显示/隐藏「每周执行日」「每月执行日」。
		 */
		function syncScheduleFields() {
			if (!scheduleModeSelect) {
				return;
			}
			var mode = scheduleModeSelect.value || 'weekly';
			if (cronWeekdayRow) {
				cronWeekdayRow.style.display = mode === 'weekly' ? '' : 'none';
			}
			if (cronMonthdayRow) {
				cronMonthdayRow.style.display = mode === 'monthly' ? '' : 'none';
			}
		}

		if (scheduleModeSelect) {
			scheduleModeSelect.addEventListener('change', syncScheduleFields);
			syncScheduleFields();
		}

		/**
		 * 渲染调度摘要（保存设置或刷新状态后同步更新）。
		 *
		 * @param {Object} schedule 调度数据。
		 */
		function renderScheduleInfo(schedule) {
			if (!schedule) {
				return;
			}
			if (scheduleWindowEl) {
				scheduleWindowEl.textContent = schedule.description || '—';
			}
			if (scheduleSummaryEl) {
				var parts = [
					'调度：' + (schedule.description || '—'),
					'WP-Cron 下次 tick：' + (schedule.next_tick_label || '—')
				];
				var period = schedule.period_key || '—';
				if (schedule.period_done) {
					period += '（本周期已收工）';
				}
				parts.push('周期键：' + period);
				scheduleSummaryEl.textContent = parts.join('；') + '；';
			}
		}

		/**
		 * 渲染消息。
		 *
		 * @param {string} text 消息内容。
		 * @param {boolean} success 是否成功。
		 */
		function showMessage(text, success) {
			message.className = success ? 'notice notice-success inline' : 'notice notice-error inline';
			message.innerHTML = '<p></p>';
			message.querySelector('p').textContent = text;
			message.style.display = 'block';
		}

		/**
		 * 提交 AJAX。
		 *
		 * @param {string} actionName WordPress AJAX action。
		 * @param {Object=} extra 额外字段。
		 * @param {boolean=} useForm 是否带上表单字段。
		 * @return {Promise<Object>}
		 */
		function submitAction(actionName, extra, useForm) {
			var formData;
			if (useForm) {
				formData = new FormData(form);
			} else {
				formData = new FormData();
				formData.append('nonce', nonce);
			}
			formData.append('action', actionName);
			if (extra) {
				Object.keys(extra).forEach(function (key) {
					formData.append(key, extra[key]);
				});
			}

			return fetch(ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			}).then(function (response) {
				return response.text().then(function (text) {
					var json = null;
					try {
						json = text ? JSON.parse(text) : null;
					} catch (parseError) {
						var snippet = (text || '').replace(/\s+/g, ' ').slice(0, 120);
						var hint = '服务器没有返回 JSON（可能是 PHP 超时、致命错误、网关超时或登录失效）。';
						if (/critical error|致命错误/i.test(text || '')) {
							hint = 'WordPress 发生致命错误，请查看 wp-content/debug.log。';
						} else if (/504|Gateway Time-out|timed out/i.test(text || '')) {
							hint = '请求超时（大文件上传时常见）。可稍后点「刷新状态」查看是否已上传成功，或改用系统 Cron 触发。';
						} else if (/wp-login|log in/i.test(text || '')) {
							hint = '登录已失效，请刷新页面后重新登录再试。';
						}
						var err = new Error(hint + (snippet ? ' 响应片段：' + snippet : ''));
						err.status = response.status;
						err.raw = text;
						throw err;
					}
					if (!response.ok || !json || !json.success) {
						var fail = new Error(json && json.data && json.data.message ? json.data.message : '请求失败');
						fail.data = json && json.data ? json.data : {};
						fail.status = response.status;
						throw fail;
					}
					return json;
				});
			});
		}

		/**
		 * 设置主要按钮禁用状态。
		 *
		 * @param {boolean} loading 是否加载中。
		 */
		function setBusy(loading) {
			saveButtons.forEach(function (btn) {
				btn.disabled = loading;
			});
			testButton.disabled = loading;
			runButton.disabled = loading;
		}

		/**
		 * 转义文本。
		 *
		 * @param {string} value 文本。
		 * @return {string}
		 */
		function escapeHtml(value) {
			var div = document.createElement('div');
			div.textContent = value == null ? '' : String(value);
			return div.innerHTML;
		}

		/**
		 * 渲染日志操作列。
		 *
		 * @param {Object} item 日志行。
		 * @return {string}
		 */
		function renderLogActions(item) {
			var parts = [];
			if (item.can_pull) {
				parts.push('<button type="button" class="button button-small ai1-oss-pull" data-log-id="' + item.id + '">拉取到本地</button>');
			}
			if (item.can_delete_local) {
				parts.push('<button type="button" class="button button-small ai1-oss-delete-local" data-log-id="' + item.id + '">删除本地</button>');
			}
			if (item.can_reupload) {
				parts.push('<button type="button" class="button button-small ai1-oss-reupload" data-log-id="' + item.id + '">重新上传</button>');
			}
			return parts.length ? '<div class="ai1-oss-row-actions">' + parts.join('') + '</div>' : '—';
		}

		/**
		 * 渲染日志表。
		 *
		 * @param {Object} payload 日志分页数据。
		 */
		function renderLogs(payload) {
			var items = payload.items || [];
			logsMeta.textContent = '共 ' + (payload.total || 0) + ' 条';
			if (!items.length) {
				logsBody.innerHTML = '<tr class="ai1-oss-empty"><td colspan="8">暂无日志</td></tr>';
				return;
			}

			logsBody.innerHTML = items.map(function (item) {
				var statusHtml = escapeHtml(item.status);
				if (item.is_monthly_anchor) {
					statusHtml += ' <span title="月度锚点">📅</span>';
				}
				if (item.remote_missing) {
					statusHtml += ' <span style="color:#b32d2e;">缺失</span>';
				}
				if (item.error_message) {
					statusHtml += '<br /><small style="color:#b32d2e;">' + escapeHtml(item.error_message) + '</small>';
				}

				var keyHtml = '—';
				if (item.oss_object_key) {
					keyHtml = '<code class="ai1-oss-key">' + escapeHtml(item.oss_object_key) + '</code> ' +
						'<button type="button" class="button-link ai1-oss-copy-key" data-key="' + escapeHtml(item.oss_object_key) + '">复制</button>';
				}

				return '<tr data-log-id="' + item.id + '">' +
					'<td>' + escapeHtml(item.created_at) + '</td>' +
					'<td>' + escapeHtml(item.trigger_type) + '</td>' +
					'<td><code>' + escapeHtml(item.local_filename) + '</code></td>' +
					'<td>' + escapeHtml(item.file_size_label) + '</td>' +
					'<td>' + statusHtml + '</td>' +
					'<td>' + keyHtml + '</td>' +
					'<td>' + (item.duration_ms != null ? escapeHtml(item.duration_ms) + ' ms' : '—') + '</td>' +
					'<td>' + renderLogActions(item) + '</td>' +
					'</tr>';
			}).join('');
		}

		/**
		 * 渲染 OSS 对象表。
		 *
		 * @param {Object} payload 列举结果。
		 */
		function renderObjects(payload) {
			var items = payload.items || [];
			var prefixLabel = payload.prefix ? ('前缀：' + payload.prefix + '；') : '前缀：空（桶根）；';
			objectsMeta.textContent = prefixLabel + (payload.message || ('共 ' + items.length + ' 个'));
			if (!items.length) {
				objectsBody.innerHTML = '<tr class="ai1-oss-empty"><td colspan="7">未找到 `.wpress` 对象</td></tr>';
				return;
			}

			objectsBody.innerHTML = items.map(function (item) {
				var localLabel = item.local_exists ? '已有' : '无';
				var kindLabel = item.kind_label || '站点包';
				var actions = '<div class="ai1-oss-row-actions">';
				actions += '<button type="button" class="button button-small ai1-oss-pull-key" data-object-key="' + escapeHtml(item.object_key) + '">拉取到本地</button>';
				if (item.local_exists) {
					actions += '<button type="button" class="button button-small ai1-oss-delete-local" data-filename="' + escapeHtml(item.filename) + '">删除本地</button>';
				}
				actions += '<button type="button" class="button button-small ai1-oss-delete-remote" data-object-key="' + escapeHtml(item.object_key) + '">删除 OSS</button>';
				actions += '</div>';
				return '<tr>' +
					'<td>' + kindLabel + '</td>' +
					'<td><code>' + escapeHtml(item.filename) + '</code></td>' +
					'<td>' + escapeHtml(item.size_label) + '</td>' +
					'<td>' + escapeHtml(item.last_modified || '—') + '</td>' +
					'<td><code>' + escapeHtml(item.object_key) + '</code></td>' +
					'<td>' + localLabel + '</td>' +
					'<td>' + actions + '</td>' +
					'</tr>';
			}).join('');
		}

		/**
		 * 拉取对象（D1：冲突时二次确认覆盖）。
		 *
		 * @param {Object} params log_id 或 object_key。
		 * @param {boolean=} force 是否覆盖。
		 * @return {Promise<Object>}
		 */
		function pullObject(params, force) {
			var extra = {};
			Object.keys(params).forEach(function (key) {
				extra[key] = params[key];
			});
			if (force) {
				extra.force_overwrite = '1';
			}

			return submitAction('ai1_oss_backup_pull_object', extra, false).catch(function (error) {
				if (error.data && error.data.needs_overwrite) {
					if (window.confirm(error.data.message + '\n\n确认覆盖本地同名文件？')) {
						return pullObject(params, true);
					}
					var cancelErr = new Error('已取消覆盖。');
					cancelErr.cancelled = true;
					throw cancelErr;
				}
				throw error;
			});
		}

		/**
		 * 显示拉取进度条。
		 *
		 * @param {string} text 文案。
		 * @param {number|null} percent 0–100；null 为不确定进度。
		 * @param {string=} state success|error|progress。
		 */
		function showPullBanner(text, percent, state) {
			if (!pullBanner) {
				return;
			}
			pullBanner.hidden = false;
			pullBanner.classList.remove('is-success', 'is-error', 'is-indeterminate');
			if (state === 'success') {
				pullBanner.classList.add('is-success');
			} else if (state === 'error') {
				pullBanner.classList.add('is-error');
			}
			if (percent == null || percent < 0) {
				if (state !== 'success' && state !== 'error') {
					pullBanner.classList.add('is-indeterminate');
				}
				if (pullBarFill) {
					pullBarFill.style.width = state === 'success' ? '100%' : '35%';
				}
			} else if (pullBarFill) {
				pullBarFill.style.width = Math.max(0, Math.min(100, percent)) + '%';
			}
			if (pullBannerText) {
				pullBannerText.textContent = text;
			}
		}

		/**
		 * 根据锁数据刷新拉取进度。
		 *
		 * @param {Object|null} lock 任务锁。
		 */
		function updatePullBannerFromLock(lock) {
			if (!pullInFlight || !lock) {
				return;
			}
			var progress = lock.progress || {};
			var expected = parseInt(progress.expected_bytes || 0, 10) || 0;
			var downloaded = parseInt(progress.downloaded_bytes || 0, 10) || 0;
			var percent = null;
			if (expected > 0) {
				percent = typeof progress.percent === 'number'
					? progress.percent
					: Math.min(99, Math.floor((downloaded * 100) / expected));
			}
			showPullBanner(lock.message || '正在从 OSS 拉取…请勿关闭或刷新本页。', percent);
		}

		/**
		 * 开始轮询拉取进度。
		 */
		function startPullPoll() {
			stopPullPoll();
			pullPollTimer = window.setInterval(function () {
				submitAction('ai1_oss_backup_job_status', null, false).then(function (json) {
					updatePullBannerFromLock(json.data && json.data.lock ? json.data.lock : null);
				}).catch(function () {});
			}, 1000);
		}

		/**
		 * 停止轮询拉取进度。
		 */
		function stopPullPoll() {
			if (pullPollTimer) {
				window.clearInterval(pullPollTimer);
				pullPollTimer = null;
			}
		}

		/**
		 * 禁用/恢复拉取按钮。
		 *
		 * @param {boolean} disabled 是否禁用。
		 */
		function setPullButtonsDisabled(disabled) {
			document.querySelectorAll('.ai1-oss-pull, .ai1-oss-pull-key, .ai1-oss-delete-local, .ai1-oss-delete-remote').forEach(function (btn) {
				btn.disabled = disabled;
			});
		}

		/**
		 * 执行拉取并展示进度。
		 *
		 * @param {Object} params 拉取参数。
		 * @param {HTMLElement=} button 触发按钮。
		 */
		function runPull(params, button) {
			pullInFlight = true;
			pullActiveButton = button || null;
			pullActiveButtonLabel = button ? button.textContent : '';
			setBusy(true);
			setPullButtonsDisabled(true);
			if (button) {
				button.textContent = '下载中…';
			}
			if (progressEl) {
				progressEl.style.display = 'none';
			}
			showPullBanner('正在从 OSS 拉取…大文件可能需要几分钟，请勿关闭或刷新本页。', null);
			if (pullBanner && typeof pullBanner.scrollIntoView === 'function') {
				pullBanner.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
			startPullPoll();

			pullObject(params, false)
				.then(function (json) {
					stopPullPoll();
					var data = json.data || {};
					var msg = data.message ? data.message : '拉取完成。';
					showMessage(msg, true);
					showPullBanner(msg, 100, 'success');

					return Promise.all([
						refreshStatus(),
						refreshLogs(),
						refreshObjects().catch(function () { return null; })
					]);
				})
				.catch(function (error) {
					stopPullPoll();
					if (error.cancelled) {
						pullBanner.hidden = true;
						if (progressEl) {
							progressEl.style.display = 'none';
						}
						return refreshStatus();
					}
					showMessage(error.message, false);
					showPullBanner(error.message || '拉取失败。', null, 'error');
					return refreshStatus();
				})
				.finally(function () {
					pullInFlight = false;
					setBusy(false);
					setPullButtonsDisabled(false);
					if (pullActiveButton && pullActiveButtonLabel) {
						pullActiveButton.textContent = pullActiveButtonLabel;
					}
					pullActiveButton = null;
					pullActiveButtonLabel = '';
				});
		}

		/**
		 * 刷新状态。
		 *
		 * @return {Promise<void>}
		 */
		function refreshStatus() {
			return submitAction('ai1_oss_backup_job_status', null, false).then(function (json) {
				var data = json.data || {};
				if (data.directory) {
					pendingEl.textContent = String(data.directory.pending_count || 0);
					var unstable = parseInt(data.directory.unstable_count || 0, 10) || 0;
					if (unstableEl) {
						unstableEl.textContent = String(unstable);
					}
					if (unstableWrap) {
						unstableWrap.style.display = unstable > 0 ? '' : 'none';
					}
				}
				if (data.summary) {
					lastRunEl.textContent = (data.summary.last_run_status || '尚未执行') +
						(data.summary.last_run_at ? ' @ ' + data.summary.last_run_at : '');
					lastMsgEl.textContent = data.summary.last_run_message || '';
				}
				if (data.running) {
					runButton.disabled = true;
					if (pullInFlight) {
						if (progressEl) {
							progressEl.style.display = 'none';
						}
						updatePullBannerFromLock(data.lock);
					} else {
						progressEl.style.display = '';
						progressEl.textContent = (data.lock && data.lock.message) ? data.lock.message : '任务进行中…';
					}
				} else if (!pullInFlight) {
					progressEl.style.display = 'none';
					runButton.disabled = false;
				}
				if (data.schedule) {
					renderScheduleInfo(data.schedule);
				}
			});
		}

		/**
		 * 刷新日志。
		 *
		 * @return {Promise<void>}
		 */
		function refreshLogs() {
			return submitAction('ai1_oss_backup_list_logs', { page: 1, per_page: 20 }, false).then(function (json) {
				renderLogs(json.data || {});
			});
		}

		/**
		 * 刷新 OSS 列表。
		 *
		 * @return {Promise<void>}
		 */
		function refreshObjects() {
			return submitAction('ai1_oss_backup_list_oss_objects', { max_keys: 200 }, false).then(function (json) {
				renderObjects(json.data || {});
			});
		}

		/**
		 * 保存设置（AJAX）。
		 *
		 * @return {Promise<void>}
		 */
		function saveSettings() {
			setBusy(true);
			return submitAction('ai1_oss_backup_save_settings', null, true)
				.then(function (json) {
					showMessage(json.data.message, true);
					if (json.data && json.data.schedule) {
						renderScheduleInfo(json.data.schedule);
					}
				})
				.catch(function (error) {
					showMessage(error.message, false);
				})
				.finally(function () {
					setBusy(false);
				});
		}

		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				saveSettings();
			});
		}

		saveButtons.forEach(function (saveButton) {
			saveButton.addEventListener('click', function (event) {
				event.preventDefault();
				saveSettings();
			});
		});

		testButton.addEventListener('click', function () {
			setBusy(true);
			submitAction('ai1_oss_backup_test_connection', null, true)
				.then(function (json) {
					showMessage(json.data.message, true);
				})
				.catch(function (error) {
					showMessage(error.message, false);
				})
				.finally(function () {
					setBusy(false);
				});
		});

		runButton.addEventListener('click', function () {
			if (!window.confirm('确认立即执行上传？将上传最多「单次 Job 上限」个待传完整包。')) {
				return;
			}
			setBusy(true);
			progressEl.style.display = '';
			progressEl.textContent = '正在启动上传任务…';
			submitAction('ai1_oss_backup_run_now', null, false)
				.then(function (json) {
					showMessage(json.data.message, true);
					return Promise.all([refreshStatus(), refreshLogs()]);
				})
				.catch(function (error) {
					showMessage(error.message, false);
					return Promise.all([refreshStatus(), refreshLogs()]);
				})
				.finally(function () {
					setBusy(false);
				});
		});

		refreshStatusButton.addEventListener('click', function () {
			refreshStatus().catch(function (error) {
				showMessage(error.message, false);
			});
		});

		clearLockButton.addEventListener('click', function () {
			if (!window.confirm('确认清除任务锁？超时 running 日志会被标记为失败。')) {
				return;
			}
			submitAction('ai1_oss_backup_clear_lock', null, false)
				.then(function (json) {
					showMessage(json.data.message, true);
					return Promise.all([refreshStatus(), refreshLogs()]);
				})
				.catch(function (error) {
					showMessage(error.message, false);
				});
		});

		refreshLogsButton.addEventListener('click', function () {
			refreshLogs().catch(function (error) {
				showMessage(error.message, false);
			});
		});

		verifyRemoteButton.addEventListener('click', function () {
			if (!window.confirm('将对最近成功日志对应的 OSS 对象做存在性校验，缺失会打标，不会自动重传。继续？')) {
				return;
			}
			setBusy(true);
			submitAction('ai1_oss_backup_verify_remote', { limit: 50 }, false)
				.then(function (json) {
					showMessage(json.data.message, true);
					return refreshLogs();
				})
				.catch(function (error) {
					showMessage(error.message, false);
				})
				.finally(function () {
					setBusy(false);
				});
		});

		listObjectsButton.addEventListener('click', function () {
			setBusy(true);
			refreshObjects()
				.then(function () {
					showMessage('已获取线上 OSS 对象最新列表。', true);
				})
				.catch(function (error) {
					showMessage(error.message, false);
				})
				.finally(function () {
					setBusy(false);
				});
		});

		logsBody.addEventListener('click', function (event) {
			var target = event.target;
			if (target.classList.contains('ai1-oss-copy-key')) {
				var key = target.getAttribute('data-key') || '';
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(key).then(function () {
						showMessage('已复制 OSS Key。', true);
					});
				} else {
					window.prompt('复制 OSS Key：', key);
				}
				return;
			}

			if (target.classList.contains('ai1-oss-delete-local')) {
				var deleteLogId = target.getAttribute('data-log-id') || '';
				var deleteName = target.getAttribute('data-filename') || '';
				if (!deleteLogId && !deleteName) {
					return;
				}
				var deleteHint = deleteLogId
					? '确认删除该日志对应的本地 `.wpress`？\n\n只删除本地磁盘上的包，不会删除 OSS 对象。'
					: ('确认删除本地 All-in-One 备份目录中的该文件？\n' + deleteName + '\n\n只删除本地磁盘上的包，不会删除 OSS 对象。');
				if (!window.confirm(deleteHint)) {
					return;
				}
				setBusy(true);
				setPullButtonsDisabled(true);
				submitAction('ai1_oss_backup_delete_local', deleteLogId ? { log_id: deleteLogId } : { filename: deleteName }, false)
					.then(function (json) {
						showMessage(json.data && json.data.message ? json.data.message : '已删除本地文件。', true);
						return Promise.all([
							refreshStatus(),
							refreshLogs(),
							refreshObjects().catch(function () { return null; })
						]);
					})
					.catch(function (error) {
						showMessage(error.message, false);
					})
					.finally(function () {
						setBusy(false);
						setPullButtonsDisabled(false);
					});
				return;
			}

			if (target.classList.contains('ai1-oss-pull')) {
				var pullLogId = target.getAttribute('data-log-id');
				if (!window.confirm('确认从 OSS 拉取该备份到本地 All-in-One 目录？下载期间请保持本页打开，顶部会显示进度。')) {
					return;
				}
				runPull({ log_id: pullLogId }, target);
				return;
			}

			if (target.classList.contains('ai1-oss-reupload')) {
				var logId = target.getAttribute('data-log-id');
				if (!window.confirm('确认重新上传该日志对应的本地包？')) {
					return;
				}
				setBusy(true);
				submitAction('ai1_oss_backup_reupload', { log_id: logId }, false)
					.then(function (json) {
						showMessage(json.data.message, true);
						return Promise.all([refreshStatus(), refreshLogs()]);
					})
					.catch(function (error) {
						showMessage(error.message, false);
						return refreshLogs();
					})
					.finally(function () {
						setBusy(false);
					});
			}
		});

		objectsBody.addEventListener('click', function (event) {
			var target = event.target;
			if (target.classList.contains('ai1-oss-delete-remote')) {
				var remoteKey = target.getAttribute('data-object-key') || '';
				if (!remoteKey) {
					return;
				}
				if (!window.confirm('确认从 OSS 删除该线上对象？此操作不可恢复。\n' + remoteKey + '\n\n不会删除本地 All-in-One 备份目录中的同名文件。')) {
					return;
				}
				setBusy(true);
				setPullButtonsDisabled(true);
				submitAction('ai1_oss_backup_delete_remote', { object_key: remoteKey }, false)
					.then(function (json) {
						showMessage(json.data && json.data.message ? json.data.message : '已删除 OSS 对象。', true);
						return Promise.all([
							refreshLogs(),
							refreshObjects().catch(function () { return null; })
						]);
					})
					.catch(function (error) {
						showMessage(error.message, false);
					})
					.finally(function () {
						setBusy(false);
						setPullButtonsDisabled(false);
					});
				return;
			}
			if (target.classList.contains('ai1-oss-delete-local')) {
				var deleteName = target.getAttribute('data-filename') || '';
				if (!deleteName) {
					return;
				}
				if (!window.confirm('确认删除本地 All-in-One 备份目录中的该文件？\n' + deleteName + '\n\n只删除本地磁盘上的包，不会删除 OSS 对象。')) {
					return;
				}
				setBusy(true);
				setPullButtonsDisabled(true);
				submitAction('ai1_oss_backup_delete_local', { filename: deleteName }, false)
					.then(function (json) {
						showMessage(json.data && json.data.message ? json.data.message : '已删除本地文件。', true);
						return Promise.all([
							refreshStatus(),
							refreshLogs(),
							refreshObjects().catch(function () { return null; })
						]);
					})
					.catch(function (error) {
						showMessage(error.message, false);
					})
					.finally(function () {
						setBusy(false);
						setPullButtonsDisabled(false);
					});
				return;
			}
			if (!target.classList.contains('ai1-oss-pull-key')) {
				return;
			}
			var objectKey = target.getAttribute('data-object-key') || '';
			if (!objectKey) {
				return;
			}
			if (!window.confirm('确认拉取该 OSS 对象到本地 All-in-One 目录？\n' + objectKey + '\n下载期间请保持本页打开，顶部会显示进度。')) {
				return;
			}
			runPull({ object_key: objectKey }, target);
		});
	}());
	</script>
</div>
