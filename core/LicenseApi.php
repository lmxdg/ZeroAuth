<?php
// 授权校验核心：卡密验证/设备绑定/下载令牌/请求加密/响应签名
declare(strict_types=1);

require_once __DIR__ . '/Security.php';

function license_api_sign_v2(array $fields, string $secret): string
{
    return hash_hmac('sha256', "v2\n" . implode("\n", $fields), $secret);
}

function license_api_verify_sign(array $spec, string $secret, string $sign): bool
{
    if ($sign === '') {
        return false;
    }
    return hash_equals(license_api_sign_v2($spec, $secret), strtolower($sign));
}

function license_api_sign_v1(string $appKey, string $target, int $timestamp, string $secret): string
{
    return md5($appKey . $target . $timestamp . $secret);
}

function license_api_site_key(string $appKey, string $licenseKey, string $domain, string $serverIp): string
{
    $target = $domain !== '' ? $domain : $serverIp;
    return hash('sha256', $appKey . '|' . $licenseKey . '|' . $target);
}

function license_api_rsa_keypair(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $priv = sec_decrypt(cfg('sys_rsa_private_key', ''));
    if ($priv !== '') {
        $public = cfg('sys_rsa_public_key', '');
        if ($public === '') {
            $key = openssl_pkey_get_private($priv);
            if ($key !== false) {
                $public = (string)openssl_pkey_get_details($key)['key'];
                set_cfg('sys_rsa_public_key', $public, '授权接口响应签名 RSA 公钥');
            }
        }
        return $cached = ['private' => $priv, 'public' => $public];
    }

    if (!function_exists('openssl_pkey_new')) {
        return $cached = ['private' => '', 'public' => ''];
    }

    // 文件锁避免并发首次请求生成多套密钥
    $lockFile = RUNTIME_PATH . '/rsa_key.lock';
    $lock = @fopen($lockFile, 'c');
    if ($lock) {
        @flock($lock, LOCK_EX);
    }
    try {
        $priv = sec_decrypt(cfg('sys_rsa_private_key', ''));
        if ($priv !== '') {
            return $cached = ['private' => $priv, 'public' => cfg('sys_rsa_public_key', '')];
        }
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        // Windows 版 PHP（phpStudy/XAMPP）常因未加载 openssl.cnf 而生成失败
        foreach ([
            (string)getenv('OPENSSL_CONF'),
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            dirname(PHP_BINARY) . '/../extras/ssl/openssl.cnf',
            '/usr/local/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
        ] as $cnf) {
            if ($cnf !== '' && @is_file($cnf)) {
                $options['config'] = $cnf;
                break;
            }
        }
        $res = openssl_pkey_new($options);
        if ($res === false) {
            return $cached = ['private' => '', 'public' => ''];
        }
        $privatePem = '';
        $exported = openssl_pkey_export($res, $privatePem, null, $options);
        if (!$exported || $privatePem === '') {
            return $cached = ['private' => '', 'public' => ''];
        }
        $publicPem = (string)openssl_pkey_get_details($res)['key'];
        $encPrivate = sec_encrypt($privatePem);
        if ($encPrivate === '') {
            return $cached = ['private' => '', 'public' => ''];
        }
        set_cfg('sys_rsa_private_key', $encPrivate, '授权接口响应签名 RSA 私钥（AES-256-GCM 加密）');
        set_cfg('sys_rsa_public_key', $publicPem, '授权接口响应签名 RSA 公钥');
        return $cached = ['private' => $privatePem, 'public' => $publicPem];
    } finally {
        if ($lock) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}

function license_api_rsa_public_pem(): string
{
    return (string)(license_api_rsa_keypair()['public'] ?? '');
}

function license_api_rsa_sign(string $payload): string
{
    $private = (string)(license_api_rsa_keypair()['private'] ?? '');
    if ($private === '' || !function_exists('openssl_sign')) {
        return '';
    }
    $key = openssl_pkey_get_private($private);
    if ($key === false) {
        return '';
    }
    $signature = '';
    openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);
    return $signature === '' ? '' : base64_encode($signature);
}

function license_api_rsa_verify(string $payload, string $signature): bool
{
    $public = license_api_rsa_public_pem();
    if ($public === '' || $signature === '') {
        return false;
    }
    $key = openssl_pkey_get_public($public);
    if ($key === false) {
        return false;
    }
    $raw = base64_decode($signature, true);
    if ($raw === false) {
        return false;
    }
    return openssl_verify($payload, $raw, $key, OPENSSL_ALGO_SHA256) === 1;
}

function license_api_out(int $code, string $msg, array $data = []): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $body = ['code' => $code, 'msg' => $msg, 'data' => $data === [] ? new stdClass() : $data];
    $raw  = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($raw)) {
        $sign = license_api_rsa_sign($raw);
        if ($sign !== '') {
            $body['rsaSign'] = $sign;
            $body['signAlg'] = 'RSA-SHA256';
        }
        // 输出文本与签名原文保持完全一致，客户端可直接校验
        $out = $sign !== ''
            ? substr($raw, 0, -1) . ',"rsaSign":' . json_encode($sign) . ',"signAlg":"RSA-SHA256"}'
            : $raw;
        echo $out;
    } else {
        echo '{"code":500,"msg":"response encode failed","data":{}}';
    }
    exit;
}

function license_api_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
        $form = [];
        parse_str($raw, $form);
        if (is_array($form) && $form !== []) {
            return $form;
        }
    }
    // 兼容 application/x-www-form-urlencoded 或 multipart 请求
    return is_array($_POST) ? $_POST : [];
}

// 解密客户端 AES-256-GCM 请求体（payload 使用 AppSecret 派生密钥）
function license_api_decode_payload(array $body, string $appSecret): array
{
    if (empty($body['payload']) || !is_string($body['payload'])) {
        return $body;
    }
    $plain = sec_api_decrypt($body['payload'], $appSecret);
    if ($plain === null) {
        return ['__payload_error' => 'payload_decrypt_failed'];
    }
    $json = json_decode($plain, true);
    if (!is_array($json)) {
        return ['__payload_error' => 'payload_invalid'];
    }
    unset($body['payload']);
    return array_merge($body, $json, ['__payload_decrypted' => 1]);
}

function license_api_wildcard_match(string $wildcard, string $domain): bool
{
    if ($wildcard === '' || $domain === '') {
        return false;
    }
    $suffix = substr($wildcard, strpos($wildcard, '*') + 1); 
    $suffix = ltrim($suffix, '.');
    if ($suffix === '') {
        return false;
    }
    if (strcasecmp($domain, $suffix) === 0) {
        return false; 
    }
    return str_ends_with(strtolower($domain), '.' . strtolower($suffix));
}

function license_api_match_target(int $licenseId, int $targetType, string $domain, string $serverIp): bool
{
    if ($targetType === 4) {
        return true; 
    }
    $rows = db()->prepare('SELECT `target_type`,`domain` FROM `license_domain` WHERE `license_id`=? AND `status`=1');
    $rows->execute([$licenseId]);
    $binds = $rows->fetchAll();
    if (!$binds) {
        return false;
    }
    $domain = sec_normalize_domain($domain);
    $serverIp = trim($serverIp);
    foreach ($binds as $b) {
        $t = (int)($b['target_type'] ?? 1) ?: 1; 
        $target = (string)$b['domain'];
        if ($t === 3) {
            if ($serverIp !== '' && strcasecmp($target, $serverIp) === 0) {
                return true;
            }
        } elseif ($t === 2) {
            if ($domain !== '' && license_api_wildcard_match($target, $domain)) {
                return true;
            }
        } else {
            if ($domain !== '' && strcasecmp($target, $domain) === 0) {
                return true;
            }
            if ($targetType === 0 && $serverIp !== '' && strcasecmp($target, $serverIp) === 0) {
                return true; 
            }
        }
    }
    return false;
}

function license_api_check_license(string $appKey, string $licenseKey, string $domain, string $serverIp, string $siteKey, string $siteName, string $ip, bool $v1Compat = false): array
{
    $fail = function (int $code, string $result, string $reason, string $msg, array $lic = [], array $app = []): array {
        return ['ok' => false, 'code' => $code, 'result' => $result, 'reason' => $reason, 'msg' => $msg, 'lic' => $lic, 'app' => $app];
    };

    $app = null;
    $stmt = db()->prepare('SELECT * FROM `app` WHERE `app_key`=? LIMIT 1');
    $stmt->execute([$appKey]);
    $app = $stmt->fetch();
    if (!$app) {
        return $fail(403, 'fail', 'app_not_found', '应用不存在');
    }
    if ((int)$app['status'] !== 1) {
        return $fail(403, 'fail', 'app_disabled', '应用已停用');
    }

    $stmt = db()->prepare('SELECT * FROM `license` WHERE `card_no`=? AND `app_id`=? LIMIT 1');
    $stmt->execute([$licenseKey, (int)$app['id']]);
    $lic = $stmt->fetch();
    if (!$lic) {
        return $fail(403, 'fail', 'license_not_found', '授权不存在');
    }
    if ((int)$lic['status'] === 2) {
        return $fail(403, 'fail', 'license_disabled', '授权已被封禁');
    }

    $now = time();
    if ((int)$lic['type'] === 1) {
        if ((int)$lic['start_time'] > $now) {
            return $fail(403, 'fail', 'not_started', '授权尚未生效');
        }
        if ((int)$lic['expire_time'] > 0 && (int)$lic['expire_time'] < $now) {
            return $fail(403, 'expired', 'expired', '授权已过期，需要续费或重新开通');
        }
    }

    $targetType = (int)($lic['target_type'] ?? 0);
    if ($targetType !== 4 && !license_api_match_target((int)$lic['id'], $targetType, $domain, $serverIp)) {
        return $fail(403, 'fail', 'target_mismatch', '授权目标不匹配（域名/泛域名/IP 未授权）');
    }

    $maxDevices = (int)$lic['max_devices'];
    $stmt = db()->prepare('SELECT `id`,`status` FROM `license_device` WHERE `license_id`=? AND `device_code`=? LIMIT 1');
    $stmt->execute([(int)$lic['id'], $siteKey]);
    $device = $stmt->fetch();
    if ($device) {
        if ((int)$device['status'] !== 1) {
            return $fail(403, 'fail', 'site_unbound', '站点已被解绑，请联系管理员重新绑定');
        }
        db()->prepare('UPDATE `license_device` SET `last_verify_time`=?,`bind_ip`=? WHERE `id`=?')
            ->execute([$now, $ip, (int)$device['id']]);
    } else {
        if ($v1Compat) {
            return $fail(403, 'signature_upgrade_required', 'signature_upgrade_required', '密钥新站点首次绑定必须使用 v2 签名，当前 SDK 需升级');
        }
        if ($maxDevices > 0) {
            $stmt = db()->prepare('SELECT COUNT(*) FROM `license_device` WHERE `license_id`=? AND `status`=1');
            $stmt->execute([(int)$lic['id']]);
            if ((int)$stmt->fetchColumn() >= $maxDevices) {
                return $fail(403, 'site_limit_exceeded', 'site_limit_exceeded', '密钥已绑定站点数达到上限，拒绝新站点');
            }
        }
        db()->prepare(
            'INSERT INTO `license_device` (`license_id`,`device_code`,`device_name`,`bind_ip`,`first_bind_time`,`last_verify_time`,`status`) VALUES (?,?,?,?,?,?,1)'
        )->execute([(int)$lic['id'], $siteKey, mb_substr($siteName, 0, 120), $ip, $now, $now]);
    }

    if ((int)$lic['type'] === 2 && (int)$lic['total_count'] > 0) {
        $stmt = db()->prepare('UPDATE `license` SET `used_count`=`used_count`+1 WHERE `id`=? AND `used_count`<`total_count`');
        $stmt->execute([(int)$lic['id']]);
        if ($stmt->rowCount() === 0) {
            return $fail(403, 'fail', 'count_exhausted', '验证次数已用完');
        }
    } elseif ((int)$lic['type'] === 2) {
        db()->prepare('UPDATE `license` SET `used_count`=`used_count`+1 WHERE `id`=?')->execute([(int)$lic['id']]);
    }

    return ['ok' => true, 'code' => 200, 'result' => 'pass', 'reason' => '', 'msg' => '授权有效', 'lic' => $lic, 'app' => $app];
}

function license_api_expire_text(array $lic): string
{
    $t = (int)($lic['expire_time'] ?? 0);
    if ((int)$lic['type'] === 3 || $t === 0) {
        return '永久有效';
    }
    return date('Y-m-d H:i:s', $t);
}

function license_api_download_token(array $app, string $version, string $licenseKey, string $ip): string
{
    $payload = base64_encode(json_encode([
        'ak' => (string)$app['app_key'],
        'v'  => $version,
        'lk' => $licenseKey,
        'ip' => $ip,
        'exp' => time() + 600,
    ], JSON_UNESCAPED_UNICODE));
    $sig = hash_hmac('sha256', $payload, (string)$app['app_secret']);
    return rtrim(strtr($payload, '+/', '-_'), '=') . '.' . substr($sig, 0, 32);
}

function license_api_verify_download_token(array $app, string $token, string $ip): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return null;
    }
    $payload = strtr($parts[0], '-_', '+/');
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $data = json_decode((string)base64_decode($payload, true), true);
    if (!is_array($data) || !isset($data['ak'], $data['v'], $data['exp'])) {
        return null;
    }
    if ((string)$data['ak'] !== (string)$app['app_key']) {
        return null;
    }
    if (!hash_equals(substr(hash_hmac('sha256', $payload, (string)$app['app_secret']), 0, 32), $parts[1])) {
        return null;
    }
    if ((int)$data['exp'] < time()) {
        return null;
    }
    if (isset($data['ip']) && $data['ip'] !== '' && $ip !== '' && strcasecmp((string)$data['ip'], $ip) !== 0) {
        return null;
    }
    return ['version' => (string)$data['v'], 'licenseKey' => (string)($data['lk'] ?? '')];
}

function license_api_resolve_by_target(int $appId, string $domain, string $serverIp): ?array
{
    $stmt = db()->prepare(
        'SELECT l.* FROM `license` l INNER JOIN `license_domain` ld ON ld.`license_id`=l.`id` AND ld.`status`=1 '
        . 'WHERE l.`app_id`=? AND l.`status`=1 ORDER BY l.`id` DESC'
    );
    $stmt->execute([$appId]);
    foreach ($stmt->fetchAll() as $lic) {
        $tt = (int)($lic['target_type'] ?? 0);
        if ($tt === 4) {
            continue;
        }
        if (license_api_match_target((int)$lic['id'], $tt, $domain, $serverIp)) {
            return $lic;
        }
    }
    return null;
}

function license_api_vlog(int $licenseId, int $appId, string $cardNo, string $domain, string $deviceCode, int $result, string $failReason = ''): void
{
    try {
        db()->prepare(
            'INSERT INTO `verify_log` (`license_id`,`app_id`,`card_no`,`ip`,`domain`,`device_code`,`result`,`fail_reason`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $licenseId, $appId, mb_substr($cardNo, 0, 60), client_ip(), mb_substr($domain, 0, 250),
            mb_substr($deviceCode, 0, 64), $result, mb_substr($failReason, 0, 110), time(),
        ]);
    } catch (Throwable $ex) {
    }
}

function license_api_out_result(array $check, array $extra = []): never
{
    $lic = is_array($check['lic'] ?? null) ? $check['lic'] : [];
    $app = is_array($check['app'] ?? null) ? $check['app'] : [];
    if (!empty($check['ok'])) {
        license_api_out(200, (string)($check['msg'] ?? '') !== '' ? (string)$check['msg'] : '授权有效', array_merge([
            'result'     => 'pass',
            'appName'    => (string)($app['name'] ?? ''),
            'expireAt'   => $lic ? license_api_expire_text($lic) : '',
            'licenseKey' => (string)($lic['card_no'] ?? ''),
        ], $extra));
    }
    license_api_out(403, (string)($check['msg'] ?? '') !== '' ? (string)$check['msg'] : '授权无效或已过期', array_merge([
        'result' => (string)($check['result'] ?? '') !== '' ? (string)$check['result'] : 'fail',
        'reason' => (string)($check['reason'] ?? ''),
    ], $extra));
}
