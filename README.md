# 天气中心

基于高德开放平台的实时天气与预报系统，包含用户端、管理后台、统一 PHP API 与 SQLite 数据库。

- 版本：v1.1.0
- 许可证：MIT（详见 [LICENSE](LICENSE)）
- 技术栈：原生 HTML/CSS/JS、PHP 7.2+（推荐 7.4 / 8.x）、SQLite、TOML
- 配置：根目录 `config.toml`（唯一配置源，不提交 Git；修改后自动热重载）
- 文档：[详细架构](doc/README.md) ｜ [部署手册](doc/DEPLOY.md) ｜ [接口文档](doc/API.md) ｜ [目录速查](mulu.md) ｜ [贡献者](CONTRIBUTORS.md)

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
README.md                            项目简要说明
LICENSE                              MIT License
CONTRIBUTORS.md                      贡献者名单与贡献方式
```

## 安全与升级

- `config.toml` 可能包含真实高德 Key，**直接排除出 Git**；仓库只保留 `example_config.toml` 模板。
- 线上升级不要用本地默认 `config.toml` 覆盖服务器文件，否则会丢失 Key 与限流配置。
- 保存配置或检测到外部修改时，自动备份到根目录 `config_backups/`（保留最近 20 份）；恢复时复制备份为 `config.toml` 即可。
- 不要提交或上传 `weather.db*`、`config.toml`、`config.toml.lock`、`config_backups/`、`.codeartsdoer/`。
- 默认管理员密码仅用于首次登录，登录后必须立即修改。

## 第三方服务说明

本项目仅封装调用【高德开放平台 API】，**高德 API 不属于本开源代码范围**。

| 第三方服务 | 用途 | 说明 |
|------------|------|------|
| 高德开放平台 REST API | 天气查询、地点联想、地理编码、逆地理 | 需自行申请 **Web 服务 Key**，并开通天气服务 |
| 高德 JS API | 前端地图展示 | 需自行申请 JS API Key 与安全密钥，并配置域名白名单 |

使用本项目时，你必须：

1. 自行前往高德开放平台注册账号、申请 Key，遵守《高德地图开放平台服务协议》；
2. 在对外展示地图/结果时，按高德要求展示高德归属标注；
3. 高德服务有额度、计费、测绘合规限制，本作者不对高德 API 可用性、数据准确性承担任何责任。

本项目不包含高德地图 SDK 源码；地图数据、服务及相关商标归高德所有，本项目与高德无隶属或代理关系。

## 许可证

本项目采用 [MIT License](LICENSE) 发布。

Copyright (c) 2026 风铃 (https://github.com/dison0331)

## 贡献者

贡献者名单、贡献范围与参与方式见 [CONTRIBUTORS.md](CONTRIBUTORS.md)。