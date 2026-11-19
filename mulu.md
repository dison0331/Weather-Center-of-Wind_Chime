# 项目目录速查

> 代码文件全部位于项目根目录（部署时即站点根目录），文档统一归档在 `doc/`。
> 完整设计：[doc/README.md](doc/README.md) ｜ 接口：[doc/API.md](doc/API.md) ｜ 部署：[doc/DEPLOY.md](doc/DEPLOY.md)

## 1. 目录结构

```
站点根目录/
├─ 入口页面（浏览器可直接访问）
│  ├─ index.html              入口导航（用户端 / 管理后台）
│  ├─ user.html               用户端单页应用（天气、地图、关注城市）
│  ├─ admin.html              管理后台（看板、日志、账号、配置、封禁）
│  ├─ 404.html                静态 404 页面
│  ├─ api.php                 统一 API 入口（唯一后端入口）
│  └─ amap-js-proxy.php       高德 JS API 密钥代理与 SDK 加载器
│
├─ API 路由模块（仅被 api.php 引入，禁止直接访问）
│  ├─ api_auth.php            初始化 / 注册 / 登录 / 登出 / me / ping
│  ├─ api_weather.php         天气、地点联想、逆地理、地理编码
│  ├─ api_cities.php          关注城市增删改查
│  └─ api_admin.php           管理后台全部接口
│
├─ 公共与中间件（仅被后端引入，禁止直接访问）
│  ├─ auth.php                双模式身份识别、黑名单、登录封装
│  ├─ rate_limit.php          游客 IP+指纹 / 用户账号配额限流
│  ├─ amap.php                高德 REST 代理（密钥注入、签名、缓存、统计）
│  ├─ db.php                  SQLite 连接、自动建库、配置读写、GC
│  ├─ helpers.php             统一响应、参数、CORS、日志、HTTP 客户端
│  └─ config.php              路径常量与时区（无任何密钥）
│
├─ 数据与部署配置
│  ├─ schema.sql              完整建表 SQL（首次初始化自动执行）
│  ├─ .htaccess               Apache 安全规则（宝塔 Nginx 不生效）
│  └─ .user.ini               宝塔 / PHP-FPM 用户级配置
│
├─ 运行时自动生成（不要上传）
│  ├─ weather.db              SQLite 主库
│  ├─ weather.db-wal          WAL 预写日志
│  └─ weather.db-shm          共享内存索引
│
├─ 文档与索引
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
| 其余 10 个 PHP 模块 | 拒绝 | `.htaccess`；宝塔需 Nginx 规则 |
| `schema.sql` / `weather.db*` / `.user.ini` | 拒绝 | `.htaccess`；宝塔需 Nginx 规则 |
| `mulu.md` / `doc/*.md` | 拒绝 | `.htaccess`；宝塔需 Nginx 规则 |

宝塔默认使用 Nginx，`.htaccess` 不生效，必须在站点配置中加入拒绝规则，详见 [DEPLOY.md 4.7](doc/DEPLOY.md#47-配置-nginx-安全规则关键)。

## 3. 部署文件清单

### 必需（缺少将无法运行）

```
api.php  api_auth.php  api_weather.php  api_cities.php  api_admin.php
auth.php  rate_limit.php  amap.php  db.php  helpers.php  config.php
schema.sql  amap-js-proxy.php
index.html  user.html  admin.html  404.html
.htaccess（Apache）或 Nginx 安全规则
.user.ini（宝塔 / PHP-FPM 可选）
```

### 可选

```
doc/        交接文档，不影响运行
mulu.md     目录速查，不影响运行
```

### 不要上传

```
.codeartsdoer/      IDE 缓存与索引
weather.db*         运行时生成；迁移场景除外
```

## 4. 结构约定

1. 所有 PHP / HTML / SQL 代码直接放在站点根目录，不新建代码子目录。
2. 新增后端模块后，必须同步加入宝塔 Nginx 的“内部模块拒绝访问”规则。
3. 新增文档统一放 `doc/`，并在同一次改动中同步 `doc/README.md` 与 `mulu.md` 的索引。
4. 系统配置（高德 Key、限流参数、注册开关）只存数据库 `system_config` 表，不写回 `config.php`。
5. 运行时数据（`weather.db*`）不纳入代码交付，首次访问 `api.php?action=ping` 时自动生成。

## 5. 默认入口

- 初始化 / 健康检查：`api.php?action=ping`
- 用户端：`user.html`
- 管理后台：`admin.html`（默认 `admin / admin123`，登录后立即改密）