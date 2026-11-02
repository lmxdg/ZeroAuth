<?php
// 首页模板 V4（渐变清新 · 玻璃拟态）
declare(strict_types=1);

$siteName = cfg('site_name', 'ZeroAuth');

$features = [
    ['icon' => 'menu-key',     'name' => '一键授权',    'desc' => '卡密即买即用，域名一键绑定，授权状态实时生效，无需繁琐配置。'],
    ['icon' => 'menu-lock',    'name' => '安全护航',    'desc' => '接口签名 + 防重放 + 数据加密三重保障，授权验证安全无忧。'],
    ['icon' => 'menu-home',    'name' => '域名管家',    'desc' => '单域名 / 泛域名灵活绑定，更换域名自助操作，省心又省力。'],
    ['icon' => 'menu-code',    'name' => '设备绑定',    'desc' => '设备指纹防多开，绑定数量自定义，换机自助解绑轻松管理。'],
    ['icon' => 'menu-agent',   'name' => '代理成长',    'desc' => '邀请好友成为代理，专属折扣自动生效，收益明细一目了然。'],
    ['icon' => 'menu-money',   'name' => '余额支付',    'desc' => '余额充值、在线购买、订单查询全流程线上化，到账实时提示。'],
];

$steps = [
    ['no' => '01', 'name' => '注册账号', 'desc' => '邮箱即可快速注册，填写邀请码还能绑定专属代理。'],
    ['no' => '02', 'name' => '挑选套餐', 'desc' => '按需选择授权时长与类型，在线支付实时到账。'],
    ['no' => '03', 'name' => '绑定使用', 'desc' => '绑定域名或设备后立即生效，随时查询续期。'],
];

$badges = ['实时生效', '安全加密', '自助管理', '贴心售后'];
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
<body class="gs-body">

<div class="gs-bg" aria-hidden="true">
    <span class="gs-orb gs-o1"></span><span class="gs-orb gs-o2"></span><span class="gs-orb gs-o3"></span>
</div>

<header class="gs-topbar">
    <div class="gs-inner gs-topbar-inner">
        <a class="gs-brand" href="./">
            <img src="<?php echo e(site_logo_url('')); ?>" alt="">
            <span><?php echo e($siteName); ?></span>
        </a>
        <nav class="gs-nav">
            <a href="./" class="on">首页</a>
            <a href="#features">服务特色</a>
            <a href="#steps">购买流程</a>
            <a href="#query">授权查询</a>
            <a href="agent/">代理商中心</a>
        </nav>
        <div class="gs-topbar-ops">
            <a class="gs-op-plain" href="user/">登录</a>
            <a class="gs-op-primary" href="user/register.php">免费注册</a>
        </div>
    </div>
</header>

<main class="gs-main">

    <section class="gs-hero">
        <div class="gs-inner gs-hero-inner">
            <div class="gs-hero-text">
                <div class="gs-hero-badges">
                    <?php foreach ($badges as $b): ?><span><?php echo e($b); ?></span><?php endforeach; ?>
                </div>
                <h1>软件授权<br><em>也可以又快又美</em></h1>
                <p class="gs-hero-sub">从购买、绑定到查询与续期，为你的每一份软件提供轻盈顺畅的授权体验。</p>
                <div class="gs-hero-ops">
                    <a class="gs-btn gs-btn-primary" href="user/">开始使用</a>
                    <a class="gs-btn gs-btn-soft" href="#query">查询授权</a>
                </div>
            </div>

            <div class="gs-hero-card" aria-hidden="true">
                <div class="gs-ch-row">
                    <span class="gs-ch-ico">🔐</span>
                    <div><b>授权状态</b><span>有效 · 永久授权</span></div>
                    <span class="gs-ch-tag">Live</span>
                </div>
                <div class="gs-ch-row">
                    <span class="gs-ch-ico">🌐</span>
                    <div><b>绑定域名</b><span>yourapp.com</span></div>
                    <span class="gs-ch-tag gs-ch-tag-v">OK</span>
                </div>
                <div class="gs-ch-row">
                    <span class="gs-ch-ico">🛡️</span>
                    <div><b>安全防护</b><span>HMAC 签名 · 防重放</span></div>
                    <span class="gs-ch-tag gs-ch-tag-v">OK</span>
                </div>
                <div class="gs-ch-bar"><i></i></div>
                <p class="gs-ch-note">授权数据实时同步中</p>
            </div>
        </div>
    </section>

    <section class="gs-query" id="query">
        <div class="gs-inner">
            <div class="gs-sec-head">
                <h2>授权查询</h2>
                <p>输入域名即可查询授权状态与有效期，即时返回结果。</p>
            </div>
            <div class="gs-query-panel">
                <form class="gs-query-form" method="get" action="./#query">
                    <input class="gs-query-input" type="text" name="q" value="<?php echo e($query_domain); ?>" placeholder="请输入域名，如 example.com" maxlength="255">
                    <button class="gs-query-btn" type="submit">查询</button>
                </form>
                <?php if ($result !== null): ?>
                <div class="gs-result gs-result-<?php echo e($result['level']); ?>">
                    <img src="assets/svg/<?php echo $result['level'] === 'ok' ? 'check-circle' : ($result['level'] === 'warn' ? 'warn-circle' : 'close-circle'); ?>.svg" alt="">
                    <div>
                        <b><?php echo e($result['main']); ?></b>
                        <span><?php echo e($result['sub']); ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="gs-features" id="features">
        <div class="gs-inner">
            <div class="gs-sec-head">
                <h2>为什么选择我们</h2>
                <p>把复杂的授权逻辑留给我们，把简单的体验留给你。</p>
            </div>
            <div class="gs-feature-grid">
                <?php foreach ($features as $f): ?>
                <div class="gs-feature-card">
                    <div class="gs-feature-icon"><img src="assets/svg/<?php echo e($f['icon']); ?>.svg" alt=""></div>
                    <h3><?php echo e($f['name']); ?></h3>
                    <p><?php echo e($f['desc']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="gs-steps" id="steps">
        <div class="gs-inner">
            <div class="gs-sec-head">
                <h2>三步上手</h2>
                <p>从注册到完成授权，最快只需一分钟。</p>
            </div>
            <div class="gs-step-grid">
                <?php foreach ($steps as $s): ?>
                <div class="gs-step-card">
                    <span class="gs-step-no"><?php echo e($s['no']); ?></span>
                    <h3><?php echo e($s['name']); ?></h3>
                    <p><?php echo e($s['desc']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="gs-cta">
        <div class="gs-inner gs-cta-inner">
            <h2>准备好开始了吗？</h2>
            <p>免费注册账号，即刻体验丝滑的授权管理。</p>
            <div class="gs-cta-ops">
                <a class="gs-btn gs-btn-white" href="user/register.php">免费注册</a>
                <a class="gs-btn gs-btn-outline" href="user/">已有账号登录</a>
            </div>
        </div>
    </section>

</main>

<footer class="gs-footer">
    <div class="gs-inner gs-footer-grid">
        <div class="gs-foot-brand-col">
            <div class="gs-foot-brand">
                <img src="<?php echo e(site_logo_url('')); ?>" alt="">
                <span><?php echo e($siteName); ?></span>
            </div>
            <p class="gs-foot-desc">轻盈优雅的软件授权与域名授权管理平台。</p>
        </div>
        <div class="gs-foot-col">
            <h4>快速入口</h4>
            <a href="user/">用户中心</a>
            <a href="agent/">代理商中心</a>
            <a href="#query">授权查询</a>
            <a href="#features">服务特色</a>
        </div>
        <div class="gs-foot-col">
            <h4>联系我们</h4>
            <?php $contacts = site_contacts_html(); ?>
            <?php echo $contacts !== '' ? $contacts : '<span class="gs-foot-line">详见后台站点信息配置</span>'; ?>
        </div>
        <div class="gs-foot-col">
            <h4>版本信息</h4>
            <span class="gs-foot-line"><?php echo e($siteName); ?> V<?php echo e(PRODUCT_VERSION); ?></span>
            <span class="gs-foot-line">内核 V<?php echo e(SYS_VERSION); ?> · <?php echo date('Y'); ?></span>
            <a class="gs-foot-line gs-foot-github" href="<?php echo e(PRODUCT_GITHUB); ?>" target="_blank" rel="noopener noreferrer">GitHub 开源</a>
        </div>
    </div>
    <div class="gs-foot-bar">
        <div class="gs-inner gs-foot-bar-inner">
            <span>© <?php echo date('Y'); ?> <?php echo e($siteName); ?> · 原作者 <?php echo e(PRODUCT_AUTHOR_ORIGINAL); ?> · 二开 <?php echo e(PRODUCT_AUTHOR_DEV); ?></span>
            <?php echo site_icp_html(); ?>
        </div>
    </div>
</footer>

</body>
</html>
