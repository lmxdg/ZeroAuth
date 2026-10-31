<?php
// 用户中心 - 退出登录
declare(strict_types=1);
require dirname(__DIR__) . '/core/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    audit('login', 'user_logout', 3, (int)$_SESSION['user_id']);
}
unset($_SESSION['user_id']);
redirect('index.php');
