<?php
// 首页模板 V5（极简商务）
declare(strict_types=1);

$siteName = cfg('site_name', 'ZeroAuth');

$services = [
    ['icon' => 'menu-key',      'name' => '授权体系',   'desc' => '限时 / 计次 / 永久授权与卡密分发，支持单域名、泛域名、IP 及纯密钥多种验证形态，满足不同商业交付模式。'],
    ['icon' => 'menu-home',     'name' => '域名授权',   'desc' => '域名归一化绑定与实时状态校验，程序与模板双维度鉴权，支持 HTTPS 安全策略与异常访问拦截。'],
    ['icon' => 'menu-code',     'name' => '设备管理',   'desc' => '设备指纹绑定与数量上限控制，客户自助解绑，管理端强制下线，全过程审计留痕。'],
    ['icon' => 'menu-agent',    'name' => '代理体系',   'desc' => '代理商独立折扣、邀请码绑定与分级数据隔离，支持余额分成与提现管理，分销体系清晰可控。'],
    ['icon' => 'menu-money',    'name' => '财务中心',   'desc' => '在线支付、余额充值、订单与财务流水全链路管理，每一笔资金变动均可追溯。'],
    ['icon' => 'menu-download', 'name' => 'SDK 支持',   'desc' => 'PHP / Java / Python / Go / Node.js / C++ 多语言官方 SDK，HMAC 签名接入，附完整对接文档。'],
];

$metrics = [
    ['value' => '3 类', 'label' => '授权模式'],
    ['value' => '6 种', 'label' => '官方 SDK'],
    ['value' => '秒级', 'label' => '验证响应'],
    ['value' => '7×24', 'label' => '服务支持'],
];

$flow = [
    ['title' => '签订服务方案', 'desc' => '根据业务场景选择合适的授权模式与套餐，支持试用与按量计费。'],
    ['title' => '部署与绑定',   'desc' => '通过用户中心完成域名 / 设备绑定，SDK 接入即插即用。'],
    ['title' => '持续运营',     'desc' => '授权状态实时监控，到期自动提醒，续费与升级自助完成。'],
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
<body class="bz-body">

<header class="bz-topbar">
    <div class="bz-inner bz-topbar-inner">
        <a class="bz-brand" href="./">
            <img src="<?php echo e(site_logo_url('')); ?>" alt="">
            <span><?php echo e($siteName); ?></span>
        </a>
        <nav class="bz-nav">
            <a href="./" class="on">首页</a>
            <a href="#services">服务体系</a>
            <a href="#flow">合作流程</a>
            <a href="#query">授权查询</a>
            <a href="agent/">代理商中心</a>
        </nav>
        <div class="bz-topbar-ops">
            <a class="bz-op-plain" href="user/">登录</a>
            <a class="bz-op-primary" href="user/register.php">注册账号</a>
        </div>
    </div>
</header>

<main class="bz-main">

    <section class="bz-hero">
        <div class="bz-inner">
            <div class="bz-hero-kicker">SOFTWARE LICENSING PLATFORM</div>
            <h1>软件授权与域名授权<br>一体化管理平台</h1>
            <p class="bz-hero-sub">为软件企业提供授权分发、域名绑定、设备管控与代理分销的完整解决方案，安全合规，稳定可靠。</p>
            <div class="bz-hero-ops">
                <a class="bz-btn bz-btn-primary" href="user/">进入用户中心</a>
                <a class="bz-btn bz-btn-plain" href="#query">查询授权状态</a>
            </div>
            <div class="bz-metrics">
                <?php foreach ($metrics as $m): ?>
                <div class="bz-metric">
                    <b><?php echo e($m['value']); ?></b>
                    <span><?php echo e($m['label']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="bz-query" id="query">
        <div class="bz-inner">
            <div class="bz-query-box">
                <div class="bz-query-info">
                    <h2>授权状态查询</h2>
                    <p>输入域名即时核验授权概况。结果仅展示授权状态概要，不涉及任何隐私信息。</p>
                </div>
                <form class="bz-query-form" method="get" action="./#query">
                    <input class="bz-query-input" type="text" name="q" value="<?php echo e($query_domain); ?>" placeholder="请输入域名，如 example.com" maxlength="255">
                    <button class="bz-query-btn" type="submit">查询</button>
                </form>
                <?php if ($result !== null): ?>
                <div class="bz-result bz-result-<?php echo e($result['level']); ?>">
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

    <section class="bz-services" id="services">
        <div class="bz-inner">
            <div class="bz-sec-head">
                <span class="bz-sec-tag">服务体系</span>
                <h2>覆盖授权全生命周期的能力矩阵</h2>
                <p>从授权生成、分发、验证到续期与风控，提供企业级的完整支撑。</p>
            </div>
            <div class="bz-service-grid">
                <?php foreach ($services as $s): ?>
                <div class="bz-service-card">
                    <div class="bz-service-icon"><img src="assets/svg/<?php echo e($s['icon']); ?>.svg" alt=""></div>
                    <h3><?php echo e($s['name']); ?></h3>
                    <p><?php echo e($s['desc']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="bz-flow" id="flow">
        <div class="bz-inner">
            <div class="bz-sec-head">
                <span class="bz-sec-tag">合作流程</span>
                <h2>清晰的三步合作路径</h2>
                <p>标准化交付流程，降低接入与运营成本。</p>
            </div>
            <div class="bz-flow-grid">
                <?php foreach ($flow as $i => $f): ?>
                <div class="bz-flow-card">
                    <span class="bz-flow-no">0<?php echo $i + 1; ?></span>
                    <h3><?php echo e($f['title']); ?></h3>
                    <p><?php echo e($f['desc']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="bz-cta">
        <div class="bz-inner bz-cta-inner">
            <div>
                <h2>开始规范化的授权管理</h2>
                <p>立即注册账号，或联系我们的服务团队获取方案建议。</p>
            </div>
            <div class="bz-cta-ops">
                <a class="bz-btn bz-btn-invert" href="user/register.php">免费注册</a>
                <a class="bz-btn bz-btn-ghost" href="user/">登录账号</a>
            </div>
        </div>
    </section>

</main>

<footer class="bz-footer">
    <div class="bz-inner bz-footer-grid">
        <div class="bz-foot-brand-col">
            <div class="bz-foot-brand">
                <img src="<?php echo e(site_logo_url('')); ?>" alt="">
                <span><?php echo e($siteName); ?></span>
            </div>
            <p class="bz-foot-desc">专业的软件授权与域名授权管理系统，为软件交付保驾护航。</p>
        </div>
        <div class="bz-foot-col">
            <h4>产品服务</h4>
            <a href="user/">用户中心</a>
            <a href="agent/">代理商中心</a>
            <a href="#services">服务体系</a>
            <a href="#query">授权查询</a>
        </div>
        <div class="bz-foot-col">
            <h4>联系我们</h4>
            <?php $contacts = site_contacts_html(); ?>
            <?php echo $contacts !== '' ? $contacts : '<span class="bz-foot-line">详见后台站点信息配置</span>'; ?>
        </div>
        <div class="bz-foot-col">
            <h4>关于系统</h4>
            <span class="bz-foot-line"><?php echo e($siteName); ?> V<?php echo e(PRODUCT_VERSION); ?>（内核 V<?php echo e(SYS_VERSION); ?>）</span>
            <a class="bz-foot-line bz-foot-github" href="<?php echo e(PRODUCT_GITHUB); ?>" target="_blank" rel="noopener noreferrer">GitHub 开源地址</a>
            <span class="bz-foot-line">© <?php echo date('Y'); ?> <?php echo e($siteName); ?></span>
        </div>
    </div>
    <div class="bz-foot-bar">
        <div class="bz-inner bz-foot-bar-inner">
            <span>原作者：<?php echo e(PRODUCT_AUTHOR_ORIGINAL); ?> · 二次开发：<?php echo e(PRODUCT_AUTHOR_DEV); ?> · 二开请保留版权</span>
            <?php echo site_icp_html(); ?>
        </div>
    </div>
</footer>

</body>
</html>
