# 天气中心 · 部署文档

> 适用版本：**v1.0.3** ｜ 技术栈：PHP 7.2+（推荐 7.4 / 8.0 / 8.1 / 8.2）+ SQLite + 高德开放平台
>
> 本文档是可直接照做的部署手册，覆盖 **宝塔面板（推荐）**、**Apache**、**Nginx + PHP-FPM** 三种环境。
> 架构、模块职责与数据流见 [README.md](README.md)；接口参数见 [API.md](API.md)。

---

## 1. 部署前须知

| 事项 | 说明 |
|------|------|
| 运行依赖 | 只需要 PHP + SQLite 扩展，**不需要 MySQL、Composer、Node.js** |
| 文件位置 | 所有代码文件放在站点**根目录**，`doc/` 仅存放文档 |
| 数据库 | `weather.db` 在首次请求时自动创建，无需手动导入 SQL |
| 默认管理员 | `admin` / `admin123`，**首次登录后必须立即修改** |
| 高德密钥 | 不在代码中，登录后台「系统配置」录入，保存后即时生效 |
| 初始化入口 | `https://你的域名/api.php?action=ping` |
| 外网依赖 | 服务器需能访问 `restapi.amap.com`（REST）与 `webapi.amap.com`（JS 地图） |

首次访问 `api.php?action=ping` 会自动完成：创建 `weather.db`、执行 `schema.sql` 建 8 张表、写入默认配置、创建默认管理员。

---

## 2. 环境要求与检查

### 2.1 最低要求

| 项目 | 要求 |
|------|------|
| PHP | 7.2 及以上；推荐 7.4 / 8.0 / 8.1 / 8.2 |
| 必需扩展 | `pdo_sqlite` |
| 推荐扩展 | `curl`（缺失时回退 `file_get_contents`，需 `allow_url_fopen=On`） |
| 可选扩展 | `openssl`、`mbstring`（缺失时代码已自动降级） |
| Web 服务器 | 宝塔默认 Nginx；也支持 Apache（`.htaccess`） |
| 磁盘 | 初始几十 MB；日志按 `log_retain_days` 自动清理 |
| 目录权限 | 站点根目录可写（用于创建 `weather.db` / `-wal` / `-shm`） |

### 2.2 检查 PHP 版本与扩展

宝塔的 PHP 有多个版本，CLI 路径形如 `/www/server/php/80/bin/php`，按实际版本替换 `80`：

```bash
/www/server/php/80/bin/php -v
/www/server/php/80/bin/php -m | grep -iE 'pdo_sqlite|curl|mbstring|openssl'
```

如果输出中没有 `pdo_sqlite`，请先在宝塔安装扩展（见 4.3），否则系统会返回：

```json
{"code":5000,"message":"PHP 未启用 pdo_sqlite 扩展，请在宝塔面板「软件商店 → PHP → 设置 → 安装扩展」中安装后重试","data":null}
```

### 2.3 检查目录

确认站点根目录存在以下入口文件：

```bash
ls /www/wwwroot/weather.yousb.top
# 应看到 api.php、amap-js-proxy.php、user.html、admin.html、schema.sql 等
```

---

## 3. 高德开放平台准备

### 3.1 创建两类 Key

登录 [高德开放平台控制台](https://console.amap.com/)，创建应用后分别添加：

| Key 类型 | 用途 | 后台字段 | 备注 |
|----------|------|----------|------|
| **Web 服务** | 后端天气、地点联想、地理编码、逆地理 | `amap_web_key` | 可开启「数字签名」 |
| **Web端（JS API）** | 前端地图 SDK | `amap_js_key` | 需填写安全密钥 |

对应安全密钥：

| 字段 | 说明 |
|------|------|
| `amap_web_secret` | Web 服务 Key 的安全密钥。**仅在高德控制台开启数字签名时填写**，未开启必须留空 |
| `amap_js_security_code` | JS API 的安全密钥（控制台中的「安全密钥 / jscode」） |

### 3.2 域名白名单（重要）

在 JS API Key 的配置中填写站点域名（如 `weather.yousb.top`），否则地图可能因域名不匹配而加载失败。

> JS API 的 Key 最终需要由浏览器加载，虽然后端已通过 `amap-js-proxy.php` 动态注入、源码不出现明文，但运行时仍可在浏览器网络面板看到。**域名白名单是限制 Key 被异地使用的关键措施**。

### 3.3 配额

高德不同接口、不同认证类型有独立配额，请在控制台确认。部署后可在后台把「每日调用配额」`amap_daily_quota` 设置为实际额度，看板会显示「今日真实调用 / 配额」的估算值（缓存命中不计入真实调用）。

---

## 4. 宝塔面板部署（推荐）

### 4.1 创建站点

1. 宝塔「网站 → 添加站点」；
2. 填写域名（或先用服务器 IP 测试）；
3. PHP 版本选择 **7.4 / 8.0 / 8.1 / 8.2**；
4. 站点根目录保持默认，例如 `/www/wwwroot/weather.yousb.top`；
5. 数据库选择「不创建」（本系统使用 SQLite）。

### 4.2 上传文件

将项目文件上传到站点根目录（FTP、宝塔文件管理、`scp` 均可），保持以下结构：

**运行必需（根目录）：**

```
api.php  amap-js-proxy.php
index.html  user.html  admin.html  404.html
schema.sql
```

**运行必需（api/ 目录）：**

```
api/
├─ config.php  helpers.php  db.php  auth.php  rate_limit.php  amap.php
└─ api_auth.php  api_weather.php  api_cities.php  api_admin.php
```

**按环境需要：**

```
.htaccess          Apache 根目录安全规则（Apache 必需；宝塔 Nginx 不生效）
api/.htaccess      Apache 下拒绝后端目录直接访问
.user.ini          宝塔/PHP-FPM 用户级配置（建议上传修复版）
```

**可选（不影响运行）：**

```
doc/             交接文档
mulu.md          目录速查
```

**不要上传：**

```
.codeartsdoer/   IDE 缓存与索引，不参与运行
weather.db*      本地运行时数据库；首次部署由服务器自动创建（迁移场景除外，见第 7 节）
```

注意：

- 不要遗漏 `schema.sql`（建库必需，保持在根目录）；
- 不要遗漏 `api/` 目录及其中的 10 个模块（根目录 `api.php` 会按路径 `require`）；
- 不要把 `api/` 内的模块移动到根目录或改名为入口文件；
- `.user.ini` 请使用本项目自带的修复版（旧主机路径已移除）；
- `*.bak`、`*.zip`、`*.log` 等临时文件不要上传到站点根目录。

### 4.3 安装 / 确认 PHP 扩展

宝塔「软件商店 → PHP-8.x → 设置 → 安装扩展」：

- 必需：`pdo_sqlite`
- 推荐：`sqlite3`、`curl`
- 可选：`mbstring`、`openssl`

安装后无需修改代码，刷新页面即可。

### 4.4 运行目录与默认文档

- 「网站 → 设置 → 网站目录」：**运行目录保持 `/`**，不要设置为 `public` 等子目录；
- 默认文档包含 `index.html`（项目已提供入口导航页）；
- **无需配置伪静态**：系统不依赖 URL 重写，接口形如 `api.php?action=xxx`。

### 4.5 `.user.ini` 与防跨站

本项目 `.user.ini` 已删除旧主机遗留的 `open_basedir=/data/wwwweb/...`（该路径在宝塔上不存在，会导致**全站 500**），当前仅保留：

```ini
display_errors=Off
log_errors=On
```

如果开启了「网站 → 设置 → 网站目录 → 防跨站攻击(open_basedir)」，宝塔会向 `.user.ini` 写入当前站点路径；建议**关闭再开启一次**，让面板按新路径重写。

`.user.ini` 由 PHP-FPM 缓存（默认 300 秒），修改后等待约 5 分钟，或在宝塔「软件商店 → PHP → 重启」立即生效。

### 4.6 目录权限

宝塔 PHP-FPM 默认以 `www` 用户运行，上传后文件属主可能是 `root`，需要修正：

```bash
chown -R www:www /www/wwwroot/weather.yousb.top
find /www/wwwroot/weather.yousb.top -type d -exec chmod 755 {} \;
find /www/wwwroot/weather.yousb.top -type f -exec chmod 644 {} \;
```

`weather.db` 由 PHP 进程创建，无需预先建库；若已存在但只读：

```bash
chown www:www /www/wwwroot/weather.yousb.top/weather.db*
```

### 4.7 配置 Nginx 安全规则（关键）

宝塔默认是 **Nginx**，项目里的 `.htaccess` **不会生效**。必须把下面的规则加入站点配置，否则 `weather.db` 可能被直接下载、`api/` 下的后端模块可能被直接请求。

**文件位置**：`/www/server/panel/vhost/nginx/你的域名.conf`（也可在宝塔「网站 → 设置 → 配置文件」中编辑）。

**放置位置**：在 `include enable-php-*.conf;` 或 `location ~ [^/]\.php(/|$)` **之前**（Nginx 正则 location 按书写顺序匹配，写在后面不会生效）。

```nginx
# 禁止下载 SQLite 数据库、建表脚本与文档
location ~* \.(db|db-wal|db-shm|sqlite|sql|md|ini|log)$ {
    return 404;
}

# 后端模块目录整体禁止直接访问（根目录 api.php 与 amap-js-proxy.php 不受影响）
location = /api { return 404; }
location ^~ /api/ { return 404; }
```

> 如需展示项目自带的 `404.html`，在站点配置的 `server` 级别加入 `error_page 404 /404.html;`（不要放进 `location` 内）。

保存后：

```bash
nginx -t && nginx -s reload
```

（宝塔面板可直接点「重载配置」。）

> 规则会拒绝 `/api/config.php` 等全部后端模块，但不会拦截根目录的 `/api.php` 与 `/amap-js-proxy.php`。
> 若使用 Apache，跳过本小节：根 `.htaccess` 保护敏感文件，`api/.htaccess` 拒绝模块目录。

### 4.8 PHP 配置建议

宝塔「软件商店 → PHP-8.x → 设置 → 配置修改」：

```ini
display_errors = Off
log_errors = On
error_log = /www/wwwlogs/php_error.log
expose_php = Off
date.timezone = Asia/Shanghai
session.cookie_httponly = 1
max_execution_time = 60
memory_limit = 128M
post_max_size = 8M
upload_max_filesize = 8M
allow_url_fopen = On
```

保存后重启 PHP。若开启了 opcache 且 `opcache.validate_timestamps=0`，更新代码后也必须重启 PHP。

### 4.9 初始化系统

浏览器访问：

```
https://你的域名/api.php?action=ping
```

预期返回（时间与站点名以实际为准）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "version": "1.0.3",
    "time": "2026-11-19 10:00:00",
    "site_name": "天气中心",
    "register_enabled": true
  }
}
```

此时站点根目录应出现：

```
weather.db
weather.db-wal
weather.db-shm
```

再验证安全规则：

```bash
curl -sI https://你的域名/weather.db | head -n 1   # 期望 HTTP/1.1 404
curl -sI https://你的域名/api/config.php | head -n 1  # 期望 404（目录级拒绝）
curl -s  https://你的域名/api.php?action=ping      # 期望 code=0
```

### 4.10 首次登录与业务配置

1. 打开 `https://你的域名/admin.html`；
2. 使用 `admin / admin123` 登录；
3. 进入「系统配置 → 管理员密码」，**立即修改密码**；
4. 在「系统配置 → 高德开放平台」填写：
   - Web 服务 Key（`amap_web_key`）
   - Web 服务安全密钥（`amap_web_secret`，未开启数字签名则留空）
   - JS API Key（`amap_js_key`）
   - JS API 安全密钥（`amap_js_security_code`）
   - 每日调用配额（可选，用于看板估算）
5. 点击「保存配置」，无需重启，立即生效。

打开 `https://你的域名/user.html` 自检：

- 搜索框输入城市，应出现联想列表；
- 查询后显示当前天气与 4 天预报；
- 地图区域显示标注；
- 点击右侧「★ 关注城市」（需登录）可收藏；
- 右上角可切换深色模式；
- 左侧可设置自动刷新间隔。

### 4.11 备份计划任务（建议）

宝塔「计划任务 → 添加任务 → Shell 脚本」，每天低峰执行：

```bash
mkdir -p /www/backup/weather
cp -a /www/wwwroot/weather.yousb.top/weather.db* /www/backup/weather/$(date +%F_%H%M)/
find /www/backup/weather -type f -mtime +14 -delete
```

备份内容包含 `weather.db`、`weather.db-wal`、`weather.db-shm` 三个文件。若安装了 `sqlite3` 命令，可用一致性更好的方式：

```bash
sqlite3 /www/wwwroot/weather.yousb.top/weather.db \
  ".backup '/www/backup/weather/weather-$(date +%F).db'"
```

宝塔内置的 MySQL 备份**不适用**于 SQLite。

### 4.12 宝塔环境常见坑

| 问题 | 处理 |
|------|------|
| 开启「网站防篡改」后提示数据库只读 | 关闭防篡改，或把站点目录加入白名单 |
| 宝塔 WAF 返回 403/429 | 查看 WAF 日志，对站点/接口加白，注意与系统自身 429 区分 |
| 修改 `.user.ini` 不生效 | 等待 5 分钟或重启 PHP |
| 上传后属主是 root | 执行 `chown -R www:www` |
| 全站 500 且 PHP 日志报 `open_basedir` | 按 4.5 处理旧路径或让面板重建 `.user.ini` |
| 已配 HTTPS 但 Cookie 无 Secure | 确认 `is_https()` 能识别；宝塔反向代理时传 `X-Forwarded-Proto` |

---

## 5. Apache 部署

1. 启用 Apache 与 PHP（mod_php 或 PHP-FPM 均可），确保加载 `pdo_sqlite`；
2. 站点根目录指向项目根目录；
3. 启用 `.htaccess` 生效：`AllowOverride All`；
4. 确保 `mod_authz_core` 可用（`.htaccess` 使用 `Require all denied`）；
5. 站点根目录可写，属主与 Web 运行用户一致；
6. 访问 `api.php?action=ping` 初始化，后续步骤同 4.9 ~ 4.10。

根 `.htaccess` 已包含：

- 禁止访问 `.db / .db-wal / .db-shm / .sql / .md / .ini`；
- 禁止目录索引；
- 404 使用项目自带页面（`ErrorDocument 404 /404.html`）；
- 关闭 `display_errors`（若模块为 mod_php）。

`api/.htaccess` 已包含：

- 拒绝 Web 直接访问 `api/` 目录下的后端模块；
- 不影响根目录 `api.php` / `amap-js-proxy.php` 通过文件系统 `require` 这些模块。

---

## 6. Nginx + PHP-FPM 通用部署

```nginx
server {
    listen 80;
    server_name weather.example.com;
    root /www/wwwroot/weather.yousb.top;
    index index.html index.php;
    error_page 404 /404.html;

    # 安全规则必须在 PHP 处理之前
    location ~* \.(db|db-wal|db-shm|sqlite|sql|md|ini|log)$ {
        return 404;
    }
    location = /api { return 404; }
    location ^~ /api/ { return 404; }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.0-fpm.sock;
    }
}
```

要点：

- `.user.ini` 在纯 Nginx 环境同样由 PHP-FPM 读取，但不要依赖它承载关键逻辑；
- 站点目录属主与 PHP-FPM 运行用户一致；
- `open_basedir` 若开启，需包含站点根目录、`/tmp`、Session 目录；
- HTTPS 时确保 `fastcgi_param HTTPS on;` 或传递 `X-Forwarded-Proto`，以便 Session Cookie 正确加 `Secure`。

---

## 7. 数据库部署选项

### 7.1 默认：站点根目录（简单）

`weather.db` 位于站点根目录，开箱即用。必须按 4.7 配置 Nginx 拒绝下载 `.db` 文件，或在 Apache 下依赖 `.htaccess`。

### 7.2 外置：站点目录之外（更安全，推荐生产）

1. 创建目录并授权：

```bash
mkdir -p /www/data/weather
chown -R www:www /www/data/weather
chmod 750 /www/data/weather
```

2. 让系统读取新路径，二选一：

**方式 A（最简单）**：编辑 `api/config.php` 的 `DB_FILE` 默认值：

```php
define('DB_FILE', '/www/data/weather/weather.db');
```

**方式 B（不改代码）**：在宝塔站点配置 `server {}` 中加入：

```nginx
fastcgi_param WEATHER_DB_FILE /www/data/weather/weather.db;
```

`api/config.php` 会优先读取 `$_SERVER['WEATHER_DB_FILE']` 或环境变量。

3. 如果开启了防跨站（`open_basedir`），需把 `/www/data/weather` 加入允许路径：

```ini
open_basedir=/www/wwwroot/weather.yousb.top/:/www/data/weather/:/tmp/
```

4. 外置后访问 `api.php?action=ping` 验证；确认新库生效后，**删除站点根目录的旧 `weather.db*`**，避免混淆与泄漏风险。

### 7.3 迁移已有数据

1. 低峰期停止写入（可临时关闭站点）；
2. 复制三个文件到新位置：`weather.db`、`weather.db-wal`、`weather.db-shm`；
3. 修正属主与权限；
4. 更新 `DB_FILE` / FastCGI 参数；
5. 访问 `api.php?action=ping`，再登录后台核对账号、配置、日志；
6. 恢复站点访问。

---

## 8. HTTPS / CDN / 反向代理

### 8.1 宝塔开启 HTTPS

「网站 → SSL → Let's Encrypt」申请证书，开启「强制 HTTPS」。

系统通过以下任一条件识别 HTTPS：`$_SERVER['HTTPS']`、端口 `443`、`X-Forwarded-Proto: https`。识别为 HTTPS 后，Session Cookie 自动加 `Secure`。

### 8.2 使用 CDN / 反向代理

若前面有 CDN 或代理，后台开启「信任反向代理」`trust_proxy=1`，否则：

- 访问日志记录的是 CDN 节点 IP；
- IP 黑名单会误伤或失效；
- 游客限流可能按 CDN IP 合并计算。

开启后系统读取 `X-Forwarded-For` 的第一个地址。请确保只有可信代理能访问源站。

### 8.3 跨域白名单

默认 `allow_origin` 为空，仅允许同源请求。若前端页面与 API 不同域，在后台填写白名单（逗号分隔）：

```
https://weather.example.com,https://www.example.com
```

跨域场景需同时允许请求头 `X-Guest-Token`、`X-Fingerprint`（代码已处理）。

---

## 9. 升级、迁移与回滚

### 9.1 升级代码

1. **先备份** `weather.db*` 与当前代码目录；
2. 上传新代码覆盖，**不要删除或覆盖 `weather.db`**；
3. 若开启了 opcache，重启 PHP；
4. 访问 `api.php?action=ping` 确认 `version` 与预期一致；
5. 打开后台核对配置（配置保存在数据库，不会因覆盖代码丢失）；
6. 测试天气、地图、登录。

当前数据库版本为 `PRAGMA user_version = 1`。未来若发布带表结构变更的版本，请以发版说明为准，升级前务必备份数据库。

### 9.2 迁移到新服务器

1. 复制全部代码与 `weather.db*`；
2. 按第 4 章重新配置 PHP 扩展、目录权限与 Nginx 规则；
3. 若更换域名：更新高德 JS API 的域名白名单、后台 `allow_origin`（如跨域）；
4. 访问 `api.php?action=ping`，登录后台确认数据完整；
5. 检查访问日志与高德调用是否正常。

### 9.3 回滚

恢复旧代码与旧 `weather.db*`，重启 PHP，访问 `api.php?action=ping` 验证。回滚前建议保留故障现场副本。

### 9.4 重置系统（谨慎）

删除 `weather.db`、`weather.db-wal`、`weather.db-shm` 后访问 `api.php?action=ping`，会重新建库并恢复默认管理员 `admin / admin123`，**所有用户、配置、日志将丢失**。仅在测试环境使用，操作前务必备份。

---

## 10. 上线验收清单

| 检查项 | 验证方式 | 期望 |
|--------|----------|------|
| 入口页 | 访问 `/` | 显示入口导航 |
| API 健康 | `/api.php?action=ping` | `code=0`，版本正确 |
| 数据库保护 | `/weather.db` | 404（Nginx 规则或 `.htaccess`） |
| 内部模块保护 | `/api/config.php`、`/api/db.php` | 404（目录级拒绝） |
| 管理员登录 | `/admin.html` | 可登录，非管理员账号被拒绝 |
| 修改密码 | 后台 → 管理员密码 | 修改成功，旧密码失效 |
| 注册开关 | 后台 → 系统配置 | 关闭后用户端隐藏注册入口 |
| 游客初始化 | 打开 `/user.html` | 自动获取游客令牌，搜索可用 |
| 天气查询 | 搜索 `深圳` | 显示实时天气与 4 天预报 |
| 地图 | 用户端地图区域 | 显示地图与标注 |
| 关注城市 | 登录后点击★ | 可增删、切换 |
| 限流 | 连续快速请求 | 超限返回 429（code 1005） |
| 日志 | 后台访问日志 | 有本次请求记录 |
| 看板 | 后台数据看板 | 请求量、趋势、高德状态有数据 |
| 备份 | 计划任务 | 生成备份文件 |
| HTTPS | 浏览器 | 证书有效，无混合内容警告 |

---

## 11. 故障排查速查

建议按以下顺序定位，能快速区分是 Nginx、PHP、数据库还是高德的问题：

1. 静态页 `/index.html` 能否打开 → 判断 Nginx / 目录 / 权限；
2. `/api.php?action=ping` 是否返回 JSON → 判断 PHP / 扩展 / 数据库；
3. `/admin.html` 能否登录 → 判断 Session；
4. 天气与地图 → 判断高德 Key / 服务器出站网络。

| 现象 | 可能原因 | 处理 |
|------|----------|------|
| 全站 500 / 空白页 | `.user.ini` 旧 `open_basedir`；文件缺失；PHP 语法错误 | 检查 `php-fpm.log` 与站点错误日志；按 4.5 处理 `.user.ini`；确认 `schema.sql` 已上传 |
| `api.php` 返回 5000 并提示 pdo_sqlite | 扩展未安装 | 宝塔 PHP 设置安装 `pdo_sqlite` 后重启 |
| 提示“数据库目录不可写” | 属主/权限错误；防篡改 | `chown -R www:www`；关闭网站防篡改 |
| 提示“数据库为只读” | 防篡改插件、文件系统只读 | 关闭防篡改或加入白名单 |
| 提示“无法打开数据库文件” | 目录不存在、`open_basedir` 限制、磁盘满 | 检查路径/权限/磁盘；外置库需加入 `open_basedir` |
| 登录后立即掉线 | Session 目录不可写；`open_basedir` 不含 Session 路径；域名/HTTPS 不一致 | 检查 `session.save_path` 权限与 `open_basedir`；统一域名与协议 |
| 天气返回 2001 | Web 服务 Key 未配置 | 后台填写 `amap_web_key` |
| 天气返回 2002 | 服务器无法访问高德；curl/allow_url_fopen 不可用；SSL 证书问题 | `curl -sI https://restapi.amap.com` 测试；安装 curl 或开启 `allow_url_fopen`；检查出站防火墙 |
| 天气返回 2003 | Key 无效、服务未开通、配额用尽、签名不匹配 | 核对 Key 类型；若未开启数字签名，`amap_web_secret` 必须留空；查看 `amap_calls.info` |
| 地点联想无结果 | Web 服务 Key 未配或配额不足 | 同上；换关键词测试 |
| 地图空白 | JS Key / 安全密钥缺失；域名白名单未配置；HTTPS 下混用 HTTP | 后台填写两个 JS 字段；高德控制台添加域名；页面统一 HTTPS |
| 搜索/请求 429 | 触发游客/账号限流或 WAF | 登录后配额更高；后台调大 `guest_rate_limit` / `user_rate_limit`；检查 WAF |
| 403 且非系统返回 | 宝塔 WAF / 防火墙拦截 | 查看 WAF 日志并加白 |
| 页面样式/接口正常但 `weather.db` 可下载 | Nginx 规则未生效或位置错误 | 按 4.7 将规则放到 PHP 处理之前并重载 |
| 修改配置不生效 | 浏览器/OPcache 缓存；配置未保存 | 后台刷新；重启 PHP；确认保存成功 |
| 上传代码后行为未变 | opcache `validate_timestamps=0` | 重启 PHP |

### 11.1 查看日志

```bash
# Nginx 访问/错误日志（宝塔默认）
tail -n 100 /www/wwwlogs/你的域名.log
tail -n 100 /www/wwwlogs/你的域名.error.log

# PHP-FPM 日志
tail -n 100 /www/server/php/80/var/log/php-fpm.log

# PHP 错误日志（按 4.8 配置的路径）
tail -n 100 /www/wwwlogs/php_error.log
```

### 11.2 查询高德调用明细

后台看板只展示成功/失败与耗时；具体错误原因记录在 `amap_calls.info`。可用 PHP CLI 查询（只读）：

```bash
/www/server/php/80/bin/php <<'PHP'
<?php
$pdo = new PDO('sqlite:/www/wwwroot/weather.yousb.top/weather.db');
$pdo->exec('PRAGMA busy_timeout = 5000');
$sql = "SELECT datetime(ts, 'unixepoch', 'localtime') AS t,
               service, ok, http_code, elapsed_ms, cached, info
        FROM amap_calls ORDER BY id DESC LIMIT 20";
foreach ($pdo->query($sql) as $r) {
    echo $r['t'], ' | ', $r['service'], ' | ok=', $r['ok'],
         ' | ', $r['http_code'], ' | ', $r['elapsed_ms'], 'ms | cache=', $r['cached'],
         ' | ', $r['info'], PHP_EOL;
}
PHP
```

### 11.3 找回管理员密码

```bash
/www/server/php/80/bin/php -r '
$db = "/www/wwwroot/weather.yousb.top/weather.db";
$pdo = new PDO("sqlite:" . $db);
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
$stmt->execute([password_hash("替换为新密码", PASSWORD_DEFAULT), "admin"]);
echo "updated: ", $stmt->rowCount(), PHP_EOL;
'
```

也可以先执行 `php -r 'echo password_hash("新密码", PASSWORD_DEFAULT);'` 取得哈希，再用 SQLite 工具更新。

---

## 12. 安全加固建议

1. **立即修改默认管理员密码**，不要使用示例密码；
2. 高德控制台为 JS API Key 配置**域名白名单**，并按需限制 Web 服务 Key 的 IP；
3. `allow_origin` 保持为空（仅同源），除非确有跨域需求；
4. 生产环境关闭用户注册，或配合较严格的限流；
5. 收紧限流参数：`guest_rate_limit`、`guest_amap_rate_limit`、`user_rate_limit`；
6. 关注后台「封禁管理」，对异常 IP / 指纹及时拉黑；
7. 保持 `display_errors=Off`、`expose_php=Off`；
8. 数据库文件务必禁止 Web 下载；生产建议外置（第 7.2 节）；
9. 定期备份数据库与代码，并验证可恢复；
10. 如仅自己使用后台，可用 Nginx 限制 `/admin.html` 的来源 IP（可选，注意会限制其他管理员）：

```nginx
location = /admin.html {
    allow 203.0.113.10;
    deny all;
}
```

---

## 13. 附录

### 13.1 主要配置项（后台「系统配置」可改）

| 键 | 默认 | 说明 |
|----|------|------|
| `amap_web_key` | 空 | Web 服务 Key（必需） |
| `amap_web_secret` | 空 | Web 服务安全密钥（开启数字签名时填） |
| `amap_js_key` | 空 | JS API Key（地图） |
| `amap_js_security_code` | 空 | JS API 安全密钥 |
| `amap_daily_quota` | 5000 | 每日配额，用于看板估算 |
| `guest_rate_limit` | 60 | 游客每窗口请求上限 |
| `guest_amap_rate_limit` | 20 | 游客每窗口高德请求上限 |
| `user_rate_limit` | 120 | 登录账号每窗口配额 |
| `rate_window` | 60 | 限流窗口（秒） |
| `login_fail_limit` | 10 | 登录失败上限 |
| `login_fail_window` | 600 | 登录失败统计窗口（秒） |
| `guest_token_ttl` | 3600 | 游客令牌有效期（秒） |
| `cache_ttl_weather` | 600 | 天气缓存（秒） |
| `cache_ttl_tips` | 300 | 联想缓存（秒） |
| `cache_ttl_regeo` | 86400 | 逆地理缓存（秒） |
| `cache_ttl_geocode` | 86400 | 地理编码缓存（秒） |
| `log_retain_days` | 30 | 日志保留天数 |
| `register_enabled` | 1 | 注册开关 |
| `default_city` | 深圳 | 默认城市 |
| `auto_refresh` | 300 | 默认自动刷新间隔 |
| `allow_origin` | 空 | 跨域白名单 |
| `trust_proxy` | 0 | 是否信任反向代理 |

### 13.2 数据库文件

| 文件 | 说明 |
|------|------|
| `weather.db` | SQLite 主库 |
| `weather.db-wal` | WAL 预写日志（未合并时存在） |
| `weather.db-shm` | 共享内存索引 |

三者需一起备份/迁移。外置部署时路径由 `DB_FILE` / `WEATHER_DB_FILE` 决定。

### 13.3 常用命令

```bash
# 权限
chown -R www:www /www/wwwroot/weather.yousb.top
chmod 755 /www/wwwroot/weather.yousb.top
chmod 644 /www/wwwroot/weather.yousb.top/*.php /www/wwwroot/weather.yousb.top/*.html

# Nginx 校验与重载
nginx -t && nginx -s reload

# 初始化健康检查
curl -s "https://你的域名/api.php?action=ping"

# 数据库访问保护验证
curl -sI "https://你的域名/weather.db" | head -n 1

# 备份
mkdir -p /www/backup/weather
cp -a /www/wwwroot/weather.yousb.top/weather.db* /www/backup/weather/$(date +%F_%H%M)/
```

### 13.4 相关文档

- [README.md](README.md)：系统设计、模块职责、鉴权限流、数据流、安全说明
- [API.md](API.md)：全部接口参数、返回结构、错误码与 curl 示例