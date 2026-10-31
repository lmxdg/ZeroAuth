<?php
// 应用更新检查接口
declare(strict_types=1);

require dirname(__DIR__, 4) . '/core/bootstrap.php';
require dirname(__DIR__, 4) . '/core/Security.php';
require dirname(__DIR__, 4) . '/core/LicenseApi.php';

$ip = client_ip();

if (sec_rate_hit('version_check', $ip, 120) > 120) {
    license_api_out(429, '请求过于频繁，请稍后再试');
}
if (sec_ip_banned($ip)) {
    license_api_out(403, 'IP 已被封禁');
}

$b = license_api_body();
$appKey         = trim((string)($b['appKey'] ?? ''));
$currentVersion = trim((string)($b['currentVersion'] ?? ''));
$licenseKey     = trim((string)($b['licenseKey'] ?? ''));
$domain         = sec_normalize_domain((string)($b['domain'] ?? ''));
$serverIp       = filter_var(trim((string)($b['serverIp'] ?? '')), FILTER_VALIDATE_IP) ? trim((string)($b['serverIp'] ?? '')) : '';
$timestamp      = (int)($b['timestamp'] ?? 0);
$signVer        = strtolower(trim((string)($b['signVersion'] ?? '')));
$sign           = strtolower(trim((string)($b['sign'] ?? '')));

if ($appKey === '') {
    license_api_out(400, '缺少参数：appKey', ['result' => 'fail', 'reason' => 'missing_app_key']);
}
if ($currentVersion === '') {
    license_api_out(400, '缺少参数：currentVersion', ['result' => 'fail', 'reason' => 'missing_current_version']);
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
    if (!license_api_verify_sign([$appKey, $currentVersion, $licenseKey, $domain, $serverIp, (string)$timestamp], (string)$app['app_secret'], $sign)) {
        license_api_out(401, '签名校验失败', ['result' => 'fail', 'reason' => 'sign_error']);
    }
} elseif ($signVer === 'v1') {
    $target = $licenseKey !== '' ? $licenseKey : $domain;
    if ($target === '' || !hash_equals(hash_hmac('sha256', "v1\n" . $appKey . "\n" . $currentVersion . "\n" . $target . "\n" . $timestamp, (string)$app['app_secret']), $sign)) {
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
    license_api_vlog(0, (int)$app['id'], $licenseKey, $domain, '', 2, 'blacklisted');
    license_api_out(403, '目标命中黑名单，应立即阻断访问', ['result' => 'blacklisted', 'reason' => 'blacklisted']);
}

$siteKey = license_api_site_key($appKey, $licenseKey, $domain, $serverIp);
$check   = license_api_check_license($appKey, $licenseKey, $domain, $serverIp, $siteKey, $domain !== '' ? $domain : $serverIp, $ip, $v1Compat);

license_api_vlog(
    (int)($check['lic']['id'] ?? 0), (int)($check['app']['id'] ?? $app['id']), $licenseKey, $domain, $siteKey,
    $check['ok'] ? 1 : 2, $check['ok'] ? '' : (string)$check['reason']
);
if (!$check['ok']) {
    license_api_out_result($check);
}

$stmt = db()->prepare('SELECT * FROM `app_version` WHERE `app_id`=? AND `status`=1 ORDER BY `published_at` DESC, `id` DESC LIMIT 1');
$stmt->execute([(int)$app['id']]);
$latest = $stmt->fetch();

if (!$latest || version_compare((string)$latest['version'], $currentVersion, '<=')) {
    license_api_out(200, '', [
        'hasUpdate'      => false,
        'currentVersion' => $currentVersion,
        'latestVersion'  => $latest ? (string)$latest['version'] : '',
    ]);
}

$stmt = db()->prepare('SELECT * FROM `app_version` WHERE `app_id`=? AND `status`=1 ORDER BY `published_at` ASC, `id` ASC');
$stmt->execute([(int)$app['id']]);
$updates = [];
foreach ($stmt->fetchAll() as $row) {
    $v = (string)$row['version'];
    if (version_compare($v, $currentVersion, '>') && version_compare($v, (string)$latest['version'], '<=')) {
        $updates[] = [
            'version'   => $v,
            'title'     => (string)$row['title'],
            'changelog' => (string)$row['changelog'],
            'updateSql' => (string)$row['update_sql'],
        ];
    }
}

$minVersion = (string)$latest['min_version'];
$force      = ((int)$latest['force_update'] === 1) || ($minVersion !== '' && version_compare($currentVersion, $minVersion, '<'));

$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host   = (string)($_SERVER['HTTP_HOST'] ?? '');
$base   = $host !== '' ? $scheme . '://' . $host : '';

license_api_out(200, '', [
    'hasUpdate'      => true,
    'currentVersion' => $currentVersion,
    'latestVersion'  => (string)$latest['version'],
    'version'        => (string)$latest['version'],
    'title'          => (string)$latest['title'],
    'changelog'      => (string)$latest['changelog'],
    'downloadUrl'    => $base . '/api/app/version/download?token=' . license_api_download_token($app, (string)$latest['version'], $licenseKey, $ip),
    'fileSizeMb'     => round((int)$latest['file_size'] / 1048576, 3),
    'fileMd5'        => (string)$latest['file_md5'],
    'forceUpdate'    => $force,
    'minVersion'     => $minVersion,
    'publishedAt'    => (int)$latest['published_at'] > 0 ? date('Y-m-d H:i:s', (int)$latest['published_at']) : '',
    'updates'        => $updates,
]);
