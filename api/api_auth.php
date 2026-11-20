<?php
/**
 * api_auth.php —— 公共身份接口：初始化、注册、登录、登出、当前身份
 * 路由声明：['auth' => public|guest_token|user|admin, 'rate' => bool, 'amap' => bool, 'handler' => callable]
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

function auth_ping($identity)
{
    return [
        'version'          => APP_VERSION,
        'time'             => date('Y-m-d H:i:s'),
        'site_name'        => get_config('site_name', '天气中心'),
        'register_enabled' => get_config('register_enabled', '1') === '1',
    ];
}

function auth_config_public($identity)
{
    return [
        'site_name'        => get_config('site_name', '天气中心'),
        'site_icon'        => get_config('site_icon', 'favicon.svg'),
        'register_enabled' => get_config('register_enabled', '1') === '1',
        'default_city'     => get_config('default_city', '深圳'),
        'language'         => get_config('language', 'zh-CN'),
        'auto_refresh'     => get_config_int('auto_refresh', 300),
        'amap_ready'       => trim((string) get_config('amap_js_key', '')) !== '',
        'version'          => APP_VERSION,
        'server_time'      => date('Y-m-d H:i:s'),
    ];
}

/** 发放/复用游客令牌（一次性 Token + 指纹绑定） */
function auth_guest_init($identity)
{
    $fp = str_param('fingerprint');
    if (!valid_fingerprint($fp)) {
        fail(1001, '浏览器指纹无效', 400);
    }

    $ttl = max(60, get_config_int('guest_token_ttl', 3600));
    $now = time();

    $old = client_token();
    if ($old !== '') {
        $row = db_one('SELECT * FROM guest_tokens WHERE token = ?', [$old]);
        if ($row && (int) $row['revoked'] === 0 && (int) $row['expires_at'] > $now && $row['fingerprint'] === $fp) {
            return ['token' => $old, 'expires_at' => (int) $row['expires_at'], 'expires_in' => (int) $row['expires_at'] - $now];
        }
    }

    db_exec('DELETE FROM guest_tokens WHERE fingerprint = ? AND expires_at < ?', [$fp, $now - 60]);

    $token = random_token(16);
    db_exec(
        'INSERT INTO guest_tokens (token, fingerprint, ip, created_at, expires_at, revoked) VALUES (?, ?, ?, ?, ?, 0)',
        [$token, $fp, client_ip(), $now, $now + $ttl]
    );

    return ['token' => $token, 'expires_at' => $now + $ttl, 'expires_in' => $ttl];
}

/** 注册（受后台注册开关控制），成功后自动登录 */
function auth_register($identity)
{
    if (get_config('register_enabled', '1') !== '1') {
        fail(1006, '当前已关闭注册', 403);
    }

    $username = str_param('username');
    $password = (string) param('password', '');
    $email = trim((string) param('email', ''));
    $fp = client_fingerprint();

    if (!valid_username($username)) {
        fail(1001, '用户名需为 3-20 位字母、数字、下划线或中文', 400);
    }
    if (strlen($password) < 6 || strlen($password) > 72) {
        fail(1001, '密码长度需为 6-72 位', 400);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail(1001, '验证邮箱格式无效', 400);
    }
    if (db_val('SELECT id FROM users WHERE username = ?', [$username])) {
        fail(1009, '用户名已存在', 409);
    }
    if ($email !== '' && db_val('SELECT id FROM users WHERE email = ?', [$email])) {
        fail(1009, '邮箱已被使用', 409);
    }

    try {
        db_exec(
            'INSERT INTO users (username, password_hash, role, status, email, account_state, fingerprint, last_ip, created_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)',
            [
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                'user',
                $email,
                'active',
                $fp,
                client_ip(),
                date('Y-m-d H:i:s'),
            ]
        );
    } catch (PDOException $e) {
        fail(1009, '用户名已存在', 409);
    }

    $user = db_one('SELECT * FROM users WHERE username = ?', [$username]);
    unset($user['password_hash']);
    session_login($user, $fp);

    return ['user' => $user];
}

function auth_login($identity)
{
    $username = str_param('username');
    $password = (string) param('password', '');

    if ($username === '' || $password === '') {
        fail(1001, '请输入账号和密码', 400);
    }
    login_fail_guard('login', client_ip());

    $user = authenticate($username, $password, false);
    session_login($user, client_fingerprint());

    return ['user' => $user];
}

function auth_logout($identity)
{
    session_logout();
    return ['logout' => true];
}

function auth_me($identity)
{
    if ($identity['type'] === 'user') {
        $cityCount = (int) db_val('SELECT COUNT(*) FROM user_cities WHERE user_id = ?', [$identity['user_id']], 0);
        return [
            'type'       => 'user',
            'user'       => $identity['user'],
            'cities'     => $cityCount,
            'ip'         => $identity['ip'],
            'server_time'=> date('Y-m-d H:i:s'),
        ];
    }
    return [
        'type'        => 'guest',
        'fingerprint' => $identity['fingerprint'],
        'has_token'   => !empty($identity['token']),
        'server_time' => date('Y-m-d H:i:s'),
    ];
}

return [
    'ping'          => ['auth' => 'public', 'rate' => false, 'handler' => 'auth_ping'],
    'config_public' => ['auth' => 'public', 'rate' => false, 'handler' => 'auth_config_public'],
    'guest_init'    => ['auth' => 'public', 'rate' => false, 'handler' => 'auth_guest_init'],
    'register'      => ['auth' => 'public', 'rate' => false, 'handler' => 'auth_register'],
    'login'         => ['auth' => 'public', 'rate' => false, 'handler' => 'auth_login'],
    'logout'        => ['auth' => 'public', 'rate' => false, 'handler' => 'auth_logout'],
    'me'            => ['auth' => 'public', 'rate' => false, 'handler' => 'auth_me'],
];