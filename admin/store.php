<?php
// 管理员后台 - 旧「应用商店」入口兼容跳转：V2.2.0 起拆分为「插件商店」与「主题商店」
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

admin_require_login();
redirect('plugin_store.php');
