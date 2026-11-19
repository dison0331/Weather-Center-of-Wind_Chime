<?php
/**
 * helpers.php —— 通用工具：统一响应、参数读取、IP/指纹、日志、HTTP 客户端
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

/**
 * 业务异常：code 为业务码，httpCode 为 HTTP 状态码
 */
class ApiError extends Exception
{
    protected $httpCode;

    public function __construct($code, $message, $httpCode = 400)
    {
        parent::__construct($message, (int) $code);
        $this->httpCode = (int) $httpCode;
    }

    public function getHttpCode()
    {
        return $this->httpCode;
    }
}

/** 抛出业务错误 */
function fail($code, $message, $httpCode = 400)
{
    throw new ApiError($code, $message, $httpCode);
}

/** 统一 JSON 输出并结束请求 */
function json_out($code, $message, $data = null, $httpCode = 200)
{
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode([
        'code'    => (int) $code,
        'message' => (string) $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 读取请求体（JSON 优先，兼容表单） */
function request_body()
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $body = [];
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $body = $json;
        } else {
            parse_str($raw, $parsed);
            if (is_array($parsed)) {
                $body = $parsed;
            }
        }
    }
    if (empty($body) && !empty($_POST)) {
        $body = $_POST;
    }
    return $body;
}

/** 读取参数（GET 优先，其次请求体） */
function param($key, $default = null)
{
    if (isset($_GET[$key])) {
        return is_string($_GET[$key]) ? trim($_GET[$key]) : $_GET[$key];
    }
    $body = request_body();
    if (isset($body[$key])) {
        return is_string($body[$key]) ? trim($body[$key]) : $body[$key];
    }
    return $default;
}

function str_param($key, $default = '')
{
    $v = param($key, $default);
    return is_string($v) ? trim($v) : '';
}

function int_param($key, $default = 0)
{
    return (int) param($key, $default);
}

function is_https()
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    return (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/**
 * 跨域策略：allow_origin 为空时仅允许同源；配置后按白名单放行
 * @return bool 是否允许
 */
function apply_cors()
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return true;
    }
    $allow = trim((string) get_config('allow_origin', ''));
    if ($allow === '') {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $same = (is_https() ? 'https://' : 'http://') . $host;
        if (strcasecmp($origin, $same) === 0) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            return true;
        }
        return false;
    }
    foreach (explode(',', $allow) as $item) {
        $item = trim($item);
        if ($item !== '' && strcasecmp($item, $origin) === 0) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Content-Type, X-Guest-Token, X-Fingerprint');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            return true;
        }
    }
    return false;
}

/** 客户端 IP（默认只信 REMOTE_ADDR，配置 trust_proxy=1 时读取 X-Forwarded-For 首个地址） */
function client_ip()
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (get_config('trust_proxy', '0') === '1') {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        }
    }
    return substr($ip, 0, 64);
}

/** 客户端浏览器指纹（请求头 X-Fingerprint 优先） */
function client_fingerprint()
{
    $fp = $_SERVER['HTTP_X_FINGERPRINT'] ?? '';
    if ($fp === '') {
        $fp = str_param('fingerprint', '');
    }
    return substr(trim((string) $fp), 0, 80);
}

/** 客户端游客令牌（请求头 X-Guest-Token 优先） */
function client_token()
{
    $t = $_SERVER['HTTP_X_GUEST_TOKEN'] ?? '';
    if ($t === '') {
        $t = str_param('token', '');
    }
    return substr(trim((string) $t), 0, 64);
}

/** 指纹格式校验 */
function valid_fingerprint($fp)
{
    return (bool) preg_match('/^[A-Za-z0-9_\-]{8,80}$/', (string) $fp);
}

/** 字符串长度（无 mbstring 时回退 strlen） */
function text_len($text)
{
    return function_exists('mb_strlen') ? mb_strlen((string) $text, 'UTF-8') : strlen((string) $text);
}

/** 字符串截断（无 mbstring 时回退 substr） */
function text_cut($text, $max)
{
    $text = (string) $text;
    return function_exists('mb_substr') ? mb_substr($text, 0, (int) $max, 'UTF-8') : substr($text, 0, (int) $max);
}

/** 用户名格式校验 */
function valid_username($name)
{
    return (bool) preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,20}$/u', (string) $name);
}

/** 生成随机令牌（兼容未启用 openssl 的宝塔 PHP 环境） */
function random_token($bytes = 16)
{
    try {
        return bin2hex(random_bytes($bytes));
    } catch (Throwable $e) {
        // 继续尝试备用方案
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        try {
            $raw = openssl_random_pseudo_bytes($bytes);
            if ($raw !== false) {
                return bin2hex($raw);
            }
        } catch (Throwable $e) {
            // 继续尝试备用方案
        }
    }
    return substr(md5(uniqid('', true) . mt_rand()), 0, max(16, (int) $bytes * 2));
}

/** 写入访问日志（失败不影响业务） */
function log_access($action, $identity, $status, $code, $elapsedMs)
{
    try {
        db_exec(
            'INSERT INTO access_logs (created_at, ts, ip, fingerprint, user_id, action, method, status, code, elapsed_ms, ua)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                date('Y-m-d H:i:s'),
                time(),
                isset($identity['ip']) ? $identity['ip'] : client_ip(),
                isset($identity['fingerprint']) ? $identity['fingerprint'] : client_fingerprint(),
                isset($identity['user_id']) ? $identity['user_id'] : null,
                substr((string) $action, 0, 60),
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                (int) $status,
                (int) $code,
                (int) $elapsedMs,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
    } catch (Throwable $e) {
        error_log('[log_access] ' . $e->getMessage());
    }
}

/**
 * 发起 GET 请求
 * @return array{body:string, code:int, elapsed_ms:int, error:string}
 */
function http_get($url, $timeout = 8)
{
    $start = microtime(true);
    $body = '';
    $code = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'WeatherSystem/' . APP_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $result = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);
        if ($result === false) {
            $body = '';
        } else {
            $body = (string) $result;
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout'    => $timeout,
                'user_agent' => 'WeatherSystem/' . APP_VERSION,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $result = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($result === false) {
            $body = '';
            $error = '请求失败';
        } else {
            $body = (string) $result;
        }
    }

    return [
        'body'       => $body,
        'code'       => $code,
        'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
        'error'      => $error,
    ];
}