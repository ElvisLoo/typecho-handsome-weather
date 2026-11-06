<?php
/**
 * Typecho Handsome · 天气数据代理
 *
 * 零配置、免 API Key：服务端请求 Open-Meteo，文件缓存 15 分钟。
 * 不接收任何外部参数（坐标写死在下方常量里），无 SSRF / 注入面。
 * 上游失败时降级返回旧缓存，再不行才返回错误，前端会显示重试入口。
 *
 * 用法：把本文件放到 Typecho 站点根目录，改下面三个常量即可。
 * 许可：Open-Meteo 免费档限非商业用途且每日调用低于 10000 次，
 *       15 分钟缓存一天最多 96 次回源，商业使用请购买其商用许可。
 *       核对日期：2026-11-06
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$CITY = '本地';          // 卡片上显示的名字
$LAT  = 39.9042;         // 示例坐标，改成你自己的纬度
$LON  = 116.4074;        // 示例坐标，改成你自己的经度
$TTL  = 900;             // 服务端缓存 15 分钟
$CACHE = sys_get_temp_dir() . '/typecho_weather_' . md5($LAT . ',' . $LON) . '.json';

function wmo_desc($c) {
    // WMO Weather interpretation codes (WW)，按 Open-Meteo 官方文档 2026-11-06 核对
    $m = [
        0 => '晴', 1 => '大部晴朗', 2 => '局部多云', 3 => '阴',
        45 => '有雾', 48 => '雾凇', 51 => '小毛毛雨', 53 => '毛毛雨', 55 => '浓毛毛雨',
        56 => '冻毛毛雨', 57 => '浓冻毛毛雨', 61 => '小雨', 63 => '中雨', 65 => '大雨',
        66 => '冻雨', 67 => '强冻雨', 71 => '小雪', 73 => '中雪', 75 => '大雪', 77 => '米雪',
        80 => '阵雨', 81 => '中阵雨', 82 => '强阵雨', 85 => '阵雪', 86 => '强阵雪',
        95 => '雷雨', 96 => '雷雨伴小冰雹', 97 => '强雷雨', 99 => '雷雨伴大冰雹',
    ];
    return isset($m[$c]) ? $m[$c] : '未知';
}
function wind_dir($d) {
    $dirs = ['北', '东北', '东', '东南', '南', '西南', '西', '西北'];
    return $dirs[(int)round(((float)$d % 360) / 45) % 8];
}
function emit($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$old = @json_decode(@file_get_contents($CACHE), true);
// 手动刷新：距上次真实回源满 20 秒才允许跳过缓存，避免被反复点击刷爆上游
$force = isset($_GET['force']) && $_GET['force'] === '1';
$forceOk = $force && (!is_array($old) || !isset($old['_fetched']) || (time() - (int)$old['_fetched']) >= 20);
if (!$forceOk && is_array($old) && isset($old['_fetched']) && (time() - $old['_fetched']) < $TTL) {
    $age = time() - $old['_fetched'];
    unset($old['_fetched']);
    $old['cached'] = 1;
    $old['age'] = $age;
    emit($old);
}

// daily 字段按官方文档要求必须带 timezone；forecast_days=1 只要今天的高低温
$url = 'https://api.open-meteo.com/v1/forecast'
     . '?latitude=' . $LAT . '&longitude=' . $LON
     . '&current=temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,is_day,wind_speed_10m,wind_direction_10m'
     . '&daily=temperature_2m_max,temperature_2m_min'
     . '&timezone=Asia%2FShanghai&forecast_days=1';

$raw = null;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 9,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Typecho-Handsome-Weather/1.0',
    ]);
    $raw = curl_exec($ch);
    if (curl_errno($ch)) $raw = null;
} else {
    $ctx = stream_context_create(['http' => ['timeout' => 9, 'header' => 'User-Agent: Typecho-Handsome-Weather/1.0']]);
    $raw = @file_get_contents($url, false, $ctx);
}

$j = $raw ? json_decode($raw, true) : null;
if (is_array($j) && isset($j['current']['temperature_2m'])) {
    $c = $j['current'];
    $out = [
        'ok'      => 1,
        'city'    => $CITY,
        'temp'    => round((float)$c['temperature_2m'], 1),
        'feels'   => isset($c['apparent_temperature']) ? round((float)$c['apparent_temperature'], 1) : null,
        'code'    => (int)($c['weather_code'] ?? 0),
        'desc'    => wmo_desc((int)($c['weather_code'] ?? 0)),
        'is_day'  => (int)($c['is_day'] ?? 1),
        'humidity'=> isset($c['relative_humidity_2m']) ? (int)$c['relative_humidity_2m'] : null,
        'wind'    => isset($c['wind_speed_10m']) ? round((float)$c['wind_speed_10m']) : null,
        'winddir' => isset($c['wind_direction_10m']) ? wind_dir($c['wind_direction_10m']) : '',
        'high'    => isset($j['daily']['temperature_2m_max'][0]) ? round((float)$j['daily']['temperature_2m_max'][0]) : null,
        'low'     => isset($j['daily']['temperature_2m_min'][0]) ? round((float)$j['daily']['temperature_2m_min'][0]) : null,
        'time'    => isset($c['time']) ? substr($c['time'], 11, 5) : date('H:i'),
        'date'    => date('n月j日'),
        'source'  => 'Open-Meteo',
        'cached'  => 0,
        'stale'   => 0,
        'age'     => 0,
    ];
    $save = $out; $save['_fetched'] = time();
    @file_put_contents($CACHE, json_encode($save, JSON_UNESCAPED_UNICODE), LOCK_EX);
    emit($out);
}

// 上游失败：有旧缓存就降级返回，标 stale
if (is_array($old) && isset($old['ok']) && $old['ok'] == 1) {
    $staleAge = isset($old['_fetched']) ? time() - (int)$old['_fetched'] : 0;
    unset($old['_fetched']);
    $old['stale'] = 1;
    $old['cached'] = 1;
    $old['age'] = $staleAge;
    emit($old);
}
emit(['ok' => 0, 'error' => '天气服务暂不可用']);
