<?php
// 首页模板 V1
declare(strict_types=1);

$siteName = cfg('site_name', '软件授权管理系统');
$contacts = site_contacts_html();
$icp = site_icp_html();

$cval = static function (string $key, string $default = '') use ($tplCode): string {
    $v = trim(plugin_cfg_get($tplCode, $key, ''));
    return $v !== '' ? $v : $default;
};

$announceOn   = plugin_cfg_get($tplCode, 'announce_on', '0') === '1';
$announceText = $cval('announce_text');
$announceLink = $cval('announce_link');

$banners = [];
foreach (explode("\n", (string)plugin_cfg_get($tplCode, 'banners', '')) as $line) {
    $parts = array_map('trim', explode('|', trim($line)));
    $img = (string)($parts[0] ?? '');
    if ($img === '') {
        continue;
    }
    $banners[] = ['img' => $img, 'link' => (string)($parts[1] ?? ''), 'title' => (string)($parts[2] ?? '')];
}

$autoplay  = (string)plugin_cfg_get($tplCode, 'banner_autoplay', '5000');
$heroTitle = $cval('hero_title', '软件授权 · 域名授权 · 设备校验');
$heroSub   = $cval('hero_sub', '一站式授权管理方案：卡密授权、域名绑定、设备指纹校验，支持限时 / 计次 / 永久三种授权类型，HMAC-SHA256 接口签名，多应用隔离，代理商分级分销。');

$featureLines = [];
foreach (explode("\n", (string)plugin_cfg_get($tplCode, 'features', '')) as $line) {
    $parts = array_map('trim', explode('|', trim($line)));
    $title = (string)($parts[0] ?? '');
    if ($title === '') {
        continue;
    }
    $featureLines[] = ['title' => $title, 'desc' => (string)($parts[1] ?? ''), 'icon' => (string)($parts[2] ?? '')];
}
if ($featureLines === []) {
    $featureLines = [
        ['title' => '卡密授权', 'desc' => '限时、计次、永久三种类型，支持批量生成、一键封禁与到期提醒。', 'icon' => 'assets/svg/menu-key.svg'],
        ['title' => '域名授权', 'desc' => '域名归一化精确绑定，程序与模板均可按域名鉴权，支持 HTTPS 校验。', 'icon' => 'assets/svg/menu-home.svg'],
        ['title' => '设备指纹', 'desc' => '设备码绑定与上限控制，客户端自助解绑，后台可强制解绑。', 'icon' => 'assets/svg/menu-code.svg'],
        ['title' => '多级分销', 'desc' => '管理员、代理商、用户三级隔离，代理商独立折扣与邀请码绑定。', 'icon' => 'assets/svg/menu-agent.svg'],
    ];
}

$showQuery  = plugin_cfg_get($tplCode, 'show_query', '1') === '1';
$footerText = $cval('footer_text');
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
<body class="v1-body">

<?php if ($announceOn && $announceText !== ''): ?>
<div class="v1-announce" id="v1Announce">
    <div class="v1-inner v1-announce-inner">
        <span class="v1-announce-label">公告</span>
        <?php if ($announceLink !== ''): ?><a href="<?php echo e($announceLink); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($announceText); ?></a>
        <?php else: ?><span class="v1-announce-text"><?php echo e($announceText); ?></span><?php endif; ?>
        <span class="v1-announce-ops">
            <i class="v1-announce-btn" id="announceTempClose" role="button" tabindex="0">暂时关闭</i>
            <i class="v1-announce-btn" id="announcePermClose" role="button" tabindex="0">永久关闭</i>
        </span>
    </div>
</div>
<?php endif; ?>

<header class="v1-topbar">
    <div class="v1-inner v1-topbar-inner">
        <a class="v1-brand" href="./">
            <img src="<?php echo e(site_logo_url('')); ?>" alt="">
            <span><?php echo e($siteName); ?></span>
        </a>
        <nav class="v1-topnav">
            <a href="#features">功能特性</a>
            <a href="#query">授权查询</a>
            <a href="agent/">代理商中心</a>
        </nav>
        <div class="v1-topbar-ops">
            <a class="v1-op-plain" href="user/">登录</a>
            <a class="v1-op-btn" href="user/register.php">免费注册</a>
        </div>
    </div>
</header>

<main class="v1-main">

<?php if (count($banners) > 0): ?>
    <section class="v1-carousel" id="v1Carousel" data-autoplay="<?php echo e($autoplay); ?>">
        <div class="v1-car-viewport" id="v1CarViewport">
            <div class="v1-car-track" id="v1CarTrack">
                <?php foreach ($banners as $i => $b): ?>
                <div class="v1-car-slide" data-index="<?php echo $i; ?>">
                    <?php if ($b['link'] !== ''): ?><a class="v1-car-link" href="<?php echo e($b['link']); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo e($b['img']); ?>" alt="<?php echo e($b['title']); ?>" draggable="false"></a>
                    <?php else: ?><img src="<?php echo e($b['img']); ?>" alt="<?php echo e($b['title']); ?>" draggable="false"><?php endif; ?>
                    <?php if ($b['title'] !== ''): ?><span class="v1-car-title"><?php echo e($b['title']); ?></span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="v1-car-veil" aria-hidden="true"></div>
        <div class="v1-car-overlay">
            <span class="v1-hero-chip">一站式授权管理方案</span>
            <h1><?php echo e($heroTitle); ?></h1>
            <p><?php echo e($heroSub); ?></p>
        </div>
        <button class="v1-car-arrow v1-car-prev" id="v1CarPrev" type="button" aria-label="上一张">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="14.5 5 8 12 14.5 19"></polyline></svg>
        </button>
        <button class="v1-car-arrow v1-car-next" id="v1CarNext" type="button" aria-label="下一张">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9.5 5 16 12 9.5 19"></polyline></svg>
        </button>
        <span class="v1-car-counter" id="v1CarCounter">01 / <?php echo str_pad((string)count($banners), 2, '0', STR_PAD_LEFT); ?></span>
        <div class="v1-car-dots" id="v1CarDots">
            <?php foreach ($banners as $i => $b): ?>
            <span class="v1-car-dot<?php echo $i === 0 ? ' on' : ''; ?>" data-index="<?php echo $i; ?>"></span>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (count($banners) > 0 && $showQuery): ?>
    <section class="v1-query-float" id="query">
        <div class="v1-inner">
            <div class="v1-query-card">
                <div class="v1-query-title">
                    <img src="assets/svg/menu-home.svg" alt="">
                    <span>域名授权查询</span>
                </div>
                <p class="v1-query-desc">输入域名查询授权状态，仅展示授权有效性，不涉及卡密等隐私信息。</p>
                <form class="v1-query-form" method="get" action="#query">
                    <input class="v1-query-input" type="text" name="q" value="<?php echo e($query_domain); ?>" placeholder="请输入域名，如 example.com" maxlength="255">
                    <button class="v1-query-btn" type="submit">查 询</button>
                </form>
                <?php if ($result !== null): ?>
                <div class="v1-query-result">
                    <div class="v1-result v1-result-<?php echo e($result['level']); ?>">
                        <img src="assets/svg/<?php echo $result['level'] === 'ok' ? 'check-circle' : ($result['level'] === 'warn' ? 'warn-circle' : 'close-circle'); ?>.svg" alt="">
                        <div>
                            <div class="v1-r-main"><?php echo e($result['main']); ?></div>
                            <div class="v1-r-sub"><?php echo e($result['sub']); ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

    <div class="v1-inner">

<?php if (count($banners) === 0): ?>
        <section class="v1-hero" id="query">
            <div class="v1-hero-text">
                <span class="v1-hero-chip">一站式授权管理方案</span>
                <h1><?php echo e($heroTitle); ?></h1>
                <p><?php echo e($heroSub); ?></p>
                <div class="v1-hero-entry">
                    <a class="v1-btn v1-btn-primary" href="user/">进入用户中心</a>
                    <a class="v1-btn v1-btn-plain" href="agent/">代理商合作</a>
                </div>
            </div>

            <?php if ($showQuery): ?>
            <div class="v1-query-card">
                <div class="v1-query-title">
                    <img src="assets/svg/menu-home.svg" alt="">
                    <span>域名授权查询</span>
                </div>
                <p class="v1-query-desc">输入域名查询授权状态，仅展示授权有效性，不涉及卡密等隐私信息。</p>
                <form class="v1-query-form" method="get" action="#query">
                    <input class="v1-query-input" type="text" name="q" value="<?php echo e($query_domain); ?>" placeholder="请输入域名，如 example.com" maxlength="255">
                    <button class="v1-query-btn" type="submit">查 询</button>
                </form>
                <?php if ($result !== null): ?>
                <div class="v1-query-result">
                    <div class="v1-result v1-result-<?php echo e($result['level']); ?>">
                        <img src="assets/svg/<?php echo $result['level'] === 'ok' ? 'check-circle' : ($result['level'] === 'warn' ? 'warn-circle' : 'close-circle'); ?>.svg" alt="">
                        <div>
                            <div class="v1-r-main"><?php echo e($result['main']); ?></div>
                            <div class="v1-r-sub"><?php echo e($result['sub']); ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>
<?php endif; ?>

        <section class="v1-features" id="features">
            <div class="v1-sec-head">
                <span class="v1-sec-eyebrow">PLATFORM</span>
                <h2>平台能力</h2>
                <p>覆盖软件授权生命周期的关键环节，购买、使用、管理更简单。</p>
            </div>
            <div class="v1-feature-grid">
                <?php foreach ($featureLines as $f): ?>
                <div class="v1-feature-card">
                    <?php if ($f['icon'] !== ''): ?><span class="v1-feature-icon"><img src="<?php echo e($f['icon']); ?>" alt=""></span><?php endif; ?>
                    <h3><?php echo e($f['title']); ?></h3>
                    <p><?php echo e($f['desc']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="v1-cta">
            <div class="v1-cta-text">
                <h2>开始使用<?php echo e($siteName); ?></h2>
                <p>注册账号即可开通授权，随时查询状态与在线续期。</p>
            </div>
            <div class="v1-cta-ops">
                <a class="v1-btn v1-btn-white" href="user/">立即登录</a>
                <a class="v1-btn v1-btn-ghost-w" href="user/register.php">免费注册</a>
            </div>
        </section>

    </div>
</main>

<footer class="v1-footer">
    <div class="v1-inner v1-footer-grid">
        <div class="v1-foot-col v1-foot-brand-col">
            <div class="v1-foot-brand">
                <img src="<?php echo e(site_logo_url('')); ?>" alt="">
                <span><?php echo e($siteName); ?></span>
            </div>
            <p class="v1-foot-desc">专业的软件授权与服务平台，让每一次软件交付都安心可控。</p>
        </div>
        <div class="v1-foot-col">
            <h4>快速入口</h4>
            <a href="user/">用户中心</a>
            <a href="agent/">代理商中心</a>
            <a href="#query">授权查询</a>
            <a href="#features">功能特性</a>
        </div>
        <div class="v1-foot-col">
            <h4>联系我们</h4>
            <?php echo $contacts !== '' ? $contacts : '<span class="v1-foot-line">详见后台站点信息配置</span>'; ?>
        </div>
        <div class="v1-foot-col">
            <h4>版本信息</h4>
            <span class="v1-foot-line"><?php echo e($siteName); ?></span>
            <span class="v1-foot-line">V<?php echo e(PRODUCT_VERSION); ?> · <?php echo date('Y'); ?></span>
        </div>
    </div>
    <div class="v1-foot-bar">
        <div class="v1-inner v1-foot-bar-inner">
            <span>© <?php echo date('Y'); ?> <?php echo e($siteName); ?><?php echo $footerText !== '' ? ' · ' . e($footerText) : ''; ?></span>
            <?php echo $icp; ?>
        </div>
    </div>
</footer>

<script src="plugin/<?php echo e($tplDir); ?>/home.js"></script>
</body>
</html>
