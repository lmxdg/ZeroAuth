<?php
// 告警中心：阈值巡检与通知
declare(strict_types=1);

function alert_add(int $level, string $module, string $title, string $content = '', string $ip = ''): int
{
    try {
        $stmt = db()->prepare(
            'SELECT `id` FROM `alert_center` WHERE `is_read`=0 AND `module`=? AND `title`=? AND `created_at`>? LIMIT 1'
        );
        $stmt->execute([$module, $title, time() - 86400]);
        if ($stmt->fetchColumn()) {
            return 0;
        }
        $ins = db()->prepare(
            'INSERT INTO `alert_center` (`level`,`module`,`title`,`content`,`ip`,`is_read`,`created_at`) VALUES (?,?,?,?,?,0,?)'
        );
        $ins->execute([
            $level, mb_substr($module, 0, 30), mb_substr($title, 0, 190),
            mb_substr($content, 0, 480), mb_substr($ip, 0, 44), time(),
        ]);
        return (int)db()->lastInsertId();
    } catch (Throwable $ex) {
        @file_put_contents(RUNTIME_PATH . '/schema_upgrade.log', date('Y-m-d H:i:s') . ' alert写入失败 ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
        return 0;
    }
}
