<?php
/**
 * db.php —— SQLite 连接、自动建表、TOML 配置读写、查询封装
 *
 * 配置唯一来源为 api/config.toml；数据库只保存用户、关注城市、日志、缓存等业务数据。
 * 旧版本 system_config 表会在 db_init() 中自动迁移到 config.toml（PRAGMA user_version 1 -> 2）。
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

/** 获取 PDO 单例 */
function db($reset = false)
{
    static $pdo = null;
    if ($reset) {
        $pdo = null;
        return null;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!extension_loaded('pdo_sqlite') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PHP 未启用 PDO SQLite 扩展（pdo_sqlite）');
    }
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    try {
        $pdo->exec('PRAGMA journal_mode = WAL');
    } catch (Throwable $e) {
        error_log('[db] WAL 模式不可用，回退默认日志模式：' . $e->getMessage());
    }
    try {
        $pdo->exec('PRAGMA synchronous = NORMAL');
    } catch (Throwable $e) {
        error_log('[db] 设置 synchronous 失败：' . $e->getMessage());
    }
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

/** 预编译执行 */
function db_exec($sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_one($sql, array $params = [])
{
    $row = db_exec($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all($sql, array $params = [])
{
    return db_exec($sql, $params)->fetchAll();
}

function db_val($sql, array $params = [], $default = null)
{
    $v = db_exec($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/** 系统默认配置（TOML 默认值） */
function default_config()
{
    return toml_default_config();
}

/** 自动建表 + 旧版配置/用户表迁移（PRAGMA user_version 0/1/2 -> 3） */
function db_init()
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

    if ($version < 1) {
        $sql = @file_get_contents(APP_ROOT . '/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('schema.sql 缺失，无法初始化数据库');
        }
        $pdo->exec($sql);
        $pdo->exec('PRAGMA user_version = 1');

        $now = date('Y-m-d H:i:s');
        $exists = db_val("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        if (!$exists) {
            db_exec(
                'INSERT INTO users (username, password_hash, role, status, account_state, created_at) VALUES (?, ?, ?, 1, ?, ?)',
                ['admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin', 'active', $now]
            );
        }
    }

    if ($version < 2) {
        if (!migrate_config_from_system_config()) {
            throw new RuntimeException('旧配置迁移到 config.toml 失败，请检查 api/ 目录写权限与 config.toml 语法');
        }
        $pdo->exec('PRAGMA user_version = 2');
    }

    if ($version < 3) {
        migrate_users_schema();
        $pdo->exec('PRAGMA user_version = 3');
    }

    $done = true;
}

/**
 * 用户表扩展列迁移（v1/v2 -> v3）：
 * email、account_state、warning、warned_at，并补充历史封禁账号状态。
 */
function migrate_users_schema()
{
    $pdo = db();
    $columns = [];
    foreach (db_all('PRAGMA table_info(users)') as $column) {
        $columns[$column['name']] = true;
    }
    if (!isset($columns['email'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT DEFAULT ''");
    }
    if (!isset($columns['account_state'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN account_state TEXT NOT NULL DEFAULT 'active'");
    }
    if (!isset($columns['warning'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN warning TEXT DEFAULT ''");
    }
    if (!isset($columns['warned_at'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN warned_at TEXT DEFAULT ''");
    }
    $pdo->exec(
        "UPDATE users
         SET account_state = CASE WHEN status = 0 THEN 'banned' ELSE 'active' END
         WHERE account_state IS NULL OR account_state = '' OR (status = 0 AND account_state = 'active')"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_email ON users (email)');
}

/**
 * 旧版 system_config 迁移到 config.toml。
 * 仅填充 TOML 中缺失、为空或仍为默认值的键，不覆盖用户已经自定义的 TOML 值。
 * 迁移失败时返回 false，保持 user_version=1，下次请求重试。
 */
function migrate_config_from_system_config()
{
    $table = db_val("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'system_config'");
    if (!$table) {
        return true;
    }
    $rows = db_all('SELECT cfg_key, cfg_value FROM system_config');
    if (!$rows) {
        return true;
    }

    $current = [];
    if (is_file(CONFIG_FILE)) {
        $content = @file_get_contents(CONFIG_FILE);
        if ($content === false) {
            error_log('[config] 旧配置迁移失败：config.toml 不可读');
            return false;
        }
        try {
            $current = toml_parse($content);
        } catch (Throwable $e) {
            error_log('[config] 旧配置迁移失败：' . $e->getMessage());
            return false;
        }
    }

    $defaults = toml_default_config();
    $changed = false;
    foreach ($rows as $row) {
        $key = (string) $row['cfg_key'];
        $value = (string) $row['cfg_value'];
        if ($key === '' || $key[0] === '_') {
            continue;
        }
        $has = array_key_exists($key, $current);
        $currentRaw = $has ? $current[$key] : '';
        if (is_bool($currentRaw)) {
            $currentRaw = $currentRaw ? '1' : '0';
        }
        $currentValue = (string) $currentRaw;
        $isDefault = $has && array_key_exists($key, $defaults)
            && (string) $defaults[$key] === $currentValue;
        if (!$has || trim($currentValue) === '' || $isDefault) {
            $current[$key] = $value;
            $changed = true;
        }
    }

    if (!$changed) {
        return true;
    }

    try {
        toml_save_file(CONFIG_FILE, array_merge($defaults, $current), CONFIG_BACKUP_DIR, CONFIG_SNAPSHOT, CONFIG_BACKUP_KEEP);
    } catch (Throwable $e) {
        error_log('[config] 旧配置迁移写入失败：' . $e->getMessage());
        return false;
    }
    return true;
}

/**
 * 一次性加载全部配置（含默认值兜底）。
 * 每次调用检查 config.toml 的 mtime/size，实现修改后自动热重载。
 */
function config_all($reload = false)
{
    static $cache = null;
    static $signature = null;
    $mtime = @filemtime(CONFIG_FILE);
    $size = @filesize(CONFIG_FILE);
    $now = ($mtime === false ? '0' : (string) $mtime) . ':' . ($size === false ? '0' : (string) $size);
    if ($cache === null || $reload || $now !== $signature) {
        $raw = toml_load_file(CONFIG_FILE);
        if (toml_last_error() !== '') {
            error_log('[config] ' . toml_last_error());
        }
        $cache = toml_normalize_config(array_merge(toml_default_config(), $raw));
        $signature = $now;
    }
    return $cache;
}

function get_config($key, $default = null)
{
    $all = config_all();
    if (array_key_exists($key, $all)) {
        return $all[$key];
    }
    return $default;
}

function get_config_int($key, $default = 0)
{
    return (int) get_config($key, $default);
}

function set_config($key, $value)
{
    set_config_many([(string) $key => (string) $value]);
}

/** 批量写入 config.toml（原子写 + 文件锁 + 自动备份） */
function set_config_many(array $pairs)
{
    $current = toml_load_file(CONFIG_FILE);
    if (toml_last_error() !== '') {
        throw new RuntimeException('config.toml 语法错误：' . toml_last_error());
    }
    foreach ($pairs as $key => $value) {
        $current[(string) $key] = $value;
    }
    toml_save_file(CONFIG_FILE, array_merge(toml_default_config(), $current), CONFIG_BACKUP_DIR, CONFIG_SNAPSHOT, CONFIG_BACKUP_KEEP);
    config_all(true);
}

/** 清理过期缓存 / 旧日志（按概率触发，避免每次请求都扫表） */
function db_gc()
{
    try {
        if (random_int(1, 50) !== 1) {
            return;
        }
        $now = time();
        db_exec('DELETE FROM amap_cache WHERE expires_at < ?', [$now]);
        db_exec('DELETE FROM guest_tokens WHERE expires_at < ?', [$now - 3600]);
        $days = max(1, get_config_int('log_retain_days', 30));
        db_exec('DELETE FROM access_logs WHERE ts < ?', [$now - $days * 86400]);
        db_exec('DELETE FROM amap_calls WHERE ts < ?', [$now - $days * 86400]);
    } catch (Throwable $e) {
        error_log('[db_gc] ' . $e->getMessage());
    }
}