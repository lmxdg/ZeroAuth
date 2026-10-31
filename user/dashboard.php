<?php
// 用户中心 - 工作台
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_user.php';

$user = user_require_login();
$now = time();

$stmt = db()->prepare(
    'SELECT COUNT(*) AS total,
        SUM(CASE WHEN `status`=1 AND (`type`<>1 OR `expire_time`=0 OR `expire_time`>?) THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN `type`=1 AND `expire_time`>? AND `expire_time`<=? THEN 1 ELSE 0 END) AS expiring,
        SUM(CASE WHEN `status`=2 OR (`type`=1 AND `expire_time`>0 AND `expire_time`<=?) THEN 1 ELSE 0 END) AS invalid
    FROM `license` WHERE `user_id`=?'
);
$stmt->execute([$now, $now, $now + 30 * 86400, $now, (int)$user['id']]);
$lic = $stmt->fetch() ?: [];

$stmt = db()->prepare('SELECT COUNT(*) FROM `license_device` d JOIN `license` l ON d.`license_id`=l.`id` WHERE l.`user_id`=? AND d.`status`=1');
$stmt->execute([(int)$user['id']]);
$deviceCount = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT `order_no`,`subject`,`amount`,`pay_status`,`created_at` FROM `order` WHERE `user_id`=? AND `license_type`>0 ORDER BY `id` DESC LIMIT 5');
$stmt->execute([(int)$user['id']]);
$orders = $stmt->fetchAll();

$notice = '';
try {
    $stmt = db()->prepare("SELECT `var_value` FROM `cloud_var` WHERE `app_id`=0 AND `var_key`='notice' AND `is_public`=1 LIMIT 1");
    $stmt->execute();
    $notice = trim((string)$stmt->fetchColumn());
} catch (Throwable $ex) {
    $notice = '';
}

user_header('概览首页', 'dashboard', $user);
?>

<style>

.stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px}
.stat-card{background:var(--card);border:1px solid var(--bd);border-radius:var(--r);padding:18px 20px}
.stat-label{font-size:13px;color:var(--t3)}
.stat-num{font-size:24px;font-weight:600;color:var(--t1);margin-top:8px;font-family:Consolas,Monaco,monospace}
.stat-num small{font-size:13px;font-weight:400;color:var(--t3);margin-left:3px;font-family:inherit}
.stat-num.blue{color:var(--p)}
.stat-num.green{color:var(--ok)}
.stat-num.orange{color:var(--warn)}
.stat-num.red{color:var(--err)}
.quick-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
.quick-item{display:flex;align-items:center;gap:10px;background:var(--card);border:1px solid var(--bd);border-radius:var(--r);padding:14px 16px;transition:border-color .15s,background .15s}
.quick-item:hover{border-color:var(--p);background:#F5F8FF}
.quick-item img{width:20px;height:20px;flex:none}
.quick-item b{font-size:13px;font-weight:500;color:var(--t1);display:block}
.quick-item i{font-style:normal;font-size:12px;color:var(--t3);display:block;margin-top:2px}
.notice-box{font-size:13px;color:var(--t2);line-height:22px;white-space:pre-line;word-break:break-word}
.order-amount{font-family:Consolas,Monaco,monospace;font-weight:600}
@media (max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr)}.quick-grid{grid-template-columns:repeat(2,1fr)}}
</style>

<?php $flash = flash_take(); if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">我的授权</div>
        <div class="stat-num blue"><?php echo (int)($lic['active'] ?? 0); ?><small>张正常</small></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">即将到期（30 天内）</div>
        <div class="stat-num orange"><?php echo (int)($lic['expiring'] ?? 0); ?><small>张</small></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">失效授权</div>
        <div class="stat-num red"><?php echo (int)($lic['invalid'] ?? 0); ?><small>张</small></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">绑定设备</div>
        <div class="stat-num"><?php echo $deviceCount; ?><small>台</small></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">账户余额</div>
        <div class="stat-num green">¥<?php echo e(number_format((float)$user['balance'], 2)); ?></div>
    </div>
</div>

<section class="card">
    <div class="page-actions" style="margin-bottom:12px">
        <h2 class="card-title" style="margin-bottom:0">快捷功能</h2>
    </div>
    <div class="quick-grid">
        <a class="quick-item" href="buy.php">
            <img src="../assets/svg/menu-money.svg" alt="">
            <span><b>购买授权</b><i>选择应用在线开通</i></span>
        </a>
        <a class="quick-item" href="finance.php">
            <img src="../assets/svg/menu-log.svg" alt="">
            <span><b>我的财务</b><i>充值 / 提现 / 流水</i></span>
        </a>
        <a class="quick-item" href="tickets.php">
            <img src="../assets/svg/menu-activity.svg" alt="">
            <span><b>我的工单</b><i>提交问题获取支持</i></span>
        </a>
        <a class="quick-item" href="agent_open.php">
            <img src="../assets/svg/menu-agent.svg" alt="">
            <span><b>开通代理商</b><i>享折扣价与专属权益</i></span>
        </a>
        <a class="quick-item" href="redeem.php">
            <img src="../assets/svg/menu-key.svg" alt="">
            <span><b>卡密兑换</b><i>兑换获得的授权卡密</i></span>
        </a>
    </div>
</section>

<?php if ($notice !== ''): ?>
<section class="card">
    <h2 class="card-title">系统公告</h2>
    <div class="notice-box"><?php echo e($notice); ?></div>
</section>
<?php endif; ?>

<section class="card">
    <div class="page-actions" style="margin-bottom:12px">
        <h2 class="card-title" style="margin-bottom:0">最近订单</h2>
        <a class="btn-plain" href="licenses.php">我的授权</a>
    </div>
    <?php if (!$orders): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无订单，前往「购买授权」开通第一个授权。</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead>
            <tr><th>订单号</th><th>内容</th><th>金额</th><th>状态</th><th>时间</th></tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td class="cell-code"><?php echo e($o['order_no']); ?></td>
                <td><?php echo e($o['subject']); ?></td>
                <td class="order-amount">¥<?php echo e(number_format((float)$o['amount'], 2)); ?></td>
                <td><?php
                    $ps = (int)$o['pay_status'];
                    echo $ps === 1 ? '<span class="tag tag-ok">已支付</span>'
                        : ($ps === 0 ? '<span class="tag tag-warn">待支付</span>'
                        : ($ps === 2 ? '<span class="tag tag-gray">已关闭</span>' : '<span class="tag tag-err">已退款</span>'));
                ?></td>
                <td class="cell-code"><?php echo e(date('Y-m-d H:i', (int)$o['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php user_footer(); ?>
