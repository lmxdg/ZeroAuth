<?php
// 用户中心 - 收银台
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Pay.php';
require dirname(__DIR__) . '/core/layout_user.php';

$user = user_require_login();

if (is_post()) {
    csrf_check();
    $orderNoPost = trim((string)($_POST['order_no'] ?? ''));
    $stmt = db()->prepare('SELECT * FROM `order` WHERE `order_no`=? AND `user_id`=? LIMIT 1');
    $stmt->execute([$orderNoPost, (int)$user['id']]);
    $orderPost = $stmt->fetch();
    if (!$orderPost) {
        flash_set('err', '订单不存在。');
        redirect('orders.php');
    }
    if ((int)$orderPost['pay_status'] !== 0) {
        redirect('pay.php?order_no=' . $orderNoPost);
    }
    $paidRes = pay_mark_paid($orderNoPost, 'balance', '');
    flash_set($paidRes['ok'] ? 'ok' : 'err', $paidRes['msg']);
    redirect('pay.php?order_no=' . $orderNoPost);
}

$orderNo = trim((string)($_GET['order_no'] ?? ''));
$channel = (string)($_GET['channel'] ?? '');

$stmt = db()->prepare('SELECT * FROM `order` WHERE `order_no`=? AND `user_id`=? LIMIT 1');
$stmt->execute([$orderNo, (int)$user['id']]);
$order = $stmt->fetch();
if (!$order) {
    flash_set('err', '订单不存在。');
    redirect('orders.php');
}

$statusMap = [0 => '未支付', 1 => '已支付', 2 => '已关闭', 3 => '已退款'];
$paid = (int)$order['pay_status'] === 1;
$closed = in_array((int)$order['pay_status'], [2, 3], true);
$isRecharge = (int)$order['license_type'] === 0; 

$cards = [];
if ($orderNo !== '') {
    $stmt = db()->prepare('SELECT `card_no` FROM `license` WHERE `remark`=? ORDER BY `id` ASC');
    $stmt->execute(['订单购买 ' . $orderNo]);
    $cards = array_column($stmt->fetchAll(), 'card_no');
}

$payError = '';
$payType = '';      $payData = '';      if (!$paid && !$closed && $channel !== '') {
    $channels = pay_channels();
    if (!isset($channels[$channel])) {
        $payError = '该支付渠道未启用。';
    } else {
        try {
            require dirname(__DIR__) . "/plugin/{$channel}/pay.php";
            $notify = base_url() . 'api/pay_callback.php?channel=' . $channel;
            $ret = match ($channel) {
                'epay'   => plugin_epay_pay($order, $notify, base_url() . 'user/orders.php'),
                'wechat' => plugin_wechat_pay($order, $notify),
                'alipay' => plugin_alipay_pay($order, $notify),
            };
            $payType = $ret['type'];
            $payData = $payType === 'qrcode' ? (string)$ret['qrcode'] : (string)($ret['pay_url'] ?? '');
            if ($payType === 'qrcode' && $payData !== '') {
                $qrPng = QRcode::png_data($payData, 5, 3);
                $payData = 'data:image/png;base64,' . base64_encode($qrPng);
            }
        } catch (Throwable $ex) {
            $payError = $ex->getMessage();
        }
    }
}

$channels = pay_channels();
$channelNames = ['alipay' => '支付宝', 'wechat' => '微信支付', 'epay' => '易支付'];

user_header('订单支付', 'orders', $user);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<style>

.order-summary{border:1px solid var(--bd);border-radius:var(--r);padding:4px 16px;margin-bottom:20px;background:var(--bg)}
.order-row{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--bd);font-size:13px;flex-wrap:wrap}
.order-row:last-child{border-bottom:none}
.order-label{width:72px;color:var(--t3);flex:none}
.order-amount{font-family:Consolas,Monaco,monospace;font-size:18px;font-weight:700;color:var(--p)}
.cell-sub{font-size:12px;color:var(--t3)}
.tag-off{background:var(--bg);color:var(--t3)}
.channel-row{display:flex;gap:10px;flex-wrap:wrap}
.channel-pill{display:inline-flex;align-items:center;gap:8px;height:38px;padding:0 16px;border:1px solid var(--bd);border-radius:var(--r);background:var(--card);font-size:14px;color:var(--t1);cursor:pointer;transition:border-color .15s,background .15s}
.channel-pill img{width:18px;height:18px;flex:none}
.channel-pill:hover{border-color:var(--p);background:var(--p-bg)}
.channel-pill.active{border-color:var(--p);background:var(--p-bg);box-shadow:inset 0 0 0 1px var(--p)}
.channel-pill.disabled{opacity:.55;cursor:not-allowed}
.qr-zone{display:flex;flex-direction:column;align-items:flex-start;gap:12px;padding:18px 0 4px}
.qr-img{width:180px;height:180px;border:1px solid var(--bd);border-radius:var(--r);background:#FFF}
.qr-tip{font-size:13px;color:var(--t3)}
@media (max-width:768px){.qr-zone{align-items:stretch}.qr-img{align-self:center}.channel-pill{flex:1 1 45%;justify-content:center}}
</style>

<section class="card" style="max-width:760px">
    <h2 class="card-title">订单支付</h2>
    <div class="order-summary">
        <div class="order-row"><span class="order-label">订单号</span><span class="cell-code"><?php echo e($order['order_no']); ?></span></div>
        <div class="order-row"><span class="order-label">商品</span><span><?php echo e($order['subject']); ?></span></div>
        <div class="order-row"><span class="order-label">应付金额</span><span class="order-amount">¥<?php echo e($order['amount']); ?></span>
            <?php if ((float)$order['discount_amount'] > 0): ?>
            <span class="cell-sub">已优惠 ¥<?php echo e($order['discount_amount']); ?></span>
            <?php endif; ?>
        </div>
        <div class="order-row"><span class="order-label">状态</span>
            <span class="tag <?php echo [1 => 'tag-ok', 0 => 'tag-warn', 2 => 'tag-off', 3 => 'tag-off'][(int)$order['pay_status']] ?? 'tag-off'; ?>" id="payStatusTag">
                <?php echo $statusMap[(int)$order['pay_status']] ?? '未知'; ?>
            </span>
        </div>
    </div>

    <?php 
    if ($paid): ?>
    <?php if ($isRecharge): ?>
    <div class="alert alert-ok">
        <img src="../assets/svg/check-circle.svg" alt="">
        <span>充值成功，余额已实时到账，可在工作台查看当前余额。余额仅用于购买授权。</span>
    </div>
    <div class="page-actions">
        <a class="btn-plain" href="orders.php">返回订单记录</a>
        <a class="btn-p" href="dashboard.php">返回工作台</a>
    </div>
    <?php else: ?>
    <div class="alert alert-ok">
        <img src="../assets/svg/check-circle.svg" alt="">
        <span>支付成功，以下卡密已发放到「我的授权」。</span>
    </div>
    <?php if ($cards): ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>#</th><th>卡密号</th></tr></thead>
            <tbody>
            <?php foreach ($cards as $i => $c): ?>
            <tr><td><?php echo $i + 1; ?></td><td class="cell-code"><?php echo e($c); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <div class="page-actions">
        <a class="btn-plain" href="orders.php">返回订单记录</a>
        <a class="btn-p" href="licenses.php">查看我的授权</a>
    </div>
    <?php endif; ?>

    <?php elseif ($closed): ?>
    <div class="alert alert-error">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>该订单已<?php echo $statusMap[(int)$order['pay_status']] ?? '关闭'; ?>，无法支付。<?php echo $isRecharge ? '如需充值请重新发起。' : '如需购买请重新下单。'; ?></span>
    </div>
    <div class="page-actions">
        <a class="btn-plain" href="orders.php">返回订单记录</a>
        <a class="btn-p" href="<?php echo $isRecharge ? 'finance.php' : 'buy.php'; ?>"><?php echo $isRecharge ? '重新充值' : '重新购买'; ?></a>
    </div>

    <?php 
    else: ?>
    <div class="form-item">
        <label class="form-label">支付方式</label>
        <?php $channelIcons = ['alipay' => '../assets/img/zfb.svg', 'wechat' => '../assets/img/wechat.svg', 'epay' => '../assets/img/epay.svg']; ?>
        <div class="channel-row" id="channelBox">
            <?php foreach ($channels as $ch => $info): ?>
            <a class="channel-pill <?php echo $channel === $ch ? 'active' : ''; ?>"
               href="pay.php?order_no=<?php echo e($orderNo); ?>&channel=<?php echo $ch; ?>">
                <img src="<?php echo $channelIcons[$ch] ?? '../assets/svg/menu-pay.svg'; ?>" alt="">
                <span><?php echo e($info['name']); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if (!$channels && !$isRecharge): ?>
        <div class="form-hint" style="margin-top:8px">暂无可用在线支付渠道，可使用余额支付或联系管理员在后台开启。</div>
        <?php endif; ?>

        <?php 
        if (!$isRecharge && pay_balance_enabled()): ?>
        <div style="margin-top:10px">
            <?php if ((float)$user['balance'] >= (float)$order['amount']): ?>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="order_no" value="<?php echo e($orderNo); ?>">
                <button class="channel-pill" type="submit"
                    data-confirm="确认使用账户余额支付本订单 ¥<?php echo e($order['amount']); ?>？支付后余额实时扣减。">
                    <img src="../assets/svg/menu-money.svg" alt="">
                    <span>余额支付（当前余额 ¥<?php echo e(number_format((float)$user['balance'], 2)); ?>）</span>
                </button>
            </form>
            <?php else: ?>
            <span class="channel-pill disabled" title="余额不足，请先充值">
                <img src="../assets/svg/menu-money.svg" alt="">
                <span>余额支付（当前余额 ¥<?php echo e(number_format((float)$user['balance'], 2)); ?>，余额不足）</span>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($payError !== ''): ?>
    <div class="alert alert-error">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span><?php echo e($payError); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($payType === 'qrcode' && $payData !== ''): ?>
    <div class="qr-zone">
        <img class="qr-img" src="<?php echo $payData; ?>" alt="支付二维码">
        <div class="qr-tip">请使用<?php echo e($channelNames[$channel] ?? '手机'); ?>扫码支付，支付完成后本页自动刷新。</div>
    </div>
    <?php elseif ($payType === 'redirect' && $payData !== ''): ?>
    <div class="qr-zone">
        <a class="btn-p" href="<?php echo e($payData); ?>" rel="nofollow">前往<?php echo e($channelNames[$channel] ?? '收款页'); ?>支付</a>
        <div class="qr-tip">支付完成后返回本页，系统将自动确认发放卡密。</div>
    </div>
    <?php endif; ?>

    <div class="page-actions">
        <a class="btn-plain" href="orders.php">返回订单记录</a>
    </div>

    <?php if ($payType !== '' && $payData !== ''): ?>
    <script>
    (function () {
        var orderNo = <?php echo json_encode($orderNo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var timer = null;
        function check() {
            fetch('../api/order_status.php?order_no=' + encodeURIComponent(orderNo), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.code === 200 && res.data.pay_status === 1) {
                        location.reload();
                    }
                })
                .catch(function () {});
        }
        timer = setInterval(check, 3000);
        window.addEventListener('beforeunload', function () { if (timer) clearInterval(timer); });
    })();
    </script>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php user_footer(); ?>
