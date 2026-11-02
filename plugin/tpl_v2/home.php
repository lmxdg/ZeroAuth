<?php
// 首页模板 V2
declare(strict_types=1);

$siteName = cfg('site_name', 'ZeroAuth');

$products = [
    ['icon' => 'menu-key',     'name' => '多类型授权',   'desc' => '支持限时、计次、永久三种授权类型，覆盖单域名、泛域名、IP 与纯密钥等不同部署场景。', 'tag' => '灵活适配'],
    ['icon' => 'menu-home',    'name' => '域名授权绑定', 'desc' => '域名归一化精确绑定，程序与模板按域名鉴权，支持 HTTPS 安全校验。', 'tag' => '精准管控'],
    ['icon' => 'menu-code',    'name' => '设备指纹校验', 'desc' => '设备码绑定与数量上限控制，客户端自助解绑，后台可强制解绑。', 'tag' => '防止多开'],
    ['icon' => 'menu-download', 'name' => '多语言 SDK',  'desc' => 'PHP / Java / Python / Go / Node.js / C++ 官方 SDK，接口 HMAC 签名安全接入。', 'tag' => '开箱即用'],
    ['icon' => 'menu-agent',   'name' => '代理分销体系', 'desc' => '代理商独立折扣与邀请码绑定，名下用户数据隔离，分级管理清晰。', 'tag' => '数据隔离'],
    ['icon' => 'menu-money',   'name' => '余额财务中心', 'desc' => '余额充值、财务流水、充值记录全链路留痕，每一笔账目清晰可查。', 'tag' => '流水留痕'],
];

$steps = [
    ['no' => '01', 'name' => '创建用户账号', 'desc' => '使用邮箱免费注册用户账号，填写代理商邀请码可自动建立绑定关系。'],
    ['no' => '02', 'name' => '选择授权方案', 'desc' => '在服务中心选择应用与套餐，自助完成授权购买与开通，实时到账。'],
    ['no' => '03', 'name' => '统一管理授权', 'desc' => '在用户中心集中查看授权状态、有效时间，支持续期与工单支持。'],
];

$heroRows = [
    ['授权类型', '单域名 / 泛域名 / IP'],
    ['开通方式', '在线购买 · 实时生效'],
    ['授权验证', '秒级响应 · 安全可靠'],
    ['状态同步', '自动完成 · 实时可靠'],
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
<body class="tc-body">

<header class="tc-topbar">
    <div class="tc-inner tc-topbar-inner">
        <a class="tc-brand" href="./">
            <img src="<?php echo e(site_logo_url('')); ?>" alt="">
            <span><?php echo e($siteName); ?></span>
        </a>
        <nav class="tc-nav">
            <a href="./" class="on">首页</a>
            <a href="#products">平台能力</a>
            <a href="#steps">服务流程</a>
            <a href="#query">授权查询</a>
            <a href="agent/">代理商中心</a>
        </nav>
        <div class="tc-topbar-ops">
            <a class="tc-op-plain" href="user/">登录</a>
            <a class="tc-op-primary" href="user/register.php">免费注册</a>
        </div>
    </div>
</header>

<main class="tc-main">

    <section class="tc-hero">
        <div class="tc-hero-blob tc-blob-1"></div>
        <div class="tc-hero-blob tc-blob-2"></div>
        <div class="tc-hero-blob tc-blob-3"></div>
        <div class="tc-hero-blob tc-blob-4"></div>
        <div class="tc-inner tc-hero-inner">
            <div class="tc-hero-text">
                <span class="tc-hero-chip">专业的软件授权与服务平台</span>
                <h1>让每一份软件授权<br><span class="tc-hl">清晰、安全、可管理</span></h1>
                <p class="tc-hero-sub">从授权开通、状态查询到在线续费，为软件服务提供完整、可靠的授权管理体验。</p>
                <ul class="tc-hero-points">
                    <li><img src="assets/svg/check-circle.svg" alt="">授权状态实时同步</li>
                    <li><img src="assets/svg/check-circle.svg" alt="">账户信息安全保护</li>
                    <li><img src="assets/svg/check-circle.svg" alt="">7×24 小时在线服务</li>
                </ul>
                <div class="tc-hero-ops">
                    <a class="tc-btn tc-btn-primary" href="user/">进入服务中心</a>
                    <a class="tc-btn tc-btn-ghost" href="#query">快速查询</a>
                </div>
            </div>

            <div class="tc-hero-card" aria-hidden="true">
                <div class="tc-ch-head">
                    <span class="tc-ch-title">授权服务中心</span>
                    <span class="tc-ch-live"><i></i>服务正常</span>
                </div>
                <div class="tc-ch-status">
                    <div>
                        <span class="tc-ch-label">授权状态</span>
                        <strong class="tc-ch-ok">授权有效</strong>
                    </div>
                    <span class="tc-ch-badge">运行中</span>
                </div>
                <div class="tc-ch-rows">
                    <?php foreach ($heroRows as $row): ?>
                    <div class="tc-ch-row"><span><?php echo e($row[0]); ?></span><b><?php echo e($row[1]); ?></b></div>
                    <?php endforeach; ?>
                </div>
                <div class="tc-ch-foot">
                    <span>最近一次状态同步成功 · 刚刚</span>
                    <div class="tc-ch-bar"><i></i></div>
                </div>
                <div class="tc-ch-tags">
                    <div class="tc-ch-tag"><b>秒级验证</b><span>稳定响应</span></div>
                    <div class="tc-ch-tag"><b>数据可追溯</b><span>状态清晰</span></div>
                </div>
            </div>
        </div>
    </section>

    <section class="tc-query" id="query">
        <div class="tc-inner">
            <div class="tc-sec-head">
                <span class="tc-sec-eyebrow">QUICK LOOKUP</span>
                <h2>快速查询服务</h2>
                <p>查询域名的授权状态与有效期，结果实时返回。</p>
            </div>
            <div class="tc-query-panel">
                <form class="tc-query-form" method="get" action="./#query">
                    <input class="tc-query-input" type="text" name="q" value="<?php echo e($query_domain); ?>" placeholder="请输入域名，如 example.com" maxlength="255">
                    <button class="tc-query-btn" type="submit">立即查询</button>
                </form>
                <p class="tc-query-hint">查询结果仅展示授权概况，不展示授权密钥、绑定目标或账户隐私信息。</p>
                <?php if ($result !== null): ?>
                <div class="tc-query-result">
                    <div class="tc-result tc-result-<?php echo e($result['level']); ?>">
                        <img src="assets/svg/<?php echo $result['level'] === 'ok' ? 'check-circle' : ($result['level'] === 'warn' ? 'warn-circle' : 'close-circle'); ?>.svg" alt="">
                        <div>
                            <div class="tc-r-main"><?php echo e($result['main']); ?></div>
                            <div class="tc-r-sub"><?php echo e($result['sub']); ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="tc-products" id="products">
        <div class="tc-inner">
            <div class="tc-sec-head">
                <span class="tc-sec-eyebrow">PLATFORM CAPABILITIES</span>
                <h2>围绕软件授权打造的一站式服务</h2>
                <p>覆盖授权生命周期中的关键环节，让授权购买、使用和管理更简单。</p>
            </div>
            <div class="tc-product-grid">
                <?php foreach ($products as $p): ?>
                <div class="tc-product-card">
                    <div class="tc-product-icon"><img src="assets/svg/<?php echo e($p['icon']); ?>.svg" alt=""></div>
                    <h3><?php echo e($p['name']); ?></h3>
                    <p><?php echo e($p['desc']); ?></p>
                    <span class="tc-product-tag"><?php echo e($p['tag']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="tc-steps" id="steps">
        <div class="tc-inner">
            <div class="tc-sec-head">
                <span class="tc-sec-eyebrow">HOW IT WORKS</span>
                <h2>三步开启授权服务</h2>
                <p>清晰直接的使用流程，无需复杂配置即可完成授权管理。</p>
            </div>
            <div class="tc-step-grid">
                <?php foreach ($steps as $s): ?>
                <div class="tc-step-card">
                    <span class="tc-step-no"><?php echo e($s['no']); ?></span>
                    <h3><?php echo e($s['name']); ?></h3>
                    <p><?php echo e($s['desc']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="tc-cta">
        <div class="tc-inner tc-cta-inner">
            <div>
                <h2>开始管理您的软件授权</h2>
                <p>登录用户中心，集中查看授权状态、有效时间和账户信息。</p>
            </div>
            <div class="tc-cta-ops">
                <a class="tc-btn tc-btn-white" href="user/">立即登录</a>
                <a class="tc-btn tc-btn-ghost-w" href="user/register.php">免费注册</a>
            </div>
        </div>
    </section>

</main>

<footer class="tc-footer">
    <div class="tc-inner tc-footer-grid">
        <div class="tc-foot-col tc-foot-brand-col">
            <div class="tc-foot-brand">
                <img src="<?php echo e(site_logo_url('')); ?>" alt="">
                <span><?php echo e($siteName); ?></span>
            </div>
            <p class="tc-foot-desc">专业的软件授权与服务平台，让每一次软件交付都安心可控。</p>
        </div>
        <div class="tc-foot-col">
            <h4>快速入口</h4>
            <a href="user/">用户中心</a>
            <a href="agent/">代理商中心</a>
            <a href="#query">授权查询</a>
            <a href="#products">平台能力</a>
        </div>
        <div class="tc-foot-col">
            <h4>联系我们</h4>
            <?php $contacts = site_contacts_html(); ?>
            <?php echo $contacts !== '' ? $contacts : '<span class="tc-foot-line">详见后台站点信息配置</span>'; ?>
        </div>
        <div class="tc-foot-col">
            <h4>版本信息</h4>
            <span class="tc-foot-line"><?php echo e($siteName); ?></span>
            <span class="tc-foot-line">V<?php echo e(PRODUCT_VERSION); ?> · <?php echo date('Y'); ?></span>
        </div>
    </div>
    <div class="tc-foot-bar">
        <div class="tc-inner tc-foot-bar-inner">
            <span>© <?php echo date('Y'); ?> <?php echo e($siteName); ?></span>
            <?php echo site_icp_html(); ?>
        </div>
    </div>
</footer>

</body>
</html>
