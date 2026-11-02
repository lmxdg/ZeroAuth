<?php
// 支付插件公共函数（MD5 签名/HTTP/PEM）
declare(strict_types=1);

function plugin_http_post(string $url, string $body, array $headers = []): ?string
{
    $resp = http_post($url, $body, $headers, 15);
    return is_string($resp) && $resp !== '' ? $resp : null;
}

function plugin_http_get(string $url, int $timeout = 10): ?string
{
    $resp = http_get($url, $timeout);
    return is_string($resp) && $resp !== '' ? $resp : null;
}

function plugin_pem_private(string $raw): string
{
    $raw = str_replace(["\r", "\n", ' '], ['', '', ''], trim($raw));
    if (str_contains($raw, '-----BEGIN')) {
        return $raw;
    }
    return "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split($raw, 64, "\n") . "-----END RSA PRIVATE KEY-----";
}

function plugin_pem_public(string $raw): string
{
    $raw = str_replace(["\r", "\n", ' '], ['', '', ''], trim($raw));
    if (str_contains($raw, '-----BEGIN')) {
        return $raw;
    }
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($raw, 64, "\n") . "-----END PUBLIC KEY-----";
}

function plugin_sign_build(array $params): string
{
    unset($params['sign'], $params['sign_type']);
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $pairs[] = $k . '=' . $v;
    }
    return implode('&', $pairs);
}

function plugin_sign_md5(array $params, string $key): string
{
    return strtoupper(md5(plugin_sign_build($params) . '&key=' . $key));
}
