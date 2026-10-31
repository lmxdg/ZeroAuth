<?php
// 管理员后台 - 应用商店
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/PluginSys.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$action = (string)($_POST['action'] ?? '');
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

$all = plugin_all();
uasort($all, fn($a, $b) => [$a['cat'], (int)$a['sort']] <=> [$b['cat'], (int)$b['sort']]);
$rows = $cat === 'all' ? $all : array_values(array_filter($all, fn($p) => $p['cat'] === $cat));

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
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">应用商店（共 <?php echo count($rows); ?> 个插件）</h2>
            <p class="card-desc" style="margin-bottom:0">插件位于 plugin/ 目录，扫描自动发现；启用状态与配置保存在数据库 plugin 表（敏感密钥单独存 sys_config，更安全），实时生效。</p>
        </div>
        <div class="spacer"></div>
        <div class="filter-bar" style="margin-bottom:0">
            <?php foreach ($cats as $key => $name): ?>
            <a class="btn-mini-plain <?php echo $cat === $key ? 'btn-mini' : ''; ?>" href="store.php?cat=<?php echo $key; ?>" style="text-decoration:none;line-height:30px"><?php echo $name; ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-app.svg" alt="">
        <p>该分类下暂无插件。</p>
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:6px">
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
