<?php
// 管理员后台 - 授权总览
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$todayStart = strtotime(date('Y-m-d') . ' 00:00:00');
$weekStart  = time() - 7 * 86400;

$stat = ['total' => 0, 'ok' => 0, 'ban' => 0, 'expired' => 0, 'devices' => 0, 'domains' => 0, 'today_total' => 0, 'today_ok' => 0];

try {
    $r = db()->query('SELECT `status`, COUNT(*) AS `c` FROM `license` GROUP BY `status`')->fetchAll();
    foreach ($r as $s) {
        if ((int)$s['status'] === 2) {
            $stat['ban'] = (int)$s['c'];
        } else {
            $stat['ok'] = (int)$s['c'];
        }
    }
    $stat['total'] = $stat['ok'] + $stat['ban'];

    $stat['expired'] = (int)db()->query('SELECT COUNT(*) FROM `license` WHERE `type`=1 AND `expire_time`>0 AND `expire_time`<' . time())->fetchColumn();
    $stat['devices'] = (int)db()->query('SELECT COUNT(*) FROM `license_device` WHERE `status`=1')->fetchColumn();
    $stat['domains'] = (int)db()->query('SELECT COUNT(*) FROM `license_domain` WHERE `status`=1')->fetchColumn();

    $stmt = db()->prepare('SELECT `result`, COUNT(*) AS `c` FROM `verify_log` WHERE `created_at`>=? GROUP BY `result`');
    $stmt->execute([$todayStart]);
    foreach ($stmt->fetchAll() as $v) {
        $stat['today_total'] += (int)$v['c'];
        if ((int)$v['result'] === 1) {
            $stat['today_ok'] = (int)$v['c'];
        }
    }
} catch (Throwable $ex) {  }

$successRate = $stat['today_total'] > 0 ? round($stat['today_ok'] / $stat['today_total'] * 100, 1) . '%' : '-';

$appStats = [];
try {
    $stmt = db()->prepare(
        'SELECT a.`id`, a.`name`, COUNT(l.`id`) AS `lic_total`, '
        . 'SUM(CASE WHEN l.`status`=1 THEN 1 ELSE 0 END) AS `lic_ok`, '
        . 'SUM(CASE WHEN l.`status`=2 THEN 1 ELSE 0 END) AS `lic_ban`, '
        . '(SELECT COUNT(*) FROM `license_device` d JOIN `license` l2 ON l2.`id`=d.`license_id` WHERE l2.`app_id`=a.`id` AND d.`status`=1) AS `device_cnt` '
        . 'FROM `app` a LEFT JOIN `license` l ON l.`app_id`=a.`id` '
        . 'GROUP BY a.`id`, a.`name` ORDER BY `lic_total` DESC, a.`id` DESC'
    );
    $stmt->execute();
    $appStats = $stmt->fetchAll();
} catch (Throwable $ex) {  }

$weekVerify = [];
try {
    $stmt = db()->prepare('SELECT `app_id`, COUNT(*) AS `c` FROM `verify_log` WHERE `created_at`>=? GROUP BY `app_id`');
    $stmt->execute([$weekStart]);
    foreach ($stmt->fetchAll() as $v) {
        $weekVerify[(int)$v['app_id']] = (int)$v['c'];
    }
} catch (Throwable $ex) {  }

$latest = [];
try {
    $latest = db()->query(
        'SELECT v.`id`, v.`card_no`, v.`ip`, v.`domain`, v.`result`, v.`fail_reason`, v.`created_at`, a.`name` AS `app_name` '
        . 'FROM `verify_log` v LEFT JOIN `app` a ON a.`id`=v.`app_id` ORDER BY v.`id` DESC LIMIT 10'
    )->fetchAll();
} catch (Throwable $ex) {  }

admin_header('授权概览', 'lic_over', $admin);
?>

<section class="card">
    <h2 class="card-title">卡密统计</h2>
    <div style="display:flex;flex-wrap:wrap;gap:48px">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $stat['total']; ?></div>
            <div class="form-hint" style="margin-top:2px">卡密总数</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--success)"><?php echo $stat['ok']; ?></div>
            <div class="form-hint" style="margin-top:2px">正常</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--error)"><?php echo $stat['ban']; ?></div>
            <div class="form-hint" style="margin-top:2px">封禁</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--warning)"><?php echo $stat['expired']; ?></div>
            <div class="form-hint" style="margin-top:2px">限时已过期</div>
        </div>
    </div>
</section>

<section class="card">
    <h2 class="card-title">使用统计</h2>
    <div style="display:flex;flex-wrap:wrap;gap:48px">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $stat['devices']; ?></div>
            <div class="form-hint" style="margin-top:2px">绑定设备数</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $stat['domains']; ?></div>
            <div class="form-hint" style="margin-top:2px">绑定域名数</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $stat['today_total']; ?></div>
            <div class="form-hint" style="margin-top:2px">今日验证次数</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:<?php echo $successRate === '-' ? 'var(--text-main)' : 'var(--success)'; ?>"><?php echo $successRate; ?></div>
            <div class="form-hint" style="margin-top:2px">今日成功率</div>
        </div>
    </div>
</section>

<section class="card">
    <h2 class="card-title">按应用统计</h2>
    <?php if (!$appStats): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>应用名</th><th>卡密数</th><th>正常</th><th>封禁</th><th>绑定设备数</th><th>近7天验证次数</th></tr></thead>
            <tbody>
            <?php foreach ($appStats as $row): ?>
            <tr>
                <td><?php echo e($row['name']); ?></td>
                <td class="cell-code"><?php echo (int)$row['lic_total']; ?></td>
                <td class="cell-sub"><?php echo (int)$row['lic_ok']; ?></td>
                <td class="cell-sub"><?php echo (int)$row['lic_ban']; ?></td>
                <td class="cell-sub"><?php echo (int)$row['device_cnt']; ?></td>
                <td class="cell-sub"><?php echo $weekVerify[(int)$row['id']] ?? 0; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2 class="card-title">最新验证记录（10 条）</h2>
    <?php if (!$latest): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>时间</th><th>卡密</th><th>应用</th><th>IP</th><th>域名</th><th style="width:140px">结果</th></tr></thead>
            <tbody>
            <?php foreach ($latest as $row): ?>
            <tr>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i:s', (int)$row['created_at']); ?></td>
                <td class="cell-code"><?php echo e($row['card_no']); ?></td>
                <td class="cell-sub"><?php echo e($row['app_name'] ?: '-'); ?></td>
                <td class="cell-code"><?php echo e($row['ip']); ?></td>
                <td class="cell-sub"><?php echo e($row['domain'] !== '' ? $row['domain'] : '-'); ?></td>
                <td>
                    <?php if ((int)$row['result'] === 1): ?>
                    <span class="tag tag-ok">成功</span>
                    <?php else: ?>
                    <span class="tag tag-err">失败</span>
                    <?php if ($row['fail_reason'] !== ''): ?><span class="cell-sub" style="margin-left:8px"><?php echo e($row['fail_reason']); ?></span><?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
