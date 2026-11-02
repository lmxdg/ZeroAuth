<?php
// 管理员后台 - 应用商店
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/PluginSys.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$action = (string)($_POST['action'] ?? '');
if ($action === 'upload') {
    // 上传失败常见原因：请求体超过 post_max_size 时 $_POST 会被清空，无法通过 CSRF 校验，需先行拦截给出可读提示
    if (empty($_POST['csrf']) && empty($_FILES['plugin_zip'])) {
        flash_set('error', '上传失败：文件超过服务器 PHP 上传限制（post_max_size / upload_max_filesize），请调整 PHP 配置或减小插件包体积。');
        redirect('store.php');
    }
}

if ($action !== '' && in_array($action, ['install', 'enable', 'disable', 'uninstall'], true)) {
    csrf_check();
    $code = trim((string)($_POST['code'] ?? ''));
    $scan = plugin_scan();
    $plugin = $scan[$code] ?? null;

    if (!$plugin) {
        flash_set('error', '插件不存在（请确认 plugin/' . $code . '/Plugin.php 是否存在）。');
    } elseif ($action === 'install') {
        if ((int)$plugin['installable'] !== 1 || plugin_status_get($code) !== 3) {
            flash_set('error', '该插件当前不可安装。');
        } else {
            plugin_status_set($code, 2);
            audit('plugin', 'install', 1, (int)$admin['id'], '安装插件：' . $plugin['name']);
            flash_set('ok', '插件「' . $plugin['name'] . '」安装成功，请先完成配置再启用。');
            redirect('store.php?act=config&code=' . $code);
        }
    } elseif ($action === 'enable') {
        if (plugin_status_get($code) !== 2) {
            flash_set('error', '仅已安装且停用状态的插件可启用。');
        } else {
            plugin_status_set($code, 1);
            $extra = '';
            if ((string)($plugin['cat'] ?? '') === 'template') {
                foreach (plugin_all() as $oc => $op) {
                    if ($oc !== $code && (string)($op['cat'] ?? '') === 'template' && (int)$op['status'] === 1) {
                        plugin_status_set($oc, 2);
                        audit('plugin', 'disable', 1, (int)$admin['id'], '模板互斥自动停用：' . $op['name']);
                        $extra .= '「' . $op['name'] . '」';
                    }
                }
                if ($extra !== '') {
                    $extra = '已自动停用其他模板：' . $extra;
                }
            }
            audit('plugin', 'enable', 1, (int)$admin['id'], '启用插件：' . $plugin['name']);
            flash_set('ok', '插件「' . $plugin['name'] . '」已启用。' . $extra);
        }
    } elseif ($action === 'disable') {
        if (plugin_status_get($code) !== 1) {
            flash_set('error', '仅启用中的插件可停用。');
        } else {
            plugin_status_set($code, 2);
            audit('plugin', 'disable', 1, (int)$admin['id'], '停用插件：' . $plugin['name']);
            flash_set('ok', '插件「' . $plugin['name'] . '」已停用，相关功能入口将同步关闭。');
        }
    } elseif ($action === 'uninstall') {
        if (plugin_status_get($code) === 3) {
            flash_set('error', '该插件尚未安装。');
        } elseif ((int)$plugin['core'] === 1) {
            flash_set('error', '系统内置核心插件不可卸载，仅可停用。');
        } else {
            plugin_status_set($code, 3);
            audit('plugin', 'uninstall', 1, (int)$admin['id'], '卸载插件：' . $plugin['name']);
            flash_set('ok', '插件「' . $plugin['name'] . '」已卸载。');
        }
    }
    redirect('store.php' . (isset($_POST['cat']) && $_POST['cat'] !== '' && $_POST['cat'] !== 'all' ? '?cat=' . urlencode((string)$_POST['cat']) : ''));
}

if ($action === 'upload') {
    csrf_check();
    if (!class_exists('ZipArchive')) {
        flash_set('error', '服务器未启用 Zip 扩展（php-zip），无法上传安装插件，请先安装并启用 ZipArchive。');
        redirect('store.php');
    }

    $file = $_FILES['plugin_zip'] ?? null;
    if (!$file || !is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $map = [
            UPLOAD_ERR_INI_SIZE   => '文件超过 php.ini 的 upload_max_filesize 限制',
            UPLOAD_ERR_FORM_SIZE  => '文件超过表单大小限制',
            UPLOAD_ERR_PARTIAL    => '文件仅上传了一部分，请重试',
            UPLOAD_ERR_NO_FILE    => '请选择要上传的插件包',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
        ];
        flash_set('error', '插件包上传失败：' . ($map[$code] ?? '未知错误（代码 ' . $code . '）'));
        redirect('store.php');
    }
    if ((int)$file['size'] <= 0 || (int)$file['size'] > 50 * 1024 * 1024) {
        flash_set('error', '插件包大小必须在 1B ~ 50MB 之间。');
        redirect('store.php');
    }
    if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'zip') {
        flash_set('error', '仅支持上传 .zip 格式的插件包。');
        redirect('store.php');
    }

    $zip = new ZipArchive();
    if ($zip->open((string)$file['tmp_name']) !== true) {
        flash_set('error', '插件包读取失败：不是有效的 zip 文件或文件已损坏。');
        redirect('store.php');
    }

    // 解析 zip 结构并做路径安全检查（防目录穿越/绝对路径/盘符）
    $topDirs  = [];   // 顶层文件夹
    $rootFiles = [];  // 根目录下的文件
    $segCheck = static function (string $name): bool {
        foreach (explode('/', $name) as $seg) {
            if ($seg === '..') {
                return false;
            }
        }
        return true;
    };
    $badPath = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($name === '') {
            continue;
        }
        if ($name[0] === '/' || preg_match('#^[a-zA-Z]:#', $name) || !$segCheck($name)) {
            $badPath = true;
            break;
        }
        $parts = array_values(array_filter(explode('/', $name), fn($s) => $s !== ''));
        if (count($parts) === 0) {
            continue;
        }
        if (count($parts) === 1 && !str_ends_with($name, '/')) {
            $rootFiles[] = $parts[0];
        } else {
            $topDirs[$parts[0]] = true;
        }
    }
    if ($badPath) {
        $zip->close();
        flash_set('error', '插件包内存在非法路径（包含目录穿越或绝对路径），已终止安装。');
        redirect('store.php');
    }

    // 临时解压目录（runtime 下），全部校验通过后再移动到 plugin/
    $tmpBase = RUNTIME_PATH . '/plugin_upload';
    if (is_dir($tmpBase)) {
        @rmdir_recursively_store($tmpBase);
    }
    if (!is_dir($tmpBase) && !@mkdir($tmpBase, 0755, true)) {
        $zip->close();
        flash_set('error', '创建临时解压目录失败，请检查 runtime/ 目录写权限。');
        redirect('store.php');
    }
    $tmpDir = $tmpBase . '/pkg';
    if (!$zip->extractTo($tmpBase)) {
        $zip->close();
        flash_set('error', '插件包解压失败，请重试。');
        redirect('store.php');
    }
    $zip->close();

    $wrapDir = '';          // 最终目标目录名
    if (count($topDirs) >= 1 && empty($rootFiles)) {
        // 标准结构：zip 内有一层文件夹，插件文件全部位于该文件夹内
        if (count($topDirs) > 1) {
            flash_set('error', '插件包结构不符合要求：zip 根目录下只允许一个文件夹（当前有 ' . count($topDirs) . ' 个）。');
            redirect('store.php');
        }
        $wrapDir = (string)array_key_first($topDirs);
        $srcDir  = $tmpBase . '/' . $wrapDir;
    } elseif (empty($topDirs) && in_array('Plugin.php', $rootFiles, true)) {
        // 兼容结构：插件文件直接位于 zip 根目录，自动按插件 code 包一层文件夹
        ob_start();
        try {
            $meta = @include $tmpBase . '/Plugin.php';
        } catch (Throwable $ex) {
            $meta = null;
        }
        ob_end_clean();
        $code = is_array($meta) ? trim((string)($meta['code'] ?? '')) : '';
        if ($code === '' || !preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $code)) {
            flash_set('error', '插件包结构不符合要求：无法从根目录 Plugin.php 中解析出合法的插件标识（code）。');
            redirect('store.php');
        }
        $wrapDir = $code;
        $srcDir  = $tmpBase;
    } else {
        flash_set('error', '插件包结构不符合要求：zip 内需有一层文件夹，插件文件（必须包含 Plugin.php）放在该文件夹内。');
        redirect('store.php');
    }

    // 目录名校验
    if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $wrapDir)) {
        flash_set('error', '插件包顶层文件夹名「' . $wrapDir . '」不合法（仅允许字母、数字、下划线、中划线，1~64 位）。');
        redirect('store.php');
    }
    if (!is_file($srcDir . '/Plugin.php')) {
        flash_set('error', '插件包缺少 Plugin.php 文件，请检查包内结构。');
        redirect('store.php');
    }

    // 读取插件元数据验证可用性
    ob_start();
    try {
        $meta = @include $srcDir . '/Plugin.php';
    } catch (Throwable $ex) {
        $meta = null;
    }
    ob_end_clean();
    if (!is_array($meta) || trim((string)($meta['code'] ?? '')) === '') {
        flash_set('error', 'Plugin.php 未返回有效的插件元数据（缺少 code），插件包不可用。');
        redirect('store.php');
    }
    $pCode = trim((string)$meta['code']);
    $pName = trim((string)($meta['name'] ?? $pCode));
    $pVer  = trim((string)($meta['version'] ?? ''));

    // 若目录名与插件 code 不一致，统一改为 code 目录，保证与系统插件目录约定一致
    if ($wrapDir !== $pCode && preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $pCode)) {
        $newSrc = $tmpBase . '/' . $pCode;
        if (!@rename($srcDir, $newSrc)) {
            flash_set('error', '插件目录调整失败（' . $wrapDir . ' → ' . $pCode . '），请重试。');
            redirect('store.php');
        }
        $wrapDir = $pCode;
        $srcDir  = $newSrc;
    }

    // 移动到 plugin/ 目录（已存在则覆盖旧版本）
    $destDir = ROOT_PATH . '/plugin/' . $wrapDir;
    $overwritten = false;
    if (is_dir($destDir)) {
        $overwritten = true;
        if (!@rmdir_recursively_store($destDir)) {
            flash_set('error', '旧版本插件目录删除失败，请检查 plugin/ 目录写权限。');
            redirect('store.php');
        }
    }
    if (!@rename($srcDir, $destDir)) {
        flash_set('error', '插件写入 plugin/ 目录失败，请检查目录写权限。');
        redirect('store.php');
    }
    @rmdir_recursively_store($tmpBase);

    audit('plugin', 'upload', 1, (int)$admin['id'], '上传插件包：' . $pName . ($pVer !== '' ? ' v' . $pVer : '') . '（目录 plugin/' . $wrapDir . '）');
    flash_set('ok', '插件包「' . $pName . ($pVer !== '' ? ' v' . $pVer : '') . '」上传并自动解压成功' . ($overwritten ? '（已覆盖旧版本）' : '') . '，在下方列表中点击「安装」即可启用。');
    redirect('store.php');
}

// 递归删除目录（应用商店上传专用）
function rmdir_recursively_store(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                return false;
            }
        } elseif (is_dir($path)) {
            if (!rmdir_recursively_store($path)) {
                return false;
            }
        }
    }
    return @rmdir($dir);
}

if ($action === 'save_config') {
    csrf_check();
    $code = trim((string)($_POST['code'] ?? ''));
    $scan = plugin_scan();
    $plugin = $scan[$code] ?? null;
    $fields = $plugin['config'] ?? null;

    if (!$plugin || !is_array($fields) || !$fields) {
        flash_set('error', '该插件无配置项或插件不存在。');
    } elseif (plugin_status_get($code) === 3) {
        flash_set('error', '请先安装插件再配置。');
    } else {
        $kv = [];
        foreach ($fields as $f) {
            if ($f['type'] === 'switch') {
                $kv[$f['key']] = isset($_POST['cfg_' . $f['key']]) ? '1' : '0';
            } else {
                $val = trim((string)($_POST['cfg_' . $f['key']] ?? ($f['default'] ?? '')));
                if ($val === '' && (($f['type'] ?? '') === 'password' || !empty($f['secret']))) {
                    continue;
                }
                $kv[$f['key']] = $val;
            }
        }
        plugin_cfg_set($code, $kv);
        audit('plugin', 'config', 1, (int)$admin['id'], '配置插件：' . $plugin['name']);
        flash_set('ok', '插件「' . $plugin['name'] . '」配置已保存，保存即生效。');
    }
    redirect('store.php?act=config&code=' . $code);
}

$cats = [
    'all'      => '全部插件',
    'captcha'  => '行为验证',
    'login'    => '第三方登录',
    'pay'      => '支付插件',
    'realname' => '实名认证服务商',
    'template' => '首页模板',
];
$catNames = array_diff_key($cats, ['all' => '']);

$cat = (string)($_GET['cat'] ?? 'all');
if (!isset($cats[$cat])) {
    $cat = 'all';
}

// 应用商店搜索：按名称 / 标识 / 描述 / 作者 / 版本模糊匹配，与分类筛选可叠加
$kw = trim((string)($_GET['kw'] ?? ''));

$all = plugin_all();
uasort($all, fn($a, $b) => [$a['cat'], (int)$a['sort']] <=> [$b['cat'], (int)$b['sort']]);
if ($cat !== 'all') {
    $all = array_filter($all, fn($p) => $p['cat'] === $cat);
}
if ($kw !== '') {
    $all = array_filter($all, function ($p) use ($kw) {
        $haystack = implode("\n", [
            (string)($p['name'] ?? ''),
            (string)($p['code'] ?? ''),
            (string)($p['description'] ?? ''),
            (string)($p['author'] ?? ''),
            (string)($p['version'] ?? ''),
        ]);
        return mb_stripos($haystack, $kw) !== false;
    });
}
$rows = array_values($all);

$editPlugin = null;
if (($_GET['act'] ?? '') === 'config') {
    $code = trim((string)($_GET['code'] ?? ''));
    $editPlugin = $all[$code] ?? null;
    if (!$editPlugin || (int)$editPlugin['status'] === 3) {
        flash_set('error', '插件不存在或尚未安装。');
        redirect('store.php');
    }
}

$statusMap = [1 => ['已启用', 'tag-ok'], 2 => ['已停用', 'tag-warn'], 3 => ['未安装', 'tag-off']];

admin_header('应用商店', 'store', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<?php 
if ($editPlugin):
    $fields = is_array($editPlugin['config'] ?? null) ? $editPlugin['config'] : [];
?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">插件配置：<?php echo e($editPlugin['name']); ?></h2>
            <p class="card-desc" style="margin-bottom:0">
                <span class="tag <?php echo $statusMap[(int)$editPlugin['status']][1] ?? 'tag-off'; ?>"><?php echo $statusMap[(int)$editPlugin['status']][0] ?? '未知'; ?></span>
                &nbsp;<?php echo e($editPlugin['description']); ?>
            </p>
        </div>
        <div class="spacer"></div>
        <a class="btn-plain" href="store.php">返回列表</a>
    </div>

    <?php if ($editPlugin['code'] === 'mail'): ?>
    <div class="alert alert-info">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>邮件插件提供完整的可视化模板管理，请前往 <a href="mail_plugin.php?scene=deliver" style="color:#0052D9">邮件插件管理页</a> 进行配置。</span>
    </div>
    <?php elseif (!$fields): ?>
    <div class="alert alert-ok">
        <img src="../assets/svg/check-circle.svg" alt="">
        <span>该插件无需配置参数，<?php echo (int)$editPlugin['status'] === 1 ? '当前已启用并生效。' : '安装后启用即可生效。'; ?></span>
    </div>
    <?php else: ?>
    <?php if ((int)$editPlugin['status'] !== 1): ?>
    <div class="alert alert-info">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>插件当前未启用，配置保存后请回到列表页点击「启用」才会生效。</span>
    </div>
    <?php endif; ?>
    <?php if ((string)($editPlugin['cat'] ?? '') === 'template'):
        $activeTpl = '';
        foreach (['tpl_v2', 'tpl_v1', 'default'] as $pc) {
            if (isset($all[$pc]) && (int)$all[$pc]['status'] === 1) {
                $activeTpl = $pc;
                break;
            }
        }
        if ($activeTpl === '') {
            foreach ($all as $oc => $op) {
                if ((string)($op['cat'] ?? '') === 'template' && (int)$op['status'] === 1) {
                    $activeTpl = $oc;
                    break;
                }
            }
        }
        $tplActive = $activeTpl === (string)$editPlugin['code'];
    ?>
    <?php if ($tplActive): ?>
    <div class="alert alert-ok">
        <img src="../assets/svg/check-circle.svg" alt="">
        <span>该模板为当前生效模板，配置保存后刷新首页立即生效。</span>
    </div>
    <?php else: ?>
    <div class="alert alert-info">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>该模板当前未生效<?php echo $activeTpl !== '' ? '（当前生效：' . e($all[$activeTpl]['name'] ?? $activeTpl) . '）' : ''; ?>，因此这里的配置不会显示在首页。保存后请回到列表页「启用」本模板——模板互斥，启用时会自动停用其他模板。</span>
    </div>
    <?php endif; endif; ?>
    <form method="post" class="form" style="max-width:640px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_config">
        <input type="hidden" name="code" value="<?php echo e($editPlugin['code']); ?>">
        <?php foreach ($fields as $f):
            $cur = plugin_cfg_get((string)$editPlugin['code'], $f['key'], (string)($f['default'] ?? '')); ?>
        <div class="form-item">
            <label class="form-label"><?php echo e($f['label']); ?></label>
            <?php if ($f['type'] === 'textarea'): ?>
            <textarea class="form-input" name="cfg_<?php echo e($f['key']); ?>" rows="4"
                placeholder="<?php echo e($f['hint'] ?? ''); ?>"><?php echo empty($f['secret']) ? e($cur) : ''; ?></textarea>
            <?php elseif ($f['type'] === 'select'): ?>
            <select class="form-input" name="cfg_<?php echo e($f['key']); ?>">
                <?php foreach (($f['options'] ?? []) as $ov => $ol): ?>
                <option value="<?php echo e((string)$ov); ?>" <?php echo (string)$cur === (string)$ov ? 'selected' : ''; ?>><?php echo e((string)$ol); ?></option>
                <?php endforeach; ?>
            </select>
            <?php elseif ($f['type'] === 'switch'): ?>
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" name="cfg_<?php echo e($f['key']); ?>" value="1" <?php echo $cur === '1' ? 'checked' : ''; ?>>
                <span>开启</span>
            </label>
            <?php elseif ($f['type'] === 'password'): ?>
            <input class="form-input" type="password" name="cfg_<?php echo e($f['key']); ?>" value=""
                placeholder="已配置则留空保持不变" autocomplete="new-password">
            <?php else: ?>
            <input class="form-input" type="text" name="cfg_<?php echo e($f['key']); ?>" value="<?php echo e($cur); ?>"
                placeholder="<?php echo e($f['hint'] ?? ''); ?>">
            <?php endif; ?>
            <?php if (!empty($f['hint']) && $f['type'] !== 'textarea'): ?>
            <div class="form-hint"><?php echo e($f['hint']); ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <button class="btn-primary" type="submit">保存配置</button>
    </form>
    <?php endif; ?>
</section>

<?php
else: ?>
<section class="card" id="pluginUploadCard" style="display:none">
    <h2 class="card-title">上传应用（插件包）</h2>
    <p class="card-desc">上传 .zip 格式的插件包，系统将<b>自动解压</b>安装到 plugin/ 目录；解压完成后在下方列表中点击「安装」并「启用」即可生效。</p>
    <form method="post" enctype="multipart/form-data" class="form" style="max-width:640px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="upload">
        <div class="form-item">
            <label class="form-label">插件包（.zip，单包最大 50MB）</label>
            <input class="form-input" type="file" name="plugin_zip" accept=".zip,application/zip" required>
        </div>
        <div class="form-hint" style="line-height:20px;margin-bottom:14px">
            · 包结构要求：zip 内需有<b>一层文件夹</b>（如 <code>my_plugin/</code>），插件文件放在该文件夹内，且必须包含 <code>Plugin.php</code>，上传后自动解压到 plugin/ 目录<br>
            · 兼容模式：若插件文件直接位于 zip 根目录（含 <code>Plugin.php</code>），系统会自动按插件标识创建一层文件夹包裹<br>
            · 若 plugin/ 下已存在同名插件，将自动覆盖为上传的新版本
        </div>
        <button class="btn-primary" type="submit">上传并自动解压</button>
    </form>
</section>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">应用商店（共 <?php echo count($rows); ?> 个插件）</h2>
            <p class="card-desc" style="margin-bottom:0">插件位于 plugin/ 目录，扫描自动发现；启用状态与配置保存在数据库 plugin 表（敏感密钥单独存 sys_config，更安全），实时生效。</p>
        </div>
        <div class="spacer"></div>
        <a class="btn-mini" href="javascript:;" style="text-decoration:none;line-height:30px;margin-right:8px" id="pluginUploadToggle"
           onclick="(function(){var c=document.getElementById('pluginUploadCard');var show=c.style.display==='none';c.style.display=show?'':'none';document.getElementById('pluginUploadToggle').textContent=show?'收起上传':'上传应用';})();return false;">上传应用</a>
        <form method="get" style="display:flex;gap:8px;align-items:center;margin-right:8px">
            <?php if ($cat !== 'all'): ?><input type="hidden" name="cat" value="<?php echo e($cat); ?>"><?php endif; ?>
            <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="搜索插件：名称 / 标识 / 描述 / 作者" style="width:230px;height:30px">
            <button class="btn-mini" type="submit" style="height:30px">搜索</button>
            <?php if ($kw !== ''): ?><a class="btn-mini-plain" href="store.php<?php echo $cat !== 'all' ? '?cat=' . e($cat) : ''; ?>" style="text-decoration:none;line-height:28px">清除</a><?php endif; ?>
        </form>
        <div class="filter-bar" style="margin-bottom:0">
            <?php foreach ($cats as $key => $name): ?>
            <a class="btn-mini-plain <?php echo $cat === $key ? 'btn-mini' : ''; ?>" href="store.php?cat=<?php echo $key; ?><?php echo $kw !== '' ? '&kw=' . urlencode($kw) : ''; ?>" style="text-decoration:none;line-height:30px"><?php echo $name; ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-app.svg" alt="">
        <p><?php echo $kw !== '' ? '未找到与「' . e($kw) . '」匹配的插件，请更换关键词试试。' : '该分类下暂无插件。'; ?></p>
    </div>
    <?php else: ?>
    <div class="store-grid">
        <?php foreach ($rows as $p):
            [$stName, $stTag] = $statusMap[(int)$p['status']] ?? ['未知', 'tag-off'];
            $installed = (int)$p['status'] !== 3;
            $cfgFields = is_array($p['config'] ?? null) ? $p['config'] : [];
            $unconfigured = false;
            if ($installed && $cfgFields) {
                $unconfigured = true;
                foreach ($cfgFields as $f) {
                    if (plugin_cfg_get((string)$p['code'], (string)$f['key'], '') !== '') {
                        $unconfigured = false;
                        break;
                    }
                }
            }
            $plugDir = ROOT_PATH . '/plugin/' . $p['dir'];
            $plugIcon = 'menu-app.svg';
            $iconName = basename(trim((string)($p['icon'] ?? '')));
            if ($iconName !== '' && is_file($plugDir . '/' . $iconName)) {
                $plugIcon = '../plugin/' . rawurlencode($p['dir']) . '/' . rawurlencode($iconName);
            } else {
                foreach (['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico'] as $ext) {
                    $found = glob($plugDir . '/*.' . $ext);
                    if ($found) {
                        $plugIcon = '../plugin/' . rawurlencode($p['dir']) . '/' . rawurlencode(basename($found[0]));
                        break;
                    }
                }
            }
        ?>
        <div style="border:1px solid var(--border,#E5E6EB);border-radius:8px;padding:14px;background:#FFF;transition:border-color .15s;display:flex;flex-direction:column"
             onmouseover="this.style.borderColor='#0052D9'" onmouseout="this.style.borderColor='var(--border,#E5E6EB)'">
            <div style="display:flex;gap:10px;flex:1">
                <div style="width:40px;height:40px;flex:none;border-radius:8px;background:var(--primary-bg,#F0F5FF);display:flex;align-items:center;justify-content:center">
                    <img src="<?php echo $plugIcon; ?>" alt="" style="width:22px;height:22px">
                </div>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px 6px">
                        <span style="font-size:14px;font-weight:600;color:#1D2129"><?php echo e($p['name']); ?></span>
                        <?php if ((int)$p['core'] === 1 || strpos((string)$p['author'], '官方') !== false): ?>
                        <span style="font-size:11px;color:#0052D9;border:1px solid #0052D9;border-radius:4px;padding:0 6px;line-height:16px;flex:none">官方</span>
                        <?php endif; ?>
                        <span style="font-size:11px;color:#86909C;border:1px solid var(--border,#E5E6EB);border-radius:4px;padding:0 6px;line-height:16px;flex:none">v<?php echo e($p['version']); ?></span>
                    </div>
                    <p style="font-size:12px;color:#4E5969;line-height:20px;margin:6px 0 0"><?php echo e($p['description']); ?></p>
                </div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border,#F2F3F5)">
                <span style="display:flex;align-items:center;gap:8px">
                    <span class="tag <?php echo (int)$p['status'] === 1 ? 'tag-ok' : 'tag-off'; ?>"><?php echo $installed ? $stName : ((int)$p['installable'] === 1 ? '未安装' : '待上架'); ?></span>
                    <?php if ($unconfigured): ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#D25F00">
                        <img src="../assets/svg/warn-circle.svg" alt="" style="width:14px;height:14px">未配置
                    </span>
                    <?php endif; ?>
                </span>
                <span style="display:flex;gap:6px;align-items:center">
                    <?php if (!$installed && (int)$p['installable'] === 1): ?>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="install">
                        <input type="hidden" name="code" value="<?php echo e($p['code']); ?>">
                        <input type="hidden" name="cat" value="<?php echo e($cat); ?>">
                        <button class="btn-mini" type="submit">安装</button>
                    </form>
                    <?php elseif ($installed): ?>
                    <a class="btn-mini-plain" href="<?php echo $p['code'] === 'mail' ? 'mail_plugin.php?scene=deliver' : ('store.php?act=config&code=' . e($p['code'])); ?>">配置</a>
                    <?php if ((int)$p['status'] === 1): ?>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="disable">
                        <input type="hidden" name="code" value="<?php echo e($p['code']); ?>">
                        <input type="hidden" name="cat" value="<?php echo e($cat); ?>">
                        <button class="btn-mini-plain" type="submit" data-confirm="确认停用插件「<?php echo e($p['name']); ?>」？对应功能入口将立即关闭。">停用</button>
                    </form>
                    <?php else: ?>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="enable">
                        <input type="hidden" name="code" value="<?php echo e($p['code']); ?>">
                        <input type="hidden" name="cat" value="<?php echo e($cat); ?>">
                        <button class="btn-mini" type="submit">启用</button>
                    </form>
                    <?php endif; ?>
                    <?php if ((int)$p['core'] === 0): ?>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="uninstall">
                        <input type="hidden" name="code" value="<?php echo e($p['code']); ?>">
                        <input type="hidden" name="cat" value="<?php echo e($cat); ?>">
                        <button class="btn-mini-plain" type="submit" data-confirm="确认卸载插件「<?php echo e($p['name']); ?>」？卸载后可重新安装。">卸载</button>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
