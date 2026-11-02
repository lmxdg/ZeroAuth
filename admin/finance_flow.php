<?php
// 管理员后台 - 财务流水
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$typeMap = [1 => '购买授权', 2 => '余额充值', 3 => '开通等级', 4 => '升级等级'];
$utNames = [1 => '用户', 2 => '代理商'];

$ut    = (string)($_GET['ut'] ?? '');
$type  = (string)($_GET['type'] ?? '');
$kw    = trim((string)($_GET['kw'] ?? ''));
$start = trim((string)($_GET['start'] ?? ''));
$end   = trim((string)($_GET['end'] ?? ''));

$where  = '';
$params = [];
if ($ut === '1' || $ut === '2') {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `f`.`user_type`=?';
    $params[] = (int)$ut;
}
if ($type !== '' && isset($typeMap[(int)$type])) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `f`.`type`=?';
    $params[] = (int)$type;
}
if ($kw !== '') {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' (`f`.`order_no` LIKE ? OR `f`.`remark` LIKE ?)';
    $params = array_merge($params, ['%' . $kw . '%', '%' . $kw . '%']);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `f`.`created_at`>=?';
    $params[] = strtotime($start . ' 00:00:00');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `f`.`created_at`<=?';
    $params[] = strtotime($end . ' 23:59:59');
}

$fromSql = 'FROM `finance_flow` `f` '
    . 'LEFT JOIN `user` `u` ON `f`.`user_type`=1 AND `u`.`id`=`f`.`user_id` '
    . 'LEFT JOIN `agent` `ag` ON `f`.`user_type`=2 AND `ag`.`id`=`f`.`user_id`';

$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$stmt = db()->prepare('SELECT COUNT(*) ' . $fromSql . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$offset = ($page - 1) * $perPage;
$params[] = $perPage;
$params[] = $offset;
$stmt = db()->prepare('SELECT `f`.*, COALESCE(`ag`.`username`, `u`.`username`, \'-\') AS `uname` ' . $fromSql . $where . ' ORDER BY `f`.`id` DESC LIMIT ? OFFSET ?');
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stat = ['total' => 0, 'today_count' => 0, 'today_amount' => 0.0, 'total_amount' => 0.0];
try {
    $stat['total']        = (int)db()->query('SELECT COUNT(*) FROM `finance_flow`')->fetchColumn();
    $stat['total_amount'] = (float)db()->query('SELECT COALESCE(SUM(`amount`),0) FROM `finance_flow`')->fetchColumn();
    $r = db()->prepare('SELECT COUNT(*) AS `c`, COALESCE(SUM(`amount`),0) AS `s` FROM `finance_flow` WHERE `created_at`>=?');
    $r->execute([strtotime(date('Y-m-d'))]);
    if ($row = $r->fetch()) {
        $stat['today_count']  = (int)$row['c'];
        $stat['today_amount'] = (float)$row['s'];
    }
} catch (Throwable $ex) {  }

admin_header('财务流水', 'finance', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<section class="card">
    <h2 class="card-title">财务概览</h2>
    <div style="display:flex;flex-wrap:wrap;gap:48px">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $stat['total']; ?></div>
            <div class="form-hint" style="margin-top:2px">流水总数</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--primary)"><?php echo $stat['today_count']; ?></div>
            <div class="form-hint" style="margin-top:2px">今日笔数</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--warning)">¥<?php echo e(number_format($stat['today_amount'], 2)); ?></div>
            <div class="form-hint" style="margin-top:2px">今日金额合计</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--success)">¥<?php echo e(number_format($stat['total_amount'], 2)); ?></div>
            <div class="form-hint" style="margin-top:2px">累计金额合计</div>
        </div>
    </div>
</section>

<section class="card">
    <h2 class="card-title">筛选条件</h2>
    <form method="get" class="filter-bar" style="flex-wrap:wrap">
        <select class="form-input" name="ut" style="max-width:130px">
            <option value="">全部账户</option>
            <option value="1" <?php echo $ut === '1' ? 'selected' : ''; ?>>用户</option>
            <option value="2" <?php echo $ut === '2' ? 'selected' : ''; ?>>代理商</option>
        </select>
        <select class="form-input" name="type" style="max-width:130px">
            <option value="">全部类型</option>
            <?php foreach ($typeMap as $k => $name): ?>
            <option value="<?php echo $k; ?>" <?php echo $type === (string)$k ? 'selected' : ''; ?>><?php echo e($name); ?></option>
            <?php endforeach; ?>
        </select>
        <input class="form-input" type="date" name="start" value="<?php echo e($start); ?>" style="max-width:160px">
        <span style="color:var(--text-third)">~</span>
        <input class="form-input" type="date" name="end" value="<?php echo e($end); ?>" style="max-width:160px">
        <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="订单号 / 备注" style="max-width:220px">
        <button class="btn-plain" type="submit">查询</button>
    </form>
</section>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">流水列表（共 <?php echo $total; ?> 条）</h2>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无数据</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>时间</th><th style="width:80px">账户类型</th><th>账号</th><th>类型</th><th>金额</th><th>变动后余额</th><th>订单号</th><th>备注</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i:s', (int)$row['created_at']); ?></td>
                <td><span class="tag <?php echo (int)$row['user_type'] === 2 ? 'tag-warn' : 'tag-info'; ?>"><?php echo $utNames[(int)$row['user_type']] ?? '未知'; ?></span></td>
                <td class="cell-code"><?php echo e((string)$row['uname']); ?></td>
                <td><?php echo e(finance_type_name((int)$row['type'])); ?></td>
                <td style="color:var(--error);font-weight:600">¥<?php echo e(number_format((float)$row['amount'], 2)); ?></td>
                <td class="cell-sub">¥<?php echo e(number_format((float)$row['balance_after'], 2)); ?></td>
                <td class="cell-code"><?php echo $row['order_no'] !== '' ? e($row['order_no']) : '-'; ?></td>
                <td class="cell-sub" style="max-width:260px;word-break:break-all"><?php echo $row['remark'] !== '' ? e($row['remark']) : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $baseQuery = 'finance_flow.php?ut=' . urlencode($ut) . '&type=' . urlencode($type)
        . '&kw=' . urlencode($kw) . '&start=' . urlencode($start) . '&end=' . urlencode($end) . '&';
    render_pagination($total, $page, $perPage, $baseQuery);
    ?>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
