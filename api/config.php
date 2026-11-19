<?php
/**
 * config.php —— 仅存放路径与常量，不存放任何密钥
 * 所有业务配置（高德 Key、限流参数、功能开关）统一存于 SQLite system_config 表
 *
 * 宝塔部署提示：
 * - 默认数据库为站点根目录下的 weather.db；
 * - 如将数据库放到站点目录之外（推荐），可通过 fastcgi_param / 环境变量
 *   设置 WEATHER_DB_FILE 为绝对路径，或直接修改下面的默认路径。
 */
if (!defined('IN_PROJECT')) {
    http_response_code(403);
    exit('Access Denied');
}

define('APP_ROOT', dirname(__DIR__));
define('API_DIR', __DIR__);

$weatherDbFile = '';
if (isset($_SERVER['WEATHER_DB_FILE']) && trim((string) $_SERVER['WEATHER_DB_FILE']) !== '') {
    $weatherDbFile = trim((string) $_SERVER['WEATHER_DB_FILE']);
} elseif (function_exists('getenv')) {
    $envDb = getenv('WEATHER_DB_FILE');
    if ($envDb !== false && trim((string) $envDb) !== '') {
        $weatherDbFile = trim((string) $envDb);
    }
}

define('DB_FILE', $weatherDbFile !== '' ? $weatherDbFile : APP_ROOT . '/weather.db');
define('APP_TIMEZONE', 'Asia/Shanghai');
define('SESSION_NAME', 'WEATHER_SESSID');
define('APP_VERSION', '1.0.3');

date_default_timezone_set(APP_TIMEZONE);