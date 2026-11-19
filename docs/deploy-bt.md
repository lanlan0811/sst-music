# 宝塔面板部署指南（2 核 2G）

> 目标：在宝塔面板上部署随身听后端（PHP 8 + MySQL），并把 App 的 `BASE_URL` 指向该域名。

## 0. 前置条件

- 一台可用的服务器（2 核 2G 足够本项目的单房间/小规模场景），已安装宝塔面板
- 一个已备案并可解析到服务器的域名（App 需 HTTPS）
- 宝塔已安装：Nginx、PHP 8.x、MySQL 5.7/8.0、phpMyAdmin（可选）

## 1. 建站

1. 宝塔 → 网站 → 添加站点：域名填 `music.example.com`，根目录 `/www/wwwroot/sst-music`，PHP 版本选 8.x，不创建 FTP。
2. 上传代码：将仓库 `server/` 目录内容上传到 `/www/wwwroot/sst-music`（也可直接用 Git 部署）。
3. 修改站点运行目录：站点设置 → 网站目录 → 运行目录选择 `/public`，保存。

目录结构应为：

```
/www/wwwroot/sst-music/
├── app/
├── public/index.php      ← 运行目录
├── routes.php
├── config.php            ← 由 config.example.php 复制
├── cacert.pem
├── sql/schema.sql
└── storage/              ← 自动创建，需可写
```

## 2. 安装 PHP 扩展

宝塔 → 软件商店 → PHP 8.x 设置 → 安装扩展：`curl`、`mbstring`、`openssl`、`pdo_mysql`（一般默认已装）。

再点「配置修改」确认：

```ini
upload_max_filesize = 4M
post_max_size = 4M
max_execution_time = 30
memory_limit = 256M
```

> 本项目不使用 Composer，无需额外目录权限设置。`storage/` 需允许 www 用户写入（宝塔默认即可）。

## 3. 创建数据库

宝塔 → 数据库 → 添加数据库：

- 数据库名 `sst_music`，用户名 `sst_music`，密码随机生成（记下来）
- 字符集 `utf8mb4`

然后用 phpMyAdmin 导入 `sql/schema.sql`（或在终端执行）：

```bash
mysql -u sst_music -p sst_music < /www/wwwroot/sst-music/sql/schema.sql
```

## 4. 填写配置

```bash
cd /www/wwwroot/sst-music
cp config.example.php config.php
```

编辑 `config.php`：数据库填第 3 步的账号密码，`cron_key` 改成随机字符串（例如 `openssl rand -hex 16` 的输出）。

## 5. Nginx 伪静态

宝塔 → 站点设置 → 伪静态，填入：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

然后重启 Nginx。验证：

```bash
curl https://music.example.com/api/health
# 期望 {"code":0,"msg":"ok","data":{"ok":true,...}}
```

## 6. HTTPS

宝塔 → 站点设置 → SSL → Let's Encrypt 申请证书 → 开启「强制 HTTPS」。

> Android 12+ 默认禁止明文 HTTP，因此 App 必须使用 HTTPS 域名。

## 7. 2C2G 性能调优

**PHP-FPM**（宝塔 → PHP 设置 → 配置修改 → 性能调整）：

```ini
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 10s
pm.max_requests = 500
```

开启 OPcache：宝塔 → PHP 设置 → 性能调整 → 勾选 OPcache（opcache.memory_consumption=64M）。

**MySQL**（宝塔 → 软件商店 → MySQL 设置 → 配置修改）：

```ini
innodb_buffer_pool_size = 128M
innodb_log_file_size = 32M
max_connections = 60
skip-log-bin       # 若不需要主从/回档，可关闭 binlog 省内存
```

**Nginx**（站点配置中调整）：

```nginx
client_max_body_size 4m;
gzip on;
gzip_types application/json text/css application/javascript;
```

重启 PHP、MySQL、Nginx 生效。

## 8. 定时清理任务

宝塔 → 计划任务 → 添加任务：

- 任务类型：访问 URL
- URL：`https://music.example.com/api/cron/cleanup?key=你的cron_key`
- 执行周期：每天 04:00

该任务清理过期 token、登录失败记录、文件缓存，并关闭 24 小时无活动的房间。

## 9. 客户端配置

编辑 `client/config.js`：

```js
const DEFAULT_BASE_URL = 'https://music.example.com/api';
```

打包后 App 内也可在「我的 → 服务器地址」中修改（便于切换测试服/正式服）。

## 10. 部署后自检

```bash
# 1. 健康检查
curl https://music.example.com/api/health

# 2. 注册/登录
curl -X POST https://music.example.com/api/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"username":"test01","password":"secret123"}'

# 3. 搜索（应返回带 qualities 的 JSON）
curl 'https://music.example.com/api/search?platform=kuwo&keyword=%E6%99%B4%E5%A4%A9&page=1'

# 4. 直链
curl 'https://music.example.com/api/song/url?platform=kuwo&id=539121879&quality=flac'
```

## 11. 常见问题

| 现象 | 排查 |
|---|---|
| `/api/health` 404 | 运行目录未设为 `public`，或伪静态未配置 |
| 返回「服务端未配置」 | 未复制 `config.php` |
| 搜索返回 500 `SSL certificate problem` | `cacert.pem` 缺失或 PHP 未启用 openssl；重新上传 cacert.pem |
| 数据库连接被拒绝 | `config.php` 的 host/port/账号密码不对，或 MySQL 未启动 |
| 一起听轮询慢/超时 | 服务器负载过高，适当降低 PHP-FPM 并发或减少 max_children |
| 音源站接口失效（502） | 源站改版；只需修改 `app/SourceClient.php` |

## 12. 安全建议

- `config.php` 已在 `.gitignore` 中，不要提交到仓库
- 宝塔面板改掉默认端口、开启面板 SSL 与登录限制
- `cron_key` 使用随机长字符串
- 定期备份数据库（宝塔计划任务 → 备份数据库）