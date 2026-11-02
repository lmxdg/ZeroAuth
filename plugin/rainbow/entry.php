<?php
// 彩虹聚合登录：入口（Step1 获取跳转地址并跳转）
declare(strict_types=1);

require dirname(__DIR__, 2) . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/Security.php';
require_once __DIR__ . '/common.php';

config();

header('Content-Type: text/html; charset=utf-8');

if (!plugin_enabled('rainbow')) {
    exit('彩虹聚合登录插件未启用。');
}

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$map  = rainbow_types_map();
if (!isset($map[$type])) {
    exit('不支持的登录方式。');
}
if (!in_array($type, rainbow_active_types(), true)) {
    exit('该登录方式未在后台启用。');
}

$appid  = trim(plugin_cfg_get('rainbow', 'rainbow_appid', ''));
$appkey = trim(plugin_cfg_get('rainbow', 'rainbow_appkey', ''));

$query = http_build_query([
    'act'          => 'login',
    'appid'        => $appid,
    'appkey'       => $appkey,
    'type'         => $type,
    'redirect_uri' => rainbow_redirect_uri(),
]);
$resp = http_get(rainbow_api_base() . '/connect.php?' . $query, 8);
$data = is_string($resp) ? json_decode($resp, true) : null;

if (!is_array($data) || (int)($data['code'] ?? -1) !== 0 || empty($data['url'])) {
    $msg = is_array($data) && isset($data['msg']) ? (string)$data['msg'] : '聚合登录接口请求失败，请稍后再试。';
    exit('第三方登录跳转失败：' . e($msg));
}

$_SESSION['rainbow_type'] = $type;
redirect((string)$data['url']);
