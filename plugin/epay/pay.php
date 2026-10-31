<?php
// 支付插件：易支付（MD5 协议）
declare(strict_types=1);

require_once __DIR__ . '/../common/common.php';

function plugin_epay_pay(array $order, string $notifyUrl, string $returnUrl): array
{
    $url = rtrim(cfg('pay_epay_url', ''), '/');
    $pid = cfg('pay_epay_pid', '');
    $key = cfg('pay_epay_key', '');
    $type = cfg('pay_epay_type', 'alipay');
    if ($url === '' || $pid === '' || $key === '') {
        throw new RuntimeException('易支付配置不完整，请到后台「应用商店 - 易支付（聚合）」配置。');
    }

    $params = [
        'pid'          => $pid,
        'type'         => $type,
        'out_trade_no' => $order['order_no'],
        'notify_url'   => $notifyUrl,
        'return_url'   => $returnUrl,
        'name'         => $order['subject'],
        'money'        => number_format((float)$order['amount'], 2, '.', ''),
        'sitename'     => cfg('site_name', '授权系统'),
    ];
    $params['sign']       = plugin_sign_md5($params, $key);
    $params['sign_type']  = 'MD5';

    return ['type' => 'redirect', 'pay_url' => $url . '/submit.php?' . http_build_query($params)];
}

function plugin_epay_verify(array $req): ?array
{
    $key = cfg('pay_epay_key', '');
    $sign = (string)($req['sign'] ?? '');
    if ($key === '' || $sign === '') {
        return null;
    }

    $mine = plugin_sign_md5($req, $key);
    if (!hash_equals($mine, strtoupper($sign))) {
        return null;
    }
    if (($req['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
        return null;
    }
    $orderNo = (string)($req['out_trade_no'] ?? '');
    if ($orderNo === '') {
        return null;
    }
    return [
        'order_no' => $orderNo,
        'trade_no' => (string)($req['trade_no'] ?? ''),
        'money'    => (float)($req['money'] ?? 0),
    ];
}

function plugin_epay_ack(): string
{
    return 'success';
}
