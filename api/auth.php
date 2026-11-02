<?php
// 授权验证接口（卡密核验，HMAC 签名）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';

header('Content-Type: application/json; charset=utf-8');

$ip = client_ip();

$vlog = function (int $licId, int $appId, string $cardNo, string $domain, string $device, int $result, string $reason = '') use ($ip): void {
    try {
        db()->prepare(
            'INSERT INTO `verify_log` (`license_id`,`app_id`,`card_no`,`ip`,`domain`,`device_code`,`result`,`fail_reason`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$licId, $appId, mb_substr($cardNo, 0, 60), $ip, mb_substr($domain, 0, 250), mb_substr($device, 0, 120), $result, mb_substr($reason, 0, 110), time()]);
    } catch (Throwable $ex) {
    }
};
$failOut = function (int $code, string $msg, ?array $lic = null, ?array $p = null, string $domain = '') use ($vlog): void {
    $vlog(
        (int)($lic['id'] ?? 0), (int)($lic['app_id'] ?? 0), (string)($p['card_no'] ?? ''),
        $domain !== '' ? $domain : (string)($p['domain'] ?? ''), (string)($p['device_code'] ?? ''), 2, $msg
    );
    json_out($code, $msg);
};

$threshold = (int)cfg('secret_alert_threshold', '60');
if ($threshold > 0 && sec_rate_hit('auth', $ip, $threshold) > $threshold) {
    json_out(429, '请求过于频繁，请稍后再试');
}

if (sec_ip_banned($ip)) {
    json_out(403, 'IP 已被封禁');
}

$p = sec_params();

// 可选加密请求体：payload 使用 AppSecret 派生密钥的 AES-256-GCM 密文
if (!empty($p['payload'])) {
    if ($p['app_id'] === '') {
        json_out(400, '缺少参数：app_id');
    }
    $stmt = db()->prepare('SELECT `app_secret`, `status` FROM `app` WHERE `app_id`=? LIMIT 1');
    $stmt->execute([$p['app_id']]);
    $payloadApp = $stmt->fetch();
    if (!$payloadApp || (int)$payloadApp['status'] !== 1) {
        json_out(404, '应用不存在或已停用');
    }
    $outerAppId = (string)$p['app_id'];
    $p['__app_secret'] = (string)$payloadApp['app_secret'];
    $p = sec_api_merge_payload($p, ['app_id', 'card_no', 'domain', 'device_code', 'timestamp', 'nonce', 'sign']);
    $p['app_id'] = $outerAppId;
    if (!empty($p['__payload_error'])) {
        json_out(400, '加密请求体解析失败');
    }
}

foreach (['app_id', 'card_no', 'domain', 'device_code', 'timestamp', 'nonce', 'sign'] as $k) {
    if (!isset($p[$k]) || $p[$k] === '') {
        json_out(400, '缺少参数：' . $k);
    }
}

$blHit = blacklist_hit([
    1 => [$p['device_code']],
    2 => [$p['card_no']],
    5 => [sec_normalize_domain($p['domain'])],
]);
if ($blHit) {
    require ROOT_PATH . '/core/Alert.php';
    alert_add(3, 'blacklist', '黑名单拦截：' . blacklist_type_name((int)$blHit['type']),
        '拦截值：' . $blHit['value'] . '（卡密：' . $p['card_no'] . '，原因：' . $blHit['reason'] . '）', $ip);
    $failOut(403, '请求已被拉黑，如有疑问请联系管理员', null, $p);
}

$window = (int)cfg('sign_time_window', '300');
if (!sec_check_timestamp((int)$p['timestamp'], $window)) {
    $failOut(401, '签名时间戳超出窗口', null, $p);
}
if (!sec_nonce_check($p['nonce'], $window * 2)) {
    $failOut(401, 'nonce 无效或已重放', null, $p);
}

$stmt = db()->prepare(
    'SELECT l.*, a.`app_secret`, a.`status` AS `app_status`, a.`name` AS `app_name` '
    . 'FROM `app` a INNER JOIN `license` l ON l.`app_id`=a.`id` AND l.`card_no`=? '
    . 'WHERE a.`app_id`=? LIMIT 1'
);
$stmt->execute([$p['card_no'], $p['app_id']]);
$lic = $stmt->fetch();

if (!$lic) {
    $failOut(404, '卡密不存在', null, $p);
}
if ((int)$lic['app_status'] !== 1) {
    $failOut(403, '应用已停用', $lic, $p);
}

if (!sec_verify_sign($p, $lic['app_secret'])) {
    audit('auth', 'sign_fail', 4, 0, 'AppID：' . $p['app_id'] . ' 卡密：' . $p['card_no']);
    $failOut(401, '签名校验失败', $lic, $p);
}

if ((int)$lic['status'] === 2) {
    audit('auth', 'verify_fail', 4, 0, '卡密已封禁：' . $p['card_no']);
    $failOut(403, '卡密已封禁', $lic, $p);
}

$now = time();
$type = (int)$lic['type'];
if ($type === 1) {
    if ((int)$lic['start_time'] > $now) {
        $failOut(403, '授权尚未生效', $lic, $p);
    }
    if ((int)$lic['expire_time'] > 0 && (int)$lic['expire_time'] < $now) {
        $failOut(403, '授权已过期', $lic, $p);
    }
}

$domain = sec_normalize_domain($p['domain']);
if ($domain === '') {
    $failOut(403, '域名参数无效', $lic, $p);
}
$stmt = db()->prepare('SELECT `id`,`is_https` FROM `license_domain` WHERE `license_id`=? AND `domain`=? AND `status`=1 LIMIT 1');
$stmt->execute([(int)$lic['id'], $domain]);
if (!$stmt->fetch()) {
    audit('auth', 'domain_denied', 4, 0, '卡密：' . $p['card_no'] . ' 域名：' . $domain);
    $failOut(403, '域名未授权', $lic, $p, $domain);
}

$deviceCode = $p['device_code'];
$stmt = db()->prepare('SELECT `id`,`status` FROM `license_device` WHERE `license_id`=? AND `device_code`=? LIMIT 1');
$stmt->execute([(int)$lic['id'], $deviceCode]);
$device = $stmt->fetch();

if ($device) {
    if ((int)$device['status'] !== 1) {
        $failOut(403, '设备已被解绑，请联系管理员重新绑定', $lic, $p, $domain);
    }
    db()->prepare('UPDATE `license_device` SET `last_verify_time`=?,`bind_ip`=? WHERE `id`=?')
        ->execute([$now, $ip, (int)$device['id']]);
} else {
    $maxDevices = (int)$lic['max_devices'];
    if ($maxDevices > 0) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM `license_device` WHERE `license_id`=? AND `status`=1');
        $stmt->execute([(int)$lic['id']]);
        if ((int)$stmt->fetchColumn() >= $maxDevices) {
            audit('auth', 'device_limit', 4, 0, '卡密：' . $p['card_no'] . ' 已达设备上限 ' . $maxDevices);
            $failOut(403, '设备绑定数已达上限', $lic, $p, $domain);
        }
    }
    db()->prepare(
        'INSERT INTO `license_device` (`license_id`,`device_code`,`device_name`,`bind_ip`,`first_bind_time`,`last_verify_time`,`status`) VALUES (?,?,?,?,?,?,1)'
    )->execute([(int)$lic['id'], $deviceCode, mb_substr($p['domain'], 0, 120), $ip, $now, $now]);
    audit('auth', 'device_bind', 4, 0, '卡密：' . $p['card_no'] . ' 绑定新设备：' . substr($deviceCode, 0, 12) . '…');
}

if ($type === 2) {
    if ((int)$lic['total_count'] > 0) {
        $stmt = db()->prepare('UPDATE `license` SET `used_count`=`used_count`+1 WHERE `id`=? AND `used_count`<`total_count`');
        $stmt->execute([(int)$lic['id']]);
        if ($stmt->rowCount() === 0) {
            $failOut(403, '验证次数已用完', $lic, $p, $domain);
        }
    } else {
        db()->prepare('UPDATE `license` SET `used_count`=`used_count`+1 WHERE `id`=?')->execute([(int)$lic['id']]);
    }
}

try {
    require ROOT_PATH . '/core/Alert.php';
    $ipCnt = db()->prepare('SELECT COUNT(DISTINCT `bind_ip`) FROM `license_device` WHERE `license_id`=?');
    $ipCnt->execute([(int)$lic['id']]);
    $piracyThreshold = max(2, (int)cfg('piracy_ip_threshold', '3'));
    if ((int)$ipCnt->fetchColumn() >= $piracyThreshold) {
        alert_add(2, 'piracy', '疑似盗版：卡密多 IP 使用',
            '卡密 ' . $lic['card_no'] . '（' . (string)$lic['app_name'] . '）已在 ' . $piracyThreshold . '+ 个不同 IP 验证使用，请到「盗版追踪」核查。', $ip);
    }
    $failCnt = db()->prepare('SELECT COUNT(*) FROM `verify_log` WHERE `ip`=? AND `result`=2 AND `created_at`>?');
    $failCnt->execute([$ip, $now - 600]);
    if ((int)$failCnt->fetchColumn() >= 20) {
        alert_add(3, 'auth', '接口异常：验证失败风暴',
            'IP ' . $ip . ' 近 10 分钟验证失败 20+ 次（最近卡密：' . $p['card_no'] . '），疑似破解/撞库行为。', $ip);
    }
} catch (Throwable $ex) {
}

$vlog((int)$lic['id'], (int)$lic['app_id'], (string)$p['card_no'], $domain, (string)$deviceCode, 1);
$usedCount = (int)$lic['used_count'] + ($type === 2 ? 1 : 0);
json_out(200, 'success', [
    'card_no'     => $lic['card_no'],
    'app_name'    => $lic['app_name'],
    'type'        => $type,
    'expire_time' => (int)$lic['expire_time'],
    'total_count' => (int)$lic['total_count'],
    'used_count'  => $usedCount,
    'max_devices' => (int)$lic['max_devices'],
    'domain'      => $domain,
    'server_time' => $now,
]);
