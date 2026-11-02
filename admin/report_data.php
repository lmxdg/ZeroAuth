<?php
// 管理员后台 - 报表数据
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$view = (string)($_GET['view'] ?? 'revenue');
if (!in_array($view, ['revenue', 'pass', 'piracy'], true)) {
    $view = 'revenue';
}

$todayStart = strtotime('today');
$since = $todayStart - 29 * 86400;
$days = [];
for ($i = 0; $i < 30; $i++) {
    $days[] = date('Y-m-d', $since + $i * 86400);
}

$revMap = [];
try {
    $stmt = db()->prepare("SELECT FROM_UNIXTIME(`created_at`,'%Y-%m-%d') AS `d`, COUNT(*) AS `c`, COALESCE(SUM(`amount`),0) AS `amt` FROM `order` WHERE `pay_status`=1 AND `created_at`>=? GROUP BY `d`");
    $stmt->execute([$since]);
    foreach ($stmt->fetchAll() as $r) {
        $revMap[$r['d']] = $r;
    }
} catch (Throwable $ex) {  }

$revDays = [];
$maxAmt = 0.0;
$revOrders = 0;
$revIncome = 0.0;
foreach ($days as $d) {
    $c = (int)($revMap[$d]['c'] ?? 0);
    $amt = (float)($revMap[$d]['amt'] ?? 0);
    $revDays[] = ['d' => $d, 'c' => $c, 'amt' => $amt];
    if ($amt > $maxAmt) {
        $maxAmt = $amt;
    }
    $revOrders += $c;
    $revIncome += $amt;
}
$revDailyAvg = $revIncome / 30;

$appTop = [];
try {
    $stmt = db()->prepare("SELECT o.`app_id`, COALESCE(a.`name`, '已删除应用') AS `app_name`, COUNT(*) AS `c`, COALESCE(SUM(o.`amount`),0) AS `amt` FROM `order` o LEFT JOIN `app` a ON a.`id`=o.`app_id` WHERE o.`pay_status`=1 AND o.`created_at`>=? GROUP BY o.`app_id`, a.`name` ORDER BY `amt` DESC, `c` DESC LIMIT 10");
    $stmt->execute([$since]);
    $appTop = $stmt->fetchAll();
} catch (Throwable $ex) {  }

$passMap = [];
try {
    $stmt = db()->prepare("SELECT FROM_UNIXTIME(`created_at`,'%Y-%m-%d') AS `d`, COUNT(*) AS `t`, SUM(CASE WHEN `result`=1 THEN 1 ELSE 0 END) AS `ok` FROM `verify_log` WHERE `created_at`>=? GROUP BY `d`");
    $stmt->execute([$since]);
    foreach ($stmt->fetchAll() as $r) {
        $passMap[$r['d']] = $r;
    }
} catch (Throwable $ex) {  }

$passDays = [];
$passTotal = 0;
$passOk = 0;
foreach ($days as $d) {
    $t = (int)($passMap[$d]['t'] ?? 0);
    $ok = (int)($passMap[$d]['ok'] ?? 0);
    $passDays[] = ['d' => $d, 't' => $t, 'ok' => $ok];
    $passTotal += $t;
    $passOk += $ok;
}
$passFail = $passTotal - $passOk;
$passAvgRate = $passTotal > 0 ? round($passOk / $passTotal * 100, 2) : 0.0;

$multiIpLic = 0;   
$blackHits = 0;    
$blackActive = 0;  
$pAlerts = [];     
try {
    $multiIpLic = (int)db()->query('SELECT COUNT(*) FROM (SELECT `license_id` FROM `license_device` GROUP BY `license_id` HAVING COUNT(DISTINCT `bind_ip`)>=2) t')->fetchColumn();
    $blackHits = (int)db()->query("SELECT COUNT(*) FROM `alert_center` WHERE `module`='blacklist'")->fetchColumn();
    $blackActive = (int)db()->query('SELECT COUNT(*) FROM `blacklist` WHERE `status`=1')->fetchColumn();
    $stmt = db()->prepare("SELECT * FROM `alert_center` WHERE `module` IN ('piracy','blacklist','auth') AND `created_at`>=? ORDER BY `id` DESC LIMIT 50");
    $stmt->execute([$since]);
    $pAlerts = $stmt->fetchAll();
} catch (Throwable $ex) {  }

$levelMap = [
    3 => ['严重', 'tag-err'],
    2 => ['警告', 'tag-warn'],
    1 => ['提示', 'tag-off'],
];

admin_header('数据报表', 'report', $admin);
?>
<style>

.view-tab{display:inline-flex;align-items:center;height:32px;padding:0 18px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-page);color:var(--text-secondary);font-size:13px;text-decoration:none}
.view-tab:hover{color:var(--primary)}
.view-tab.is-active{border-color:var(--primary);color:var(--primary);font-weight:600;background:rgba(var(--primary-rgb,0,110,255),.06)}
</style>

<section class="card">
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="view-tab<?php echo $view === 'revenue' ? ' is-active' : ''; ?>" href="report_data.php?view=revenue">收入报表</a>
        <a class="view-tab<?php echo $view === 'pass' ? ' is-active' : ''; ?>" href="report_data.php?view=pass">通过率报表</a>
        <a class="view-tab<?php echo $view === 'piracy' ? ' is-active' : ''; ?>" href="report_data.php?view=piracy">盗版风控</a>
    </div>
</section>

<?php if ($view === 'revenue'): ?>
<section class="card">
    <h2 class="card-title">近 30 天每日收入</h2>
    <p class="card-desc">统计每日已支付订单（pay_status=1）的订单数与金额，柱高按当日金额占比展示，悬浮查看明细。</p>
    <?php if ($revOrders === 0): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span>
    </div>
    <?php else: ?>
    <div style="display:flex;align-items:flex-end;height:180px;gap:3px">
        <?php foreach ($revDays as $x): ?>
        <?php if ($x['amt'] > 0 && $maxAmt > 0): $h = round($x['amt'] / $maxAmt * 100, 2); ?>
        <div style="flex:1;height:<?php echo $h; ?>%;background:var(--primary);border-radius:2px 2px 0 0" title="<?php echo e($x['d'] . '：¥' . number_format($x['amt'], 2) . '（' . $x['c'] . ' 单）'); ?>"></div>
        <?php else: ?>
        <div style="flex:1;height:2px;background:#dcdfe6;border-radius:2px 2px 0 0" title="<?php echo e($x['d'] . '：¥0.00（0 单）'); ?>"></div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:3px;margin-top:6px">
        <?php foreach ($revDays as $i => $x): ?>
        <div style="flex:1;text-align:center;font-size:11px;color:var(--text-third)"><?php echo $i % 5 === 0 ? e(substr($x['d'], 5)) : ''; ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="display:flex;flex-wrap:wrap;gap:48px;margin-top:24px;padding-top:16px;border-top:1px solid var(--border)">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $revOrders; ?></div>
            <div class="form-hint" style="margin-top:2px">期间订单数（单）</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--success)">¥<?php echo number_format($revIncome, 2); ?></div>
            <div class="form-hint" style="margin-top:2px">期间总收入（元）</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--primary)">¥<?php echo number_format($revDailyAvg, 2); ?></div>
            <div class="form-hint" style="margin-top:2px">日均收入（元）</div>
        </div>
    </div>
</section>

<section class="card">
    <h2 class="card-title">按应用收入 TOP10</h2>
    <p class="card-desc">近 30 天已支付订单按应用汇总（pay_status=1）。</p>
    <?php if (!$appTop): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th style="width:70px">排名</th><th>应用名</th><th>订单数</th><th>金额（元）</th></tr></thead>
            <tbody>
            <?php foreach ($appTop as $i => $r): ?>
            <tr>
                <td class="cell-code"><?php echo $i + 1; ?></td>
                <td><?php echo e((string)$r['app_name']); ?></td>
                <td><?php echo (int)$r['c']; ?></td>
                <td class="cell-code">¥<?php echo number_format((float)$r['amt'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php elseif ($view === 'pass'): ?>
<section class="card">
    <h2 class="card-title">近 30 天验证通过率</h2>
    <p class="card-desc">统计每日验证总数、成功数与成功率（result=1 为成功），柱高为当日成功率（0-100%）。</p>
    <?php if ($passTotal === 0): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span>
    </div>
    <?php else: ?>
    <div style="display:flex;align-items:flex-end;height:180px;gap:3px">
        <?php foreach ($passDays as $x): ?>
        <?php if ($x['t'] > 0): $h = round($x['ok'] / $x['t'] * 100, 2); ?>
        <div style="flex:1;height:<?php echo $h; ?>%;background:#0052d9;border-radius:2px 2px 0 0" title="<?php echo e($x['d'] . '：验证 ' . $x['t'] . ' 次 / 成功 ' . $x['ok'] . ' 次 / 成功率 ' . $h . '%'); ?>"></div>
        <?php else: ?>
        <div style="flex:1;height:2px;background:#dcdfe6;border-radius:2px 2px 0 0" title="<?php echo e($x['d'] . '：无验证记录'); ?>"></div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:3px;margin-top:6px">
        <?php foreach ($passDays as $i => $x): ?>
        <div style="flex:1;text-align:center;font-size:11px;color:var(--text-third)"><?php echo $i % 5 === 0 ? e(substr($x['d'], 5)) : ''; ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="display:flex;flex-wrap:wrap;gap:48px;margin-top:24px;padding-top:16px;border-top:1px solid var(--border)">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $passTotal; ?></div>
            <div class="form-hint" style="margin-top:2px">期间总验证（次）</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--success)"><?php echo $passOk; ?></div>
            <div class="form-hint" style="margin-top:2px">总成功（次）</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--error)"><?php echo $passFail; ?></div>
            <div class="form-hint" style="margin-top:2px">总失败（次）</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--primary)"><?php echo $passAvgRate; ?>%</div>
            <div class="form-hint" style="margin-top:2px">平均成功率</div>
        </div>
    </div>
</section>

<?php else: ?>
<section class="card">
    <h2 class="card-title">风控概览</h2>
    <div style="display:flex;flex-wrap:wrap;gap:48px">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--error)"><?php echo $multiIpLic; ?></div>
            <div class="form-hint" style="margin-top:2px">多 IP 风险卡密数（同一卡密绑定 ≥2 个 IP）</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--warning)"><?php echo $blackHits; ?></div>
            <div class="form-hint" style="margin-top:2px">黑名单拦截总数</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $blackActive; ?></div>
            <div class="form-hint" style="margin-top:2px">生效黑名单条数</div>
        </div>
    </div>
</section>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">近 30 天安全类告警</h2>
    </div>
    <p class="card-desc">来源模块：piracy（盗版追踪）、blacklist（黑名单拦截）、auth（验证异常），最多显示 50 条。</p>
    <?php if (!$pAlerts): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>时间</th><th style="width:70px">级别</th><th>标题</th><th>内容</th><th>来源模块</th><th>IP</th><th>归属地</th></tr></thead>
            <tbody>
            <?php foreach ($pAlerts as $row): [$lvName, $lvTag] = $levelMap[(int)$row['level']] ?? ['未知', 'tag-off']; ?>
            <tr>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i:s', (int)$row['created_at']); ?></td>
                <td><span class="tag <?php echo $lvTag; ?>"><?php echo e($lvName); ?></span></td>
                <td style="max-width:220px;word-break:break-all"><b><?php echo e($row['title']); ?></b></td>
                <td class="cell-sub" style="max-width:280px;word-break:break-all"><?php echo $row['content'] !== '' ? e(mb_substr($row['content'], 0, 80) . (mb_strlen($row['content']) > 80 ? '…' : '')) : '-'; ?></td>
                <td class="cell-code"><?php echo e($row['module'] !== '' ? $row['module'] : '-'); ?></td>
                <td class="cell-code"><?php echo $row['ip'] !== '' ? e($row['ip']) : '-'; ?></td>
                <td><?php echo $row['ip'] !== '' ? ip_geo_badge((string)$row['ip']) : '<span class="text-sub">-</span>'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
