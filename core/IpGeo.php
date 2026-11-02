<?php
// IP 归属地识别：调用 ip9.com.cn 接口获取访客地区（国内省市 / 境外地区）
declare(strict_types=1);

/**
 * 判断是否为公网 IP（内网、保留地址无需查询接口）。
 */
function ip_geo_is_public(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * 归属地识别是否开启（后台「验证设置」可关闭，避免外部接口不可用时拖慢页面）。
 */
function ip_geo_enabled(): bool
{
    return (string)cfg('ip_geo_enabled', '1') === '1';
}

function ip_geo_cache_file(): string
{
    return ROOT_PATH . '/runtime/ip_geo_cache.json';
}

/**
 * @return array<string,mixed>
 */
function ip_geo_cache_read(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $file = ip_geo_cache_file();
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $cache = $data;
            }
        }
    }
    return $cache;
}

/**
 * @param array<string,mixed> $data
 */
function ip_geo_cache_write(array $data): void
{
    $file = ip_geo_cache_file();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // 限容：超过 5000 条时保留最近 3000 条，避免文件无限增长
    if (count($data) > 5000) {
        uasort($data, static function (array $a, array $b): int {
            return (int)($b['t'] ?? 0) <=> (int)($a['t'] ?? 0);
        });
        $data = array_slice($data, 0, 3000, true);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $file);
    } else {
        @unlink($tmp);
    }
}

/**
 * 生成展示用的地区文本。
 *
 * 规则：国内展示「省 市」（省市同名只保留一个，港澳台同样处理）；
 * 境外展示「国家」，有城市/省份时追加在后面。
 *
 * @param array<string,mixed> $data ip9 返回的 data 字段
 */
function ip_geo_region(array $data): string
{
    $country = trim((string)($data['country'] ?? ''));
    $prov    = trim((string)($data['prov'] ?? ''));
    $city    = trim((string)($data['city'] ?? ''));
    $area    = trim((string)($data['area'] ?? ''));

    $parts = [];
    // 境外：国家 +（城市优先，其次省份），不再拼接州/省全称，避免过长
    if ($country !== '' && $country !== '中国') {
        $parts[] = $country;
        $sub = $city !== '' ? $city : ($prov !== '' ? $prov : $area);
        if ($sub !== '' && $sub !== $country) {
            $parts[] = $sub;
        }
        return implode(' ', $parts);
    }
    // 国内（含港澳台）：省 + 市
    foreach ([$prov, $city !== '' ? $city : $area] as $part) {
        if ($part === '' || $part === $country) {
            continue;
        }
        if (!in_array($part, $parts, true)) {
            $parts[] = $part;
        }
    }
    return $parts ? implode(' ', $parts) : '未知';
}

/**
 * 调用 ip9.com.cn 查询归属地。
 *
 * @return array<string,mixed>|null
 */
function ip_geo_api(string $ip): ?array
{
    $url = 'https://ip9.com.cn/get?ip=' . rawurlencode($ip);
    $body = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'APEAuth/2.0 (+https://ip9.com.cn)',
            ]);
            $body = (string)curl_exec($ch);
            curl_close($ch);
        }
    }

    if ($body === '') {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 3,
                'header'  => "User-Agent: APEAuth/2.0\r\nAccept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $body = (string)@file_get_contents($url, false, $ctx);
    }

    if ($body === '') {
        return null;
    }
    $json = json_decode($body, true);
    if (!is_array($json) || (int)($json['ret'] ?? 0) !== 200 || !is_array($json['data'] ?? null)) {
        return null;
    }
    return $json['data'];
}

/**
 * 查询 IP 归属地（带文件缓存）。
 *
 * @return array{ip:string,region:string,country:string,prov:string,city:string,isp:string,ok:bool,source:string}
 */
function ip_geo_lookup(string $ip): array
{
    $ip = trim($ip);
    $empty = [
        'ip' => $ip, 'region' => '', 'country' => '', 'prov' => '',
        'city' => '', 'isp' => '', 'ok' => false, 'source' => 'none',
    ];

    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return $empty;
    }
    if (!ip_geo_is_public($ip)) {
        return [
            'ip' => $ip, 'region' => '内网 / 本机', 'country' => '', 'prov' => '',
            'city' => '', 'isp' => '', 'ok' => true, 'source' => 'local',
        ];
    }
    if (!ip_geo_enabled()) {
        return $empty;
    }

    $cache = ip_geo_cache_read();
    $now = time();
    $ttlOk = 30 * 86400;  // 成功结果缓存 30 天
    $ttlErr = 21600;      // 失败结果缓存 6 小时，避免频繁重试

    if (isset($cache[$ip]) && is_array($cache[$ip])) {
        $item = $cache[$ip];
        $age = $now - (int)($item['t'] ?? 0);
        $ttl = !empty($item['ok']) ? $ttlOk : $ttlErr;
        if ($age >= 0 && $age < $ttl) {
            return [
                'ip' => $ip,
                'region' => (string)($item['r'] ?? ''),
                'country' => (string)($item['c'] ?? ''),
                'prov' => (string)($item['p'] ?? ''),
                'city' => (string)($item['ci'] ?? ''),
                'isp' => (string)($item['i'] ?? ''),
                'ok' => !empty($item['ok']),
                'source' => 'cache',
            ];
        }
    }

    // 单次请求最多查询 30 个不同 IP（覆盖一页日志 20 条并留余量），避免外部接口拖慢页面
    static $lookups = 0;
    if ($lookups >= 30) {
        return $empty;
    }
    $lookups++;

    $data = ip_geo_api($ip);
    if ($data === null) {
        $cache[$ip] = ['t' => $now, 'r' => '', 'c' => '', 'p' => '', 'ci' => '', 'i' => '', 'ok' => 0];
        ip_geo_cache_write($cache);
        return $empty;
    }

    $region = ip_geo_region($data);
    $row = [
        'ip' => $ip,
        'region' => $region,
        'country' => trim((string)($data['country'] ?? '')),
        'prov' => trim((string)($data['prov'] ?? '')),
        'city' => trim((string)($data['city'] ?? '')),
        'isp' => trim((string)($data['isp'] ?? '')),
        'ok' => $region !== '' && $region !== '未知',
        'source' => 'api',
    ];

    $cache[$ip] = [
        't' => $now,
        'r' => $row['region'],
        'c' => $row['country'],
        'p' => $row['prov'],
        'ci' => $row['city'],
        'i' => $row['isp'],
        'ok' => $row['ok'] ? 1 : 0,
    ];
    ip_geo_cache_write($cache);
    return $row;
}

/**
 * 只要地区文本，失败返回空字符串。
 */
function ip_geo_text(string $ip): string
{
    $row = ip_geo_lookup($ip);
    return (string)$row['region'];
}

/**
 * 输出表格里的归属地标签（带 ISP 提示）；无效/未识别时输出 -。
 */
function ip_geo_badge(string $ip): string
{
    $row = ip_geo_lookup($ip);
    if (!$row['ok'] || $row['region'] === '') {
        return '<span class="text-sub">-</span>';
    }
    $tip = $row['isp'] !== '' ? $row['isp'] : $row['region'];
    return '<span class="ip-geo-tag" title="' . e($tip) . '">' . e($row['region']) . '</span>';
}
