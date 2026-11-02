<?php
// 管理员后台 - 卡密黑名单
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$typeNames = [1 => '设备码', 2 => '卡密', 3 => '用户', 4 => '邮箱', 5 => '域名'];

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
        $type   = (int)($_POST['type'] ?? 0);
        $value  = trim((string)($_POST['value'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (!isset($typeNames[$type])) {
            flash_set('err', '请选择有效的黑名单类型。');
        } elseif ($value === '' || mb_strlen($value) > 190) {
            flash_set('err', '黑名单值不能为空，且不超过 190 个字符。');
        } else {
            try {
                db()->prepare('INSERT INTO `blacklist` (`type`,`value`,`reason`,`status`,`created_at`) VALUES (?,?,?,1,?)')
                    ->execute([$type, mb_substr($value, 0, 190), mb_substr($reason, 0, 255), time()]);
                audit('blacklist', 'add', 1, (int)$admin['id'], '新增黑名单：' . blacklist_type_name($type) . ' ' . $value . ($reason !== '' ? '（' . $reason . '）' : ''));
                flash_set('ok', '已加入黑名单，验证接口实时生效。');
            } catch (Throwable $ex) {
                flash_set('err', '该值已在黑名单中。');
            }
        }
        redirect('blacklist_manage.php');
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `type`,`value`,`status` FROM `blacklist` WHERE `id`=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $new = (int)$row['status'] === 1 ? 0 : 1;
            db()->prepare('UPDATE `blacklist` SET `status`=? WHERE `id`=?')->execute([$new, $id]);
            audit('blacklist', 'toggle', 1, (int)$admin['id'], ($new === 1 ? '启用' : '停用') . '黑名单：' . blacklist_type_name((int)$row['type']) . ' ' . $row['value']);
            flash_set('ok', '已' . ($new === 1 ? '启用' : '停用') . '：' . $row['value']);
        }
        redirect('blacklist_manage.php');
    }

    if ($action === 'del') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `type`,`value` FROM `blacklist` WHERE `id`=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            db()->prepare('DELETE FROM `blacklist` WHERE `id`=?')->execute([$id]);
            audit('blacklist', 'del', 1, (int)$admin['id'], '删除黑名单：' . blacklist_type_name((int)$row['type']) . ' ' . $row['value']);
            flash_set('ok', '已删除黑名单记录：' . $row['value']);
        }
        redirect('blacklist_manage.php');
    }
}

$type   = (string)($_GET['type'] ?? '');
$status = (string)($_GET['status'] ?? '');
$kw     = trim((string)($_GET['kw'] ?? ''));

$where  = '';
$params = [];
if ($type !== '' && isset($typeNames[(int)$type])) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `type`=?';
    $params[] = (int)$type;
}
if ($status !== '' && in_array($status, ['0', '1'], true)) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `status`=?';
    $params[] = (int)$status;
}
if ($kw !== '') {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' (`value` LIKE ? OR `reason` LIKE ?)';
    $params = array_merge($params, ['%' . $kw . '%', '%' . $kw . '%']);
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$stmt = db()->prepare('SELECT COUNT(*) FROM `blacklist`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$offset = ($page - 1) * $perPage;
$params[] = $perPage;
$params[] = $offset;
$stmt = db()->prepare('SELECT * FROM `blacklist`' . $where . ' ORDER BY `id` DESC LIMIT ? OFFSET ?');
$stmt->execute($params);
$rows = $stmt->fetchAll();

$cntTotal  = 0;
$cntActive = 0;
$cntBlocked = 0;
try {
    $cntTotal = (int)db()->query('SELECT COUNT(*) FROM `blacklist`')->fetchColumn();
    $cntActive = (int)db()->query('SELECT COUNT(*) FROM `blacklist` WHERE `status`=1')->fetchColumn();
    $stmt = db()->prepare("SELECT COUNT(*) FROM `alert_center` WHERE `module`='blacklist' AND `created_at`>=?");
    $stmt->execute([strtotime('today')]);
    $cntBlocked = (int)$stmt->fetchColumn();
} catch (Throwable $ex) {  }

admin_header('黑名单管理', 'blacklist', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<section class="card">
    <h2 class="card-title">拦截概览</h2>
    <div style="display:flex;flex-wrap:wrap;gap:48px">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $cntTotal; ?></div>
            <div class="form-hint" style="margin-top:2px">黑名单总数</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--success)"><?php echo $cntActive; ?></div>
            <div class="form-hint" style="margin-top:2px">生效中</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--error)"><?php echo $cntBlocked; ?></div>
            <div class="form-hint" style="margin-top:2px">今日拦截次数</div>
        </div>
    </div>
</section>

<section class="card">
    <h2 class="card-title">新增黑名单</h2>
    <p class="card-desc">黑名单对验证接口实时生效（设备码/卡密/域名），命中即拒绝并在告警中心记录。</p>
    <form method="post" class="form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add">
        <div class="form-item">
            <label class="form-label">黑名单类型</label>
            <select class="form-input" name="type" style="max-width:200px">
                <?php foreach ($typeNames as $k => $name): ?>
                <option value="<?php echo $k; ?>"><?php echo e($name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-item">
            <label class="form-label">黑名单值</label>
            <input class="form-input" type="text" name="value" maxlength="190" style="max-width:360px" placeholder="设备码 / 卡密 / 用户名 / 邮箱 / 域名" required>
        </div>
        <div class="form-item">
            <label class="form-label">拉黑原因</label>
            <input class="form-input" type="text" name="reason" maxlength="255" style="max-width:360px" placeholder="例如：恶意共享授权（选填）">
        </div>
        <button class="btn-primary" type="submit" data-confirm="确认将该值加入黑名单？验证接口将立即生效。">加入黑名单</button>
    </form>
</section>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">黑名单列表（共 <?php echo $total; ?> 条）</h2>
    </div>

    <form method="get" class="filter-bar" style="flex-wrap:wrap;margin-bottom:12px">
        <select class="form-input" name="type" style="max-width:140px">
            <option value="">全部类型</option>
            <?php foreach ($typeNames as $k => $name): ?>
            <option value="<?php echo $k; ?>" <?php echo $type === (string)$k ? 'selected' : ''; ?>><?php echo e($name); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="status" style="max-width:140px">
            <option value="">全部状态</option>
            <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>生效中</option>
            <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>已停用</option>
        </select>
        <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="值 / 原因 关键词" style="max-width:240px">
        <button class="btn-plain" type="submit">查询</button>
    </form>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>类型</th><th>值</th><th>原因</th><th style="width:90px">状态</th><th>添加时间</th><th style="width:130px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td><span class="tag tag-info"><?php echo e(blacklist_type_name((int)$row['type'])); ?></span></td>
                <td class="cell-code" style="max-width:280px;word-break:break-all"><?php echo e($row['value']); ?></td>
                <td class="cell-sub" style="max-width:240px;word-break:break-all"><?php echo $row['reason'] !== '' ? e($row['reason']) : '-'; ?></td>
                <td>
                    <?php if ((int)$row['status'] === 1): ?>
                    <span class="tag tag-ok">生效中</span>
                    <?php else: ?>
                    <span class="tag tag-off">已停用</span>
                    <?php endif; ?>
                </td>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i', (int)$row['created_at']); ?></td>
                <td>
                    <form method="post" style="display:inline-block">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-mini-plain" type="submit" data-confirm="确认<?php echo (int)$row['status'] === 1 ? '停用' : '启用'; ?>该黑名单记录？"><?php echo (int)$row['status'] === 1 ? '停用' : '启用'; ?></button>
                    </form>
                    <form method="post" style="display:inline-block">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="del">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-danger-mini btn-mini" type="submit" data-confirm="确认删除该黑名单记录（<?php echo e($row['value']); ?>）？删除后立即恢复放行。">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $baseQuery = 'blacklist_manage.php?type=' . urlencode($type) . '&status=' . urlencode($status) . '&kw=' . urlencode($kw) . '&';
    render_pagination($total, $page, $perPage, $baseQuery);
    ?>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
