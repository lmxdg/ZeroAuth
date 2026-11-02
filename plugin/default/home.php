<?php
// 默认首页模板
declare(strict_types=1);

$siteName = cfg('site_name', 'ZeroAuth');
$contacts = site_contacts_html();
$icp = site_icp_html();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($siteName); ?> - 软件授权 &amp; 域名授权管理系统</title>
<?php echo site_meta_tags(''); ?>
<link rel="stylesheet" href="plugin/<?php echo e($tplDir); ?>/home.css">
</head>
<body class="dft-body">

<header class="dft-topbar">
    <div class="dft-inner dft-topbar-inner">
        <a class="dft-brand" href="./">
            <img src="<?php echo e(site_logo_url('')); ?>" alt="">
            <span><?php echo e($siteName); ?></span>
        </a>
        <nav class="dft-topnav">
            <a href="agent/"><img src="assets/svg/menu-agent.svg" alt=""><span>代理商中心</span></a>
            <a href="user/"><img src="assets/svg/user.svg" alt=""><span>用户中心</span></a>
        </nav>
    </div>
</header>

<main class="dft-main">
    <div class="dft-inner">

        <section class="dft-hero">
            <div class="dft-hero-text">
                <h1>软件授权<span class="dft-dot">·</span>域名授权管理</h1>
                <p>为 PHP 程序与模板提供卡密授权、域名绑定、设备指纹校验的一站式授权方案。支持限时 / 计次 / 永久三种授权类型，HMAC-SHA256 接口签名，多应用隔离，代理商分级分销。</p>
                <div class="dft-hero-entry">
                    <a class="dft-btn dft-btn-primary" href="user/">进入用户中心</a>
                    <a class="dft-btn dft-btn-plain" href="agent/">代理商合作</a>
                </div>
            </div>

            <div class="dft-query-card">
                <div class="dft-query-title">
                    <img src="assets/svg/menu-home.svg" alt="">
                    <span>域名授权查询</span>
                </div>
                <p class="dft-query-desc">输入域名查询授权状态，仅展示授权有效性，不涉及卡密等隐私信息。</p>
                <form class="dft-query-form" method="get" action="">
                    <input class="dft-query-input" type="text" name="q" value="<?php echo e($query_domain); ?>" placeholder="请输入域名，如 example.com" maxlength="255">
                    <button class="dft-query-btn" type="submit">查 询</button>
                </form>
                <?php if ($result !== null): ?>
                <div class="dft-query-result">
                    <div class="dft-result dft-result-<?php echo e($result['level']); ?>">
                        <img src="assets/svg/<?php echo $result['level'] === 'ok' ? 'check-circle' : ($result['level'] === 'warn' ? 'warn-circle' : 'close-circle'); ?>.svg" alt="">
                        <div>
                            <div class="dft-r-main"><?php echo e($result['main']); ?></div>
                            <div class="dft-r-sub"><?php echo e($result['sub']); ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="dft-features">
            <h2 class="dft-sec-title">核心能力</h2>
            <p class="dft-sec-sub">从授权生成到接口校验的完整闭环</p>
            <div class="dft-feature-grid">
                <div class="dft-feature-card">
                    <img src="assets/svg/menu-key.svg" alt="">
                    <h3>卡密授权</h3>
                    <p>限时、计次、永久三种类型，支持批量生成、一键封禁与到期提醒。</p>
                </div>
                <div class="dft-feature-card">
                    <img src="assets/svg/menu-home.svg" alt="">
                    <h3>域名授权</h3>
                    <p>域名归一化精确绑定，程序与模板均可按域名鉴权，支持 HTTPS 校验。</p>
                </div>
                <div class="dft-feature-card">
                    <img src="assets/svg/menu-code.svg" alt="">
                    <h3>设备指纹</h3>
                    <p>设备码绑定与上限控制，客户端自助解绑，后台可强制解绑。</p>
                </div>
                <div class="dft-feature-card">
                    <img src="assets/svg/menu-agent.svg" alt="">
                    <h3>多级分销</h3>
                    <p>管理员、代理商、用户三级隔离，代理商独立折扣与邀请码绑定。</p>
                </div>
            </div>
        </section>

    </div>
</main>

<footer class="dft-footer">
    <div class="dft-inner dft-footer-inner">
        <span class="dft-copy">© <?php echo date('Y'); ?> <?php echo e($siteName); ?> · V<?php echo e(PRODUCT_VERSION); ?></span>
        <?php if ($contacts !== ''): ?><span class="dft-foot-contacts"><?php echo $contacts; ?></span><?php endif; ?>
        <?php echo $icp; ?>
    </div>
</footer>

</body>
</html>
