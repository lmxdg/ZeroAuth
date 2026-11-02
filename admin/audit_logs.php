<?php
// 管理员后台 - 审计日志
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$moduleMap = [
    'auth'     => '卡密验证',
    'secret'   => '密钥下发',
    'pay'      => '支付订单',
    'login'    => '登录',
    'user'     => '用户',
    'agent'    => '代理商',
    'license'  => '卡密管理',
    'security' => '安全防护',
    'config'   => '系统配置',
    'sdk'      => 'SDK',
];
$actorMap = [
    1 => ['管理员', 'tag-ok'],
    2 => ['代理商', 'tag-info'],
    3 => ['用户', ''],
    4 => ['系统/SDK', 'tag-off'],
];

if (is_post()) {
    csrf_check();
    $postAction = (string)($_POST['action'] ?? '');
    if ($postAction === 'clean') {
        $days = max(30, min(365, (int)($_POST['days'] ?? 90)));
        $before = time() - $days * 86400;
        $stmt = db()->prepare('DELETE FROM `audit_log` WHERE `created_at`<?');
        $stmt->execute([$before]);
        $n = $stmt->rowCount();
        audit('config', 'audit_clean', 1, (int)$admin['id'], '清理 ' . $days . ' 天前审计日志 ' . $n . ' 条');
        flash_set('ok', '已清理 ' . date('Y-m-d H:i', $before) . ' 之前的审计日志 ' . $n . ' 条。');
        redirect('audit_logs.php');
    }
    if ($postAction === 'clear_all') {
        $n = (int)db()->query('SELECT COUNT(*) FROM `audit_log`')->fetchColumn();
        db()->exec('DELETE FROM `audit_log`');
        audit('config', 'audit_clear_all', 1, (int)$admin['id'], '清空全部审计日志 ' . $n . ' 条');
        flash_set('ok', '已清空全部审计日志 ' . $n . ' 条（本次清理操作保留 1 条审计记录）。');
        redirect('audit_logs.php');
    }
}

$module = trim((string)($_GET['module'] ?? ''));
$actor  = (string)($_GET['actor'] ?? '');
$kw     = trim((string)($_GET['kw'] ?? ''));
$start  = trim((string)($_GET['start'] ?? ''));
$end    = trim((string)($_GET['end'] ?? ''));

$where  = '';
$params = [];
if (isset($moduleMap[$module])) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `module`=?';
    $params[] = $module;
}
if (isset($actorMap[(int)$actor])) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `actor_type`=?';
    $params[] = (int)$actor;
}
if ($kw !== '') {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' (`action` LIKE ? OR `detail` LIKE ?)';
    $params = array_merge($params, ['%' . $kw . '%', '%' . $kw . '%']);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `created_at`>=?';
    $params[] = strtotime($start . ' 00:00:00');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `created_at`<=?';
    $params[] = strtotime($end . ' 23:59:59');
}

$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$stmt = db()->prepare('SELECT COUNT(*) FROM `audit_log`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$offset = ($page - 1) * $perPage;
$params[] = $perPage;
$params[] = $offset;
$stmt = db()->prepare('SELECT * FROM `audit_log`' . $where . ' ORDER BY `id` DESC LIMIT ? OFFSET ?');
$stmt->execute($params);
$rows = $stmt->fetchAll();

admin_header('审计日志', 'audit', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<section class="card">
    <h2 class="card-title">筛选条件</h2>
    <form method="get" class="filter-bar" style="flex-wrap:wrap">
        <select class="form-input" name="module" style="max-width:140px">
            <option value="">全部模块</option>
            <?php foreach ($moduleMap as $k => $name): ?>
            <option value="<?php echo e($k); ?>" <?php echo $module === $k ? 'selected' : ''; ?>><?php echo e($name); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="actor" style="max-width:140px">
            <option value="">全部操作者</option>
            <?php foreach ($actorMap as $k => [$name, $tag]): ?>
            <option value="<?php echo $k; ?>" <?php echo $actor === (string)$k ? 'selected' : ''; ?>><?php echo e($name); ?></option>
            <?php endforeach; ?>
        </select>
        <input class="form-input" type="date" name="start" value="<?php echo e($start); ?>" style="max-width:160px">
        <span style="color:var(--text-third)">~</span>
        <input class="form-input" type="date" name="end" value="<?php echo e($end); ?>" style="max-width:160px">
        <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="动作 / 详情关键词" style="max-width:220px">
        <button class="btn-plain" type="submit">查询</button>
    </form>
</section>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">日志列表（共 <?php echo $total; ?> 条）</h2>
        <div class="spacer"></div>
        <form method="post" style="display:flex">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clean">
            <input type="hidden" name="days" value="90">
            <button class="btn-danger-mini btn-mini" type="submit" data-confirm="确认清理 90 天前的审计日志？该操作不可恢复。">清理 90 天前日志</button>
        </form>
        <form method="post" style="display:flex;margin-left:8px">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clear_all">
            <button class="btn-danger-mini btn-mini" type="submit" data-confirm="确定清空全部审计日志吗？该操作不可恢复（本次清理操作会保留 1 条记录）。">清空全部日志</button>
        </form>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-log.svg" alt="">
        <p>暂无日志，或无匹配结果。</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>时间</th><th>模块</th><th>动作</th><th>操作者</th><th>IP</th><th>归属地</th><th>详情</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                [$actorName, $actorTag] = $actorMap[(int)$row['actor_type']] ?? ['未知', 'tag-off'];
            ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i:s', (int)$row['created_at']); ?></td>
                <td><?php echo e($moduleMap[$row['module']] ?? $row['module']); ?></td>
                <td class="cell-code"><?php echo e($row['action']); ?></td>
                <td>
                    <span class="tag <?php echo $actorTag; ?>"><?php echo e($actorName); ?></span>
                    <?php if ((int)$row['actor_id'] > 0): ?><span class="cell-sub">#<?php echo (int)$row['actor_id']; ?></span><?php endif; ?>
                </td>
                <td class="cell-code"><?php echo e($row['ip']); ?></td>
                <td><?php echo ip_geo_badge((string)$row['ip']); ?></td>
                <td class="cell-sub" style="max-width:420px;word-break:break-all"><?php echo $row['detail'] !== '' ? e($row['detail']) : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $baseQuery = 'audit_logs.php?module=' . urlencode($module) . '&actor=' . urlencode($actor)
        . '&kw=' . urlencode($kw) . '&start=' . urlencode($start) . '&end=' . urlencode($end) . '&';
    render_pagination($total, $page, $perPage, $baseQuery);
    ?>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
