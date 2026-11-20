<?php
/**
 * api_update.php —— 管理后台在线更新引擎
 *
 * 更新源支持两种模式（后台系统配置 update_source_type）：
 * 1. manifest：HTTPS JSON 清单（update_manifest_url 填清单地址）
 *    {
 *      "version": "1.1.0.1023",
 *      "download_url": "https://example.com/weather-1.1.0.1023.zip",
 *      "sha256": "可选，64 位十六进制",
 *      "release_url": "https://github.com/.../releases/tag/...",
 *      "notes": "可选发布说明"
 *    }
 * 2. github：update_manifest_url 填 GitHub 仓库地址，自动读取最新 Release
 *    https://github.com/owner/repo 或 .../releases/latest
 *    版本号取 Release tag（四段 vX.Y.Z.N），需大于当前版本才会提示更新
 *
 * 更新流程：全量备份（代码/config/DB）→ 下载并校验 ZIP → 校验包结构 →
 * 替换代码（保留 config.toml/DB/config_backups/update_backups）→ 写入新版本号 →
 * HTTP 健康检查 → 失败自动回退。成功保留备份供手动回退。
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

/** 当前版本（纯数字） */
function update_current_version()
{
    return ltrim(trim((string) APP_VERSION), 'Vv');
}

function update_dir()
{
    return APP_ROOT . '/update_backups';
}

/** 确保更新备份目录存在、可写并带 Web 访问保护 */
function update_ensure_dir()
{
    $dir = update_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        fail(5000, '更新目录不可写，请检查 update_backups/ 权限（宝塔需 www:www）', 500);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
        );
    }
    return $dir;
}

/** 更新互斥锁，避免并发更新 */
function update_lock()
{
    $dir = update_ensure_dir();
    $fp = @fopen($dir . '/.update.lock', 'c');
    if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
        if ($fp !== false) {
            fclose($fp);
        }
        fail(1005, '已有更新任务正在执行，请稍后重试', 409);
    }
    return $fp;
}

function update_unlock($fp)
{
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/** 读取并校验更新清单（根据 update_source_type 选择来源） */
function update_manifest()
{
    $url = trim((string) get_config('update_manifest_url', ''));
    if ($url === '') {
        fail(2001, '未配置更新源地址，请先在系统配置中填写', 503);
    }
    if (!preg_match('#^https://#i', $url)) {
        fail(2001, '更新源地址必须使用 HTTPS', 400);
    }
    if (get_config('update_source_type', 'manifest') === 'github') {
        return update_manifest_from_github($url);
    }
    return update_manifest_from_json($url);
}

/** 从 GitHub 仓库地址中提取 owner / repo */
function update_github_repo($url)
{
    $patterns = array(
        '#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/?$#i',
        '#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/releases/latest/?$#i',
        '#^https://api\.github\.com/repos/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/releases/latest/?$#i',
    );
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $m)) {
            return array($m[1], $m[2]);
        }
    }
    fail(2002, 'GitHub 更新源应填写仓库地址，例如 https://github.com/owner/repo', 502);
}

/** 读取 GitHub 最新 Release，自动选择 ZIP 资产 */
function update_manifest_from_github($url)
{
    list($owner, $repo) = update_github_repo($url);
    $api = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases/latest';
    $response = http_get($api, 15);
    if ($response['code'] < 200 || $response['code'] >= 300) {
        fail(2002, 'GitHub 最新 Release 无法访问（HTTP ' . ($response['code'] ?: '0') . '）', 502);
    }
    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        fail(2002, 'GitHub Release 响应不是有效 JSON', 502);
    }
    if (!empty($data['message'])) {
        fail(2002, 'GitHub 返回：' . text_cut(trim((string) $data['message']), 200), 502);
    }

    $latest = ltrim(trim((string) ($data['tag_name'] ?? '')), 'Vv');
    if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,9}$/', $latest)) {
        fail(2002, 'GitHub Release tag 必须为四段式 vX.Y.Z.N', 502);
    }

    $assets = isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array();
    $chosen = null;
    foreach ($assets as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $name = (string) ($asset['name'] ?? '');
        $download = trim((string) ($asset['browser_download_url'] ?? ''));
        if ($download === '' || !preg_match('#^https://#i', $download) || !preg_match('/\.zip$/i', $name)) {
            continue;
        }
        if ($chosen === null) {
            $chosen = $asset;
        }
        if (stripos($name, $latest) !== false) {
            $chosen = $asset;
            break;
        }
    }
    if ($chosen === null) {
        fail(2002, 'GitHub Release 未找到 ZIP 资产，请上传完整项目 ZIP', 502);
    }

    $download = trim((string) ($chosen['browser_download_url'] ?? ''));
    $sha = '';
    $digest = strtolower(trim((string) ($chosen['digest'] ?? '')));
    if (preg_match('/^sha256:([a-f0-9]{64})$/', $digest, $m)) {
        $sha = $m[1];
    }
    $releaseUrl = trim((string) ($data['html_url'] ?? ''));
    if ($releaseUrl !== '' && !preg_match('#^https://#i', $releaseUrl)) {
        $releaseUrl = '';
    }
    $notes = trim((string) ($data['body'] ?? ''));
    if (text_len($notes) > 5000) {
        $notes = text_cut($notes, 5000);
    }

    return array(
        'version'      => $latest,
        'download_url' => $download,
        'sha256'       => $sha,
        'release_url'  => $releaseUrl,
        'notes'        => $notes,
    );
}

/** 读取并校验自建 JSON 清单 */
function update_manifest_from_json($url)
{
    $response = http_get($url, 10);
    if ($response['code'] < 200 || $response['code'] >= 300) {
        fail(2002, '更新清单无法访问（HTTP ' . ($response['code'] ?: '0') . '）', 502);
    }
    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        fail(2002, '更新清单不是有效 JSON', 502);
    }

    $latest = ltrim(trim((string) ($data['version'] ?? '')), 'Vv');
    if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,9}$/', $latest)) {
        fail(2002, '更新清单版本号必须为四段式 X.Y.Z.N', 502);
    }
    $download = trim((string) ($data['download_url'] ?? ''));
    if ($download === '' || !preg_match('#^https://#i', $download)) {
        fail(2002, '更新包下载地址必须为 HTTPS', 502);
    }
    $sha = strtolower(trim((string) ($data['sha256'] ?? '')));
    if ($sha !== '' && !preg_match('/^[a-f0-9]{64}$/', $sha)) {
        fail(2002, 'sha256 格式无效', 502);
    }
    $releaseUrl = trim((string) ($data['release_url'] ?? ''));
    if ($releaseUrl !== '' && !preg_match('#^https://#i', $releaseUrl)) {
        $releaseUrl = '';
    }
    $notes = trim((string) ($data['notes'] ?? ''));
    if (text_len($notes) > 5000) {
        $notes = text_cut($notes, 5000);
    }

    return [
        'version'      => $latest,
        'download_url' => $download,
        'sha256'       => $sha,
        'release_url'  => $releaseUrl,
        'notes'        => $notes,
    ];
}

/** 规范化路径，用于精确比较（路径不存在时仅统一分隔符） */
function update_normalize_path($path)
{
    $path = str_replace('\\', '/', (string) $path);
    $real = @realpath($path);
    if ($real !== false) {
        return rtrim(str_replace('\\', '/', $real), '/');
    }
    $parent = @realpath(dirname($path));
    if ($parent !== false) {
        return rtrim(str_replace('\\', '/', $parent), '/') . '/' . basename($path);
    }
    return rtrim($path, '/');
}

/** 更新时必须保留的文件（数据库/配置可能配置在子目录） */
function update_keep_files()
{
    $keep = [];
    foreach ([CONFIG_FILE, DB_FILE, DB_FILE . '-wal', DB_FILE . '-shm', API_DIR . '/.htaccess'] as $file) {
        $keep[update_normalize_path($file)] = true;
    }
    return $keep;
}

/** 更新时需要保护的顶层路径 */
function update_excluded_top()
{
    return [
        'config.toml',
        'config.toml.lock',
        'weather.db',
        'weather.db-wal',
        'weather.db-shm',
        'config_backups',
        'update_backups',
        '.git',
        '.gitignore',
        '.codeartsdoer',
        '.user.ini',
        '.htaccess',
    ];
}

/** 递归复制目录（按顶层路径排除受保护内容，并按绝对路径保留配置文件与数据库） */
function update_copy_tree($source, $target, array $skipTop = [], array $keep = [])
{
    $source = rtrim($source, '/\\');
    if (!is_dir($source)) {
        throw new RuntimeException('源目录不存在：' . $source);
    }
    $skip = [];
    foreach ($skipTop as $item) {
        $skip[$item] = true;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $relative = str_replace('\\', '/', substr($path, strlen($source) + 1));
        if ($relative === '' || strpos($relative, "\0") !== false) {
            continue;
        }
        $top = explode('/', $relative)[0];
        if (isset($skip[$top])) {
            continue;
        }
        if ($item->isLink()) {
            continue;
        }
        $destination = rtrim($target, '/\\') . '/' . $relative;
        if (isset($keep[update_normalize_path($path)]) || isset($keep[update_normalize_path($destination)])) {
            continue;
        }
        if ($item->isDir()) {
            if (!is_dir($destination) && !@mkdir($destination, 0755, true)) {
                throw new RuntimeException('无法创建目录：' . $destination);
            }
        } else {
            $parent = dirname($destination);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                throw new RuntimeException('无法创建目录：' . $parent);
            }
            if (!@copy($path, $destination)) {
                throw new RuntimeException('无法复制文件：' . $relative);
            }
        }
    }
}

/** 删除代码（保留配置、数据库、备份、Git、IDE、.user.ini） */
function update_clear_code()
{
    $skip = array_flip(update_excluded_top());
    $keep = update_keep_files();
    $items = scandir(APP_ROOT);
    if (!is_array($items)) {
        throw new RuntimeException('无法读取项目目录');
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || isset($skip[$item])) {
            continue;
        }
        update_delete_tree(APP_ROOT . '/' . $item, $keep);
    }
}

function update_delete_tree($path, array $keep = [])
{
    if (isset($keep[update_normalize_path($path)])) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            update_delete_tree($path . '/' . $item, $keep);
        }
    }
    @rmdir($path);
}

/** 备份数据库；优先 VACUUM INTO 一致性快照，失败回退文件复制 */
function update_db_snapshot($directory)
{
    if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
        throw new RuntimeException('无法创建数据库备份目录');
    }
    $target = $directory . '/weather.db';
    $mode = 'snapshot';
    try {
        $pdo = db();
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        if (is_file($target)) {
            @unlink($target);
        }
        $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
        if (!is_file($target) || filesize($target) <= 0) {
            throw new RuntimeException('VACUUM INTO 未生成文件');
        }
    } catch (Throwable $e) {
        $mode = 'files';
        @unlink($target);
        if (!@copy(DB_FILE, $target)) {
            throw new RuntimeException('数据库备份失败：' . $e->getMessage());
        }
        foreach (['-wal', '-shm'] as $suffix) {
            $file = DB_FILE . $suffix;
            if (is_file($file)) {
                @copy($file, $target . $suffix);
            }
        }
    }
    return $mode;
}

/** 从备份恢复数据库（恢复前关闭当前连接） */
function update_restore_db($directory, $mode)
{
    $source = rtrim($directory, '/\\') . '/weather.db';
    if (!is_file($source)) {
        throw new RuntimeException('备份数据库不存在');
    }
    try {
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) {
        // 忽略 checkpoint 失败，继续关闭连接
    }
    db(true);
    @unlink(DB_FILE . '-wal');
    @unlink(DB_FILE . '-shm');
    if (!@copy($source, DB_FILE)) {
        throw new RuntimeException('无法恢复数据库文件');
    }
    if ($mode === 'files') {
        foreach (['-wal', '-shm'] as $suffix) {
            $file = $source . $suffix;
            if (is_file($file)) {
                @copy($file, DB_FILE . $suffix);
            }
        }
    }
    clearstatcache(true, DB_FILE);
}

function update_read_meta($directory)
{
    $file = rtrim($directory, '/\\') . '/backup.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function update_write_meta($directory, array $meta)
{
    $meta['updated_at'] = date('Y-m-d H:i:s');
    @file_put_contents(
        rtrim($directory, '/\\') . '/backup.json',
        json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

/** 全量备份：代码 + 配置 + 数据库 */
function update_create_backup($fromVersion, $toVersion)
{
    $root = update_ensure_dir();
    $safeFrom = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $fromVersion);
    $directory = $root . '/backup-' . date('Ymd-His') . '-' . $safeFrom;
    if (is_dir($directory)) {
        $directory .= '-' . substr(md5(uniqid('', true)), 0, 4);
    }
    if (!@mkdir($directory, 0755, true)) {
        throw new RuntimeException('无法创建备份目录');
    }

    try {
        if (!@copy(CONFIG_FILE, $directory . '/config.toml')) {
            throw new RuntimeException('无法备份 config.toml');
        }
        $dbMode = update_db_snapshot($directory . '/db');
        update_copy_tree(APP_ROOT, $directory . '/code', update_excluded_top(), update_keep_files());
        $meta = [
            'from_version' => (string) $fromVersion,
            'to_version'   => (string) $toVersion,
            'created_at'   => date('Y-m-d H:i:s'),
            'status'       => 'pending',
            'db_mode'      => $dbMode,
        ];
        update_write_meta($directory, $meta);
    } catch (Throwable $e) {
        update_delete_tree($directory);
        throw $e;
    }

    return $directory;
}

function update_backup_dirs()
{
    $dir = update_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $items = glob($dir . '/backup-*', GLOB_ONLYDIR);
    if (!is_array($items)) {
        return [];
    }
    usort($items, function ($a, $b) {
        return strcmp(basename($b), basename($a));
    });
    return $items;
}

/** 最近一次可用于回退的成功备份 */
function update_latest_backup()
{
    foreach (update_backup_dirs() as $directory) {
        $meta = update_read_meta($directory);
        if (!$meta || ($meta['status'] ?? '') !== 'success') {
            continue;
        }
        return [
            'dir'          => basename($directory),
            'from_version' => (string) ($meta['from_version'] ?? ''),
            'to_version'   => (string) ($meta['to_version'] ?? ''),
            'created_at'   => (string) ($meta['created_at'] ?? ''),
        ];
    }
    return null;
}

function update_mark_backup($directory, $status)
{
    $meta = update_read_meta($directory);
    if (!$meta) {
        return;
    }
    $meta['status'] = (string) $status;
    update_write_meta($directory, $meta);
}

function update_prune_backups($keep = 5)
{
    $directories = update_backup_dirs();
    foreach (array_slice($directories, max(1, (int) $keep)) as $directory) {
        update_delete_tree($directory);
    }
}

/** 下载更新包并校验大小/散列 */
function update_download($url, $destination, $sha256 = '')
{
    $parent = dirname($destination);
    if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
        throw new RuntimeException('无法创建下载目录');
    }

    if (function_exists('curl_init')) {
        $fp = @fopen($destination, 'wb');
        if ($fp === false) {
            throw new RuntimeException('无法写入更新包');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => 'WeatherUpdater/' . APP_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = (string) curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code < 200 || $code >= 300) {
            @unlink($destination);
            throw new RuntimeException('更新包下载失败：' . ($error ?: 'HTTP ' . $code));
        }
        if (!preg_match('#^https://#i', $effective)) {
            @unlink($destination);
            throw new RuntimeException('更新包重定向到非 HTTPS 地址');
        }
    } else {
        $body = @file_get_contents($url);
        if ($body === false || @file_put_contents($destination, $body) === false) {
            @unlink($destination);
            throw new RuntimeException('更新包下载失败（未启用 curl）');
        }
    }

    $size = @filesize($destination);
    if ($size === false || $size <= 0 || $size > 100 * 1024 * 1024) {
        @unlink($destination);
        throw new RuntimeException('更新包大小异常');
    }
    if ($sha256 !== '') {
        $actual = strtolower((string) @hash_file('sha256', $destination));
        if (!hash_equals($sha256, $actual)) {
            @unlink($destination);
            throw new RuntimeException('更新包 sha256 校验失败');
        }
    }
}

/** 安全解压 ZIP（拒绝绝对路径、..、盘符、符号链接） */
function update_extract_zip($zipPath, $directory)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP 未启用 ZipArchive 扩展，无法在线更新');
    }
    if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
        throw new RuntimeException('无法创建解压目录');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('更新包不是有效 ZIP');
    }
    $total = 0;
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '' || strpos($name, "\0") !== false) {
                throw new RuntimeException('更新包含非法路径');
            }
            $name = str_replace('\\', '/', $name);
            if (substr($name, -1) === '/') {
                continue;
            }
            if ($name[0] === '/' || preg_match('#^[A-Za-z]:#', $name) || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                throw new RuntimeException('更新包存在路径穿越：' . $name);
            }
            if (strpos($name, '__MACOSX/') === 0) {
                continue;
            }
            $stat = $zip->statIndex($i);
            $mode = ((int) ($stat['external'] ?? 0) >> 16) & 0xFFFF;
            if (($mode & 0xF000) === 0xA000) {
                throw new RuntimeException('更新包包含符号链接');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > 100 * 1024 * 1024) {
                throw new RuntimeException('更新包解压后过大');
            }
            if (!$zip->extractTo($directory, $name)) {
                throw new RuntimeException('解压失败：' . $name);
            }
        }
    } finally {
        $zip->close();
    }
}

function update_package_root($directory)
{
    if (is_file($directory . '/api.php')) {
        return $directory;
    }
    $items = scandir($directory);
    $dirs = [];
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || !is_dir($directory . '/' . $item)) {
                continue;
            }
            $dirs[] = $item;
        }
    }
    if (count($dirs) === 1 && is_file($directory . '/' . $dirs[0] . '/api.php')) {
        return $directory . '/' . $dirs[0];
    }
    throw new RuntimeException('更新包结构异常：未找到 api.php');
}

function update_validate_package($root, $version)
{
    $required = [
        'api.php',
        'amap-js-proxy.php',
        'api/config.php',
        'api/toml.php',
        'api/helpers.php',
        'api/db.php',
        'api/auth.php',
        'api/rate_limit.php',
        'api/amap.php',
        'api/api_auth.php',
        'api/api_weather.php',
        'api/api_cities.php',
        'api/api_admin.php',
        'api/api_update.php',
        'schema.sql',
        'VERSION',
        'index.html',
        'user.html',
        'admin.html',
        '404.html',
        'favicon.svg',
    ];
    foreach ($required as $file) {
        if (!is_file($root . '/' . $file)) {
            throw new RuntimeException('更新包缺少文件：' . $file);
        }
    }
    $packageVersion = ltrim(trim((string) @file_get_contents($root . '/VERSION')), 'Vv');
    if ($packageVersion !== $version) {
        throw new RuntimeException('更新包 VERSION 与清单不一致');
    }
}

function update_fetch_package(array $manifest)
{
    $root = update_ensure_dir();
    $temp = $root . '/.package-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6);
    if (!@mkdir($temp, 0755, true)) {
        throw new RuntimeException('无法创建更新临时目录');
    }
    try {
        $zip = $temp . '/package.zip';
        update_download($manifest['download_url'], $zip, $manifest['sha256']);
        $extract = $temp . '/extract';
        update_extract_zip($zip, $extract);
        $packageRoot = update_package_root($extract);
        update_validate_package($packageRoot, $manifest['version']);
        return ['root' => $packageRoot, 'temp' => $temp];
    } catch (Throwable $e) {
        update_delete_tree($temp);
        throw $e;
    }
}

function update_cleanup_package($package)
{
    if (is_array($package) && !empty($package['temp']) && is_dir($package['temp'])) {
        update_delete_tree($package['temp']);
    }
}

function update_health_url()
{
    $scheme = is_https() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
    }
    if ($host === '') {
        return '';
    }
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php');
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . $base . '/api.php?action=ping&_update=' . time();
}

/** 更新后的 HTTP 健康检查：ping 必须返回目标版本 */
function update_health($targetVersion)
{
    $url = update_health_url();
    if ($url === '') {
        return ['ok' => false, 'message' => '无法构造健康检查地址', 'url' => ''];
    }
    $response = http_get($url, 10);
    $json = json_decode($response['body'], true);
    if (!is_array($json) || (int) ($json['code'] ?? -1) !== 0) {
        return ['ok' => false, 'message' => '健康检查接口返回异常', 'url' => $url];
    }
    $version = ltrim((string) ($json['data']['version'] ?? ''), 'Vv');
    if ($version !== $targetVersion) {
        return ['ok' => false, 'message' => '版本未生效（当前 ' . ($version ?: '未知') . '）', 'url' => $url];
    }
    return ['ok' => true, 'message' => 'ok', 'url' => $url];
}

/** 从备份恢复代码/config/数据库，并做健康检查 */
function update_restore_backup($directory)
{
    $directory = rtrim($directory, '/\\');
    $meta = update_read_meta($directory);
    if (!$meta) {
        throw new RuntimeException('备份元数据缺失');
    }
    if (!is_dir($directory . '/code') || !is_file($directory . '/config.toml')) {
        throw new RuntimeException('备份内容不完整');
    }

    try {
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) {
        // 继续关闭连接
    }
    db(true);
    update_clear_code();
    update_copy_tree($directory . '/code', APP_ROOT, update_excluded_top(), update_keep_files());
    if (!@copy($directory . '/config.toml', CONFIG_FILE)) {
        throw new RuntimeException('无法恢复 config.toml');
    }
    update_restore_db($directory . '/db', (string) ($meta['db_mode'] ?? 'snapshot'));
    update_mark_backup($directory, 'rolled_back');

    $fromVersion = ltrim((string) ($meta['from_version'] ?? ''), 'Vv');
    $health = update_health($fromVersion);
    if (!$health['ok']) {
        throw new RuntimeException('回退后健康检查失败：' . $health['message']);
    }
}

/** 状态：当前版本、更新源是否配置、可回退节点 */
function admin_update_status($identity)
{
    $backup = update_latest_backup();
    $manifestUrl = trim((string) get_config('update_manifest_url', ''));
    return [
        'current'             => update_current_version(),
        'manifest_configured' => (bool) preg_match('#^https://#i', $manifestUrl),
        'last_backup'         => $backup,
        'backup_count'        => count(update_backup_dirs()),
    ];
}

/** 检查更新 */
function admin_update_check($identity)
{
    $manifest = update_manifest();
    $current = update_current_version();
    $available = version_compare($manifest['version'], $current, '>');
    return [
        'current'          => $current,
        'latest'           => $manifest['version'],
        'update_available' => $available,
        'release_url'      => $manifest['release_url'],
        'notes'            => $manifest['notes'],
        'checked_at'       => date('Y-m-d H:i:s'),
    ];
}

/** 执行更新：全量备份 + 更新 + 健康检查 + 失败自动回退 */
function admin_update_apply($identity)
{
    $lock = update_lock();
    $backupDir = '';
    $package = null;
    $started = false;
    try {
        $manifest = update_manifest();
        $current = update_current_version();
        if (!version_compare($manifest['version'], $current, '>')) {
            fail(1001, '当前已是最新版本', 400);
        }

        $backupDir = update_create_backup($current, $manifest['version']);
        $package = update_fetch_package($manifest);

        $started = true;
        update_clear_code();
        update_copy_tree($package['root'], APP_ROOT, update_excluded_top(), update_keep_files());
        set_config('app_version', $manifest['version']);

        $health = update_health($manifest['version']);
        if (!$health['ok']) {
            throw new RuntimeException('健康检查失败：' . $health['message']);
        }
        update_mark_backup($backupDir, 'success');
        update_prune_backups(5);

        return [
            'from_version' => $current,
            'current'      => $manifest['version'],
            'backup'       => basename($backupDir),
        ];
    } catch (Throwable $e) {
        if ($started && $backupDir !== '' && is_dir($backupDir)) {
            try {
                update_restore_backup($backupDir);
            } catch (Throwable $rollbackError) {
                error_log('[update] 自动回退失败：' . $rollbackError->getMessage());
                fail(5000, '更新失败，自动回退也失败：' . $rollbackError->getMessage(), 500);
            }
            fail(5000, '更新失败，已自动回退：' . $e->getMessage(), 500);
        }
        if ($backupDir !== '' && is_dir($backupDir)) {
            update_delete_tree($backupDir);
        }
        fail(5000, '更新失败（尚未替换代码）：' . $e->getMessage(), 500);
    } finally {
        update_cleanup_package($package);
        update_unlock($lock);
    }
}

/** 手动回退到最近一次成功更新前的版本 */
function admin_update_rollback($identity)
{
    $latest = update_latest_backup();
    if (!$latest) {
        fail(1001, '没有可回退的备份', 400);
    }
    $lock = update_lock();
    try {
        $directory = update_dir() . '/' . $latest['dir'];
        update_restore_backup($directory);
        return [
            'from_version' => $latest['to_version'],
            'current'      => $latest['from_version'],
        ];
    } catch (Throwable $e) {
        fail(5000, '回退失败：' . $e->getMessage(), 500);
    } finally {
        update_unlock($lock);
    }
}

return [
    'admin_update_status'   => ['auth' => 'admin', 'rate' => false, 'handler' => 'admin_update_status'],
    'admin_update_check'    => ['auth' => 'admin', 'rate' => false, 'handler' => 'admin_update_check'],
    'admin_update_apply'    => ['auth' => 'admin', 'rate' => false, 'handler' => 'admin_update_apply'],
    'admin_update_rollback' => ['auth' => 'admin', 'rate' => false, 'handler' => 'admin_update_rollback'],
];