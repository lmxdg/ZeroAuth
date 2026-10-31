<?php
// 管理员后台 - 退出登录
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId > 0) {
    audit('login', 'admin_logout', 1, $adminId);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
}
session_destroy();

header('Location: index.php');
exit;
