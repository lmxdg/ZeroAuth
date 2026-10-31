<?php
// 余额支付插件元数据
declare(strict_types=1);

return [
    'code' => 'balance',
    'name' => '余额支付',
    'cat' => 'pay',
    'description' => '用户余额抵扣支付，余额不足时整体回滚，后台支付配置可控制入口。',
    'version' => '1.0.0',
    'author' => '官方内置',
    'core' => 1,
    'installable' => 1,
    'status' => 1,
    'sort' => 40,
    'config' => array (
),
];
