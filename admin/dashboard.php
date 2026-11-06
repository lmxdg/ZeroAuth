<?php
// 管理员后台 - 工作台
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Pay.php';
require dirname(__DIR__) . '/core/Mail.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

// 远程公告操作（关闭指定公告 / 手动检查新公告）与更新检测操作
if (is_post()) {
    $nAction = (string)($_POST['action'] ?? '');
    if (in_array($nAction, ['notice_dismiss', 'notice_refresh', 'update_check', 'ruin_cancel_top'], true)) {
        csrf_check();
        if ($nAction === 'notice_dismiss') {
            remote_notice_dismiss(trim((string)($_POST['id'] ?? '')));
        } elseif ($nAction === 'notice_refresh') {
            remote_notice_fetch(true);
            flash_set('ok', remote_notice_url() !== '' ? '远程公告检查完成。' : '远程公告地址未配置，请在「系统设置」中填写 remote_notice_url。');
        } elseif ($nAction === 'update_check') {
            $up = update_check_fetch(true);
            if (!empty($up['ok'])) {
                flash_set(version_compare((string)$up['version'], (string)PRODUCT_VERSION, '>') ? 'warn' : 'ok', version_compare((string)$up['version'], (string)PRODUCT_VERSION, '>') ? ('发现新版本 V' . $up['version'] . '（当前 V' . PRODUCT_VERSION . '），可点击「立即更新」一键在线升级。') : ('当前已是最新版本 V' . PRODUCT_VERSION . '。'));
            } else {
                flash_set('error', '更新检测失败：无法连接 GitHub Releases，请稍后重试。');
            }
        } elseif ($nAction === 'ruin_cancel_top') {
            ruin_schedule(0);
            flash_set('ok', '定时跑路已取消。');
        }
        redirect('dashboard.php');
    }
}

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
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<?php
// 远程公告（来自二开作者远程通道，缓存 1 小时，失败自动降级）
$remoteNotices = [];
try {
    $remoteNotices = remote_notice_active();
} catch (Throwable $ex) {
}
if ($remoteNotices):
    $rnLevels = [
        'info'  => ['远程公告', '#0052D9', '#F0F5FF'],
        'ok'    => ['系统通知', '#00B578', '#E8F7F1'],
        'warn'  => ['重要公告', '#D25F00', '#FFF7E8'],
        'error' => ['紧急公告', '#D54941', '#FBEBEA'],
    ];
?>
<section class="card" id="remoteNotices">
    <div class="page-actions">
        <div>
            <h2 class="card-title">远程公告</h2>
            <p class="card-desc" style="margin-bottom:0">来自系统维护者的远程公告通道（缓存 1 小时），用于发布更新提醒与重要通知。</p>
        </div>
        <div class="spacer"></div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="notice_refresh">
            <button class="btn-mini-plain" type="submit">检查新公告</button>
        </form>
    </div>
    <?php foreach ($remoteNotices as $rn):
        [$rnLabel, $rnColor, $rnBg] = $rnLevels[$rn['level']] ?? $rnLevels['info']; ?>
    <div style="display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--border,#F2F3F5)">
        <span style="flex:none;font-size:12px;color:<?php echo $rnColor; ?>;background:<?php echo $rnBg; ?>;border-radius:4px;padding:2px 8px;line-height:18px"><?php echo e($rnLabel); ?></span>
        <div style="flex:1;min-width:0">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
                <b style="font-size:14px;color:var(--text-primary,#1D2129)"><?php echo e($rn['title']); ?></b>
                <?php if ($rn['date'] !== ''): ?>
                <span style="font-size:12px;color:var(--text-third,#86909C)"><?php echo e($rn['date']); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($rn['content'] !== ''): ?>
            <p style="font-size:13px;color:var(--text-secondary,#4E5969);line-height:20px;margin:4px 0 0;white-space:pre-wrap"><?php echo e($rn['content']); ?></p>
            <?php endif; ?>
            <?php if ($rn['url'] !== ''): ?>
            <a href="<?php echo e($rn['url']); ?>" target="_blank" rel="noopener noreferrer" style="font-size:12px;color:#0052D9;margin-top:4px;display:inline-block">查看详情 →</a>
            <?php endif; ?>
        </div>
        <form method="post" style="flex:none">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="notice_dismiss">
            <input type="hidden" name="id" value="<?php echo e($rn['id']); ?>">
            <button class="btn-mini-plain" type="submit">我知道了</button>
        </form>
    </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php
// 定时跑路已启动：顶部红色横幅警示（可在此直接取消）
try {
    $ruinAt = ruin_scheduled_at();
} catch (Throwable $ex) {
    $ruinAt = 0;
}
if ($ruinAt > 0):
?>
<section class="card" style="border:1px solid rgba(220,38,38,.55);background:#FFF5F5">
    <div class="page-actions">
        <div>
            <h2 class="card-title" style="color:#dc2626">⏱ 警告：定时跑路已启动</h2>
            <p class="card-desc" style="margin-bottom:0;color:#dc2626">
                系统将于 <b><?php echo e(date('Y-m-d H:i:s', $ruinAt)); ?></b>
                （剩余 <?php $l = max(0, $ruinAt - time()); echo $l >= 86400 ? floor($l / 86400) . ' 天 ' . floor(($l % 86400) / 3600) . ' 小时 ' . floor(($l % 3600) / 60) . ' 分钟' : floor($l / 3600) . ' 小时 ' . floor(($l % 3600) / 60) . ' 分钟 ' . ($l % 60) . ' 秒'; ?>）
                自动执行跑路：删除数据库全部数据表并清空网站全部文件，不可恢复！
            </p>
        </div>
        <div class="spacer"></div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="ruin_cancel_top">
            <button class="btn-mini-plain" type="submit" style="color:#0e9f6e">立即取消</button>
        </form>
    </div>
</section>
<?php endif; ?>

<?php
// 版本更新检测：拉取 GitHub 最新 Releases 与本地版本比较（缓存 6 小时）
$updateInfo = [];
try {
    $updateInfo = update_check_fetch();
} catch (Throwable $ex) {
}
$updateOk = !empty($updateInfo['ok']);
$updateNew = $updateOk && version_compare((string)($updateInfo['version'] ?? ''), (string)PRODUCT_VERSION, '>');
?>
<section class="card" id="updateCheck" style="<?php echo $updateNew ? 'border:1px solid rgba(210,95,0,.45);background:#FFF9F0' : ''; ?>">
    <div class="page-actions">
        <div>
            <h2 class="card-title"><?php echo $updateNew ? '🚀 发现新版本' : '版本更新'; ?></h2>
            <p class="card-desc" style="margin-bottom:0">
                <?php if ($updateOk): ?>
                    当前版本 <b>V<?php echo e(PRODUCT_VERSION); ?></b>（内核 V<?php echo e(SYS_VERSION); ?>）
                    · GitHub 最新 Releases：<b>V<?php echo e($updateInfo['version']); ?></b>
                    <?php if (($updateInfo['published_at'] ?? '') !== ''): ?>
                        <span style="color:var(--text-third,#86909C)">（发布于 <?php echo e(substr((string)$updateInfo['published_at'], 0, 10)); ?>）</span>
                    <?php endif; ?>
                    <?php if ($updateNew): ?>
                        <br><b style="color:#D25F00">远程版本高于本地，建议尽快更新！</b>
                        <?php if (($updateInfo['name'] ?? '') !== ''): ?>
                            <br>Release：<?php echo e($updateInfo['name']); ?>
                        <?php endif; ?>
                        <?php if (($updateInfo['summary'] ?? '') !== ''): ?>
                        <span style="display:block;margin-top:4px;white-space:pre-wrap"><?php echo e(mb_substr(trim((string)$updateInfo['summary']), 0, 200)); ?><?php echo mb_strlen((string)$updateInfo['summary']) > 200 ? '…' : ''; ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        · 当前已是最新版本 ✓
                    <?php endif; ?>
                <?php else: ?>
                    当前版本 <b>V<?php echo e(PRODUCT_VERSION); ?></b>（内核 V<?php echo e(SYS_VERSION); ?>）
                    · 暂未能连接 GitHub Releases 检测最新版本（缓存 30 分钟后自动重试，也可手动检查）
                <?php endif; ?>
            </p>
        </div>
        <div class="spacer"></div>
        <?php if ($updateNew): ?>
        <a class="btn-mini" href="update.php" style="text-decoration:none;line-height:30px;margin-right:8px">立即更新</a>
        <?php endif; ?>
        <?php if ($updateNew && ($updateInfo['url'] ?? '') !== ''): ?>
        <a class="btn-mini-plain" href="<?php echo e((string)$updateInfo['url']); ?>" target="_blank" rel="noopener noreferrer" style="text-decoration:none;line-height:30px;margin-right:8px">前往下载</a>
        <?php endif; ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_check">
            <button class="btn-mini-plain" type="submit">检查更新</button>
        </form>
    </div>
</section>

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
