<?php
// 支付插件：支付宝当面付（RSA2）
declare(strict_types=1);

require_once __DIR__ . '/../common/common.php';

function plugin_alipay_pay(array $order, string $notifyUrl): array
{
    $appId  = cfg('pay_alipay_app_id', '');
    $priKey = cfg('pay_alipay_private_key', '');
    $gateway = rtrim(cfg('pay_alipay_gateway', 'https://openapi.alipay.com/gateway.do'), '/');
    if ($appId === '' || $priKey === '') {
        throw new RuntimeException('支付宝配置不完整，请到后台「应用商店 - 支付宝（当面付）」配置。');
    }

    $params = [
        'app_id'      => $appId,
        'method'      => 'alipay.trade.precreate',
        'format'      => 'JSON',
        'charset'     => 'utf-8',
        'sign_type'   => 'RSA2',
        'timestamp'   => date('Y-m-d H:i:s'),
        'version'     => '1.0',
        'notify_url'  => $notifyUrl,
        'biz_content' => json_encode([
            'out_trade_no' => $order['order_no'],
            'total_amount' => number_format((float)$order['amount'], 2, '.', ''),
            'subject'      => mb_substr($order['subject'], 0, 60, 'UTF-8'),
        ], JSON_UNESCAPED_UNICODE),
    ];
    $params['sign'] = plugin_alipay_sign($params, $priKey);

    $res = plugin_http_post($gateway, http_build_query($params));
    if ($res === null) {
        throw new RuntimeException('支付宝下单请求失败（网络或 curl 扩展问题）。');
    }
    $data = json_decode($res, true);
    $body = $data['alipay_trade_precreate_response'] ?? null;
    if (!is_array($body) || ($body['code'] ?? '') !== '10000') {
        $msg = is_array($body) ? (string)($body['sub_msg'] ?? $body['msg'] ?? '未知错误') : '响应解析失败';
        throw new RuntimeException('支付宝下单失败：' . $msg);
    }
    $qrCode = (string)($body['qr_code'] ?? '');
    if ($qrCode === '') {
        throw new RuntimeException('支付宝下单未返回支付码。');
    }
    return ['type' => 'qrcode', 'qrcode' => $qrCode];
}

function plugin_alipay_verify(array $req): ?array
{
    $pubKey = cfg('pay_alipay_public_key', '');
    $sign = (string)($req['sign'] ?? '');
    if ($pubKey === '' || $sign === '') {
        return null;
    }

    $data = plugin_sign_build($req);     $ok = openssl_verify($data, (string)base64_decode($sign, true), plugin_pem_public($pubKey), OPENSSL_ALGO_SHA256);
    if ($ok !== 1) {
        return null;
    }

    $status = (string)($req['trade_status'] ?? '');
    if (!in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
        return null;
    }
    $orderNo = (string)($req['out_trade_no'] ?? '');
    if ($orderNo === '') {
        return null;
    }
    return [
        'order_no' => $orderNo,
        'trade_no' => (string)($req['trade_no'] ?? ''),
        'money'    => (float)($req['total_amount'] ?? 0),
    ];
}

function plugin_alipay_ack(): string
{
    return 'success';
}

function plugin_alipay_sign(array $params, string $priKeyRaw): string
{
    $data = plugin_sign_build($params);
    $pem = plugin_pem_private($priKeyRaw);
    $pkeyId = openssl_pkey_get_private($pem);
    if ($pkeyId === false) {
        throw new RuntimeException('支付宝应用私钥格式错误，无法签名。');
    }
    $sig = '';
    if (!openssl_sign($data, $sig, $pkeyId, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('支付宝签名失败。');
    }
    return base64_encode($sig);
}
