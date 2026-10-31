<?php
// 授权校验接口（新版协议 v1/v2）
declare(strict_types=1);

require dirname(__DIR__, 3) . '/core/bootstrap.php';
require dirname(__DIR__, 3) . '/core/Security.php';
require dirname(__DIR__, 3) . '/core/LicenseApi.php';

$ip = client_ip();

if (sec_rate_hit('license_verify', $ip, 120) > 120) {
    license_api_out(429, '请求过于频繁，请稍后再试');
}
if (sec_ip_banned($ip)) {
    license_api_out(403, 'IP 已被封禁');
}

$b = license_api_body();
$appKey     = trim((string)($b['appKey'] ?? ''));
$licenseKey = trim((string)($b['licenseKey'] ?? ''));
$domain     = sec_normalize_domain((string)($b['domain'] ?? ''));
$serverIp   = filter_var(trim((string)($b['serverIp'] ?? '')), FILTER_VALIDATE_IP) ? trim((string)($b['serverIp'] ?? '')) : '';
$timestamp  = (int)($b['timestamp'] ?? 0);
$signVer    = strtolower(trim((string)($b['signVersion'] ?? '')));
$sign       = strtolower(trim((string)($b['sign'] ?? '')));

if ($appKey === '') {
    license_api_out(400, '缺少参数：appKey', ['result' => 'fail', 'reason' => 'missing_app_key']);
}
if ($timestamp <= 0) {
    license_api_out(400, '缺少参数：timestamp', ['result' => 'fail', 'reason' => 'missing_timestamp']);
}
if (!sec_check_timestamp($timestamp, 600)) {
    license_api_out(401, '签名时间戳超出允许窗口（±10 分钟）', ['result' => 'fail', 'reason' => 'timestamp_out_of_window']);
}
if ($licenseKey === '' && $domain === '' && $serverIp === '') {
    license_api_out(400, '授权目标缺失：licenseKey / domain / serverIp 至少传一项', ['result' => 'fail', 'reason' => 'missing_target']);
}

$stmt = db()->prepare('SELECT * FROM `app` WHERE `app_key`=? LIMIT 1');
$stmt->execute([$appKey]);
$app = $stmt->fetch();
if (!$app) {
    license_api_out(403, '授权无效或已过期', ['result' => 'fail', 'reason' => 'app_not_found']);
}

$v1Compat = false;
if ($signVer === 'v2') {
    if (!license_api_verify_sign([$appKey, $licenseKey, $domain, $serverIp, (string)$timestamp], (string)$app['app_secret'], $sign)) {
        license_api_out(401, '签名校验失败', ['result' => 'fail', 'reason' => 'sign_error']);
    }
} elseif ($signVer === 'v1') {
    $target = $licenseKey !== '' ? $licenseKey : $domain;
    if ($target === '' || !hash_equals(license_api_sign_v1($appKey, $target, $timestamp, (string)$app['app_secret']), $sign)) {
        license_api_out(401, '签名校验失败', ['result' => 'fail', 'reason' => 'sign_error']);
    }
    $v1Compat = true;
} else {
    license_api_out(400, '不支持的 signVersion，新接入请使用 v2', ['result' => 'fail', 'reason' => 'sign_version_invalid']);
}

if ($licenseKey === '') {
    $lic = license_api_resolve_by_target((int)$app['id'], $domain, $serverIp);
    if (!$lic) {
        license_api_out(403, '授权无效或已过期', ['result' => 'fail', 'reason' => 'license_not_found']);
    }
    $licenseKey = (string)$lic['card_no'];
}

$blHit = blacklist_hit([2 => [$licenseKey], 5 => [$domain]]);
if ($blHit) {
    try {
        require ROOT_PATH . '/core/Alert.php';
        alert_add(3, 'blacklist', '黑名单拦截：' . blacklist_type_name((int)$blHit['type']),
            '拦截值：' . $blHit['value'] . '（appKey：' . $appKey . '，licenseKey：' . $licenseKey . '）', $ip);
    } catch (Throwable $ex) {
    }
    license_api_vlog(0, (int)$app['id'], $licenseKey, $domain, '', 2, 'blacklisted');
    license_api_out(403, '目标命中黑名单，应立即阻断访问', ['result' => 'blacklisted', 'reason' => 'blacklisted']);
}

$siteKey = license_api_site_key($appKey, $licenseKey, $domain, $serverIp);
$check   = license_api_check_license($appKey, $licenseKey, $domain, $serverIp, $siteKey, $domain !== '' ? $domain : $serverIp, $ip, $v1Compat);

license_api_vlog(
    (int)($check['lic']['id'] ?? 0), (int)($check['app']['id'] ?? $app['id']), $licenseKey, $domain, $siteKey,
    $check['ok'] ? 1 : 2, $check['ok'] ? '' : (string)$check['reason']
);

license_api_out_result($check);
