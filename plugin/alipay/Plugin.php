<?php
// 支付宝插件元数据
declare(strict_types=1);

return [
    'code' => 'alipay',
    'name' => '支付宝（当面付）',
    'cat' => 'pay',
    'description' => '支付宝当面付扫码支付，支持下单与回调验签。',
    'version' => '1.0.0',
    'author' => '官方内置',
    'core' => 1,
    'installable' => 1,
    'status' => 1,
    'sort' => 10,
    'config' => array (
  0 => 
  array (
    'key' => 'pay_alipay_app_id',
    'label' => '应用 AppID',
    'type' => 'text',
    'hint' => '支付宝开放平台应用 APPID',
  ),
  1 => 
  array (
    'key' => 'pay_alipay_private_key',
    'label' => '应用私钥',
    'type' => 'textarea',
    'secret' => 1,
    'hint' => '应用私钥（原始格式，不含 BEGIN/END 行）；已配置则留空保持不变',
  ),
  2 => 
  array (
    'key' => 'pay_alipay_public_key',
    'label' => '支付宝公钥',
    'type' => 'textarea',
    'hint' => '支付宝公钥（用于回调验签）',
  ),
  3 => 
  array (
    'key' => 'pay_alipay_gateway',
    'label' => '网关地址',
    'type' => 'text',
    'default' => 'https://openapi.alipay.com/gateway.do',
  ),
),
];
