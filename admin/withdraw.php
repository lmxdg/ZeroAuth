<?php
// 管理员后台 - 提现管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Mail.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$error = '';

try {
    db()->query('ALTER TABLE `withdraw` ADD COLUMN `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT \'用户ID（与 agent_id 二选一）\' AFTER `agent_id`');
} catch (Throwable $ex) {
}

function wd_status_name(int $s): string
{
    return [0 => '待审核', 1 => '已通过', 2 => '已驳回', 3 => '已打款'][$s] ?? '未知';
}
function wd_status_tag(int $s): string
{
    return [0 => 'tag-warn', 1 => 'tag-ok', 2 => 'tag-err', 3 => 'tag-info'][$s] ?? 'tag-off';
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $wid    = (int)($_POST['id'] ?? 0);

    if ($action === 'fee_save') {
        $rate = (float)($_POST['fee_rate'] ?? 0);
        if ($rate < 0 || $rate > 50) {
            $error = '费率需在 0 ~ 50 之间。';
        } else {
            set_cfg('withdraw_fee_rate', (string)$rate, '提现手续费率（%）0-50');
            audit('config', 'withdraw_fee_update', 1, (int)$admin['id'], '提现费率调整为 ' . $rate . '%');
            flash_set('ok', '提现费率已保存：' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%。');
            redirect('withdraw.php');
        }
    }

    $stmt = db()->prepare('SELECT * FROM `withdraw` WHERE `id`=?');
    $stmt->execute([$wid]);
    $wd = $stmt->fetch();

    if (!$wd) {
        $error = '提现申请不存在。';
    } elseif ($action !== 'pass' && $action !== 'reject') {
        $error = '未知操作。';
    } elseif ((int)$wd['status'] !== 0) {
        $error = '该申请已审核过，请刷新页面。';
    } else {
        $reason   = trim((string)($_POST['reason'] ?? ''));
        $isAgent  = (int)$wd['agent_id'] > 0;
        $amount   = number_format((float)$wd['amount'], 2, '.', '');
        $ownerId  = $isAgent ? (int)$wd['agent_id'] : (int)$wd['user_id'];

        if ($action === 'reject' && ($reason === '' || mb_strlen($reason) > 200)) {
            $error = '驳回必须填写理由（200 字以内）。';
        } else {
            if ($isAgent) {
                $stmt = db()->prepare('SELECT `username`,`email`,`balance` FROM `agent` WHERE `id`=?');
            } else {
                $stmt = db()->prepare('SELECT `username`,`email`,`balance` FROM `user` WHERE `id`=?');
            }
            $stmt->execute([$ownerId]);
            $owner = $stmt->fetch();

            if (!$owner) {
                $error = $isAgent ? '代理商账号不存在。' : '用户账号不存在。';
            } elseif ($action === 'pass' && (float)$owner['balance'] < (float)$wd['amount']) {
                $error = '该' . ($isAgent ? '代理商' : '用户') . '当前余额不足以扣减（余额 ' . number_format((float)$owner['balance'], 2) . ' 元），请先核实。';
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    if ($action === 'pass') {
                        $table = $isAgent ? '`agent`' : '`user`';
                        $upd = $pdo->prepare('UPDATE ' . $table . ' SET `balance`=`balance`-? WHERE `id`=? AND `balance`>=?');
                        $upd->execute([$amount, $ownerId, $amount]);
                        if ($upd->rowCount() !== 1) {
                            throw new RuntimeException('余额扣减失败，请重试。');
                        }
                        $to = 1;
                    } else {
                        $to = 2;
                    }
                    $pdo->prepare('UPDATE `withdraw` SET `status`=?,`admin_remark`=?,`admin_id`=?,`review_time`=? WHERE `id`=? AND `status`=0')
                        ->execute([$to, $reason, (int)$admin['id'], time(), $wid]);
                    $pdo->commit();
                } catch (Throwable $ex) {
                    $pdo->rollBack();
                    $error = $ex instanceof RuntimeException ? $ex->getMessage() : '审核操作失败。';
                }

                if ($error === '') {
                    $wdFee  = (float)($wd['fee'] ?? 0);
                    $wdPaid = max(0, (float)$wd['amount'] - $wdFee);
                    audit('withdraw', $action === 'pass' ? 'pass' : 'reject', 1, (int)$admin['id'],
                        ($isAgent ? '代理商' : '用户') . '提现单 #' . $wid . ' 金额 ' . $wd['amount'] . ' 审核结果：' . ($action === 'pass' ? '通过（余额已扣减 ' . $wd['amount'] . '，线下打款 ' . number_format($wdPaid, 2, '.', '') . '）' : '驳回，理由：' . $reason));
                    if ((string)$owner['email'] !== '') {
                        mail_scene_send('withdraw', (string)$owner['email'], [
                            'userName' => (string)$owner['username'],
                            'amount'   => (string)$wd['amount'] . ' 元',
                            'fee'      => $wdFee > 0 ? number_format($wdFee, 2, '.', '') . ' 元' : '无',
                            'receive'  => number_format($wdPaid, 2, '.', '') . ' 元',
                            'account'  => (string)$wd['account'],
                            'result'   => $action === 'pass' ? '已通过' : '已驳回',
                            'reason'   => $reason !== '' ? $reason : '无',
                            'time'     => date('Y-m-d H:i'),
                        ]);
                    }
                    flash_set('ok', $action === 'pass'
                        ? '提现已通过，余额已扣减' . ((string)$owner['email'] !== '' ? '，结果已邮件通知申请人' : '') . '。'
                        : '提现已驳回' . ((string)$owner['email'] !== '' ? '，驳回理由已邮件通知申请人' : '') . '。');
                    redirect('withdraw.php');
                }
            }
        }
    }
}

$pageSize = 15;
$page     = max(1, (int)($_GET['page'] ?? 1));
$filterTy = (string)($_GET['type'] ?? '');   
$filterSt = (int)($_GET['status'] ?? 0);
$filterKw = trim((string)($_GET['kw'] ?? ''));

function page_url(int $p): string
{
    global $filterTy, $filterSt, $filterKw;
    return 'withdraw.php?' . http_build_query(['type' => $filterTy, 'status' => $filterSt ?: '', 'kw' => $filterKw, 'page' => $p]);
}

$where  = ['1=1'];
$params = [];
if ($filterTy === 'user') {
    $where[] = 'w.`user_id`>0';
} elseif ($filterTy === 'agent') {
    $where[] = 'w.`agent_id`>0';
}
if (in_array($filterSt, [0, 1, 2, 3], true) && $filterSt !== null && isset($_GET['status'])) {
    $where[]  = 'w.`status`=?';
    $params[] = $filterSt;
}
if ($filterKw !== '') {
    $where[]  = '(u.`username` LIKE ? OR g.`username` LIKE ? OR w.`account` LIKE ?)';
    $params[] = '%' . $filterKw . '%';
    $params[] = '%' . $filterKw . '%';
    $params[] = '%' . $filterKw . '%';
}
$whereStr = implode(' AND ', $where);

$stmt = db()->prepare('SELECT COUNT(*) FROM `withdraw` w LEFT JOIN `user` u ON u.`id`=w.`user_id` LEFT JOIN `agent` g ON g.`id`=w.`agent_id` WHERE ' . $whereStr);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $pageSize));
$page  = min($page, $pages);

$stmt = db()->prepare('SELECT w.*, u.`username` AS `user_name`, u.`email` AS `user_email`, g.`username` AS `agent_name`, g.`email` AS `agent_email` FROM `withdraw` w LEFT JOIN `user` u ON u.`id`=w.`user_id` LEFT JOIN `agent` g ON g.`id`=w.`agent_id` WHERE ' . $whereStr . ' ORDER BY FIELD(w.`status`,0,1,2,3), w.`id` DESC LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize));
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statStmt = db()->query('SELECT `status`, COUNT(*) AS `c` FROM `withdraw` GROUP BY `status`');
$stat = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
foreach ($statStmt->fetchAll() as $s) {
    $stat[(int)$s['status']] = (int)$s['c'];
}

admin_header('提现管理', 'withdraw', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif;
if ($error !== ''): ?>
<div class="alert alert-error">
    <img src="../assets/svg/warn-circle.svg" alt="">
    <span><?php echo e($error); ?></span>
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-label">待审核</div><div class="stat-value" style="color:var(--warning)"><?php echo $stat[0]; ?></div></div>
    <div class="stat-card"><div class="stat-label">已通过</div><div class="stat-value" style="color:var(--success)"><?php echo $stat[1]; ?></div></div>
    <div class="stat-card"><div class="stat-label">已驳回</div><div class="stat-value" style="color:var(--error)"><?php echo $stat[2]; ?></div></div>
</div>

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">提现费率</h2>
            <p class="card-desc" style="margin-bottom:0">按提现金额的百分比收取手续费，申请时费率随单快照；0 表示不收费。</p>
        </div>
        <div class="spacer"></div>
        <form method="post" class="filter-bar" style="margin-bottom:0">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="fee_save">
            <input class="form-input" type="number" name="fee_rate" min="0" max="50" step="0.01" value="<?php echo e(cfg('withdraw_fee_rate', '0')); ?>" style="width:110px" required>
            <span style="line-height:32px;color:var(--text-sub)">%</span>
            <button class="btn-primary" type="submit">保存费率</button>
        </form>
    </div>
</section>

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">提现申请（共 <?php echo $total; ?> 条）</h2>
            <p class="card-desc" style="margin-bottom:0">通过将扣减申请人余额，驳回需填写理由；审核结果自动邮件通知申请人。</p>
        </div>
        <div class="spacer"></div>
        <div class="filter-bar" style="margin-bottom:0">
            <a class="btn-plain" href="withdraw.php" style="text-decoration:none;line-height:32px">全部</a>
            <a class="btn-mini-plain <?php echo $filterTy === 'user' ? 'btn-mini' : ''; ?>" href="withdraw.php?type=user<?php echo isset($_GET['status']) ? '&status=' . $filterSt : ''; ?>" style="line-height:30px">用户</a>
            <a class="btn-mini-plain <?php echo $filterTy === 'agent' ? 'btn-mini' : ''; ?>" href="withdraw.php?type=agent<?php echo isset($_GET['status']) ? '&status=' . $filterSt : ''; ?>" style="line-height:30px">代理商</a>
            <a class="btn-mini-plain <?php echo ($_GET['status'] ?? '') === '0' ? 'btn-mini' : ''; ?>" href="withdraw.php?status=0<?php echo $filterTy !== '' ? '&type=' . $filterTy : ''; ?>" style="line-height:30px">待审核 <?php echo $stat[0]; ?></a>
            <form method="get" style="display:flex">
                <?php if ($filterTy !== ''): ?><input type="hidden" name="type" value="<?php echo e($filterTy); ?>"><?php endif; ?>
                <?php if (isset($_GET['status'])): ?><input type="hidden" name="status" value="<?php echo $filterSt; ?>"><?php endif; ?>
                <input class="form-input" type="text" name="kw" value="<?php echo e($filterKw); ?>" placeholder="账号 / 收款账号">
                <button class="btn-plain" type="submit">搜索</button>
            </form>
        </div>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-money.svg" alt="">
        <p>暂无提现申请。</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>#</th><th>类型</th><th>申请人</th><th>金额</th><th>收款账号</th><th>状态</th><th>时间</th><th style="width:220px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                $isAgent = (int)$row['agent_id'] > 0;
                $uname   = $isAgent ? (string)$row['agent_name'] : (string)$row['user_name'];
                $uemail  = $isAgent ? (string)$row['agent_email'] : (string)$row['user_email'];
                $pending = (int)$row['status'] === 0;
            ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td><span class="tag <?php echo $isAgent ? 'tag-info' : 'tag-off'; ?>"><?php echo $isAgent ? '代理商' : '用户'; ?></span></td>
                <td class="cell-main"><?php echo e($uname !== '' ? $uname : ('#' . ($isAgent ? $row['agent_id'] : $row['user_id']))); ?><?php echo $uemail !== '' ? '<div><span class="wd-k">邮箱</span>' . e($uemail) . '</div>' : ''; ?></td>
                <td class="cell-main" style="white-space:nowrap"><?php $rFee = (float)($row['fee'] ?? 0); ?>
                    <div><span class="wd-k">金额</span><?php echo e(number_format((float)$row['amount'], 2)); ?> 元</div>
                    <div><span class="wd-k">到账</span><span style="font-weight:600;color:var(--error)"><?php echo e(number_format(max(0, (float)$row['amount'] - $rFee), 2)); ?> 元</span></div>
                </td>
                <td class="cell-sub"><?php echo e($row['account']); ?><?php echo $row['agent_remark'] !== '' ? '<div><span class="wd-k">备注</span>' . e($row['agent_remark']) . '</div>' : ''; ?></td>
                <td>
                    <span class="tag <?php echo wd_status_tag((int)$row['status']); ?>"><?php echo wd_status_name((int)$row['status']); ?></span>
                    <?php if ((int)$row['status'] === 2 && $row['admin_remark'] !== ''): ?>
                    <div><span class="wd-k">理由</span><?php echo e($row['admin_remark']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="cell-sub"><div><span class="wd-k">申请</span><?php echo date('Y-m-d H:i', (int)$row['created_at']); ?></div><?php echo (int)$row['status'] > 0 && (int)$row['review_time'] > 0 ? '<div><span class="wd-k">审核</span>' . date('Y-m-d H:i', (int)$row['review_time']) . '</div>' : ''; ?></td>
                <td style="white-space:normal">
                    <?php if ($pending): ?>
                    <?php $wdFee = (float)($row['fee'] ?? 0); $wdPaid = max(0, (float)$row['amount'] - $wdFee); ?>
                    <form method="post" style="display:inline-block" data-confirm="确认通过「<?php echo e($uname); ?>」的提现申请？余额将扣减 <?php echo e(number_format((float)$row['amount'], 2)); ?> 元；手续费 <?php echo $wdFee > 0 ? e(number_format($wdFee, 2)) . ' 元（费率 ' . e(rtrim(rtrim(number_format((float)($row['fee_rate'] ?? 0), 2, '.', ''), '0'), '.')) . '%）' : '免费'; ?>，请线下打款 <?php echo e(number_format($wdPaid, 2)); ?> 元并确认到账。">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="pass">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-mini" type="submit">通过</button>
                    </form>
                    <button class="btn-mini-plain" type="button" onclick="openWdReject(<?php echo (int)$row['id']; ?>, '<?php echo e($uname); ?>', '<?php echo e(number_format((float)$row['amount'], 2)); ?>')">驳回</button>
                    <?php else: ?>
                    <span class="cell-sub">已处理</span>
                    <?php endif; ?>
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
    <?php endif; ?>
</section>

<div class="modal-mask" id="wdRjMask">
    <div class="modal-box" style="width:440px;max-width:calc(100vw - 40px)">
        <div class="modal-title">驳回提现申请</div>
        <div class="modal-text" id="wdRjWho"></div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="wdRjId" value="0">
            <textarea class="form-input" id="wdRjReason" name="reason" rows="3" maxlength="200" placeholder="请填写驳回理由（必填，将以邮件通知申请人）" required style="height:auto;min-height:80px;resize:vertical;margin-bottom:18px"></textarea>
            <div class="modal-actions" style="margin-bottom:0">
                <button class="btn-plain" type="button" onclick="closeWdReject()">取消</button>
                <button class="btn-danger-mini" type="submit">确认驳回</button>
            </div>
        </form>
    </div>
</div>
<script>
function openWdReject(id, name, amount) {
    document.getElementById('wdRjId').value = id;
    document.getElementById('wdRjWho').textContent = '确定驳回「' + name + '」的 ' + amount + ' 元提现申请吗？余额不做扣减，驳回理由将通过邮件通知。';
    document.getElementById('wdRjReason').value = '';
    document.getElementById('wdRjMask').classList.add('show');
    setTimeout(function () { document.getElementById('wdRjReason').focus(); }, 50);
}
function closeWdReject() {
    document.getElementById('wdRjMask').classList.remove('show');
}
document.getElementById('wdRjMask').addEventListener('click', function (e) { if (e.target === this) closeWdReject(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeWdReject(); });
</script>
<?php admin_footer(); ?>
