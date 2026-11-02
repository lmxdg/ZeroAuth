<?php
// 管理员后台 - 版本管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$act   = (string)($_GET['act'] ?? 'list');
$error = '';

function ver_ok(string $v): bool
{
    return (bool)preg_match('/^[0-9A-Za-z._-]{1,32}$/', $v);
}

function pkg_dir(): string
{
    $dir = RUNTIME_PATH . '/app_files';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

$apps = db()->query('SELECT `id`,`name`,`app_key` FROM `app` ORDER BY `id` DESC')->fetchAll();
$appId = (int)($_GET['app_id'] ?? 0);
if ($appId <= 0 && $apps) {
    $appId = (int)$apps[0]['id'];
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $vid        = (int)($_POST['id'] ?? 0);
        $appIdPost  = (int)($_POST['app_id'] ?? 0);
        $version    = trim((string)($_POST['version'] ?? ''));
        $title      = trim((string)($_POST['title'] ?? ''));
        $changelog  = trim((string)($_POST['changelog'] ?? ''));
        $forceUpd   = isset($_POST['force_update']) ? 1 : 0;
        $minVersion = trim((string)($_POST['min_version'] ?? ''));
        $updateSql  = trim((string)($_POST['update_sql'] ?? ''));
        $status     = (int)($_POST['status'] ?? 1) === 2 ? 2 : 1;

        $appOk = false;
        foreach ($apps as $a) {
            if ((int)$a['id'] === $appIdPost) {
                $appOk = true;
                break;
            }
        }

        $filePath = '';
        $fileSize = 0;
        $fileMd5  = '';
        $file     = $_FILES['pkg'] ?? null;
        if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                $error = '更新包上传失败（错误码 ' . (int)$file['error'] . '），请检查文件大小限制。';
            } elseif (!is_uploaded_file($file['tmp_name'])) {
                $error = '更新包上传校验失败。';
            } else {
                $ext = strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION));
                $ext = preg_replace('/[^a-z0-9]/', '', $ext);
                $safeVer = preg_replace('/[^0-9A-Za-z._-]/', '', $version);
                $newName = 'app' . $appIdPost . '_v' . $safeVer . '_' . time() . ($ext !== '' ? '.' . $ext : '.bin');
                if (!@move_uploaded_file($file['tmp_name'], pkg_dir() . '/' . $newName)) {
                    $error = '更新包保存失败，请检查 runtime/app_files 目录权限。';
                } else {
                    $filePath = $newName;
                    $fileSize = (int)filesize(pkg_dir() . '/' . $newName);
                    $fileMd5  = md5_file(pkg_dir() . '/' . $newName);
                }
            }
        }

        if ($error === '') {
            if (!$appOk) {
                $error = '所属应用不存在。';
            } elseif (!ver_ok($version)) {
                $error = '版本号格式不合法（1~32 位字母数字._-，如 1.5.0）。';
            } elseif (mb_strlen($title) > 190) {
                $error = '版本标题不能超过 190 字。';
            } elseif ($minVersion !== '' && !ver_ok($minVersion)) {
                $error = '最低可用版本号格式不合法。';
            } else {
                try {
                    if ($vid > 0) {
                        $stmt = db()->prepare('SELECT * FROM `app_version` WHERE `id`=?');
                        $stmt->execute([$vid]);
                        $old = $stmt->fetch();
                        if (!$old) {
                            $error = '版本不存在。';
                        } else {
                            if ($filePath === '') {
                                $filePath = (string)$old['file_path'];
                                $fileSize = (int)$old['file_size'];
                                $fileMd5  = (string)$old['file_md5'];
                            } elseif ((string)$old['file_path'] !== '' && is_file(pkg_dir() . '/' . $old['file_path'])) {
                                @unlink(pkg_dir() . '/' . $old['file_path']);
                            }
                            db()->prepare(
                                'UPDATE `app_version` SET `app_id`=?,`version`=?,`title`=?,`changelog`=?,`file_path`=?,`file_size`=?,`file_md5`=?,`force_update`=?,`min_version`=?,`update_sql`=?,`status`=? WHERE `id`=?'
                            )->execute([$appIdPost, $version, $title, $changelog, $filePath, $fileSize, $fileMd5, $forceUpd, $minVersion, $updateSql, $status, $vid]);
                        }
                    } else {
                        db()->prepare(
                            'INSERT INTO `app_version` (`app_id`,`version`,`title`,`changelog`,`file_path`,`file_size`,`file_md5`,`force_update`,`min_version`,`update_sql`,`status`,`published_at`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?)'
                        )->execute([$appIdPost, $version, $title, $changelog, $filePath, $fileSize, $fileMd5, $forceUpd, $minVersion, $updateSql, time(), time()]);
                    }
                } catch (PDOException $ex) {
                    $error = $ex->getCode() === '23000' ? '该应用下已存在相同版本号。' : '保存失败：' . $ex->getMessage();
                } catch (Throwable $ex) {
                    $error = '保存失败：' . $ex->getMessage();
                }
            }
        }

        if ($error === '') {
            audit('license', 'app_version_save', 1, (int)$admin['id'], '应用ID：' . $appIdPost . ' 版本：' . $version . '（' . ($vid > 0 ? '编辑' : '发布') . '）');
            flash_set('ok', '版本 ' . $version . ' 已' . ($vid > 0 ? '更新' : '发布') . '。');
            redirect('app_version_manage.php?app_id=' . $appIdPost);
        }
        $appId = $appIdPost;
    }

    if ($action === 'toggle') {
        $vid = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `id`,`app_id`,`version`,`status` FROM `app_version` WHERE `id`=?');
        $stmt->execute([$vid]);
        if ($row = $stmt->fetch()) {
            $new = (int)$row['status'] === 1 ? 2 : 1;
            db()->prepare('UPDATE `app_version` SET `status`=? WHERE `id`=?')->execute([$new, $vid]);
            audit('license', 'app_version_toggle', 1, (int)$admin['id'], '版本：' . $row['version'] . ' 状态：' . ($new === 1 ? '已发布' : '已下架'));
            flash_set('ok', '版本 ' . $row['version'] . ' 已' . ($new === 1 ? '发布' : '下架') . '。');
        }
        redirect('app_version_manage.php?app_id=' . (int)($_POST['app_id'] ?? $appId));
    }

    if ($action === 'delete') {
        $vid = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `id`,`version`,`file_path` FROM `app_version` WHERE `id`=?');
        $stmt->execute([$vid]);
        if ($row = $stmt->fetch()) {
            db()->prepare('DELETE FROM `app_version` WHERE `id`=?')->execute([$vid]);
            $fp = (string)$row['file_path'];
            if ($fp !== '') {
                $cnt = db()->prepare('SELECT COUNT(*) FROM `app_version` WHERE `file_path`=?');
                $cnt->execute([$fp]);
                if ((int)$cnt->fetchColumn() === 0 && is_file(pkg_dir() . '/' . $fp)) {
                    @unlink(pkg_dir() . '/' . $fp);
                }
            }
            audit('license', 'app_version_delete', 1, (int)$admin['id'], '版本：' . $row['version']);
            flash_set('ok', '版本 ' . $row['version'] . ' 已删除。');
        }
        redirect('app_version_manage.php?app_id=' . (int)($_POST['app_id'] ?? $appId));
    }
}

$edit = null;
if ($act === 'edit') {
    $stmt = db()->prepare('SELECT * FROM `app_version` WHERE `id`=?');
    $stmt->execute([(int)($_GET['id'] ?? 0)]);
    $edit = $stmt->fetch();
    if (!$edit) {
        $act = 'list';
    } else {
        $appId = (int)$edit['app_id'];
    }
}

$versions = [];
if ($appId > 0) {
    $stmt = db()->prepare('SELECT * FROM `app_version` WHERE `app_id`=? ORDER BY `published_at` DESC, `id` DESC');
    $stmt->execute([$appId]);
    $versions = $stmt->fetchAll();
}
$currentApp = null;
foreach ($apps as $a) {
    if ((int)$a['id'] === $appId) {
        $currentApp = $a;
        break;
    }
}

admin_header('应用版本管理', 'app_ver', $admin);
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
if ($act === 'edit' || $act === 'new'): ?>
<section class="card">
    <h2 class="card-title"><?php echo $edit ? '编辑版本' : '发布新版本'; ?></h2>
    <p class="card-desc">版本号用于客户端 version_compare 比较（如 1.5.0）；升级 SQL 按版本升序在客户端执行（分号分隔语句）；勾选强制更新或当前版本低于最低可用版本时，客户端不允许跳过更新。</p>
    <form method="post" enctype="multipart/form-data" class="form" style="max-width:720px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo $edit ? (int)$edit['id'] : 0; ?>">
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">所属应用</label>
                <select class="form-input" name="app_id" required>
                    <?php foreach ($apps as $a): ?>
                    <option value="<?php echo (int)$a['id']; ?>" <?php echo ($edit ? (int)$edit['app_id'] : $appId) === (int)$a['id'] ? 'selected' : ''; ?>>
                        <?php echo e($a['name']); ?>（<?php echo e($a['app_key']); ?>）
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-item">
                <label class="form-label">版本号</label>
                <input class="form-input" type="text" name="version" maxlength="32" required value="<?php echo e((string)($edit['version'] ?? '')); ?>" placeholder="1.5.0">
            </div>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">版本标题</label>
                <input class="form-input" type="text" name="title" maxlength="190" value="<?php echo e((string)($edit['title'] ?? '')); ?>" placeholder="性能优化与问题修复">
            </div>
            <div class="form-item">
                <label class="form-label">状态</label>
                <select class="form-input" name="status">
                    <option value="1" <?php echo (int)($edit['status'] ?? 1) === 1 ? 'selected' : ''; ?>>已发布（客户端可检查到）</option>
                    <option value="2" <?php echo (int)($edit['status'] ?? 1) === 2 ? 'selected' : ''; ?>>已下架</option>
                </select>
            </div>
        </div>
        <div class="form-item">
            <label class="form-label">更新说明（changelog）</label>
            <textarea class="form-input" name="changelog" rows="4" placeholder="1. 优化启动速度&#10;2. 修复已知问题"><?php echo e((string)($edit['changelog'] ?? '')); ?></textarea>
        </div>
        <div class="form-item">
            <label class="form-label">更新包<?php echo $edit && (string)$edit['file_path'] !== '' ? '（已上传 ' . e((string)$edit['file_path']) . '，不选择则保留）' : ''; ?></label>
            <input class="form-input" type="file" name="pkg" <?php echo $edit ? '' : 'required'; ?>>
            <?php if ($edit && (int)$edit['file_size'] > 0): ?>
            <div class="form-hint">当前包：<?php echo round((int)$edit['file_size'] / 1048576, 3); ?> MB，MD5：<?php echo e((string)$edit['file_md5']); ?></div>
            <?php endif; ?>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">强制更新</label>
                <label class="form-check"><input type="checkbox" name="force_update" <?php echo (int)($edit['force_update'] ?? 0) === 1 ? 'checked' : ''; ?>> 强制更新（客户端禁止跳过）</label>
            </div>
            <div class="form-item">
                <label class="form-label">最低可用版本（可选）</label>
                <input class="form-input" type="text" name="min_version" maxlength="32" value="<?php echo e((string)($edit['min_version'] ?? '')); ?>" placeholder="1.1.0（低于此版本强制更新）">
            </div>
        </div>
        <div class="form-item">
            <label class="form-label">升级 SQL（可选，分号分隔）</label>
            <textarea class="form-input" name="update_sql" rows="4" placeholder="ALTER TABLE example ADD INDEX idx_enabled (enabled);"><?php echo e((string)($edit['update_sql'] ?? '')); ?></textarea>
            <div class="form-hint">客户端按版本升序依次执行每个版本的 updateSql。</div>
        </div>
        <div class="page-actions">
            <a class="btn-plain" href="app_version_manage.php?app_id=<?php echo $appId; ?>">返回列表</a>
            <button class="btn-primary" type="submit"><?php echo $edit ? '保存修改' : '发布版本'; ?></button>
        </div>
    </form>
</section>

<?php 
else: ?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">应用版本管理</h2>
            <p class="card-desc" style="margin-bottom:0">更新包存储于 runtime/app_files（Web 不可直接访问），通过短期令牌接口下发。</p>
        </div>
        <div class="spacer"></div>
        <?php if ($appId > 0): ?>
        <a class="btn-primary" href="app_version_manage.php?act=new&app_id=<?php echo $appId; ?>">发布新版本</a>
        <?php endif; ?>
    </div>
    <?php if ($apps): ?>
    <div class="form-row" style="margin-bottom:12px">
        <div class="form-item">
            <label class="form-label">切换应用</label>
            <select class="form-input" onchange="location.href='app_version_manage.php?app_id='+this.value">
                <?php foreach ($apps as $a): ?>
                <option value="<?php echo (int)$a['id']; ?>" <?php echo $appId === (int)$a['id'] ? 'selected' : ''; ?>><?php echo e($a['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>版本</th><th>标题</th><th>更新包</th><th>MD5</th><th>强制</th><th>最低版本</th><th>发布时间</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php if (!$versions): ?>
            <tr><td colspan="9" style="text-align:center;color:var(--text-sub)">暂无版本，点击右上角「发布新版本」。</td></tr>
            <?php endif; ?>
            <?php foreach ($versions as $v): ?>
            <tr>
                <td class="cell-code cell-main"><?php echo e((string)$v['version']); ?></td>
                <td class="cell-sub"><?php echo e((string)$v['title']); ?></td>
                <td class="cell-sub"><?php echo (int)$v['file_size'] > 0 ? round((int)$v['file_size'] / 1048576, 3) . ' MB' : '未上传'; ?></td>
                <td class="cell-code"><?php echo e(mb_substr((string)$v['file_md5'], 0, 12)); ?>…</td>
                <td><?php echo (int)$v['force_update'] === 1 ? '<span class="tag tag-danger">强制</span>' : '<span class="tag">普通</span>'; ?></td>
                <td class="cell-code"><?php echo e((string)$v['min_version'] ?: '-'); ?></td>
                <td class="cell-sub"><?php echo (int)$v['published_at'] > 0 ? date('Y-m-d H:i', (int)$v['published_at']) : '-'; ?></td>
                <td><?php echo (int)$v['status'] === 1 ? '<span class="tag tag-ok">已发布</span>' : '<span class="tag">已下架</span>'; ?></td>
                <td class="cell-actions">
                    <a class="link" href="app_version_manage.php?act=edit&id=<?php echo (int)$v['id']; ?>">编辑</a>
                    <form method="post" style="display:inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>">
                        <input type="hidden" name="app_id" value="<?php echo $appId; ?>">
                        <button class="link" type="submit"><?php echo (int)$v['status'] === 1 ? '下架' : '发布'; ?></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('确认删除版本 <?php echo e((string)$v['version']); ?>？')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>">
                        <input type="hidden" name="app_id" value="<?php echo $appId; ?>">
                        <button class="link link-danger" type="submit">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="alert alert-info">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>暂无应用，请先到「应用管理」新建应用。</span>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
