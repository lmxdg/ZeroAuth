<?php
// 管理员后台 - 验证日志
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$resultMap = [
    1 => ['成功', 'tag-ok'],
    2 => ['失败', 'tag-err'],
];

$todayStart = strtotime(date('Y-m-d') . ' 00:00:00');

if (is_post()) {
    csrf_check();
    $postAction = (string)($_POST['action'] ?? '');
    if ($postAction === 'clean') {
        $before = time() - 90 * 86400;
        $stmt = db()->prepare('DELETE FROM `verify_log` WHERE `created_at`<?');
        $stmt->execute([$before]);
        $n = $stmt->rowCount();
        audit('verify_log', 'clean', 1, (int)$admin['id'], '清理 90 天前验证日志 ' . $n . ' 条');
        flash_set('ok', '已清理 ' . date('Y-m-d H:i', $before) . ' 之前的验证日志 ' . $n . ' 条。');
        redirect('verify_logs.php');
    }
    if ($postAction === 'clear_all') {
        $n = (int)db()->query('SELECT COUNT(*) FROM `verify_log`')->fetchColumn();
        db()->exec('DELETE FROM `verify_log`');
        audit('verify_log', 'clear_all', 1, (int)$admin['id'], '清空全部验证日志 ' . $n . ' 条');
        flash_set('ok', '已清空全部验证日志 ' . $n . ' 条。');
        redirect('verify_logs.php');
    }
}

$stat = ['total' => 0, 'ok' => 0, 'fail' => 0];
try {
    $stmt = db()->prepare('SELECT `result`, COUNT(*) AS `c` FROM `verify_log` WHERE `created_at`>=? GROUP BY `result`');
    $stmt->execute([$todayStart]);
    foreach ($stmt->fetchAll() as $v) {
        if ((int)$v['result'] === 1) {
            $stat['ok'] = (int)$v['c'];
        } else {
            $stat['fail'] += (int)$v['c'];
        }
    }
    $stat['total'] = $stat['ok'] + $stat['fail'];
} catch (Throwable $ex) {  }

$successRate = $stat['total'] > 0 ? round($stat['ok'] / $stat['total'] * 100, 1) . '%' : '-';

$filterResult = (string)($_GET['result'] ?? '');
$filterApp    = (int)($_GET['app_id'] ?? 0);
$filterKw     = trim((string)($_GET['kw'] ?? ''));
$filterStart  = trim((string)($_GET['start'] ?? ''));
$filterEnd    = trim((string)($_GET['end'] ?? ''));

$where  = '';
$params = [];
if ($filterResult !== '' && isset($resultMap[(int)$filterResult])) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `result`=?';
    $params[] = (int)$filterResult;
}
if ($filterApp > 0) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `app_id`=?';
    $params[] = $filterApp;
}
if ($filterKw !== '') {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' (`card_no` LIKE ? OR `ip` LIKE ? OR `domain` LIKE ?)';
    $params = array_merge($params, ['%' . $filterKw . '%', '%' . $filterKw . '%', '%' . $filterKw . '%']);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterStart)) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `created_at`>=?';
    $params[] = strtotime($filterStart . ' 00:00:00');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterEnd)) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `created_at`<=?';
    $params[] = strtotime($filterEnd . ' 23:59:59');
}

$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));

$stmt = db()->prepare('SELECT COUNT(*) FROM `verify_log`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$queryParams   = $params;
$queryParams[] = $perPage;
$queryParams[] = $offset;

$stmt = db()->prepare(
    'SELECT v.`id`, v.`card_no`, v.`ip`, v.`domain`, v.`device_code`, v.`result`, v.`fail_reason`, v.`created_at`, a.`name` AS `app_name` '
    . 'FROM `verify_log` v LEFT JOIN `app` a ON a.`id`=v.`app_id`' . $where . ' ORDER BY v.`id` DESC LIMIT ? OFFSET ?'
);
$stmt->execute($queryParams);
$rows = $stmt->fetchAll();

admin_header('验证日志', 'verify_logs', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<section class="card">
    <h2 class="card-title">今日概览</h2>
    <div style="display:flex;flex-wrap:wrap;gap:48px">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $stat['total']; ?></div>
            <div class="form-hint" style="margin-top:2px">今日验证</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--success)"><?php echo $stat['ok']; ?></div>
            <div class="form-hint" style="margin-top:2px">今日成功</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--error)"><?php echo $stat['fail']; ?></div>
            <div class="form-hint" style="margin-top:2px">今日失败</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:<?php echo $successRate === '-' ? 'var(--text-main)' : 'var(--success)'; ?>"><?php echo $successRate; ?></div>
            <div class="form-hint" style="margin-top:2px">成功率</div>
        </div>
    </div>
</section>

<section class="card">
    <h2 class="card-title">筛选条件</h2>
    <form method="get" class="filter-bar" style="flex-wrap:wrap">
        <select class="form-input" name="result" style="max-width:120px">
            <option value="">全部结果</option>
            <?php foreach ($resultMap as $k => [$name, $tag]): ?>
            <option value="<?php echo $k; ?>" <?php echo $filterResult === (string)$k ? 'selected' : ''; ?>><?php echo e($name); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="app_id" style="max-width:150px">
            <option value="">全部应用</option>
            <?php foreach (db()->query('SELECT `id`,`name` FROM `app` ORDER BY `id` DESC')->fetchAll() as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>" <?php echo $filterApp === (int)$a['id'] ? 'selected' : ''; ?>><?php echo e($a['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <input class="form-input" type="date" name="start" value="<?php echo e($filterStart); ?>" style="max-width:160px">
        <span style="color:var(--text-third)">~</span>
        <input class="form-input" type="date" name="end" value="<?php echo e($filterEnd); ?>" style="max-width:160px">
        <input class="form-input" type="text" name="kw" value="<?php echo e($filterKw); ?>" placeholder="卡密 / IP / 域名" style="max-width:220px">
        <button class="btn-plain" type="submit">查询</button>
    </form>
</section>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">验证日志（共 <?php echo $total; ?> 条）</h2>
        <div class="spacer"></div>
        <form method="post" style="display:flex">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clean">
            <input type="hidden" name="days" value="90">
            <button class="btn-danger-mini btn-mini" type="submit" data-confirm="确认清理 90 天前的验证日志？该操作不可恢复。">清理 90 天前日志</button>
        </form>
        <form method="post" style="display:flex;margin-left:8px">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clear_all">
            <button class="btn-danger-mini btn-mini" type="submit" data-confirm="确定清空全部验证日志吗？该操作不可恢复。">清空全部日志</button>
        </form>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>时间</th><th>卡密</th><th>应用</th><th>IP</th><th>归属地</th><th>域名</th><th>设备码</th><th style="width:70px">结果</th><th>失败原因</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): [$rName, $rTag] = $resultMap[(int)$row['result']] ?? ['未知', 'tag-off']; ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i:s', (int)$row['created_at']); ?></td>
                <td class="cell-code"><?php echo e($row['card_no']); ?></td>
                <td class="cell-sub"><?php echo e($row['app_name'] ?: '-'); ?></td>
                <td class="cell-code"><?php echo e($row['ip']); ?></td>
                <td><?php echo ip_geo_badge((string)$row['ip']); ?></td>
                <td class="cell-sub"><?php echo $row['domain'] !== '' ? e($row['domain']) : '-'; ?></td>
                <td class="cell-code" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo $row['device_code'] !== '' ? e(mb_substr($row['device_code'], 0, 18) . '…') : '-'; ?></td>
                <td><span class="tag <?php echo $rTag; ?>"><?php echo e($rName); ?></span></td>
                <td class="cell-sub" style="max-width:220px;word-break:break-all"><?php echo $row['fail_reason'] !== '' ? e($row['fail_reason']) : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $baseQuery = 'verify_logs.php?result=' . urlencode($filterResult) . '&app_id=' . urlencode((string)$filterApp)
        . '&kw=' . urlencode($filterKw) . '&start=' . urlencode($filterStart) . '&end=' . urlencode($filterEnd) . '&';
    render_pagination($total, $page, $perPage, $baseQuery);
    ?>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
