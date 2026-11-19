<?php
/**
 * toml.php —— 轻量 TOML 配置层（无第三方依赖，兼容 PHP 7.2+）
 *
 * 支持本系统所需的 TOML 子集：
 * - 扁平键值：key = "string" / 123 / 1.5 / true / false
 * - 行注释与行尾注释（#）
 * - 基本字符串、字面量字符串
 * - 可选分节（[section] 会展平为 section_key，推荐直接使用扁平键）
 *
 * 不支持的语法会返回明确错误，避免静默丢配置。
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

/** 默认配置（与后台字段一一对应；读取后会归一化为字符串） */
function toml_default_config()
{
    return [
        'site_name'             => '天气中心',
        'app_version'           => '1.1.0',
        'timezone'              => 'Asia/Shanghai',
        'language'              => 'zh-CN',
        'default_city'          => '深圳',
        'auto_refresh'          => '300',
        'allow_origin'          => '',
        'trust_proxy'           => '0',
        'register_enabled'      => '1',

        'database_path'         => 'weather.db',

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

/** 序列化分组与字段顺序（仅用于生成可读的 config.toml） */
function toml_config_groups()
{
    return [
        '站点与运行' => [
            'site_name', 'app_version', 'timezone', 'language', 'default_city',
            'auto_refresh', 'allow_origin', 'trust_proxy', 'register_enabled',
        ],
        '数据库' => ['database_path'],
        '高德开放平台' => [
            'amap_web_key', 'amap_web_secret', 'amap_js_key',
            'amap_js_security_code', 'amap_daily_quota',
        ],
        '限流与配额' => [
            'guest_rate_limit', 'guest_amap_rate_limit', 'user_rate_limit',
            'rate_window', 'login_fail_limit', 'login_fail_window', 'guest_token_ttl',
        ],
        '缓存与数据清理' => [
            'cache_ttl_weather', 'cache_ttl_tips', 'cache_ttl_regeo',
            'cache_ttl_geocode', 'log_retain_days',
        ],
    ];
}

/** 布尔型配置键 */
function toml_bool_keys()
{
    return ['trust_proxy', 'register_enabled'];
}

/** 整数型配置键 */
function toml_int_keys()
{
    return [
        'auto_refresh', 'amap_daily_quota',
        'guest_rate_limit', 'guest_amap_rate_limit', 'user_rate_limit',
        'rate_window', 'login_fail_limit', 'login_fail_window', 'guest_token_ttl',
        'cache_ttl_weather', 'cache_ttl_tips', 'cache_ttl_regeo', 'cache_ttl_geocode',
        'log_retain_days',
    ];
}

/** 去除行尾注释，保留引号内的 # */
function toml_strip_comment($line)
{
    $out = '';
    $quote = '';
    $escaped = false;
    $length = strlen($line);
    for ($i = 0; $i < $length; $i++) {
        $ch = $line[$i];
        if ($quote !== '') {
            $out .= $ch;
            if ($quote === '"') {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
            }
            if ($ch === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            $out .= $ch;
            continue;
        }
        if ($ch === '#') {
            break;
        }
        $out .= $ch;
    }
    return trim($out);
}

/** 解析单个值 */
function toml_parse_value($value)
{
    $value = trim($value);
    if ($value === '') {
        throw new RuntimeException('配置值为空');
    }
    if ($value[0] === '"') {
        if (strlen($value) < 2 || substr($value, -1) !== '"') {
            throw new RuntimeException('字符串引号未闭合：' . $value);
        }
        $inner = substr($value, 1, -1);
        $decoded = json_decode('"' . $inner . '"');
        if (!is_string($decoded)) {
            throw new RuntimeException('字符串转义无效：' . $value);
        }
        return $decoded;
    }
    if ($value[0] === "'") {
        if (strlen($value) < 2 || substr($value, -1) !== "'") {
            throw new RuntimeException('字符串引号未闭合：' . $value);
        }
        return substr($value, 1, -1);
    }
    $lower = strtolower($value);
    if ($lower === 'true') {
        return true;
    }
    if ($lower === 'false') {
        return false;
    }
    if (preg_match('/^-?\d+$/', $value)) {
        return (int) $value;
    }
    if (preg_match('/^-?\d+\.\d+$/', $value)) {
        return (float) $value;
    }
    if ($value[0] === '[') {
        throw new RuntimeException('暂不支持数组配置：' . $value);
    }
    throw new RuntimeException('无法识别的配置值：' . $value);
}

/** 解析 TOML 内容为扁平键数组 */
function toml_parse($content)
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', (string) $content);
    $data = [];
    $section = '';
    $lines = preg_split('/\r\n|\r|\n/', $content);
    foreach ($lines as $index => $raw) {
        $line = toml_strip_comment($raw);
        if ($line === '') {
            continue;
        }
        if ($line[0] === '[') {
            if (!preg_match('/^\[([A-Za-z0-9_.\-]+)\]$/', $line, $match)) {
                throw new RuntimeException('分节格式错误（第 ' . ($index + 1) . ' 行）');
            }
            $section = preg_replace('/[^A-Za-z0-9_]/', '_', $match[1]) . '_';
            continue;
        }
        if (!preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.+)$/', $line, $match)) {
            throw new RuntimeException('键值格式错误（第 ' . ($index + 1) . ' 行）');
        }
        $key = $section . $match[1];
        $data[$key] = toml_parse_value($match[2]);
    }
    return $data;
}

/**
 * 读取配置文件（合并默认值）
 * 文件不存在时返回默认配置；解析失败时返回默认配置并记录错误。
 */
function toml_load_file($path)
{
    $GLOBALS['WEATHER_TOML_ERROR'] = '';
    $defaults = toml_default_config();
    if (!is_file($path)) {
        return $defaults;
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        $GLOBALS['WEATHER_TOML_ERROR'] = '配置文件不可读：' . $path;
        error_log('[config] ' . $GLOBALS['WEATHER_TOML_ERROR']);
        return $defaults;
    }
    try {
        $parsed = toml_parse($content);
    } catch (Throwable $e) {
        $GLOBALS['WEATHER_TOML_ERROR'] = $e->getMessage();
        error_log('[config] ' . $e->getMessage() . ' @ ' . $path);
        return $defaults;
    }
    return array_merge($defaults, $parsed);
}

/** 最近一次配置读写错误 */
function toml_last_error()
{
    return isset($GLOBALS['WEATHER_TOML_ERROR']) ? (string) $GLOBALS['WEATHER_TOML_ERROR'] : '';
}

/** 归一化为字符串，兼容现有 get_config 的字符串比较逻辑 */
function toml_normalize_config(array $raw)
{
    $normalized = [];
    foreach ($raw as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif ($value === null) {
            $value = '';
        } elseif (is_scalar($value)) {
            $value = (string) $value;
        } else {
            $value = '';
        }
        $normalized[(string) $key] = $value;
    }
    return $normalized;
}

function toml_escape_string($value)
{
    return str_replace(
        ["\\", "\"", "\n", "\r", "\t"],
        ["\\\\", "\\\"", "\\n", "\\r", "\\t"],
        (string) $value
    );
}

function toml_encode_value($key, $value, array $boolKeys, array $intKeys)
{
    if (isset($boolKeys[$key])) {
        return ($value === true || $value === 1 || $value === '1' || $value === 'true') ? 'true' : 'false';
    }
    if (isset($intKeys[$key])) {
        return (string) (int) $value;
    }
    if (is_array($value) || is_object($value)) {
        return '""';
    }
    return '"' . toml_escape_string($value) . '"';
}

/** 生成完整 TOML 文本（固定分组顺序，未知键追加在末尾） */
function toml_dump(array $config)
{
    $groups = toml_config_groups();
    $boolKeys = array_flip(toml_bool_keys());
    $intKeys = array_flip(toml_int_keys());
    $lines = [
        '# 天气系统配置（TOML）',
        '# 修改后下一次请求自动生效；也可在管理后台保存。',
        '# api/ 目录已整体禁止 Web 访问，本文件不会被直接下载。',
        '',
    ];
    $written = [];
    foreach ($groups as $title => $keys) {
        $lines[] = '# ===== ' . $title . ' =====';
        foreach ($keys as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $lines[] = $key . ' = ' . toml_encode_value($key, $config[$key], $boolKeys, $intKeys);
            $written[$key] = true;
        }
        $lines[] = '';
    }
    foreach ($config as $key => $value) {
        $key = (string) $key;
        if ($key === '' || $key[0] === '_' || isset($written[$key])) {
            continue;
        }
        $lines[] = $key . ' = ' . toml_encode_value($key, $value, $boolKeys, $intKeys);
    }
    return implode("\n", $lines) . "\n";
}

/** 确保备份目录存在且可写 */
function toml_backup_ready($backupDir)
{
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }
    return is_dir($backupDir) && is_writable($backupDir);
}

/** 只保留最近 $keep 份备份 */
function toml_prune_backups($backupDir, $keep)
{
    $keep = max(1, (int) $keep);
    $files = glob($backupDir . '/config-*.toml');
    if (!is_array($files)) {
        return;
    }
    sort($files);
    while (count($files) > $keep) {
        $old = array_shift($files);
        @unlink($old);
    }
}

/** 将配置内容写入带时间戳的备份文件 */
function toml_backup_content($content, $backupDir, $keep = 20)
{
    if (!toml_backup_ready($backupDir)) {
        return false;
    }
    $now = microtime(true);
    $stamp = date('Ymd-His', (int) $now) . '-' . sprintf('%06d', (int) (($now - floor($now)) * 1000000));
    $target = $backupDir . '/config-' . $stamp . '.toml';
    if (is_file($target)) {
        $target = $backupDir . '/config-' . $stamp . '-' . substr(md5(uniqid('', true)), 0, 4) . '.toml';
    }
    if (@file_put_contents($target, $content) === false) {
        return false;
    }
    @chmod($target, 0640);
    toml_prune_backups($backupDir, $keep);
    return true;
}

/** 备份当前配置文件 */
function toml_backup_copy($source, $backupDir, $keep = 20)
{
    if (!is_file($source)) {
        return false;
    }
    $content = @file_get_contents($source);
    if ($content === false) {
        return false;
    }
    return toml_backup_content($content, $backupDir, $keep);
}

/**
 * 外部修改保护：
 * 每次请求比较 config.toml 与快照，发现手动修改时先把上一版写入备份目录，再更新快照。
 * 后台保存会由 toml_save_file() 主动备份并更新快照，不会重复备份。
 */
function toml_guard_snapshot($path, $snapshot, $backupDir, $keep = 20)
{
    if (!is_file($path)) {
        return;
    }
    $current = @file_get_contents($path);
    if ($current === false) {
        return;
    }
    if (!is_file($snapshot)) {
        if (@file_put_contents($snapshot, $current) !== false) {
            @chmod($snapshot, 0640);
        }
        return;
    }
    $previous = @file_get_contents($snapshot);
    if ($previous === false) {
        @file_put_contents($snapshot, $current);
        return;
    }
    if ($previous !== $current) {
        if (!toml_backup_content($previous, $backupDir, $keep)) {
            error_log('[config] 自动备份失败，请检查目录写权限：' . $backupDir);
        }
        @file_put_contents($snapshot, $current);
        @chmod($snapshot, 0640);
    }
}

/** 原子保存：文件锁 + 临时文件 + rename + 备份 + 快照更新 */
function toml_save_file($path, array $config, $backupDir = '', $snapshot = '', $keep = 20)
{
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('配置目录不存在或不可写：' . $dir);
    }
    $lockPath = $path . '.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if ($lock !== false) {
            fclose($lock);
        }
        throw new RuntimeException('无法锁定配置文件：' . $lockPath);
    }
    $tmp = $path . '.tmp.' . getmypid();
    try {
        if (is_file($path)) {
            if ($backupDir !== '') {
                toml_backup_copy($path, $backupDir, $keep);
            }
            @copy($path, $path . '.bak');
        }
        $content = toml_dump($config);
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException('无法写入配置文件：' . $path);
        }
        if (!@rename($tmp, $path)) {
            throw new RuntimeException('无法替换配置文件：' . $path);
        }
        @chmod($path, 0644);
        if ($snapshot !== '') {
            @file_put_contents($snapshot, $content);
            @chmod($snapshot, 0640);
        }
        clearstatcache(true, $path);
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function toml_is_absolute_path($path)
{
    $path = (string) $path;
    if ($path === '') {
        return false;
    }
    if ($path[0] === '/' || $path[0] === '\\') {
        return true;
    }
    return strlen($path) >= 3
        && ctype_alpha($path[0])
        && $path[1] === ':'
        && ($path[2] === '/' || $path[2] === '\\');
}

/** 解析数据库/配置文件路径：空值回退默认，相对路径基于项目根目录 */
function toml_resolve_path($path, $root)
{
    $path = trim((string) $path);
    if ($path === '') {
        return rtrim($root, '/\\') . '/weather.db';
    }
    if (toml_is_absolute_path($path)) {
        return $path;
    }
    return rtrim($root, '/\\') . '/' . ltrim($path, '/\\');
}

function toml_valid_timezone($timezone)
{
    $timezone = trim((string) $timezone);
    return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true);
}