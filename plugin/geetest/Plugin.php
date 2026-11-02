<?php
// 极验行为验证插件元数据
declare(strict_types=1);

return [
    'code' => 'geetest',
    'name' => '极验4.0 行为验证',
    'cat' => 'captcha',
    'description' => '极验4.0行为验证（三端登录/注册/找回密码启用），替代传统干扰线图片验证码。需在极验后台（geetest.com）创建「行为验证4.0 - Web」应用获取 ID 与 Key。',
    'version' => '1.0.0',
    'author' => 'ZeroAuth',
    'core' => 0,
    'installable' => 1,
    'status' => 2,
    'sort' => 5,
    'config' => [
        [
            'key'   => 'geetest_captcha_id',
            'label' => '验证 ID（captchaId）',
            'type'  => 'text',
            'hint'  => '极验后台申请的行为验证 ID',
        ],
        [
            'key'   => 'geetest_captcha_key',
            'label' => '验证 Key（captchaKey）',
            'type'  => 'password',
            'hint'  => '已配置则留空保持不变；仅用于服务端二次校验',
        ],
    ],
];
