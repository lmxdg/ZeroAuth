<?php
// 管理员后台 - SDK 日志
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

if (is_post()) {
    csrf_check();
    if ((string)($_POST['action'] ?? '') === 'clear_all') {
        $n = (int)db()->query('SELECT COUNT(*) FROM `sdk_download_log`')->fetchColumn();
        db()->exec('DELETE FROM `sdk_download_log`');
        audit('sdk', 'log_clear_all', 1, (int)$admin['id'], '清空全部 SDK 下载日志 ' . $n . ' 条');
        flash_set('ok', '已清空全部 SDK 下载日志 ' . $n . ' 条。');
        redirect('sdk_logs.php');
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$size = 15;
$total = (int)db()->query('SELECT COUNT(*) FROM `sdk_download_log`')->fetchColumn();
$pages = max(1, (int)ceil($total / $size));
$page = min($page, $pages);
$offset = ($page - 1) * $size;

$stmt = db()->prepare('SELECT l.*, a.name AS app_name, t.name AS tpl_name FROM `sdk_download_log` l '
    . 'LEFT JOIN `app` a ON a.id = l.app_id '
    . 'LEFT JOIN `sdk_template` t ON t.id = l.template_id '
    . 'ORDER BY l.id DESC LIMIT ? OFFSET ?');
$stmt->bindValue(1, $size, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

admin_header('SDK 下载日志', 'sdk_logs', $admin);
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
            <h2 class="card-title" style="margin-bottom:6px">SDK 下载日志</h2>
            <p class="card-desc" style="margin-bottom:0">记录每次 SDK 打包下载的账号、应用、模板、IP 与时间，共 <?php echo $total; ?> 条。</p>
        </div>
        <div class="spacer"></div>
        <form method="post" style="display:flex">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clear_all">
            <button class="btn-danger-mini btn-mini" type="submit" data-confirm="确定清空全部 SDK 下载日志吗？该操作不可恢复。">清空全部日志</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>#</th><th>下载账号</th><th>应用</th><th>模板</th><th>IP 地址</th><th>归属地</th><th>下载时间</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="7"><div class="empty-state">暂无下载记录。</div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-main"><?php echo e($row['account_name']); ?></td>
                <td class="cell-main"><?php echo e($row['app_name'] ?: ('应用#' . (int)$row['app_id'])); ?></td>
                <td class="cell-sub"><?php echo e($row['tpl_name'] ?: ('模板#' . (int)$row['template_id'])); ?></td>
                <td class="cell-code"><?php echo e($row['ip']); ?></td>
                <td><?php echo ip_geo_badge((string)$row['ip']); ?></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i:s', (int)$row['created_at']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php  if ($pages > 1): ?>
    <div class="page-actions" style="margin-top:16px">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <?php if ($i === $page): ?>
            <span class="btn-mini" style="cursor:default;background:var(--primary-bg);border:1px solid var(--primary);color:var(--primary)"><?php echo $i; ?></span>
            <?php else: ?>
            <a class="btn-mini-plain" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
