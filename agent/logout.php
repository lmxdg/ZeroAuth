<?php
// 代理商中心 - 退出登录
declare(strict_types=1);
require dirname(__DIR__) . '/core/bootstrap.php';

if (!empty($_SESSION['agent_id'])) {
    audit('login', 'agent_logout', 2, (int)$_SESSION['agent_id']);
}
unset($_SESSION['agent_id']);
redirect('index.php');
