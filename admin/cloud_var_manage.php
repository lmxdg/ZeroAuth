<?php
// 管理员后台 - 云变量管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$act   = (string)($_GET['act'] ?? 'list');
$id    = (int)($_GET['id'] ?? 0);
$appId = (int)($_GET['app_id'] ?? 0);
$error = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $varId    = (int)($_POST['id'] ?? 0);
        $appIdPost = (int)($_POST['app_id'] ?? 0);
        $varKey   = trim((string)($_POST['var_key'] ?? ''));
        $varValue = (string)($_POST['var_value'] ?? '');
        $isPublic = (int)($_POST['is_public'] ?? 1) === 1 ? 1 : 0;

        $stmt = db()->prepare('SELECT `name` FROM `app` WHERE `id`=?');
        $stmt->execute([$appIdPost]);
        $appName = (string)($stmt->fetchColumn() ?: '');

        if ($appName === '') {
            $error = '请选择有效的应用。';
        } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,63}$/', $varKey)) {
            $error = '变量键名需以字母开头，仅含字母 / 数字 / 下划线，长度 2~64。';
        } elseif (strlen($varValue) > 65535) {
            $error = '变量值过长（上限 64KB）。';
        } else {
            try {
                if ($varId > 0) {
                    $stmt = db()->prepare('UPDATE `cloud_var` SET `app_id`=?,`var_key`=?,`var_value`=?,`is_public`=?,`update_time`=? WHERE `id`=?');
                    $stmt->execute([$appIdPost, $varKey, $varValue, $isPublic, time(), $varId]);
                    audit('cloud_var', 'update', 1, (int)$admin['id'], '应用：' . $appName . ' 键：' . $varKey);
                    flash_set('ok', '云变量已保存。');
                } else {
                    $stmt = db()->prepare('INSERT INTO `cloud_var` (`app_id`,`var_key`,`var_value`,`is_public`,`update_time`) VALUES (?,?,?,?,?)');
                    $stmt->execute([$appIdPost, $varKey, $varValue, $isPublic, time()]);
                    audit('cloud_var', 'create', 1, (int)$admin['id'], '应用：' . $appName . ' 键：' . $varKey);
                    flash_set('ok', '云变量已创建。');
                }
                redirect('cloud_var_manage.php?app_id=' . $appIdPost);
            } catch (PDOException $ex) {
                $error = str_contains($ex->getMessage(), 'uk_app_var') || str_contains($ex->getMessage(), 'Duplicate')
                    ? '该应用下已存在同名变量键。'
                    : '保存失败：' . $ex->getMessage();
            }
            $act = $varId > 0 ? 'edit' : 'new';
            $id  = $varId;
            $appId = $appIdPost;
        }
    }

    if ($action === 'delete') {
        $varId = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `var_key`,`app_id` FROM `cloud_var` WHERE `id`=?');
        $stmt->execute([$varId]);
        if ($row = $stmt->fetch()) {
            db()->prepare('DELETE FROM `cloud_var` WHERE `id`=?')->execute([$varId]);
            audit('cloud_var', 'delete', 1, (int)$admin['id'], '应用ID：' . $row['app_id'] . ' 键：' . $row['var_key']);
            flash_set('ok', '云变量已删除。');
            redirect('cloud_var_manage.php?app_id=' . (int)$row['app_id']);
        }
        redirect('cloud_var_manage.php');
    }
}

$var = null;
if ($id > 0 && $act === 'edit') {
    $stmt = db()->prepare('SELECT * FROM `cloud_var` WHERE `id`=?');
    $stmt->execute([$id]);
    $var = $stmt->fetch();
    if (!$var) {
        $error = '云变量不存在或已删除。';
        $act = 'list';
    } else {
        $appId = (int)$var['app_id'];
    }
}

$apps = db()->query('SELECT `id`,`name` FROM `app` WHERE `status`=1 ORDER BY `id` DESC')->fetchAll();
if ($appId === 0 && $apps) {
    $appId = (int)$apps[0]['id'];
}

$vars = [];
if ($act === 'list' && $appId > 0) {
    $stmt = db()->prepare('SELECT * FROM `cloud_var` WHERE `app_id`=? ORDER BY `id` DESC');
    $stmt->execute([$appId]);
    $vars = $stmt->fetchAll();
}

admin_header('云变量管理', 'cloud_var', $admin);
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
            <h2 class="card-title">云变量列表</h2>
            <p class="card-desc">客户端拉取地址：<span class="cell-code"><?php echo e(base_url() . 'api/cloud_var.php?app_id=应用AppID&keys=key1,key2'); ?></span>。公开变量无需签名；拉取私有变量需追加 private=1 与 timestamp/nonce/sign 签名参数。</p>
        </div>
        <div class="spacer"></div>
        <form method="get" style="display:inline-flex;gap:8px;align-items:center">
            <select class="form-input" name="app_id" onchange="location.href='cloud_var_manage.php?app_id='+this.value">
                <?php foreach ($apps as $a): ?>
                <option value="<?php echo (int)$a['id']; ?>" <?php echo (int)$a['id'] === $appId ? 'selected' : ''; ?>><?php echo e($a['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($apps): ?>
        <a class="btn-primary" href="cloud_var_manage.php?act=new&app_id=<?php echo $appId; ?>">新建变量</a>
        <?php endif; ?>
    </div>
    <?php if (!$apps): ?>
    <div class="alert alert-info">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>暂无应用，请先到「应用管理」新建应用。</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>变量键名</th><th>变量值</th><th>可见性</th><th>更新时间</th><th style="width:150px">操作</th></tr></thead>
            <tbody>
            <?php if (!$vars): ?>
            <tr><td colspan="5"><div class="empty-state">该应用暂无云变量，点击右上角「新建变量」添加。</div></td></tr>
            <?php endif; ?>
            <?php foreach ($vars as $v): ?>
            <tr>
                <td class="cell-code"><?php echo e($v['var_key']); ?></td>
                <td class="cell-main" style="max-width:480px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo e(mb_strimwidth((string)$v['var_value'], 0, 80, '…')); ?></td>
                <td><span class="tag <?php echo (int)$v['is_public'] === 1 ? 'tag-ok' : 'tag-info'; ?>"><?php echo (int)$v['is_public'] === 1 ? '公开' : '私有（需签名）'; ?></span></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$v['update_time']); ?></td>
                <td>
                    <a class="btn-mini" href="cloud_var_manage.php?act=edit&id=<?php echo (int)$v['id']; ?>&app_id=<?php echo $appId; ?>">编辑</a>
                    <form method="post" style="display:inline" data-confirm="确定删除变量「<?php echo e($v['var_key']); ?>」吗？客户端将无法再读取该变量。">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>">
                        <button class="btn-danger-mini" type="submit">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php 
elseif ($act === 'new' || $act === 'edit'): ?>
<section class="card">
    <h2 class="card-title"><?php echo $act === 'new' ? '新建云变量' : '编辑云变量'; ?></h2>
    <p class="card-desc">典型用途：notice（公告文本）、version（最新版本号）、vip_url（购买/升级链接）、config（JSON 配置）。</p>
    <form method="post" class="form" style="max-width:640px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int)($var['id'] ?? 0); ?>">
        <div class="form-item">
            <label class="form-label">所属应用</label>
            <select class="form-input" name="app_id" required>
                <?php foreach ($apps as $a): ?>
                <option value="<?php echo (int)$a['id']; ?>" <?php echo (int)($var['app_id'] ?? $appId) === (int)$a['id'] ? 'selected' : ''; ?>><?php echo e($a['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-item">
            <label class="form-label">变量键名</label>
            <input class="form-input" type="text" name="var_key" maxlength="64" value="<?php echo e($var['var_key'] ?? ''); ?>" required>
            <div class="form-hint">以字母开头，仅字母 / 数字 / 下划线，如 notice、version。</div>
        </div>
        <div class="form-item">
            <label class="form-label">变量值</label>
            <textarea class="form-input" name="var_value" rows="6" placeholder="公告文本 / 版本号 / 链接 / JSON 均可"><?php echo e($var['var_value'] ?? ''); ?></textarea>
            <div class="form-hint">最长 64KB，客户端按原文返回。</div>
        </div>
        <div class="form-item">
            <label class="form-label">可见性</label>
            <select class="form-input" name="is_public">
                <option value="1" <?php echo (int)($var['is_public'] ?? 1) === 1 ? 'selected' : ''; ?>>公开（无需签名即可读取）</option>
                <option value="0" <?php echo (int)($var['is_public'] ?? 1) === 0 ? 'selected' : ''; ?>>私有（需 HMAC 签名鉴权）</option>
            </select>
        </div>
        <div class="page-actions">
            <a class="btn-plain" href="cloud_var_manage.php?app_id=<?php echo $appId; ?>">返回列表</a>
            <button class="btn-primary" type="submit">保存变量</button>
        </div>
    </form>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
