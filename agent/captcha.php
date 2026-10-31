<?php
// 代理商中心 - 图形验证码（统一渲染：GD 可用输出 PNG，否则自动降级 SVG，绝不输出空白图）
declare(strict_types=1);

// 验证码端点禁止任何错误/警告文本混入图片二进制流（open_basedir 等环境防护）
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
require dirname(__DIR__) . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/Security.php';
if (sec_rate_hit('captcha', client_ip(), 60) > 60) {
    captcha_busy_output();
}
captcha_render();
