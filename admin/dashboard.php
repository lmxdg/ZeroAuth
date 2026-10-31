<?php
// 管理员后台 - 工作台
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Pay.php';
require dirname(__DIR__) . '/core/Mail.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

mail_expire_scan();

function table_count(string $table): int
{
    try {
        return (int)db()->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

$appCount    = table_count('app');
$licenseCnt  = table_count('license');
$orderCnt    = table_count('order');
$userCnt     = table_count('user');
$agentCnt    = table_count('agent');
$sdkTplCnt   = table_count('sdk_template');
$sdkDlCnt    = table_count('sdk_download_log');
$auditCnt    = table_count('audit_log');

$dayStart = strtotime('today');
$dayEnd = $dayStart + 86400;

function day_count(string $table, int $from, int $to): int
{
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `created_at`>=? AND `created_at`<?");
        $st->execute([$from, $to]);
        return (int)$st->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

function day_income(int $from, int $to): float
{
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(`amount`),0) FROM `order` WHERE `pay_status`=1 AND `created_at`>=? AND `created_at`<?");
        $st->execute([$from, $to]);
        return (float)$st->fetchColumn();
    } catch (Throwable $ex) {
        return 0.0;
    }
}

function trend_pct(float $today, float $yest): int
{
    if ($yest > 0) {
        return (int)round(($today - $yest) / $yest * 100);
    }
    return $today > 0 ? 100 : 0;
}

$ordToday   = day_count('order', $dayStart, $dayEnd);
$ordYest    = day_count('order', $dayStart - 86400, $dayStart);
$incToday   = day_income($dayStart, $dayEnd);
$incYest    = day_income($dayStart - 86400, $dayStart);
$licToday   = day_count('license', $dayStart, $dayEnd);
$licYest    = day_count('license', $dayStart - 86400, $dayStart);
$userToday  = day_count('user', $dayStart, $dayEnd);
$userYest   = day_count('user', $dayStart - 86400, $dayStart);
$agentToday = day_count('agent', $dayStart, $dayEnd);
$agentYest  = day_count('agent', $dayStart - 86400, $dayStart);

function status_count(string $table, int $status): int
{
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `status`=?");
        $st->execute([$status]);
        return (int)$st->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

$userOn  = status_count('user', 1);
$userOff = status_count('user', 2);
$agentOn  = status_count('agent', 1);
$agentOff = status_count('agent', 2);
$appOn   = status_count('app', 1);
$appOff  = status_count('app', 2);
$licValidCnt   = status_count('license', 1);
$licBannedCnt  = status_count('license', 2);

$validLic = 0;
try {
    $now = time();
    $validLic = (int)db()->query("SELECT COUNT(*) FROM `license` WHERE `status`=1 AND (`expire_time`=0 OR `expire_time`>{$now}) AND (`start_time`=0 OR `start_time`<={$now})")->fetchColumn();
} catch (Throwable $ex) {
}

$expiring = 0;
try {
    $now = time();
    $expiring = (int)db()->query("SELECT COUNT(*) FROM `license` WHERE `status`=1 AND `expire_time`>{$now} AND `expire_time`<=" . ($now + 86400 * 7))->fetchColumn();
} catch (Throwable $ex) {
}

function trend_html(float $today, float $yest): string
{
    $pct = trend_pct($today, $yest);
    $cls = $pct < 0 ? 'down' : 'up';
    $sign = $pct >= 0 ? '+' : '';
    return '<div class="stat-trend"><b class="' . $cls . '">' . $sign . $pct . '%</b> 较昨日</div>';
}

$paidAmount = '0.00';
$paidCnt = 0;
try {
    $row = db()->query('SELECT COUNT(*), COALESCE(SUM(`amount`),0) FROM `order` WHERE `pay_status`=1')->fetch(PDO::FETCH_NUM);
    $paidCnt = (int)$row[0];
    $paidAmount = number_format((float)$row[1], 2);
} catch (Throwable $ex) {
}

$appRank = [];
try {
    $licByApp = db()->query('SELECT `app_id`, COUNT(*) FROM `license` GROUP BY `app_id`')->fetchAll(PDO::FETCH_KEY_PAIR);
    $incByApp = db()->query('SELECT `app_id`, COALESCE(SUM(`amount`),0) FROM `order` WHERE `pay_status`=1 GROUP BY `app_id`')->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($licByApp) {
        $apps = db()->query('SELECT `id`, `name` FROM `app`')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($apps as $a) {
            $cnt = isset($licByApp[$a['id']]) ? (int)$licByApp[$a['id']] : 0;
            if ($cnt <= 0) continue;
            $appRank[] = ['name' => $a['name'], 'cnt' => $cnt, 'income' => isset($incByApp[$a['id']]) ? (float)$incByApp[$a['id']] : 0.0];
        }
        usort($appRank, function ($x, $y) { return $y['cnt'] - $x['cnt']; });
        $appRank = array_slice($appRank, 0, 5);
    }
} catch (Throwable $ex) {
}

$agentRank = [];
try {
    $totalByAgent = db()->query('SELECT `owner_id`, COUNT(*) FROM `license` WHERE `owner_type`=2 GROUP BY `owner_id`')->fetchAll(PDO::FETCH_KEY_PAIR);
    $todayRows = db()->query('SELECT `owner_id`, COUNT(*) FROM `license` WHERE `owner_type`=2 AND `created_at`>=' . $dayStart . ' GROUP BY `owner_id`')->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($totalByAgent) {
        $ids = implode(',', array_map('intval', array_keys($totalByAgent)));
        $rows = db()->query("SELECT `id`, `username`, `balance` FROM `agent` WHERE `id` IN ({$ids})")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $agentRank[] = [
                'name'    => $r['username'],
                'cnt'     => (int)$totalByAgent[$r['id']],
                'today'   => isset($todayRows[$r['id']]) ? (int)$todayRows[$r['id']] : 0,
                'balance' => (float)$r['balance'],
            ];
        }
        usort($agentRank, function ($x, $y) { return $y['cnt'] - $x['cnt']; });
        $agentRank = array_slice($agentRank, 0, 5);
    }
} catch (Throwable $ex) {
}

admin_header('工作台', 'dashboard', $admin);
?>
<div class="stat-grid">
    <div class="stat-card">
        <img class="stat-icon" src="../assets/svg/stat-money.svg" alt="">
        <div class="stat-label">今日收入</div>
        <div class="stat-value">¥<?php echo number_format($incToday, 2); ?><span class="unit">元</span></div>
        <?php echo trend_html($incToday, $incYest); ?>
    </div>
    <div class="stat-card">
        <img class="stat-icon" src="../assets/svg/stat-order.svg" alt="">
        <div class="stat-label">今日订单</div>
        <div class="stat-value"><?php echo $ordToday; ?><span class="unit">单</span></div>
        <?php echo trend_html($ordToday, $ordYest); ?>
    </div>
    <div class="stat-card">
        <img class="stat-icon" src="../assets/svg/stat-auth.svg" alt="">
        <div class="stat-label">有效授权</div>
        <div class="stat-value"><?php echo $validLic; ?><span class="unit">个</span></div>
        <?php echo trend_html($licToday, $licYest); ?>
    </div>
    <div class="stat-card">
        <img class="stat-icon" src="../assets/svg/stat-expire.svg" alt="">
        <div class="stat-label">即将到期</div>
        <div class="stat-value"><?php echo $expiring; ?><span class="unit">个</span></div>
        <?php echo trend_html(0, 0); ?>
    </div>
    <div class="stat-card">
        <img class="stat-icon" src="../assets/svg/stat-user.svg" alt="">
        <div class="stat-label">用户总数</div>
        <div class="stat-value"><?php echo $userCnt; ?><span class="unit">人</span></div>
        <?php echo trend_html($userToday, $userYest); ?>
    </div>
    <div class="stat-card">
        <img class="stat-icon" src="../assets/svg/stat-agent.svg" alt="">
        <div class="stat-label">代理商</div>
        <div class="stat-value"><?php echo $agentCnt; ?><span class="unit">个</span></div>
        <?php echo trend_html($agentToday, $agentYest); ?>
    </div>
</div>

<section class="card">
    <div class="wm-head">
        <h2 class="card-title">用户分布地图</h2>
        <button type="button" class="wm-refresh-btn" id="wmRefreshBtn"><img src="../assets/svg/reload.svg" alt=""><span>数据刷新</span></button>
    </div>
    <div class="wm-head-sub"><span>精确到省市，颜色深浅代表用户数量，鼠标悬浮查看统计，点击查看完整明细</span><span id="wmUnresolvedNote" class="cell-sub"></span></div>
    <div class="wm-body">
        <div class="world-map-box" id="worldMapChart"></div>
        <aside class="wm-side" id="wmSide"></aside>
    </div>
</section>

<div class="dash-groups">
    <section class="card dash-group">
        <h2 class="card-title">代理商运营</h2>
        <div class="group-sub">代理商数量、状态和今日新增</div>
        <div class="tile-grid">
            <div class="tile">
                <div class="tile-label">代理商总数</div>
                <div class="tile-value"><?php echo $agentCnt; ?><span class="unit">个</span></div>
                <div class="tile-sub">启用 <?php echo $agentOn; ?> 个 / 禁用 <?php echo $agentOff; ?> 个</div>
            </div>
            <div class="tile">
                <div class="tile-label">今日新增代理商</div>
                <div class="tile-value"><?php echo $agentToday; ?><span class="unit">个</span></div>
                <div class="tile-sub">当天创建的代理商账号</div>
            </div>
            <div class="tile">
                <div class="tile-label">审计日志</div>
                <div class="tile-value"><?php echo $auditCnt; ?><span class="unit">条</span></div>
                <div class="tile-sub">全部后台操作记录</div>
            </div>
            <div class="tile">
                <div class="tile-label">SDK 模板</div>
                <div class="tile-value"><?php echo $sdkTplCnt; ?><span class="unit">个</span></div>
                <div class="tile-sub">累计下载 <?php echo $sdkDlCnt; ?> 次</div>
            </div>
        </div>
    </section>
    <section class="card dash-group">
        <h2 class="card-title">用户数据</h2>
        <div class="group-sub">用户增长和订单消费</div>
        <div class="tile-grid">
            <div class="tile">
                <div class="tile-label">用户总数</div>
                <div class="tile-value"><?php echo $userCnt; ?><span class="unit">人</span></div>
                <div class="tile-sub">启用 <?php echo $userOn; ?> 人 / 禁用 <?php echo $userOff; ?> 人</div>
            </div>
            <div class="tile">
                <div class="tile-label">今日新增用户</div>
                <div class="tile-value"><?php echo $userToday; ?><span class="unit">人</span></div>
                <div class="tile-sub">当天注册或后台创建</div>
            </div>
            <div class="tile">
                <div class="tile-label">已支付订单</div>
                <div class="tile-value"><?php echo $paidCnt; ?><span class="unit">笔</span></div>
                <div class="tile-sub">累计支付成功的订单</div>
            </div>
            <div class="tile">
                <div class="tile-label">支付总金额</div>
                <div class="tile-value">¥<?php echo $paidAmount; ?><span class="unit">元</span></div>
                <div class="tile-sub">所有已支付订单合计</div>
            </div>
        </div>
    </section>
    <section class="card dash-group">
        <h2 class="card-title">应用产品</h2>
        <div class="group-sub">应用、授权和支付规模</div>
        <div class="tile-grid">
            <div class="tile">
                <div class="tile-label">应用总数</div>
                <div class="tile-value"><?php echo $appCount; ?><span class="unit">个</span></div>
                <div class="tile-sub">上架 <?php echo $appOn; ?> 个 / 下架 <?php echo $appOff; ?> 个</div>
            </div>
            <div class="tile">
                <div class="tile-label">卡密授权</div>
                <div class="tile-value"><?php echo $licenseCnt; ?><span class="unit">个</span></div>
                <div class="tile-sub">有效 <?php echo $licValidCnt; ?> 个 / 封禁 <?php echo $licBannedCnt; ?> 个</div>
            </div>
            <div class="tile">
                <div class="tile-label">即将到期</div>
                <div class="tile-value"><?php echo $expiring; ?><span class="unit">个</span></div>
                <div class="tile-sub">7 天内到期的限时授权</div>
            </div>
            <div class="tile">
                <div class="tile-label">支付渠道</div>
                <div class="tile-value"><?php echo count(pay_channels()); ?><span class="unit">个</span></div>
                <div class="tile-sub">已接入的支付方式</div>
            </div>
        </div>
    </section>
</div>
<div class="rank-grid">
    <section class="card rank-card">
        <h2 class="card-title">应用销售排行</h2>
        <div class="group-sub">按授权数量排序</div>
        <div class="rank-list">
            <?php if (!$appRank): ?>
                <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span></div>
            <?php else: foreach ($appRank as $i => $r): ?>
                <div class="rank-row">
                    <span class="rank-badge b<?php echo $i + 1; ?>"><?php echo $i + 1; ?></span>
                    <div class="rank-info">
                        <span class="rank-name"><?php echo e($r['name']); ?></span>
                        <span class="rank-sub">收入 ¥<?php echo number_format($r['income'], 2); ?></span>
                    </div>
                    <b class="rank-count"><?php echo $r['cnt']; ?></b>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </section>
    <section class="card rank-card">
        <h2 class="card-title">代理商排行</h2>
        <div class="group-sub">按累计开通授权排序</div>
        <div class="rank-list">
            <?php if (!$agentRank): ?>
                <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span></div>
            <?php else: foreach ($agentRank as $i => $r): ?>
                <div class="rank-row">
                    <span class="rank-badge b<?php echo $i + 1; ?>"><?php echo $i + 1; ?></span>
                    <div class="rank-info">
                        <span class="rank-name"><?php echo e($r['name']); ?></span>
                        <span class="rank-sub">今日开通 <?php echo $r['today']; ?> 个 ｜ 余额 ¥<?php echo number_format($r['balance'], 2); ?></span>
                    </div>
                    <b class="rank-count"><?php echo $r['cnt']; ?></b>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </section>
</div>
<?php admin_footer(['../assets/lib/echarts.min.js', 'assets/world-map.js']); ?>
