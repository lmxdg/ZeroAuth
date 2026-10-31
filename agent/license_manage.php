<?php
// 代理商中心 - 授权管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/layout_agent.php';

$agent = agent_require_login();
$aid   = (int)$agent['id'];
$act   = (string)($_GET['act'] ?? 'list');
$error = '';

function ltype_name(int $type): string
{
    return [1 => '限时', 2 => '计次', 3 => '永久'][$type] ?? '未知';
}

function agent_own_license(int $id, int $aid): ?array
{
    $stmt = db()->prepare('SELECT * FROM `license` WHERE `id`=? AND `owner_type`=2 AND `owner_id`=?');
    $stmt->execute([$id, $aid]);
    return $stmt->fetch() ?: null;
}

if (is_post()) {
    csrf_check();
    $action   = (string)($_POST['action'] ?? '');
    $id       = (int)($_POST['id'] ?? 0);
    $license  = agent_own_license($id, $aid);

    if (!$license) {
        flash_set('err', '卡密不存在或无权操作。');
        redirect('license_manage.php');
    }

    if ($action === 'ban' || $action === 'unban') {
        $to = $action === 'ban' ? 2 : 1;
        db()->prepare('UPDATE `license` SET `status`=? WHERE `id`=?')->execute([$to, $id]);
        audit('license', 'agent_' . $action, 2, $aid, '卡密：' . $license['card_no']);
        flash_set('ok', $action === 'ban' ? '卡密已封禁，验证接口将立即拒绝。' : '卡密已解封。');
        redirect('license_manage.php?act=detail&id=' . $id);
    }

    if ($action === 'add_domain') {
        $domain = sec_normalize_domain((string)($_POST['domain'] ?? ''));
        if ($domain === '') {
            flash_set('err', '域名格式不正确。');
        } else {
            $stmt = db()->prepare('SELECT COUNT(*) FROM `license_domain` WHERE `license_id`=?');
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() >= 100) {
                flash_set('err', '绑定域名最多 100 个。');
            } else {
                db()->prepare('INSERT IGNORE INTO `license_domain` (`license_id`,`domain`,`is_https`,`status`,`created_at`) VALUES (?,?,0,1,?)')
                    ->execute([$id, $domain, time()]);
                audit('license', 'agent_add_domain', 2, $aid, '卡密：' . $license['card_no'] . ' 绑定域名：' . $domain);
                flash_set('ok', '域名已绑定。');
            }
        }
        redirect('license_manage.php?act=detail&id=' . $id);
    }

    if ($action === 'del_domain') {
        $did = (int)($_POST['domain_id'] ?? 0);
        db()->prepare('DELETE FROM `license_domain` WHERE `id`=? AND `license_id`=?')->execute([$did, $id]);
        audit('license', 'agent_del_domain', 2, $aid, '卡密：' . $license['card_no']);
        flash_set('ok', '域名绑定已解除。');
        redirect('license_manage.php?act=detail&id=' . $id);
    }

    if ($action === 'unbind_device') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $stmt = db()->prepare('SELECT `device_code`,`device_name` FROM `license_device` WHERE `id`=? AND `license_id`=?');
        $stmt->execute([$deviceId, $id]);
        $dev = $stmt->fetch();
        if ($dev) {
            db()->prepare('DELETE FROM `license_device` WHERE `id`=?')->execute([$deviceId]);
            audit('device', 'agent_unbind', 2, $aid, '卡密：' . $license['card_no'] . ' 设备：' . $dev['device_name']);
            flash_set('ok', '设备「' . $dev['device_name'] . '」已强制解绑。');
        }
        redirect('license_manage.php?act=detail&id=' . $id);
    }

    if ($action === 'unbind_all') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM `license_device` WHERE `license_id`=?');
        $stmt->execute([$id]);
        $cnt = (int)$stmt->fetchColumn();
        db()->prepare('DELETE FROM `license_device` WHERE `license_id`=?')->execute([$id]);
        audit('device', 'agent_unbind_all', 2, $aid, '卡密：' . $license['card_no'] . ' 解绑 ' . $cnt . ' 台设备');
        flash_set('ok', '已解绑全部 ' . $cnt . ' 台设备。');
        redirect('license_manage.php?act=detail&id=' . $id);
    }
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));

if ($act === 'detail') {
    $license = agent_own_license((int)($_GET['id'] ?? 0), $aid);
    if (!$license) {
        flash_set('err', '卡密不存在或无权查看。');
        redirect('license_manage.php');
    }

    $stmt = db()->prepare('SELECT `name` FROM `app` WHERE `id`=?');
    $stmt->execute([(int)$license['app_id']]);
    $appName = (string)($stmt->fetchColumn() ?: '未知应用');

    $stmt = db()->prepare('SELECT * FROM `license_domain` WHERE `license_id`=? ORDER BY `id` ASC');
    $stmt->execute([(int)$license['id']]);
    $domains = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT * FROM `license_device` WHERE `license_id`=? ORDER BY `last_verify_time` DESC');
    $stmt->execute([(int)$license['id']]);
    $devices = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT `username` FROM `user` WHERE `id`=?');
    $stmt->execute([(int)$license['user_id']]);
    $ownerUser = (string)($stmt->fetchColumn() ?: '');
}

$kw     = trim((string)($_GET['kw'] ?? ''));
$status = (string)($_GET['status'] ?? '');

$where  = ' WHERE `l`.`owner_type`=2 AND `l`.`owner_id`=?';
$params = [$aid];
if ($kw !== '') {
    $where .= ' AND `l`.`card_no` LIKE ?';
    $params[] = '%' . $kw . '%';
}
if ($status === '1' || $status === '2') {
    $where .= ' AND `l`.`status`=?';
    $params[] = (int)$status;
}
$stmt = db()->prepare('SELECT COUNT(*) FROM `license` `l`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$params[] = $perPage;
$params[] = ($page - 1) * $perPage;
$stmt = db()->prepare(
    'SELECT `l`.*, (SELECT COUNT(*) FROM `license_device` `d` WHERE `d`.`license_id`=`l`.`id` AND `d`.`status`=1) AS `device_count` '
    . 'FROM `license` `l`' . $where . ' ORDER BY `l`.`id` DESC LIMIT ? OFFSET ?'
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

agent_header($act === 'detail' ? '授权详情' : '我的授权', 'license_manage', $agent);
?>
<style>

.key-line{display:flex;align-items:center;gap:10px;background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:12px 16px;margin-bottom:16px;flex-wrap:wrap}
.key-value{font-family:Consolas,Monaco,monospace;font-size:16px;font-weight:700;letter-spacing:1px;color:var(--t1);word-break:break-all}
.kv-table{width:100%;border-collapse:collapse;font-size:13px}
.kv-table th{width:110px;text-align:left;padding:11px 12px;color:var(--t3);font-weight:400;vertical-align:top;white-space:nowrap}
.kv-table td{padding:11px 12px;color:var(--t1);border-bottom:1px solid var(--bd)}
.kv-table tr:last-child td{border-bottom:none}
.cell-sub{font-size:12px;color:var(--t3)}
.cell-main{color:var(--t1)}
.btn-mini,.btn-mini-plain{display:inline-flex;align-items:center;justify-content:center;height:28px;padding:0 12px;border-radius:var(--r);font-size:12px;cursor:pointer;border:1px solid var(--bd);background:var(--card);color:var(--t2)}
.btn-mini:hover,.btn-mini-plain:hover{color:var(--p);border-color:var(--p)}
.btn-danger-mini{display:inline-flex;align-items:center;justify-content:center;height:28px;padding:0 12px;border-radius:var(--r);font-size:12px;cursor:pointer;border:1px solid #FDC9C9;background:var(--err-bg);color:var(--err)}
.btn-danger-mini:hover{background:var(--err);border-color:var(--err);color:#FFF}
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.filter-bar .form-input{max-width:220px}
</style>
<?php
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<?php if ($act === 'detail'):
    $lic = $license;
    ?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">卡密详情</h2>
            <p class="card-desc" style="margin-bottom:0">所属应用：<?php echo e($appName); ?> · <?php echo e(ltype_name((int)$lic['type'])); ?>授权</p>
        </div>
        <div class="spacer"></div>
        <a class="btn-p" href="license_manage.php">返回列表</a>
    </div>

    <div class="key-line">
        <span class="key-value"><?php echo e($lic['card_no']); ?></span>
        <span class="tag <?php echo (int)$lic['status'] === 1 ? 'tag-ok' : 'tag-err'; ?>"><?php echo (int)$lic['status'] === 1 ? '正常' : '已封禁'; ?></span>
    </div>

    <div class="table-wrap">
        <table class="kv-table">
            <tbody>
            <tr><th>授权状态</th>
                <td>
                    <span class="tag <?php echo (int)$lic['status'] === 1 ? 'tag-ok' : 'tag-err'; ?>"><?php echo (int)$lic['status'] === 1 ? '正常' : '已封禁'; ?></span>
                    <form method="post" style="display:inline-block;margin-left:8px">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="<?php echo (int)$lic['status'] === 2 ? 'unban' : 'ban'; ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$lic['id']; ?>">
                        <button class="btn-mini-plain" type="submit" data-confirm="确认<?php echo (int)$lic['status'] === 2 ? '解封' : '封禁'; ?>该卡密？"><?php echo (int)$lic['status'] === 2 ? '解封' : '一键封禁'; ?></button>
                    </form>
                </td>
            </tr>
            <tr><th>类型 / 次数</th>
                <td><?php echo e(ltype_name((int)$lic['type'])); ?>
                    <?php if ((int)$lic['type'] === 1): ?>，<?php echo date('Y-m-d H:i', (int)$lic['start_time']); ?> ~ <?php echo (int)$lic['expire_time'] > 0 ? date('Y-m-d H:i', (int)$lic['expire_time']) : '不限'; ?>
                    <?php elseif ((int)$lic['type'] === 2): ?>，已用 <?php echo (int)$lic['used_count']; ?> / <?php echo (int)$lic['total_count']; ?> 次
                    <?php else: ?>，不限时不限次<?php endif; ?>
                </td>
            </tr>
            <tr><th>设备绑定</th><td>已绑定 <?php echo count($devices); ?> / 上限 <?php echo (int)$lic['max_devices']; ?> 台</td></tr>
            <tr><th>结算单价</th><td><?php echo e(number_format((float)$lic['price'], 2)); ?> 元</td></tr>
            <tr><th>兑换用户</th><td><?php echo $ownerUser !== '' ? e($ownerUser) : '<span class="cell-sub">未被兑换</span>'; ?></td></tr>
            <tr><th>备注</th><td class="cell-sub"><?php echo $lic['remark'] !== '' ? e($lic['remark']) : '-'; ?></td></tr>
            <tr><th>生成时间</th><td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$lic['created_at']); ?></td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2 class="card-title">绑定域名（<?php echo count($domains); ?> 个）</h2>
    <form method="post" class="filter-bar">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add_domain">
        <input type="hidden" name="id" value="<?php echo (int)$lic['id']; ?>">
        <input class="form-input" type="text" name="domain" placeholder="example.com（可带协议/端口）" required>
        <button class="btn-plain" type="submit">绑定域名</button>
    </form>
    <?php if (!$domains): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>未绑定域名；不绑定域名时验证接口不校验来源域名。</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>域名</th><th>绑定时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($domains as $dm): ?>
            <tr>
                <td class="cell-code cell-main"><?php echo e($dm['domain']); ?></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$dm['created_at']); ?></td>
                <td>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="del_domain">
                        <input type="hidden" name="id" value="<?php echo (int)$lic['id']; ?>">
                        <input type="hidden" name="domain_id" value="<?php echo (int)$dm['id']; ?>">
                        <button class="btn-danger-mini" type="submit" data-confirm="确认解除域名 <?php echo e($dm['domain']); ?> 的绑定？">解除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">绑定设备（<?php echo count($devices); ?> 台）</h2>
        <div class="spacer"></div>
        <?php if ($devices): ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="unbind_all">
            <input type="hidden" name="id" value="<?php echo (int)$lic['id']; ?>">
            <button class="btn-danger-mini" type="submit" data-confirm="确认强制解绑该卡密下的全部设备？解绑后设备需重新验证绑定。">全部解绑</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if (!$devices): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无绑定设备。</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>设备名称</th><th>设备指纹</th><th>绑定 IP</th><th>首次绑定</th><th>最后验证</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($devices as $dv): ?>
            <tr>
                <td class="cell-main"><?php echo $dv['device_name'] !== '' ? e($dv['device_name']) : '未命名设备'; ?></td>
                <td class="cell-code cell-sub"><?php echo e(substr((string)$dv['device_code'], 0, 16)); ?>…</td>
                <td class="cell-sub"><?php echo e($dv['bind_ip']); ?></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$dv['first_bind_time']); ?></td>
                <td class="cell-sub"><?php echo (int)$dv['last_verify_time'] > 0 ? date('Y-m-d H:i', (int)$dv['last_verify_time']) : '-'; ?></td>
                <td>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="unbind_device">
                        <input type="hidden" name="id" value="<?php echo (int)$lic['id']; ?>">
                        <input type="hidden" name="device_id" value="<?php echo (int)$dv['id']; ?>">
                        <button class="btn-danger-mini" type="submit" data-confirm="确认强制解绑设备「<?php echo e($dv['device_name'] !== '' ? $dv['device_name'] : '未命名设备'); ?>」？">强制解绑</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php else: ?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">我的卡密（共 <?php echo $total; ?> 张）</h2>
            <p class="card-desc" style="margin-bottom:0">仅显示本代理商生成的卡密。</p>
        </div>
        <div class="spacer"></div>
        <form method="get" class="filter-bar" style="margin-bottom:0">
            <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="按卡密号搜索">
            <select class="form-input" name="status">
                <option value="">全部状态</option>
                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>正常</option>
                <option value="2" <?php echo $status === '2' ? 'selected' : ''; ?>>已封禁</option>
            </select>
            <button class="btn-plain" type="submit">筛选</button>
        </form>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无卡密，可通过「开通授权」自助开卡。</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>卡密</th><th>类型</th><th>状态</th><th>设备</th><th>有效期至</th><th>兑换</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="cell-code cell-main"><?php echo e($row['card_no']); ?></td>
                <td><span class="tag tag-info"><?php echo e(ltype_name((int)$row['type'])); ?></span></td>
                <td><span class="tag <?php echo (int)$row['status'] === 1 ? 'tag-ok' : 'tag-err'; ?>"><?php echo (int)$row['status'] === 1 ? '正常' : '已封禁'; ?></span></td>
                <td class="cell-sub"><?php echo (int)$row['device_count']; ?> / <?php echo (int)$row['max_devices']; ?></td>
                <td class="cell-sub"><?php echo (int)$row['type'] === 1 && (int)$row['expire_time'] > 0 ? date('Y-m-d H:i', (int)$row['expire_time']) : ((int)$row['type'] === 1 ? '不限' : '-'); ?></td>
                <td class="cell-sub"><?php echo (int)$row['user_id'] > 0 ? '已兑换' : '未兑换'; ?></td>
                <td><a class="btn-mini" href="license_manage.php?act=detail&id=<?php echo (int)$row['id']; ?>">详情</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, 'license_manage.php?kw=' . urlencode($kw) . '&status=' . urlencode($status) . '&'); ?>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php agent_footer(); ?>
