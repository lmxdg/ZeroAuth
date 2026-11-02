<?php
// 管理员后台 - 开发者栏目：插件开发文档（渲染系统内置 Markdown 文档）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/MarkdownLite.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$docFile = ROOT_PATH . '/插件开发文档.md';
$content = is_file($docFile) ? (string)file_get_contents($docFile) : '';
$updatedAt = $content !== '' && is_file($docFile) ? date('Y-m-d H:i', (int)filemtime($docFile)) : '';

admin_header('插件开发文档', 'doc_plugin', $admin);
echo md_lite_styles();
?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">插件开发文档</h2>
            <p class="card-desc" style="margin-bottom:0">如何为 <?php echo e(PRODUCT_NAME); ?> 开发插件：Plugin.php 元数据、插件目录结构、配置项定义、插件钩子与安全规范。内容与系统内置文档 <code>插件开发文档.md</code> 实时同步。</p>
        </div>
        <div class="spacer"></div>
        <a class="btn-mini-plain" href="dev_docs_theme.php" style="text-decoration:none;line-height:30px">查看主题开发文档 →</a>
    </div>
    <?php if ($content !== ''): ?>
    <div class="md-doc"><?php echo md_lite_to_html($content); ?></div>
    <p class="card-desc" style="margin-top:14px">文档更新时间：<?php echo e($updatedAt); ?> · 更多资料请访问开源仓库 <a href="<?php echo e(PRODUCT_GITHUB); ?>" target="_blank" rel="noopener noreferrer"><?php echo e(PRODUCT_GITHUB); ?></a></p>
    <?php else: ?>
    <div class="alert alert-error">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>未找到插件开发文档文件（插件开发文档.md），请检查系统根目录文件完整性。</span>
    </div>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
