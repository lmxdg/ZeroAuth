<?php
// 检测更新：拉取 GitHub 最新 Releases 与本地版本比较，发现新版则提示
declare(strict_types=1);

const UPDATE_CHECK_TTL = 21600;      // 正常缓存 6 小时
const UPDATE_CHECK_TTL_FAIL = 1800;  // 拉取失败缓存 30 分钟，避免频繁请求 GitHub API

// 拉取最新版本信息（带本地缓存；$force=true 强制重新拉取）
function update_check_fetch(bool $force = false): array
{
    static $cache = null;
    if ($cache !== null && !$force) {
        return $cache;
    }
    $cacheFile = RUNTIME_PATH . '/update_check.json';
    if (!$force && is_file($cacheFile)) {
        $j = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($j)) {
            $checkedAt = (int)($j['checked_at'] ?? 0);
            $ttl = !empty($j['ok']) ? UPDATE_CHECK_TTL : UPDATE_CHECK_TTL_FAIL;
            if ($checkedAt > 0 && (time() - $checkedAt) < $ttl) {
                return $cache = $j;
            }
        }
    }

    $result = ['ok' => false, 'checked_at' => time(), 'version' => '', 'tag' => '', 'url' => '', 'name' => '', 'published_at' => '', 'summary' => ''];
    $api = '';
    if (defined('PRODUCT_GITHUB') && PRODUCT_GITHUB !== '') {
        $repo = trim(parse_url(PRODUCT_GITHUB, PHP_URL_PATH) ?? '', '/');
        $api = 'https://api.github.com/repos/' . $repo . '/releases/latest';
    }
    if ($api !== '' && function_exists('http_get')) {
        $raw = http_get($api, 8, ['User-Agent: ZeroAuth-UpdateCheck', 'Accept: application/vnd.github+json']);
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j) && isset($j['tag_name'])) {
                $tag = trim((string)$j['tag_name']);
                $result['ok'] = true;
                $result['tag'] = $tag;
                $result['version'] = ltrim($tag, "vV \t\n\r");
                $result['url'] = (string)($j['html_url'] ?? PRODUCT_GITHUB . '/releases');
                $result['name'] = mb_substr(trim((string)($j['name'] ?? '')), 0, 100);
                $result['published_at'] = trim((string)($j['published_at'] ?? ''));
                $body = trim((string)($j['body'] ?? ''));
                $result['summary'] = mb_substr(preg_replace('/\r\n|\r/', "\n", $body) ?? '', 0, 400);
            }
        }
    }
    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $cache = $result;
}

// 是否发现新版本（远程版本号高于本地 PRODUCT_VERSION）
function update_has_new(): bool
{
    $r = update_check_fetch();
    if (empty($r['ok']) || ($r['version'] ?? '') === '') {
        return false;
    }
    return version_compare((string)$r['version'], (string)PRODUCT_VERSION, '>');
}
