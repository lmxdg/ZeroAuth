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

    $result = ['ok' => false, 'checked_at' => time(), 'version' => '', 'tag' => '', 'url' => '', 'name' => '', 'published_at' => '', 'summary' => '', 'zip_url' => '', 'zip_name' => '', 'zip_size' => 0];
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
                // 捕获 Release 附件中的 zip 安装包（一键更新用），优先体积最大的 zip 资产
                $zip = ['url' => '', 'name' => '', 'size' => 0];
                foreach ((array)($j['assets'] ?? []) as $asset) {
                    if (!is_array($asset)) {
                        continue;
                    }
                    $aUrl = trim((string)($asset['browser_download_url'] ?? ''));
                    $aName = trim((string)($asset['name'] ?? ''));
                    if ($aUrl === '' || strtolower(pathinfo($aName, PATHINFO_EXTENSION)) !== 'zip') {
                        continue;
                    }
                    if ((int)($asset['size'] ?? 0) >= $zip['size']) {
                        $zip = ['url' => $aUrl, 'name' => $aName, 'size' => (int)($asset['size'] ?? 0)];
                    }
                }
                $result['zip_url'] = $zip['url'];
                $result['zip_name'] = $zip['name'];
                $result['zip_size'] = $zip['size'];
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

// 一键更新安装包来源：优先 Release zip 资产，缺失时回退仓库源码 zip（codeload）
function update_zip_source(array $info): array
{
    if (($info['zip_url'] ?? '') !== '') {
        return ['url' => (string)$info['zip_url'], 'name' => (string)$info['zip_name'], 'size' => (int)$info['zip_size'], 'from' => 'release'];
    }
    if (defined('PRODUCT_GITHUB') && PRODUCT_GITHUB !== '' && ($info['tag'] ?? '') !== '') {
        $repo = trim(parse_url(PRODUCT_GITHUB, PHP_URL_PATH) ?? '', '/');
        return [
            'url'  => 'https://codeload.github.com/' . $repo . '/zip/refs/tags/' . rawurlencode((string)$info['tag']),
            'name' => $repo . '-' . (string)$info['tag'] . '.zip',
            'size' => 0,
            'from' => 'source',
        ];
    }
    return ['url' => '', 'name' => '', 'size' => 0, 'from' => ''];
}
