<?php
// 管理员后台 - 授权管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$act   = (string)($_GET['act'] ?? 'list');
$id    = (int)($_GET['id'] ?? 0);
$error = '';

function license_type_name(int $type): string
{
    return [1 => '限时', 2 => '计次', 3 => '永久'][$type] ?? '未知';
}

if (is_post()) {
    csrf_check();
    $action    = (string)($_POST['action'] ?? '');

    // 批量删除只提交 ids[]，没有 license_id，必须先行处理，否则会误判为“卡密不存在”
    if ($action === 'batch_delete') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $ids = array_values(array_unique(array_filter($ids, fn ($v) => $v > 0)));
        if (!$ids) {
            flash_set('error', '请先勾选要删除的卡密。');
            redirect('license_manage.php');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $delD = $pdo->prepare('DELETE FROM `license_domain` WHERE `license_id`=?');
            $delV = $pdo->prepare('DELETE FROM `license_device` WHERE `license_id`=?');
            $delL = $pdo->prepare('DELETE FROM `license` WHERE `id`=?');
            $deleted = 0;
            foreach ($ids as $lid) {
                $delD->execute([$lid]);
                $delV->execute([$lid]);
                $delL->execute([$lid]);
                $deleted += $delL->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $deleted = 0;
        }
        if ($deleted > 0) {
            audit('license', 'batch_delete', 1, (int)$admin['id'], '批量删除卡密 ' . $deleted . ' 张，ID：' . implode(',', $ids));
            flash_set('ok', '已删除 ' . $deleted . ' 张卡密（含域名绑定与设备记录）。');
        } else {
            flash_set('error', '删除失败，请重试。');
        }
        redirect('license_manage.php');
    }

    $licenseId = (int)($_POST['license_id'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM `license` WHERE `id`=?');
    $stmt->execute([$licenseId]);
    $license = $stmt->fetch();

    if (!$license) {
        flash_set('error', '卡密不存在或已删除。');
        redirect('license_manage.php');
    }
    $cardNo = $license['card_no'];

    if ($action === 'ban' || $action === 'unban') {
        $to = $action === 'ban' ? 2 : 1;
        db()->prepare('UPDATE `license` SET `status`=? WHERE `id`=?')->execute([$to, $licenseId]);
        audit('license', $action, 1, (int)$admin['id'], '卡密：' . $cardNo);
        flash_set('ok', $action === 'ban' ? '卡密已封禁，验证接口将立即拒绝。' : '卡密已解封。');
        redirect('license_manage.php?act=detail&id=' . $licenseId);
    }

    if ($action === 'add_domain') {
        $raw = trim((string)($_POST['domain'] ?? ''));
        $domain = sec_normalize_domain($raw);
        if ($domain === '') {
            flash_set('error', '域名格式不正确。');
        } else {
            try {
                $stmt = db()->prepare('INSERT IGNORE INTO `license_domain` (`license_id`,`domain`,`is_https`,`status`,`created_at`) VALUES (?,?,0,1,?)');
                $stmt->execute([$licenseId, $domain, time()]);
                audit('license', 'bind_domain', 1, (int)$admin['id'], '卡密：' . $cardNo . ' 域名：' . $domain);
                flash_set('ok', '域名已绑定：' . $domain);
            } catch (PDOException $ex) {
                flash_set('error', '绑定失败：' . $ex->getMessage());
            }
        }
        redirect('license_manage.php?act=detail&id=' . $licenseId);
    }

    if ($action === 'del_domain') {
        $domainId = (int)($_POST['domain_id'] ?? 0);
        $stmt = db()->prepare('SELECT `domain` FROM `license_domain` WHERE `id`=? AND `license_id`=?');
        $stmt->execute([$domainId, $licenseId]);
        if ($row = $stmt->fetch()) {
            db()->prepare('DELETE FROM `license_domain` WHERE `id`=?')->execute([$domainId]);
            audit('license', 'unbind_domain', 1, (int)$admin['id'], '卡密：' . $cardNo . ' 域名：' . $row['domain']);
            flash_set('ok', '域名绑定已移除。');
        }
        redirect('license_manage.php?act=detail&id=' . $licenseId);
    }

    if ($action === 'unbind_device') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $stmt = db()->prepare('SELECT `device_code`,`device_name` FROM `license_device` WHERE `id`=? AND `license_id`=?');
        $stmt->execute([$deviceId, $licenseId]);
        if ($row = $stmt->fetch()) {
            db()->prepare('DELETE FROM `license_device` WHERE `id`=?')->execute([$deviceId]);
            audit('license', 'force_unbind_device', 1, (int)$admin['id'],
                '卡密：' . $cardNo . ' 设备：' . $row['device_name'] . '（' . substr($row['device_code'], 0, 12) . '…）');
            flash_set('ok', '设备已强制解绑，名额已释放。');
        }
        redirect('license_manage.php?act=detail&id=' . $licenseId);
    }

    if ($action === 'unbind_all') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM `license_device` WHERE `license_id`=?');
        $stmt->execute([$licenseId]);
        $count = (int)$stmt->fetchColumn();
        db()->prepare('DELETE FROM `license_device` WHERE `license_id`=?')->execute([$licenseId]);
        audit('license', 'unbind_all_device', 1, (int)$admin['id'], '卡密：' . $cardNo . ' 解绑设备：' . $count . ' 台');
        flash_set('ok', '已解绑全部设备（' . $count . ' 台），名额全部释放。');
        redirect('license_manage.php?act=detail&id=' . $licenseId);
    }
}

$license = null;
$domains = [];
$devices = [];
if ($act === 'detail' && $id > 0) {
    $stmt = db()->prepare(
        'SELECT l.*, a.`name` AS `app_name`, a.`app_id` AS `app_app_id` FROM `license` l LEFT JOIN `app` a ON a.`id`=l.`app_id` WHERE l.`id`=?'
    );
    $stmt->execute([$id]);
    $license = $stmt->fetch();
    if ($license) {
        $stmt = db()->prepare('SELECT * FROM `license_domain` WHERE `license_id`=? ORDER BY `id` ASC');
        $stmt->execute([$id]);
        $domains = $stmt->fetchAll();

        $stmt = db()->prepare('SELECT * FROM `license_device` WHERE `license_id`=? ORDER BY `last_verify_time` DESC');
        $stmt->execute([$id]);
        $devices = $stmt->fetchAll();
    } else {
        $act = 'list';
    }
}

$list   = [];
$total  = 0;
$page   = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 15;
$pages  = 1;
if ($act === 'list') {
    $where  = ['1=1'];
    $params = [];
    if ($appId = (int)($_GET['app_id'] ?? 0)) {
        $where[]  = 'l.`app_id`=?';
        $params[] = $appId;
    }
    if ($type = (int)($_GET['type'] ?? 0)) {
        if (in_array($type, [1, 2, 3], true)) {
            $where[]  = 'l.`type`=?';
            $params[] = $type;
        }
    }
    if ($status = (int)($_GET['status'] ?? 0)) {
        if (in_array($status, [1, 2], true)) {
            $where[]  = 'l.`status`=?';
            $params[] = $status;
        }
    }
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw !== '') {
        $where[]  = 'l.`card_no` LIKE ?';
        $params[] = '%' . $kw . '%';
    }
    $whereStr = implode(' AND ', $where);

    $stmt = db()->prepare('SELECT COUNT(*) FROM `license` l WHERE ' . $whereStr);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $pageSize));
    $page  = min($page, $pages);

    $stmt = db()->prepare(
        'SELECT l.*, a.`name` AS `app_name`, '
        . '(SELECT COUNT(*) FROM `license_device` d WHERE d.`license_id`=l.`id` AND d.`status`=1) AS `device_count` '
        . 'FROM `license` l LEFT JOIN `app` a ON a.`id`=l.`app_id` '
        . 'WHERE ' . $whereStr . ' ORDER BY l.`id` DESC LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize)
    );
    $stmt->execute($params);
    $list = $stmt->fetchAll();
}

$filterApp   = (int)($_GET['app_id'] ?? 0);
$filterType  = (int)($_GET['type'] ?? 0);
$filterStatus = (int)($_GET['status'] ?? 0);
$filterKw    = trim((string)($_GET['kw'] ?? ''));

function page_url(int $p): string
{
    global $filterApp, $filterType, $filterStatus, $filterKw;
    return 'license_manage.php?' . http_build_query([
        'app_id' => $filterApp ?: '', 'type' => $filterType ?: '',
        'status' => $filterStatus ?: '', 'kw' => $filterKw, 'page' => $p,
    ]);
}

admin_header('卡密管理', 'license', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<?php 
if ($act === 'list'): ?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">卡密列表</h2>
            <p class="card-desc" style="margin-bottom:0">共 <?php echo $total; ?> 张卡密。点击「详情」可管理域名绑定与设备解绑。</p>
        </div>
        <div class="spacer"></div>
        <button class="btn-danger" type="submit" form="licenseBatchForm">批量删除</button>
        <a class="btn-primary" href="license_generate.php">批量生成</a>
    </div>
    <form method="get" class="filter-bar">
        <select class="form-input" name="app_id">
            <option value="">全部应用</option>
            <?php foreach (db()->query('SELECT `id`,`name` FROM `app` ORDER BY `id` DESC')->fetchAll() as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>" <?php echo $filterApp === (int)$a['id'] ? 'selected' : ''; ?>><?php echo e($a['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="type">
            <option value="">全部类型</option>
            <option value="1" <?php echo $filterType === 1 ? 'selected' : ''; ?>>限时</option>
            <option value="2" <?php echo $filterType === 2 ? 'selected' : ''; ?>>计次</option>
            <option value="3" <?php echo $filterType === 3 ? 'selected' : ''; ?>>永久</option>
        </select>
        <select class="form-input" name="status">
            <option value="">全部状态</option>
            <option value="1" <?php echo $filterStatus === 1 ? 'selected' : ''; ?>>正常</option>
            <option value="2" <?php echo $filterStatus === 2 ? 'selected' : ''; ?>>已封禁</option>
        </select>
        <input class="form-input" type="text" name="kw" maxlength="64" placeholder="卡密关键词" value="<?php echo e($filterKw); ?>">
        <button class="btn-plain" type="submit">筛选</button>
    </form>
    <form method="post" id="licenseBatchForm" data-confirm="确定删除勾选的卡密吗？删除后不可恢复，域名绑定与设备记录将一并清除。">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="batch_delete">
        <div class="table-wrap">
        <table class="tb">
            <thead><tr>
                <th style="width:36px"><input type="checkbox" id="licenseCheckAll" title="全选本页"></th>
                <th>ID</th><th>卡密</th><th>应用</th><th>类型</th><th>状态</th><th>有效期 / 次数</th><th>设备</th><th>创建时间</th><th style="width:120px">操作</th>
            </tr></thead>
            <tbody>
            <?php if (!$list): ?>
            <tr><td colspan="10"><div class="empty-state">暂无符合条件的卡密。</div></td></tr>
            <?php endif; ?>
            <?php foreach ($list as $row): ?>
            <?php
                $validText = '-';
                if ((int)$row['type'] === 1) {
                    $validText = (int)$row['expire_time'] > 0 ? date('Y-m-d', (int)$row['expire_time']) : '不限';
                } elseif ((int)$row['type'] === 2) {
                    $validText = (int)$row['total_count'] > 0 ? ((int)$row['used_count'] . '/' . (int)$row['total_count'] . ' 次') : ((int)$row['used_count'] . ' 次/不限');
                } else {
                    $validText = '永久';
                }
                $expired = (int)$row['type'] === 1 && (int)$row['expire_time'] > 0 && (int)$row['expire_time'] < time();
            ?>
            <tr>
                <td><input type="checkbox" name="ids[]" value="<?php echo (int)$row['id']; ?>" class="license-check"></td>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-code cell-main"><?php echo e($row['card_no']); ?></td>
                <td class="cell-sub"><?php echo e($row['app_name'] ?: '已删应用'); ?></td>
                <td><span class="tag tag-info"><?php echo license_type_name((int)$row['type']); ?></span></td>
                <td><span class="tag <?php echo (int)$row['status'] === 2 ? 'tag-err' : ($expired ? 'tag-warn' : 'tag-ok'); ?>">
                    <?php echo (int)$row['status'] === 2 ? '已封禁' : ($expired ? '已过期' : '正常'); ?></span></td>
                <td class="cell-sub"><?php echo $validText; ?></td>
                <td class="cell-sub"><?php echo (int)$row['device_count'] . '/' . (int)$row['max_devices']; ?></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$row['created_at']); ?></td>
                <td>
                    <a class="btn-mini" href="license_manage.php?act=detail&id=<?php echo (int)$row['id']; ?>">详情</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?><a class="page-btn" href="<?php echo page_url($page - 1); ?>">上一页</a><?php else: ?><span class="page-btn disabled">上一页</span><?php endif; ?>
        <?php
        $pageList = [];
        for ($p = 1; $p <= $pages; $p++) {
            if ($p === 1 || $p === $pages || abs($p - $page) <= 2) {
                $pageList[] = $p;
            }
        }
        $prev = 0;
        foreach ($pageList as $p):
            if ($prev && $p - $prev > 1): ?>
            <a class="page-btn" href="<?php echo page_url((int)ceil(($prev + $p) / 2)); ?>">...</a>
            <?php endif;
            $prev = $p; ?>
            <?php if ($p === $page): ?>
            <span class="page-btn active"><?php echo $p; ?></span>
            <?php else: ?>
            <a class="page-btn" href="<?php echo page_url($p); ?>"><?php echo $p; ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < $pages): ?><a class="page-btn" href="<?php echo page_url($page + 1); ?>">下一页</a><?php else: ?><span class="page-btn disabled">下一页</span><?php endif; ?>
        <span class="page-info">共 <?php echo $total; ?> 条 / <?php echo $pages; ?> 页</span>
    </div>
    <?php endif; ?>
    </form>
    <script>
    (function () {
        var all = document.getElementById('licenseCheckAll');
        if (!all) return;
        all.addEventListener('change', function () {
            document.querySelectorAll('.license-check').forEach(function (cb) { cb.checked = all.checked; });
        });
    })();
    </script>
</section>

<?php 
elseif ($act === 'detail'):
    $l        = $license;
    $expired  = (int)$l['type'] === 1 && (int)$l['expire_time'] > 0 && (int)$l['expire_time'] < time();
    $used     = (int)$l['used_count'];
    $totalCnt = (int)$l['total_count'];
    $exhaust  = (int)$l['type'] === 2 && $totalCnt > 0 && $used >= $totalCnt;
?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">卡密详情：<span class="cell-code"><?php echo e($l['card_no']); ?></span></h2>
            <p class="card-desc" style="margin-bottom:0">所属应用：<?php echo e($l['app_name'] ?: '已删应用'); ?>（AppID：<?php echo e($l['app_app_id'] ?: '-'); ?>）</p>
        </div>
        <div class="spacer"></div>
        <form method="post" style="display:inline" data-confirm="确定<?php echo (int)$l['status'] === 2 ? '解封' : '封禁'; ?>该卡密吗？">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="<?php echo (int)$l['status'] === 2 ? 'unban' : 'ban'; ?>">
            <input type="hidden" name="license_id" value="<?php echo (int)$l['id']; ?>">
            <button class="btn-mini-plain" type="submit"><?php echo (int)$l['status'] === 2 ? '解封卡密' : '一键封禁'; ?></button>
        </form>
        <a class="btn-plain" href="license_manage.php">返回列表</a>
    </div>
    <table class="kv-table">
        <tr><th>授权类型</th><td><span class="tag tag-info"><?php echo license_type_name((int)$l['type']); ?></span></td></tr>
        <tr><th>状态</th><td>
            <span class="tag <?php echo (int)$l['status'] === 2 ? 'tag-err' : ($expired ? 'tag-warn' : 'tag-ok'); ?>">
            <?php echo (int)$l['status'] === 2 ? '已封禁' : ($expired ? '已过期' : '正常'); ?></span>
        </td></tr>
        <tr><th>生效时间</th><td><?php echo date('Y-m-d H:i:s', (int)$l['start_time']); ?></td></tr>
        <?php if ((int)$l['type'] === 1): ?>
        <tr><th>到期时间</th><td><?php echo (int)$l['expire_time'] > 0 ? date('Y-m-d H:i:s', (int)$l['expire_time']) : '不限'; ?></td></tr>
        <?php elseif ((int)$l['type'] === 2): ?>
        <tr><th>验证次数</th><td>已用 <?php echo $used; ?><?php echo $totalCnt > 0 ? ' / 总 ' . $totalCnt . ' 次' : '（不限次）'; ?><?php echo $exhaust ? '（已用完）' : ''; ?></td></tr>
        <?php endif; ?>
        <tr><th>设备绑定</th><td><?php echo count($devices) . ' / 上限 ' . (int)$l['max_devices'] . ' 台'; ?></td></tr>
        <tr><th>价格 / 归属</th><td><?php echo e(number_format((float)$l['price'], 2)); ?> 元 · <?php echo (int)$l['owner_type'] === 2 ? '代理商' : '管理员'; ?></td></tr>
        <tr><th>备注</th><td><?php echo e($l['remark'] !== '' ? $l['remark'] : '-'); ?></td></tr>
        <tr><th>创建时间</th><td><?php echo date('Y-m-d H:i:s', (int)$l['created_at']); ?></td></tr>
    </table>
</section>

<section class="card">
    <h2 class="card-title">绑定域名（<?php echo count($domains); ?>）</h2>
    <p class="card-desc">绑定后 /api/auth.php 校验请求来源域名（归一化为小写主机名比对）；未绑定任何域名的卡密将被验证接口拒绝。</p>
    <form method="post" class="filter-bar">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add_domain">
        <input type="hidden" name="license_id" value="<?php echo (int)$l['id']; ?>">
        <input class="form-input" type="text" name="domain" maxlength="255" placeholder="example.com" required>
        <button class="btn-primary" type="submit">添加域名</button>
    </form>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>域名</th><th>绑定时间</th><th style="width:100px">操作</th></tr></thead>
            <tbody>
            <?php if (!$domains): ?>
            <tr><td colspan="4"><div class="empty-state">尚未绑定域名，验证接口将拒绝该卡密的验证请求。</div></td></tr>
            <?php endif; ?>
            <?php foreach ($domains as $d): ?>
            <tr>
                <td class="cell-code"><?php echo (int)$d['id']; ?></td>
                <td class="cell-code cell-main"><?php echo e($d['domain']); ?></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$d['created_at']); ?></td>
                <td>
                    <form method="post" style="display:inline" data-confirm="确定移除域名「<?php echo e($d['domain']); ?>」的绑定吗？">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="del_domain">
                        <input type="hidden" name="license_id" value="<?php echo (int)$l['id']; ?>">
                        <input type="hidden" name="domain_id" value="<?php echo (int)$d['id']; ?>">
                        <button class="btn-danger-mini" type="submit">移除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title" style="margin-bottom:0">绑定设备（<?php echo count($devices); ?>）</h2>
        </div>
        <div class="spacer"></div>
        <?php if ($devices): ?>
        <form method="post" style="display:inline" data-confirm="确定解绑该卡密下的全部设备吗？解绑后客户端需重新绑定。">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="unbind_all">
            <input type="hidden" name="license_id" value="<?php echo (int)$l['id']; ?>">
            <button class="btn-danger" type="submit">一键解绑全部</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>设备指纹（哈希）</th><th>设备名称</th><th>绑定IP</th><th>首次绑定</th><th>最后验证</th><th style="width:120px">操作</th></tr></thead>
            <tbody>
            <?php if (!$devices): ?>
            <tr><td colspan="7"><div class="empty-state">暂无绑定设备。</div></td></tr>
            <?php endif; ?>
            <?php foreach ($devices as $dev): ?>
            <tr>
                <td class="cell-code"><?php echo (int)$dev['id']; ?></td>
                <td class="cell-code cell-main"><?php echo e(substr($dev['device_code'], 0, 24)); ?>…</td>
                <td class="cell-sub"><?php echo e($dev['device_name'] !== '' ? $dev['device_name'] : '-'); ?></td>
                <td class="cell-sub"><?php echo e($dev['bind_ip']); ?></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$dev['first_bind_time']); ?></td>
                <td class="cell-sub"><?php echo (int)$dev['last_verify_time'] > 0 ? date('Y-m-d H:i', (int)$dev['last_verify_time']) : '-'; ?></td>
                <td>
                    <form method="post" style="display:inline" data-confirm="确定强制解绑该设备吗？解绑后立即释放设备名额。">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="unbind_device">
                        <input type="hidden" name="license_id" value="<?php echo (int)$l['id']; ?>">
                        <input type="hidden" name="device_id" value="<?php echo (int)$dev['id']; ?>">
                        <button class="btn-danger-mini" type="submit">强制解绑</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
