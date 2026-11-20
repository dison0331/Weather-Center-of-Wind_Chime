<?php
/**
 * config.php —— 从 config.toml 读取运行常量与数据库路径
 *
 * 配置默认位置：项目根目录 config.toml；
 * 兼容旧部署：若根目录不存在但 api/config.toml 存在，则自动回退读取 api/config.toml。
 * 可通过 WEATHER_CONFIG_FILE 覆盖路径。
 * 修改配置文件后下一次请求自动生效；后台保存会写回该文件并自动备份。
 * 数据库只保存用户、关注城市、日志、缓存等业务数据。
 */
if (!defined('IN_PROJECT')) {
    http_response_code(403);
    exit('Access Denied');
}

define('APP_ROOT', dirname(__DIR__));
define('API_DIR', __DIR__);

require_once API_DIR . '/toml.php';

$configFile = '';
if (isset($_SERVER['WEATHER_CONFIG_FILE']) && trim((string) $_SERVER['WEATHER_CONFIG_FILE']) !== '') {
    $configFile = trim((string) $_SERVER['WEATHER_CONFIG_FILE']);
} elseif (function_exists('getenv')) {
    $envConfig = getenv('WEATHER_CONFIG_FILE');
    if ($envConfig !== false && trim((string) $envConfig) !== '') {
        $configFile = trim((string) $envConfig);
    }
}
if ($configFile === '') {
    $rootConfig = APP_ROOT . '/config.toml';
    $legacyConfig = API_DIR . '/config.toml';
    if (is_file($rootConfig)) {
        $configFile = $rootConfig;
    } elseif (is_file($legacyConfig)) {
        $configFile = $legacyConfig;
    } else {
        $configFile = $rootConfig;
    }
} elseif (!toml_is_absolute_path($configFile)) {
    $configFile = toml_resolve_path($configFile, APP_ROOT);
}
define('CONFIG_FILE', $configFile);
define('CONFIG_BACKUP_DIR', APP_ROOT . '/config_backups');
define('CONFIG_SNAPSHOT', CONFIG_BACKUP_DIR . '/.snapshot');
define('CONFIG_BACKUP_KEEP', 20);

$bootConfig = toml_load_file(CONFIG_FILE);
define('CONFIG_PARSE_ERROR', toml_last_error());

$timezone = trim((string) ($bootConfig['timezone'] ?? 'Asia/Shanghai'));
if (!toml_valid_timezone($timezone)) {
    $timezone = 'Asia/Shanghai';
}
define('APP_TIMEZONE', $timezone);

$appVersion = trim((string) ($bootConfig['app_version'] ?? ''));
define('APP_VERSION', $appVersion !== '' ? $appVersion : '1.1.0.1021');

$appLanguage = trim((string) ($bootConfig['language'] ?? ''));
define('APP_LANGUAGE', $appLanguage !== '' ? $appLanguage : 'zh-CN');

define('SESSION_NAME', 'WEATHER_SESSID');

$weatherDbFile = '';
if (isset($_SERVER['WEATHER_DB_FILE']) && trim((string) $_SERVER['WEATHER_DB_FILE']) !== '') {
    $weatherDbFile = trim((string) $_SERVER['WEATHER_DB_FILE']);
} elseif (function_exists('getenv')) {
    $envDb = getenv('WEATHER_DB_FILE');
    if ($envDb !== false && trim((string) $envDb) !== '') {
        $weatherDbFile = trim((string) $envDb);
    }
}
if ($weatherDbFile === '') {
    $weatherDbFile = toml_resolve_path($bootConfig['database_path'] ?? '', APP_ROOT);
} elseif (!toml_is_absolute_path($weatherDbFile)) {
    $weatherDbFile = toml_resolve_path($weatherDbFile, APP_ROOT);
}
define('DB_FILE', $weatherDbFile);

date_default_timezone_set(APP_TIMEZONE);
toml_guard_snapshot(CONFIG_FILE, CONFIG_SNAPSHOT, CONFIG_BACKUP_DIR, CONFIG_BACKUP_KEEP);