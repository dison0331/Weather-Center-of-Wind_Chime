<?php
/**
 * api_weather.php —— 天气查询、地点联想、逆地理、地理编码（全部经后端高德代理）
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

function wx_clip($text, $max)
{
    return text_cut((string) $text, (int) $max);
}

/** 天气查询（实时 + 未来预报） */
function wx_weather($identity)
{
    $city = wx_clip(str_param('city'), 50);
    if ($city === '') {
        fail(1001, '缺少城市参数', 400);
    }

    $adcode = preg_match('/^\d{6,12}$/', $city) ? $city : '';

    // 高德天气接口的实时数据（lives）只稳定支持 adcode；中文城市名先解析为 adcode
    if ($adcode === '') {
        try {
            $geo = amap_proxy('geocode', ['address' => $city]);
            $geocodes = isset($geo['payload']['geocodes']) && is_array($geo['payload']['geocodes'])
                ? $geo['payload']['geocodes'] : [];
            if (!empty($geocodes[0]['adcode']) && preg_match('/^\d{6,12}$/', (string) $geocodes[0]['adcode'])) {
                $adcode = (string) $geocodes[0]['adcode'];
            }
        } catch (Throwable $e) {
            error_log('[weather] 城市 adcode 解析失败：' . $e->getMessage());
        }
    }

    $res = amap_proxy('weather', ['city' => $adcode !== '' ? $adcode : $city, 'extensions' => 'all']);
    $p = $res['payload'];
    $lives = isset($p['lives']) && is_array($p['lives']) ? $p['lives'] : [];
    $forecasts = isset($p['forecasts']) && is_array($p['forecasts']) ? $p['forecasts'] : [];

    if ($adcode === '' && !empty($lives[0]['adcode'])) {
        $adcode = (string) $lives[0]['adcode'];
    }
    if ($adcode === '' && !empty($forecasts[0]['adcode'])) {
        $adcode = (string) $forecasts[0]['adcode'];
    }

    $cityName = $city;
    if (isset($lives[0]['city']) && is_string($lives[0]['city']) && $lives[0]['city'] !== '') {
        $cityName = $lives[0]['city'];
    } elseif (isset($forecasts[0]['city']) && is_string($forecasts[0]['city']) && $forecasts[0]['city'] !== '') {
        $cityName = $forecasts[0]['city'];
    }

    return [
        'cached'    => $res['cached'],
        'count'     => isset($p['count']) ? (int) $p['count'] : 0,
        'adcode'    => $adcode,
        'city'      => $cityName,
        'lives'     => $lives,
        'forecasts' => $forecasts,
    ];
}

/** 地点联想 */
function wx_tips($identity)
{
    $keywords = wx_clip(str_param('keywords'), 30);
    if ($keywords === '') {
        fail(1001, '请输入关键词', 400);
    }
    $res = amap_proxy('tips', ['keywords' => $keywords, 'citylimit' => 'true']);
    $tips = [];
    foreach (($res['payload']['tips'] ?? []) as $tip) {
        if (!is_array($tip)) {
            continue;
        }
        $name = trim((string) ($tip['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $tips[] = [
            'id'       => (string) ($tip['id'] ?? ''),
            'name'     => $name,
            'district' => (string) ($tip['district'] ?? ''),
            'adcode'   => (string) ($tip['adcode'] ?? ''),
            'address'  => (string) ($tip['address'] ?? ''),
            'location' => (string) ($tip['location'] ?? ''),
        ];
        if (count($tips) >= 12) {
            break;
        }
    }
    return ['cached' => $res['cached'], 'tips' => $tips];
}

/** 逆地理编码 */
function wx_regeo($identity)
{
    $location = str_param('location');
    if (!preg_match('/^-?\d{1,3}(\.\d+)?,-?\d{1,3}(\.\d+)?$/', $location)) {
        fail(1001, '坐标格式应为 经度,纬度', 400);
    }
    $res = amap_proxy('regeo', ['location' => $location, 'extensions' => 'base', 'radius' => '1000']);
    $re = $res['payload']['regeocode'] ?? [];
    return ['cached' => $res['cached'], 'regeocode' => $re];
}

/** 地理编码（城市名 → 坐标，用于地图标注） */
function wx_geocode($identity)
{
    $address = wx_clip(str_param('address'), 80);
    if ($address === '') {
        fail(1001, '缺少地址参数', 400);
    }
    $res = amap_proxy('geocode', ['address' => $address]);
    $list = [];
    foreach (($res['payload']['geocodes'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $list[] = [
            'formatted_address' => (string) ($item['formatted_address'] ?? ''),
            'province'          => (string) ($item['province'] ?? ''),
            'city'              => (string) ($item['city'] ?? ''),
            'district'          => (string) ($item['district'] ?? ''),
            'adcode'            => (string) ($item['adcode'] ?? ''),
            'location'          => (string) ($item['location'] ?? ''),
        ];
        if (count($list) >= 5) {
            break;
        }
    }
    return ['cached' => $res['cached'], 'geocodes' => $list];
}

return [
    'weather' => ['auth' => 'guest_token', 'rate' => true, 'amap' => true, 'handler' => 'wx_weather'],
    'tips'    => ['auth' => 'guest_token', 'rate' => true, 'amap' => true, 'handler' => 'wx_tips'],
    'regeo'   => ['auth' => 'guest_token', 'rate' => true, 'amap' => true, 'handler' => 'wx_regeo'],
    'geocode' => ['auth' => 'guest_token', 'rate' => true, 'amap' => true, 'handler' => 'wx_geocode'],
];