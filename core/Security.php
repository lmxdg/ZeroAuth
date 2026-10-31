<?php
// 接口安全库：签名/防重放/限流/IP 黑名单
declare(strict_types=1);

function sec_dir(string $sub): string
{
    $dir = RUNTIME_PATH . '/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function sec_ip_banned(string $ip): bool
{
    $stmt = db()->prepare('SELECT `expire_time` FROM `ip_ban` WHERE `ip`=?');
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }
    $expire = (int)$row['expire_time'];
    return $expire === 0 || $expire > time();
}

function sec_rate_hit(string $channel, string $ip, int $threshold): int
{
    $minute = (int)floor(time() / 60);
    $file   = sec_dir('ratelimit') . '/' . hash('sha256', $channel . '|' . $ip . '|' . $minute) . '.json';

    $count   = 1;
    $alerted = false;
    if (is_file($file)) {
        $j       = json_decode((string)@file_get_contents($file), true);
        $count   = (int)($j['c'] ?? 0) + 1;
        $alerted = !empty($j['a']);
    }

    if ($threshold > 0 && $count > $threshold && !$alerted) {
        audit($channel, 'rate_alert', 4, 0, 'IP ' . $ip . ' 单分钟访问 ' . $count . ' 次，超过告警阈值 ' . $threshold . ' 次/分钟');
        require_once ROOT_PATH . '/core/Mail.php';
        mail_scene_send('alert', '', [
            'title'     => '安全告警：接口高频访问',
            'channel'   => $channel,
            'ip'        => $ip,
            'count'     => (int)$count . ' 次/分钟',
            'threshold' => (int)$threshold . ' 次/分钟',
            'time'      => date('Y-m-d H:i:s'),
        ]);
        $alerted = true;
    }

    @file_put_contents($file, json_encode(['c' => $count, 'a' => $alerted]));

    if (random_int(1, 50) === 1) {
        foreach (glob(sec_dir('ratelimit') . '/*.json') ?: [] as $f) {
            if ((int)filemtime($f) < time() - 300) {
                @unlink($f);
            }
        }
    }

    return $count;
}

function sec_nonce_check(string $nonce, int $ttl): bool
{
    if ($nonce === '' || strlen($nonce) > 64) {
        return false;
    }
    $file = sec_dir('nonce') . '/' . hash('sha256', $nonce) . '.json';
    if (is_file($file) && (time() - (int)filemtime($file)) < $ttl) {
        return false;     }
    @file_put_contents($file, (string)time());

    if (random_int(1, 100) === 1) {
        foreach (glob(sec_dir('nonce') . '/*.json') ?: [] as $f) {
            if ((int)filemtime($f) < time() - $ttl * 2) {
                @unlink($f);
            }
        }
    }
    return true;
}

function sec_verify_sign(array $params, string $key): bool
{
    if (empty($params['sign']) || !is_string($params['sign']) || $key === '') {
        return false;
    }
    $sign = $params['sign'];
    unset($params['sign']);
    ksort($params);
    $expected = hash_hmac('sha256', http_build_query($params), $key);
    return hash_equals($expected, $sign);
}

function sec_check_timestamp(int $timestamp, int $window): bool
{
    return $window > 0 && abs(time() - $timestamp) <= $window;
}

function sec_normalize_domain(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (!str_contains($input, '://')) {
        $input = 'http://' . $input;
    }
    $host = (string)parse_url($input, PHP_URL_HOST);
    $port = (string)parse_url($input, PHP_URL_PORT);
    $host = strtolower($host);
    if ($host === '') {
        return '';
    }
    return $port !== '' && (int)$port !== 80 && (int)$port !== 443 ? $host . ':' . $port : $host;
}

function sec_params(): array
{
    $keys = ['app_id', 'card_no', 'domain', 'device_code', 'timestamp', 'nonce', 'sign'];
    $out  = [];
    foreach ($keys as $k) {
        if (isset($_REQUEST[$k]) && is_scalar($_REQUEST[$k])) {
            $out[$k] = trim((string)$_REQUEST[$k]);
        }
    }
    return $out;
}

function sec_mask_secret(string $secret): string
{
    $len = strlen($secret);
    if ($len <= 10) {
        return str_repeat('*', $len);
    }
    return substr($secret, 0, 6) . str_repeat('*', 8) . substr($secret, -4);
}

function sec_enc_key(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $key = cfg('sys_encrypt_key', '');
    if ($key === '') {
        $key = bin2hex(random_bytes(32));
        set_cfg('sys_encrypt_key', $key, '敏感字段加密密钥（AES-128-CBC）');
    }
    return $cached = substr(hash('sha256', $key, true), 0, 16);
}

function sec_id_encrypt(string $idCard): string
{
    if ($idCard === '') {
        return '';
    }
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($idCard, 'AES-128-CBC', sec_enc_key(), OPENSSL_RAW_DATA, $iv);
    return $cipher === false ? '' : 'enc1:' . base64_encode($iv . $cipher);
}

function sec_id_decrypt(string $stored): string
{
    if (!str_starts_with($stored, 'enc1:')) {
        return '';
    }
    $raw = base64_decode(substr($stored, 5), true);
    if ($raw === false || strlen($raw) < 32) {
        return '';
    }
    $plain = openssl_decrypt(substr($raw, 16), 'AES-128-CBC', sec_enc_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? '' : $plain;
}

function sec_mask_idcard(string $idCard): string
{
    $len = strlen($idCard);
    if ($len < 10) {
        return str_repeat('*', $len);
    }
    return substr($idCard, 0, 4) . str_repeat('*', $len - 8) . substr($idCard, -4);
}

function sec_check_idcard(string $id): bool
{
    $id = strtoupper(trim($id));
    if (preg_match('/^\d{15}$/', $id)) {
        return true;     }
    if (!preg_match('/^\d{17}[0-9X]$/', $id)) {
        return false;
    }
    $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
    $codes   = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
    $sum = 0;
    for ($i = 0; $i < 17; $i++) {
        $sum += (int)$id[$i] * $weights[$i];
    }
    return $codes[$sum % 11] === $id[17];
}
