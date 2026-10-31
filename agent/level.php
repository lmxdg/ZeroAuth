<?php
// 代理商中心 - 我的等级
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/AgentLevel.php';
require dirname(__DIR__) . '/core/layout_agent.php';

$agent = agent_require_login();

if (is_post()) {
    csrf_check();
    $levelId = (int)($_POST['level_id'] ?? 0);
    $result = agent_level_purchase(2, $agent, $levelId);
    if (isset($result['error'])) {
        flash_set('err', $result['error']);
    } else {
        flash_set('ok', '等级「' . $result['level']['name'] . '」已生效'
            . ((float)$result['gift'] > 0 ? '，赠送余额 ¥' . number_format((float)$result['gift'], 2) . ' 已入账。' : '。')
            . '本次仅更新折扣与权益，名下数据不受影响。');
    }
    redirect('level.php');
}

$curLevel = null;
if ((int)$agent['level_id'] > 0) {
    $stmt = db()->prepare('SELECT * FROM `agent_level` WHERE `id`=? LIMIT 1');
    $stmt->execute([(int)$agent['level_id']]);
    $curLevel = $stmt->fetch() ?: null;
}

$levels = db()->query(
    'SELECT * FROM `agent_level` WHERE `status`=1 ORDER BY `sort` DESC, `price` ASC, `id` ASC'
)->fetchAll();

agent_header('等级升级', 'profile', $agent);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<style>

.plan-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
.plan-card{position:relative;background:var(--card);border:1px solid var(--bd);border-radius:var(--r);padding:20px;display:flex;flex-direction:column;transition:border-color .15s}
.plan-card:hover{border-color:var(--p)}
.plan-current{border-color:var(--p)}
.plan-badge{position:absolute;top:0;right:0;background:var(--p);color:#FFF;font-size:11px;line-height:20px;padding:0 10px;border-radius:0 var(--r) 0 var(--r)}
.plan-name{font-size:15px;font-weight:600;color:var(--t1)}
.plan-price{font-size:24px;font-weight:700;color:var(--p);margin:10px 0 8px;font-family:Consolas,Monaco,monospace}
.plan-price span{font-size:12px;font-weight:400;color:var(--t3);font-family:inherit;margin-left:4px}
.plan-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.plan-chip{display:inline-block;padding:1px 8px;border-radius:2px;font-size:12px;line-height:20px;background:var(--p-bg);color:var(--p)}
.plan-chip-gray{background:var(--bg);color:var(--t3)}
.plan-benefits{font-size:12px;color:var(--t3);line-height:20px;margin-bottom:16px;flex:1}
.plan-card .btn-p,.plan-card .btn-plain{width:100%}
</style>

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title" style="margin-bottom:4px">我的等级</h2>
            <p class="card-desc" style="margin-bottom:0">升级等级仅更新折扣、权益与赠送余额，名下用户、授权与财务数据不受影响。</p>
        </div>
        <div class="spacer"></div>
        <span class="tag tag-info">代理余额 ¥<?php echo e(number_format((float)$agent['balance'], 2)); ?></span>
    </div>

    <?php if ($curLevel): ?>
    <div class="alert alert-ok">
        <img src="../assets/svg/check-circle.svg" alt="">
        <span>当前等级：<strong><?php echo e((string)$curLevel['name']); ?></strong>
            ｜ 开卡折扣：<strong><?php echo e(rtrim(rtrim(number_format((float)$curLevel['discount'] * 10, 1, '.', ''), '0'), '.')); ?> 折</strong>
            ｜ 累计赠送余额 ¥<?php echo e(number_format((float)$agent['gift_balance'], 2)); ?></span>
    </div>
    <?php else: ?>
    <div class="alert alert-info">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>您尚未开通等级，按默认折扣计价。选择下方等级使用余额即可开通。</span>
    </div>
    <?php endif; ?>

    <?php if (!$levels): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无可开通的等级，请联系管理员。</span>
    </div>
    <?php else: ?>
    <div class="plan-grid">
        <?php foreach ($levels as $lv):
            $isCur = $curLevel && (int)$curLevel['id'] === (int)$lv['id']; ?>
        <div class="plan-card<?php echo $isCur ? ' plan-current' : ''; ?>">
            <?php if ($isCur): ?><span class="plan-badge">当前等级</span><?php endif; ?>
            <div class="plan-name"><?php echo e((string)$lv['name']); ?></div>
            <div class="plan-price">¥<?php echo e(number_format((float)$lv['price'], 2)); ?><span>开通价</span></div>
            <div class="plan-chips">
                <span class="plan-chip"><?php echo e(rtrim(rtrim(number_format((float)$lv['discount'] * 10, 1, '.', ''), '0'), '.')); ?> 折</span>
                <?php if ((float)$lv['gift_balance'] > 0): ?><span class="plan-chip plan-chip-gray">送 ¥<?php echo e(number_format((float)$lv['gift_balance'], 2)); ?> 余额</span><?php endif; ?>
            </div>
            <div class="plan-benefits"><?php echo $lv['benefits'] !== '' ? e((string)$lv['benefits']) : '等级权益详询管理员'; ?></div>
            <?php if ($isCur): ?>
            <button class="btn-plain" type="button" disabled style="width:100%;opacity:.6;cursor:not-allowed">当前等级</button>
            <?php else: ?>
            <form method="post" data-confirm="确定使用余额 ¥<?php echo e(number_format((float)$lv['price'], 2)); ?> <?php echo $curLevel ? '升级到' : '开通'; ?>「<?php echo e((string)$lv['name']); ?>」？<?php echo $curLevel ? '升级仅更新折扣与权益，不迁移任何数据。' : ''; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="level_id" value="<?php echo (int)$lv['id']; ?>">
                <button class="btn-p" type="submit" style="width:100%"><?php echo $curLevel ? '升级到此等级' : '立即开通'; ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="card-desc" style="margin-top:16px">余额不足？前往<a href="finance.php" style="color:#0052d9">我的财务</a>充值后再升级。</p>
    <?php endif; ?>
</section>
<?php agent_footer(); ?>
