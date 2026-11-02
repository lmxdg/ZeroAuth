<?php
// 系统公告读取
declare(strict_types=1);

/**
 * 获取当前生效的公告。
 *
 * @param int $target 展示端：1 全部 2 用户中心 3 代理商中心
 */
function notice_active(int $target, int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    $now   = time();
    try {
        $stmt = db()->prepare(
            'SELECT `id`,`title`,`content`,`type`,`created_at` FROM `notice` '
            . 'WHERE `status`=1 AND (`target`=1 OR `target`=?) '
            . 'AND (`start_time`=0 OR `start_time`<=?) '
            . 'AND (`end_time`=0 OR `end_time`>=?) '
            . 'ORDER BY `sort` DESC, `id` DESC LIMIT ?'
        );
        $stmt->execute([$target, $now, $now, $limit]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $ex) {
        return [];
    }
}

function notice_type_name(string $type): string
{
    return [
        'info'    => '通知',
        'success' => '成功',
        'warn'    => '提醒',
        'danger'  => '重要',
    ][$type] ?? '通知';
}

function notice_alert_class(string $type): string
{
    return [
        'info'    => 'alert-info',
        'success' => 'alert-ok',
        'warn'    => 'alert-warn',
        'danger'  => 'alert-error',
    ][$type] ?? 'alert-info';
}

/**
 * 在用户中心 / 代理商中心首页渲染生效公告。
 */
function notice_render(int $target): void
{
    $items = notice_active($target, 5);
    if ($items === []) {
        return;
    }
    ?>
<section class="card notice-card">
    <h2 class="card-title">系统公告</h2>
    <div class="notice-list">
        <?php foreach ($items as $item): ?>
        <div class="notice-item <?php echo e(notice_alert_class((string)$item['type'])); ?>">
            <span class="notice-badge"><?php echo e(notice_type_name((string)$item['type'])); ?></span>
            <div class="notice-body">
                <div class="notice-title"><?php echo e((string)$item['title']); ?></div>
                <div class="notice-content"><?php echo nl2br(e((string)$item['content'])); ?></div>
                <div class="notice-time"><?php echo date('Y-m-d H:i', (int)$item['created_at']); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
    <?php
}

