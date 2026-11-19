<?php
/**
 * api.php —— 全部前端请求的统一入口
 * 路由格式：api.php?action=xxx
 * 处理链：建库 → CORS → Session → 身份识别 → 黑名单 → 鉴权 → 限流 → 业务处理 → 日志 → 统一响应
 *
 * 宝塔兼容：
 * - 自动检测 pdo_sqlite 扩展并返回可读错误（面板「PHP 设置 → 安装扩展」）
 * - Session Cookie 参数兼容 PHP 7.2+（宝塔可切换多个 PHP 版本）
 * - 移除 X-Powered-By，减少环境指纹暴露
 */
define('IN_PROJECT', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/amap.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);
header_remove('X-Powered-By');

$startTime = microtime(true);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    db_init();
    db_gc();
} catch (Throwable $e) {
    error_log('[db_init] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $message = '数据库初始化失败，请检查站点目录写权限';
    if (!extension_loaded('pdo_sqlite')) {
        $message = 'PHP 未启用 pdo_sqlite 扩展，请在宝塔面板「软件商店 → PHP → 设置 → 安装扩展」中安装后重试';
    } elseif (stripos($e->getMessage(), 'readonly') !== false || stripos($e->getMessage(), 'read-only') !== false) {
        $message = '数据库为只读（可能开启了宝塔「网站防篡改」），请关闭防篡改或将站点目录加入白名单';
    } elseif (stripos($e->getMessage(), 'unable to open database') !== false) {
        $message = '无法打开数据库文件，请检查目录权限、open_basedir 限制与磁盘空间';
    } elseif (!@is_writable(dirname(DB_FILE))) {
        $message = '数据库目录不可写，请将站点目录属主设为 www 并授予写权限（chown -R www:www）';
    } elseif (!is_file(APP_ROOT . '/schema.sql')) {
        $message = '缺少 schema.sql，无法初始化数据库，请重新上传完整文件';
    }
    json_out(5000, $message, null, 500);
}

if (!apply_cors()) {
    json_out(1003, '非法请求来源', null, 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SESSION_NAME);
    $secureCookie = is_https();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secureCookie, true);
    }
    @session_start();
}

$routes = array_merge(
    require APP_ROOT . '/api_auth.php',
    require APP_ROOT . '/api_weather.php',
    require APP_ROOT . '/api_cities.php',
    require APP_ROOT . '/api_admin.php'
);

$action = (string) param('action', '');
$identity = guest_base_identity();

// 公开初始化接口允许携带过期/失效令牌重新初始化
$lenientActions = ['guest_init', 'ping', 'config_public', 'login', 'register', 'admin_login', 'me', 'logout'];
try {
    $identity = identify();
} catch (ApiError $e) {
    if (in_array($action, $lenientActions, true) && $e->getCode() === 1002) {
        $identity = guest_base_identity();
    } else {
        log_access($action, guest_base_identity(), $e->getHttpCode(), $e->getCode(), (int) round((microtime(true) - $startTime) * 1000));
        json_out($e->getCode(), $e->getMessage(), null, $e->getHttpCode());
    }
}

try {
    if ($action === '' || !isset($routes[$action])) {
        fail(404, '接口不存在', 404);
    }
    $route = $routes[$action];

    // 1) 黑名单拦截（IP / 指纹）
    enforce_blacklist($identity);

    // 2) 鉴权拦截
    if (($route['auth'] ?? 'public') === 'guest_token') {
        require_guest_token($identity);
    } elseif (($route['auth'] ?? 'public') === 'user') {
        require_login($identity);
    } elseif (($route['auth'] ?? 'public') === 'admin') {
        require_admin($identity);
    }

    // 3) 限流中间件
    if (!empty($route['rate'])) {
        rate_check($identity, $action, !empty($route['amap']));
    }

    // 4) 业务处理
    $data = call_user_func($route['handler'], $identity);

    log_access($action, $identity, 200, 0, (int) round((microtime(true) - $startTime) * 1000));
    json_out(0, 'ok', $data, 200);
} catch (ApiError $e) {
    log_access($action, $identity, $e->getHttpCode(), $e->getCode(), (int) round((microtime(true) - $startTime) * 1000));
    json_out($e->getCode(), $e->getMessage(), null, $e->getHttpCode());
} catch (Throwable $e) {
    error_log('[api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    log_access($action, $identity, 500, 5000, (int) round((microtime(true) - $startTime) * 1000));
    json_out(5000, '服务器内部错误', null, 500);
}