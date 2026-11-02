<?php
// 接口公钥与请求加密参数（供客户端拉取并校验 /api/license/verify 响应签名）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/LicenseApi.php';

header('Content-Type: application/json; charset=utf-8');

$ip = client_ip();

$threshold = (int)cfg('secret_alert_threshold', '60');
if ($threshold > 0 && sec_rate_hit('public_key', $ip, $threshold) > $threshold) {
    json_out(429, '请求过于频繁，请稍后再试');
}
if (sec_ip_banned($ip)) {
    json_out(403, 'IP 已被封禁');
}

$keypair = license_api_rsa_keypair();
$pem     = (string)($keypair['public'] ?? '');

json_out(200, 'success', [
    'version'      => '2.0',
    'signAlg'      => 'RSA-SHA256',
    'publicKey'    => $pem,
    'requestCrypt' => [
        'alg'           => 'AES-256-GCM',
        'keyDerivation' => 'SHA-256("ape-api-v2|" . appSecret) 原始 32 字节',
        'payloadFormat' => 'base64url(12字节IV || 密文 || 16字节认证标签)',
        'field'         => 'payload',
        'outerFields'   => ['appKey / app_id 用于服务端定位应用密钥'],
        'endpoints'     => [
            '/api/license/verify',
            '/api/auth.php',
            '/api/get_secret.php',
            '/api/cloud_var.php',
        ],
    ],
    'serverTime'   => time(),
]);
