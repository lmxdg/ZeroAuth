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
    ['手机号注册', cfg('register_phone_enabled', '0') === '1' ? '已开启' : '已关闭'],
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
        <tr><th>二开作者</th><td><b><?php echo e(PRODUCT_AUTHOR_DEV); ?></b>（二次开发与维护）</td></tr>
        <tr><th>开源地址</th><td><a href="<?php echo e(PRODUCT_GITHUB); ?>" target="_blank" rel="noopener noreferrer" style="color:#0052D9;word-break:break-all"><?php echo e(PRODUCT_GITHUB); ?></a></td></tr>
        <tr><th>官方 QQ 群</th><td><b><?php echo e(PRODUCT_QQ_GROUP); ?></b>（交流与反馈）</td></tr>
        </tbody>
    </table>

    <div class="card-title">版权与使用声明</div>
    <div class="alert alert-warn" style="margin-bottom:16px">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span><b>二次开发请保留版权：</b>原作者 <?php echo e(PRODUCT_AUTHOR_ORIGINAL); ?>，二开作者 <?php echo e(PRODUCT_AUTHOR_DEV); ?>。本系统基于开源精神发布（开源地址 <a href="<?php echo e(PRODUCT_GITHUB); ?>" target="_blank" rel="noopener noreferrer" style="color:#D25F00"><?php echo e(PRODUCT_GITHUB); ?></a>），进行二次开发后请保留原作者与二开作者的版权信息及本页面入口，谢谢尊重开发者劳动成果。</span>
    </div>
    <p class="card-desc" style="line-height:22px">
        本系统提供软件授权、卡密分发、域名授权验证、多应用管理、代理商体系等能力；
        V2.2.0 起应用商店拆分为主题商店与插件商店，实名 / 验证码 / 短信设置统一迁入系统设置，支持手机号注册（短信宝 / 赛邮）与后台一键在线更新（update.php）。
        V2.2.2 起后台一键更新支持页内分块下载与实时进度显示，下载完成后自动替换文件。
        V2.2.3 起内置 AI 客服（用户中心悬浮窗，标准 OpenAI 接口，后台配置）与 Bug 广场（用户分享 Bug、互助回复、标记解决）。
        插件与主题开发请参见系统 <code>docs/</code> 目录下的《插件开发文档》与《主题开发文档》。
    </p>
</section>

<section class="card" style="max-width:860px">
    <div class="card-title">合作伙伴</div>
    <style>
    .partner-card{display:flex;align-items:center;gap:16px;padding:16px 18px;border:1px solid var(--border,#E5E6EB);border-radius:10px;background:var(--bg-page,#F7F8FA);text-decoration:none;transition:border-color .18s,background .18s,transform .18s}
    .partner-card:hover{border-color:var(--primary,#006EFF);background:var(--bg-card,#FFFFFF);transform:translateY(-1px);text-decoration:none}
    .partner-logo{height:48px;width:auto;flex:none;display:block}
    .partner-info{flex:1;min-width:0}
    .partner-name{font-size:15px;font-weight:700;line-height:22px;color:var(--text-main,#1D2129)}
    .partner-desc{font-size:12.5px;line-height:19px;color:var(--text-third,#86909C);margin-top:2px}
    .partner-btn{flex:none;display:inline-flex;align-items:center;justify-content:center;gap:4px;height:32px;padding:0 14px;border-radius:999px;font-size:13px;font-weight:600;color:var(--primary,#006EFF);background:var(--primary-bg,#ECF2FF)}
    .partner-card:hover .partner-btn{background:var(--primary,#006EFF);color:#FFFFFF}
    @media(max-width:768px){.partner-card{display:grid;grid-template-columns:auto minmax(0,1fr);gap:10px 12px;padding:14px}.partner-logo{height:38px}.partner-desc{font-size:12px;line-height:18px}.partner-btn{grid-column:1 / -1;width:100%;height:32px;font-size:12.5px;padding:0 12px}}
    </style>
    <a class="partner-card" href="<?php echo e(PARTNER_URL); ?>" target="_blank" rel="noopener noreferrer sponsored" title="<?php echo e(PARTNER_NAME . ' · ' . PARTNER_SLOGAN); ?>">
        <img class="partner-logo" src="../assets/img/ad/mw-logo.png" alt="<?php echo e(PARTNER_NAME); ?>">
        <div class="partner-info">
            <div class="partner-name"><?php echo e(PARTNER_NAME); ?></div>
            <div class="partner-desc"><?php echo e(PARTNER_SLOGAN); ?> · 助力您的上云之旅</div>
        </div>
        <span class="partner-btn">前往官网 →</span>
    </a>
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
