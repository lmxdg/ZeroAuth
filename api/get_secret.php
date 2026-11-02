<?php
// 密钥下发接口（域名+设备双绑后下发）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';

header('Content-Type: application/json; charset=utf-8');

$ip = client_ip();

$threshold = (int)cfg('secret_alert_threshold', '60');
if ($threshold > 0 && sec_rate_hit('secret', $ip, $threshold) > $threshold) {
    json_out(429, '请求过于频繁，请稍后再试');
}

if (sec_ip_banned($ip)) {
    json_out(403, 'IP 已被封禁');
}

if (cfg('secret_api_enabled', '1') !== '1') {
    json_out(403, '密钥下发接口已关闭');
}

$p = sec_params();
if (!isset($p['app_id']) || $p['app_id'] === '') {
    json_out(400, '缺少参数：app_id');
}

$stmt = db()->prepare('SELECT `id`,`app_id`,`app_key`,`app_secret`,`status` FROM `app` WHERE `app_id`=?');
$stmt->execute([$p['app_id']]);
$app = $stmt->fetch();
if (!$app || (int)$app['status'] !== 1) {
    json_out(404, '应用不存在或已停用');
}

// 可选加密请求体：payload 使用 AppSecret 派生密钥的 AES-256-GCM 密文
if (!empty($p['payload'])) {
    $outerAppId = (string)$p['app_id'];
    $p['__app_secret'] = (string)$app['app_secret'];
    $p = sec_api_merge_payload($p, ['app_id', 'domain', 'device_code', 'timestamp', 'nonce', 'sign']);
    $p['app_id'] = $outerAppId;
    if (!empty($p['__payload_error'])) {
        json_out(400, '加密请求体解析失败');
    }
}

foreach (['app_id', 'domain', 'device_code', 'timestamp', 'nonce', 'sign'] as $k) {
    if (!isset($p[$k]) || $p[$k] === '') {
        json_out(400, '缺少参数：' . $k);
    }
}

$window = (int)cfg('sign_time_window', '300');
if (!sec_check_timestamp((int)$p['timestamp'], $window)) {
    json_out(401, '签名时间戳超出窗口');
}

if (!sec_nonce_check($p['nonce'], $window * 2)) {
    json_out(401, 'nonce 无效或已重放');
}

if (!sec_verify_sign($p, $app['app_id'])) {
    audit('secret', 'sign_fail', 4, 0, 'AppID：' . $p['app_id'] . ' 域名：' . $p['domain']);
    json_out(401, '签名校验失败');
}

$domain = sec_normalize_domain($p['domain']);
if ($domain === '') {
    json_out(403, '域名参数无效');
}
$stmt = db()->prepare(
    'SELECT d.`id` FROM `license_domain` d '
    . 'INNER JOIN `license` l ON l.`id`=d.`license_id` '
    . 'WHERE l.`app_id`=? AND l.`status`=1 AND d.`status`=1 AND d.`domain`=? LIMIT 1'
);
$stmt->execute([(int)$app['id'], $domain]);
if (!$stmt->fetch()) {
    audit('secret', 'domain_denied', 4, 0, 'AppID：' . $p['app_id'] . ' 域名：' . $domain);
    json_out(403, '域名未授权');
}

$stmt = db()->prepare(
    'SELECT dev.`id` FROM `license_device` dev '
    . 'INNER JOIN `license` l ON l.`id`=dev.`license_id` '
    . 'WHERE l.`app_id`=? AND l.`status`=1 AND dev.`status`=1 AND dev.`device_code`=? LIMIT 1'
);
$stmt->execute([(int)$app['id'], $p['device_code']]);
if (!$stmt->fetch()) {
    audit('secret', 'device_denied', 4, 0, 'AppID：' . $p['app_id'] . ' 设备：' . substr($p['device_code'], 0, 12) . '…');
    json_out(403, '设备未授权');
}

$sessionTtl = (int)cfg('secret_session_ttl', '1800');
$cacheTtl   = (int)cfg('sdk_cache_ttl', '1800');

json_out(200, 'success', [
    'appkey'    => $app['app_key'],
    'appsecret' => $app['app_secret'],
    'expire'    => time() + $sessionTtl,
    'cache_ttl' => $cacheTtl,
]);
