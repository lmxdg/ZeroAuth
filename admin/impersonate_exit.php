<?php
// 管理员后台 - 退出代登录
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

config();

$imp  = $_SESSION['impersonate'] ?? null;
$type = is_array($imp) ? (string)($imp['type'] ?? '') : '';
$back = is_array($imp) ? (string)($imp['back'] ?? '') : '';
$impId = is_array($imp) ? (int)($imp['id'] ?? 0) : 0;

unset($_SESSION['impersonate']);
if ($type === 'agent') {
    unset($_SESSION['agent_id']);
} elseif ($type === 'user') {
    unset($_SESSION['user_id']);
}

$admin = current_admin();
if ($admin) {
    audit('impersonate', 'exit', 1, (int)$admin['id'],
        '结束代登录：' . ($type === 'agent' ? '代理商' : '用户') . ' #' . $impId);
}

$allowBack = ['agent_manage.php', 'user_manage.php'];
if ($admin && in_array($back, $allowBack, true)) {
    redirect($back);
}
redirect('dashboard.php');
