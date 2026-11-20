-- ============================================================
-- 天气系统 · SQLite 建表脚本（SQLite 3 方言）
-- 首次请求 api.php 时由 db.php 自动执行，PRAGMA user_version = 3
--
-- 配置已迁移到根目录 config.toml，数据库只保存用户与业务数据。
-- 旧版本升级时 db.php 会把 system_config 内容自动迁移到 config.toml，
-- 迁移完成并确认无误后可手动执行：DROP TABLE IF EXISTS system_config;
-- ============================================================

-- 1. 用户表
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    role          TEXT    NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    status        INTEGER NOT NULL DEFAULT 1 CHECK (status IN (0, 1)), -- 1 正常 / 0 停用或封禁（细分状态见 account_state）
    email         TEXT    DEFAULT '',
    account_state TEXT    NOT NULL DEFAULT 'active', -- active / disabled / banned
    warning       TEXT    DEFAULT '',
    warned_at     TEXT    DEFAULT '',
    fingerprint   TEXT    DEFAULT '',
    last_ip       TEXT    DEFAULT '',
    last_login_at TEXT    DEFAULT '',
    created_at    TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_users_role   ON users (role);
CREATE INDEX IF NOT EXISTS idx_users_status ON users (status);
CREATE INDEX IF NOT EXISTS idx_users_email  ON users (email);

-- 2. 关注城市表
CREATE TABLE IF NOT EXISTS user_cities (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    adcode     TEXT    NOT NULL DEFAULT '',
    city_name  TEXT    NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, city_name)
);
CREATE INDEX IF NOT EXISTS idx_user_cities_user ON user_cities (user_id, sort_order);

-- 3. 访问日志表
CREATE TABLE IF NOT EXISTS access_logs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at  TEXT    NOT NULL,
    ts          INTEGER NOT NULL,
    ip          TEXT    DEFAULT '',
    fingerprint TEXT    DEFAULT '',
    user_id     INTEGER DEFAULT NULL,
    action      TEXT    DEFAULT '',
    method      TEXT    DEFAULT 'GET',
    status      INTEGER DEFAULT 200,
    code        INTEGER DEFAULT 0,
    elapsed_ms  INTEGER DEFAULT 0,
    ua          TEXT    DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_logs_ts        ON access_logs (ts);
CREATE INDEX IF NOT EXISTS idx_logs_ip        ON access_logs (ip, ts);
CREATE INDEX IF NOT EXISTS idx_logs_fp        ON access_logs (fingerprint, ts);
CREATE INDEX IF NOT EXISTS idx_logs_user      ON access_logs (user_id, ts);
CREATE INDEX IF NOT EXISTS idx_logs_action    ON access_logs (action, ts);

-- 4. 游客令牌表（一次性 Token + 浏览器指纹绑定）
CREATE TABLE IF NOT EXISTS guest_tokens (
    token       TEXT PRIMARY KEY,
    fingerprint TEXT NOT NULL,
    ip          TEXT DEFAULT '',
    created_at  INTEGER NOT NULL,
    expires_at  INTEGER NOT NULL,
    revoked     INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_guest_tokens_fp ON guest_tokens (fingerprint);

-- 5. 黑名单表（IP / 浏览器指纹）
CREATE TABLE IF NOT EXISTS blacklist (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    type       TEXT NOT NULL CHECK (type IN ('ip', 'fingerprint')),
    value      TEXT NOT NULL,
    reason     TEXT DEFAULT '',
    created_at TEXT NOT NULL,
    UNIQUE (type, value)
);

-- 6. 高德接口缓存表
CREATE TABLE IF NOT EXISTS amap_cache (
    cache_key  TEXT PRIMARY KEY,
    payload    TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_amap_cache_exp ON amap_cache (expires_at);

-- 7. 高德调用统计表（管理员看板：成功率 / 延迟 / 调用量）
CREATE TABLE IF NOT EXISTS amap_calls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ts         INTEGER NOT NULL,
    service    TEXT    NOT NULL,
    ok         INTEGER NOT NULL DEFAULT 0,
    http_code  INTEGER DEFAULT 0,
    elapsed_ms INTEGER DEFAULT 0,
    info       TEXT    DEFAULT '',
    cached     INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_amap_calls_ts ON amap_calls (ts);