# WP AI1 OSS Auto Backup

将 [All-in-One WP Migration](https://wordpress.org/plugins/all-in-one-wp-migration/)（及 Pro）生成的 `.wpress` 备份，自动上传到**独立**的阿里云 OSS 私有 Bucket。支持定时调度、保留策略、上传日志、从 OSS 拉取回本地、远端存在性校验。

## 基本信息

- 作者：`biancaplus`
- 当前版本：`1.0.0`
- WordPress：`5.8+`
- PHP：`7.4+`
- 许可证：[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
- 后台入口：**设置 → AI1 OSS 自动备份**（仅管理员）

## 功能概览

| 能力 | 说明 |
|------|------|
| 独立 OSS 配置 | AccessKey / Endpoint / Bucket / 可选 Object 前缀；使用备份专用 Bucket |
| 自动上传 | 扫描 `ai1wm-backups`（或 `AI1WM_BACKUPS_PATH`）中的完整 `.wpress` |
| 半包保护 | 写入中的文件不会上传；写完后由后续 tick 续传 |
| 调度 | 每天 / 每周 / 每月 + 指定时刻；WP-Cron hourly tick |
| 单次上限 | 每轮最多上传 N 个包（默认 3），剩余下轮续传 |
| 保留策略 | OSS 滚动保留数 + 可选月度锚点 + 总地板（不会删光） |
| 本地清理 | 可选：上传成功后删本地 `.wpress`；可选按保留数清理本地超额包 |
| 日志 | 成功 / 失败记录；固定约 180 天清理（仍属保留范围的 success 不删） |
| 拉取 | 从 OSS 拉回 All-in-One 备份目录；默认不覆盖，确认后可覆盖 |
| 远端校验 | 标记缺失的 `.wpress`；不自动重传；本地有完整包时可「重新上传」 |
| 异常可见 | 管理员全站后台红条（任务中断 / Job 失败 / 调度未执行） |

## 依赖

1. **All-in-One WP Migration**（或 Pro）：负责导出 `.wpress`
2. **阿里云 OSS SDK**：默认复用已安装的 `oss-aliyun` 插件中的 SDK（`wp-content/plugins/oss-aliyun/sdk/vendor`）。若站点没有该插件，需自行保证 `\OSS\OssClient` 可加载。

## 安装

1. 将 `wp-ai1-oss-auto-backup` 目录上传至 `/wp-content/plugins/`。
2. 在 WordPress 后台的「插件」页面启用 **WP AI1 OSS Auto Backup**。
3. 进入 **设置 → AI1 OSS 自动备份**。

## 使用方法

### 1. 配置 OSS

在设置页填写：

- AccessKey ID / Secret
- Endpoint（如 `oss-cn-hangzhou.aliyuncs.com`，一般不带协议）
- **备份专用** Bucket
- Object 路径前缀（可留空；留空则对象落在桶根）
- 若 ECS 与 OSS 同地域，可勾选内网 Endpoint

点击「测试 OSS 连接」确认可用后保存。

### 2. 配置调度与保留

- **调度模式**：每天 / 每周 / 每月，并设置执行时刻
- 建议将本工具时刻设在 All-in-One **导出完成之后**（例如 AI1WM 周六 03:00，本工具 04:00）
- **单次 Job 上限**：控制每轮上传个数，避免一次拖垮 PHP
- **OSS 滚动保留数**：按一份 `.wpress`（一条成功上传日志）计，兼作总地板
- 可选：上传成功后删本地、清理 OSS/本地超额、启用月度锚点保留

### 3. 生产环境 Cron（强烈建议）

WP-Cron 依赖访问才能触发。生产环境请用系统 Cron 定期唤醒，例如每 5 分钟：

```bash
*/5 * * * * curl -fsS -o /dev/null "https://你的站点/wp-cron.php?doing_wp_cron"
```

若已设置 `DISABLE_WP_CRON`，则**必须**配置系统 Cron，否则定时上传不会执行。

### 4. 手动操作

- **立即执行上传**：上传当前待传完整包（受单次上限约束）
- **刷新状态 / 清除任务锁**：排查卡住的任务
- **日志**：复制 OSS Key、拉取到本地、删除本地 `.wpress`、重新上传
- **校验远端对象**：对成功日志做存在性检查并打标
- **OSS 对象列表**：列举前缀内 `.wpress`；可拉取、删除本地或删除 OSS

### 5. 与 All-in-One 联调

1. 用 AI1WM 导出一份备份
2. 确认文件出现在备份目录且已写完
3. 在本插件点「立即执行上传」或等待调度
4. 在 AI1WM「备份」页与本插件 OSS 列表对照；需要时可从 OSS「拉取到本地」后再用 AI1WM 还原

## 数据与权限

| 项 | 值 |
|----|-----|
| 设置 Option | `ai1_oss_auto_backup_settings`（`autoload=no`） |
| 日志表 | `{wpdb_prefix}ai1_oss_backup_logs` |
| 任务锁 | `ai1_oss_auto_backup_lock` |
| 权限 | 仅 `manage_options` |
| 前台 | 无公开下载代理；桶应保持私有 |

停用插件会清除 WP-Cron 钩子，但**保留**设置与日志表。卸载插件默认**仍保留**设置与日志表，仅清除任务锁；可按需手工清理数据库。

## 安全提示

- AccessKey 仅管理员可见；页面与成功 JSON 不回传 Secret 明文
- 拉取 / 删除受 Object 前缀与 `.wpress` 后缀约束
- OSS 清理采用「仅删本工具成功日志对应对象」策略，避免空前缀误扫桶

## 更新日志

### 1.0.0

- 首次通用版发布。
- All-in-One `.wpress` 自动上传 OSS、调度、保留、日志、拉取与远端校验。
