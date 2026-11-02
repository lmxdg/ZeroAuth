<?php
// 管理员后台 - 应用管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$act   = (string)($_GET['act'] ?? 'list');
if ($act !== 'list') {
    redirect('app_manage.php');
}
$error = '';

function app_gen(int $bytes): string
{
    return bin2hex(random_bytes($bytes));
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $appIdPost = (int)($_POST['id'] ?? 0);
        $name      = trim((string)($_POST['name'] ?? ''));
        $methods   = $_POST['auth_methods'] ?? [];
        $callback  = trim((string)($_POST['callback_url'] ?? ''));
        $status    = (int)($_POST['status'] ?? 2) === 1 ? 1 : 2;
        $remark    = trim((string)($_POST['remark'] ?? ''));

        $methods = array_values(array_intersect([1, 2, 3, 4], array_map('intval', (array)$methods)));

        if ($name === '' || mb_strlen($name) > 64) {
            $error = '应用名称不能为空且不超过 64 字。';
        } elseif ($callback !== '' && (mb_strlen($callback) > 255 || !filter_var($callback, FILTER_VALIDATE_URL))) {
            $error = '回调地址必须为合法 URL（http/https），或不填写。';
        } elseif (mb_strlen($remark) > 255) {
            $error = '备注不能超过 255 字。';
        } else {
            try {
                if ($appIdPost > 0) {
                    $stmt = db()->prepare('UPDATE `app` SET `name`=?,`auth_methods`=?,`callback_url`=?,`status`=?,`remark`=? WHERE `id`=?');
                    $stmt->execute([$name, implode(',', $methods), $callback, $status, $remark, $appIdPost]);
                    audit('app', 'update', 1, (int)$admin['id'], '应用ID：' . $appIdPost . ' 名称：' . $name);
                    flash_set('ok', '应用已保存。');
                } else {
                    $appIdStr = 'app' . app_gen(8);
                    $stmt = db()->prepare('INSERT INTO `app` (`name`,`app_id`,`app_key`,`app_secret`,`max_devices`,`license_types`,`auth_methods`,`callback_url`,`price`,`status`,`remark`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([$name, $appIdStr, app_gen(24), app_gen(24), 1, '1,2,3', implode(',', $methods), $callback, '0.00', $status, $remark, time()]);
                    audit('app', 'create', 1, (int)$admin['id'], '应用：' . $name . ' AppID：' . $appIdStr);
                    flash_set('ok', '应用已创建，AppID：' . $appIdStr);
                }
                redirect('app_manage.php');
            } catch (RuntimeException $ex) {
                $error = $ex->getMessage();
            } catch (PDOException $ex) {
                $error = '保存失败：' . $ex->getMessage();
            }
        }
    }

    if ($action === 'reset_secret') {
        $appIdPost = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `name` FROM `app` WHERE `id`=?');
        $stmt->execute([$appIdPost]);
        if ($row = $stmt->fetch()) {
            $up = db()->prepare('UPDATE `app` SET `app_key`=?,`app_secret`=? WHERE `id`=?');
            $up->execute([app_gen(24), app_gen(24), $appIdPost]);
            audit('app', 'reset_secret', 1, (int)$admin['id'], '应用ID：' . $appIdPost . ' 名称：' . $row['name']);
            flash_set('ok', '应用「' . $row['name'] . '」的 AppKey/AppSecret 已重置，客户端需重新拉取密钥。');
        }
        redirect('app_manage.php');
    }

    if ($action === 'toggle') {
        $appIdPost = (int)($_POST['id'] ?? 0);
        $to = (int)($_POST['to'] ?? 1) === 1 ? 1 : 2;
        $stmt = db()->prepare('UPDATE `app` SET `status`=? WHERE `id`=?');
        $stmt->execute([$to, $appIdPost]);
        audit('app', 'toggle', 1, (int)$admin['id'], '应用ID：' . $appIdPost . ' 状态：' . ($to === 1 ? '启用' : '停用'));
        flash_set('ok', $to === 1 ? '应用已启用。' : '应用已停用，密钥下发与验证接口将拒绝该应用。');
        redirect('app_manage.php');
    }

    if ($action === 'delete') {
        $appIdPost = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT COUNT(*) FROM `license` WHERE `app_id`=?');
        $stmt->execute([$appIdPost]);
        if ((int)$stmt->fetchColumn() > 0) {
            flash_set('error', '该应用名下已有卡密授权，禁止删除；如需下线请在列表中停用。');
        } else {
            $stmt = db()->prepare('DELETE FROM `app` WHERE `id`=?');
            $stmt->execute([$appIdPost]);
            db()->prepare('DELETE FROM `cloud_var` WHERE `app_id`=?')->execute([$appIdPost]);
            audit('app', 'delete', 1, (int)$admin['id'], '应用ID：' . $appIdPost);
            flash_set('ok', '应用已删除。');
        }
        redirect('app_manage.php');
    }
}

$apps = db()->query(
    'SELECT a.*, (SELECT COUNT(*) FROM `license` l WHERE l.`app_id`=a.`id`) AS `license_count`, '
    . '(SELECT COUNT(*) FROM `app_version` v WHERE v.`app_id`=a.`id` AND v.`status`=1) AS `version_count` '
    . 'FROM `app` a ORDER BY a.`id` DESC'
)->fetchAll();

admin_header('应用管理', 'app', $admin);
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
            <h2 class="card-title">应用列表</h2>
            <p class="card-desc" style="margin-bottom:0">AppID / AppKey / AppSecret 创建时自动生成；AppSecret 在客户端凭 AppID 通过密钥下发接口动态获取。</p>
        </div>
        <div class="spacer"></div>
        <button class="btn-primary" type="button" id="btnNewApp">新增应用</button>
    </div>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>序号</th><th>应用名称</th><th>AppKey</th><th>授权方式</th><th>AppSecret</th><th>授权数</th><th>版本</th><th>状态</th><th>授权检验</th><th>创建时间</th><th style="width:280px">操作</th></tr></thead>
            <tbody>
            <?php if (!$apps): ?>
            <tr><td colspan="11"><div class="empty-state">暂无应用，点击右上角「新增应用」创建第一个应用。</div></td></tr>
            <?php endif; ?>
            <?php $methodNames = [1 => '单域名', 2 => '泛域名', 3 => 'IP', 4 => '密钥']; ?>
            <?php foreach ($apps as $i => $row): ?>
            <?php $rowMethods = array_filter(array_map('intval', explode(',', (string)$row['auth_methods']))); ?>
            <tr>
                <td class="cell-code"><?php echo $i + 1; ?></td>
                <td class="cell-main"><?php echo e($row['name']); ?></td>
                <td class="cell-code"><?php echo e($row['app_key']); ?></td>
                <td class="cell-sub">
                    <?php if ($rowMethods): foreach ($rowMethods as $m): ?>
                    <span class="tag tag-info" style="margin-right:4px"><?php echo $methodNames[$m] ?? '未知'; ?></span>
                    <?php endforeach; else: ?>
                    <span class="tag tag-off">未开放</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="secret-box">
                        <span class="cell-code sec-masked"><?php echo e(sec_mask_secret((string)$row['app_secret'])); ?></span>
                        <span class="cell-code sec-full" style="display:none"><?php echo e($row['app_secret']); ?></span>
                        <button type="button" class="sec-toggle">显示</button>
                    </div>
                </td>
                <td><?php echo (int)$row['license_count']; ?></td>
                <td class="cell-sub"><?php echo (int)$row['version_count'] > 0 ? '已发布 ' . (int)$row['version_count'] : '未发布'; ?></td>
                <td><span class="tag <?php echo (int)$row['status'] === 1 ? 'tag-ok' : 'tag-off'; ?>"><?php echo (int)$row['status'] === 1 ? '启用' : '停用'; ?></span></td>
                <td>
                    <form method="post" style="display:inline" data-confirm="确定<?php echo (int)$row['status'] === 1 ? '停用' : '启用'; ?>应用「<?php echo e($row['name']); ?>」吗？">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <input type="hidden" name="to" value="<?php echo (int)$row['status'] === 1 ? 2 : 1; ?>">
                        <button class="switch-btn <?php echo (int)$row['status'] === 1 ? 'on' : ''; ?>" type="submit" aria-label="切换授权检验"></button>
                    </form>
                </td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$row['created_at']); ?></td>
                <td>
                    <a class="btn-mini" href="app_version_manage.php?app_id=<?php echo (int)$row['id']; ?>">版本</a>
                    <button class="btn-mini btn-edit" type="button"
                        data-id="<?php echo (int)$row['id']; ?>"
                        data-name="<?php echo e($row['name']); ?>"
                        data-methods="<?php echo e($row['auth_methods']); ?>"
                        data-callback="<?php echo e($row['callback_url']); ?>"
                        data-status="<?php echo (int)$row['status']; ?>"
                        data-remark="<?php echo e($row['remark']); ?>">编辑</button>
                    <form method="post" style="display:inline" data-confirm="确定重置应用「<?php echo e($row['name']); ?>」的 AppKey/AppSecret 吗？旧密钥立即失效，已下发客户端需重新拉取。">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="reset_secret">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-mini-plain" type="submit">重置密钥</button>
                    </form>
                    <form method="post" style="display:inline" data-confirm="确定删除应用「<?php echo e($row['name']); ?>」吗？删除后不可恢复。">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-danger-mini" type="submit">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="modal-mask" id="appModal">
    <div class="modal-box modal-form">
        <div class="modal-form-head">
            <span class="t" id="appModalTitle">新增应用</span>
            <button type="button" class="modal-x" id="appModalClose" aria-label="关闭">&times;</button>
        </div>
        <form method="post" class="form" style="max-width:none" id="appModalForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="0" id="appModalId">
            <div class="form-h">
                <label class="form-label"><i class="req">*</i>应用名称</label>
                <div class="form-ctrl">
                    <input class="form-input" type="text" name="name" id="appName" maxlength="64" placeholder="请输入应用名称" required>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label">授权方式</label>
                <div class="form-ctrl">
                    <div class="check-row">
                        <label class="check-item"><input type="checkbox" name="auth_methods[]" value="1" checked>单域名</label>
                        <label class="check-item"><input type="checkbox" name="auth_methods[]" value="2" checked>泛域名</label>
                        <label class="check-item"><input type="checkbox" name="auth_methods[]" value="3" checked>IP</label>
                        <label class="check-item"><input type="checkbox" name="auth_methods[]" value="4" checked>密钥</label>
                    </div>
                    <div class="form-hint">全部取消后，用户端和代理端将不再显示该应用。</div>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label">回调地址</label>
                <div class="form-ctrl">
                    <input class="form-input" type="text" name="callback_url" id="appCallback" maxlength="255" placeholder="授权验证回调URL（可选）">
                </div>
            </div>
            <div class="form-h">
                <label class="form-label">状态</label>
                <div class="form-ctrl">
                    <div class="switch-line">
                        <span>禁用</span>
                        <label class="pref-switch"><input type="checkbox" name="status" value="1" checked id="appStatus"><i></i></label>
                        <span>启用</span>
                    </div>
                </div>
            </div>
            <div class="form-h">
                <label class="form-label">备注</label>
                <div class="form-ctrl">
                    <textarea class="form-input" name="remark" id="appRemark" maxlength="255" rows="3" placeholder="可选"></textarea>
                </div>
            </div>
            <div class="modal-actions" style="margin-top:22px">
                <button class="btn-plain" type="button" id="appModalCancel">取消</button>
                <button class="btn-primary" type="submit">确定</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('appModal');
    if (!modal) return;
    var form = document.getElementById('appModalForm');
    var title = document.getElementById('appModalTitle');
    var idInput = document.getElementById('appModalId');
    var nameInput = document.getElementById('appName');
    var callbackInput = document.getElementById('appCallback');
    var remarkInput = document.getElementById('appRemark');
    var statusInput = document.getElementById('appStatus');

    var close = function () { modal.classList.remove('show'); };

    function setMethods(str) {
        var allow = (str || '').split(',');
        Array.prototype.forEach.call(form.querySelectorAll('input[name="auth_methods[]"]'), function (c) {
            c.checked = allow.indexOf(c.value) >= 0;
        });
    }

    function openEdit(btn) {
        title.textContent = '编辑应用';
        idInput.value = btn.getAttribute('data-id') || '0';
        nameInput.value = btn.getAttribute('data-name') || '';
        callbackInput.value = btn.getAttribute('data-callback') || '';
        remarkInput.value = btn.getAttribute('data-remark') || '';
        statusInput.checked = btn.getAttribute('data-status') === '1';
        setMethods(btn.getAttribute('data-methods'));
        modal.classList.add('show');
        nameInput.focus();
    }

    document.getElementById('btnNewApp').addEventListener('click', function () {
        title.textContent = '新增应用';
        idInput.value = '0';
        nameInput.value = '';
        callbackInput.value = '';
        remarkInput.value = '';
        statusInput.checked = true;
        setMethods('1,2,3,4');
        modal.classList.add('show');
        nameInput.focus();
    });

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (t && t.closest && t.closest('.btn-edit')) {
            openEdit(t.closest('.btn-edit'));
            return;
        }
        if (t && t.closest && t.closest('.sec-toggle')) {
            var btn = t.closest('.sec-toggle');
            var box = btn.closest('.secret-box');
            var masked = box.querySelector('.sec-masked');
            var full = box.querySelector('.sec-full');
            var showing = full.style.display !== 'none';
            full.style.display = showing ? 'none' : '';
            masked.style.display = showing ? '' : 'none';
            btn.textContent = showing ? '显示' : '隐藏';
        }
    });

    document.getElementById('appModalClose').addEventListener('click', close);
    document.getElementById('appModalCancel').addEventListener('click', close);
    modal.addEventListener('click', function (ev) { if (ev.target === modal) close(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') close(); });
})();
</script>
<?php admin_footer(); ?>
