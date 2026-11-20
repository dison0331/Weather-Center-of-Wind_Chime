# 天气中心 · 接口文档

> 所有请求统一入口：`api.php?action=<action>`
> 统一响应格式：`{"code": 0, "message": "ok", "data": {...}}`

---

## 1. 通用约定

### 1.1 请求方式

- 查询类接口支持 `GET` 或 `POST`
- 写操作建议使用 `POST`，请求体为 JSON：`Content-Type: application/json`
- `action` 参数始终放在 URL 上

### 1.2 身份请求头

| 头 | 说明 |
|----|------|
| `X-Guest-Token` | 游客令牌（`guest_init` 获取） |
| `X-Fingerprint` | 浏览器指纹（8-80 位字母/数字/下划线/连字符） |
| Cookie `WEATHER_SESSID` | 登录用户 Session（浏览器自动携带） |

> 登录用户优先使用 Session；游客访问业务接口必须携带有效令牌与指纹。

### 1.3 响应与状态码

| HTTP | code | 含义 |
|------|------|------|
| 200 | 0 | 成功 |
| 400 | 1001 | 参数错误 |
| 401 | 1002 | 未登录 / 游客令牌无效、过期或指纹不匹配 |
| 401 | 1007 | 账号或密码错误 |
| 403 | 1003 | 访问被限制（IP/指纹黑名单、非法来源、接口不存在时的来源问题） |
| 403 | 1004 | 权限不足（需要管理员） |
| 403 | 1006 | 注册已关闭 |
| 403 | 1008 | 账号已被封禁 |
| 404 | 404 | action 不存在 |
| 409 | 1009 | 用户名已存在 |
| 429 | 1005 | 限流 / 登录尝试过多 |
| 502 | 2002 | 高德响应异常 |
| 502 | 2003 | 高德接口返回错误 |
| 503 | 2001 | 高德 Web 服务 Key 未配置 |
| 500 | 5000 | 服务器内部错误 |

请求被限流时响应头包含：

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
```

---

## 2. 公共接口（无需登录）

### 2.1 ping

`GET api.php?action=ping`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "version": "1.1.0.1021",
    "time": "2026-11-19 10:00:00",
    "site_name": "天气中心",
    "register_enabled": true
  }
}
```

### 2.2 config_public

`GET api.php?action=config_public`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "site_name": "天气中心",
    "site_icon": "favicon.svg",
    "register_enabled": true,
    "default_city": "深圳",
    "language": "zh-CN",
    "auto_refresh": 300,
    "amap_ready": true,
    "version": "1.1.0.1021",
    "server_time": "2026-11-19 10:00:00"
  }
}
```

### 2.3 guest_init

`POST api.php?action=guest_init`

| 参数 | 必填 | 说明 |
|------|------|------|
| fingerprint | 是 | 浏览器指纹（8-80 位） |

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "token": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
    "expires_at": 1793000000,
    "expires_in": 3600
  }
}
```

> 若请求携带的旧令牌仍有效且指纹一致，会直接复用；否则签发新令牌。

### 2.4 register

`POST api.php?action=register`

| 参数 | 必填 | 说明 |
|------|------|------|
| username | 是 | 3-20 位字母、数字、下划线或中文 |
| password | 是 | 6-72 位 |
| email | 否 | 验证/联系邮箱，格式校验，可留空 |
| fingerprint | 否 | 记录到用户表 |

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "user": {
      "id": 2,
      "username": "demo",
      "role": "user",
      "status": 1,
      "fingerprint": "a1b2c3d4e5f6a7b8",
      "last_ip": "203.0.113.8",
      "last_login_at": "2026-11-19 10:00:00",
      "created_at": "2026-11-19 10:00:00"
    }
  }
}
```

> 注册成功后自动建立 Session（登录状态）。

### 2.5 login

`POST api.php?action=login`

| 参数 | 必填 |
|------|------|
| username | 是 |
| password | 是 |

返回同 `register` 的 `user` 结构。失败返回 1007；封禁返回 1008。

### 2.6 logout

`POST api.php?action=logout` → `{"code":0,"message":"ok","data":{"logout":true}}`

### 2.7 me

`GET api.php?action=me`

登录用户：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "type": "user",
    "user": { "id": 2, "username": "demo", "role": "user", "status": 1 },
    "cities": 3,
    "ip": "203.0.113.8",
    "server_time": "2026-11-19 10:00:00"
  }
}
```

游客：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "type": "guest",
    "fingerprint": "a1b2c3d4e5f6a7b8",
    "has_token": true,
    "server_time": "2026-11-19 10:00:00"
  }
}
```

---

## 3. 天气与地点接口

> 需要有效游客令牌或登录 Session；均计入限流；结果经后端缓存。

### 3.1 weather —— 天气查询

`POST api.php?action=weather`

| 参数 | 必填 | 说明 |
|------|------|------|
| city | 是 | 城市名称或 adcode（推荐 adcode）；传中文城市名时后端会先通过地理编码解析为 adcode |

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "cached": false,
    "count": 1,
    "adcode": "440300",
    "city": "深圳市",
    "lives": [
      {
        "province": "广东",
        "city": "深圳市",
        "adcode": "440300",
        "weather": "晴",
        "temperature": "26",
        "winddirection": "东",
        "windpower": "3",
        "humidity": "62",
        "reporttime": "2026-11-19 09:00:00"
      }
    ],
    "forecasts": [
      {
        "city": "深圳市",
        "adcode": "440300",
        "province": "广东",
        "reporttime": "2026-11-19 08:00:00",
        "casts": [
          {
            "date": "2026-11-19",
            "dayweather": "晴",
            "nightweather": "多云",
            "daytemp": "27",
            "nighttemp": "19",
            "daywind": "东",
            "daypower": "3",
            "nightwind": "东",
            "nightpower": "2"
          }
        ]
      }
    ]
  }
}
```

> `adcode` / `city` 为后端最终使用的城市标识。若某城市暂无实时观测数据，`lives` 可能为空，前端会使用 `forecasts[0].casts[0]` 展示今日温度范围。

### 3.2 tips —— 地点联想

`POST api.php?action=tips`

| 参数 | 必填 | 说明 |
|------|------|------|
| keywords | 是 | 关键词，最长 30 字 |

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "cached": true,
    "tips": [
      {
        "id": "B00140...",
        "name": "深圳",
        "district": "广东省深圳市",
        "adcode": "440300",
        "address": "",
        "location": "114.057868,22.543099"
      }
    ]
  }
}
```

### 3.3 regeo —— 逆地理编码（预留）

`POST api.php?action=regeo`

| 参数 | 必填 | 说明 |
|------|------|------|
| location | 是 | `经度,纬度`，如 `114.057868,22.543099` |

返回高德 `regeocode` 结构。

### 3.4 geocode —— 地理编码

`POST api.php?action=geocode`

| 参数 | 必填 | 说明 |
|------|------|------|
| address | 是 | 城市名或地址，最长 80 字 |

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "cached": true,
    "geocodes": [
      {
        "formatted_address": "广东省深圳市",
        "province": "广东省",
        "city": "深圳市",
        "district": [],
        "adcode": "440300",
        "location": "114.057868,22.543099"
      }
    ]
  }
}
```

---

## 4. 关注城市接口（需登录）

### 4.1 cities_list

`GET api.php?action=cities_list`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "cities": [
      { "id": 1, "adcode": "440300", "city_name": "深圳", "sort_order": 1, "created_at": "2026-11-19 10:00:00" }
    ]
  }
}
```

### 4.2 cities_add

`POST api.php?action=cities_add`

| 参数 | 必填 | 说明 |
|------|------|------|
| city_name | 是 | 最长 40 字 |
| adcode | 否 | 6-12 位数字 |

上限 30 个；重复关注返回 1001。

### 4.3 cities_del

`POST api.php?action=cities_del`，参数 `id`（关注记录 ID）。仅能删除本人的记录。

### 4.4 cities_reorder（预留）

`POST api.php?action=cities_reorder`，参数 `ids` 为数组或 JSON 字符串，按数组顺序写入 `sort_order`。

---

## 5. 管理后台接口（需管理员 Session）

### 5.1 admin_login

`POST api.php?action=admin_login`

| 参数 | 必填 |
|------|------|
| username | 是 |
| password | 是 |

仅接受 `role=admin` 且 `status=1` 的账号。返回 `user`。非管理员返回 1004，密码错误 1007。

### 5.2 admin_overview

`POST api.php?action=admin_overview`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "requests_today": 1280,
    "users_total": 24,
    "users_banned": 1,
    "guest_tokens": 8,
    "amap": {
      "real_calls": 320,
      "cache_hits": 512,
      "success": 315,
      "failed": 5,
      "success_rate": 98.4,
      "avg_ms": 186,
      "quota": 5000,
      "quota_used": 320,
      "quota_left": 4680
    },
    "trend": [{ "hour": 0, "count": 3 }, { "hour": 1, "count": 0 }],
    "recent_calls": [
      { "ts": 1793000000, "time": "10:00:00", "service": "weather", "ok": 1, "http_code": 200, "elapsed_ms": 152, "info": "ok", "cached": 0 }
    ]
  }
}
```

### 5.3 admin_logs

`POST api.php?action=admin_logs`

| 参数 | 说明 |
|------|------|
| page | 页码，默认 1 |
| size | 每页 5-100，默认 20 |
| ip | IP 模糊匹配 |
| action | 接口名模糊匹配 |
| uid | 用户 ID 精确匹配 |
| date_from | 开始日期 `YYYY-MM-DD` |
| date_to | 结束日期 `YYYY-MM-DD`（含当天） |

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "page": 1,
    "size": 20,
    "total": 1280,
    "pages": 64,
    "items": [
      { "id": 1, "created_at": "2026-11-19 10:00:00", "ip": "203.0.113.8", "fingerprint": "a1b2...", "user_id": null, "action": "weather", "method": "POST", "status": 200, "code": 0, "elapsed_ms": 152, "ua": "Mozilla/5.0 ..." }
    ]
  }
}
```

### 5.4 admin_users

`POST api.php?action=admin_users`，参数 `page`、`size`、`keyword`（用户名、邮箱或最近 IP 模糊匹配）。

每项包含 `id / username / email / role / status / account_state / warning / warned_at / last_ip / last_login_at / created_at / city_count`。

### 5.5 admin_user_status（兼容接口）

`POST api.php?action=admin_user_status`

| 参数 | 必填 | 说明 |
|------|------|------|
| id | 是 | 目标用户 ID |
| status | 是 | `0` 封禁，`1` 解封 |

不能封禁当前登录账号，不能封禁最后一个可用管理员。新代码建议使用 `admin_user_action`。

### 5.5.1 admin_user_create

`POST api.php?action=admin_user_create`

| 参数 | 必填 | 说明 |
|------|------|------|
| username | 是 | 3-20 位字母、数字、下划线或中文 |
| password | 否 | 6-72 位；留空自动生成 |
| email | 否 | 验证/联系邮箱，格式校验 |

返回 `id`、`username`、`email`；自动生成密码时额外返回 `generated_password`（只返回一次）。

### 5.5.2 admin_user_action

`POST api.php?action=admin_user_action`

| 参数 | 必填 | 说明 |
|------|------|------|
| id | 是 | 目标用户 ID |
| action | 是 | `enable` 启用/解封、`disable` 停用、`ban` 封禁 |

停用与封禁均会将 `status` 设为 0，并通过 `account_state` 区分（disabled / banned）。
不能停用或封禁当前登录账号，不能停用或封禁最后一个可用管理员。

### 5.5.3 admin_user_warn

`POST api.php?action=admin_user_warn`

| 参数 | 必填 | 说明 |
|------|------|------|
| id | 是 | 目标用户 ID |
| warning | 否 | 警告内容，最长 200 字；留空清除警告 |

警告会在用户登录后以顶部提示条展示。

### 5.5.4 admin_user_reset_password

`POST api.php?action=admin_user_reset_password`

| 参数 | 必填 | 说明 |
|------|------|------|
| id | 是 | 目标用户 ID |
| password | 否 | 6-72 位；留空自动生成 |

自动生成时返回 `generated_password`（只返回一次）。

### 5.6 admin_config_get

`POST api.php?action=admin_config_get`

返回 `config`（全部配置项 + `updated_at`）与 `file`（配置文件 basename）。配置来自 `config.toml`，仅管理员可见，包含高德密钥。

### 5.7 admin_config_save

`POST api.php?action=admin_config_save`

请求体：

```json
{
  "config": {
    "site_name": "天气中心",
    "site_icon": "favicon.svg",
    "admin_email": "",
    "app_version": "1.1.0.1021",
    "timezone": "Asia/Shanghai",
    "language": "zh-CN",
    "database_path": "weather.db",
    "amap_web_key": "xxxx",
    "amap_web_secret": "xxxx",
    "amap_js_key": "xxxx",
    "amap_js_security_code": "xxxx",
    "register_enabled": "1",
    "guest_rate_limit": "60",
    "guest_amap_rate_limit": "20",
    "user_rate_limit": "120",
    "rate_window": "60",
    "cache_ttl_weather": "600",
    "log_retain_days": "30"
  }
}
```

返回 `saved`（已保存键）、`deferred`（下一次请求生效的键）、`config`（最新配置）、`updated_at`、`file`。

保存规则：

- 全部配置写入 `config.toml`（原子写 + 文件锁 + 自动备份到根目录 `config_backups/`）；
- 高德 Key、限流、注册开关等保存后立即生效；
- `app_version`、`timezone`、`language`、`database_path` 在下一次请求生效；
- `database_path` 会校验目标目录存在且可写；`timezone` 必须是有效 IANA 时区；
- TOML 语法错误时拒绝保存，返回 1001。

### 5.8 admin_change_password

`POST api.php?action=admin_change_password`

| 参数 | 必填 | 说明 |
|------|------|------|
| old_password | 是 | 当前密码 |
| new_password | 是 | 6-72 位，且不能与当前密码相同 |

```json
{ "code": 0, "message": "ok", "data": { "changed": true } }
```

### 5.9 admin_blacklist_list

`POST api.php?action=admin_blacklist_list`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "items": [
      { "id": 1, "type": "ip", "value": "198.51.100.7", "reason": "异常扫描", "created_at": "2026-11-19 10:00:00" }
    ]
  }
}
```

### 5.10 admin_blacklist_add

`POST api.php?action=admin_blacklist_add`

| 参数 | 必填 | 说明 |
|------|------|------|
| type | 是 | `ip` 或 `fingerprint` |
| value | 是 | IP 需通过 `FILTER_VALIDATE_IP`；指纹需 8-80 位 |
| reason | 否 | 最长 100 字 |

### 5.11 admin_blacklist_delete

`POST api.php?action=admin_blacklist_delete`，参数 `id`。

---

## 6. 调用示例

### 6.1 游客查询天气

```bash
# 1. 初始化游客身份
curl -s "http://localhost/api.php?action=guest_init" \
  -H "Content-Type: application/json" \
  -H "X-Fingerprint: a1b2c3d4e5f6a7b8" \
  -d '{"fingerprint":"a1b2c3d4e5f6a7b8"}'

# 2. 使用返回的 token 查询天气
curl -s "http://localhost/api.php?action=weather" \
  -H "Content-Type: application/json" \
  -H "X-Fingerprint: a1b2c3d4e5f6a7b8" \
  -H "X-Guest-Token: <上一步 token>" \
  -d '{"city":"440300"}'
```

### 6.2 管理员登录并查看看板

```bash
curl -s -c cookie.txt "http://localhost/api.php?action=admin_login" \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}'

curl -s -b cookie.txt "http://localhost/api.php?action=admin_overview" \
  -H "Content-Type: application/json" -d '{}'
```

### 6.3 保存配置

```bash
curl -s -b cookie.txt "http://localhost/api.php?action=admin_config_save" \
  -H "Content-Type: application/json" \
  -d '{"config":{"register_enabled":"0","guest_rate_limit":"30"}}'
```

---

## 7. 安全提示

- 所有 `admin_*` 接口必须先在管理后台登录（Session），否则返回 1004。
- 黑名单对 `api.php` 全部 action 生效，添加前请确认不会误封自己的出口 IP。
- 修改高德 Key 后无需重启，下一次请求即读取新值；旧缓存仍可能在 TTL 内返回。
- 前端请求应始终携带 `X-Fingerprint`，否则游客令牌会被判定指纹不匹配（1002）。