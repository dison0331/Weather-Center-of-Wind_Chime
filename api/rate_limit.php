<?php
/**
 * rate_limit.php —— 分层限流中间件
 * 游客：IP + 指纹双维度（高德代理接口使用更严格阈值）
 * 登录用户：账号级配额（不受 IP/指纹限制）
 * 管理员：不参与限流
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

/** 计入限流的 action 过滤条件（初始化类接口不消耗配额） */
function rate_sql_exclude()
{
    return "action NOT IN ('ping','config_public','guest_init','me','logout','admin_login','register','login')";
}

function rate_check($identity, $action, $isAmap = false)
{
    if ($identity['type'] === 'user' && $identity['role'] === 'admin') {
        return;
    }

    $window = max(10, get_config_int('rate_window', 60));
    $since = time() - $window;

    if ($identity['type'] === 'user') {
        $limit = get_config_int('user_rate_limit', 120);
        if ($limit <= 0) {
            return;
        }
        $count = (int) db_val(
            'SELECT COUNT(*) FROM access_logs WHERE user_id = ? AND ts >= ? AND ' . rate_sql_exclude(),
            [$identity['user_id'], $since],
            0
        );
        rate_headers($limit, $count);
        if ($count >= $limit) {
            fail(1005, '账号请求配额已用尽，请稍后重试', 429);
        }
        return;
    }

    // 游客必须携带有效令牌才能访问业务接口
    if (empty($identity['token'])) {
        fail(1002, '请先初始化游客身份', 401);
    }

    $limit = $isAmap
        ? get_config_int('guest_amap_rate_limit', 20)
        : get_config_int('guest_rate_limit', 60);
    if ($limit <= 0) {
        return;
    }

    $ipCount = (int) db_val(
        'SELECT COUNT(*) FROM access_logs WHERE ip = ? AND ts >= ? AND ' . rate_sql_exclude(),
        [$identity['ip'], $since],
        0
    );
    $fpCount = (int) db_val(
        'SELECT COUNT(*) FROM access_logs WHERE fingerprint = ? AND ts >= ? AND ' . rate_sql_exclude(),
        [$identity['fingerprint'], $since],
        0
    );
    $count = max($ipCount, $fpCount);
    rate_headers($limit, $count);
    if ($count >= $limit) {
        fail(1005, '游客请求过于频繁，请登录后获得更高配额', 429);
    }
}

function rate_headers($limit, $count)
{
    if (!headers_sent()) {
        header('X-RateLimit-Limit: ' . (int) $limit);
        header('X-RateLimit-Remaining: ' . max(0, (int) $limit - (int) $count));
    }
}