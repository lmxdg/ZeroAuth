<?php
// 用户中心 - 我的授权
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_user.php';

$user = user_require_login();
$uid  = (int)$user['id'];
$act  = (string)($_GET['act'] ?? 'list');

function ul_type_name(int $type): string
{
    return [1 => '限时', 2 => '计次', 3 => '永久'][$type] ?? '未知';
}

function ul_expired(array $lic): bool
{
    if ((int)$lic['type'] === 1 && (int)$lic['expire_time'] > 0 && (int)$lic['expire_time'] < time()) {
        return true;
    }
    if ((int)$lic['type'] === 2 && (int)$lic['total_count'] > 0 && (int)$lic['used_count'] >= (int)$lic['total_count']) {
        return true;
    }
    return false;
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM `license` WHERE `id`=? AND `user_id`=?');
    $stmt->execute([$id, $uid]);
    $lic = $stmt->fetch();

    if (!$lic) {
        flash_set('err', '授权不存在或无权操作。');
        redirect('licenses.php');
    }

    if ($action === 'unbind_device') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $stmt = db()->prepare('SELECT `device_code`,`device_name` FROM `license_device` WHERE `id`=? AND `license_id`=?');
        $stmt->execute([$deviceId, $id]);
        $dev = $stmt->fetch();
        if ($dev) {
            db()->prepare('DELETE FROM `license_device` WHERE `id`=?')->execute([$deviceId]);
            audit('device', 'user_unbind', 3, $uid, '卡密：' . $lic['card_no'] . ' 自助解绑：' . $dev['device_name']);
            flash_set('ok', '设备「' . $dev['device_name'] . '」已解绑。');
        }
        redirect('licenses.php?act=detail&id=' . $id);
    }

    if ($action === 'unbind_all') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM `license_device` WHERE `license_id`=?');
        $stmt->execute([$id]);
        $cnt = (int)$stmt->fetchColumn();
        db()->prepare('DELETE FROM `license_device` WHERE `license_id`=?')->execute([$id]);
        audit('device', 'user_unbind_all', 3, $uid, '卡密：' . $lic['card_no'] . ' 自助解绑全部 ' . $cnt . ' 台设备');
        flash_set('ok', '已解绑全部 ' . $cnt . ' 台设备。');
        redirect('licenses.php?act=detail&id=' . $id);
    }
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));

if ($act === 'detail') {
    $stmt = db()->prepare('SELECT * FROM `license` WHERE `id`=? AND `user_id`=?');
    $stmt->execute([(int)($_GET['id'] ?? 0), $uid]);
    $lic = $stmt->fetch();
    if (!$lic) {
        flash_set('err', '授权不存在或无权查看。');
        redirect('licenses.php');
    }

    $stmt = db()->prepare('SELECT `name` FROM `app` WHERE `id`=?');
    $stmt->execute([(int)$lic['app_id']]);
    $appName = (string)($stmt->fetchColumn() ?: '未知应用');

    $stmt = db()->prepare('SELECT * FROM `license_domain` WHERE `license_id`=? ORDER BY `id` ASC');
    $stmt->execute([(int)$lic['id']]);
    $domains = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT * FROM `license_device` WHERE `license_id`=? ORDER BY `last_verify_time` DESC');
    $stmt->execute([(int)$lic['id']]);
    $devices = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT `ip`,`domain`,`device_code`,`result`,`fail_reason`,`created_at` FROM `verify_log` WHERE `license_id`=? ORDER BY `id` DESC LIMIT 20');
    $stmt->execute([(int)$lic['id']]);
    $logs = $stmt->fetchAll();
}

$stmt = db()->prepare('SELECT COUNT(*) FROM `license` WHERE `user_id`=?');
$stmt->execute([$uid]);
$total = (int)$stmt->fetchColumn();
$stmt = db()->prepare(
    'SELECT `l`.*, `a`.`name` AS `app_name` FROM `license` `l` LEFT JOIN `app` `a` ON `a`.`id`=`l`.`app_id` '
    . 'WHERE `l`.`user_id`=? ORDER BY `l`.`id` DESC LIMIT ? OFFSET ?'
);
$stmt->execute([$uid, $perPage, ($page - 1) * $perPage]);
$rows = $stmt->fetchAll();

user_header($act === 'detail' ? '授权详情' : '我的授权', 'licenses', $user);
?>

<style>

.key-line{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:12px 16px;margin-bottom:16px}
.key-value{font-family:Consolas,Monaco,monospace;font-size:15px;font-weight:600;color:var(--t1);word-break:break-all}
.kv-table{width:100%;border-collapse:collapse;font-size:13px}
.kv-table th{width:120px;text-align:left;padding:11px 12px;color:var(--t3);font-weight:400;vertical-align:top;border-bottom:1px solid var(--bd);white-space:nowrap}
.kv-table td{padding:11px 12px;color:var(--t1);border-bottom:1px solid var(--bd)}
.kv-table tr:last-child th,.kv-table tr:last-child td{border-bottom:none}
.btn-mini{display:inline-flex;align-items:center;height:26px;padding:0 12px;border:1px solid var(--bd);border-radius:var(--r);font-size:12px;color:var(--t2);cursor:pointer;background:var(--card)}
.btn-mini:hover{color:var(--p);border-color:var(--p)}
.btn-danger-mini{display:inline-flex;align-items:center;height:26px;padding:0 12px;border:1px solid #FDC9C9;border-radius:var(--r);font-size:12px;color:var(--err);cursor:pointer;background:var(--card)}
.btn-danger-mini:hover{background:var(--err-bg)}
.log-fail{color:var(--err)}
</style>

<?php $flash = flash_take(); if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<?php if ($act === 'detail'):
    $expired = ul_expired($lic);
    ?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">授权详情</h2>
            <p class="card-desc" style="margin-bottom:0">所属应用：<?php echo e($appName); ?></p>
        </div>
        <div class="spacer"></div>
        <a class="btn-plain" href="licenses.php">返回列表</a>
    </div>

    <div class="key-line">
        <span class="key-value"><?php echo e($lic['card_no']); ?></span>
        <?php if ((int)$lic['status'] === 2): ?>
        <span class="tag tag-err">已封禁</span>
        <?php elseif ($expired): ?>
        <span class="tag tag-warn">已失效</span>
        <?php else: ?>
        <span class="tag tag-ok">有效</span>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="kv-table">
            <tbody>
            <tr><th>授权类型</th>
                <td><?php echo e(ul_type_name((int)$lic['type'])); ?>
                    <?php if ((int)$lic['type'] === 1): ?>，<?php echo date('Y-m-d H:i', (int)$lic['start_time']); ?> ~ <?php echo (int)$lic['expire_time'] > 0 ? date('Y-m-d H:i', (int)$lic['expire_time']) : '不限'; ?>
                    <?php elseif ((int)$lic['type'] === 2): ?>，剩余 <?php echo max(0, (int)$lic['total_count'] - (int)$lic['used_count']); ?> / <?php echo (int)$lic['total_count']; ?> 次
                    <?php else: ?>，永久有效<?php endif; ?>
                </td>
            </tr>
            <tr><th>设备绑定</th><td>已绑定 <?php echo count($devices); ?> / 上限 <?php echo (int)$lic['max_devices']; ?> 台</td></tr>
            <tr><th>绑定域名</th>
                <td>
                    <?php if (!$domains): ?>
                        <span style="color:var(--t3)">未绑定域名（不校验来源域名）</span>
                    <?php else: ?>
                        <?php foreach ($domains as $i => $dm): ?><?php echo $i > 0 ? '、' : ''; ?><code class="cell-code"><?php echo e($dm['domain']); ?></code><?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr><th>兑换时间</th><td>卡密生成于 <?php echo date('Y-m-d H:i', (int)$lic['created_at']); ?></td></tr>
            </tbody>
        </table>
    </div>
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
            <button class="btn-danger-mini" type="submit" data-confirm="确认解绑全部设备？解绑后需重新验证绑定。">全部解绑</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if (!$devices): ?>
    <div class="empty-state"><img src="../assets/svg/menu-key.svg" alt=""><span>暂无绑定设备；客户端首次验证成功后自动绑定。</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>设备名称</th><th>设备指纹</th><th>首次绑定</th><th>最后验证</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($devices as $dv): ?>
            <tr>
                <td><?php echo $dv['device_name'] !== '' ? e($dv['device_name']) : '未命名设备'; ?></td>
                <td class="cell-code"><?php echo e(substr((string)$dv['device_code'], 0, 16)); ?>…</td>
                <td><?php echo date('Y-m-d H:i', (int)$dv['first_bind_time']); ?></td>
                <td><?php echo (int)$dv['last_verify_time'] > 0 ? date('Y-m-d H:i', (int)$dv['last_verify_time']) : '-'; ?></td>
                <td>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="unbind_device">
                        <input type="hidden" name="id" value="<?php echo (int)$lic['id']; ?>">
                        <input type="hidden" name="device_id" value="<?php echo (int)$dv['id']; ?>">
                        <button class="btn-danger-mini" type="submit" data-confirm="确认解绑设备「<?php echo e($dv['device_name'] !== '' ? $dv['device_name'] : '未命名设备'); ?>」？">解绑</button>
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
    <h2 class="card-title">验证日志（最近 20 条）</h2>
    <?php if (!$logs): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无验证记录。</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>时间</th><th>结果</th><th>IP</th><th>域名</th><th>设备指纹</th><th>失败原因</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $lg): ?>
            <tr>
                <td class="cell-code"><?php echo e(date('Y-m-d H:i:s', (int)$lg['created_at'])); ?></td>
                <td><?php echo (int)$lg['result'] === 1 ? '<span class="tag tag-ok">成功</span>' : '<span class="tag tag-err">失败</span>'; ?></td>
                <td class="cell-code"><?php echo e($lg['ip']); ?></td>
                <td class="cell-code"><?php echo e($lg['domain'] !== '' ? $lg['domain'] : '-'); ?></td>
                <td class="cell-code"><?php echo $lg['device_code'] !== '' ? e(substr((string)$lg['device_code'], 0, 16)) . '…' : '-'; ?></td>
                <td class="log-fail"><?php echo $lg['fail_reason'] !== '' ? e($lg['fail_reason']) : '-'; ?></td>
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
        <h2 class="card-title" style="margin-bottom:0">我的授权（共 <?php echo $total; ?> 条）</h2>
        <div class="spacer"></div>
        <a class="btn-p" href="redeem.php">兑换新卡密</a>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无授权，先去「卡密兑换」激活授权码，或前往「购买授权」在线开通。</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>应用</th><th>卡密</th><th>类型</th><th>状态</th><th>有效期至</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): $expired = ul_expired($row); ?>
            <tr>
                <td><?php echo e($row['app_name'] ?? '未知应用'); ?></td>
                <td class="cell-code"><?php echo e($row['card_no']); ?></td>
                <td><span class="tag tag-info"><?php echo e(ul_type_name((int)$row['type'])); ?></span></td>
                <td>
                    <?php if ((int)$row['status'] === 2): ?>
                    <span class="tag tag-err">已封禁</span>
                    <?php elseif ($expired): ?>
                    <span class="tag tag-warn">已失效</span>
                    <?php else: ?>
                    <span class="tag tag-ok">有效</span>
                    <?php endif; ?>
                </td>
                <td><?php echo (int)$row['type'] === 1 && (int)$row['expire_time'] > 0 ? date('Y-m-d H:i', (int)$row['expire_time']) : ((int)$row['type'] === 1 ? '不限' : '-'); ?></td>
                <td><a class="btn-mini" href="licenses.php?act=detail&id=<?php echo (int)$row['id']; ?>">详情</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, 'licenses.php?'); ?>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php user_footer(); ?>
