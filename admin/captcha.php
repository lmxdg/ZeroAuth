<?php
// 管理员后台 - 图形验证码（与用户端/代理端统一渲染，修复无 GD 环境输出 1x1 空白图的问题）
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
