<?php
// 远程公告：从远程地址拉取公告 JSON，带本地缓存与失败降级（用于二开作者向所有部署站点下发系统公告）
declare(strict_types=1);

// 默认远程公告地址：可后台 sys_config 覆盖（cfg 键 remote_notice_url）
function remote_notice_url(): string
{
    $default = PRODUCT_GITHUB !== ''
        ? 'https://raw.githubusercontent.com/lmxdg/zeroauth/main/notice.json'
        : '';
    return trim(cfg('remote_notice_url', $default));
}

// 拉取原始公告（1 小时缓存，失败回退旧缓存；$force=true 跳过缓存重新拉取）
function remote_notice_fetch(bool $force = false): array
{
    static $cache = null;
    if ($cache !== null && !$force) {
        return $cache;
    }
    $cacheFile = RUNTIME_PATH . '/remote_notice.json';
    $ttl = 3600;
    if (!$force && is_file($cacheFile) && (time() - (int)@filemtime($cacheFile) < $ttl)) {
        $j = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($j)) {
            return $cache = $j;
        }
    }
    $list = [];
    $url = remote_notice_url();
    if ($url !== '' && function_exists('http_get')) {
        $raw = http_get($url, 3);
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                // 兼容三种返回格式：纯数组 / {data:[...]}（授权站 /api/notice.php）/ {list:[...]}
                if (isset($j['data']) && is_array($j['data'])) {
                    $list = array_values($j['data']);
                } elseif (isset($j['list']) && is_array($j['list'])) {
                    $list = array_values($j['list']);
                } elseif (!isset($j['code']) && !isset($j['msg'])) {
                    $list = $j;
                }
                if ($list !== []) {
                    @file_put_contents($cacheFile, json_encode($list, JSON_UNESCAPED_UNICODE), LOCK_EX);
                }
            }
        }
    }
    if ($list === [] && is_file($cacheFile)) {
        $j = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($j)) {
            $list = $j;
        }
    }
    return $cache = $list;
}

// 规范化公告字段并按日期倒序
function remote_notice_clean(array $raw): array
{
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $title = trim((string)($item['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $url = trim((string)($item['url'] ?? ''));
        $out[] = [
            'id'      => trim((string)($item['id'] ?? '')) ?: md5($title . ($item['date'] ?? '')),
            'title'   => mb_substr($title, 0, 100),
            'content' => mb_substr(trim((string)($item['content'] ?? '')), 0, 500),
            'level'   => in_array(($item['level'] ?? ''), ['info', 'ok', 'warn', 'error'], true) ? (string)$item['level'] : 'info',
            'date'    => trim((string)($item['date'] ?? '')),
            'url'     => filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '',
        ];
    }
    usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));
    return array_slice($out, 0, 10);
}

// 当前展示的公告（排除本次会话已关闭的）
function remote_notice_active(): array
{
    $dismissed = (array)($_SESSION['remote_notice_dismissed'] ?? []);
    return array_values(array_filter(
        remote_notice_clean(remote_notice_fetch()),
        fn($n) => !in_array($n['id'], $dismissed, true)
    ));
}

// 关闭指定公告（会话内不再显示）
function remote_notice_dismiss(string $id): void
{
    $dismissed = (array)($_SESSION['remote_notice_dismissed'] ?? []);
    if (!in_array($id, $dismissed, true)) {
        $dismissed[] = mb_substr($id, 0, 64);
    }
    $_SESSION['remote_notice_dismissed'] = $dismissed;
}
