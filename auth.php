<?php
/**
 * auth.php —— 双模式身份识别（Session 登录用户 / 游客 Token+指纹）、黑名单、登录封装
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

/**
 * 解析当前身份
 * 返回：type(user|guest)、role、user_id、user、ip、fingerprint、token
 */
function identify()
{
    static $identity = null;
    if ($identity !== null) {
        return $identity;
    }

    $ip = client_ip();
    $fp = client_fingerprint();

    // 1) 登录用户模式（PHP Session）
    if (!empty($_SESSION['uid'])) {
        $user = db_one('SELECT * FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
        if ($user) {
            if ((int) $user['status'] !== 1) {
                unset($_SESSION['uid']);
                fail(1008, '账号已被封禁', 403);
            }
            unset($user['password_hash']);
            $identity = [
                'type'        => 'user',
                'role'        => $user['role'],
                'user_id'     => (int) $user['id'],
                'user'        => $user,
                'ip'          => $ip,
                'fingerprint' => $fp,
                'token'       => null,
            ];
            return $identity;
        }
        unset($_SESSION['uid']);
    }

    // 2) 游客模式（一次性 Token + 指纹绑定）
    $token = client_token();
    $guest = [
        'type'        => 'guest',
        'role'        => 'guest',
        'user_id'     => null,
        'user'        => null,
        'ip'          => $ip,
        'fingerprint' => $fp,
        'token'       => $token !== '' ? $token : null,
    ];

    if ($token !== '') {
        $row = db_one('SELECT * FROM guest_tokens WHERE token = ?', [$token]);
        if (!$row) {
            fail(1002, '游客令牌无效，请重新初始化', 401);
        }
        if ((int) $row['revoked'] === 1) {
            fail(1002, '游客令牌已失效，请重新初始化', 401);
        }
        if ((int) $row['expires_at'] < time()) {
            fail(1002, '游客令牌已过期，请重新初始化', 401);
        }
        if (!valid_fingerprint($fp) || $row['fingerprint'] !== $fp) {
            fail(1002, '浏览器指纹与令牌不匹配，请重新初始化', 401);
        }
    }

    return $identity = $guest;
}

/** 游客基础身份（令牌无效时用于重新初始化的接口） */
function guest_base_identity()
{
    return [
        'type'        => 'guest',
        'role'        => 'guest',
        'user_id'     => null,
        'user'        => null,
        'ip'          => client_ip(),
        'fingerprint' => client_fingerprint(),
        'token'       => null,
    ];
}

/** 校验 IP / 指纹黑名单 */
function enforce_blacklist($identity)
{
    static $list = null;
    if ($list === null) {
        $list = db_all('SELECT type, value FROM blacklist');
    }
    $ip = (string) ($identity['ip'] ?? '');
    $fp = (string) ($identity['fingerprint'] ?? '');
    foreach ($list as $row) {
        if ($row['type'] === 'ip' && $ip !== '' && hash_equals((string) $row['value'], $ip)) {
            fail(1003, '您的访问已被限制', 403);
        }
        if ($row['type'] === 'fingerprint' && $fp !== '' && hash_equals((string) $row['value'], $fp)) {
            fail(1003, '您的访问已被限制', 403);
        }
    }
}

/** 业务接口要求已登录 */
function require_login($identity)
{
    if ($identity['type'] !== 'user') {
        fail(1002, '请先登录', 401);
    }
}

/** 业务接口要求携带有效的游客令牌或已登录 */
function require_guest_token($identity)
{
    if ($identity['type'] === 'guest' && empty($identity['token'])) {
        fail(1002, '请先初始化游客身份', 401);
    }
}

/** 管理接口要求管理员角色 */
function require_admin($identity)
{
    if ($identity['type'] !== 'user' || ($identity['user']['role'] ?? '') !== 'admin') {
        fail(1004, '需要管理员权限', 403);
    }
}

/** 登录失败防护（基于访问日志统计） */
function login_fail_guard($action, $ip)
{
    $limit = get_config_int('login_fail_limit', 10);
    if ($limit <= 0) {
        return;
    }
    $window = max(60, get_config_int('login_fail_window', 600));
    $count = (int) db_val(
        'SELECT COUNT(*) FROM access_logs WHERE action = ? AND code <> 0 AND ip = ? AND ts >= ?',
        [$action, $ip, time() - $window],
        0
    );
    if ($count >= $limit) {
        fail(1005, '尝试次数过多，请稍后再试', 429);
    }
}

/** 校验账号密码并返回用户（不含密码哈希） */
function authenticate($username, $password, $requireAdmin = false)
{
    $user = db_one('SELECT * FROM users WHERE username = ?', [$username]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        fail(1007, '账号或密码错误', 401);
    }
    if ((int) $user['status'] !== 1) {
        fail(1008, '账号已被封禁', 403);
    }
    if ($requireAdmin && $user['role'] !== 'admin') {
        fail(1004, '该账号不是管理员', 403);
    }
    unset($user['password_hash']);
    return $user;
}

/** 建立登录 Session */
function session_login(array $user, $fingerprint = '')
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['uid'] = (int) $user['id'];
    db_exec(
        'UPDATE users SET last_login_at = ?, last_ip = ?, fingerprint = ? WHERE id = ?',
        [date('Y-m-d H:i:s'), client_ip(), substr((string) $fingerprint, 0, 80), (int) $user['id']]
    );
    return $user;
}

/** 销毁登录 Session */
function session_logout()
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}