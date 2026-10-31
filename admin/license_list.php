<?php
// 管理员后台 - 卡密列表
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

function license_type_name(int $type): string
{
    return [1 => '限时', 2 => '计次', 3 => '永久'][$type] ?? '未知';
}

if (is_post()) {
    csrf_check();
    $action    = (string)($_POST['action'] ?? '');
    $licenseId = (int)($_POST['license_id'] ?? 0);

    if (in_array($action, ['ban', 'unban'], true) && $licenseId > 0) {
        $stmt = db()->prepare('SELECT `card_no` FROM `license` WHERE `id`=?');
        $stmt->execute([$licenseId]);
        if (!$row = $stmt->fetch()) {
            flash_set('error', '卡密不存在或已删除。');
            redirect('license_list.php');
        }
        $to = $action === 'ban' ? 2 : 1;
        db()->prepare('UPDATE `license` SET `status`=? WHERE `id`=?')->execute([$to, $licenseId]);
        audit('license_list', $action, 1, (int)$admin['id'], '卡密：' . $row['card_no']);
        flash_set('ok', $action === 'ban' ? '卡密已封禁，验证接口将立即拒绝。' : '卡密已解封。');
    }
    redirect('license_list.php');
}

$filterKw        = trim((string)($_GET['kw'] ?? ''));
$filterApp       = (int)($_GET['app_id'] ?? 0);
$filterType      = (int)($_GET['type'] ?? 0);
$filterStatus    = (int)($_GET['status'] ?? 0);
$filterOwnerType = (int)($_GET['owner_type'] ?? 0);

$where  = ['1=1'];
$params = [];
if ($filterKw !== '') {
    $where[]  = 'l.`card_no` LIKE ?';
    $params[] = '%' . $filterKw . '%';
}
if ($filterApp > 0) {
    $where[]  = 'l.`app_id`=?';
    $params[] = $filterApp;
}
if (in_array($filterType, [1, 2, 3], true)) {
    $where[]  = 'l.`type`=?';
    $params[] = $filterType;
}
if (in_array($filterStatus, [1, 2], true)) {
    $where[]  = 'l.`status`=?';
    $params[] = $filterStatus;
}
if (in_array($filterOwnerType, [1, 2], true)) {
    $where[]  = 'l.`owner_type`=?';
    $params[] = $filterOwnerType;
}
$whereStr = implode(' AND ', $where);

$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));

$stmt = db()->prepare('SELECT COUNT(*) FROM `license` l WHERE ' . $whereStr);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$queryParams   = $params;
$queryParams[] = $perPage;
$queryParams[] = $offset;

$stmt = db()->prepare(
    'SELECT l.`id`, l.`card_no`, l.`type`, l.`status`, l.`expire_time`, l.`total_count`, l.`used_count`, '
    . 'l.`owner_type`, l.`owner_id`, l.`created_at`, a.`name` AS `app_name`, '
    . 'COALESCE(u.`username`, g.`username`, \'\') AS `owner_name`, '
    . '(SELECT COUNT(*) FROM `license_device` d WHERE d.`license_id`=l.`id` AND d.`status`=1) AS `device_count` '
    . 'FROM `license` l '
    . 'LEFT JOIN `app` a ON a.`id`=l.`app_id` '
    . 'LEFT JOIN `user` u ON l.`owner_type`=1 AND u.`id`=l.`owner_id` '
    . 'LEFT JOIN `agent` g ON l.`owner_type`=2 AND g.`id`=l.`owner_id` '
    . 'WHERE ' . $whereStr . ' ORDER BY l.`id` DESC LIMIT ? OFFSET ?'
);
$stmt->execute($queryParams);
$rows = $stmt->fetchAll();

admin_header('授权列表', 'lic_list', $admin);
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
            <h2 class="card-title">授权列表</h2>
            <p class="card-desc" style="margin-bottom:0">共 <?php echo $total; ?> 张卡密。全量视图，侧重归属与使用情况；域名绑定与设备解绑请前往「卡密管理」。</p>
        </div>
    </div>
    <form method="get" class="filter-bar" style="flex-wrap:wrap">
        <select class="form-input" name="app_id" style="max-width:150px">
            <option value="">全部应用</option>
            <?php foreach (db()->query('SELECT `id`,`name` FROM `app` ORDER BY `id` DESC')->fetchAll() as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>" <?php echo $filterApp === (int)$a['id'] ? 'selected' : ''; ?>><?php echo e($a['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="type" style="max-width:120px">
            <option value="">全部类型</option>
            <option value="1" <?php echo $filterType === 1 ? 'selected' : ''; ?>>限时</option>
            <option value="2" <?php echo $filterType === 2 ? 'selected' : ''; ?>>计次</option>
            <option value="3" <?php echo $filterType === 3 ? 'selected' : ''; ?>>永久</option>
        </select>
        <select class="form-input" name="status" style="max-width:120px">
            <option value="">全部状态</option>
            <option value="1" <?php echo $filterStatus === 1 ? 'selected' : ''; ?>>正常</option>
            <option value="2" <?php echo $filterStatus === 2 ? 'selected' : ''; ?>>已封禁</option>
        </select>
        <select class="form-input" name="owner_type" style="max-width:130px">
            <option value="">全部归属</option>
            <option value="1" <?php echo $filterOwnerType === 1 ? 'selected' : ''; ?>>用户</option>
            <option value="2" <?php echo $filterOwnerType === 2 ? 'selected' : ''; ?>>代理商</option>
        </select>
        <input class="form-input" type="text" name="kw" maxlength="64" placeholder="卡密关键词" value="<?php echo e($filterKw); ?>" style="max-width:220px">
        <button class="btn-plain" type="submit">筛选</button>
    </form>

    <?php if (!$rows): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr>
                <th>ID</th><th>卡密</th><th>应用</th><th>类型</th><th style="width:80px">状态</th><th>有效期 / 次数</th><th>归属</th><th>设备数</th><th>创建时间</th><th style="width:90px">操作</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                $expired = (int)$row['type'] === 1 && (int)$row['expire_time'] > 0 && (int)$row['expire_time'] < time();
                $ownerName = '-';
                if ((int)$row['owner_type'] === 1 && $row['owner_name'] !== '') {
                    $ownerName = e($row['owner_name']);
                } elseif ((int)$row['owner_type'] === 2 && $row['owner_name'] !== '') {
                    $ownerName = '代理:' . e($row['owner_name']);
                }
            ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-code cell-main"><?php echo e($row['card_no']); ?></td>
                <td class="cell-sub"><?php echo e($row['app_name'] ?: '已删应用'); ?></td>
                <td><span class="tag tag-info"><?php echo license_type_name((int)$row['type']); ?></span></td>
                <td><span class="tag <?php echo (int)$row['status'] === 2 ? 'tag-err' : 'tag-ok'; ?>"><?php echo (int)$row['status'] === 2 ? '已封禁' : '正常'; ?></span></td>
                <td class="cell-sub" style="white-space:nowrap">
                    <?php if ((int)$row['type'] === 1): ?>
                        <?php if ((int)$row['expire_time'] > 0): ?>
                            <?php echo date('Y-m-d', (int)$row['expire_time']); ?>
                            <?php if ($expired): ?><span style="color:var(--error);margin-left:4px">已过期</span><?php endif; ?>
                        <?php else: ?>不限<?php endif; ?>
                    <?php elseif ((int)$row['type'] === 2): ?>
                        <?php echo (int)$row['used_count'] . '/' . ((int)$row['total_count'] > 0 ? (int)$row['total_count'] : '∞'); ?>
                    <?php else: ?>永久<?php endif; ?>
                </td>
                <td class="cell-sub"><?php echo $ownerName; ?></td>
                <td class="cell-sub"><?php echo (int)$row['device_count']; ?></td>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i', (int)$row['created_at']); ?></td>
                <td>
                    <form method="post" style="display:inline" data-confirm="确定<?php echo (int)$row['status'] === 2 ? '解封' : '封禁'; ?>卡密「<?php echo e($row['card_no']); ?>」吗？">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="<?php echo (int)$row['status'] === 2 ? 'unban' : 'ban'; ?>">
                        <input type="hidden" name="license_id" value="<?php echo (int)$row['id']; ?>">
                        <button class="<?php echo (int)$row['status'] === 2 ? 'btn-mini-plain' : 'btn-danger-mini'; ?>" type="submit"><?php echo (int)$row['status'] === 2 ? '解封' : '封禁'; ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $baseQuery = 'license_list.php?app_id=' . urlencode((string)$filterApp) . '&type=' . urlencode((string)$filterType)
        . '&status=' . urlencode((string)$filterStatus) . '&owner_type=' . urlencode((string)$filterOwnerType)
        . '&kw=' . urlencode($filterKw) . '&';
    render_pagination($total, $page, $perPage, $baseQuery);
    ?>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
