<?php
// 用户中心 - 财务流水
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Pay.php';
require dirname(__DIR__) . '/core/layout_user.php';

$user = user_require_login();
$uid  = (int)$user['id'];
$feeRate = min(50, max(0, (float)cfg('withdraw_fee_rate', '0')));

function uf_status_name(int $s): string
{
    return [0 => '待审核', 1 => '已通过', 2 => '已驳回', 3 => '已打款'][$s] ?? '未知';
}
function uf_status_tag(int $s): string
{
    return [0 => 'tag-warn', 1 => 'tag-ok', 2 => 'tag-err', 3 => 'tag-info'][$s] ?? 'tag-gray';
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'recharge') {
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        try {
            $order = pay_create_recharge('user', $uid, $amount);
            audit('pay', 'recharge_create', 3, $uid, '充值订单：' . $order['order_no'] . ' 金额：' . $order['amount']);
            redirect('pay.php?order_no=' . $order['order_no']);
        } catch (RuntimeException $ex) {
            flash_set('err', $ex->getMessage());
            redirect('finance.php');
        }
    }

    if ($action === 'apply') {
        $amount  = round((float)($_POST['amount'] ?? 0), 2);
        $account = trim((string)($_POST['account'] ?? ''));
        $remark  = trim((string)($_POST['apply_remark'] ?? ''));
        $fee     = round($amount * $feeRate / 100, 2);

        if ($amount < 1 || $amount > 999999) {
            flash_set('err', '提现金额需在 1 ~ 999999 元之间。');
        } elseif ($account === '' || mb_strlen($account) > 128) {
            flash_set('err', '请填写收款账号（支付宝 / 银行卡等）。');
        } else {
            $stmt = db()->prepare('SELECT `balance` FROM `user` WHERE `id`=?');
            $stmt->execute([$uid]);
            $balance = (float)$stmt->fetchColumn();
            if ($balance < $amount) {
                flash_set('err', '余额不足，当前余额 ' . number_format($balance, 2) . ' 元。');
            } else {
                $stmt = db()->prepare('SELECT COUNT(*) FROM `withdraw` WHERE `user_id`=? AND `status`=0');
                $stmt->execute([$uid]);
                if ((int)$stmt->fetchColumn() > 0) {
                    flash_set('err', '您已有待审核的提现申请，请等待管理员处理。');
                } else {
                    $stmt = db()->prepare('INSERT INTO `withdraw` (`user_id`,`amount`,`fee`,`fee_rate`,`status`,`account`,`agent_remark`,`created_at`) VALUES (?,?,?,?,0,?,?,?)');
                    $stmt->execute([$uid, number_format($amount, 2, '.', ''), number_format($fee, 2, '.', ''), $feeRate, $account, $remark, time()]);
                    audit('withdraw', 'user_apply', 3, $uid, '申请提现 ' . $amount . ' 元（手续费 ' . $fee . ' 元）');
                    flash_set('ok', '提现申请已提交，等待管理员审核。');
                }
            }
        }
        redirect('finance.php');
    }
}

$stmt = db()->prepare("SELECT
    COALESCE(SUM(CASE WHEN `type`=2 THEN `amount` ELSE 0 END),0) AS recharge_sum,
    COALESCE(SUM(CASE WHEN `type`=1 THEN `amount` ELSE 0 END),0) AS consume_sum
    FROM `finance_flow` WHERE `user_type`=1 AND `user_id`=?");
$stmt->execute([$uid]);
$sum = $stmt->fetch() ?: ['recharge_sum' => 0, 'consume_sum' => 0];

$typeFilter = (int)($_GET['type'] ?? 0);
$where = '`user_type`=1 AND `user_id`=?';
$params = [$uid];
if (in_array($typeFilter, [1, 2, 3, 4], true)) {
    $where .= ' AND `type`=?';
    $params[] = $typeFilter;
}
$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$stmt = db()->prepare("SELECT COUNT(*) FROM `finance_flow` WHERE $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$stmt = db()->prepare("SELECT * FROM `finance_flow` WHERE $where ORDER BY `id` DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$perPage, ($page - 1) * $perPage]));
$flows = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM `withdraw` WHERE `user_id`=? ORDER BY `id` DESC LIMIT 10');
$stmt->execute([$uid]);
$withdraws = $stmt->fetchAll();

user_header('我的财务', 'finance', $user);
?>

<style>

.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
.stat-card{background:var(--card);border:1px solid var(--bd);border-radius:var(--r);padding:18px 20px}
.stat-label{font-size:13px;color:var(--t3)}
.stat-num{font-size:24px;font-weight:600;color:var(--t1);margin-top:8px;font-family:Consolas,Monaco,monospace}
.stat-num.green{color:var(--ok)}
.stat-num.blue{color:var(--p)}
.amount-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.amt-chip{border:1px solid var(--bd);border-radius:var(--r);background:var(--card);padding:8px 22px;font-size:15px;font-weight:600;color:var(--t1);cursor:pointer;font-family:Consolas,Monaco,monospace;transition:border-color .15s,color .15s}
.amt-chip:hover{border-color:var(--p);color:var(--p)}
.amt-chip.on{border-color:var(--p);background:var(--p-bg);color:var(--p)}
.recharge-input{width:160px;height:34px;padding:0 12px;border:1px solid var(--bd);border-radius:var(--r);outline:none}
.recharge-input:focus{border-color:var(--p)}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.filter-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.filter-tabs a{display:inline-flex;align-items:center;height:30px;padding:0 14px;border:1px solid var(--bd);border-radius:var(--r);font-size:13px;color:var(--t2);background:var(--card)}
.filter-tabs a:hover{color:var(--p);border-color:var(--p)}
.filter-tabs a.on{background:var(--p);border-color:var(--p);color:#FFF}
.flow-in{color:var(--ok);font-weight:600;font-family:Consolas,Monaco,monospace}
.flow-out{color:var(--t1);font-weight:600;font-family:Consolas,Monaco,monospace}
@media (max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr)}.grid-2{grid-template-columns:1fr}}
</style>

<?php $flash = flash_take(); if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">账户余额（元）</div>
        <div class="stat-num green">¥<?php echo e(number_format((float)$user['balance'], 2)); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">累计充值（元）</div>
        <div class="stat-num">¥<?php echo e(number_format((float)$sum['recharge_sum'], 2)); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">累计消费（元）</div>
        <div class="stat-num blue">¥<?php echo e(number_format((float)$sum['consume_sum'], 2)); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">可提现余额（元）</div>
        <div class="stat-num">¥<?php echo e(number_format((float)$user['balance'], 2)); ?></div>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <h2 class="card-title">快捷充值</h2>
        <form method="post" id="rechargeForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="recharge">
            <div class="amount-row">
                <?php foreach ([100, 300, 500, 1000] as $amt): ?>
                <button type="button" class="amt-chip" data-amt="<?php echo $amt; ?>">¥<?php echo $amt; ?></button>
                <?php endforeach; ?>
                <input class="recharge-input" type="number" name="amount" id="amtInput" placeholder="自定义金额" min="1" max="50000" step="0.01" required>
            </div>
            <div class="form-hint" style="margin-bottom:14px">单笔充值 1 ~ 50000 元；支付成功后余额实时到账。</div>
            <button class="btn-p" type="submit">立即充值</button>
        </form>
    </section>

    <section class="card">
        <h2 class="card-title">余额提现</h2>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="apply">
            <div class="form-item">
                <label class="form-label">提现金额（元）</label>
                <input class="form-input" type="number" name="amount" min="1" max="999999" step="0.01" placeholder="最低 1 元" required>
            </div>
            <div class="form-item">
                <label class="form-label">收款账号</label>
                <input class="form-input" type="text" name="account" maxlength="128" placeholder="支付宝 / 银行卡等" required>
            </div>
            <div class="form-item">
                <label class="form-label">备注（可选）</label>
                <input class="form-input" type="text" name="apply_remark" maxlength="255" placeholder="收款人姓名等">
            </div>
            <div class="form-hint" style="margin-bottom:14px">同一时间仅一笔待审核申请；审核通过后由管理员打款，手续费费率 <?php echo e((string)$feeRate); ?>%。</div>
            <button class="btn-p" type="submit" data-confirm="确认提交提现申请？提交后需等待管理员审核。">提交申请</button>
        </form>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <h2 class="card-title">充值与消费明细</h2>
    <div class="filter-tabs">
        <a class="<?php echo $typeFilter === 0 ? 'on' : ''; ?>" href="finance.php?type=0">全部</a>
        <a class="<?php echo $typeFilter === 2 ? 'on' : ''; ?>" href="finance.php?type=2">余额充值</a>
        <a class="<?php echo $typeFilter === 1 ? 'on' : ''; ?>" href="finance.php?type=1">购买授权</a>
        <a class="<?php echo $typeFilter === 3 ? 'on' : ''; ?>" href="finance.php?type=3">开通等级</a>
        <a class="<?php echo $typeFilter === 4 ? 'on' : ''; ?>" href="finance.php?type=4">升级等级</a>
    </div>
    <?php if (!$flows): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无流水记录。</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>时间</th><th>类型</th><th>金额（元）</th><th>变动后余额</th><th>备注</th></tr></thead>
            <tbody>
            <?php foreach ($flows as $f): $in = (int)$f['type'] === 2; ?>
            <tr>
                <td class="cell-code"><?php echo e(date('Y-m-d H:i', (int)$f['created_at'])); ?></td>
                <td><span class="tag <?php echo $in ? 'tag-ok' : 'tag-info'; ?>"><?php echo e(finance_type_name((int)$f['type'])); ?></span></td>
                <td class="<?php echo $in ? 'flow-in' : 'flow-out'; ?>"><?php echo $in ? '+' : '-'; ?><?php echo e(number_format((float)$f['amount'], 2)); ?></td>
                <td><?php echo e(number_format((float)$f['balance_after'], 2)); ?></td>
                <td><?php echo $f['remark'] !== '' ? e($f['remark']) : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, 'finance.php?type=' . $typeFilter . '&'); ?>
    <?php endif; ?>
</section>

<?php if ($withdraws): ?>
<section class="card">
    <h2 class="card-title">提现记录（最近 10 条）</h2>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>金额</th><th>手续费</th><th>实际到账</th><th>收款账号</th><th>状态</th><th>申请时间</th><th>审核备注</th></tr></thead>
            <tbody>
            <?php foreach ($withdraws as $wd): $wdFee = (float)$wd['fee']; ?>
            <tr>
                <td><?php echo e(number_format((float)$wd['amount'], 2)); ?></td>
                <td><?php echo $wdFee > 0 ? e(number_format($wdFee, 2)) : '免费'; ?></td>
                <td><?php echo e(number_format(max(0, (float)$wd['amount'] - $wdFee), 2)); ?></td>
                <td class="cell-code"><?php echo e($wd['account']); ?></td>
                <td><span class="tag <?php echo uf_status_tag((int)$wd['status']); ?>"><?php echo uf_status_name((int)$wd['status']); ?></span></td>
                <td class="cell-code"><?php echo e(date('Y-m-d H:i', (int)$wd['created_at'])); ?></td>
                <td><?php echo $wd['admin_remark'] !== '' ? e($wd['admin_remark']) : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<script>

(function () {
    var input = document.getElementById('amtInput');
    document.querySelectorAll('.amt-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            document.querySelectorAll('.amt-chip').forEach(function (c) { c.classList.remove('on'); });
            chip.classList.add('on');
            input.value = chip.getAttribute('data-amt');
        });
    });
    input.addEventListener('input', function () {
        document.querySelectorAll('.amt-chip').forEach(function (c) {
            c.classList.toggle('on', c.getAttribute('data-amt') === input.value);
        });
    });
})();
</script>
<?php user_footer(); ?>
