<?php
/**
 * amap-js-proxy.php —— 高德 JS API 安全配置与 SDK 动态加载器（根目录入口）
 * 前端通过 <script src="amap-js-proxy.php"></script> 引入，
 * Key / 安全密钥从数据库读取，前端源码中不出现任何明文密钥。
 */
define('IN_PROJECT', true);

if (!is_file(__DIR__ . '/api/config.php')) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code(500);
    echo "window.__AMAP_READY = Promise.reject(new Error('后端模块目录 api/ 缺失'));";
    exit;
}

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/amap.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

db_init();

if (!apply_cors()) {
    http_response_code(403);
    echo "window.__AMAP_READY = Promise.reject(new Error('非法请求来源'));";
    exit;
}

$js = amap_js_config();
if ($js['key'] === '') {
    echo "window.__AMAP_READY = Promise.reject(new Error('地图服务未配置'));";
    exit;
}

$keyJson = json_encode($js['key'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$codeJson = json_encode($js['securityCode'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

echo "window._AMapSecurityConfig = { securityJsCode: {$codeJson} };\n";
echo "window.__AMAP_READY = new Promise(function (resolve, reject) {\n";
echo "  var s = document.createElement('script');\n";
echo "  s.async = true;\n";
echo "  s.src = 'https://webapi.amap.com/maps?v=2.0&key=' + encodeURIComponent({$keyJson});\n";
echo "  s.onload = function () { resolve(window.AMap || null); };\n";
echo "  s.onerror = function () { reject(new Error('地图 SDK 加载失败')); };\n";
echo "  document.head.appendChild(s);\n";
echo "});\n";