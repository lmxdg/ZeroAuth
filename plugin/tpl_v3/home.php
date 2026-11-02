<?php
// 首页模板 V3（极夜暗黑 · 科技风）
declare(strict_types=1);

$siteName = cfg('site_name', 'ZeroAuth');

$features = [
    ['icon' => 'menu-key',      'name' => '全类型授权',   'desc' => '限时、计次、永久三种授权类型，覆盖域名、泛域名、IP 与纯密钥部署形态。'],
    ['icon' => 'menu-lock',     'name' => '加密与签名',   'desc' => 'AES-256-GCM 数据加密、HMAC-SHA256 接口签名、RSA-2048 响应验签，多层防护。'],
    ['icon' => 'menu-home',     'name' => '域名强绑定',   'desc' => '域名归一化精确绑定，实时校验授权状态，异常访问秒级拦截。'],
    ['icon' => 'menu-code',     'name' => '设备指纹',     'desc' => '设备码绑定与数量上限控制，客户端自助解绑，后台强制下线。'],
    ['icon' => 'menu-agent',    'name' => '代理分销',     'desc' => '多级代理商折扣与分成，邀请码自动绑定，名下数据完全隔离。'],
    ['icon' => 'menu-download', 'name' => '多语言 SDK',   'desc' => 'PHP / Java / Python / Go / Node.js / C++ 六语言 SDK，离线验签开箱即用。'],
];

$steps = [
    ['no' => '01', 'name' => '接入 SDK',   'desc' => '下载对应语言 SDK，配置 AppKey / AppSecret 完成初始化。'],
    ['no' => '02', 'name' => '绑定授权',   'desc' => '在用户中心购买套餐并绑定域名或设备，授权实时生效。'],
    ['no' => '03', 'name' => '自动化验证', 'desc' => '程序启动时自动完成授权校验与心跳同步，无需人工干预。'],
];

$heroStats = [
    ['授权验证', '秒级响应'],
    ['数据加密', 'AES-256-GCM'],
    ['接口防护', '签名 + 防重放'],
    ['服务可用', '7 × 24 小时'],
];
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
<body class="nv-body">

<div class="nv-bg" aria-hidden="true">
    <span class="nv-star nv-s1"></span><span class="nv-star nv-s2"></span><span class="nv-star nv-s3"></span>
    <span class="nv-glow nv-g1"></span><span class="nv-glow nv-g2"></span>
    <div class="nv-grid"></div>
</div>

<header class="nv-topbar">
    <div class="nv-inner nv-topbar-inner">
        <a class="nv-brand" href="./">
            <img src="<?php echo e(site_logo_url('')); ?>" alt="">
            <span><?php echo e($siteName); ?></span>
        </a>
        <nav class="nv-nav">
            <a href="./" class="on">首页</a>
            <a href="#features">核心能力</a>
            <a href="#steps">接入流程</a>
            <a href="#query">授权查询</a>
            <a href="agent/">代理商中心</a>
        </nav>
        <div class="nv-topbar-ops">
            <a class="nv-op-plain" href="user/">登录</a>
            <a class="nv-op-primary" href="user/register.php">免费注册</a>
        </div>
    </div>
</header>

<main class="nv-main">

    <section class="nv-hero">
        <div class="nv-inner nv-hero-inner">
            <div class="nv-hero-text">
                <span class="nv-hero-chip"><i></i>NIGHTFALL AUTH ENGINE · 授权引擎在线</span>
                <h1>暗夜之中<br><em>授权尽在掌控</em></h1>
                <p class="nv-hero-sub">面向软件交付场景的新一代授权验证系统——域名绑定、设备指纹、加密签名，为每一份软件保驾护航。</p>
                <div class="nv-hero-ops">
                    <a class="nv-btn nv-btn-primary" href="user/">进入控制中心</a>
                    <a class="nv-btn nv-btn-ghost" href="#query">查询授权</a>
                </div>
                <div class="nv-hero-stats">
                    <?php foreach ($heroStats as $st): ?>
                    <div class="nv-stat"><b><?php echo e($st[1]); ?></b><span><?php echo e($st[0]); ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="nv-hero-card" aria-hidden="true">
                <div class="nv-ch-head">
                    <span class="nv-ch-dot"></span><span class="nv-ch-dot"></span><span class="nv-ch-dot"></span>
                    <span class="nv-ch-title">auth-zero · terminal</span>
                </div>
                <div class="nv-ch-body">
                    <p><span class="nv-cmd">$</span> zeroauth verify --domain=yourapp.com</p>
                    <p class="nv-ok"><span class="nv-tick">✓</span> LICENSE VALID · 永久授权</p>
                    <p class="nv-dim">signature: HMAC-SHA256 ... verified</p>
                    <p class="nv-dim">latency: 38ms · region: cn-east</p>
                    <p><span class="nv-cmd">$</span> <span class="nv-cursor">_</span></p>
                </div>
            </div>
        </div>
    </section>

    <section class="nv-query" id="query">
        <div class="nv-inner">
            <div class="nv-sec-head">
                <span class="nv-sec-tag">LICENSE LOOKUP</span>
                <h2>授权状态查询</h2>
                <p>输入域名即时查询授权概况，结果实时返回。</p>
            </div>
            <div class="nv-query-panel">
                <form class="nv-query-form" method="get" action="./#query">
                    <input class="nv-query-input" type="text" name="q" value="<?php echo e($query_domain); ?>" placeholder="请输入域名，如 example.com" maxlength="255">
                    <button class="nv-query-btn" type="submit">立即查询</button>
                </form>
                <?php if ($result !== null): ?>
                <div class="nv-result nv-result-<?php echo e($result['level']); ?>">
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

    <section class="nv-features" id="features">
        <div class="nv-inner">
            <div class="nv-sec-head">
                <span class="nv-sec-tag">CORE FEATURES</span>
                <h2>为软件授权而生的核心能力</h2>
                <p>从验证到风控的完整链路，全部内置于一套系统。</p>
            </div>
            <div class="nv-feature-grid">
                <?php foreach ($features as $f): ?>
                <div class="nv-feature-card">
                    <div class="nv-feature-icon"><img src="assets/svg/<?php echo e($f['icon']); ?>.svg" alt=""></div>
                    <h3><?php echo e($f['name']); ?></h3>
                    <p><?php echo e($f['desc']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="nv-steps" id="steps">
        <div class="nv-inner">
            <div class="nv-sec-head">
                <span class="nv-sec-tag">QUICK START</span>
                <h2>三步完成接入</h2>
                <p>清晰简洁的接入路径，最快十分钟跑通授权验证。</p>
            </div>
            <div class="nv-step-grid">
                <?php foreach ($steps as $s): ?>
                <div class="nv-step-card">
                    <span class="nv-step-no"><?php echo e($s['no']); ?></span>
                    <h3><?php echo e($s['name']); ?></h3>
                    <p><?php echo e($s['desc']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="nv-cta">
        <div class="nv-inner nv-cta-inner">
            <div>
                <h2>即刻开启你的授权防线</h2>
                <p>注册用户中心，一站式管理域名、设备与授权状态。</p>
            </div>
            <div class="nv-cta-ops">
                <a class="nv-btn nv-btn-light" href="user/">立即登录</a>
                <a class="nv-btn nv-btn-ghost" href="user/register.php">免费注册</a>
            </div>
        </div>
    </section>

</main>

<footer class="nv-footer">
    <div class="nv-inner nv-footer-grid">
        <div class="nv-foot-brand-col">
            <div class="nv-foot-brand">
                <img src="<?php echo e(site_logo_url('')); ?>" alt="">
                <span><?php echo e($siteName); ?></span>
            </div>
            <p class="nv-foot-desc">新一代软件授权与域名授权管理系统，暗夜守护每一份软件交付。</p>
        </div>
        <div class="nv-foot-col">
            <h4>快速入口</h4>
            <a href="user/">用户中心</a>
            <a href="agent/">代理商中心</a>
            <a href="#query">授权查询</a>
            <a href="#features">核心能力</a>
        </div>
        <div class="nv-foot-col">
            <h4>联系我们</h4>
            <?php $contacts = site_contacts_html(); ?>
            <?php echo $contacts !== '' ? $contacts : '<span class="nv-foot-line">详见后台站点信息配置</span>'; ?>
        </div>
        <div class="nv-foot-col">
            <h4>版本信息</h4>
            <span class="nv-foot-line"><?php echo e($siteName); ?> V<?php echo e(PRODUCT_VERSION); ?></span>
            <span class="nv-foot-line">内核 V<?php echo e(SYS_VERSION); ?> · <?php echo date('Y'); ?></span>
            <a class="nv-foot-line nv-foot-github" href="<?php echo e(PRODUCT_GITHUB); ?>" target="_blank" rel="noopener noreferrer">GitHub 开源</a>
        </div>
    </div>
    <div class="nv-foot-bar">
        <div class="nv-inner nv-foot-bar-inner">
            <span>© <?php echo date('Y'); ?> <?php echo e($siteName); ?> · 原作者 <?php echo e(PRODUCT_AUTHOR_ORIGINAL); ?> · 二开 <?php echo e(PRODUCT_AUTHOR_DEV); ?></span>
            <?php echo site_icp_html(); ?>
        </div>
    </div>
</footer>

</body>
</html>
