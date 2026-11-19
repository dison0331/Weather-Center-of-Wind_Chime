<?php
/**
 * db.php —— SQLite 连接、自动建表、配置读写、查询封装
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

/** 获取 PDO 单例 */
function db()
{
    static $pdo = null;
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

/** 系统默认配置（首次建库时写入 system_config，可在后台修改） */
function default_config()
{
    return [
        'site_name'             => '天气中心',
        'allow_origin'          => '',
        'trust_proxy'           => '0',
        'register_enabled'      => '1',
        'default_city'          => '深圳',
        'auto_refresh'          => '300',

        'amap_web_key'          => '',
        'amap_web_secret'       => '',
        'amap_js_key'           => '',
        'amap_js_security_code' => '',
        'amap_daily_quota'      => '5000',

        'guest_rate_limit'      => '60',
        'guest_amap_rate_limit' => '20',
        'user_rate_limit'       => '120',
        'rate_window'           => '60',
        'login_fail_limit'      => '10',
        'login_fail_window'     => '600',

        'guest_token_ttl'       => '3600',
        'cache_ttl_weather'     => '600',
        'cache_ttl_tips'        => '300',
        'cache_ttl_regeo'       => '86400',
        'cache_ttl_geocode'     => '86400',

        'log_retain_days'       => '30',
    ];
}

/** 自动建表 + 初始化默认数据（通过 PRAGMA user_version 判断，仅首次执行） */
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
        foreach (default_config() as $key => $val) {
            db_exec(
                'INSERT OR IGNORE INTO system_config (cfg_key, cfg_value, updated_at) VALUES (?, ?, ?)',
                [$key, $val, $now]
            );
        }
        $exists = db_val("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        if (!$exists) {
            db_exec(
                'INSERT INTO users (username, password_hash, role, status, created_at) VALUES (?, ?, ?, 1, ?)',
                ['admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin', $now]
            );
        }
    }
    $done = true;
}

/** 一次性加载全部配置（含默认值兜底） */
function config_all($reload = false)
{
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = default_config();
        foreach (db_all('SELECT cfg_key, cfg_value FROM system_config') as $row) {
            $cache[$row['cfg_key']] = $row['cfg_value'];
        }
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
    db_exec(
        'INSERT OR REPLACE INTO system_config (cfg_key, cfg_value, updated_at) VALUES (?, ?, ?)',
        [(string) $key, (string) $value, date('Y-m-d H:i:s')]
    );
    config_all(true);
}

function set_config_many(array $pairs)
{
    $now = date('Y-m-d H:i:s');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO system_config (cfg_key, cfg_value, updated_at) VALUES (?, ?, ?)');
        foreach ($pairs as $key => $value) {
            $stmt->execute([(string) $key, (string) $value, $now]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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