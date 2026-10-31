<?php
// 关于系统：产品信息、版本、版权与开发团队
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$envItems = [
    ['PHP 版本', PHP_VERSION],
    ['服务器软件', $_SERVER['SERVER_SOFTWARE'] ?? '-'],
    ['GD 扩展（验证码）', extension_loaded('gd') ? '已加载' : '未加载（自动降级为 SVG 验证码）'],
    ['cURL 扩展（支付/邮件）', extension_loaded('curl') ? '已加载' : '未加载'],
    ['OpenSSL 扩展（安全签名）', extension_loaded('openssl') ? '已加载' : '未加载'],
    ['真实 IP 获取', cfg('trust_proxy_ip', '1') === '1' ? '已开启' : '已关闭'],
    ['注册验证码', cfg('register_captcha', '1') === '1' ? '已开启' : '已关闭'],
    ['官方 QQ 群', PRODUCT_QQ_GROUP],
];

admin_header('关于系统', 'about', $admin);
?>
<div class="settings-head">
    <div>
        <h1 class="settings-title">关于系统</h1>
        <p class="settings-sub">产品信息 · 版本 · 版权声明</p>
    </div>
</div>

<section class="card" style="max-width:860px">
    <div style="display:flex;align-items:center;gap:18px;margin-bottom:20px">
        <img src="<?php echo e(site_logo_url('../')); ?>" alt="logo" style="width:64px;height:64px;border-radius:12px">
        <div>
            <div style="font-size:20px;font-weight:700;color:var(--text-primary,#1D2129)"><?php echo e(PRODUCT_FULLNAME); ?></div>
            <div style="font-size:13px;color:var(--text-third,#86909C);margin-top:4px">V<?php echo e(PRODUCT_VERSION); ?>（内核 V<?php echo e(SYS_VERSION); ?>）</div>
        </div>
    </div>

    <div class="card-title" style="margin-top:8px">开发团队</div>
    <table class="tb" style="margin-bottom:20px">
        <tbody>
        <tr><th style="width:180px">原作者</th><td><b><?php echo e(PRODUCT_AUTHOR_ORIGINAL); ?></b></td></tr>
        <tr><th>修改 / 二次开发</th><td><b><?php echo e(PRODUCT_AUTHOR_MODIFIED); ?></b></td></tr>
        <tr><th>官方 QQ 群</th><td><b><?php echo e(PRODUCT_QQ_GROUP); ?></b>（交流与反馈）</td></tr>
        </tbody>
    </table>

    <div class="card-title">版权与使用声明</div>
    <div class="alert alert-warn" style="margin-bottom:16px">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span><b>二次开发请保留版权：</b>本系统基于开源精神发布，进行二次开发后请保留原作者与修改者版权信息及本页面入口，谢谢尊重开发者劳动成果。</span>
    </div>
    <p class="card-desc" style="line-height:22px">
        本系统提供软件授权、卡密分发、域名授权验证、多应用管理、代理商体系等能力；
        V2.0 起行为验证全面插件化（Cloudflare Turnstile / 极验4.0 / 彩虹聚合登录等），并新增真实 IP 获取能力。
        插件与主题开发请参见系统 <code>docs/</code> 目录下的《插件开发文档》与《主题开发文档》。
    </p>
</section>

<section class="card" style="max-width:860px">
    <div class="card-title">运行环境</div>
    <table class="tb">
        <tbody>
        <?php foreach ($envItems as [$k, $v]): ?>
        <tr><th style="width:220px"><?php echo e($k); ?></th><td><?php echo e((string)$v); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php admin_footer(); ?>
