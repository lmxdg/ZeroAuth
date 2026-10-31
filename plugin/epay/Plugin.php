<?php
// 易支付插件元数据
declare(strict_types=1);

return [
    'code' => 'epay',
    'name' => '易支付（聚合）',
    'cat' => 'pay',
    'description' => '聚合易支付接口，支持支付宝/微信/QQ 等多子渠道。',
    'version' => '1.0.0',
    'author' => '官方内置',
    'core' => 1,
    'installable' => 1,
    'status' => 1,
    'sort' => 30,
    'config' => array (
  0 => 
  array (
    'key' => 'pay_epay_url',
    'label' => '网关地址',
    'type' => 'text',
    'hint' => '易支付提交地址，如 https://pay.example.com/',
  ),
  1 => 
  array (
    'key' => 'pay_epay_pid',
    'label' => '商户 PID',
    'type' => 'text',
    'hint' => '',
  ),
  2 => 
  array (
    'key' => 'pay_epay_key',
    'label' => '商户密钥',
    'type' => 'password',
    'hint' => '已配置则留空保持不变',
  ),
  3 => 
  array (
    'key' => 'pay_epay_type',
    'label' => '默认子渠道',
    'type' => 'select',
    'options' => 
    array (
      'alipay' => '支付宝',
      'wxpay' => '微信支付',
      'qqpay' => 'QQ 钱包',
    ),
    'default' => 'alipay',
  ),
),
];
