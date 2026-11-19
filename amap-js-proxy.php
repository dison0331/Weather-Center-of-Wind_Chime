<?php
/**
 * amap-js-proxy.php —— 高德 JS API 安全配置与 SDK 动态加载器
 * 前端通过 <script src="amap-js-proxy.php"></script> 引入，
 * Key / 安全密钥从数据库读取，前端源码中不出现任何明文密钥。
 */
define('IN_PROJECT', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/amap.php';

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