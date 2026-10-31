<?php
// 代理商中心 - 工作台
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/AgentLevel.php';
require dirname(__DIR__) . '/core/layout_agent.php';

$agent = agent_require_login();
$aid   = (int)$agent['id'];

function ad_count(string $sql, array $params): int
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

$userCount  = ad_count('SELECT COUNT(*) FROM `user` WHERE `agent_id`=?', [$aid]);
$licTotal   = ad_count('SELECT COUNT(*) FROM `license` WHERE `owner_type`=2 AND `owner_id`=?', [$aid]);
$licActive  = ad_count('SELECT COUNT(*) FROM `license` WHERE `owner_type`=2 AND `owner_id`=? AND `status`=1', [$aid]);
$licFree    = ad_count('SELECT COUNT(*) FROM `license` WHERE `owner_type`=2 AND `owner_id`=? AND `user_id`=0 AND `status`=1', [$aid]);

$todayStart = strtotime('today');
$todayOrders = ad_count('SELECT COUNT(*) FROM `order` WHERE `agent_id`=? AND `created_at`>=?', [$aid, $todayStart]);
$todayCards  = ad_count('SELECT COUNT(*) FROM `order` WHERE `agent_id`=? AND `license_type`>0 AND `pay_status`=1 AND `created_at`>=?', [$aid, $todayStart]);

$downSum = ['today' => 0.0, 'total' => 0.0];
try {
    $stmt = db()->prepare('SELECT
        COALESCE(SUM(CASE WHEN o.`created_at`>=? THEN o.`amount` ELSE 0 END),0) AS today_sum,
        COALESCE(SUM(o.`amount`),0) AS total_sum
        FROM `order` o INNER JOIN `user` u ON u.`id`=o.`user_id`
        WHERE u.`agent_id`=? AND o.`pay_status`=1');
    $stmt->execute([$todayStart, $aid]);
    $row = $stmt->fetch();
    if ($row) {
        $downSum = ['today' => (float)$row['today_sum'], 'total' => (float)$row['total_sum']];
    }
} catch (Throwable $ex) {
}

$curLevel = null;
if ((int)$agent['level_id'] > 0) {
    $stmt = db()->prepare('SELECT * FROM `agent_level` WHERE `id`=? LIMIT 1');
    $stmt->execute([(int)$agent['level_id']]);
    $curLevel = $stmt->fetch() ?: null;
}

$downOrders = [];
$downUsers = [];
try {
    $stmt = db()->prepare(
        'SELECT o.`subject`,o.`amount`,o.`pay_status`,o.`created_at`,u.`username`
         FROM `order` o INNER JOIN `user` u ON u.`id`=o.`user_id`
         WHERE u.`agent_id`=? ORDER BY o.`id` DESC LIMIT 6'
    );
    $stmt->execute([$aid]);
    $downOrders = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT `username`,`created_at` FROM `user` WHERE `agent_id`=? ORDER BY `id` DESC LIMIT 5');
    $stmt->execute([$aid]);
    $downUsers = $stmt->fetchAll();
} catch (Throwable $ex) {
}

$discountText = rtrim(rtrim(number_format(agent_effective_discount($agent) * 10, 1, '.', ''), '0'), '.');

agent_header('概览首页', 'dashboard', $agent);
$flash = flash_take();
?>

<style>

.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
.stat-card{background:var(--card);border:1px solid var(--bd);border-radius:var(--r);padding:18px 20px}
.stat-label{font-size:13px;color:var(--t3)}
.stat-num{font-size:24px;font-weight:600;color:var(--t1);margin-top:8px;font-family:Consolas,Monaco,monospace}
.stat-num.blue{color:var(--p)}
.stat-num.green{color:var(--ok)}
.stat-num.orange{color:var(--warn)}
.stat-num small{font-size:12px;font-weight:400;color:var(--t3);font-family:inherit;margin-left:4px}
.level-line{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.level-chip{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 12px;background:var(--p-bg);border-radius:var(--r);color:var(--p);font-size:13px;font-weight:500}
.level-chip img{width:14px;height:14px}
.quick-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.quick-item{display:flex;align-items:center;gap:12px;padding:16px;border:1px solid var(--bd);border-radius:var(--r);background:var(--card);transition:border-color .15s}
.quick-item:hover{border-color:var(--p)}
.quick-item img{width:28px;height:28px;flex:none}
.quick-item b{display:block;font-size:14px;color:var(--t1);font-weight:500}
.quick-item i{display:block;font-style:normal;font-size:12px;color:var(--t3);margin-top:2px}
.grid-2{display:grid;grid-template-columns:3fr 2fr;gap:16px}
.dyn-row{display:flex;align-items:center;gap:10px;padding:11px 0;border-bottom:1px solid var(--bd);font-size:13px}
.dyn-row:last-child{border-bottom:none}
.dyn-dot{width:6px;height:6px;border-radius:50%;background:var(--p);flex:none}
.dyn-main{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--t1)}
.dyn-time{font-size:12px;color:var(--t3);flex:none}
.dyn-amount{font-family:Consolas,Monaco,monospace;font-weight:600;flex:none}
@media (max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr)}.grid-2{grid-template-columns:1fr}}
</style>

<?php if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">名下用户</div>
        <div class="stat-num blue"><?php echo $userCount; ?><small>人</small></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">授权总数</div>
        <div class="stat-num"><?php echo $licTotal; ?><small>张</small></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">今日订单</div>
        <div class="stat-num green"><?php echo $todayOrders; ?><small>笔（开卡 <?php echo $todayCards; ?>）</small></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">下级消费（今日 / 累计）</div>
        <div class="stat-num orange">¥<?php echo e(number_format($downSum['today'], 2)); ?><small>/ ¥<?php echo e(number_format($downSum['total'], 2)); ?></small></div>
    </div>
</div>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">我的代理等级</h2>
        <a class="btn-plain" href="level.php">等级升级</a>
    </div>
    <div class="level-line">
        <span class="level-chip"><img src="../assets/svg/menu-agent.svg" alt=""><?php echo $curLevel ? e((string)$curLevel['name']) : '默认等级'; ?></span>
        <span style="font-size:13px;color:var(--t2)">开卡折扣 <b><?php echo $discountText; ?> 折</b></span>
        <span style="font-size:13px;color:var(--t2)">可用余额 <b>¥<?php echo e(number_format((float)$agent['balance'], 2)); ?></b></span>
        <span style="font-size:13px;color:var(--t2)">赠送余额 <b>¥<?php echo e(number_format((float)$agent['gift_balance'], 2)); ?></b></span>
    </div>
    <?php if ($curLevel && (string)$curLevel['benefits'] !== ''): ?>
    <div class="form-hint" style="margin-top:10px">等级权益：<?php echo e((string)$curLevel['benefits']); ?></div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="page-actions" style="margin-bottom:12px">
        <h2 class="card-title" style="margin-bottom:0">快捷功能</h2>
    </div>
    <div class="quick-grid">
        <a class="quick-item" href="buy.php">
            <img src="../assets/svg/menu-money.svg" alt="">
            <span><b>开通授权</b><i>按代理折扣开卡</i></span>
        </a>
        <a class="quick-item" href="users.php">
            <img src="../assets/svg/menu-agent.svg" alt="">
            <span><b>名下用户</b><i>邀请码绑定与用户管理</i></span>
        </a>
        <a class="quick-item" href="finance.php">
            <img src="../assets/svg/menu-log.svg" alt="">
            <span><b>我的财务</b><i>充值 / 提现 / 流水</i></span>
        </a>
        <a class="quick-item" href="license_manage.php">
            <img src="../assets/svg/menu-key.svg" alt="">
            <span><b>我的授权</b><i>卡密管理与下发</i></span>
        </a>
        <a class="quick-item" href="tickets.php">
            <img src="../assets/svg/menu-activity.svg" alt="">
            <span><b>我的工单</b><i>提交问题获取支持</i></span>
        </a>
        <a class="quick-item" href="profile.php">
            <img src="../assets/svg/menu-user.svg" alt="">
            <span><b>个人设置</b><i>资料 / 密码 / 邀请码</i></span>
        </a>
    </div>
</section>

<div class="grid-2">
    <section class="card">
        <div class="page-actions" style="margin-bottom:8px">
            <h2 class="card-title" style="margin-bottom:0">下级订单动态</h2>
            <a class="btn-plain" href="finance.php">全部明细</a>
        </div>
        <?php if (!$downOrders): ?>
        <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>名下用户暂无订单。</span></div>
        <?php else: ?>
        <?php foreach ($downOrders as $d): ?>
        <div class="dyn-row">
            <span class="dyn-dot"></span>
            <span class="dyn-main"><?php echo e((string)$d['username']); ?> · <?php echo e((string)$d['subject']); ?></span>
            <span class="dyn-amount">¥<?php echo e(number_format((float)$d['amount'], 2)); ?></span>
            <span class="dyn-time"><?php echo e(date('m-d H:i', (int)$d['created_at'])); ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="page-actions" style="margin-bottom:8px">
            <h2 class="card-title" style="margin-bottom:0">最新绑定用户</h2>
            <a class="btn-plain" href="users.php">全部用户</a>
        </div>
        <?php if (!$downUsers): ?>
        <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无绑定用户，可在「个人设置」复制邀请码下发。</span></div>
        <?php else: ?>
        <?php foreach ($downUsers as $u): ?>
        <div class="dyn-row">
            <span class="dyn-dot" style="background:var(--ok)"></span>
            <span class="dyn-main"><?php echo e((string)$u['username']); ?> 注册绑定</span>
            <span class="dyn-time"><?php echo e(date('m-d H:i', (int)$u['created_at'])); ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>
<?php agent_footer(); ?>
