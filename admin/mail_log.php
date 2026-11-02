<?php
// 管理员后台 - 邮件日志
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';
require dirname(__DIR__) . '/core/Mail.php';

$admin = admin_require_login();

$statusMap = [
    1 => ['发送成功', 'tag-ok'],
    0 => ['待发送', 'tag-warn'],
    2 => ['发送失败', 'tag-err'],
];

if (is_post()) {
    csrf_check();
    $postAction = (string)($_POST['action'] ?? '');
    if ($postAction === 'clean') {
        $days = max(30, min(365, (int)($_POST['days'] ?? 90)));
        $before = time() - $days * 86400;
        $stmt = db()->prepare('DELETE FROM `email_log` WHERE `created_at`<?');
        $stmt->execute([$before]);
        $n = $stmt->rowCount();
        audit('config', 'mail_log_clean', 1, (int)$admin['id'], '清理 ' . $days . ' 天前邮件日志 ' . $n . ' 条');
        flash_set('ok', '已清理 ' . date('Y-m-d H:i', $before) . ' 之前的邮件日志 ' . $n . ' 条。');
        redirect('mail_log.php');
    }
    if ($postAction === 'clear_all') {
        $n = (int)db()->query('SELECT COUNT(*) FROM `email_log`')->fetchColumn();
        db()->exec('DELETE FROM `email_log`');
        audit('config', 'mail_log_clear_all', 1, (int)$admin['id'], '清空全部邮件日志 ' . $n . ' 条');
        flash_set('ok', '已清空全部邮件日志 ' . $n . ' 条。');
        redirect('mail_log.php');
    }
}

$viewId = (int)($_GET['view'] ?? 0);
if ($viewId > 0) {
    $stmt = db()->prepare('SELECT `id`,`to_email`,`subject`,`content`,`status`,`error_msg`,`created_at` FROM `email_log` WHERE `id`=?');
    $stmt->execute([$viewId]);
    $mail = $stmt->fetch();
    if (!$mail) {
        flash_set('err', '邮件日志不存在或已被清理。');
        redirect('mail_log.php');
    }
    admin_header('邮件详情', 'mail_log', $admin);
    [$stName, $stTag] = $statusMap[(int)$mail['status']] ?? ['未知', 'tag-off'];
    ?>
    <section class="card">
        <div class="page-actions">
            <h2 class="card-title" style="margin-bottom:0">邮件详情 #<?php echo (int)$mail['id']; ?></h2>
            <div class="spacer"></div>
            <a class="btn-plain" href="mail_log.php" style="line-height:30px">返回列表</a>
        </div>
        <div class="table-wrap" style="margin-bottom:16px">
            <table class="tb">
                <tbody>
                <tr><td style="width:110px;background:var(--bg-page);white-space:nowrap">收件邮箱</td><td class="cell-code"><?php echo e($mail['to_email']); ?></td></tr>
                <tr><td style="background:var(--bg-page);white-space:nowrap">邮件主题</td><td><?php echo e($mail['subject']); ?></td></tr>
                <tr><td style="background:var(--bg-page);white-space:nowrap">发送状态</td>
                    <td><span class="tag <?php echo $stTag; ?>"><?php echo e($stName); ?></span>
                    <?php if ($mail['error_msg'] !== ''): ?><span class="cell-sub" style="margin-left:8px"><?php echo e($mail['error_msg']); ?></span><?php endif; ?>
                </td></tr>
                <tr><td style="background:var(--bg-page);white-space:nowrap">创建时间</td><td class="cell-sub"><?php echo date('Y-m-d H:i:s', (int)$mail['created_at']); ?></td></tr>
                </tbody>
            </table>
        </div>
        <iframe style="width:100%;height:560px;border:1px solid var(--border);border-radius:var(--radius);background:#F2F3F5" title="邮件内容预览" srcdoc="<?php echo e($mail['content']); ?>"></iframe>
    </section>
    <?php admin_footer();
    exit;
}

$status = (string)($_GET['status'] ?? '');
$kw     = trim((string)($_GET['kw'] ?? ''));
$start  = trim((string)($_GET['start'] ?? ''));
$end    = trim((string)($_GET['end'] ?? ''));

$where  = '';
$params = [];
if ($status !== '' && isset($statusMap[(int)$status])) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `status`=?';
    $params[] = (int)$status;
}
if ($kw !== '') {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' (`to_email` LIKE ? OR `subject` LIKE ? OR `error_msg` LIKE ?)';
    $params = array_merge($params, ['%' . $kw . '%', '%' . $kw . '%', '%' . $kw . '%']);
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
$stmt = db()->prepare('SELECT COUNT(*) FROM `email_log`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$offset = ($page - 1) * $perPage;
$params[] = $perPage;
$params[] = $offset;
$stmt = db()->prepare('SELECT `id`,`to_email`,`subject`,`status`,`error_msg`,`created_at` FROM `email_log`' . $where . ' ORDER BY `id` DESC LIMIT ? OFFSET ?');
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stat = ['ok' => 0, 'fail' => 0, 'pending' => 0];
try {
    $r = db()->query('SELECT `status`, COUNT(*) AS `c` FROM `email_log` GROUP BY `status`')->fetchAll();
    foreach ($r as $s) {
        if ((int)$s['status'] === 1) $stat['ok'] = (int)$s['c'];
        elseif ((int)$s['status'] === 2) $stat['fail'] = (int)$s['c'];
        else $stat['pending'] += (int)$s['c'];
    }
} catch (Throwable $ex) {  }

admin_header('邮件日志', 'mail_log', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<section class="card">
    <h2 class="card-title">发送概览</h2>
    <div style="display:flex;flex-wrap:wrap;gap:48px">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $stat['ok'] + $stat['fail'] + $stat['pending']; ?></div>
            <div class="form-hint" style="margin-top:2px">累计发送</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--success)"><?php echo $stat['ok']; ?></div>
            <div class="form-hint" style="margin-top:2px">发送成功</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--error)"><?php echo $stat['fail']; ?></div>
            <div class="form-hint" style="margin-top:2px">发送失败</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--warning)"><?php echo $stat['pending']; ?></div>
            <div class="form-hint" style="margin-top:2px">待发送</div>
        </div>
    </div>
</section>

<section class="card">
    <h2 class="card-title">筛选条件</h2>
    <form method="get" class="filter-bar" style="flex-wrap:wrap">
        <select class="form-input" name="status" style="max-width:140px">
            <option value="">全部状态</option>
            <?php foreach ($statusMap as $k => [$name, $tag]): ?>
            <option value="<?php echo $k; ?>" <?php echo $status === (string)$k ? 'selected' : ''; ?>><?php echo e($name); ?></option>
            <?php endforeach; ?>
        </select>
        <input class="form-input" type="date" name="start" value="<?php echo e($start); ?>" style="max-width:160px">
        <span style="color:var(--text-third)">~</span>
        <input class="form-input" type="date" name="end" value="<?php echo e($end); ?>" style="max-width:160px">
        <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="收件邮箱 / 主题 / 失败原因" style="max-width:240px">
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
            <button class="btn-danger-mini btn-mini" type="submit" data-confirm="确认清理 90 天前的邮件日志？该操作不可恢复。">清理 90 天前日志</button>
        </form>
        <form method="post" style="display:flex;margin-left:8px">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clear_all">
            <button class="btn-danger-mini btn-mini" type="submit" data-confirm="确定清空全部邮件日志吗？该操作不可恢复。">清空全部日志</button>
        </form>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-log.svg" alt="">
        <p>暂无邮件日志，或无匹配结果。系统发信（发卡通知、告警等）时自动记录。</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>时间</th><th>收件邮箱</th><th>主题</th><th style="width:90px">状态</th><th>失败原因</th><th style="width:70px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): [$stName, $stTag] = $statusMap[(int)$row['status']] ?? ['未知', 'tag-off']; ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i:s', (int)$row['created_at']); ?></td>
                <td class="cell-code"><?php echo e($row['to_email']); ?></td>
                <td style="max-width:280px" class="cell-sub"><?php echo e($row['subject']); ?></td>
                <td><span class="tag <?php echo $stTag; ?>"><?php echo e($stName); ?></span></td>
                <td class="cell-sub" style="max-width:240px;word-break:break-all"><?php echo $row['error_msg'] !== '' ? e($row['error_msg']) : '-'; ?></td>
                <td><a class="btn-mini-plain" href="mail_log.php?view=<?php echo (int)$row['id']; ?>" style="text-decoration:none;line-height:26px">查看</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $baseQuery = 'mail_log.php?status=' . urlencode($status) . '&kw=' . urlencode($kw)
        . '&start=' . urlencode($start) . '&end=' . urlencode($end) . '&';
    render_pagination($total, $page, $perPage, $baseQuery);
    ?>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
