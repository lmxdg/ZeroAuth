<?php
// 管理员后台 - 盗版追踪
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$threshold = max(2, (int)cfg('piracy_ip_threshold', '3'));

if (is_post()) {
    csrf_check();
    if ((string)($_POST['action'] ?? '') === 'blacklist') {
        $cardNo = trim((string)($_POST['card_no'] ?? ''));
        if ($cardNo === '') {
            flash_set('error', '卡密不能为空。');
            redirect('piracy_track.php');
        }
        $stmt = db()->prepare('INSERT IGNORE INTO `blacklist` (`type`,`value`,`reason`,`status`,`created_at`) VALUES (2,?,?,1,?)');
        $stmt->execute([$cardNo, '盗版风险：多IP使用', time()]);
        if ($stmt->rowCount() > 0) {
            audit('piracy', 'blacklist', 1, (int)$admin['id'], '卡密：' . $cardNo . ' 原因：盗版风险：多IP使用');
            flash_set('ok', '卡密「' . $cardNo . '」已加入黑名单（卡密类），验证接口将立即拒绝。');
        } else {
            flash_set('error', '卡密「' . $cardNo . '」已在黑名单中，无需重复添加。');
        }
    }
    redirect('piracy_track.php');
}

$riskRows = [];
try {
    $stmt = db()->prepare(
        'SELECT l.`id`, l.`card_no`, a.`name` AS `app_name`, '
        . 'COUNT(DISTINCT d.`bind_ip`) AS `ip_count`, '
        . 'COUNT(*) AS `device_count`, '
        . 'MAX(d.`last_verify_time`) AS `last_verify` '
        . 'FROM `license_device` d '
        . 'INNER JOIN `license` l ON l.`id`=d.`license_id` '
        . 'LEFT JOIN `app` a ON a.`id`=l.`app_id` '
        . 'LEFT JOIN `user` u ON l.`owner_type`=1 AND u.`id`=l.`owner_id` '
        . 'LEFT JOIN `agent` g ON l.`owner_type`=2 AND g.`id`=l.`owner_id` '
        . 'WHERE (l.`owner_type`=1 AND u.`id` IS NOT NULL) OR (l.`owner_type`=2 AND g.`id` IS NOT NULL) '
        . 'GROUP BY l.`id`, l.`card_no`, a.`name` '
        . 'HAVING COUNT(DISTINCT d.`bind_ip`) >= 2 '
        . 'ORDER BY `ip_count` DESC, `last_verify` DESC '
        . 'LIMIT 100'
    );
    $stmt->execute();
    $riskRows = $stmt->fetchAll();
} catch (Throwable $ex) {  }

$ipMap     = [];
$blacklisted = [];
if ($riskRows) {
    $ids = array_map(static fn ($r) => (int)$r['id'], $riskRows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT `license_id`, `bind_ip` FROM `license_device` WHERE `license_id` IN (' . $in . ') GROUP BY `license_id`, `bind_ip`');
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $r) {
        $ipMap[(int)$r['license_id']][] = (string)$r['bind_ip'];
    }

    $cards = array_map(static fn ($r) => (string)$r['card_no'], $riskRows);
    $inC   = implode(',', array_fill(0, count($cards), '?'));
    $stmt  = db()->prepare('SELECT `value` FROM `blacklist` WHERE `type`=2 AND `status`=1 AND `value` IN (' . $inC . ')');
    $stmt->execute($cards);
    foreach ($stmt->fetchAll() as $r) {
        $blacklisted[(string)$r['value']] = true;
    }
}

admin_header('盗版追踪', 'piracy', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">盗版追踪</h2>
            <p class="card-desc" style="margin-bottom:0">
                原理：同一卡密在多个不同 IP 验证使用视为盗版风险（一卡多机分发）。当前阈值为
                <span class="cell-code"><?php echo $threshold; ?></span> 个 IP，
                达到阈值标记「高风险」，2 个及以上标记「关注」。仅统计归属用户 / 代理商的卡密。
            </p>
        </div>
    </div>

    <?php if (!$riskRows): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr>
                <th>卡密</th><th>应用</th><th style="width:90px">风险等级</th><th>IP 数</th><th>设备数</th><th>IP 列表</th><th>最近验证时间</th><th style="width:110px">操作</th>
            </tr></thead>
            <tbody>
            <?php foreach ($riskRows as $row):
                $licenseId = (int)$row['id'];
                $cardNo    = (string)$row['card_no'];
                $ipCount   = (int)$row['ip_count'];
                $ips       = $ipMap[$licenseId] ?? [];
                $shownIps  = array_slice($ips, 0, 5);
                $extra     = max(0, count($ips) - 5);
                $isBlack   = isset($blacklisted[$cardNo]);
            ?>
            <tr>
                <td class="cell-code cell-main"><?php echo e($cardNo); ?></td>
                <td class="cell-sub"><?php echo e($row['app_name'] ?: '已删应用'); ?></td>
                <td>
                    <?php if ($isBlack): ?>
                    <span class="tag tag-off">已拉黑</span>
                    <?php elseif ($ipCount >= $threshold): ?>
                    <span class="tag tag-err">高风险</span>
                    <?php else: ?>
                    <span class="tag tag-warn">关注</span>
                    <?php endif; ?>
                </td>
                <td class="cell-code" style="<?php echo $ipCount >= $threshold ? 'color:var(--error);font-weight:600' : ''; ?>"><?php echo $ipCount; ?></td>
                <td class="cell-sub"><?php echo (int)$row['device_count']; ?></td>
                <td style="max-width:340px">
                    <?php if (!$shownIps): ?>
                    <span class="cell-sub">-</span>
                    <?php else: ?>
                        <?php foreach ($shownIps as $i => $ip): ?>
                            <?php if ($i > 0): ?><span class="cell-sub">, </span><?php endif; ?>
                            <span class="cell-code"><?php echo e($ip); ?></span>
                        <?php endforeach; ?>
                        <?php if ($extra > 0): ?><span class="cell-sub"> 等（其余 <?php echo $extra; ?> 个）</span><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="cell-sub" style="white-space:nowrap"><?php echo (int)$row['last_verify'] > 0 ? date('Y-m-d H:i', (int)$row['last_verify']) : '-'; ?></td>
                <td>
                    <?php if ($isBlack): ?>
                    <span class="cell-sub">-</span>
                    <?php else: ?>
                    <form method="post" style="display:inline" data-confirm="确定将卡密「<?php echo e($cardNo); ?>」加入黑名单吗？加入后该卡密将无法通过验证。">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="blacklist">
                        <input type="hidden" name="card_no" value="<?php echo e($cardNo); ?>">
                        <button class="btn-danger-mini" type="submit">加入黑名单</button>
                    </form>
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
