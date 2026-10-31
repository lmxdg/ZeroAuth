<?php
// 内置支付插件元数据
declare(strict_types=1);

return [
    'code' => 'builtin',
    'name' => '内置实名认证',
    'cat' => 'realname',
    'description' => '姓名+身份证号（GB11643 校验位）本地校验，AES 加密存储，后台人工审核。',
    'version' => '1.0.0',
    'author' => '官方内置',
    'core' => 1,
    'installable' => 1,
    'status' => 1,
    'sort' => 10,
    'config' => array (
),
];
