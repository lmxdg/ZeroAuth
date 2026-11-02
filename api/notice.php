<?php
// 远程公告分发接口（授权站端）
// 接入本授权站的下游系统在后台「系统设置 → 远程公告地址」填入本接口完整地址
// 例：https://你的授权站域名/api/notice.php
// 返回授权站管理员在「系统设置 → 远程公告分发」中发布的公告列表（JSON）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$publish = cfg('remote_notice_publish', '1') === '1';
if (!$publish) {
    echo json_encode(['code' => 1, 'msg' => 'notice disabled', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = (string)cfg('remote_notice_content', '[]');
$list = json_decode($raw, true);
if (!is_array($list)) {
    $list = [];
}

// 规范化字段，仅保留客户端需要的字段
$clean = [];
foreach ($list as $item) {
    if (!is_array($item)) {
        continue;
    }
    $title = trim((string)($item['title'] ?? ''));
    if ($title === '') {
        continue;
    }
    $clean[] = [
        'id'      => mb_substr(trim((string)($item['id'] ?? '')) ?: md5($title . ($item['date'] ?? '')), 0, 64),
        'title'   => mb_substr($title, 0, 100),
        'content' => mb_substr(trim((string)($item['content'] ?? '')), 0, 500),
        'level'   => in_array(($item['level'] ?? ''), ['info', 'ok', 'warn', 'error'], true) ? (string)$item['level'] : 'info',
        'date'    => mb_substr(trim((string)($item['date'] ?? '')), 0, 20),
        'url'     => filter_var((string)($item['url'] ?? ''), FILTER_VALIDATE_URL) !== false ? (string)$item['url'] : '',
    ];
    if (count($clean) >= 10) {
        break;
    }
}

echo json_encode([
    'code'       => 0,
    'msg'        => 'success',
    'updated_at' => (int)cfg('remote_notice_updated', '0') ?: time(),
    'data'       => $clean,
], JSON_UNESCAPED_UNICODE);
