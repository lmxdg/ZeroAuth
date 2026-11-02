<?php
// 管理员后台 - 代理商管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$act   = (string)($_GET['act'] ?? 'list');
$error = '';

function withdraw_status(int $s): string
{
    return [0 => '待审核', 1 => '已通过', 2 => '已驳回', 3 => '已打款'][$s] ?? '未知';
}
function withdraw_tag(int $s): string
{
    return [0 => 'tag-warn', 1 => 'tag-ok', 2 => 'tag-err', 3 => 'tag-info'][$s] ?? 'tag-off';
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $email    = trim((string)($_POST['email'] ?? ''));
        $discount = (float)($_POST['discount'] ?? 1);
        $balance  = (float)($_POST['balance'] ?? 0);
        [$pwOk, $pwMsg] = sec_password_check($password, $username);

        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            $error = '账号需为 3~32 位字母、数字或下划线。';
        } elseif (!$pwOk) {
            $error = $pwMsg;
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '邮箱格式不正确。';
        } elseif ($discount < 0.01 || $discount > 1) {
            $error = '代理折扣需在 0.01 ~ 1.00 之间（如 0.8 表示 8 折）。';
        } elseif ($balance < 0 || $balance > 99999999) {
            $error = '初始余额不合法。';
        } else {
            $stmt = db()->prepare('SELECT `id` FROM `agent` WHERE `username`=?');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = '该代理商账号已存在。';
            } else {
                $stmt = db()->prepare('INSERT INTO `agent` (`username`,`password`,`email`,`balance`,`discount`,`status`,`reg_ip`,`created_at`) VALUES (?,?,?,?,?,1,?,?)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email, number_format($balance, 2, '.', ''), number_format($discount, 2, '.', ''), client_ip(), time()]);
                audit('agent', 'create', 1, (int)$admin['id'], '开通代理商：' . $username . ' 折扣：' . $discount);
                flash_set('ok', '代理商「' . $username . '」开通成功。');
                redirect('agent_manage.php');
            }
        }
        $act = 'create';
    }

    if ($action === 'update') {
        $id       = (int)($_POST['id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $discount = (float)($_POST['discount'] ?? 1);
        $invite   = strtoupper(trim((string)($_POST['invite_code'] ?? '')));

        $stmt = db()->prepare('SELECT `username`,`invite_code` FROM `agent` WHERE `id`=?');
        $stmt->execute([$id]);
        $oldRow = $stmt->fetch();

        if ($id <= 0 || !$oldRow) {
            $error = '参数错误或代理商不存在。';
        } elseif (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            $error = '账号需为 3 ~ 32 位字母、数字或下划线。';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '邮箱格式不正确。';
        } elseif ($discount < 0.01 || $discount > 1) {
            $error = '代理折扣需在 0.01 ~ 1.00 之间。';
        } elseif ($invite !== '' && !preg_match('/^[A-Za-z0-9]{4,16}$/', $invite)) {
            $error = '邀请码需为 4 ~ 16 位字母或数字，保存后自动转为大写。';
        } else {
            if (strcasecmp($username, (string)$oldRow['username']) !== 0) {
                $stmt = db()->prepare('SELECT `id` FROM `agent` WHERE `username`=? AND `id`<>?');
                $stmt->execute([$username, $id]);
                if ($stmt->fetch()) {
                    $error = '该账号已被其他代理商使用。';
                }
            }
            if ($error === '' && $invite !== '' && strcasecmp($invite, (string)$oldRow['invite_code']) !== 0) {
                $stmt = db()->prepare('SELECT `id` FROM `agent` WHERE `invite_code`=? AND `id`<>?');
                $stmt->execute([$invite, $id]);
                if ($stmt->fetch()) {
                    $error = '该邀请码已被其他代理商使用。';
                }
            }
        }
        if ($error === '') {
            $stmt = db()->prepare('UPDATE `agent` SET `username`=?,`invite_code`=?,`email`=?,`discount`=? WHERE `id`=?');
            $stmt->execute([
                $username,
                $invite !== '' ? $invite : (string)$oldRow['invite_code'],
                $email,
                number_format($discount, 2, '.', ''),
                $id,
            ]);
            $detail = '代理商 #' . $id . '（' . $oldRow['username'] . '）资料已更新，折扣 ' . $discount;
            if ($username !== (string)$oldRow['username']) {
                $detail .= '，账号由 ' . $oldRow['username'] . ' 变更为 ' . $username;
            }
            if ($invite !== '' && strcasecmp($invite, (string)$oldRow['invite_code']) !== 0) {
                $detail .= '，邀请码由 ' . $oldRow['invite_code'] . ' 变更为 ' . $invite;
            }
            audit('agent', 'update', 1, (int)$admin['id'], $detail);
            flash_set('ok', '代理商资料已更新。');
            redirect('agent_manage.php?act=detail&id=' . $id);
        }
        $act = 'edit';
    }

    if ($action === 'balance') {
        $id     = (int)($_POST['id'] ?? 0);
        $btype  = (string)($_POST['btype'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $remark = trim((string)($_POST['remark'] ?? ''));

        if ($id <= 0) {
            $error = '参数错误。';
        } elseif (!in_array($btype, ['add', 'sub'], true)) {
            $error = '请选择调整方式（增加 / 减少）。';
        } elseif ($amount <= 0 || $amount > 999999) {
            $error = '调整金额需大于 0 且不超过 999999 元。';
        } else {
            $delta = $btype === 'add' ? $amount : -$amount;
            try {
                db()->beginTransaction();
                $stmt = db()->prepare('SELECT `username`,`balance` FROM `agent` WHERE `id`=? FOR UPDATE');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) {
                    throw new RuntimeException('代理商不存在。');
                }
                $newBalance = (float)$row['balance'] + $delta;
                if ($newBalance < 0) {
                    throw new RuntimeException('调整后余额不能为负数。');
                }
                db()->prepare('UPDATE `agent` SET `balance`=? WHERE `id`=?')
                    ->execute([number_format($newBalance, 2, '.', ''), $id]);
                db()->commit();
            } catch (Throwable $ex) {
                db()->rollBack();
                $error = $ex instanceof RuntimeException ? $ex->getMessage() : '余额调整失败。';
            }
            if ($error === '') {
                audit('agent', 'balance', 1, (int)$admin['id'],
                    '代理商：' . $row['username'] . ' 调整 ' . ($delta > 0 ? '+' : '') . $delta . ' 元，调整后 ' . $newBalance . ' 元。备注：' . $remark);
                flash_set('ok', '余额已调整。');
                redirect('agent_manage.php?act=detail&id=' . $id);
            }
        }
        $act = 'detail';
    }

    if ($action === 'reset_password' || $action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM `agent` WHERE `id`=?');
        $stmt->execute([$id]);
        $agent = $stmt->fetch();
        if (!$agent) {
            $error = '代理商不存在。';
        } elseif ($action === 'reset_password') {
            $newPwd = (string)($_POST['new_password'] ?? '');
            [$pwOk, $pwMsg] = sec_password_check($newPwd, (string)($agent['username'] ?? ''));
            if (!$pwOk) {
                $error = $pwMsg;
            } else {
                db()->prepare('UPDATE `agent` SET `password`=?,`login_fail`=0,`lock_time`=0 WHERE `id`=?')
                    ->execute([password_hash($newPwd, PASSWORD_DEFAULT), $id]);
                audit('agent', 'reset_password', 1, (int)$admin['id'], '重置代理商 ' . $agent['username'] . ' 的密码');
                flash_set('ok', '密码已重置。');
                redirect('agent_manage.php?act=detail&id=' . $id);
            }
        } else {
            $to = (int)$agent['status'] === 1 ? 2 : 1;
            db()->prepare('UPDATE `agent` SET `status`=? WHERE `id`=?')->execute([$to, $id]);
            audit('agent', $to === 1 ? 'enable' : 'disable', 1, (int)$admin['id'], '代理商：' . $agent['username']);
            flash_set('ok', $to === 1 ? '账号已启用。' : '账号已禁用，该代理商将无法登录。');
            redirect('agent_manage.php?act=detail&id=' . $id);
        }
        $act = 'detail';
    }

    if ($action === 'withdraw_review') {
        $wid    = (int)($_POST['wid'] ?? 0);
        $result = (string)($_POST['result'] ?? '');
        $remark = trim((string)($_POST['admin_remark'] ?? ''));

        $stmt = db()->prepare('SELECT * FROM `withdraw` WHERE `id`=? AND `status`=0');
        $stmt->execute([$wid]);
        $wd = $stmt->fetch();

        if (!$wd) {
            $error = '提现申请不存在或已审核。';
        } elseif (!in_array($result, ['pass', 'reject'], true)) {
            $error = '审核操作不合法。';
        } elseif ($result === 'reject' && ($remark === '' || mb_strlen($remark) > 200)) {
            $error = '驳回必须填写理由（200 字以内）。';
        } else {
            try {
                db()->beginTransaction();
                if ($result === 'pass') {
                    $stmt = db()->prepare('SELECT `balance` FROM `agent` WHERE `id`=? FOR UPDATE');
                    $stmt->execute([(int)$wd['agent_id']]);
                    $agentRow = $stmt->fetch();
                    if (!$agentRow) {
                        throw new RuntimeException('代理商不存在。');
                    }
                    if ((float)$agentRow['balance'] < (float)$wd['amount']) {
                        throw new RuntimeException('该代理商余额不足，无法通过该提现申请。');
                    }
                    db()->prepare('UPDATE `agent` SET `balance`=`balance`-? WHERE `id`=?')
                        ->execute([number_format((float)$wd['amount'], 2, '.', ''), (int)$wd['agent_id']]);
                    $to = 1;
                } else {
                    $to = 2;
                }
                db()->prepare('UPDATE `withdraw` SET `status`=?,`admin_remark`=?,`admin_id`=?,`review_time`=? WHERE `id`=?')
                    ->execute([$to, $remark, (int)$admin['id'], time(), $wid]);
                db()->commit();
            } catch (Throwable $ex) {
                db()->rollBack();
                $error = $ex instanceof RuntimeException ? $ex->getMessage() : '审核操作失败。';
            }
            if ($error === '') {
                audit('withdraw', 'review_' . $result, 1, (int)$admin['id'],
                    '提现单 #' . $wid . ' 金额 ' . $wd['amount'] . ' 审核结果：' . ($result === 'pass' ? '通过（已扣减余额）' : '驳回，理由：' . $remark));
                $stmt = db()->prepare('SELECT `username`,`email` FROM `agent` WHERE `id`=?');
                $stmt->execute([(int)$wd['agent_id']]);
                $wdOwner = $stmt->fetch();
                if ($wdOwner && (string)$wdOwner['email'] !== '') {
                    require_once ROOT_PATH . '/core/Mail.php';
                    mail_scene_send('withdraw', (string)$wdOwner['email'], [
                        'userName' => (string)$wdOwner['username'],
                        'amount'   => (string)$wd['amount'] . ' 元',
                        'account'  => (string)$wd['account'],
                        'result'   => $result === 'pass' ? '已通过' : '已驳回',
                        'reason'   => $remark !== '' ? $remark : '无',
                        'time'     => date('Y-m-d H:i'),
                    ]);
                }
                flash_set('ok', $result === 'pass' ? '提现已通过，余额已扣减。' : '提现申请已驳回，理由已通知代理商。');
                redirect('agent_manage.php?act=detail&id=' . (int)$wd['agent_id']);
            }
        }
        $act = 'detail';
    }
    if ($action === 'impersonate') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM `agent` WHERE `id`=?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            flash_set('error', '代理商不存在。');
        } elseif ((int)$target['status'] !== 1) {
            flash_set('error', '该代理商已禁用，无法代登录。');
        } else {
            $_SESSION['impersonate'] = ['type' => 'agent', 'id' => $id, 'admin_name' => $admin['username'], 'back' => 'agent_manage.php'];
            $_SESSION['agent_id'] = $id;
            audit('agent', 'impersonate', 1, (int)$admin['id'], '代登录代理商：' . $target['username']);
            redirect('../agent/dashboard.php');
        }
        redirect('agent_manage.php' . ($id > 0 ? '?act=detail&id=' . $id : ''));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM `agent` WHERE `id`=?');
        $stmt->execute([$id]);
        $delAgent = $stmt->fetch();
        if (!$delAgent) {
            flash_set('error', '代理商不存在。');
            redirect('agent_manage.php');
        }
        if ((float)$delAgent['balance'] != 0.0) {
            flash_set('error', '代理商「' . $delAgent['username'] . '」余额不为 0，请先通过「余额与密码」清零后再删除。');
            redirect('agent_manage.php?act=detail&id=' . $id);
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM `withdraw` WHERE `agent_id`=? AND `status`=0');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            flash_set('error', '代理商「' . $delAgent['username'] . '」存在待审核的提现申请，请先审核处理后再删除。');
            redirect('agent_manage.php?act=detail&id=' . $id);
        }
        db()->prepare('UPDATE `user` SET `agent_id`=0 WHERE `agent_id`=?')->execute([$id]);
        db()->prepare('UPDATE `license` SET `owner_type`=1,`owner_id`=0 WHERE `owner_type`=2 AND `owner_id`=?')->execute([$id]);
        db()->prepare('DELETE FROM `agent` WHERE `id`=?')->execute([$id]);
        audit('agent', 'delete', 1, (int)$admin['id'], '删除代理商：' . $delAgent['username'] . '（邮箱：' . ((string)$delAgent['email'] !== '' ? $delAgent['email'] : '无') . '）；名下用户转为平台直营，名下卡密转为管理员持有');
        flash_set('ok', '代理商「' . $delAgent['username'] . '」已删除，名下用户转为平台直营，名下卡密转为管理员持有。');
        redirect('agent_manage.php');
    }
}

$kw = trim((string)($_GET['kw'] ?? ''));
$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));

if ($act === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM `agent` WHERE `id`=?');
    $stmt->execute([$id]);
    $agent = $stmt->fetch();
    if (!$agent) {
        flash_set('err', '代理商不存在。');
        redirect('agent_manage.php');
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM `license` WHERE `owner_type`=2 AND `owner_id`=?');
    $stmt->execute([$id]);
    $licCount = (int)$stmt->fetchColumn();
    $stmt = db()->prepare('SELECT COUNT(*) FROM `user` WHERE `agent_id`=?');
    $stmt->execute([$id]);
    $userCount = (int)$stmt->fetchColumn();
    $stmt = db()->prepare('SELECT COUNT(*) FROM `withdraw` WHERE `agent_id`=? AND `status`=0');
    $stmt->execute([$id]);
    $wdPending = (int)$stmt->fetchColumn();

    $stmt = db()->prepare('SELECT * FROM `withdraw` WHERE `agent_id`=? ORDER BY `id` DESC LIMIT 50');
    $stmt->execute([$id]);
    $withdraws = $stmt->fetchAll();
}

admin_header($act === 'detail' ? '代理商详情' : ($act === 'create' ? '开通代理商' : '代理商管理'), 'agent', $admin);
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
if ($act === 'detail'):
?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">代理商：<?php echo e($agent['username']); ?></h2>
            <p class="card-desc" style="margin-bottom:0">注册时间 <?php echo date('Y-m-d H:i', (int)$agent['created_at']); ?></p>
        </div>
        <div class="spacer"></div>
        <?php if ((int)$agent['status'] === 1): ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="impersonate">
            <input type="hidden" name="id" value="<?php echo (int)$agent['id']; ?>">
            <button class="btn-plain" type="submit" data-confirm="确认以代理商 <?php echo e($agent['username']); ?> 的身份登录其中心？操作将被记录。">代登录</button>
        </form>
        <?php endif; ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?php echo (int)$agent['id']; ?>">
            <button class="<?php echo (int)$agent['status'] === 1 ? 'btn-danger' : 'btn-plain'; ?>" type="submit"
                <?php echo (int)$agent['status'] === 1 ? 'data-confirm="确认禁用该代理商账号？禁用后无法登录。"' : ''; ?>>
                <?php echo (int)$agent['status'] === 1 ? '禁用账号' : '启用账号'; ?>
            </button>
        </form>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo (int)$agent['id']; ?>">
            <button class="btn-danger" type="submit" data-confirm="确认删除代理商 <?php echo e($agent['username']); ?>？名下用户将转为平台直营，名下卡密转为管理员持有，订单与提现记录保留，删除后不可恢复。">删除账号</button>
        </form>
        <a class="btn-primary" href="agent_manage.php">返回列表</a>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-label">账户余额</div><div class="stat-value"><?php echo e(number_format((float)$agent['balance'], 2)); ?> 元</div></div>
        <div class="stat-card"><div class="stat-label">独立折扣</div><div class="stat-value"><?php echo e((string)$agent['discount']); ?></div></div>
        <div class="stat-card"><div class="stat-label">名下卡密</div><div class="stat-value"><?php echo $licCount; ?></div></div>
        <div class="stat-card"><div class="stat-label">名下用户 / 待审提现</div><div class="stat-value"><?php echo $userCount; ?> / <?php echo $wdPending; ?></div></div>
    </div>

    <div class="table-wrap">
        <table class="kv-table">
            <tbody>
            <tr><th>账号状态</th><td><span class="tag <?php echo (int)$agent['status'] === 1 ? 'tag-ok' : 'tag-err'; ?>"><?php echo (int)$agent['status'] === 1 ? '正常' : '已禁用'; ?></span></td></tr>
            <tr><th>邮箱</th><td><?php echo $agent['email'] !== '' ? e($agent['email']) : '未设置'; ?></td></tr>
            <tr><th>注册 IP</th><td class="cell-sub">
                <?php $agentRegIp = (string)($agent['reg_ip'] ?? ''); ?>
                <?php if ($agentRegIp !== ''): ?><span class="cell-code"><?php echo e($agentRegIp); ?></span>&nbsp;<?php echo ip_geo_badge($agentRegIp); ?><?php else: ?>未记录<?php endif; ?>
            </td></tr>
            <tr><th>最后登录</th><td class="cell-sub"><?php echo (int)$agent['last_login_time'] > 0 ? date('Y-m-d H:i', (int)$agent['last_login_time']) : '从未登录'; ?></td></tr>
            <tr><th>最后登录 IP</th><td class="cell-sub">
                <?php $agentLoginIp = (string)($agent['last_login_ip'] ?? ''); ?>
                <?php if ($agentLoginIp !== ''): ?><span class="cell-code"><?php echo e($agentLoginIp); ?></span>&nbsp;<?php echo ip_geo_badge($agentLoginIp); ?><?php else: ?>未记录<?php endif; ?>
            </td></tr>
            <tr><th>登录锁定</th><td class="cell-sub"><?php echo (int)$agent['lock_time'] > time() ? '锁定至 ' . date('Y-m-d H:i', (int)$agent['lock_time']) : '无'; ?></td></tr>
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
        <input type="hidden" name="id" value="<?php echo (int)$agent['id']; ?>">
        <div class="form-item">
            <label class="form-label">登录账号</label>
            <input class="form-input" type="text" name="username" value="<?php echo e($agent['username']); ?>" maxlength="32" required>
            <div class="form-hint">3 ~ 32 位字母、数字或下划线；修改后该代理商需使用新账号登录</div>
        </div>
        <div class="form-item">
            <label class="form-label">邮箱</label>
            <input class="form-input" type="email" name="email" value="<?php echo e($agent['email']); ?>" placeholder="用于接收通知（可选）">
        </div>
        <div class="form-item">
            <label class="form-label">独立折扣</label>
            <input class="form-input" type="number" name="discount" min="0.01" max="1" step="0.01" value="<?php echo e((string)$agent['discount']); ?>" required>
            <div class="form-hint">0.8 表示 8 折；代理商开通授权时按此折扣结算（已开通等级的按等级折扣）。</div>
        </div>
        <div class="form-item">
            <label class="form-label">邀请码（特殊权限）</label>
            <input class="form-input" type="text" name="invite_code" value="<?php echo e((string)$agent['invite_code']); ?>" maxlength="16" placeholder="留空保持不变">
            <div class="form-hint">4 ~ 16 位字母或数字（自动转大写）；修改后原邀请码立即失效，已绑定用户不受影响</div>
        </div>
        <button class="btn-primary" type="submit" data-confirm="确认保存修改？账号或邀请码变更后立即生效。">保存修改</button>
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
    <div class="bal-now">当前余额：<b>¥<?php echo e(number_format((float)$agent['balance'], 2)); ?></b></div>
    <form method="post" class="form" style="max-width:none" data-confirm="确认调整该代理商余额？">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="balance">
        <input type="hidden" name="id" value="<?php echo (int)$agent['id']; ?>">
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
        <input type="hidden" name="id" value="<?php echo (int)$agent['id']; ?>">
        <div class="form-item">
            <label class="form-label">重置登录密码</label>
            <input class="form-input" type="text" name="new_password" minlength="8" maxlength="64" placeholder="新密码（8 位以上，含字母/数字/符号两类）" required>
        </div>
        <button class="btn-plain" type="submit" data-confirm="确认重置该代理商的登录密码？">重置密码</button>
    </form>
</section>
</div>

<section class="card">
    <h2 class="card-title">提现记录</h2>
    <?php if (!$withdraws): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-money.svg" alt="">
        <p>该代理商暂无提现申请。</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>#</th><th>金额</th><th>收款账号</th><th>状态</th><th>申请时间</th><th>审核信息</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($withdraws as $wd): ?>
            <tr>
                <td class="cell-code"><?php echo (int)$wd['id']; ?></td>
                <td class="cell-main"><?php echo e(number_format((float)$wd['amount'], 2)); ?> 元</td>
                <td class="cell-sub"><?php echo e($wd['account']); ?></td>
                <td><span class="tag <?php echo withdraw_tag((int)$wd['status']); ?>"><?php echo withdraw_status((int)$wd['status']); ?></span></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$wd['created_at']); ?></td>
                <td class="cell-sub">
                    <?php if ((int)$wd['status'] === 0): ?>
                        待审核
                    <?php else: ?>
                        <?php echo e($wd['admin_remark'] !== '' ? $wd['admin_remark'] : '无备注'); ?>
                        <br><?php echo (int)$wd['review_time'] > 0 ? date('Y-m-d H:i', (int)$wd['review_time']) : ''; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int)$wd['status'] === 0): ?>
                    <form method="post" style="display:flex;gap:6px">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="withdraw_review">
                        <input type="hidden" name="wid" value="<?php echo (int)$wd['id']; ?>">
                        <input type="hidden" name="result" value="pass">
                        <input type="hidden" name="admin_remark" value="">
                        <button class="btn-mini" type="submit" data-confirm="确认通过该提现申请？通过后将扣减代理商余额 <?php echo e(number_format((float)$wd['amount'], 2)); ?> 元。">通过</button>
                    </form>
                    <form method="post" style="margin-top:4px">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="withdraw_review">
                        <input type="hidden" name="wid" value="<?php echo (int)$wd['id']; ?>">
                        <input type="hidden" name="result" value="reject">
                        <input type="hidden" name="admin_remark" value="不符合提现条件">
                        <button class="btn-danger-mini" type="submit" data-confirm="确认驳回该提现申请？">驳回</button>
                    </form>
                    <?php else: ?>
                    <span class="cell-sub">已处理</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php 
elseif ($act === 'create'):
?>
<section class="card">
    <h2 class="card-title">开通代理商</h2>
    <p class="card-desc">代理商由管理员开通；开通后代理商使用账号密码登录代理商中心（/agent/），数据与其他代理商完全隔离。</p>
    <form method="post" class="form" style="max-width:560px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">登录账号</label>
                <input class="form-input" type="text" name="username" minlength="3" maxlength="32" placeholder="字母、数字或下划线" required>
            </div>
            <div class="form-item">
                <label class="form-label">登录密码</label>
                <input class="form-input" type="text" name="password" minlength="8" maxlength="64" placeholder="8 位以上，含字母/数字/符号两类" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">邮箱（可选）</label>
                <input class="form-input" type="email" name="email" maxlength="128" placeholder="agent@example.com">
            </div>
            <div class="form-item">
                <label class="form-label">独立折扣</label>
                <input class="form-input" type="number" name="discount" min="0.01" max="1" step="0.01" value="1.00" required>
                <div class="form-hint">0.8 表示 8 折。</div>
            </div>
        </div>
        <div class="form-item">
            <label class="form-label">初始余额（元）</label>
            <input class="form-input" type="number" name="balance" min="0" max="99999999" step="0.01" value="0">
        </div>
        <div class="page-actions">
            <a class="btn-plain" href="agent_manage.php">返回列表</a>
            <button class="btn-primary" type="submit">确认开通</button>
        </div>
    </form>
</section>

<?php 
else:
    $where  = '';
    $params = [];
    if ($kw !== '') {
        $where = ' WHERE `username` LIKE ?';
        $params[] = '%' . $kw . '%';
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM `agent`' . $where);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = db()->prepare('SELECT * FROM `agent`' . $where . ' ORDER BY `id` DESC LIMIT ? OFFSET ?');
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">代理商列表（共 <?php echo $total; ?> 个）</h2>
            <p class="card-desc" style="margin-bottom:0">数据相互隔离：代理商仅能查看自己名下的卡密、用户与订单。</p>
        </div>
        <div class="spacer"></div>
        <form method="get" class="filter-bar" style="margin-bottom:0">
            <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="按账号搜索">
            <button class="btn-plain" type="submit">搜索</button>
        </form>
        <a class="btn-primary" href="agent_manage.php?act=create">开通代理商</a>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-agent.svg" alt="">
        <p>暂无代理商<?php echo $kw !== '' ? '，或无匹配结果' : '，点击右上角「开通代理商」创建第一个'; ?>。</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>头像</th><th>账号</th><th>余额</th><th>折扣</th><th>状态</th><th>邮箱</th><th>最后登录</th><th>IP 地址</th><th>IP 归属地</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-avatar"><?php echo avatar_img($row, 'list-avatar', (string)$row['username']); ?></td>
                <td class="cell-main"><?php echo e($row['username']); ?></td>
                <td class="cell-main"><?php echo e(number_format((float)$row['balance'], 2)); ?> 元</td>
                <td class="cell-sub"><?php echo e((string)$row['discount']); ?></td>
                <td><span class="tag <?php echo (int)$row['status'] === 1 ? 'tag-ok' : 'tag-err'; ?>"><?php echo (int)$row['status'] === 1 ? '正常' : '已禁用'; ?></span></td>
                <td class="cell-sub"><?php echo $row['email'] !== '' ? e($row['email']) : '-'; ?></td>
                <td class="cell-sub"><?php echo (int)$row['last_login_time'] > 0 ? date('Y-m-d H:i', (int)$row['last_login_time']) : '从未登录'; ?></td>
                <?php
                $agentLastIp = (string)($row['last_login_ip'] ?? '');
                $agentRegIpRow = (string)($row['reg_ip'] ?? '');
                $agentShowIp = $agentLastIp !== '' ? $agentLastIp : $agentRegIpRow;
                ?>
                <td class="cell-code"><?php if ($agentShowIp !== ''): ?><?php echo e($agentShowIp); ?><?php if ($agentLastIp === ''): ?> <span class="cell-sub" style="font-size:11px">注册</span><?php endif; ?><?php else: ?><span class="cell-sub">-</span><?php endif; ?></td>
                <td><?php echo $agentShowIp !== '' ? ip_geo_badge($agentShowIp) : '<span class="cell-sub">-</span>'; ?></td>
                <td><a class="btn-mini" href="agent_manage.php?act=detail&id=<?php echo (int)$row['id']; ?>">详情</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, 'agent_manage.php?kw=' . urlencode($kw) . '&'); ?>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
