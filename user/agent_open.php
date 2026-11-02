<?php
// 用户中心 - 升级代理商
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/AgentLevel.php';
require dirname(__DIR__) . '/core/layout_user.php';

$user = user_require_login();
$result = null;

if (is_post()) {
    csrf_check();
    $levelId = (int)($_POST['level_id'] ?? 0);
    $result = agent_level_purchase(1, $user, $levelId);
    if (isset($result['error'])) {
        flash_set('err', $result['error']);
    } else {
        audit('level', 'user_open_page', 1, (int)$user['id'], '开通等级：' . $result['level']['name']);
        flash_set('ok', '代理身份已开通，个人数据已完整迁移至代理商后台，请使用原账号密码登录代理商中心。');
        redirect('agent_open.php?ok=1');
    }
}

$levels = db()->query(
    'SELECT * FROM `agent_level` WHERE `status`=1 AND `auto_open`=1 ORDER BY `sort` DESC, `price` ASC, `id` ASC'
)->fetchAll();

$stmt = db()->prepare('SELECT `id`,`username`,`level_id`,`balance`,`gift_balance` FROM `agent` WHERE `username`=? LIMIT 1');
$stmt->execute([(string)$user['username']]);
$myAgent = $stmt->fetch() ?: null;
$curLevel = null;
if ($myAgent && (int)$myAgent['level_id'] > 0) {
    $stmt = db()->prepare('SELECT * FROM `agent_level` WHERE `id`=? LIMIT 1');
    $stmt->execute([(int)$myAgent['level_id']]);
    $curLevel = $stmt->fetch() ?: null;
}

user_header('开通代理商', 'agent_open', $user);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<style>

.plan-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.plan-card{position:relative;display:flex;flex-direction:column;background:var(--card);border:1px solid var(--bd);border-radius:var(--r);padding:20px;transition:border-color .15s}
.plan-card:hover{border-color:var(--p)}
.plan-card.plan-current{border-color:var(--p);box-shadow:inset 0 0 0 1px var(--p)}
.plan-badge{position:absolute;top:12px;right:12px;font-size:12px;color:var(--p);background:var(--p-bg);border-radius:2px;padding:1px 8px;line-height:20px}
.plan-name{font-size:16px;font-weight:600;color:var(--t1)}
.plan-price{font-size:24px;font-weight:700;color:var(--p);margin:10px 0 4px;font-family:Consolas,Monaco,monospace}
.plan-price span{font-size:12px;font-weight:400;color:var(--t3);margin-left:4px;font-family:inherit}
.plan-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.plan-chip{display:inline-block;font-size:12px;line-height:20px;padding:1px 8px;border-radius:2px;background:var(--p-bg);color:var(--p)}
.plan-chip-gray{background:var(--bg);color:var(--t3)}
.plan-benefits{font-size:12px;color:var(--t3);line-height:20px;margin-bottom:16px;white-space:pre-line;flex:1}
.plan-card .btn-p,.plan-card .btn-plain{width:100%}
</style>

<?php if (isset($_GET['ok']) && $myAgent): ?>
<section class="card">
    <div class="alert alert-ok" style="margin-bottom:0">
        <img src="../assets/svg/check-circle.svg" alt="">
        <span>代理身份已生效，当前等级：<strong><?php echo $curLevel ? e((string)$curLevel['name']) : '默认等级'; ?></strong>。账号与密码保持不变，<a href="../agent/index.php" style="color:var(--p)">前往代理商中心 →</a></span>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">开通代理商</h2>
            <p class="card-desc" style="margin-bottom:0">使用账户余额开通代理等级，享受开卡折扣价与专属权益。开通后个人数据（授权、工单）自动完整迁移至代理商后台，账号密码保持不变。</p>
        </div>
        <div class="spacer"></div>
        <span class="tag tag-info">当前余额 ¥<?php echo e(number_format((float)$user['balance'], 2)); ?></span>
    </div>

    <?php if ($myAgent): ?>
    <div class="alert alert-info" style="margin-bottom:16px">
        <img src="../assets/svg/menu-agent.svg" alt="">
        <span>您已开通代理：账号 <strong><?php echo e((string)$myAgent['username']); ?></strong>
            · 当前等级：<strong><?php echo $curLevel ? e((string)$curLevel['name']) : '默认等级'; ?></strong>
            · 代理余额 ¥<?php echo e(number_format((float)$myAgent['balance'], 2)); ?>
            。选择更高等级即可升级，升级仅更新折扣 / 权益 / 赠送余额，不影响已有数据。</span>
    </div>
    <?php endif; ?>

    <?php if (!$levels): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无可开通的代理等级，请稍后再来或联系管理员。</span>
    </div>
    <?php else: ?>
    <div class="plan-grid">
        <?php foreach ($levels as $lv):
            $isCur = $myAgent && (int)$myAgent['level_id'] === (int)$lv['id']; ?>
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
            <button class="btn-plain" type="button" disabled style="opacity:.6;cursor:not-allowed">当前等级</button>
            <?php else: ?>
            <form method="post" data-confirm="确定使用余额 ¥<?php echo e(number_format((float)$lv['price'], 2)); ?> <?php echo $myAgent ? '升级到' : '开通'; ?>「<?php echo e((string)$lv['name']); ?>」？<?php echo $myAgent ? '升级仅更新折扣与权益，不迁移数据。' : '开通后个人数据将自动迁移至代理商后台。'; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="level_id" value="<?php echo (int)$lv['id']; ?>">
                <button class="btn-p" type="submit"><?php echo $myAgent ? '升级到此等级' : '立即开通'; ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="card-desc" style="margin-top:16px">余额不足？前往<a href="finance.php" style="color:var(--p)">我的财务</a>充值后再开通。</p>
    <?php endif; ?>
</section>
<?php user_footer(); ?>
