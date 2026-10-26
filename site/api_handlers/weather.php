<?php
declare(strict_types=1);

/**
 * 天气接口：输单登记页右侧「今天」卡片用（天气 / 气温 / 高低温 / 湿度 / 风）。
 *
 * 设计要点：
 * - 由服务端（php-fpm）直连免费且无需 key 的 Open-Meteo：
 *   前端不直连外网，避免 CORS、混合内容与办公网出口差异；
 * - 城市取环境变量 WEATHER_CITY（写在 .env）；未配置时返回 available=false，
 *   页面不显示天气块即可，**不影响登记流程**；
 * - 结果缓存 30 分钟到 site/runtime/weather_cache.json：天气不需要秒级实时，
 *   避免每次进页面都打一次外网（线上是公网库，多一次外网往返就慢一次）；
 * - 任何异常（外网不通 / 解析失败 / 超时）一律降级为 available=false，绝不让接口 500。
 */

const QLY_WEATHER_TTL = 1800;

/** WMO 天气代码 → [中文描述, emoji] */
function qly_weather_code_map(int $code): array
{
    $map = [
        0 => ['晴', '☀️'],
        1 => ['晴间多云', '🌤️'],
        2 => ['多云', '⛅'],
        3 => ['阴', '☁️'],
        45 => ['有雾', '🌫️'],
        48 => ['雾凇', '🌫️'],
        51 => ['毛毛雨', '🌦️'],
        53 => ['小雨', '🌦️'],
        55 => ['中雨', '🌧️'],
        56 => ['冻雨', '🌧️'],
        57 => ['强冻雨', '🌧️'],
        61 => ['小雨', '🌦️'],
        63 => ['中雨', '🌧️'],
        65 => ['大雨', '🌧️'],
        66 => ['冻雨', '🌧️'],
        67 => ['强冻雨', '🌧️'],
        71 => ['小雪', '🌨️'],
        73 => ['中雪', '🌨️'],
        75 => ['大雪', '❄️'],
        77 => ['米雪', '🌨️'],
        80 => ['阵雨', '🌦️'],
        81 => ['强阵雨', '🌧️'],
        82 => ['暴雨', '⛈️'],
        85 => ['阵雪', '🌨️'],
        86 => ['强阵雪', '❄️'],
        95 => ['雷阵雨', '⛈️'],
        96 => ['雷阵雨伴冰雹', '⛈️'],
        99 => ['雷阵雨伴强冰雹', '⛈️'],
    ];
    return $map[$code] ?? ['未知', '🌡️'];
}

/** 带超时的 GET，返回解析后的数组；失败返回 null（不抛异常） */
function qly_weather_get(string $url): ?array
{
    $raw = null;
    if (function_exists('curl_init')) {
        // 先按校验证书请求；个别主机 CA 缺失时再降级重试一次，避免整个功能不可用
        foreach ([true, false] as $verify) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                CURLOPT_USERAGENT => 'qly-weather/1.0',
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno === 0 && is_string($body) && $body !== '') {
                $raw = $body;
                break;
            }
        }
    }
    if ($raw === null) {
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'user_agent' => 'qly-weather/1.0']]);
        $body = @file_get_contents($url, false, $ctx);
        if (is_string($body) && $body !== '') {
            $raw = $body;
        }
    }
    if ($raw === null) {
        return null;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

/** 取城市坐标（Open-Meteo Geocoding，中文优先）；带缓存 */
function qly_weather_geocode(string $city, array &$cache): ?array
{
    if (isset($cache['geo'][$city]) && is_array($cache['geo'][$city])) {
        return $cache['geo'][$city];
    }
    $url = 'https://geocoding-api.open-meteo.com/v1/search?name=' . rawurlencode($city)
        . '&count=1&language=zh&format=json';
    $json = qly_weather_get($url);
    $hit = $json['results'][0] ?? null;
    if (!is_array($hit) || !isset($hit['latitude'], $hit['longitude'])) {
        return null;
    }
    $geo = [
        'lat' => (float) $hit['latitude'],
        'lon' => (float) $hit['longitude'],
        'name' => (string) ($hit['name'] ?? $city),
        'admin' => (string) ($hit['admin1'] ?? ''),
    ];
    $cache['geo'][$city] = $geo;
    return $geo;
}

/** 读缓存（含城市坐标缓存），过期返回 null */
function qly_weather_read_cache(): array
{
    $file = qly_site_dir() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'weather_cache.json';
    if (!@is_file($file)) {
        return [];
    }
    $json = json_decode((string) @file_get_contents($file), true);
    return is_array($json) ? $json : [];
}

function qly_weather_write_cache(array $cache): void
{
    $file = qly_site_dir() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'weather_cache.json';
    $dir = dirname($file);
    if (!@is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($file, json_encode($cache, JSON_UNESCAPED_UNICODE));
}

function qly_api_weather_today(): void
{
    qly_auth_user();

    $city = trim((string) qly_env('WEATHER_CITY', false));
    if ($city === '') {
        // 未配置城市：前端不显示天气块（不报错，不影响登记）
        qly_ok(['available' => false, 'reason' => '未配置城市']);
    }

    $now = time();
    $cache = qly_weather_read_cache();
    $payload = $cache['payload'] ?? null;
    $fresh = is_array($payload)
        && (string) ($cache['city'] ?? '') === $city
        && (int) ($cache['fetchedAt'] ?? 0) > 0
        && ($now - (int) $cache['fetchedAt']) < QLY_WEATHER_TTL;
    if ($fresh) {
        qly_ok($payload);
    }

    $geo = qly_weather_geocode($city, $cache);
    if ($geo === null) {
        qly_ok(['available' => false, 'reason' => '城市解析失败']);
    }

    $url = 'https://api.open-meteo.com/v1/forecast'
        . '?latitude=' . $geo['lat'] . '&longitude=' . $geo['lon']
        . '&current=temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m'
        . '&daily=temperature_2m_max,temperature_2m_min,weather_code,sunrise,sunset'
        . '&timezone=Asia%2FShanghai&forecast_days=1';
    $json = qly_weather_get($url);
    if ($json === null || !isset($json['current'])) {
        qly_ok(['available' => false, 'reason' => '天气服务不可用']);
    }

    $cur = $json['current'];
    $daily = $json['daily'] ?? [];
    $code = (int) ($cur['weather_code'] ?? 0);
    [$text, $icon] = qly_weather_code_map($code);
    $round = static function ($v): ?int {
        return $v === null || $v === '' ? null : (int) round((float) $v);
    };

    $payload = [
        'available' => true,
        'city' => $geo['name'] !== '' ? $geo['name'] : $city,
        'region' => $geo['admin'],
        'text' => $text,
        'icon' => $icon,
        'weatherCode' => $code,
        'temp' => $round($cur['temperature_2m'] ?? null),
        'feelsLike' => $round($cur['apparent_temperature'] ?? null),
        'humidity' => $round($cur['relative_humidity_2m'] ?? null),
        'wind' => $round($cur['wind_speed_10m'] ?? null),
        'high' => $round($daily['temperature_2m_max'][0] ?? null),
        'low' => $round($daily['temperature_2m_min'][0] ?? null),
        'sunrise' => $daily['sunrise'][0] ?? null,
        'sunset' => $daily['sunset'][0] ?? null,
        'observedAt' => $cur['time'] ?? null,
        'updatedAt' => date('Y-m-d H:i:s'),
    ];
    $cache['city'] = $city;
    $cache['fetchedAt'] = $now;
    $cache['payload'] = $payload;
    qly_weather_write_cache($cache);
    qly_ok($payload);
}
