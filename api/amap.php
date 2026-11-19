<?php
/**
 * amap.php —— 高德 Web 服务代理：密钥注入、参数签名、结果缓存、调用统计
 * 前端永远不接触 Key / 安全密钥
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

/** 高德签名：参数按 key 升序拼接 k=v&... 后追加私钥，取 md5 */
function amap_sign(array $params, $secret)
{
    ksort($params);
    $parts = [];
    foreach ($params as $k => $v) {
        $parts[] = $k . '=' . $v;
    }
    return md5(implode('&', $parts) . $secret);
}

function amap_log_call($service, $ok, $httpCode, $elapsedMs, $info = '', $cached = 0)
{
    try {
        db_exec(
            'INSERT INTO amap_calls (ts, service, ok, http_code, elapsed_ms, info, cached) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [time(), (string) $service, $ok ? 1 : 0, (int) $httpCode, (int) $elapsedMs, substr((string) $info, 0, 255), (int) $cached]
        );
    } catch (Throwable $e) {
        error_log('[amap_log_call] ' . $e->getMessage());
    }
}

/**
 * 代理调用高德接口（带缓存）
 * @return array{payload: array, cached: bool}
 */
function amap_proxy($service, array $params, $throwOnFail = true)
{
    $services = [
        'weather' => ['url' => 'https://restapi.amap.com/v3/weather/weatherInfo', 'ttl' => 'cache_ttl_weather', 'default' => 600],
        'tips'    => ['url' => 'https://restapi.amap.com/v3/assistant/inputtips', 'ttl' => 'cache_ttl_tips', 'default' => 300],
        'regeo'   => ['url' => 'https://restapi.amap.com/v3/geocode/regeo', 'ttl' => 'cache_ttl_regeo', 'default' => 86400],
        'geocode' => ['url' => 'https://restapi.amap.com/v3/geocode/geo', 'ttl' => 'cache_ttl_geocode', 'default' => 86400],
    ];
    if (!isset($services[$service])) {
        fail(1001, '不支持的高德接口', 400);
    }
    $conf = $services[$service];

    $key = trim((string) get_config('amap_web_key', ''));
    if ($key === '') {
        fail(2001, '高德 Web 服务 Key 未配置，请联系管理员', 503);
    }

    // 过滤空参数，避免签名与缓存键包含噪声
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = is_scalar($v) ? (string) $v : '';
        }
    }

    $ttl = get_config_int($conf['ttl'], $conf['default']);
    $cacheKey = 'amap:' . $service . ':' . md5(json_encode($params, JSON_UNESCAPED_UNICODE));

    if ($ttl > 0) {
        $row = db_one('SELECT payload, expires_at FROM amap_cache WHERE cache_key = ?', [$cacheKey]);
        if ($row && (int) $row['expires_at'] >= time()) {
            $decoded = json_decode($row['payload'], true);
            if (is_array($decoded)) {
                amap_log_call($service, true, 200, 0, 'cache-hit', 1);
                return ['payload' => $decoded, 'cached' => true];
            }
        }
    }

    $params['key'] = $key;
    $secret = trim((string) get_config('amap_web_secret', ''));
    if ($secret !== '') {
        $params['sig'] = amap_sign($params, $secret);
    }

    $url = $conf['url'] . '?' . http_build_query($params);
    $resp = http_get($url, 8);
    $json = json_decode($resp['body'], true);

    if (!is_array($json)) {
        amap_log_call($service, false, $resp['code'], $resp['elapsed_ms'], $resp['error'] ?: 'invalid-response', 0);
        fail(2002, '高德接口响应异常，请稍后重试', 502);
    }

    $ok = (isset($json['status']) && (string) $json['status'] === '1');
    if (!$ok) {
        amap_log_call($service, false, $resp['code'], $resp['elapsed_ms'], (string) ($json['info'] ?? 'unknown'), 0);
        fail(2003, '高德接口暂时不可用', 502);
    }

    amap_log_call($service, true, $resp['code'], $resp['elapsed_ms'], 'ok', 0);

    if ($ttl > 0) {
        try {
            db_exec(
                'INSERT OR REPLACE INTO amap_cache (cache_key, payload, expires_at, created_at) VALUES (?, ?, ?, ?)',
                [
                    $cacheKey,
                    json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    time() + $ttl,
                    time(),
                ]
            );
        } catch (Throwable $e) {
            error_log('[amap_cache] ' . $e->getMessage());
        }
    }

    return ['payload' => $json, 'cached' => false];
}

/** 高德 JS API 前端配置（供 amap-js-proxy.php 使用，禁止直接输出到 HTML 源码） */
function amap_js_config()
{
    return [
        'key'          => trim((string) get_config('amap_js_key', '')),
        'securityCode' => trim((string) get_config('amap_js_security_code', '')),
    ];
}