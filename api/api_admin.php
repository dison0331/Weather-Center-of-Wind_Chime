<?php
/**
 * api_admin.php —— 管理后台接口：登录、看板、日志、用户、配置、黑名单
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

/** 管理员专属登录（要求角色为 admin） */
function adm_login($identity)
{
    $username = str_param('username');
    $password = (string) param('password', '');
    if ($username === '' || $password === '') {
        fail(1001, '请输入管理员账号和密码', 400);
    }
    login_fail_guard('admin_login', client_ip());

    $user = authenticate($username, $password, true);
    session_login($user, client_fingerprint());

    return ['user' => $user];
}

/** 数据看板汇总 */
function adm_overview($identity)
{
    $todayStart = strtotime('today');

    $requestsToday = (int) db_val('SELECT COUNT(*) FROM access_logs WHERE ts >= ?', [$todayStart], 0);
    $usersTotal = (int) db_val('SELECT COUNT(*) FROM users', [], 0);
    $usersBanned = (int) db_val('SELECT COUNT(*) FROM users WHERE status = 0', [], 0);
    $guestTokens = (int) db_val('SELECT COUNT(*) FROM guest_tokens WHERE expires_at > ?', [time()], 0);

    $amap = db_one(
        'SELECT
            SUM(CASE WHEN cached = 0 THEN 1 ELSE 0 END) AS real_calls,
            SUM(CASE WHEN cached = 1 THEN 1 ELSE 0 END) AS cache_hits,
            SUM(CASE WHEN cached = 0 AND ok = 1 THEN 1 ELSE 0 END) AS succ,
            SUM(CASE WHEN cached = 0 AND ok = 0 THEN 1 ELSE 0 END) AS faild,
            AVG(CASE WHEN cached = 0 THEN elapsed_ms END) AS avg_ms
         FROM amap_calls WHERE ts >= ?',
        [$todayStart]
    );
    $real = (int) ($amap['real_calls'] ?? 0);
    $succ = (int) ($amap['succ'] ?? 0);
    $faild = (int) ($amap['faild'] ?? 0);
    $quota = get_config_int('amap_daily_quota', 5000);

    $rows = db_all('SELECT ts FROM access_logs WHERE ts >= ?', [$todayStart]);
    $hourMap = [];
    foreach ($rows as $row) {
        $hour = (int) date('G', (int) $row['ts']);
        $hourMap[$hour] = ($hourMap[$hour] ?? 0) + 1;
    }
    $trend = [];
    for ($h = 0; $h < 24; $h++) {
        $trend[] = ['hour' => $h, 'count' => $hourMap[$h] ?? 0];
    }

    $recent = db_all(
        'SELECT ts, service, ok, http_code, elapsed_ms, info, cached FROM amap_calls ORDER BY id DESC LIMIT 15'
    );
    foreach ($recent as &$row) {
        $row['time'] = date('H:i:s', (int) $row['ts']);
        $row['ok'] = (int) $row['ok'];
        $row['cached'] = (int) $row['cached'];
    }
    unset($row);

    return [
        'requests_today' => $requestsToday,
        'users_total'    => $usersTotal,
        'users_banned'   => $usersBanned,
        'guest_tokens'   => $guestTokens,
        'amap' => [
            'real_calls'   => $real,
            'cache_hits'   => (int) ($amap['cache_hits'] ?? 0),
            'success'      => $succ,
            'failed'       => $faild,
            'success_rate' => $real > 0 ? round($succ / $real * 100, 1) : 100,
            'avg_ms'       => (int) round((float) ($amap['avg_ms'] ?? 0)),
            'quota'        => $quota,
            'quota_used'   => $real,
            'quota_left'   => $quota > 0 ? max(0, $quota - $real) : -1,
        ],
        'trend'        => $trend,
        'recent_calls' => $recent,
    ];
}

/** 访问日志分页查询 */
function adm_logs($identity)
{
    $page = max(1, int_param('page', 1));
    $size = min(100, max(5, int_param('size', 20)));
    $where = [];
    $args = [];

    $ip = trim((string) param('ip', ''));
    if ($ip !== '') {
        $where[] = 'ip LIKE ?';
        $args[] = '%' . $ip . '%';
    }
    $action = trim((string) param('action', ''));
    if ($action !== '') {
        $where[] = 'action LIKE ?';
        $args[] = '%' . $action . '%';
    }
    $uid = int_param('uid', 0);
    if ($uid > 0) {
        $where[] = 'user_id = ?';
        $args[] = $uid;
    }
    $from = trim((string) param('date_from', ''));
    if ($from !== '' && ($tsFrom = strtotime($from . ' 00:00:00')) !== false) {
        $where[] = 'ts >= ?';
        $args[] = $tsFrom;
    }
    $to = trim((string) param('date_to', ''));
    if ($to !== '' && ($tsTo = strtotime($to . ' 00:00:00')) !== false) {
        $where[] = 'ts < ?';
        $args[] = $tsTo + 86400;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = (int) db_val('SELECT COUNT(*) FROM access_logs' . $whereSql, $args, 0);
    $offset = ($page - 1) * $size;
    $items = db_all(
        'SELECT id, created_at, ip, fingerprint, user_id, action, method, status, code, elapsed_ms, ua
         FROM access_logs' . $whereSql . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $offset,
        $args
    );
    foreach ($items as &$row) {
        $row['id'] = (int) $row['id'];
        $row['status'] = (int) $row['status'];
        $row['code'] = (int) $row['code'];
        $row['elapsed_ms'] = (int) $row['elapsed_ms'];
        $row['user_id'] = $row['user_id'] === null ? null : (int) $row['user_id'];
    }
    unset($row);

    return ['page' => $page, 'size' => $size, 'total' => $total, 'pages' => (int) ceil($total / $size), 'items' => $items];
}

/** 账号列表 */
function adm_users($identity)
{
    $page = max(1, int_param('page', 1));
    $size = min(100, max(5, int_param('size', 20)));
    $keyword = trim((string) param('keyword', ''));
    $where = '';
    $args = [];
    if ($keyword !== '') {
        $where = ' WHERE u.username LIKE ? OR u.last_ip LIKE ?';
        $args = ['%' . $keyword . '%', '%' . $keyword . '%'];
    }
    $total = (int) db_val('SELECT COUNT(*) FROM users u' . $where, $args, 0);
    $offset = ($page - 1) * $size;
    $items = db_all(
        'SELECT u.id, u.username, u.role, u.status, u.last_ip, u.last_login_at, u.created_at,
                (SELECT COUNT(*) FROM user_cities c WHERE c.user_id = u.id) AS city_count
         FROM users u' . $where . ' ORDER BY u.id DESC LIMIT ' . $size . ' OFFSET ' . $offset,
        $args
    );
    foreach ($items as &$row) {
        $row['id'] = (int) $row['id'];
        $row['status'] = (int) $row['status'];
        $row['city_count'] = (int) $row['city_count'];
    }
    unset($row);
    return ['page' => $page, 'size' => $size, 'total' => $total, 'pages' => (int) ceil($total / $size), 'items' => $items];
}

/** 封禁 / 解封账号 */
function adm_user_status($identity)
{
    $id = int_param('id');
    $status = int_param('status', -1);
    if ($id <= 0 || !in_array($status, [0, 1], true)) {
        fail(1001, '参数无效', 400);
    }
    $target = db_one('SELECT id, role, status FROM users WHERE id = ?', [$id]);
    if (!$target) {
        fail(1001, '账号不存在', 404);
    }
    if ($id === $identity['user_id'] && $status === 0) {
        fail(1001, '不能封禁当前登录账号', 400);
    }
    if ($target['role'] === 'admin' && $status === 0) {
        $adminCount = (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 1", [], 0);
        if ($adminCount <= 1) {
            fail(1001, '不能封禁最后一个可用管理员', 400);
        }
    }
    db_exec('UPDATE users SET status = ? WHERE id = ?', [$status, $id]);
    return ['id' => $id, 'status' => $status];
}

/** 读取系统配置（config.toml） */
function adm_config_get($identity)
{
    $all = config_all(true);
    $result = [];
    foreach (array_keys(default_config()) as $key) {
        $result[$key] = $all[$key] ?? '';
    }
    $mtime = @filemtime(CONFIG_FILE);
    $result['updated_at'] = $mtime ? date('Y-m-d H:i:s', $mtime) : '';
    return ['config' => $result, 'file' => basename(CONFIG_FILE)];
}

/** 保存系统配置（写入 config.toml，支持热重载） */
function adm_config_save($identity)
{
    $input = param('config', []);
    if (is_string($input)) {
        $decoded = json_decode($input, true);
        $input = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($input) || empty($input)) {
        fail(1001, '没有可保存的配置', 400);
    }
    if (defined('CONFIG_PARSE_ERROR') && CONFIG_PARSE_ERROR !== '') {
        fail(1001, 'config.toml 存在语法错误，请先修复文件：' . CONFIG_PARSE_ERROR, 400);
    }

    $stringFields = [
        'site_name'             => 60,
        'app_version'           => 20,
        'timezone'              => 60,
        'default_city'          => 50,
        'allow_origin'          => 300,
        'database_path'         => 300,
        'amap_web_key'          => 200,
        'amap_web_secret'       => 200,
        'amap_js_key'           => 200,
        'amap_js_security_code' => 200,
    ];
    $enumFields = [
        'register_enabled' => ['0', '1'],
        'trust_proxy'      => ['0', '1'],
        'language'         => ['zh-CN', 'en-US'],
    ];
    $numberRanges = [
        'auto_refresh'          => [30, 86400],
        'amap_daily_quota'      => [0, 100000000],
        'guest_rate_limit'      => [0, 100000],
        'guest_amap_rate_limit' => [0, 100000],
        'user_rate_limit'       => [0, 100000],
        'rate_window'           => [10, 3600],
        'login_fail_limit'      => [0, 10000],
        'login_fail_window'     => [60, 86400],
        'guest_token_ttl'       => [60, 604800],
        'cache_ttl_weather'     => [0, 86400],
        'cache_ttl_tips'        => [0, 86400],
        'cache_ttl_regeo'       => [0, 604800],
        'cache_ttl_geocode'     => [0, 604800],
        'log_retain_days'       => [1, 365],
    ];

    $pairs = [];
    foreach ($stringFields as $key => $maxLen) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = trim((string) $input[$key]);
        if (text_len($value) > $maxLen) {
            fail(1001, $key . ' 内容过长', 400);
        }
        $pairs[$key] = $value;
    }
    foreach ($enumFields as $key => $allowed) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = (string) $input[$key];
        if (!in_array($value, $allowed, true)) {
            fail(1001, $key . ' 取值无效', 400);
        }
        $pairs[$key] = $value;
    }
    foreach ($numberRanges as $key => $range) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        if (!is_numeric($input[$key])) {
            fail(1001, $key . ' 必须为数字', 400);
        }
        $value = (int) $input[$key];
        if ($value < $range[0] || $value > $range[1]) {
            fail(1001, $key . ' 超出允许范围 ' . $range[0] . '~' . $range[1], 400);
        }
        $pairs[$key] = (string) $value;
    }

    if (isset($pairs['timezone']) && !toml_valid_timezone($pairs['timezone'])) {
        fail(1001, '时区无效，请填写 IANA 时区（如 Asia/Shanghai）', 400);
    }
    if (isset($pairs['app_version']) && $pairs['app_version'] !== ''
        && !preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $pairs['app_version'])) {
        fail(1001, '版本号格式应为 x.y.z', 400);
    }
    if (isset($pairs['database_path']) && $pairs['database_path'] !== '') {
        $target = toml_resolve_path($pairs['database_path'], APP_ROOT);
        $dbDir = dirname($target);
        if (!is_dir($dbDir) || !is_writable($dbDir)) {
            fail(1001, '数据库目录不存在或不可写：' . $dbDir, 400);
        }
        if (in_array(strtolower(pathinfo($target, PATHINFO_EXTENSION)), ['php', 'toml', 'sql', 'ini', 'md', 'html'], true)) {
            fail(1001, '数据库路径不能指向代码或配置文件', 400);
        }
        if (is_dir($target)) {
            fail(1001, '数据库路径不能指向目录', 400);
        }
        if (is_file($target) && !is_writable($target)) {
            fail(1001, '数据库文件不可写：' . $target, 400);
        }
    }
    if (isset($pairs['allow_origin']) && $pairs['allow_origin'] !== '') {
        foreach (explode(',', $pairs['allow_origin']) as $origin) {
            $origin = trim($origin);
            if ($origin !== '' && !preg_match('#^https?://[^\s/]+$#i', $origin)) {
                fail(1001, '跨域白名单格式应为 https://域名', 400);
            }
        }
    }
    if (empty($pairs)) {
        fail(1001, '没有可保存的配置', 400);
    }

    try {
        set_config_many($pairs);
    } catch (Throwable $e) {
        error_log('[config] 保存失败：' . $e->getMessage());
        fail(5000, '配置保存失败：' . $e->getMessage(), 500);
    }

    $deferred = [];
    foreach (['app_version', 'timezone', 'language', 'database_path'] as $key) {
        if (array_key_exists($key, $pairs)) {
            $deferred[] = $key;
        }
    }
    $mtime = @filemtime(CONFIG_FILE);
    return [
        'saved'      => array_keys($pairs),
        'deferred'   => $deferred,
        'config'     => config_all(true),
        'updated_at' => $mtime ? date('Y-m-d H:i:s', $mtime) : '',
        'file'       => basename(CONFIG_FILE),
    ];
}

/** 修改当前管理员密码 */
function adm_change_password($identity)
{
    $old = (string) param('old_password', '');
    $new = (string) param('new_password', '');
    if ($old === '' || $new === '') {
        fail(1001, '请填写当前密码和新密码', 400);
    }
    if (strlen($new) < 6 || strlen($new) > 72) {
        fail(1001, '新密码长度需为 6-72 位', 400);
    }
    $user = db_one('SELECT id, password_hash FROM users WHERE id = ?', [$identity['user_id']]);
    if (!$user || !password_verify($old, $user['password_hash'])) {
        fail(1007, '当前密码不正确', 401);
    }
    if (password_verify($new, $user['password_hash'])) {
        fail(1001, '新密码不能与当前密码相同', 400);
    }
    db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $identity['user_id']]);
    return ['changed' => true];
}

/** 黑名单列表 */
function adm_blacklist_list($identity)
{
    $items = db_all('SELECT id, type, value, reason, created_at FROM blacklist ORDER BY id DESC LIMIT 500');
    foreach ($items as &$row) {
        $row['id'] = (int) $row['id'];
    }
    unset($row);
    return ['items' => $items];
}

/** 添加黑名单（IP / 指纹） */
function adm_blacklist_add($identity)
{
    $type = str_param('type');
    $value = trim((string) param('value', ''));
    $reason = trim((string) param('reason', ''));

    if (!in_array($type, ['ip', 'fingerprint'], true) || $value === '') {
        fail(1001, '请提供有效的类型和值', 400);
    }
    if ($type === 'ip' && !filter_var($value, FILTER_VALIDATE_IP)) {
        fail(1001, 'IP 地址格式无效', 400);
    }
    if ($type === 'fingerprint' && !valid_fingerprint($value)) {
        fail(1001, '浏览器指纹格式无效', 400);
    }
    if (text_len($reason) > 100) {
        $reason = text_cut($reason, 100);
    }

    db_exec(
        'INSERT OR REPLACE INTO blacklist (type, value, reason, created_at) VALUES (?, ?, ?, ?)',
        [$type, $value, $reason, date('Y-m-d H:i:s')]
    );
    return ['type' => $type, 'value' => $value];
}

/** 删除黑名单 */
function adm_blacklist_delete($identity)
{
    $id = int_param('id');
    if ($id <= 0) {
        fail(1001, '缺少记录 ID', 400);
    }
    $stmt = db_exec('DELETE FROM blacklist WHERE id = ?', [$id]);
    if ($stmt->rowCount() === 0) {
        fail(1001, '记录不存在', 404);
    }
    return ['id' => $id];
}

return [
    'admin_login'            => ['auth' => 'public', 'rate' => false, 'handler' => 'adm_login'],
    'admin_overview'         => ['auth' => 'admin', 'rate' => false, 'handler' => 'adm_overview'],
    'admin_logs'             => ['auth' => 'admin', 'rate' => false, 'handler' => 'adm_logs'],
    'admin_users'            => ['auth' => 'admin', 'rate' => false, 'handler' => 'adm_users'],
    'admin_user_status'      => ['auth' => 'admin', 'rate' => false, 'handler' => 'adm_user_status'],
    'admin_config_get'       => ['auth' => 'admin', 'rate' => false, 'handler' => 'adm_config_get'],
    'admin_config_save'      => ['auth' => 'admin', 'rate' => false, 'handler' => 'adm_config_save'],
    'admin_change_password'  => ['auth' => 'admin', 'rate' => false, 'handler' => 'adm_change_password'],
    'admin_blacklist_list'   => ['auth' => 'admin', 'rate' => false, 'handler' => 'adm_blacklist_list'],
    'admin_blacklist_add'    => ['auth' => 'admin', 'rate' => false, 'handler' => 'adm_blacklist_add'],
    'admin_blacklist_delete' => ['auth' => 'admin', 'rate' => false, 'handler' => 'adm_blacklist_delete'],
];