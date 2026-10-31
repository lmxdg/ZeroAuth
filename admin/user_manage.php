<?php
// 管理员后台 - 用户管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';
require dirname(__DIR__) . '/core/Security.php';

$admin = admin_require_login();
$act   = (string)($_GET['act'] ?? 'list');
$error = '';

function real_status_info(int $s): array
{
    return [0 => ['未认证', 'tag-off'], 1 => ['待审核', 'tag-warn'], 2 => ['已通过', 'tag-ok'], 3 => ['已驳回', 'tag-err']][$s] ?? ['未知', 'tag-off'];
}

function agent_audit_label(int $aid): string
{
    if ($aid <= 0) {
        return '平台直营';
    }
    $st = db()->prepare('SELECT `username` FROM `agent` WHERE `id`=?');
    $st->execute([$aid]);
    $name = (string)$st->fetchColumn();
    return ($name !== '' ? $name : '已删除代理商') . '(#' . $aid . ')';
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'create') {
        $cUsername = trim((string)($_POST['username'] ?? ''));
        $cPassword = (string)($_POST['password'] ?? '');
        $cEmail    = trim((string)($_POST['email'] ?? ''));
        $cAgentId  = (int)($_POST['agent_id'] ?? 0);

        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $cUsername)) {
            $error = '账号需为 3 ~ 32 位字母、数字或下划线。';
        } elseif (strlen($cPassword) < 6 || strlen($cPassword) > 64) {
            $error = '初始密码长度需在 6 ~ 64 位之间。';
        } elseif ($cEmail !== '' && !filter_var($cEmail, FILTER_VALIDATE_EMAIL)) {
            $error = '邮箱格式不正确。';
        } else {
            $stmt = db()->prepare('SELECT `id` FROM `user` WHERE `username`=?');
            $stmt->execute([$cUsername]);
            if ($stmt->fetch()) {
                $error = '该账号已被注册。';
            } elseif ($cAgentId > 0) {
                $stmt = db()->prepare('SELECT `id` FROM `agent` WHERE `id`=? AND `status`=1');
                $stmt->execute([$cAgentId]);
                if (!$stmt->fetch()) {
                    $error = '所选代理商不存在或已禁用。';
                }
            }
            if ($error === '') {
                db()->prepare('INSERT INTO `user` (`username`,`password`,`email`,`agent_id`,`status`,`reg_ip`,`created_at`) VALUES (?,?,?,?,1,\'\',?)')
                    ->execute([$cUsername, password_hash($cPassword, PASSWORD_DEFAULT), $cEmail, $cAgentId, time()]);
                audit('user', 'create', 1, (int)$admin['id'], '手动添加用户：' . $cUsername . ($cAgentId > 0 ? '（代理商#' . $cAgentId . '）' : '（平台直营）'));
                flash_set('ok', '用户「' . $cUsername . '」已创建。');
                redirect('user_manage.php');
            }
        }
    }

    $stmt = db()->prepare('SELECT * FROM `user` WHERE `id`=?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user && $action !== 'create') {
        $error = '用户不存在。';
    } elseif ($action === 'toggle') {
        $to = (int)$user['status'] === 1 ? 2 : 1;
        db()->prepare('UPDATE `user` SET `status`=? WHERE `id`=?')->execute([$to, $id]);
        audit('user', $to === 1 ? 'enable' : 'disable', 1, (int)$admin['id'], '用户：' . $user['username']);
        flash_set('ok', $to === 1 ? '账号已启用。' : '账号已禁用，该用户将无法登录。');
        redirect('user_manage.php');
    } elseif ($action === 'reset_password') {
        $newPwd = (string)($_POST['new_password'] ?? '');
        if (strlen($newPwd) < 6 || strlen($newPwd) > 64) {
            $error = '新密码长度需在 6 ~ 64 位之间。';
        } else {
            db()->prepare('UPDATE `user` SET `password`=? WHERE `id`=?')
                ->execute([password_hash($newPwd, PASSWORD_DEFAULT), $id]);
            audit('user', 'reset_password', 1, (int)$admin['id'], '重置用户 ' . $user['username'] . ' 的密码');
            flash_set('ok', '密码已重置。');
            redirect('user_manage.php');
        }
    } elseif ($action === 'update') {
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $newAgentId = (int)($_POST['agent_id'] ?? 0);
        $oldAgentId = (int)$user['agent_id'];
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            $error = '账号需为 3 ~ 32 位字母、数字或下划线。';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '邮箱格式不正确。';
        } elseif ($phone !== '' && !preg_match('/^1[3-9]\d{9}$/', $phone)) {
            $error = '手机号格式不正确。';
        } elseif ($newAgentId !== $oldAgentId && $newAgentId > 0) {
            $stmt = db()->prepare('SELECT `id` FROM `agent` WHERE `id`=? AND `status`=1');
            $stmt->execute([$newAgentId]);
            if (!$stmt->fetch()) {
                $error = '所选代理商不存在或已禁用。';
            }
        }
        if ($error === '' && strcasecmp($username, (string)$user['username']) !== 0) {
            $stmt = db()->prepare('SELECT `id` FROM `user` WHERE `username`=? AND `id`<>?');
            $stmt->execute([$username, $id]);
            if ($stmt->fetch()) {
                $error = '该账号已被其他用户使用。';
            }
        }
        if ($error === '') {
            db()->prepare('UPDATE `user` SET `username`=?,`email`=?,`phone`=?,`agent_id`=? WHERE `id`=?')
                ->execute([$username, $email, $phone, $newAgentId, $id]);
            $detail = '编辑用户 ' . $user['username'] . ' 的资料';
            if ($username !== (string)$user['username']) {
                $detail .= '，账号由 ' . $user['username'] . ' 变更为 ' . $username;
            }
            if ($newAgentId !== $oldAgentId) {
                $detail .= '，上级代理商由 ' . agent_audit_label($oldAgentId) . ' 变更为 ' . agent_audit_label($newAgentId);
            }
            audit('user', 'update', 1, (int)$admin['id'], $detail);
            flash_set('ok', '用户资料已更新。');
            redirect('user_manage.php?act=edit&id=' . $id);
        }
        $act = 'edit';
    } elseif ($action === 'balance') {
        $btype  = (string)($_POST['btype'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $remark = trim((string)($_POST['remark'] ?? ''));
        if (!in_array($btype, ['add', 'sub'], true)) {
            $error = '请选择调整方式（增加 / 减少）。';
        } elseif ($amount <= 0 || $amount > 999999) {
            $error = '调整金额需大于 0 且不超过 999999 元。';
        } else {
            $delta = $btype === 'add' ? $amount : -$amount;
            try {
                db()->beginTransaction();
                $stmt = db()->prepare('SELECT `username`,`balance` FROM `user` WHERE `id`=? FOR UPDATE');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) {
                    throw new RuntimeException('用户不存在。');
                }
                $newBalance = (float)$row['balance'] + $delta;
                if ($newBalance < 0) {
                    throw new RuntimeException('调整后余额不能为负数。');
                }
                db()->prepare('UPDATE `user` SET `balance`=? WHERE `id`=?')
                    ->execute([number_format($newBalance, 2, '.', ''), $id]);
                db()->commit();
                audit('user', 'balance', 1, (int)$admin['id'],
                    '用户：' . $row['username'] . ' 调整 ' . ($delta > 0 ? '+' : '') . $delta . ' 元，调整后 ' . $newBalance . ' 元。备注：' . $remark);
                flash_set('ok', '余额已调整。');
                redirect('user_manage.php?act=edit&id=' . $id);
            } catch (Throwable $ex) {
                db()->rollBack();
                $error = $ex instanceof RuntimeException ? $ex->getMessage() : '余额调整失败。';
            }
        }
        $act = 'edit';
    } elseif ($action === 'impersonate') {
        if ((int)$user['status'] !== 1) {
            flash_set('error', '该用户已禁用，无法代登录。');
            redirect('user_manage.php');
        }
        $_SESSION['impersonate'] = ['type' => 'user', 'id' => $id, 'admin_name' => $admin['username'], 'back' => 'user_manage.php'];
        $_SESSION['user_id'] = $id;
        audit('user', 'impersonate', 1, (int)$admin['id'], '代登录用户：' . $user['username']);
        redirect('../user/dashboard.php');
    } elseif ($action === 'delete') {
        if ((float)$user['balance'] != 0.0) {
            flash_set('error', '用户「' . $user['username'] . '」余额不为 0，请先通过「余额与密码」调整为 0 后再删除。');
            redirect('user_manage.php?act=edit&id=' . $id);
        }
        db()->prepare('UPDATE `license` SET `user_id`=0 WHERE `user_id`=?')->execute([$id]);
        db()->prepare('DELETE FROM `user` WHERE `id`=?')->execute([$id]);
        audit('user', 'delete', 1, (int)$admin['id'], '删除用户：' . $user['username'] . '（邮箱：' . ((string)$user['email'] !== '' ? $user['email'] : '无') . '）；名下授权卡密已回收为未兑换状态');
        flash_set('ok', '用户「' . $user['username'] . '」已删除，名下授权卡密已回收为未兑换状态。');
        redirect('user_manage.php');
    }
}

$kw = trim((string)($_GET['kw'] ?? ''));
$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));

$where  = '';
$params = [];
if ($kw !== '') {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' (`u`.`username` LIKE ? OR `u`.`email` LIKE ?)';
    $params = array_merge($params, ['%' . $kw . '%', '%' . $kw . '%']);
}
$stmt = db()->prepare('SELECT COUNT(*) FROM `user` `u`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$offset = ($page - 1) * $perPage;
$params[] = $perPage;
$params[] = $offset;
$stmt = db()->prepare(
    'SELECT `u`.*, IFNULL(`a`.`username`, \'\') AS `agent_name` FROM `user` `u` '
    . 'LEFT JOIN `agent` `a` ON `a`.`id`=`u`.`agent_id`' . $where . ' ORDER BY `u`.`id` DESC LIMIT ? OFFSET ?'
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($act === 'edit') {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare(
        'SELECT `u`.*, IFNULL(`a`.`username`, \'\') AS `agent_name` FROM `user` `u` '
        . 'LEFT JOIN `agent` `a` ON `a`.`id`=`u`.`agent_id` WHERE `u`.`id`=?'
    );
    $stmt->execute([$eid]);
    $editUser = $stmt->fetch();
    if (!$editUser) {
        flash_set('error', '用户不存在。');
        redirect('user_manage.php');
    }
    // 下拉列出全部代理商（含禁用，标注后缀）；当前绑定的代理商（注册邀请码绑定或后台改绑）会自动选中
    $editAgentOptions = db()->query('SELECT `id`,`username`,`status` FROM `agent` ORDER BY `id` ASC')->fetchAll();
}

admin_header($act === 'edit' ? '用户详情' : '用户管理', 'user', $admin);
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

<?php 
if ($act === 'edit'):
    [$editRealName, $editRealTag] = real_status_info((int)$editUser['real_status']);
    $editMasked = (int)$editUser['real_status'] > 0 ? sec_id_decrypt((string)$editUser['id_card']) : '';
?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">用户：<?php echo e($editUser['username']); ?></h2>
            <p class="card-desc" style="margin-bottom:0">注册时间 <?php echo date('Y-m-d H:i', (int)$editUser['created_at']); ?><?php echo $editUser['agent_name'] !== '' ? ' · 所属代理商：' . e($editUser['agent_name']) : ' · 平台直营'; ?></p>
        </div>
        <div class="spacer"></div>
        <?php if ((int)$editUser['status'] === 1): ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="impersonate">
            <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
            <button class="btn-plain" type="submit" data-confirm="确认以用户 <?php echo e($editUser['username']); ?> 的身份登录其中心？操作将被记录。">代登录</button>
        </form>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
            <button class="btn-danger" type="submit" data-confirm="确认禁用该用户账号？禁用后无法登录。">禁用账号</button>
        </form>
        <?php else: ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
            <button class="btn-primary" type="submit">启用账号</button>
        </form>
        <?php endif; ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
            <button class="btn-danger" type="submit" data-confirm="确认删除用户 <?php echo e($editUser['username']); ?>？其名下授权卡密将回收为未兑换状态，订单与流水记录保留，删除后不可恢复。">删除账号</button>
        </form>
        <a class="btn-plain" href="user_manage.php">返回列表</a>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-label">账户余额</div><div class="stat-value"><?php echo e(number_format((float)$editUser['balance'], 2)); ?> 元</div></div>
        <div class="stat-card"><div class="stat-label">实名状态</div><div class="stat-value"><span class="tag <?php echo $editRealTag; ?>"><?php echo $editRealName; ?></span></div></div>
        <div class="stat-card"><div class="stat-label">账号状态</div><div class="stat-value"><span class="tag <?php echo (int)$editUser['status'] === 1 ? 'tag-ok' : 'tag-err'; ?>"><?php echo (int)$editUser['status'] === 1 ? '正常' : '已禁用'; ?></span></div></div>
    </div>

    <div class="table-wrap">
        <table class="kv-table">
            <tbody>
            <tr><th>邮箱</th><td><?php echo $editUser['email'] !== '' ? e($editUser['email']) : '未设置'; ?></td></tr>
            <tr><th>手机号</th><td><?php echo $editUser['phone'] !== '' ? e($editUser['phone']) : '未设置'; ?></td></tr>
            <tr><th>实名信息</th><td class="cell-sub"><?php echo $editMasked !== '' ? e($editUser['real_name']) . '（' . e($editMasked) . '）' : '未提交'; ?></td></tr>
            <tr><th>最后登录</th><td class="cell-sub"><?php echo (int)$editUser['last_login_time'] > 0 ? date('Y-m-d H:i', (int)$editUser['last_login_time']) . '（IP ' . ((string)$editUser['last_login_ip'] !== '' ? e($editUser['last_login_ip']) : '-') . '）' : '从未登录'; ?></td></tr>
            </tbody>
        </table>
    </div>
</section>

<div class="form-row">
<section class="card" style="flex:1;min-width:0">
    <h2 class="card-title">编辑资料</h2>
    <form method="post" class="form" style="max-width:none">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
        <div class="form-item">
            <label class="form-label">登录账号</label>
            <input class="form-input" type="text" name="username" value="<?php echo e($editUser['username']); ?>" maxlength="32" required>
            <p class="form-hint">3 ~ 32 位字母、数字或下划线；修改后该用户需使用新账号登录</p>
        </div>
        <div class="form-item">
            <label class="form-label">邮箱</label>
            <input class="form-input" type="email" name="email" value="<?php echo e($editUser['email']); ?>" maxlength="128" placeholder="用于接收通知（可选）">
        </div>
        <div class="form-item">
            <label class="form-label">手机号</label>
            <input class="form-input" type="text" name="phone" value="<?php echo e($editUser['phone']); ?>" maxlength="20" placeholder="11 位手机号（可选）">
        </div>
        <div class="form-item">
            <label class="form-label">所属代理商（上级）</label>
            <select class="form-input" name="agent_id">
                <option value="0" <?php echo (int)$editUser['agent_id'] === 0 ? 'selected' : ''; ?>>平台直营（无上级）</option>
                <?php foreach ($editAgentOptions as $ag): ?>
                <option value="<?php echo (int)$ag['id']; ?>" <?php echo (int)$editUser['agent_id'] === (int)$ag['id'] ? 'selected' : ''; ?>><?php echo e($ag['username']); ?><?php echo (int)$ag['status'] !== 1 ? '（已禁用）' : ''; ?></option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint">用户注册时通过邀请码绑定的上级会自动带入，此处可改绑或设为平台直营；保持不变则无需调整</p>
        </div>
        <button class="btn-primary" type="submit">保存修改</button>
    </form>
</section>

<section class="card" style="flex:1;min-width:0">
    <h2 class="card-title">余额与密码</h2>
    <style>
    .bal-now{display:flex;align-items:center;gap:8px;margin-bottom:14px;padding:10px 12px;background:var(--bg-page);border-radius:var(--radius);font-size:13px;color:var(--text-sub)}
    .bal-now b{font-size:18px;color:var(--text-main)}
    .bal-toggle{display:flex}
    .bal-opt{flex:0 0 auto;width:110px;height:36px;border:1px solid var(--border);background:var(--bg-card);cursor:pointer;font-size:13px;color:var(--text-sub);font-family:inherit}
    .bal-opt:first-child{border-radius:var(--radius) 0 0 var(--radius)}
    .bal-opt+.bal-opt{border-left:none;border-radius:0 var(--radius) var(--radius) 0}
    .bal-opt.on{background:var(--primary);border-color:var(--primary);color:#fff}
    .bal-quick{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
    .bal-chip{height:28px;padding:0 14px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);cursor:pointer;font-size:12px;color:var(--text-sub);font-family:inherit}
    .bal-chip:hover{border-color:var(--primary);color:var(--primary)}
    </style>
    <div class="bal-now">当前余额：<b>¥<?php echo e(number_format((float)$editUser['balance'], 2)); ?></b></div>
    <form method="post" class="form" style="max-width:none" data-confirm="确认调整该用户余额？">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="balance">
        <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
        <div class="form-item">
            <label class="form-label">调整方式</label>
            <div class="bal-toggle" id="balToggle">
                <button class="bal-opt on" type="button" data-v="add">＋ 增加余额</button>
                <button class="bal-opt" type="button" data-v="sub">－ 减少余额</button>
            </div>
        </div>
        <input type="hidden" name="btype" id="balType" value="add">
        <div class="form-item">
            <label class="form-label">调整金额（元）</label>
            <input class="form-input" type="number" name="amount" id="balAmount" step="0.01" min="0.01" max="999999" placeholder="请输入金额" required>
            <div class="bal-quick">
                <button class="bal-chip" type="button" data-v="10">10 元</button>
                <button class="bal-chip" type="button" data-v="50">50 元</button>
                <button class="bal-chip" type="button" data-v="100">100 元</button>
                <button class="bal-chip" type="button" data-v="500">500 元</button>
                <button class="bal-chip" type="button" data-v="1000">1000 元</button>
            </div>
        </div>
        <div class="form-item">
            <label class="form-label">调整备注</label>
            <input class="form-input" type="text" name="remark" maxlength="255" placeholder="充值凭证、扣减原因等（选填）">
        </div>
        <button class="btn-primary" type="submit">执行调整</button>
    </form>
    <script>
    (function () {
        var typeInput = document.getElementById('balType');
        var amount = document.getElementById('balAmount');
        var opts = document.querySelectorAll('#balToggle .bal-opt');
        opts.forEach(function (b) {
            b.addEventListener('click', function () {
                opts.forEach(function (x) { x.classList.remove('on'); });
                b.classList.add('on');
                typeInput.value = b.getAttribute('data-v');
            });
        });
        document.querySelectorAll('.bal-chip').forEach(function (c) {
            c.addEventListener('click', function () { amount.value = c.getAttribute('data-v'); });
        });
    })();
    </script>
    <div class="section-gap"></div>
    <form method="post" class="form" style="max-width:none">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
        <div class="form-item">
            <label class="form-label">重置登录密码</label>
            <input class="form-input" type="text" name="new_password" minlength="6" maxlength="64" placeholder="新密码（6 位以上）" required>
        </div>
        <button class="btn-plain" type="submit" data-confirm="确认重置该用户的登录密码？">重置密码</button>
    </form>
</section>
</div>

<?php 
else: ?>
<section class="card">
    <?php $agentOptions = db()->query('SELECT `id`,`username` FROM `agent` WHERE `status`=1 ORDER BY `id` ASC')->fetchAll(); ?>
    <div class="page-actions">
        <div>
            <h2 class="card-title">用户管理（共 <?php echo $total; ?> 个）</h2>
            <p class="card-desc" style="margin-bottom:0">含平台直营与代理商名下用户；实名认证的审核请前往「用户中心 → 实名认证审核」。</p>
        </div>
        <div class="spacer"></div>
        <div class="filter-bar" style="margin-bottom:0">
            <button class="btn-plain" type="button" onclick="var f=document.getElementById('userCreateBox');f.style.display=f.style.display==='none'?'block':'none'">添加用户</button>
            <a class="btn-plain" href="user_manage.php" style="text-decoration:none;line-height:32px">全部</a>
            <form method="get" style="display:flex">
                <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="按账号 / 邮箱搜索">
                <button class="btn-plain" type="submit">搜索</button>
            </form>
        </div>
    </div>

    <div id="userCreateBox" style="display:none;margin-top:14px;padding:18px;background:var(--bg-page);border-radius:var(--radius)">
        <h3 style="font-size:15px;margin-bottom:12px">添加用户<?php echo cfg('register_mode', '1') !== '1' ? '<span class="tag tag-warn" style="margin-left:8px;font-weight:400">当前已关闭自助注册</span>' : ''; ?></h3>
        <form method="post" class="form" style="max-width:none">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="form-row">
                <div class="form-item">
                    <label class="form-label">登录账号 <span class="req">*</span></label>
                    <input class="form-input" type="text" name="username" maxlength="32" placeholder="3 ~ 32 位字母、数字或下划线" required>
                </div>
                <div class="form-item">
                    <label class="form-label">初始密码 <span class="req">*</span></label>
                    <input class="form-input" type="text" name="password" minlength="6" maxlength="64" placeholder="6 ~ 64 位" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-item">
                    <label class="form-label">邮箱（可选）</label>
                    <input class="form-input" type="email" name="email" maxlength="128" placeholder="用于接收通知">
                </div>
                <div class="form-item">
                    <label class="form-label">所属代理商</label>
                    <select class="form-input" name="agent_id">
                        <option value="0">平台直营</option>
                        <?php foreach ($agentOptions as $ag): ?>
                        <option value="<?php echo (int)$ag['id']; ?>"><?php echo e($ag['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:8px">
                <button class="btn-primary" type="submit">确认添加</button>
                <button class="btn-plain" type="button" onclick="document.getElementById('userCreateBox').style.display='none'">取消</button>
            </div>
        </form>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-user.svg" alt="">
        <p>暂无用户<?php echo $kw !== '' ? '，或无匹配结果' : (cfg('register_mode', '1') === '1' ? '；用户可在用户中心（/user/）自行注册，或点击右上角「添加用户」手动创建。' : '；当前已关闭自助注册，请点击右上角「添加用户」手动创建。'); ?></p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>头像</th><th>账号</th><th>邮箱</th><th>实名</th><th>所属代理商</th><th>状态</th><th>注册时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                [$realName, $realTag] = real_status_info((int)$row['real_status']);
            ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-avatar"><?php echo avatar_img($row, 'list-avatar', (string)$row['username']); ?></td>
                <td class="cell-main"><?php echo e($row['username']); ?></td>
                <td class="cell-sub"><?php echo $row['email'] !== '' ? e($row['email']) : '-'; ?></td>
                <td><span class="tag <?php echo $realTag; ?>" title="实名审核请前往「用户中心 → 实名认证审核」"><?php echo $realName; ?></span></td>
                <td class="cell-sub"><?php echo $row['agent_name'] !== '' ? e($row['agent_name']) : '平台直营'; ?></td>
                <td><span class="tag <?php echo (int)$row['status'] === 1 ? 'tag-ok' : 'tag-err'; ?>"><?php echo (int)$row['status'] === 1 ? '正常' : '已禁用'; ?></span></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$row['created_at']); ?></td>
                <td style="white-space:normal">
                    <form method="post" style="display:inline-block">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-mini <?php echo (int)$row['status'] === 1 ? 'btn-danger-mini' : 'btn-mini'; ?>" type="submit"
                            <?php echo (int)$row['status'] === 1 ? 'data-confirm="确认禁用用户 ' . e($row['username']) . '？"' : ''; ?>>
                            <?php echo (int)$row['status'] === 1 ? '禁用' : '启用'; ?>
                        </button>
                    </form>
                    <form method="post" style="display:inline-block;margin-left:4px">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <input type="hidden" name="new_password" value="">
                        <button class="btn-mini-plain" type="submit"
                            data-confirm="确认重置该用户的登录密码？"
                            data-prompt="new_password" data-prompt-label="请输入新密码（6位以上）" data-prompt-min="6">重置密码</button>
                    </form>
                    <span style="display:inline-block;width:8px"></span>
                    <a class="btn-mini" href="user_manage.php?act=edit&id=<?php echo (int)$row['id']; ?>">编辑</a>
                    <?php if ((int)$row['status'] === 1): ?>
                    <form method="post" style="display:inline-block;margin-left:4px">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="impersonate">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-mini-plain" type="submit" data-confirm="确认以用户 <?php echo e($row['username']); ?> 的身份登录其中心？操作将被记录。">代登录</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, 'user_manage.php?kw=' . urlencode($kw) . '&'); ?>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
