<?php
// 管理员后台 - 折扣管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$act   = (string)($_GET['act'] ?? 'list');
$id    = (int)($_GET['id'] ?? 0);
$error = '';

$typeNames = [1 => '折扣', 2 => '满减', 3 => '首购优惠', 4 => '批量优惠'];

try {
    db()->query("ALTER TABLE `discount_activity` ADD COLUMN `app_ids` varchar(255) NOT NULL DEFAULT '' COMMENT '适用产品ID（逗号分隔，空=全部产品）' AFTER `min_amount`");
} catch (Throwable $ex) {
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $actId    = (int)($_POST['id'] ?? 0);
        $name     = trim((string)($_POST['name'] ?? ''));
        $type     = (int)($_POST['type'] ?? 1);
        $value    = round((float)($_POST['value'] ?? 0), 2);
        $minAmt   = round((float)($_POST['min_amount'] ?? 0), 2);
        $start    = strtotime((string)($_POST['start_time'] ?? '')) ?: 0;
        $end      = strtotime((string)($_POST['end_time'] ?? '')) ?: 0;
        $status   = (int)($_POST['status'] ?? 1) === 1 ? 1 : 2;
        $appIdsArr = array_map('intval', (array)($_POST['app_ids'] ?? []));
        $appIdsArr = array_values(array_unique(array_filter($appIdsArr, fn ($v) => $v > 0)));
        $appIdsStr = $appIdsArr ? implode(',', $appIdsArr) : '';

        if ($name === '' || mb_strlen($name) > 64) {
            $error = '活动名称不能为空且不超过 64 字。';
        } elseif (!isset($typeNames[$type])) {
            $error = '活动类型不合法。';
        } elseif (in_array($type, [1, 4], true) && ($value <= 0 || $value >= 1)) {
            $error = '折扣率需在 0 ~ 1 之间（如 0.8 表示八折）。';
        } elseif (in_array($type, [2, 3], true) && ($value <= 0 || $value > 999999)) {
            $error = '优惠金额需大于 0。';
        } elseif ($type === 2 && $minAmt <= 0) {
            $error = '满减活动需填写最低满足金额。';
        } elseif ($type === 4 && ((int)$minAmt < 2 || $minAmt > 100)) {
            $error = '批量优惠需填写满足张数（2 ~ 100）。';
        } elseif ($end > 0 && $start > 0 && $end < $start) {
            $error = '结束时间不能早于开始时间。';
        } else {
            if ($actId > 0) {
                $stmt = db()->prepare('UPDATE `discount_activity` SET `name`=?,`type`=?,`value`=?,`min_amount`=?,`app_ids`=?,`start_time`=?,`end_time`=?,`status`=? WHERE `id`=?');
                $stmt->execute([$name, $type, number_format($value, 2, '.', ''), number_format($minAmt, 2, '.', ''), $appIdsStr, $start, $end, $status, $actId]);
                audit('discount', 'update', 1, (int)$admin['id'], '活动：' . $name . ' 适用产品：' . ($appIdsStr === '' ? '全部' : $appIdsStr));
                flash_set('ok', '活动已保存。');
            } else {
                $stmt = db()->prepare('INSERT INTO `discount_activity` (`name`,`type`,`value`,`min_amount`,`app_ids`,`start_time`,`end_time`,`status`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$name, $type, number_format($value, 2, '.', ''), number_format($minAmt, 2, '.', ''), $appIdsStr, $start, $end, $status, time()]);
                audit('discount', 'create', 1, (int)$admin['id'], '活动：' . $name . ' 适用产品：' . ($appIdsStr === '' ? '全部' : $appIdsStr));
                flash_set('ok', '活动已创建。');
            }
            redirect('discount_manage.php');
        }
        $act = $actId > 0 ? 'edit' : 'new';
        $id  = $actId;
    }

    if ($action === 'toggle') {
        $actId = (int)($_POST['id'] ?? 0);
        $to = (int)($_POST['to'] ?? 1) === 1 ? 1 : 2;
        db()->prepare('UPDATE `discount_activity` SET `status`=? WHERE `id`=?')->execute([$to, $actId]);
        audit('discount', 'toggle', 1, (int)$admin['id'], '活动ID：' . $actId . ' 状态：' . ($to === 1 ? '启用' : '停用'));
        flash_set('ok', $to === 1 ? '活动已启用。' : '活动已停用。');
        redirect('discount_manage.php');
    }

    if ($action === 'delete') {
        $actId = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM `discount_activity` WHERE `id`=?')->execute([$actId]);
        audit('discount', 'delete', 1, (int)$admin['id'], '活动ID：' . $actId);
        flash_set('ok', '活动已删除。');
        redirect('discount_manage.php');
    }
}

$disc = null;
if ($id > 0 && $act === 'edit') {
    $stmt = db()->prepare('SELECT * FROM `discount_activity` WHERE `id`=?');
    $stmt->execute([$id]);
    $disc = $stmt->fetch();
    if (!$disc) {
        $error = '活动不存在或已删除。';
        $act = 'list';
    }
}

$discs = [];
$appNameMap = [];
if ($act === 'list') {
    $discs = db()->query('SELECT * FROM `discount_activity` ORDER BY `id` DESC')->fetchAll();
}
foreach (db()->query('SELECT `id`,`name` FROM `app` ORDER BY `id` DESC')->fetchAll() as $a) {
    $appNameMap[(int)$a['id']] = (string)$a['name'];
}
$now = time();

admin_header('折扣活动', 'discount', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif;
if ($error !== ''): ?>
<div class="alert alert-error">
    <img src="../assets/svg/warn-circle.svg" alt="">
    <span><?php echo e($error); ?></span>
</div>
<?php endif; ?>

<?php 
if ($act === 'list'): ?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">折扣活动</h2>
            <p class="card-desc">用户下单时在生效期内自动匹配优惠金额最大的一项，并记入订单的优惠明细。多活动同时生效时互不叠加。</p>
        </div>
        <div class="spacer"></div>
        <a class="btn-primary" href="discount_manage.php?act=new">新建活动</a>
    </div>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>活动名称</th><th>类型</th><th>优惠内容</th><th>适用产品</th><th>有效期</th><th>状态</th><th style="width:200px">操作</th></tr></thead>
            <tbody>
            <?php if (!$discs): ?>
            <tr><td colspan="7"><div class="empty-state">暂无活动，点击右上角「新建活动」创建第一个营销活动。</div></td></tr>
            <?php endif; ?>
            <?php foreach ($discs as $d): ?>
            <?php
                $running = (int)$d['status'] === 1 && (int)$d['start_time'] <= $now && ((int)$d['end_time'] === 0 || (int)$d['end_time'] >= $now);
                $val = (int)$d['type'] === 1 || (int)$d['type'] === 4
                    ? ((float)$d['value'] * 10) . ' 折'
                    : '减 ¥' . e($d['value']);
                if ((int)$d['type'] === 2) {
                    $val = '满 ¥' . e($d['min_amount']) . ' ' . $val;
                } elseif ((int)$d['type'] === 4) {
                    $val = '满 ' . (int)$d['min_amount'] . ' 张 ' . $val;
                } elseif ((int)$d['type'] === 3) {
                    $val = '首单 ' . $val . ((float)$d['min_amount'] > 0 ? '（满 ¥' . e($d['min_amount']) . '）' : '');
                }
                $scopeIds = array_map('intval', array_filter(explode(',', (string)($d['app_ids'] ?? ''))));
                $scopeText = '全部产品';
                if ($scopeIds) {
                    $names = [];
                    foreach ($scopeIds as $sid) {
                        $names[] = $appNameMap[$sid] ?? ('产品#' . $sid);
                    }
                    $scopeText = implode('、', $names);
                }
            ?>
            <tr>
                <td class="cell-main"><?php echo e($d['name']); ?></td>
                <td><span class="tag tag-info"><?php echo $typeNames[(int)$d['type']] ?? '未知'; ?></span></td>
                <td><?php echo $val; ?></td>
                <td class="cell-sub"><?php echo e($scopeText); ?></td>
                <td class="cell-sub">
                    <?php echo (int)$d['start_time'] > 0 ? date('Y-m-d H:i', (int)$d['start_time']) : '立即'; ?>
                    ~
                    <?php echo (int)$d['end_time'] > 0 ? date('Y-m-d H:i', (int)$d['end_time']) : '长期'; ?>
                </td>
                <td>
                    <span class="tag <?php echo $running ? 'tag-ok' : 'tag-off'; ?>">
                        <?php echo $running ? '进行中' : ((int)$d['status'] === 1 ? '未开始/已结束' : '已停用'); ?>
                    </span>
                </td>
                <td>
                    <a class="btn-mini" href="discount_manage.php?act=edit&id=<?php echo (int)$d['id']; ?>">编辑</a>
                    <form method="post" style="display:inline" data-confirm="确定<?php echo (int)$d['status'] === 1 ? '停用' : '启用'; ?>活动「<?php echo e($d['name']); ?>」吗？">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                        <input type="hidden" name="to" value="<?php echo (int)$d['status'] === 1 ? 2 : 1; ?>">
                        <button class="btn-mini-plain" type="submit"><?php echo (int)$d['status'] === 1 ? '停用' : '启用'; ?></button>
                    </form>
                    <form method="post" style="display:inline" data-confirm="确定删除活动「<?php echo e($d['name']); ?>」吗？删除后不可恢复。">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                        <button class="btn-danger-mini" type="submit">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php 
elseif ($act === 'new' || $act === 'edit'):
    $type = (int)($disc['type'] ?? 1);
?>
<section class="card">
    <h2 class="card-title"><?php echo $act === 'new' ? '新建活动' : '编辑活动'; ?></h2>
    <p class="card-desc">选择活动类型后表单会自动切换提示，并实时预览优惠效果；适用产品不勾选表示全部产品生效。</p>
    <form method="post" class="form" style="max-width:640px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int)($disc['id'] ?? 0); ?>">
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">活动名称</label>
                <input class="form-input" type="text" name="name" placeholder="请输入活动名称" maxlength="64" value="<?php echo e($disc['name'] ?? ''); ?>" required>
            </div>
            <div class="form-item">
                <label class="form-label">活动类型</label>
                <select class="form-input" name="type" id="discType" required style="display:none">
                    <?php foreach ($typeNames as $t => $tName): ?>
                    <option value="<?php echo $t; ?>" <?php echo $type === $t ? 'selected' : ''; ?>><?php echo $tName; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="disc-chips" id="discTypeChips">
                    <?php foreach ($typeNames as $t => $tName): ?>
                    <a class="chip-btn <?php echo $type === $t ? 'chip-active' : ''; ?>" href="javascript:;" data-type="<?php echo $t; ?>"><?php echo $tName; ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label" id="discValueLabel">折扣率</label>
                <input class="form-input" type="number" name="value" id="discValue" placeholder="请输入折扣率 / 优惠金额" min="0" max="999999" step="0.01" value="<?php echo e($disc['value'] ?? '0.80'); ?>" required>
                <div class="form-hint" id="discValueHint"></div>
                <div class="disc-chips" id="discChips" style="margin-top:8px"></div>
            </div>
            <div class="form-item">
                <label class="form-label" id="discMinLabel">最低满足金额</label>
                <input class="form-input" type="number" name="min_amount" id="discMin" placeholder="请输入最低满足金额 / 满足张数" min="0" max="999999" step="0.01" value="<?php echo e($disc['min_amount'] ?? '0.00'); ?>">
                <div class="form-hint" id="discMinHint"></div>
            </div>
        </div>
        <div class="form-item">
            <label class="form-label">优惠效果预览</label>
            <div class="disc-preview" id="discPreview"></div>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">开始时间</label>
                <input class="form-input" type="datetime-local" name="start_time" value="<?php echo (int)($disc['start_time'] ?? 0) > 0 ? date('Y-m-d\TH:i', (int)$disc['start_time']) : ''; ?>">
                <div class="form-hint">留空表示立即开始。</div>
            </div>
            <div class="form-item">
                <label class="form-label">结束时间</label>
                <input class="form-input" type="datetime-local" name="end_time" value="<?php echo (int)($disc['end_time'] ?? 0) > 0 ? date('Y-m-d\TH:i', (int)$disc['end_time']) : ''; ?>">
                <div class="form-hint">留空表示长期有效。</div>
            </div>
        </div>
        <div class="form-item">
            <label class="form-label">适用产品</label>
            <?php
            $editScopeIds = array_map('intval', array_filter(explode(',', (string)($disc['app_ids'] ?? ''))));
            if (!$appNameMap): ?>
            <div class="app-picker-empty">暂无产品，保存后将作用于全部产品。</div>
            <?php else: ?>
            <div class="app-picker" id="appPicker">
                <?php foreach ($appNameMap as $appIdKey => $appName): ?>
                <label class="app-picker-item">
                    <input type="checkbox" name="app_ids[]" value="<?php echo (int)$appIdKey; ?>" class="scope-check" <?php echo in_array((int)$appIdKey, $editScopeIds, true) ? 'checked' : ''; ?>>
                    <span><?php echo e($appName); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="disc-chips" style="margin-top:8px">
                <a class="chip-btn" href="javascript:;" id="scopeAll">全选</a>
                <a class="chip-btn" href="javascript:;" id="scopeNone">不限产品</a>
            </div>
            <?php endif; ?>
            <div class="form-hint">不勾选任何产品时活动对全部产品生效；勾选后仅对所选产品下单时生效。</div>
        </div>
        <div class="form-item">
            <label class="form-label">状态</label>
            <select class="form-input" name="status">
                <option value="1" <?php echo (int)($disc['status'] ?? 1) === 1 ? 'selected' : ''; ?>>启用</option>
                <option value="2" <?php echo (int)($disc['status'] ?? 1) === 2 ? 'selected' : ''; ?>>停用</option>
            </select>
        </div>
        <div class="page-actions">
            <a class="btn-plain" href="discount_manage.php">返回列表</a>
            <button class="btn-primary" type="submit">保存活动</button>
        </div>
    </form>
    <script>
    (function () {
        var type = document.getElementById('discType');
        var value = document.getElementById('discValue');
        var min = document.getElementById('discMin');
        var valueLabel = document.getElementById('discValueLabel');
        var valueHint = document.getElementById('discValueHint');
        var minLabel = document.getElementById('discMinLabel');
        var minHint = document.getElementById('discMinHint');
        var chipsBox = document.getElementById('discChips');
        var preview = document.getElementById('discPreview');

        var conf = {
            1: { vLabel: '折扣率', vHint: '填 0.01 ~ 0.99，例如 0.8 表示八折。', mLabel: '最低满足金额（可选）', mHint: '可留 0，表示不限下单金额。', chips: [['9 折', 0.9], ['8.5 折', 0.85], ['8 折', 0.8], ['7 折', 0.7], ['5 折', 0.5]] },
            2: { vLabel: '减免金额（元）', vHint: '订单满下方金额时，减免此金额。', mLabel: '满足金额（元）', mHint: '订单金额达到该值才可享受减免。', chips: [['减 5', 5], ['减 10', 10], ['减 20', 20], ['减 50', 50]] },
            3: { vLabel: '首单减免金额（元）', vHint: '用户首笔订单直接减免此金额。', mLabel: '最低下单金额（可选）', mHint: '可留 0，表示首单不限金额。', chips: [['减 5', 5], ['减 10', 10], ['减 20', 20], ['减 50', 50]] },
            4: { vLabel: '折扣率', vHint: '一次购买张数达标后按此折扣结算，例如 0.8 表示八折。', mLabel: '满足张数', mHint: '填写 2 ~ 100，例如填 5 表示买满 5 张生效。', chips: [['9 折', 0.9], ['8.5 折', 0.85], ['8 折', 0.8], ['7 折', 0.7], ['5 折', 0.5]] }
        };

        function fmt(n) { return '¥' + (Math.round(n * 100) / 100).toFixed(2); }

        function render() {
            var c = conf[type.value] || conf[1];
            valueLabel.textContent = c.vLabel;
            valueHint.textContent = c.vHint;
            minLabel.textContent = c.mLabel;
            minHint.textContent = c.mHint;

            document.querySelectorAll('#discTypeChips .chip-btn').forEach(function (chip) {
                chip.classList.toggle('chip-active', chip.getAttribute('data-type') === type.value);
            });

            chipsBox.innerHTML = '';
            c.chips.forEach(function (ch) {
                var a = document.createElement('a');
                a.href = 'javascript:;';
                a.className = 'chip-btn';
                a.textContent = ch[0];
                a.addEventListener('click', function () {
                    value.value = ch[1];
                    if (type.value === '2' && parseFloat(min.value || 0) <= parseFloat(ch[1])) {
                        min.value = (Math.round(parseFloat(ch[1]) * 2 * 100) / 100).toFixed(2);
                    }
                    paint();
                });
                chipsBox.appendChild(a);
            });

            paint();
        }

        function paint() {
            var v = parseFloat(value.value || 0);
            var m = parseFloat(min.value || 0);
            var t = type.value;
            var text = '';
            if (t === '1') {
                text = (v > 0 && v < 1)
                    ? '示例：下单 ' + fmt(100) + ' → ' + fmt(100 * v) + '，立省 ' + fmt(100 * (1 - v))
                    : '折扣率需在 0.01 ~ 0.99 之间（0.8 = 八折）。';
            } else if (t === '2') {
                var base = Math.max(m, v * 2, 1);
                text = (v > 0 && m > 0)
                    ? '示例：满 ' + fmt(m) + ' 减 ' + fmt(v) + '，下单 ' + fmt(base) + ' → ' + fmt(Math.max(0, base - v))
                    : '请填写减免金额与满足金额。';
            } else if (t === '3') {
                text = (v > 0)
                    ? '示例：用户首单' + (m > 0 ? '满 ' + fmt(m) + ' ' : '') + '减免 ' + fmt(v)
                    : '请填写首单减免金额。';
            } else {
                var n = m >= 2 ? Math.ceil(m) : 5;
                text = (v > 0 && v < 1 && m >= 2)
                    ? '示例：一次购买 ' + n + ' 张共 ' + fmt(100 * n) + ' → ' + fmt(100 * n * v) + '，立省 ' + fmt(100 * n * (1 - v))
                    : '折扣率填 0.01 ~ 0.99，满足张数填 2 ~ 100。';
            }
            preview.textContent = text;
        }

        type.addEventListener('change', render);
        document.querySelectorAll('#discTypeChips .chip-btn').forEach(function (chip) {
            chip.addEventListener('click', function () {
                type.value = chip.getAttribute('data-type');
                render();
            });
        });
        value.addEventListener('input', paint);
        min.addEventListener('input', paint);

        document.getElementById('scopeAll').addEventListener('click', function () {
            document.querySelectorAll('.scope-check').forEach(function (cb) { cb.checked = true; });
        });
        document.getElementById('scopeNone').addEventListener('click', function () {
            document.querySelectorAll('.scope-check').forEach(function (cb) { cb.checked = false; });
        });

        render();
    })();
    </script>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
