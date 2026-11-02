<?php
// 管理员后台 - 套餐管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$act   = (string)($_GET['act'] ?? 'list');
if ($act !== 'list') {
    redirect('package_manage.php');
}
$error = '';

$methodNames = [1 => '单域名授权', 2 => '泛域名授权', 3 => 'IP授权', 4 => '密钥授权'];

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $idPost       = (int)($_POST['id'] ?? 0);
        $appId        = (int)($_POST['app_id'] ?? 0);
        $name         = trim((string)($_POST['name'] ?? ''));
        $authMethod   = (int)($_POST['auth_method'] ?? 0);
        $durationDays = (int)($_POST['duration_days'] ?? 0);
        $price        = round((float)($_POST['price'] ?? 0), 2);
        $maxSites     = (int)($_POST['max_sites'] ?? 0);
        $sort         = (int)($_POST['sort'] ?? 0);
        $status       = (int)($_POST['status'] ?? 0) === 1 ? 1 : 0;
        $remark       = trim((string)($_POST['remark'] ?? ''));

        try {
            $stmt = db()->prepare('SELECT `id`,`name`,`auth_methods` FROM `app` WHERE `id`=?');
            $stmt->execute([$appId]);
            $app = $stmt->fetch();
            if (!$app) {
                throw new RuntimeException('所选应用不存在，请重新选择。');
            }
            $appMethods = array_map('intval', array_filter(explode(',', (string)$app['auth_methods'])));
            if (!in_array($authMethod, [0, 1, 2, 3, 4], true)) {
                throw new RuntimeException('授权方式不合法。');
            }
            if ($authMethod > 0 && !in_array($authMethod, $appMethods, true)) {
                throw new RuntimeException('授权方式与所选应用不匹配，请重新选择。');
            }
            if ($name === '' || mb_strlen($name) > 64) {
                throw new RuntimeException('套餐名称不能为空且不超过 64 字。');
            }
            if ($durationDays < 0 || $durationDays > 36500) {
                throw new RuntimeException('授权天数需在 0 ~ 36500 之间（0 表示永久）。');
            }
            if ($price < 0 || $price > 999999) {
                throw new RuntimeException('价格不合法（0 ~ 999999）。');
            }
            if ($maxSites < 0 || $maxSites > 99999) {
                throw new RuntimeException('最大站点数需在 0 ~ 99999 之间（0 表示不限制）。');
            }
            if (mb_strlen($remark) > 255) {
                throw new RuntimeException('备注不能超过 255 字。');
            }

            $licenseType = $durationDays > 0 ? 1 : 3;
            if ($idPost > 0) {
                $stmt = db()->prepare('UPDATE `app_package` SET `app_id`=?,`name`=?,`license_type`=?,`auth_method`=?,`duration_days`=?,`total_count`=0,`max_sites`=?,`price`=?,`sort`=?,`status`=?,`remark`=? WHERE `id`=?');
                $stmt->execute([$appId, $name, $licenseType, $authMethod, $durationDays, $maxSites, number_format($price, 2, '.', ''), $sort, $status, $remark, $idPost]);
                audit('package', 'update', 1, (int)$admin['id'], '套餐ID：' . $idPost . ' 名称：' . $name . ' 应用ID：' . $appId);
                flash_set('ok', '套餐已保存。');
            } else {
                $stmt = db()->prepare('INSERT INTO `app_package` (`app_id`,`name`,`license_type`,`auth_method`,`duration_days`,`total_count`,`max_sites`,`price`,`original_price`,`sort`,`status`,`remark`,`created_at`) VALUES (?,?,?,?,?,?,?,?,"0.00",?,?,?,?)');
                $stmt->execute([$appId, $name, $licenseType, $authMethod, $durationDays, 0, $maxSites, number_format($price, 2, '.', ''), $sort, $status, $remark, time()]);
                audit('package', 'create', 1, (int)$admin['id'], '套餐：' . $name . ' 应用ID：' . $appId);
                flash_set('ok', '套餐「' . $name . '」已创建。');
            }
            redirect('package_manage.php');
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }

    if ($action === 'toggle') {
        $idPost = (int)($_POST['id'] ?? 0);
        $to     = (int)($_POST['to'] ?? 1) === 1 ? 1 : 0;
        $stmt = db()->prepare('UPDATE `app_package` SET `status`=? WHERE `id`=?');
        $stmt->execute([$to, $idPost]);
        audit('package', 'toggle', 1, (int)$admin['id'], '套餐ID：' . $idPost . ' 状态：' . ($to === 1 ? '启用' : '停用'));
        flash_set('ok', $to === 1 ? '套餐已启用。' : '套餐已停用，用户端不再展示该套餐。');
        redirect('package_manage.php');
    }

    if ($action === 'delete') {
        $idPost = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `name` FROM `app_package` WHERE `id`=?');
        $stmt->execute([$idPost]);
        $name = (string)$stmt->fetchColumn();
        if ($name !== '') {
            db()->prepare('DELETE FROM `app_package` WHERE `id`=?')->execute([$idPost]);
            audit('package', 'delete', 1, (int)$admin['id'], '套餐ID：' . $idPost . ' 名称：' . $name);
            flash_set('ok', '套餐「' . $name . '」已删除。');
        }
        redirect('package_manage.php');
    }
}

$apps = db()->query('SELECT `id`,`name`,`status`,`auth_methods` FROM `app` ORDER BY `id` DESC')->fetchAll();

$rows = db()->query(
    'SELECT p.*, a.`name` AS `app_name` FROM `app_package` p LEFT JOIN `app` a ON a.`id`=p.`app_id` '
    . 'ORDER BY p.`sort` DESC, p.`id` DESC'
)->fetchAll();

admin_header('套餐管理', 'package', $admin);
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

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title" style="margin-bottom:0">套餐列表</h2>
            <p class="card-desc" style="margin-bottom:0">套餐在用户端「购买授权」页按应用分组展示；授权天数 0 表示永久，最大站点数 0 表示不限制。</p>
        </div>
        <div class="spacer"></div>
        <button class="btn-primary" type="button" id="btnNewPkg">新增套餐</button>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无数据</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>应用</th><th>套餐名</th><th>授权方式</th><th>授权天数</th><th>最大站点数</th><th>售价</th><th>排序</th><th style="width:70px">状态</th><th style="width:190px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                $days = (int)$row['duration_days'];
            ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td><?php echo $row['app_name'] !== null ? e($row['app_name']) : '<span class="cell-sub">应用已删除</span>'; ?></td>
                <td class="cell-main"><?php echo e($row['name']); ?></td>
                <td class="cell-sub"><?php echo (int)$row['auth_method'] > 0 ? ($methodNames[(int)$row['auth_method']] ?? '未知') : '不限制'; ?></td>
                <td><?php echo $days > 0 ? $days . ' 天' : '永久'; ?></td>
                <td><?php echo (int)$row['max_sites'] > 0 ? (int)$row['max_sites'] . ' 个' : '不限制'; ?></td>
                <td>¥<?php echo e(number_format((float)$row['price'], 2)); ?></td>
                <td><?php echo (int)$row['sort']; ?></td>
                <td><span class="tag <?php echo (int)$row['status'] === 1 ? 'tag-ok' : 'tag-off'; ?>"><?php echo (int)$row['status'] === 1 ? '启用' : '停用'; ?></span></td>
                <td>
                    <button class="btn-mini-plain pkg-edit" type="button" style="line-height:26px"
                        data-id="<?php echo (int)$row['id']; ?>"
                        data-app="<?php echo (int)$row['app_id']; ?>"
                        data-name="<?php echo e($row['name']); ?>"
                        data-method="<?php echo (int)$row['auth_method']; ?>"
                        data-days="<?php echo $days; ?>"
                        data-price="<?php echo e(number_format((float)$row['price'], 2, '.', '')); ?>"
                        data-sites="<?php echo (int)$row['max_sites']; ?>"
                        data-sort="<?php echo (int)$row['sort']; ?>"
                        data-status="<?php echo (int)$row['status']; ?>"
                        data-remark="<?php echo e($row['remark']); ?>">编辑</button>
                    <form method="post" style="display:inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <input type="hidden" name="to" value="<?php echo (int)$row['status'] === 1 ? 0 : 1; ?>">
                        <button class="btn-mini-plain" type="submit" data-confirm="确定<?php echo (int)$row['status'] === 1 ? '停用' : '启用'; ?>套餐「<?php echo e($row['name']); ?>」吗？"><?php echo (int)$row['status'] === 1 ? '停用' : '启用'; ?></button>
                    </form>
                    <form method="post" style="display:inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-danger-mini" type="submit" data-confirm="确定删除套餐「<?php echo e($row['name']); ?>」吗？删除后不可恢复。">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<div class="modal-mask" id="pkgModal">
    <div class="modal-box modal-form">
        <div class="modal-form-head">
            <span class="t" id="pkgModalTitle">新增套餐</span>
            <button type="button" class="modal-x" id="pkgModalClose" aria-label="关闭">&times;</button>
        </div>
        <form method="post" class="form" style="max-width:none" id="pkgForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="0" id="pkgId">
            <div class="form-h">
                <label class="form-label"><i class="req">*</i>所属应用</label>
                <div class="form-ctrl">
                    <select class="form-input" name="app_id" id="pkgApp" required>
                        <?php foreach ($apps as $a): ?>
                        <option value="<?php echo (int)$a['id']; ?>" data-methods="<?php echo e($a['auth_methods']); ?>">
                            <?php echo e($a['name']); ?><?php (int)$a['status'] !== 1 && print('（已停用）'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label"><i class="req">*</i>套餐名称</label>
                <div class="form-ctrl">
                    <input class="form-input" type="text" name="name" id="pkgName" maxlength="64" placeholder="请输入套餐名称" required>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label">授权方式</label>
                <div class="form-ctrl">
                    <select class="form-input" name="auth_method" id="pkgAuthMethod"></select>
                    <div class="form-hint" id="pkgAuthHint"></div>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label">快捷时长</label>
                <div class="form-ctrl">
                    <div class="quick-days" id="pkgQuick">
                        <button type="button" data-days="30">1个月</button>
                        <button type="button" data-days="90">3个月</button>
                        <button type="button" data-days="365">1年</button>
                        <button type="button" data-days="0">永久</button>
                    </div>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label"><i class="req">*</i>授权天数</label>
                <div class="form-ctrl">
                    <div class="stepper">
                        <input class="form-input" type="text" inputmode="numeric" name="duration_days" id="pkgDays" value="0" required>
                        <span class="step-btns">
                            <button type="button" class="step-up" data-target="pkgDays" data-step="1" aria-label="增加">▲</button>
                            <button type="button" class="step-down" data-target="pkgDays" data-step="1" aria-label="减少">▼</button>
                        </span>
                    </div>
                    <span class="step-hint">0 表示永久</span>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label"><i class="req">*</i>价格</label>
                <div class="form-ctrl">
                    <div class="stepper">
                        <input class="form-input" type="text" inputmode="decimal" name="price" id="pkgPrice" value="0.00" required>
                        <span class="step-btns">
                            <button type="button" class="step-up" data-target="pkgPrice" data-step="1" aria-label="增加">▲</button>
                            <button type="button" class="step-down" data-target="pkgPrice" data-step="1" aria-label="减少">▼</button>
                        </span>
                    </div>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label">最大站点数</label>
                <div class="form-ctrl">
                    <div class="stepper">
                        <input class="form-input" type="text" inputmode="numeric" name="max_sites" id="pkgSites" value="0">
                        <span class="step-btns">
                            <button type="button" class="step-up" data-target="pkgSites" data-step="1" aria-label="增加">▲</button>
                            <button type="button" class="step-down" data-target="pkgSites" data-step="1" aria-label="减少">▼</button>
                        </span>
                    </div>
                    <span class="step-hint">0 表示不限制</span>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label">排序</label>
                <div class="form-ctrl">
                    <div class="stepper">
                        <input class="form-input" type="text" inputmode="numeric" name="sort" id="pkgSort" value="0">
                        <span class="step-btns">
                            <button type="button" class="step-up" data-target="pkgSort" data-step="1" aria-label="增加">▲</button>
                            <button type="button" class="step-down" data-target="pkgSort" data-step="1" aria-label="减少">▼</button>
                        </span>
                    </div>
                    <span class="step-hint">数值越大越靠前</span>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label">状态</label>
                <div class="form-ctrl">
                    <div class="switch-line">
                        <span>禁用</span>
                        <label class="pref-switch"><input type="checkbox" name="status" value="1" checked id="pkgStatus"><i></i></label>
                        <span>启用</span>
                    </div>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label">备注</label>
                <div class="form-ctrl">
                    <textarea class="form-input" name="remark" id="pkgRemark" maxlength="255" rows="3" placeholder="可选"></textarea>
                </div>
            </div>
            <div class="modal-actions" style="margin-top:22px">
                <button class="btn-plain" type="button" id="pkgModalCancel">取消</button>
                <button class="btn-primary" type="submit">确定</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('pkgModal');
    if (!modal) return;
    var title = document.getElementById('pkgModalTitle');
    var form = document.getElementById('pkgForm');
    var idInput = document.getElementById('pkgId');
    var appSel = document.getElementById('pkgApp');
    var nameInput = document.getElementById('pkgName');
    var authSel = document.getElementById('pkgAuthMethod');
    var authHint = document.getElementById('pkgAuthHint');
    var daysInput = document.getElementById('pkgDays');
    var priceInput = document.getElementById('pkgPrice');
    var sitesInput = document.getElementById('pkgSites');
    var sortInput = document.getElementById('pkgSort');
    var statusInput = document.getElementById('pkgStatus');
    var remarkInput = document.getElementById('pkgRemark');
    var quickBox = document.getElementById('pkgQuick');

    var methodNames = { 1: '单域名授权', 2: '泛域名授权', 3: 'IP授权', 4: '密钥授权' };
    var close = function () { modal.classList.remove('show'); };

    function appMethods() {
        var opt = appSel.options[appSel.selectedIndex];
        var raw = (opt && opt.getAttribute('data-methods')) || '';
        return raw ? raw.split(',').filter(function (v) { return ['1', '2', '3', '4'].indexOf(v) >= 0; }) : [];
    }

    function rebuildAuth(sel) {
        var list = appMethods();
        authSel.innerHTML = '';
        if (list.length) {
            list.forEach(function (v) {
                var o = document.createElement('option');
                o.value = v;
                o.textContent = methodNames[v];
                authSel.appendChild(o);
            });
            if (list.indexOf(String(sel)) >= 0) authSel.value = String(sel);
            authHint.textContent = '仅显示当前应用已配置的授权方式：' + list.map(function (v) { return methodNames[v]; }).join('、');
        } else {
            var o = document.createElement('option');
            o.value = '0';
            o.textContent = '不限制（发卡时选择）';
            authSel.appendChild(o);
            authHint.textContent = '该应用未开放授权方式，暂只能选择不限制。';
        }
    }

    function syncQuick() {
        var d = parseInt(daysInput.value, 10);
        if (isNaN(d)) d = -1;
        Array.prototype.forEach.call(quickBox.children, function (b) {
            b.classList.toggle('active', parseInt(b.getAttribute('data-days'), 10) === d);
        });
    }

    document.getElementById('btnNewPkg').addEventListener('click', function () {
        title.textContent = '新增套餐';
        idInput.value = '0';
        nameInput.value = '';
        daysInput.value = '0';
        priceInput.value = '0.00';
        sitesInput.value = '0';
        sortInput.value = '0';
        statusInput.checked = true;
        remarkInput.value = '';
        rebuildAuth(0);
        syncQuick();
        modal.classList.add('show');
        nameInput.focus();
    });

    function openEdit(btn) {
        title.textContent = '编辑套餐';
        idInput.value = btn.getAttribute('data-id') || '0';
        appSel.value = btn.getAttribute('data-app');
        if (appSel.selectedIndex < 0) appSel.selectedIndex = 0;
        nameInput.value = btn.getAttribute('data-name') || '';
        rebuildAuth(parseInt(btn.getAttribute('data-method') || '0', 10));
        daysInput.value = btn.getAttribute('data-days') || '0';
        priceInput.value = btn.getAttribute('data-price') || '0.00';
        sitesInput.value = btn.getAttribute('data-sites') || '0';
        sortInput.value = btn.getAttribute('data-sort') || '0';
        statusInput.checked = btn.getAttribute('data-status') === '1';
        remarkInput.value = btn.getAttribute('data-remark') || '';
        syncQuick();
        modal.classList.add('show');
        nameInput.focus();
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (t && t.closest && t.closest('.pkg-edit')) {
            openEdit(t.closest('.pkg-edit'));
            return;
        }
        var stepBtn = t && t.closest ? t.closest('.step-btns button') : null;
        if (stepBtn) {
            var input = document.getElementById(stepBtn.getAttribute('data-target'));
            if (!input) return;
            var step = parseFloat(stepBtn.getAttribute('data-step')) || 1;
            var up = stepBtn.classList.contains('step-up');
            var val = parseFloat(input.value);
            if (isNaN(val)) val = 0;
            val = Math.round((val + (up ? step : -step)) * 100) / 100;
            if (val < 0) val = 0;
            input.value = input === priceInput ? val.toFixed(2) : String(Math.round(val));
            syncQuick();
            return;
        }
        var q = t && t.closest ? t.closest('#pkgQuick button') : null;
        if (q) {
            daysInput.value = q.getAttribute('data-days');
            syncQuick();
        }
    });

    daysInput.addEventListener('input', syncQuick);
    appSel.addEventListener('change', function () { rebuildAuth(0); });

    document.getElementById('pkgModalClose').addEventListener('click', close);
    document.getElementById('pkgModalCancel').addEventListener('click', close);
    modal.addEventListener('click', function (ev) { if (ev.target === modal) close(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') close(); });
})();
</script>
<?php admin_footer(); ?>
