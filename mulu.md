# 项目目录速查

> 入口页面与 `api.php` 位于项目根目录；后端模块统一放在 `api/`；唯一配置文件为 `api/config.toml`；文档统一归档在 `doc/`。

项目说明：[README.md](README.md) ｜ 完整设计：[doc/README.md](doc/README.md) ｜ 接口：[doc/API.md](doc/API.md) ｜ 部署：[doc/DEPLOY.md](doc/DEPLOY.md)

## 1. 目录结构

```
站点根目录/
├─ 入口页面（浏览器可直接访问）
│  ├─ index.html              入口导航（用户端 / 管理后台）
│  ├─ user.html               用户端单页应用
│  ├─ admin.html              管理后台单页应用
│  ├─ 404.html                静态 404 页面
│  ├─ api.php                 统一 API 入口（保留根目录）
│  └─ amap-js-proxy.php       高德 JS API 密钥代理与 SDK 加载器
│
├─ api/                       后端模块（禁止 Web 直接访问）
│  ├─ config.toml             唯一配置源（DB 路径 / 时区 / 语言 / 版本 / 高德 Key / 限流 / 注册开关）
│  ├─ config.php              从 config.toml 读取 APP_ROOT、API_DIR、DB_FILE、时区、版本
│  ├─ toml.php                TOML 解析/序列化、原子写、文件锁、热重载
│  ├─ helpers.php             统一响应、参数、CORS、日志、HTTP 客户端
│  ├─ db.php                  SQLite 连接、建表、配置读写、旧配置迁移、GC
│  ├─ auth.php                双模式身份识别、黑名单、登录封装
│  ├─ rate_limit.php          游客 IP+指纹 / 用户账号配额限流
│  ├─ amap.php                高德 REST 代理（密钥注入、签名、缓存、统计）
│  ├─ api_auth.php            初始化 / 注册 / 登录 / 登出 / me / ping
│  ├─ api_weather.php         天气、地点联想、逆地理、地理编码
│  ├─ api_cities.php          关注城市增删改查
│  ├─ api_admin.php           管理后台全部接口
│  └─ .htaccess               Apache 下拒绝 Web 直接访问
│
├─ 数据与部署配置
│  ├─ schema.sql              建表 SQL（首次初始化自动执行）
│  ├─ example_config.toml     配置模板（复制为 api/config.toml）
│  ├─ .htaccess               Apache 根目录安全规则（含 404 页面）
│  └─ .user.ini               宝塔 / PHP-FPM 用户级配置
│
├─ 运行时自动生成（不要上传）
│  ├─ weather.db              SQLite 主库（用户 / 城市 / 日志 / 缓存）
│  ├─ weather.db-wal          WAL 预写日志
│  ├─ weather.db-shm          共享内存索引
│  ├─ api/config.toml.bak     上次保存的配置备份（目录级拒绝访问）
│  └─ api/config.toml.lock    配置写入文件锁（目录级拒绝访问）
│
├─ 文档与索引
│  ├─ README.md               项目简要说明（快速开始 / 文档入口）
│  ├─ mulu.md                 本速查（根目录快捷入口）
│  └─ doc/
│     ├─ README.md            架构、模块、数据流、安全
│     ├─ API.md               接口参数、返回示例、错误码
│     └─ DEPLOY.md            部署手册（宝塔 / Apache / Nginx）
│
└─ .codeartsdoer/             IDE 工具目录，不参与部署，不要上传
```

## 2. 访问控制

| 路径 | 浏览器直接访问 | 依据 |
|------|----------------|------|
| `index.html` / `user.html` / `admin.html` / `404.html` | 允许 | 静态页面 |
| `api.php` / `amap-js-proxy.php` | 允许 | 唯一后端对外入口 |
| `/api/` 整个目录（含 `config.toml`） | 拒绝 | 宝塔 Nginx `location ^~ /api/`；Apache `api/.htaccess` |
| `schema.sql` / `weather.db*` / `.user.ini` / `.htaccess` | 拒绝 | 根 `.htaccess` 与 Nginx 扩展名规则 |
| `README.md` / `mulu.md` / `doc/*.md` | 拒绝 | 同上 |

宝塔默认使用 Nginx，`.htaccess` 不生效，必须在站点配置中加入：

```nginx
location = /api { return 404; }
location ^~ /api/ { return 404; }
location ~* \.(db|db-wal|db-shm|sqlite|sql|md|ini|log|toml|bak|tmp|lock)$ { return 404; }
```

详见 [DEPLOY.md 4.7](doc/DEPLOY.md#47-配置-nginx-安全规则关键)。

## 3. 配置说明

- **唯一配置源**：`api/config.toml`，包括数据库路径、时区、语言、版本号、高德 Key、限流参数、注册开关等。
- **热重载**：外部修改 `config.toml` 后，下一次请求自动读取；后台保存立即刷新当前进程。
- **后台保存**：原子写（临时文件 + rename）+ 文件锁 + 自动备份 `config.toml.bak`。
- **请求级配置**：`timezone`、`language`、`app_version`、`database_path` 在下一次请求生效；其余业务配置保存后立即生效。
- **数据库用途**：SQLite 只保存用户、关注城市、访问日志、游客令牌、黑名单、高德缓存与调用统计；不再保存系统配置。
- **旧版本迁移**：首次请求会把旧 `system_config` 表内容自动补入 `config.toml`，完成后可手动执行 `DROP TABLE IF EXISTS system_config;`。

## 4. 部署文件清单

### 必需（根目录）

```
index.html  user.html  admin.html  404.html
api.php  amap-js-proxy.php
schema.sql
```

### 必需（api/ 目录）

```
config.php  toml.php  config.toml
helpers.php  db.php  auth.php  rate_limit.php  amap.php
api_auth.php  api_weather.php  api_cities.php  api_admin.php
```

### 按环境需要

```
.htaccess          Apache 根目录安全规则（含 ErrorDocument 404）
api/.htaccess      Apache 拒绝后端目录直接访问
.user.ini          宝塔 / PHP-FPM 用户级配置
Nginx 规则         宝塔 / 通用 Nginx 必须添加
```

### 可选

```
README.md   项目简要说明
example_config.toml  配置模板（部署时复制为 api/config.toml）
doc/        交接文档，不影响运行
mulu.md     目录速查，不影响运行
```

### 不要上传

```
.codeartsdoer/      IDE 缓存与索引
.git/               版本控制目录
.gitignore          版本控制配置（可不上传）
weather.db*         运行时生成；迁移场景除外
api/config.toml.bak / api/config.toml.lock  运行时生成
```

## 5. 结构约定

1. 入口页面与 `api.php`、`amap-js-proxy.php` 留在根目录；后端实现模块统一放 `api/`，不新增其他代码目录；
2. `api/` 目录整体禁止 Web 访问，新增后端模块直接放入 `api/` 即可；
3. 配置统一写 `api/config.toml`，禁止把密钥或限流参数回写到代码或数据库；
4. `api/config.php` 中 `APP_ROOT` 指向项目根目录、`API_DIR` 指向 `api/`；`schema.sql` 与 `weather.db` 位于根目录；
5. 新增文档统一放 `doc/`，并在同一次改动中同步 `doc/README.md` 与 `mulu.md` 的索引；
6. `weather.db*` 与配置备份/锁文件属于运行时数据，不纳入代码交付。

## 6. 默认入口

- 初始化 / 健康检查：`api.php?action=ping`
- 用户端：`user.html`
- 管理后台：`admin.html`（默认 `admin / admin123`，登录后立即改密）