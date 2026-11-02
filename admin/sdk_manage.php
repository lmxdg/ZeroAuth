<?php
// 管理员后台 - SDK 模板管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$act = (string)($_GET['act'] ?? 'list');
$id = (int)($_GET['id'] ?? 0);
$error = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $tplId   = (int)($_POST['id'] ?? 0);
        $name    = trim((string)($_POST['name'] ?? ''));
        $lang    = trim((string)($_POST['language'] ?? 'php'));
        $content = (string)($_POST['content'] ?? '');

        if ($name === '' || mb_strlen($name) > 64) {
            $error = '模板名称不能为空且不超过 64 字。';
        } elseif (!sdk_language_valid($lang)) {
            $error = '不支持的模板语言，请从下拉列表中选择。';
        } elseif (!str_contains($content, '{{app_id}}') && !str_contains($content, '{{app_key}}')) {
            $error = '模板必须包含 {{app_id}} 或 {{app_key}} 占位符（导出时自动替换为对应应用标识）。';
        } elseif (strlen($content) > 512 * 1024) {
            $error = '模板内容过大（上限 512KB）。';
        } else {
            $now = time();
            if ($tplId > 0) {
                $stmt = db()->prepare('UPDATE `sdk_template` SET `name`=?,`language`=?,`content`=?,`update_time`=? WHERE `id`=?');
                $stmt->execute([$name, $lang, $content, $now, $tplId]);
                audit('sdk', 'template_update', 1, (int)$admin['id'], '模板ID：' . $tplId . ' 名称：' . $name);
            } else {
                $stmt = db()->prepare('INSERT INTO `sdk_template` (`name`,`language`,`content`,`status`,`created_at`,`update_time`) VALUES (?,?,?,1,?,?)');
                $stmt->execute([$name, $lang, $content, $now, $now]);
                audit('sdk', 'template_create', 1, (int)$admin['id'], '模板：' . $name);
            }
            flash_set('ok', '模板已保存。');
            redirect('sdk_manage.php');
        }
        $act = $tplId > 0 ? 'edit' : 'new';
        $id = $tplId;
    }

    if ($action === 'delete') {
        $tplId = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('DELETE FROM `sdk_template` WHERE `id`=?');
        $stmt->execute([$tplId]);
        audit('sdk', 'template_delete', 1, (int)$admin['id'], '模板ID：' . $tplId);
        flash_set('ok', '模板已删除。');
        redirect('sdk_manage.php');
    }

    if ($action === 'seed' || $action === 'seed_all') {
        $want = $action === 'seed_all' ? null : (string)($_POST['language'] ?? '');
        $builtins = sdk_builtin_templates();
        $existing = [];
        foreach (db()->query('SELECT `language` FROM `sdk_template`')->fetchAll(PDO::FETCH_COLUMN) as $lg) {
            $existing[(string)$lg] = true;
        }
        $now = time();
        $inserted = 0;
        $stmt = db()->prepare('INSERT INTO `sdk_template` (`name`,`language`,`content`,`status`,`created_at`,`update_time`) VALUES (?,?,?,1,?,?)');
        foreach ($builtins as $item) {
            $lg = $item['language'];
            if ($want !== null && $lg !== $want) {
                continue;
            }
            // 导入全部时跳过已有该语言模板的语言，避免重复
            if ($want === null && isset($existing[$lg])) {
                continue;
            }
            $stmt->execute([$item['name'], $lg, $item['content'], $now, $now]);
            $inserted++;
            $existing[$lg] = true;
        }
        if ($inserted > 0) {
            audit('sdk', 'template_seed', 1, (int)$admin['id'], '导入内置 SDK 模板 ' . $inserted . ' 套');
            flash_set('ok', '已导入 ' . $inserted . ' 套内置 SDK 模板。');
        } else {
            flash_set('ok', '没有需要导入的内置模板。');
        }
        redirect('sdk_manage.php');
    }
}

$tpl = null;
if ($id > 0 && in_array($act, ['edit', 'preview'], true)) {
    $stmt = db()->prepare('SELECT * FROM `sdk_template` WHERE `id`=?');
    $stmt->execute([$id]);
    $tpl = $stmt->fetch();
    if (!$tpl) {
        $error = '模板不存在或已删除。';
        $act = 'list';
    }
}

$apps = db()->query('SELECT `id`,`name`,`app_id` FROM `app` ORDER BY `id` DESC')->fetchAll();

// 各语言已导入模板数量（用于内置模板库状态展示）
$languageMap = sdk_language_map();
$langCounts = [];
foreach (db()->query('SELECT `language`, COUNT(*) AS `c` FROM `sdk_template` GROUP BY `language`')->fetchAll() as $lc) {
    $langCounts[(string)$lc['language']] = (int)$lc['c'];
}

admin_header('SDK 模板管理', 'sdk', $admin);
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
if ($act === 'list'):
    $rows = db()->query('SELECT `id`,`name`,`language`,`status`,`created_at`,`update_time` FROM `sdk_template` ORDER BY `id` DESC')->fetchAll();
?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">模板列表</h2>
            <p class="card-desc" style="margin-bottom:0">支持 PHP / Java / Python / Go / Node.js / C++ 多套模板；源码携带 <code>{{app_id}}</code>、<code>{{app_key}}</code>、<code>{{api_base}}</code> 占位符，导出时自动替换为目标应用信息与当前站点地址。</p>
        </div>
        <div class="spacer"></div>
        <a class="btn-primary" href="sdk_manage.php?act=new">新建模板</a>
    </div>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>模板名称</th><th>语言</th><th>状态</th><th>更新时间</th><th style="width:280px">操作</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="6"><div class="empty-state">暂无 SDK 模板，点击右上角「新建模板」创建第一套模板。</div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-main"><?php echo e($row['name']); ?></td>
                <td class="cell-sub"><?php echo e(sdk_language_label((string)$row['language'])); ?></td>
                <td><span class="tag <?php echo (int)$row['status'] === 1 ? 'tag-ok' : 'tag-off'; ?>"><?php echo (int)$row['status'] === 1 ? '启用' : '停用'; ?></span></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$row['update_time']); ?></td>
                <td>
                    <a class="btn-mini" href="sdk_manage.php?act=edit&id=<?php echo (int)$row['id']; ?>">编辑</a>
                    <a class="btn-mini-plain" href="sdk_manage.php?act=preview&id=<?php echo (int)$row['id']; ?>">预览</a>
                    <form method="post" style="display:inline" data-confirm="确定删除模板「<?php echo e($row['name']); ?>」吗？删除后不可恢复。">
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

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title" style="margin-bottom:0">内置多语言模板库</h2>
            <p class="card-desc" style="margin-bottom:0">内置 PHP / Java / Python / Go / Node.js / C++ 六套 V2 协议模板，导入后可在线修改，也可直接用于导出下载。</p>
        </div>
        <div class="spacer"></div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="seed_all">
            <button class="btn-plain" type="submit">一键导入缺失模板</button>
        </form>
    </div>
    <div class="table-wrap" style="margin-top:12px">
        <table class="tb">
            <thead><tr><th style="width:110px">语言</th><th>说明</th><th style="width:110px">已导入</th><th style="width:150px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($languageMap as $lg => $meta): ?>
            <tr>
                <td class="cell-main"><?php echo e($meta['label']); ?></td>
                <td class="cell-sub"><?php echo e($meta['desc']); ?></td>
                <td class="cell-sub"><?php echo isset($langCounts[$lg]) ? $langCounts[$lg] . ' 套' : '<span class="tag tag-off">未导入</span>'; ?></td>
                <td>
                    <form method="post" style="display:inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="seed">
                        <input type="hidden" name="language" value="<?php echo e($lg); ?>">
                        <button class="btn-mini-plain" type="submit"<?php echo isset($langCounts[$lg]) ? ' data-confirm="已存在该语言模板，仍要再导入一套内置 ' . e($meta['label']) . ' 模板吗？"' : ''; ?>>导入<?php echo e($meta['label']); ?>模板</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2 class="card-title">导出打包 SDK</h2>
    <p class="card-desc">选择模板与目标应用，系统自动将模板内 <code>{{app_id}}</code>、<code>{{app_key}}</code>、<code>{{api_base}}</code> 替换为应用信息与当前站点地址后打包 ZIP 下载；主文件名与扩展名随模板语言自动适配。下载行为记录 SDK 下载审计日志。</p>
    <?php if (!$apps): ?>
    <div class="alert alert-info">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>暂无可用应用。应用管理模块于阶段3交付，届时可创建应用并获取 App ID 后在此导出 SDK。</span>
    </div>
    <?php endif; ?>
    <form method="post" action="sdk_export.php" id="sdkExportForm" class="form-row" style="max-width:100%">
        <?php echo csrf_field(); ?>
        <div class="form-item">
            <label class="form-label">SDK 模板</label>
            <select class="form-input" name="template_id" required>
                <option value="">请选择模板</option>
                <?php foreach ($rows as $row): ?>
                <option value="<?php echo (int)$row['id']; ?>"><?php echo e($row['name']); ?>（<?php echo e(sdk_language_label((string)$row['language'])); ?> #<?php echo (int)$row['id']; ?>）</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-item">
            <label class="form-label">目标应用</label>
            <select class="form-input" name="app_id" required>
                <option value="">请选择应用</option>
                <?php foreach ($apps as $app): ?>
                <option value="<?php echo (int)$app['id']; ?>"><?php echo e($app['name']); ?>（<?php echo e($app['app_id']); ?>）</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-item" style="align-self:flex-end">
            <button class="btn-primary" type="submit" <?php echo !$apps ? 'disabled' : ''; ?>>打包下载 ZIP</button>
        </div>
    </form>
</section>

<?php
elseif ($act === 'new' || $act === 'edit'): ?>
<section class="card">
    <h2 class="card-title"><?php echo $act === 'new' ? '新建 SDK 模板' : '编辑 SDK 模板'; ?></h2>
    <p class="card-desc">在线编辑模板源码：PHP 使用语法高亮，其他语言以纯文本预览。模板仅保留 <code>{{app_id}}</code> / <code>{{app_key}}</code> 占位符，AppSecret 由客户端通过密钥下发接口动态拉取，禁止硬编码。</p>
    <form method="post" class="form" style="max-width:100%">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int)($tpl['id'] ?? 0); ?>">
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">模板名称</label>
                <input class="form-input" type="text" name="name" maxlength="64" value="<?php echo e($tpl['name'] ?? ''); ?>" required>
            </div>
            <div class="form-item">
                <label class="form-label">模板语言</label>
                <select class="form-input" name="language" id="langSelect">
                    <?php foreach ($languageMap as $lg => $meta): ?>
                    <option value="<?php echo e($lg); ?>" <?php echo ($tpl['language'] ?? 'php') === $lg ? 'selected' : ''; ?>><?php echo e($meta['label']); ?>（导出为 .<?php echo e($meta['ext']); ?>）</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-item">
            <div class="editor-toolbar">
                <span>模板源码</span>
                <span class="editor-note">必须包含 <code>{{app_id}}</code> 或 <code>{{app_key}}</code> 占位符 · 可选 <code>{{api_base}}</code> 导出时自动替换为当前站点地址 · Tab 键输入 4 空格缩进 · 修改后预览自动刷新</span>
            </div>
            <div class="code-editor-wrap">
                <textarea class="code-editor" id="codeEditor" name="content" spellcheck="false"><?php echo e($tpl['content'] ?? "<?php\n// 新建 PHP 授权 SDK 模板\n// 应用ID（导出时自动替换）：{{app_id}}\n"); ?></textarea>
            </div>
        </div>
        <div class="form-item">
            <label class="form-label">高亮预览</label>
            <div class="code-editor-wrap">
                <pre class="code-view" id="codePreview" data-lang="<?php echo e(sdk_language_hl((string)($tpl['language'] ?? 'php'))); ?>"></pre>
            </div>
        </div>
        <div class="page-actions">
            <a class="btn-plain" href="sdk_manage.php">返回列表</a>
            <button class="btn-primary" type="submit">保存模板</button>
        </div>
    </form>
</section>

<?php
else: ?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">预览：<?php echo e($tpl['name']); ?></h2>
            <p class="card-desc" style="margin-bottom:0">语言：<?php echo e(sdk_language_label((string)$tpl['language'])); ?> · 以下内容中 <code>{{app_id}}</code> / <code>{{app_key}}</code> / <code>{{api_base}}</code> 将在导出时替换为实际应用信息与站点地址。</p>
        </div>
        <div class="spacer"></div>
        <a class="btn-plain" href="sdk_manage.php">返回列表</a>
    </div>
    <div class="code-editor-wrap" style="margin-top:14px">
        <pre class="code-view" data-lang="<?php echo e(sdk_language_hl((string)$tpl['language'])); ?>"><?php echo e($tpl['content']); ?></pre>
    </div>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
