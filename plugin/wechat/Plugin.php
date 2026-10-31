<?php
// 微信支付插件元数据
declare(strict_types=1);

return [
    'code' => 'wechat',
    'name' => '微信支付',
    'cat' => 'pay',
    'description' => '微信 Native 扫码支付，支持下单与回调验签。',
    'version' => '1.0.0',
    'author' => '官方内置',
    'core' => 1,
    'installable' => 1,
    'status' => 1,
    'sort' => 20,
    'config' => array (
  0 => 
  array (
    'key' => 'pay_wechat_app_id',
    'label' => '公众号/应用 AppID',
    'type' => 'text',
    'hint' => '微信支付绑定的 AppID',
  ),
  1 => 
  array (
    'key' => 'pay_wechat_mch_id',
    'label' => '商户号',
    'type' => 'text',
    'hint' => '微信支付商户号',
  ),
  2 => 
  array (
    'key' => 'pay_wechat_key',
    'label' => '商户 API 密钥',
    'type' => 'password',
    'hint' => 'V2 API 密钥（32 位）；已配置则留空保持不变',
  ),
),
];
