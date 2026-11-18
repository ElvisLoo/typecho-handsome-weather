<?php
/**
 * Typecho Handsome · 天气数据代理（按访客 IP 定位）
 *
 * 零配置、免 API Key。服务端从 CDN 回源头取访客 IP → 查归属地坐标 →
 * 请求 Open-Meteo，按坐标分桶缓存 15 分钟。每个访客看到的都是自己那儿的天气。
 *
 * 安全：不接收客户端传入的坐标/URL，IP 先经 filter_var 校验才拼进上游地址，
 *       坐标只接受第三方返回的合法数值，无 SSRF / 参数注入面。
 *       转发头（EO-Connecting-IP 等）只在 REMOTE_ADDR 命中 $TRUSTED_PROXIES 时采信，
 *       防止伪造头污染缓存 / 刷上游；另按 IP 段限流。
 * 降级：定位失败 → 回退常量坐标；上游失败 → 返回旧缓存（stale）；再不行才报错。
 * 时区：timezone=auto，境外访客的「今日高低温 / 更新时间」按当地算。
 *
 * 用法：放到 Typecho 站点根目录，只改下面 $FALLBACK_* 三个常量即可。
 * 许可：Open-Meteo 免费档限非商业用途且每日调用低于 10000 次。
 *       核对日期：2026-11-06（PHP 8.5 兼容修复：2026-11-12）
 */
// PHP 8.5 会把 Deprecated / Warning 以 "<br /><b>..." 混进响应体，污染 JSON。
// 接口只认纯 JSON；错误仍写入 error_log，不回显给浏览器。
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
// 万一 CDN 将来改成按 URL 缓存，Vary 保证不同访客不会串味
header('Vary: EO-Connecting-IP, X-Forwarded-For');
header('X-Content-Type-Options: nosniff');

// 定位全部失败时的兜底（开源包里是示例坐标；线上换成自己常驻地）
$FALLBACK_CITY = '本地';
$FALLBACK_LAT  = 39.9042;
$FALLBACK_LON  = 116.4074;

$TTL      = 900;    // 天气缓存 15 分钟
$GEO_TTL  = 86400;  // IP 段定位结果缓存 24 小时
$GEO_DIR  = sys_get_temp_dir() . '/jg_geo_v2';   // 每个 IP 段一个文件，避免整表读写竞态
$GEO_FILE = sys_get_temp_dir() . '/jg_geo_prefix_v1.json'; // 旧总表，仅一次性迁移读取
// 可信 CDN 回源段：只有 REMOTE_ADDR 落在这里面，才采信 EO-Connecting-IP 等转发头。
// 留空 = 不信任任何转发头（最安全，直连时按 REMOTE_ADDR 定位）。
// 线上 EdgeOne 回源实测 114.66.247.140，可配 ['114.66.247.0/24']。
$TRUSTED_PROXIES = [];
// 每个 IP 段每分钟的定位回源限流（未命中缓存时计数）
$RATE_MAX    = 30;
$RATE_WINDOW = 60;

function emit($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

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
    // 以方位角为中心 ±22.5° 归属，比 round() 更贴合气象惯例
    return $dirs[(int)floor((((float)$d % 360) + 22.5) / 45) % 8];
}
function http_get($url, $timeout) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Typecho-Handsome-Weather/1.1',
        ]);
        $raw = curl_exec($ch);
        if (curl_errno($ch)) $raw = null;
        // PHP 8.5: curl_close() 已废弃（自 8.0 起无实际效果），句柄随作用域自动释放
        return $raw;
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => $timeout,
        'header'  => 'User-Agent: Typecho-Handsome-Weather/1.1',
    ]]);
    return @file_get_contents($url, false, $ctx);
}

/** 只认公网 IP，避免把伪造/私有地址拿去查上游 */
function public_ip($s) {
    $ip = trim((string)$s);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return '';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return '';
    return $ip;
}
/** CIDR 归属判断（IPv4/IPv6），用于识别可信 CDN 回源 IP */
function ip_in_cidr($ip, $cidr) {
    if (strpos($cidr, '/') === false) return false;
    list($net, $bits) = explode('/', $cidr, 2);
    $bits = (int)$bits;
    $ipP = @inet_pton($ip);
    $netP = @inet_pton($net);
    if ($ipP === false || $netP === false) return false;
    if (strlen($ipP) !== strlen($netP)) return false;
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($bytes && substr($ipP, 0, $bytes) !== substr($netP, 0, $bytes)) return false;
    if ($rem) {
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        if ((ord($ipP[$bytes]) & ord($mask)) !== (ord($netP[$bytes]) & ord($mask))) return false;
    }
    return true;
}
function is_trusted_proxy($ip) {
    global $TRUSTED_PROXIES;
    if ($ip === '') return false;
    foreach ($TRUSTED_PROXIES as $c) {
        if (ip_in_cidr($ip, $c)) return true;
    }
    return false;
}
/**
 * 取访客 IP。转发头（EO-Connecting-IP / X-Forwarded-For…）可被任意伪造，
 * 只有当回源 IP 落在可信 CDN 段内才采信；公网直连一律以 REMOTE_ADDR 为准，
 * 否则伪造头能污染 IP 段缓存（24h）并刷爆上游配额。
 * 本机 / 内网自检（REMOTE_ADDR 为私网）仍按原顺序采信转发头。
 */
function client_ip() {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $remotePub = public_ip($remote);
    if ($remotePub !== '' && !is_trusted_proxy($remotePub)) {
        return $remotePub; // 公网直连且非可信回源：不信任任何转发头
    }
    $keys = ['HTTP_EO_CONNECTING_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR',
             'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (empty($_SERVER[$k])) continue;
        $v = (string)$_SERVER[$k];
        if (strpos($v, ',') !== false) {
            foreach (explode(',', $v) as $part) {
                $ip = public_ip($part);
                if ($ip) return $ip;
            }
        } else {
            $ip = public_ip($v);
            if ($ip) return $ip;
        }
    }
    return '';
}
/** 同一个 C 段基本同城，按段缓存可避免为每个 IP 生成一个文件 */
function ip_prefix($ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        return $p[0] . '.' . $p[1] . '.' . $p[2];
    }
    $p = explode(':', $ip);
    return implode(':', array_slice($p, 0, 4));
}
function geo_all() {
    global $GEO_FILE;
    $raw = @file_get_contents($GEO_FILE);
    $a = $raw ? json_decode($raw, true) : null;
    return is_array($a) ? $a : [];
}
function geo_file($key) {
    global $GEO_DIR;
    return $GEO_DIR . '/' . md5($key) . '.json';
}
function geo_get($key) {
    $d = @json_decode(@file_get_contents(geo_file($key)), true);
    if (is_array($d)) return $d;
    // 兼容旧总表：只读取该 key，不写回总表
    $all = geo_all();
    return $all[$key] ?? null;
}
function geo_gc() {
    global $GEO_DIR, $GEO_TTL;
    $now = time();
    foreach (glob($GEO_DIR . '/*.json') ?: [] as $f) {
        $d = @json_decode(@file_get_contents($f), true);
        if (is_array($d) && ($now - (int)($d['ts'] ?? 0)) > $GEO_TTL) @unlink($f);
    }
}
function geo_put($key, $val) {
    global $GEO_DIR;
    @is_dir($GEO_DIR) || @mkdir($GEO_DIR, 0755, true);
    $val['ts'] = time();
    @file_put_contents(geo_file($key), json_encode($val, JSON_UNESCAPED_UNICODE), LOCK_EX);
    // 概率清理过期文件，控制目录体积
    if (function_exists('random_int') && random_int(1, 100) === 1) geo_gc();
}
/** 按 IP 段的本地限流：窗口内累计超过 $max 次返回 true */
function rate_limit_hit($key, $max, $window) {
    $f = sys_get_temp_dir() . '/jg_rl_' . md5($key) . '.json';
    $now = time();
    $d = @json_decode(@file_get_contents($f), true);
    if (!is_array($d) || ($now - (int)($d['t'] ?? 0)) >= $window) $d = ['t' => $now, 'n' => 0];
    $d['n']++;
    @file_put_contents($f, json_encode($d), LOCK_EX);
    return $d['n'] > $max;
}
/**
 * 国标行政区划码前 2 位 → ipwho.is 的 region_code。
 * 两个 IP 库对同一个 IP 可能给出完全不同的归属地（实测 119.29.29.29：
 * ipwho.is 说广东广州，pconline 说北京市——腾讯的注册地）。
 * 坐标和城市名必须同源，否则会出现「北京市 30°C」这种错配。
 */
$PROV_CODE = [
    '11' => 'BJ', '12' => 'TJ', '13' => 'HE', '14' => 'SX', '15' => 'NM',
    '21' => 'LN', '22' => 'JL', '23' => 'HL',
    '31' => 'SH', '32' => 'JS', '33' => 'ZJ', '34' => 'AH', '35' => 'FJ', '36' => 'JX', '37' => 'SD',
    '41' => 'HA', '42' => 'HB', '43' => 'HN', '44' => 'GD', '45' => 'GX', '46' => 'HI',
    '50' => 'CQ', '51' => 'SC', '52' => 'GZ', '53' => 'YN', '54' => 'XZ',
    '61' => 'SN', '62' => 'GS', '63' => 'QH', '64' => 'NX', '65' => 'XJ',
    '71' => 'TW', '81' => 'HK', '82' => 'MO',
];
/** pconline 是国内库、给中文城市名，但返回 GBK 且没有经纬度，只用来补名字 */
function cn_city($ip, $regionCode) {
    global $PROV_CODE;
    $raw = http_get('https://whois.pconline.com.cn/ipJson.jsp?ip=' . rawurlencode($ip) . '&json=true', 3);
    if (!$raw) return '';
    if (function_exists('mb_convert_encoding')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'GBK');
    } elseif (function_exists('iconv')) {
        $raw = @iconv('GBK', 'UTF-8//IGNORE', $raw);
    } else {
        return '';
    }
    $j = json_decode(trim($raw), true);
    if (!is_array($j)) return '';
    // 省份对不上就判定错配，退回 ipwho.is 自己的城市名（经中文映射表）
    $pc = substr((string)($j['proCode'] ?? ''), 0, 2);
    if ($regionCode !== '' && $pc !== ''
        && (!isset($PROV_CODE[$pc]) || $PROV_CODE[$pc] !== $regionCode)) {
        return '';
    }
    $c = trim((string)($j['city'] ?? ''));
    if ($c === '' || strpos($c, '未知') !== false) return '';
    return function_exists('mb_substr') ? mb_substr($c, 0, 12) : substr($c, 0, 24);
}
/** 常见英文城市兜底（pconline 偶发失败时用） */
$CN_NAME = [
    'Beijing' => '北京', 'Shanghai' => '上海', 'Tianjin' => '天津', 'Chongqing' => '重庆',
    'Nanjing' => '南京', 'Suzhou' => '苏州', 'Kunshan' => '昆山', 'Wuxi' => '无锡',
    'Hangzhou' => '杭州', 'Ningbo' => '宁波', 'Hefei' => '合肥', 'Jinan' => '济南',
    'Qingdao' => '青岛', 'Zhengzhou' => '郑州', 'Wuhan' => '武汉', 'Changsha' => '长沙',
    'Guangzhou' => '广州', 'Shenzhen' => '深圳', 'Dongguan' => '东莞', 'Foshan' => '佛山',
    'Chengdu' => '成都', 'Xian' => '西安', "Xi'an" => '西安', 'Shenyang' => '沈阳',
    'Dalian' => '大连', 'Harbin' => '哈尔滨', 'Changchun' => '长春', 'Fuzhou' => '福州',
    'Xiamen' => '厦门', 'Nanchang' => '南昌', 'Kunming' => '昆明', 'Guiyang' => '贵阳',
    'Lanzhou' => '兰州', 'Taiyuan' => '太原', 'Shijiazhuang' => '石家庄', 'Nanning' => '南宁',
    'Haikou' => '海口', 'Hong Kong' => '中国香港', 'Macao' => '中国澳门', 'Taipei' => '中国台湾',
    'Changzhou' => '常州', 'Xuzhou' => '徐州', 'Nantong' => '南通', 'Yancheng' => '盐城',
    'Yangzhou' => '扬州', 'Zhenjiang' => '镇江', 'Taizhou' => '泰州', 'Jiangyin' => '江阴',
    'Jiaxing' => '嘉兴', 'Shaoxing' => '绍兴', 'Wenzhou' => '温州', 'Jinhua' => '金华',
    'Huzhou' => '湖州', 'Taizhou Zhejiang' => '台州', 'Zhuhai' => '珠海', 'Zhongshan' => '中山',
    'Huizhou' => '惠州', 'Shantou' => '汕头', 'Quanzhou' => '泉州', 'Zhangzhou' => '漳州',
    'Tangshan' => '唐山', 'Baoding' => '保定', 'Langfang' => '廊坊', 'Cangzhou' => '沧州',
    'Yantai' => '烟台', 'Weifang' => '潍坊', 'Zibo' => '淄博', 'Linyi' => '临沂',
    'Luoyang' => '洛阳', 'Nanyang' => '南阳', 'Wuhu' => '芜湖', 'Mianyang' => '绵阳',
    'Xianyang' => '咸阳', 'Baoji' => '宝鸡', 'Datong' => '大同', 'Guilin' => '桂林',
    'Tokyo' => '东京', 'Osaka' => '大阪', 'Seoul' => '首尔', 'Singapore' => '新加坡',
];
function city_name($en, $country, $ip, $regionCode, $usePconline) {
    global $CN_NAME;
    if ($country === 'CN' && $usePconline) {
        $cn = cn_city($ip, $regionCode);
        if ($cn !== '') return $cn;
    }
    if ($en === '') return '';
    return isset($CN_NAME[$en]) ? $CN_NAME[$en] : $en;
}
/** 主 ipwho.is（HTTPS/免 key/带坐标），备 ipinfo.io；都失败返回 null */
function locate($ip) {
    $geoRaw = http_get('https://ipwho.is/' . rawurlencode($ip), 4);
    $j = is_string($geoRaw) ? json_decode($geoRaw, true) : null;
    if (is_array($j) && !empty($j['success'])
        && is_numeric($j['latitude'] ?? null) && is_numeric($j['longitude'] ?? null)) {
        return [
            'city' => city_name(
                trim((string)($j['city'] ?? '')),
                (string)($j['country_code'] ?? ''),
                $ip,
                strtoupper(trim((string)($j['region_code'] ?? ''))),
                true
            ),
            'lat'  => (float)$j['latitude'],
            'lon'  => (float)$j['longitude'],
            'geo'  => 'ipwho.is',
        ];
    }
    $geoRaw2 = http_get('https://ipinfo.io/' . rawurlencode($ip) . '/json', 4);
    $j = is_string($geoRaw2) ? json_decode($geoRaw2, true) : null;
    if (is_array($j) && isset($j['loc']) && strpos($j['loc'], ',') !== false) {
        list($la, $lo) = explode(',', $j['loc'], 2);
        if (is_numeric($la) && is_numeric($lo)) {
            return [
                'city' => city_name(trim((string)($j['city'] ?? '')), (string)($j['country'] ?? ''), $ip, '', false),
                'lat'  => (float)$la,
                'lon'  => (float)$lo,
                'geo'  => 'ipinfo.io',
            ];
        }
    }
    return null;
}

// ---------- 1. 定位 ----------
$ip     = client_ip();
$geo    = null;
$cachedGeo = null;
if ($ip !== '') {
    $key = ip_prefix($ip);
    $g = geo_get($key);
    if (is_array($g) && (time() - (int)($g['ts'] ?? 0)) < $GEO_TTL) {
        $cachedGeo = $g;
    } else {
        if (rate_limit_hit('geo:' . $key, $RATE_MAX, $RATE_WINDOW)) {
            emit(['ok' => 0, 'error' => '请求过于频繁，请稍后再试']);
        }
        $g = locate($ip);
        if ($g && abs($g['lat']) <= 90 && abs($g['lon']) <= 180) {
            $geo = $g;
            geo_put($key, $g);
        }
    }
    if (!$geo && $cachedGeo) $geo = $cachedGeo;
}

if ($geo) {
    $CITY = $geo['city'] !== '' ? $geo['city'] : '当地';
    $LAT  = round($geo['lat'], 3);
    $LON  = round($geo['lon'], 3);
    $GEOSRC = $geo['geo'];
    $FALLBACK_FLAG = 0;
} else {
    $CITY = $FALLBACK_CITY;
    $LAT  = $FALLBACK_LAT;
    $LON  = $FALLBACK_LON;
    $GEOSRC = 'fallback';
    $FALLBACK_FLAG = 1;
}

// ---------- 2. 天气缓存 ----------
$CACHE = sys_get_temp_dir() . '/jg_weather_' . md5($LAT . ',' . $LON) . '.json';
$old = @json_decode(@file_get_contents($CACHE), true);
// 手动刷新：距上次真实回源满 20 秒才允许跳过缓存，避免被反复点击刷爆上游
$force = isset($_GET['force']) && $_GET['force'] === '1';
$forceOk = $force && (!is_array($old) || !isset($old['_fetched']) || (time() - (int)$old['_fetched']) >= 20);
if (!$forceOk && is_array($old) && isset($old['_fetched']) && (time() - $old['_fetched']) < $TTL) {
    $age = time() - $old['_fetched'];
    unset($old['_fetched']);
    $old['cached'] = 1;
    $old['age'] = $age;
    // 同一坐标桶可能被不同城市名命中，位置字段始终以本次访客为准
    $old['city'] = $CITY;
    $old['geo'] = $GEOSRC;
    $old['fallback'] = $FALLBACK_FLAG;
    emit($old);
}

// timezone=auto：Open-Meteo 按坐标选当地时区，境外访客的高低温与更新时间才对
$url = 'https://api.open-meteo.com/v1/forecast'
     . '?latitude=' . $LAT . '&longitude=' . $LON
     . '&current=temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,is_day,wind_speed_10m,wind_direction_10m'
     . '&daily=temperature_2m_max,temperature_2m_min'
     . '&timezone=auto&forecast_days=1';

$raw = http_get($url, 9);
$j   = $raw ? json_decode($raw, true) : null;
if (is_array($j) && isset($j['current']['temperature_2m'])) {
    $c = $j['current'];
    $day = (string)($j['daily']['time'][0] ?? ($c['time'] ?? ''));
    $dp  = explode('-', substr($day, 0, 10));
    $dateTxt = (count($dp) === 3) ? ((int)$dp[1] . '月' . (int)$dp[2] . '日') : date('n月j日');
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
        'date'    => $dateTxt,
        'source'  => 'Open-Meteo',
        'geo'     => $GEOSRC,
        'fallback'=> $FALLBACK_FLAG,
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
    $old['city'] = $CITY;
    $old['geo'] = $GEOSRC;
    $old['fallback'] = $FALLBACK_FLAG;
    emit($old);
}
emit(['ok' => 0, 'error' => '天气服务暂不可用']);
