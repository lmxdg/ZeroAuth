<?php
// 彩虹聚合登录插件元数据
declare(strict_types=1);

return [
    'code' => 'rainbow',
    'name' => '彩虹聚合登录',
    'cat' => 'login',
    'description' => '接入彩虹聚合登录（OAuth2.0），支持 QQ / 微信 / 支付宝 / 微博 / 百度 / 抖音 / Gitee / GitHub 等第三方账号快捷登录，可自动注册并绑定站点账号。',
    'version' => '1.0.0',
    'author' => 'ZeroAuth',
    'core' => 0,
    'installable' => 1,
    'status' => 2,
    'sort' => 6,
    'config' => [
        [
            'key'   => 'rainbow_api',
            'label' => '接口地址',
            'type'  => 'text',
            'hint'  => '彩虹聚合登录站点地址，默认 https://uniqueker.top',
        ],
        [
            'key'   => 'rainbow_appid',
            'label' => 'APPID',
            'type'  => 'text',
            'hint'  => '聚合登录平台申请的应用 ID',
        ],
        [
            'key'   => 'rainbow_appkey',
            'label' => 'APPKEY',
            'type'  => 'password',
            'hint'  => '已配置则留空保持不变',
        ],
        [
            'key'   => 'rainbow_types',
            'label' => '启用登录方式',
            'type'  => 'text',
            'hint'  => '英文逗号分隔，可选值：qq,wx,alipay,sina,baidu,douyin,huawei,xiaomi,google,microsoft,facebook,dingtalk,line,gitee,github,gitlab',
        ],
        [
            'key'   => 'rainbow_autoreg',
            'label' => '自动注册新用户',
            'type'  => 'switch',
            'hint'  => '开启后，未绑定的第三方账号首次登录将自动创建用户中心账号',
        ],
    ],
];
