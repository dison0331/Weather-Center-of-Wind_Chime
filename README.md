# 天气中心

基于高德开放平台的实时天气与预报系统，包含用户端、管理后台、统一 PHP API 与 SQLite 数据库。

- 版本：v1.1.0
- 技术栈：原生 HTML/CSS/JS、PHP 7.2+（推荐 7.4 / 8.x）、SQLite、TOML
- 配置：根目录 `config.toml`（唯一配置源，不提交 Git；修改后自动热重载）
- 文档：[详细架构](doc/README.md) ｜ [部署手册](doc/DEPLOY.md) ｜ [接口文档](doc/API.md) ｜ [目录速查](mulu.md)

## 快速开始

1. 环境：PHP 7.2+（推荐 7.4 / 8.x），启用 `pdo_sqlite`，站点根目录可写；宝塔环境建议同时安装 `curl`。
2. 上传或克隆到站点根目录，确保 `api/` 目录完整；首次部署将 `example_config.toml` 复制为 `config.toml`（已有配置勿覆盖）；宝塔执行 `chown -R www:www 站点目录`。
3. 宝塔 Nginx 添加 `/api/` 与 `/config_backups/` 目录级 404 规则，并确保拒绝 `.toml/.lock`（部署文档 4.7 已包含）；确认根目录 `config.toml` 可写（建议 644）。
4. 访问 `api.php?action=ping` 初始化数据库（首次自动建库）。
5. 打开 `admin.html` 登录（默认管理员见部署文档），立即修改密码并填写高德 Key。
6. 打开 `user.html` 验证天气、地点联想与地图。

## 目录

```
index.html / user.html / admin.html   页面入口
api.php                              统一 API 入口
amap-js-proxy.php                    高德 JS Key 代理
api/                                 后端模块
config.toml                          唯一配置源（不提交 Git）
config_backups/                      配置自动备份（不提交 Git）
schema.sql                           建表 SQL
example_config.toml                  配置模板（唯一允许提交的配置）
doc/                                 架构、接口、部署文档
mulu.md                              目录速查
```

## 安全与升级

- `config.toml` 可能包含真实高德 Key，**直接排除出 Git**；仓库只保留 `example_config.toml` 模板。
- 线上升级不要用本地默认 `config.toml` 覆盖服务器文件，否则会丢失 Key 与限流配置。
- 保存配置或检测到外部修改时，自动备份到根目录 `config_backups/`（保留最近 20 份）；恢复时复制备份为 `config.toml` 即可。
- 不要提交或上传 `weather.db*`、`config.toml`、`config.toml.lock`、`config_backups/`、`.codeartsdoer/`。
- 默认管理员密码仅用于首次登录，登录后必须立即修改。