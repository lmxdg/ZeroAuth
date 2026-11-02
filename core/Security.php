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

    // 使用独占创建避免并发请求同时通过 nonce 校验（重放窗口竞态）
    $fh = @fopen($file, 'x');
    if ($fh === false) {
        if (is_file($file) && (time() - (int)filemtime($file)) < $ttl) {
            return false;
        }
        // 文件已过期的极端情况下覆盖写入
        if (!@file_put_contents($file, (string)time())) {
            return false;
        }
    } else {
        fwrite($fh, (string)time());
        fclose($fh);
    }

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

// 统一读取请求参数：兼容 query / form / JSON body
function sec_request_all(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }
    $data = array_merge($_GET, $_POST);
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
    }
    return $cached = $data;
}

function sec_params(): array
{
    $keys = ['app_id', 'card_no', 'domain', 'device_code', 'timestamp', 'nonce', 'sign', 'payload'];
    $req  = sec_request_all();
    $out  = [];
    foreach ($keys as $k) {
        if (isset($req[$k]) && is_scalar($req[$k])) {
            $out[$k] = trim((string)$req[$k]);
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

// 加密主密钥：首次使用时自动生成并写入 sys_config
function sec_enc_master(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $key = cfg('sys_encrypt_key', '');
    if ($key === '' || strlen($key) < 32) {
        $key = bin2hex(random_bytes(32));
        set_cfg('sys_encrypt_key', $key, '敏感字段与接口加密主密钥（SHA-256 派生）');
    }
    return $cached = $key;
}

// 旧版 AES-128-CBC 密钥（保持与原 enc1 数据完全兼容）
function sec_enc_key(): string
{
    return substr(hash('sha256', sec_enc_master(), true), 0, 16);
}

function sec_gcm_key(): string
{
    return hash('sha256', 'ape-enc-v2|' . sec_enc_master(), true);
}

function sec_encrypt_raw(string $plain, string $key, string $aad = ''): ?string
{
    if ($plain === '' || $key === '' || !function_exists('openssl_encrypt')) {
        return null;
    }
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    if ($cipher === false || strlen($tag) !== 16) {
        return null;
    }
    return $iv . $tag . $cipher;
}

function sec_decrypt_raw(string $raw, string $key, string $aad = ''): ?string
{
    if ($raw === '' || $key === '' || strlen($raw) < 28 || !function_exists('openssl_decrypt')) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
    return $plain === false ? null : $plain;
}

// 敏感字段统一加密：AES-256-GCM，存储格式 enc2:<base64(iv+tag+cipher)>
function sec_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $raw = sec_encrypt_raw($plain, sec_gcm_key(), 'ape-sec-v2');
    return $raw === null ? '' : 'enc2:' . base64_encode($raw);
}

// 兼容解密：enc2（AES-256-GCM）与历史 enc1（AES-128-CBC）
function sec_decrypt(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    if (str_starts_with($stored, 'enc2:')) {
        $raw = base64_decode(substr($stored, 5), true);
        if ($raw === false) {
            return '';
        }
        return sec_decrypt_raw($raw, sec_gcm_key(), 'ape-sec-v2') ?? '';
    }
    if (str_starts_with($stored, 'enc1:')) {
        $raw = base64_decode(substr($stored, 5), true);
        if ($raw === false || strlen($raw) < 32) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 16), 'AES-128-CBC', sec_enc_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $plain === false ? '' : $plain;
    }
    return '';
}

function sec_id_encrypt(string $idCard): string
{
    return sec_encrypt($idCard);
}

function sec_id_decrypt(string $stored): string
{
    return sec_decrypt($stored);
}

// 接口请求体加密（密钥由 AppSecret 派生，AES-256-GCM）
function sec_api_key(string $secret): string
{
    return hash('sha256', 'ape-api-v2|' . $secret, true);
}

function sec_api_encrypt(string $plain, string $secret): string
{
    if ($secret === '') {
        return '';
    }
    $raw = sec_encrypt_raw($plain, sec_api_key($secret), 'ape-api-v2');
    return $raw === null ? '' : rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function sec_api_decrypt(string $payload, string $secret): ?string
{
    if ($payload === '' || $secret === '') {
        return null;
    }
    $b64 = strtr($payload, '-_', '+/');
    $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
    $raw = base64_decode($b64, true);
    if ($raw === false) {
        return null;
    }
    return sec_decrypt_raw($raw, sec_api_key($secret), 'ape-api-v2');
}

// 解密接口 payload 并返回字段数组（客户端使用 AppSecret 派生密钥）
function sec_api_decode_payload(string $payload, string $secret): ?array
{
    $plain = sec_api_decrypt($payload, $secret);
    if ($plain === null) {
        return null;
    }
    $json = json_decode($plain, true);
    return is_array($json) ? $json : null;
}

function sec_api_merge_payload(array $params, array $allowedKeys): array
{
    if (empty($params['payload']) || !is_string($params['payload'])) {
        return $params;
    }
    $secret = (string)($params['__app_secret'] ?? '');
    if ($secret === '') {
        return $params;
    }
    $decoded = sec_api_decode_payload($params['payload'], $secret);
    unset($params['payload'], $params['__app_secret']);
    if ($decoded === null) {
        $params['__payload_error'] = 1;
        return $params;
    }
    foreach ($allowedKeys as $k) {
        if (array_key_exists($k, $decoded) && is_scalar($decoded[$k])) {
            $params[$k] = trim((string)$decoded[$k]);
        }
    }
    return $params;
}

/* ================= TOTP 二次验证（RFC 6238 / SHA1 / 6 位 / 30 秒） ================= */

function sec_base32_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');
    if ($secret === '') {
        return '';
    }
    $out = '';
    $buffer = 0;
    $bits = 0;
    $len = strlen($secret);
    for ($i = 0; $i < $len; $i++) {
        $val = strpos($alphabet, $secret[$i]);
        if ($val === false) {
            return '';
        }
        $buffer = (($buffer << 5) | $val) & 0xFFFFFFFF;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buffer >> $bits) & 0xFF);
        }
    }
    return $out;
}

function sec_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $buffer = 0;
    $bits = 0;
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $buffer = (($buffer << 8) | ord($data[$i])) & 0xFFFFFFFF;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= $alphabet[($buffer >> $bits) & 0x1F];
        }
    }
    if ($bits > 0) {
        $out .= $alphabet[($buffer << (5 - $bits)) & 0x1F];
    }
    return $out;
}

function sec_totp_secret(int $bytes = 20): string
{
    return sec_base32_encode(random_bytes(max(10, min(64, $bytes))));
}

function sec_totp_code(string $secret, int $timestamp = 0, int $period = 30): string
{
    $key = sec_base32_decode($secret);
    if ($key === '') {
        return '';
    }
    $time = $timestamp > 0 ? $timestamp : time();
    $counter = (int)floor($time / max(1, $period));
    $hash = hash_hmac('sha1', pack('N*', 0, $counter), $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $code = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($code % 1000000), 6, '0', STR_PAD_LEFT);
}

function sec_totp_verify(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if ($secret === '' || strlen($code) !== 6) {
        return false;
    }
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $expected = sec_totp_code($secret, $now + $i * 30);
        if ($expected !== '' && hash_equals($expected, $code)) {
            return true;
        }
    }
    return false;
}

function sec_totp_uri(string $secret, string $account, string $issuer = ''): string
{
    $issuer = $issuer !== '' ? $issuer : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'APE');
    $label  = rawurlencode($issuer . ':' . $account);
    return 'otpauth://totp/' . $label
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&period=30&digits=6&algorithm=SHA1';
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

/**
 * 校验密码强度：8~64 位，至少包含字母、数字、符号中的两类，且不能与账号相同。
 *
 * @return array{0:bool,1:string} [是否通过, 错误提示]
 */
function sec_password_check(string $password, string $username = ''): array
{
    if ($password === '') {
        return [false, '密码不能为空。'];
    }
    $len = strlen($password);
    if ($len < 8 || $len > 64) {
        return [false, '密码长度需在 8 ~ 64 位之间。'];
    }

    $classes = 0;
    if (preg_match('/[A-Za-z]/', $password)) {
        $classes++;
    }
    if (preg_match('/[0-9]/', $password)) {
        $classes++;
    }
    if (preg_match('/[^A-Za-z0-9]/', $password)) {
        $classes++;
    }
    if ($classes < 2) {
        return [false, '密码需至少包含字母、数字、符号中的两类。'];
    }

    $username = trim($username);
    if ($username !== '' && strcasecmp($password, $username) === 0) {
        return [false, '密码不能与账号相同。'];
    }

    return [true, ''];
}
