<?php
// Cloudflare Turnstile 人机验证插件元数据
declare(strict_types=1);

return [
    'code' => 'turnstile',
    'name' => 'Cloudflare Turnstile 验证',
    'cat' => 'captcha',
    'description' => 'Cloudflare Turnstile 无感人机验证，替代传统图形验证码与极验。需在 Cloudflare Dashboard → Turnstile 创建站点获取 Site Key 与 Secret Key。启用后优先级高于极验与图形验证码。',
    'version' => '1.0.0',
    'author' => 'ZeroAuth',
    'core' => 0,
    'installable' => 1,
    'status' => 2,
    'sort' => 4,
    'config' => [
        [
            'key'   => 'turnstile_site_key',
            'label' => '站点密钥（Site Key）',
            'type'  => 'text',
            'hint'  => 'Cloudflare Turnstile 站点密钥，前台可见',
        ],
        [
            'key'   => 'turnstile_secret_key',
            'label' => '密钥（Secret Key）',
            'type'  => 'password',
            'hint'  => '已配置则留空保持不变；仅用于服务端校验',
        ],
    ],
];
