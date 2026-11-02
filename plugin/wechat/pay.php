<?php
// 支付插件：微信扫码（Native V2）
declare(strict_types=1);

require_once __DIR__ . '/../common/common.php';

const WECHAT_UNIFIED_URL = 'https://api.mch.weixin.qq.com/pay/unifiedorder';

function plugin_wechat_pay(array $order, string $notifyUrl): array
{
    $appId = cfg('pay_wechat_app_id', '');
    $mchId = cfg('pay_wechat_mch_id', '');
    $key   = cfg('pay_wechat_key', '');
    if ($appId === '' || $mchId === '' || $key === '') {
        throw new RuntimeException('微信支付配置不完整，请到后台「应用商店 - 微信扫码支付」配置。');
    }

    $params = [
        'appid'            => $appId,
        'mch_id'           => $mchId,
        'nonce_str'        => bin2hex(random_bytes(8)),
        'body'             => mb_substr($order['subject'], 0, 60, 'UTF-8'),
        'out_trade_no'     => $order['order_no'],
        'total_fee'        => (string)max(1, (int)round(((float)$order['amount']) * 100)),
        'spbill_create_ip' => client_ip(),
        'notify_url'       => $notifyUrl,
        'trade_type'       => 'NATIVE',
    ];
    $params['sign'] = plugin_sign_md5($params, $key);

    $xml = plugin_wechat_to_xml($params);
    $res = plugin_http_post(WECHAT_UNIFIED_URL, $xml, ['Content-Type: text/xml']);
    if ($res === null) {
        throw new RuntimeException('微信下单请求失败（网络或 curl 扩展问题）。');
    }

    $data = plugin_wechat_from_xml($res);
    if (!$data || ($data['return_code'] ?? '') !== 'SUCCESS') {
        throw new RuntimeException('微信下单失败：' . (string)($data['return_msg'] ?? '响应解析失败'));
    }
    if (($data['result_code'] ?? '') !== 'SUCCESS') {
        throw new RuntimeException('微信下单失败：' . (string)($data['err_code_des'] ?? $data['err_code'] ?? '未知错误'));
    }

    if (!hash_equals(plugin_sign_md5($data, $key), (string)($data['sign'] ?? ''))) {
        throw new RuntimeException('微信下单响应验签失败。');
    }
    $codeUrl = (string)($data['code_url'] ?? '');
    if ($codeUrl === '') {
        throw new RuntimeException('微信下单未返回支付码。');
    }
    return ['type' => 'qrcode', 'qrcode' => $codeUrl];
}

function plugin_wechat_verify(string $rawXml): ?array
{
    $key = cfg('pay_wechat_key', '');
    if ($key === '') {
        return null;
    }
    $data = plugin_wechat_from_xml($rawXml);
    if (!$data) {
        return null;
    }
    $sign = (string)($data['sign'] ?? '');
    if ($sign === '' || !hash_equals(plugin_sign_md5($data, $key), $sign)) {
        return null;
    }
    if (($data['return_code'] ?? '') !== 'SUCCESS' || ($data['result_code'] ?? '') !== 'SUCCESS') {
        return null;
    }
    $orderNo = (string)($data['out_trade_no'] ?? '');
    if ($orderNo === '') {
        return null;
    }
    return [
        'order_no' => $orderNo,
        'trade_no' => (string)($data['transaction_id'] ?? ''),
        'money'    => ((int)($data['total_fee'] ?? 0)) / 100,
    ];
}

function plugin_wechat_ack(): string
{
    return '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
}

function plugin_wechat_to_xml(array $params): string
{
    $xml = '<xml>';
    foreach ($params as $k => $v) {
        $xml .= '<' . $k . '><![CDATA[' . $v . ']]></' . $k . '>';
    }
    return $xml . '</xml>';
}

function plugin_wechat_from_xml(string $xml): ?array
{
    if (trim($xml) === '') {
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    $obj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($obj === false) {
        return null;
    }
    $out = [];
    foreach ((array)$obj as $k => $v) {
        $out[$k] = is_string($v) ? trim($v) : $v;
    }
    return $out;
}
