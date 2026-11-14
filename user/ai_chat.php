<?php
// 用户中心 - AI 客服对话接口（服务端代理标准 OpenAI 兼容接口，API Key 不落前端）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/AiChat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!ai_cs_ready()) {
    json_out(403, 'AI 客服未开启或未配置完整。');
}

$user = current_user();
if (!$user) {
    json_out(401, '请先登录后再使用 AI 客服。');
}

if (!is_post()) {
    json_out(405, '仅支持 POST 请求。');
}

// CSRF：悬浮窗通过 X-CSRF-Token 请求头携带令牌
$csrfHeader = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($csrfHeader === '' || !hash_equals(csrf_token(), $csrfHeader)) {
    json_out(403, '非法请求（CSRF 校验失败），请刷新页面后重试。');
}

// 会话级限流：单用户每分钟最多 20 次对话，防止滥用拖垮接口
$now = time();
$_SESSION['ai_chat_hits'] = array_values(array_filter(
    (array)($_SESSION['ai_chat_hits'] ?? []),
    static fn ($t) => is_int($t) && $t > $now - 60
));
if (count($_SESSION['ai_chat_hits']) >= 20) {
    json_out(429, '发送太频繁了，请稍等一分钟再试。');
}
$_SESSION['ai_chat_hits'][] = $now;

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_out(400, '请求格式错误。');
}

// 解析上下文：仅接受 user / assistant 两种角色，截取最近 20 条
$history = [];
if (isset($payload['messages']) && is_array($payload['messages'])) {
    foreach (array_slice($payload['messages'], -20) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $role    = (string)($m['role'] ?? '');
        $content = trim((string)($m['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        if ($role === 'user') {
            $history[] = ['role' => 'user', 'content' => mb_substr($content, 0, 2000)];
        } elseif ($role === 'assistant') {
            $history[] = ['role' => 'assistant', 'content' => mb_substr($content, 0, 8000)];
        }
    }
} else {
    $msg = trim((string)($payload['message'] ?? ''));
    if ($msg !== '') {
        $history[] = ['role' => 'user', 'content' => mb_substr($msg, 0, 2000)];
    }
}

if ($history === [] || end($history)['role'] !== 'user') {
    json_out(400, '请输入消息内容。');
}

// 组装最终 messages：系统提示词置顶，其中附带用户名便于称呼（不含敏感信息）
$messages = [
    ['role' => 'system', 'content' => str_replace('{username}', (string)$user['username'], ai_cs_prompt())],
    ...$history,
];

$res = ai_cs_chat($messages);
if (isset($res['error'])) {
    json_out(500, $res['error']);
}

json_out(0, 'success', ['reply' => $res['reply']]);
