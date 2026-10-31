<?php
// 彩虹聚合登录：公共函数（登录方式表 / 配置读取）
declare(strict_types=1);

require_once ROOT_PATH . '/core/PluginSys.php';

function rainbow_api_base(): string
{
    $v = rtrim(trim(plugin_cfg_get('rainbow', 'rainbow_api', '')), '/');
    return $v !== '' ? $v : 'https://uniqueker.top';
}

function rainbow_types_map(): array
{
    return [
        'qq'        => 'QQ',
        'wx'        => '微信',
        'alipay'    => '支付宝',
        'sina'      => '微博',
        'baidu'     => '百度',
        'douyin'    => '抖音',
        'huawei'    => '华为',
        'xiaomi'    => '小米',
        'google'    => '谷歌',
        'microsoft' => '微软',
        'facebook'  => 'Facebook',
        'dingtalk'  => '钉钉',
        'line'      => 'Line',
        'gitee'     => 'Gitee',
        'github'    => 'GitHub',
        'gitlab'    => 'GitLab',
    ];
}

// 插件已启用且配置完整时，返回可用于展示按钮的登录方式列表
function rainbow_active_types(): array
{
    if (!function_exists('plugin_status_enabled') || !plugin_status_enabled('rainbow')) {
        return [];
    }
    $appid  = trim(plugin_cfg_get('rainbow', 'rainbow_appid', ''));
    $appkey = trim(plugin_cfg_get('rainbow', 'rainbow_appkey', ''));
    if ($appid === '' || $appkey === '') {
        return [];
    }
    $map  = rainbow_types_map();
    $list = [];
    foreach (explode(',', (string)plugin_cfg_get('rainbow', 'rainbow_types', 'qq')) as $t) {
        $t = strtolower(trim($t));
        if ($t !== '' && isset($map[$t]) && !in_array($t, $list, true)) {
            $list[] = $t;
        }
    }
    return $list;
}

// 回调地址：本插件 callback.php 的绝对地址
function rainbow_redirect_uri(): string
{
    return base_url() . 'plugin/rainbow/callback.php';
}

// 绑定表懒创建
function rainbow_table_ensure(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS `rainbow_bind` ('
        . '`id` int unsigned NOT NULL AUTO_INCREMENT,'
        . '`user_id` int unsigned NOT NULL COMMENT "站点用户ID",'
        . '`login_type` varchar(24) NOT NULL COMMENT "登录方式",'
        . '`social_uid` varchar(64) NOT NULL COMMENT "第三方UID",'
        . '`nickname` varchar(64) NOT NULL DEFAULT "" COMMENT "第三方昵称",'
        . '`faceimg` varchar(255) NOT NULL DEFAULT "" COMMENT "第三方头像",'
        . '`created_at` int unsigned NOT NULL DEFAULT 0,'
        . 'PRIMARY KEY (`id`),'
        . 'UNIQUE KEY `uk_type_uid` (`login_type`,`social_uid`),'
        . 'KEY `idx_user` (`user_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT="彩虹聚合登录绑定"'
    );
}
