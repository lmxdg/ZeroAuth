<?php
// 云变量读取接口（公开/签名两级）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';

header('Content-Type: application/json; charset=utf-8');

$ip = client_ip();

$threshold = (int)cfg('secret_alert_threshold', '60');
if ($threshold > 0 && sec_rate_hit('cloud_var', $ip, $threshold) > $threshold) {
    json_out(429, '请求过于频繁，请稍后再试');
}
if (sec_ip_banned($ip)) {
    json_out(403, 'IP 已被封禁');
}

$p = sec_params();
// keys / private 不属于通用签名参数集，单独读取
$req = sec_request_all();
foreach (['keys', 'private'] as $k) {
    if (isset($req[$k]) && is_scalar($req[$k])) {
        $p[$k] = trim((string)$req[$k]);
    }
}
if (!isset($p['app_id']) || $p['app_id'] === '') {
    json_out(400, '缺少参数：app_id');
}

$stmt = db()->prepare('SELECT `id`,`name`,`app_secret`,`status` FROM `app` WHERE `app_id`=? LIMIT 1');
$stmt->execute([$p['app_id']]);
$app = $stmt->fetch();
if (!$app) {
    json_out(404, '应用不存在');
}
if ((int)$app['status'] !== 1) {
    json_out(403, '应用已停用');
}

// 可选加密请求体：payload 使用 AppSecret 派生密钥的 AES-256-GCM 密文
if (!empty($p['payload'])) {
    $outerAppId = (string)$p['app_id'];
    $p['__app_secret'] = (string)$app['app_secret'];
    $p = sec_api_merge_payload($p, ['app_id', 'keys', 'private', 'timestamp', 'nonce', 'sign']);
    $p['app_id'] = $outerAppId;
    if (!empty($p['__payload_error'])) {
        json_out(400, '加密请求体解析失败');
    }
}

$wantPrivate = isset($p['private']) && $p['private'] === '1';
if ($wantPrivate) {
    foreach (['timestamp', 'nonce', 'sign'] as $k) {
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
    if (!sec_verify_sign($p, $app['app_secret'])) {
        audit('cloud_var', 'sign_fail', 4, 0, 'AppID：' . $p['app_id']);
        json_out(401, '签名校验失败');
    }
}

$vars = [];
if (isset($p['keys']) && $p['keys'] !== '') {
    $keys = array_slice(array_filter(array_map('trim', explode(',', (string)$p['keys']))), 0, 50);
    if ($keys) {
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $sql = 'SELECT `var_key`,`var_value` FROM `cloud_var` WHERE `app_id`=? AND `var_key` IN (' . $marks . ')';
        $exec = array_merge([(int)$app['id']], $keys);
        if (!$wantPrivate) {
            $sql .= ' AND `is_public`=1';
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($exec);
        $vars = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
} else {
    $sql = 'SELECT `var_key`,`var_value` FROM `cloud_var` WHERE `app_id`=?';
    if (!$wantPrivate) {
        $sql .= ' AND `is_public`=1';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([(int)$app['id']]);
    $vars = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

json_out(200, 'success', [
    'vars'        => $vars,
    'server_time' => time(),
]);
