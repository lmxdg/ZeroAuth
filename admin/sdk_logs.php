<?php
// 管理员后台 - SDK 日志
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

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
?>
<section class="card">
    <h2 class="card-title">SDK 下载日志</h2>
    <p class="card-desc">记录每次 SDK 打包下载的账号、应用、模板、IP 与时间，共 <?php echo $total; ?> 条。</p>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>#</th><th>下载账号</th><th>应用</th><th>模板</th><th>IP 地址</th><th>下载时间</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="6"><div class="empty-state">暂无下载记录。</div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-main"><?php echo e($row['account_name']); ?></td>
                <td class="cell-main"><?php echo e($row['app_name'] ?: ('应用#' . (int)$row['app_id'])); ?></td>
                <td class="cell-sub"><?php echo e($row['tpl_name'] ?: ('模板#' . (int)$row['template_id'])); ?></td>
                <td class="cell-code"><?php echo e($row['ip']); ?></td>
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
