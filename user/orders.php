<?php
// 用户中心 - 我的订单
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_user.php';

$user = user_require_login();
$uid  = (int)$user['id'];

function order_status_info(int $s): array
{
    return [0 => ['未支付', 'tag-warn'], 1 => ['已支付', 'tag-ok'], 2 => ['已关闭', 'tag-off'], 3 => ['已退款', 'tag-err']][$s] ?? ['未知', 'tag-off'];
}

function order_channel_name(string $c): string
{
    return ['alipay' => '支付宝', 'wechat' => '微信支付', 'epay' => '易支付', 'balance' => '余额支付'][$c] ?? ($c !== '' ? $c : '-');
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));

$stmt = db()->prepare('SELECT COUNT(*) FROM `order` WHERE `user_id`=?');
$stmt->execute([$uid]);
$total = (int)$stmt->fetchColumn();
$stmt = db()->prepare('SELECT * FROM `order` WHERE `user_id`=? ORDER BY `id` DESC LIMIT ? OFFSET ?');
$stmt->execute([$uid, $perPage, ($page - 1) * $perPage]);
$rows = $stmt->fetchAll();

user_header('订单记录', 'orders', $user);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<style>

.tag-off{background:var(--bg);color:var(--t3)}
.cell-sub{color:var(--t3);font-size:12px}
.btn-mini{display:inline-flex;align-items:center;height:26px;padding:0 12px;border:1px solid var(--bd);border-radius:var(--r);font-size:12px;color:var(--t2);cursor:pointer;transition:all .15s;background:var(--card)}
.btn-mini:hover{color:var(--p);border-color:var(--p)}
</style>

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">订单记录（共 <?php echo $total; ?> 条）</h2>
            <p class="card-desc" style="margin-bottom:0">包含购买授权与余额充值的全部订单；未支付订单可继续支付，已支付订单可查看发放的卡密。</p>
        </div>
        <div class="spacer"></div>
        <a class="btn-p" href="buy.php">购买授权</a>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无订单记录。</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>订单号</th><th>标题</th><th>金额</th><th>支付渠道</th><th>状态</th><th>下单时间</th><th>支付时间</th><th style="width:110px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): [$sName, $sTag] = order_status_info((int)$row['pay_status']); ?>
            <tr>
                <td class="cell-code"><?php echo e($row['order_no']); ?></td>
                <td><?php echo e($row['subject']); ?></td>
                <td style="font-family:Consolas,Monaco,monospace;font-weight:600"><?php echo e(number_format((float)$row['amount'], 2)); ?> 元</td>
                <td class="cell-sub"><?php echo order_channel_name((string)$row['pay_channel']); ?></td>
                <td><span class="tag <?php echo $sTag; ?>"><?php echo $sName; ?></span></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$row['created_at']); ?></td>
                <td class="cell-sub"><?php echo (int)$row['paid_time'] > 0 ? date('Y-m-d H:i', (int)$row['paid_time']) : '-'; ?></td>
                <td>
                    <a class="btn-mini" href="pay.php?order_no=<?php echo e($row['order_no']); ?>">
                        <?php echo (int)$row['pay_status'] === 0 ? '去支付' : '详情'; ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, 'orders.php?'); ?>
    <?php endif; ?>
</section>
<?php user_footer(); ?>
