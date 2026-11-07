<?php
// 管理员后台公共布局
declare(strict_types=1);
require_once __DIR__ . '/Ui.php';

/**
 * 后台顶部广告位（合作伙伴推广）
 * 图片随包内置：assets/img/ad/mw.png（横幅底图）/ mw-logo.png（品牌标识）
 */
function admin_ad_banner(): void
{
    if (!defined('PARTNER_URL') || PARTNER_URL === '') {
        return;
    }
    ?>
    <a class="admin-ad" href="<?php echo e(PARTNER_URL); ?>" target="_blank" rel="noopener noreferrer sponsored"
       title="<?php echo e(PARTNER_NAME . ' · ' . PARTNER_SLOGAN); ?>">
        <span class="admin-ad-body">
            <img class="admin-ad-logo" src="../assets/img/ad/mw-logo.png" alt="<?php echo e(PARTNER_NAME); ?>">
            <span class="admin-ad-copy">
                <span class="admin-ad-title"><?php echo e(PARTNER_NAME); ?> · 高性能云服务器</span>
                <span class="admin-ad-desc">多线 BGP · 多区可选 · 稳定又实惠</span>
            </span>
        </span>
        <span class="admin-ad-act">立即选购<span class="admin-ad-arrow">→</span></span>
        <span class="admin-ad-mark">广告</span>
    </a>
    <?php
}

function admin_header(string $title, string $active, array $admin): void
{
    $groups = [
        ['label' => '', 'items' => [
            'dashboard'   => ['控制台', 'dashboard.php', 'menu-home'],
            'plugin_store'=> ['插件商店', 'plugin_store.php', 'menu-download'],
            'theme_store' => ['主题商店', 'theme_store.php', 'menu-app'],
            'report'      => ['数据报表', 'report_data.php', 'menu-activity'],
        ]],
        ['label' => '业务管理', 'icon' => 'group-biz', 'items' => [
            'app'      => ['应用管理', 'app_manage.php', 'menu-app'],
            'package'  => ['套餐管理', 'package_manage.php', 'menu-app'],
            'license'  => ['卡密管理', 'license_manage.php', 'menu-key'],
            'order'    => ['订单管理', 'order_manage.php', 'menu-money'],
            'discount' => ['折扣活动', 'discount_manage.php', 'menu-activity'],
            'level'    => ['等级管理', 'level_manage.php', 'menu-agent'],
            'finance'  => ['财务流水', 'finance_flow.php', 'menu-money'],
        ]],
        ['label' => '用户中心', 'icon' => 'group-user', 'items' => [
            'user'      => ['用户管理', 'user_manage.php', 'menu-user'],
            'agent'     => ['代理商管理', 'agent_manage.php', 'menu-agent'],
            'realname'  => ['实名认证审核', 'realname.php', 'menu-user'],
            'withdraw'  => ['提现管理', 'withdraw.php', 'menu-money'],
            'ticket'    => ['工单管理', 'ticket_manage.php', 'menu-log'],
            'notice'    => ['公告管理', 'notice_manage.php', 'menu-log'],
        ]],
        ['label' => '开发者', 'icon' => 'group-dev', 'items' => [
            'app_ver'   => ['应用版本管理', 'app_version_manage.php', 'menu-app'],
            'sdk'       => ['SDK 模板管理', 'sdk_manage.php', 'menu-code'],
            'docs_api'  => ['开发文档', 'docs_api.php', 'menu-code'],
            'doc_plugin' => ['插件开发文档', 'dev_docs_plugin.php', 'menu-code'],
            'doc_theme'  => ['主题开发文档', 'dev_docs_theme.php', 'menu-code'],
            'sdk_logs'  => ['SDK 下载日志', 'sdk_logs.php', 'menu-log'],
            'cloud_var' => ['云变量管理', 'cloud_var_manage.php', 'menu-var'],
            'ruin'      => ['危险操作', 'ruin.php', 'menu-log'],
        ]],
        ['label' => '授权与安全', 'icon' => 'group-safe', 'items' => [
            'lic_over'    => ['授权概览', 'license_overview.php', 'menu-key'],
            'lic_list'    => ['授权列表', 'license_list.php', 'menu-key'],
            'verify_logs' => ['验证日志', 'verify_logs.php', 'menu-log'],
            'piracy'      => ['盗版追踪', 'piracy_track.php', 'menu-lock'],
            'ip_ban'      => ['IP 封禁', 'ip_ban_manage.php', 'menu-lock'],
            'blacklist'   => ['黑名单管理', 'blacklist_manage.php', 'menu-lock'],
            'alert'       => ['告警中心', 'alert_center.php', 'menu-activity'],
            'audit'       => ['审计日志', 'audit_logs.php', 'menu-log'],
            'security'    => ['加密与验证', 'security_center.php', 'menu-lock'],
        ]],
        ['label' => '系统', 'icon' => 'group-sys', 'items' => [
            'settings' => ['系统配置', 'settings.php', 'menu-setting'],
            'health'   => ['健康检查', 'health.php', 'menu-activity'],
            'backup'   => ['数据备份', 'backup_manage.php', 'menu-download'],
            'profile'  => ['个人信息', 'profile.php', 'menu-user'],
            'mail_log' => ['邮件日志', 'mail_log.php', 'menu-log'],
            'about'    => ['关于系统', 'about.php', 'menu-home'],
        ]],
    ];
    $cfgSiteName = cfg('site_name', 'ZeroAuth');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($title); ?> - 管理员后台</title>
<?php echo site_meta_tags('../'); ?>
<?php echo avatar_fallback_script(); ?>
<script>

(function () { try {
    var p = JSON.parse(localStorage.getItem('adminPrefs') || '{}');
    var d = { themeMode: 'light', menuLayout: 'left', menuTheme: 'light', primary: '#006EFF', boxStyle: 'card', colorWeak: false, multiTab: false, showBreadcrumb: false, showCollapseBtn: true, showQuick: true, showReload: true, showLang: true, tabStyle: 'chip', pageAnim: 'fade', radius: 8, menuWidth: 230 };
    for (var k in d) { if (p[k] === undefined) p[k] = d[k]; }
    var r = document.documentElement;
    if (p.themeMode === 'dark') r.classList.add('theme-dark');
    if (p.menuLayout === 'top') r.classList.add('menu-top');
    if (p.menuTheme === 'dark') r.classList.add('menu-dark');
    if (p.boxStyle === 'shadow') r.classList.add('box-flat');
    if (p.colorWeak) r.classList.add('color-weak');
    if (p.multiTab) r.classList.add('multitab');
    if (p.showBreadcrumb) r.classList.add('show-crumbs');
    if (p.tabStyle === 'line') r.classList.add('tab-line');
    if (p.pageAnim === 'fade') r.classList.add('anim-fade');
    if (p.pageAnim === 'slide') r.classList.add('anim-slide');
    if (!p.showCollapseBtn) r.classList.add('hide-collapsebtn');
    if (!p.showQuick) r.classList.add('hide-quick');
    if (!p.showReload) r.classList.add('hide-reload');
    if (!p.showLang) r.classList.add('hide-lang');
    function rgb(h) { var s = String(h || '').replace('#', ''); if (s.length === 3) s = s.replace(/./g, function (c) { return c + c; }); var n = parseInt(s, 16) || 0; return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; }
    var c = rgb(p.primary), st = r.style;
    st.setProperty('--primary', p.primary);
    st.setProperty('--primary-rgb', c.join(','));
    st.setProperty('--primary-hover', 'rgb(' + c.map(function (v) { return Math.round(v * 0.85); }).join(',') + ')');
    st.setProperty('--sidebar-width', p.menuWidth + 'px');
    st.setProperty('--radius', p.radius + 'px');

    if (p.showProgress) {
        var tries = 0;
        var tStart = setInterval(function () {
            var bar = document.getElementById('topbarProgress');
            if (!bar && ++tries < 250) return;
            clearInterval(tStart);
            if (!bar) return;
            var w = 0, done = false, tStep;
            function tFinish() {
                if (done) return;
                done = true;
                clearInterval(tStep);
                bar.style.width = '100%';
                setTimeout(function () { bar.style.opacity = '0'; }, 350);
            }
            bar.style.opacity = '1';
            tStep = setInterval(function () {
                w += Math.max(0.4, (90 - w) * 0.1) * (0.5 + Math.random());
                if (w >= 90) w = 90;
                bar.style.width = w + '%';
            }, 180);
            window.addEventListener('load', tFinish);
            setTimeout(tFinish, 8000);
        }, 20);
    }
} catch (e) { } })();
</script>
<link rel="stylesheet" href="../assets/css/ui-shared.css">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="admin-body" data-wm-text="<?php echo e($cfgSiteName . ' · ' . $admin['username']); ?>">
<div class="topbar-progress" id="topbarProgress"></div>
<div class="admin-layout">

    <aside class="admin-sidebar" id="adminSidebar">
        <div class="side-brand">
            <img src="<?php echo e(site_logo_url('../')); ?>" alt="" class="side-logo">
            <span class="side-title"><?php echo e($cfgSiteName); ?></span>
        </div>
        <nav class="side-menu">
            <?php foreach ($groups as $gi => $group): ?>
            <?php if ($group['label'] === ''): ?>
            <?php foreach ($group['items'] as $key => [$name, $url, $icon]): ?>
            <a class="side-item <?php echo $active === $key ? 'active' : ''; ?>" href="<?php echo e($url); ?>">
                <?php echo ui_icon($icon, $key); ?>
                <span><?php echo e($name); ?></span>
            </a>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="side-group" data-group="g<?php echo $gi; ?>">
                <div class="side-parent" role="button" tabindex="0">
                    <?php echo ui_icon($group['icon'], $group['icon']); ?>
                    <span><?php echo e($group['label']); ?></span>
                    <?php echo ui_icon('arrow-down', 'top_collapse', 'side-caret'); ?>
                </div>
                <div class="side-sub">
                    <?php foreach ($group['items'] as $key => [$name, $url, $icon]): ?>
                    <a class="side-item <?php echo $active === $key ? 'active' : ''; ?>" href="<?php echo e($url); ?>">
                        <?php echo ui_icon($icon, $key); ?>
                        <span><?php echo e($name); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot"><?php echo e(PRODUCT_NAME); ?> V<?php echo e(PRODUCT_VERSION); ?></div>
    </aside>

    <div class="side-mask" id="sideMask"></div>

    <div class="admin-main">
        <?php admin_ad_banner(); ?>
        <header class="admin-topbar">
            <?php echo ui_icon('menu', 'top_menu', 'topbar-trigger', ['id' => 'menuTrigger']); ?>
            <div class="topbar-title"><?php echo e($title); ?></div>
            <div class="topbar-right">
                <div class="topbar-actions">
                    <button class="topbar-act" id="sideCollapseBtn" type="button" title="折叠/展开侧边栏">
                        <?php echo ui_icon('menu', 'top_collapse', 'topbar-ico'); ?>
                    </button>
                    <div class="topbar-drop" id="quickMenu">
                        <button class="topbar-act" id="quickMenuBtn" type="button" title="快速入口">
                            <?php echo ui_icon('apps', 'top_quick', 'topbar-ico'); ?>
                        </button>
                        <div class="topbar-panel quick-panel">
                            <div class="topbar-panel-title">快速入口</div>
                            <div class="quick-grid">
                                <a class="quick-item" href="dashboard.php"><?php echo ui_icon('menu-home', 'dashboard', 'quick-ico'); ?><span>控制台</span></a>
                                <a class="quick-item" href="plugin_store.php"><?php echo ui_icon('menu-download', 'plugin_store', 'quick-ico'); ?><span>插件商店</span></a>
                                <a class="quick-item" href="theme_store.php"><?php echo ui_icon('menu-app', 'theme_store', 'quick-ico'); ?><span>主题商店</span></a>
                                <a class="quick-item" href="license_manage.php"><?php echo ui_icon('menu-key', 'license', 'quick-ico'); ?><span>卡密管理</span></a>
                                <a class="quick-item" href="order_manage.php"><?php echo ui_icon('menu-money', 'order', 'quick-ico'); ?><span>订单管理</span></a>
                                <a class="quick-item" href="user_manage.php"><?php echo ui_icon('menu-user', 'user', 'quick-ico'); ?><span>用户管理</span></a>
                                <a class="quick-item" href="settings.php"><?php echo ui_icon('menu-setting', 'settings', 'quick-ico'); ?><span>系统配置</span></a>
                            </div>
                        </div>
                    </div>
                    <button class="topbar-act" id="reloadBtn" type="button" title="重载页面">
                        <?php echo ui_icon('reload', 'top_reload', 'topbar-ico'); ?>
                    </button>
                    <button class="topbar-act" id="fullscreenBtn" type="button" title="全屏">
                        <?php echo ui_icon('fullscreen', 'top_fullscreen', 'topbar-ico', [
                            'id' => 'fullscreenIcon',
                            'data-src-off' => ui_icon_src('fullscreen', 'top_fullscreen'),
                            'data-src-on' => ui_icon_src('fullscreen-exit', 'top_fullscreen'),
                        ]); ?>
                    </button>
                    <div class="topbar-drop" id="langMenu">
                        <button class="topbar-act" id="langMenuBtn" type="button" title="多语言">
                            <?php echo ui_icon('i18n', 'top_lang', 'topbar-ico'); ?>
                        </button>
                        <div class="topbar-panel lang-panel">
                            <button type="button" class="lang-item" data-lang="zh-CN">简体中文</button>
                            <button type="button" class="lang-item" data-lang="zh-TW">繁體中文</button>
                            <button type="button" class="lang-item" data-lang="en">English</button>
                        </div>
                    </div>
                    <button class="topbar-act" id="settingsBtn" type="button" title="页面设置">
                        <?php echo ui_icon('setting', 'top_settings', 'topbar-ico'); ?>
                    </button>
                    <button class="topbar-act" id="themeModeBtn" type="button" title="主题风格">
                        <?php echo ui_icon('moon', 'top_theme', 'topbar-ico', [
                            'id' => 'themeModeIcon',
                            'data-src-light' => ui_icon_src('moon', 'top_theme'),
                            'data-src-dark' => ui_icon_src('sun', 'top_theme'),
                        ]); ?>
                    </button>
                </div>
                <div class="user-menu" id="userMenu">
                    <button class="user-menu-trigger" id="userMenuBtn" type="button" title="账号菜单">
                        <?php echo avatar_img($admin, 'topbar-avatar'); ?>
                    </button>
                    <div class="user-menu-panel">
                        <div class="user-menu-head">
                            <?php echo avatar_img($admin, ''); ?>
                            <div>
                                <span class="user-menu-name"><?php echo e($admin['nickname'] !== '' ? $admin['nickname'] : $admin['username']); ?></span>
                                <span class="user-menu-role">账号：<?php echo e($admin['username']); ?></span>
                            </div>
                        </div>
                        <a class="user-menu-item" href="profile.php">
                            <?php echo ui_icon('menu-user', 'user'); ?>
                            个人中心
                        </a>
                        <div class="user-menu-logout-box">
                            <a class="user-menu-logout" href="logout.php">退出登录</a>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <?php
        $crumbGroup = '';
        foreach ($groups as $g) {
            if (isset($g['items'][$active]) && $g['label'] !== '') { $crumbGroup = $g['label']; break; }
        }
        ?>
        <nav class="admin-crumbs" id="adminCrumbs">
            <a href="dashboard.php">首页</a>
            <?php if ($crumbGroup !== ''): ?><span class="crumb-sep">/</span><span><?php echo e($crumbGroup); ?></span><?php endif; ?>
            <span class="crumb-sep">/</span><span class="crumb-cur"><?php echo e($title); ?></span>
        </nav>
        <div class="admin-tabs" id="adminTabs" data-title="<?php echo e($title); ?>" data-url="<?php echo e($_SERVER['REQUEST_URI'] ?? ''); ?>"></div>
        <script>
        (function () {
            var menu = document.getElementById('userMenu');
            var btn = document.getElementById('userMenuBtn');
            if (!menu || !btn) return;
            btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('open'); });
            document.addEventListener('click', function (e) { if (!menu.contains(e.target)) menu.classList.remove('open'); });
        })();
        </script>
        <main class="admin-content">
    <?php
}

function admin_footer(array $extraScripts = []): void
{
    ?>
        </main>
    </div>
</div>
<div class="settings-mask" id="settingsMask"></div>
<aside class="settings-drawer" id="settingsDrawer" aria-label="页面设置">
    <div class="sd-head">
        <span>页面设置</span>
        <button type="button" class="topbar-act" id="settingsClose"><?php echo ui_icon('close', 'top_collapse', 'topbar-ico'); ?></button>
    </div>
    <div class="sd-body">
        <div class="sd-group-title">界面风格</div>
        <div class="sd-row">
            <span>主题风格</span>
            <div class="sd-chips">
                <button type="button" class="sd-chip" data-pref="themeMode" data-val="light">亮色</button>
                <button type="button" class="sd-chip" data-pref="themeMode" data-val="dark">暗色</button>
            </div>
        </div>
        <div class="sd-row">
            <span>菜单布局</span>
            <div class="sd-chips">
                <button type="button" class="sd-chip" data-pref="menuLayout" data-val="left">左侧</button>
                <button type="button" class="sd-chip" data-pref="menuLayout" data-val="top">顶部</button>
            </div>
        </div>
        <div class="sd-row">
            <span>菜单风格</span>
            <div class="sd-chips">
                <button type="button" class="sd-chip" data-pref="menuTheme" data-val="light">亮色</button>
                <button type="button" class="sd-chip" data-pref="menuTheme" data-val="dark">暗色</button>
            </div>
        </div>
        <div class="sd-row">
            <span>盒子样式</span>
            <div class="sd-chips">
                <button type="button" class="sd-chip" data-pref="boxStyle" data-val="card">卡片</button>
                <button type="button" class="sd-chip" data-pref="boxStyle" data-val="shadow">投影</button>
            </div>
        </div>

        <div class="sd-group-title">系统主题色</div>
        <div class="sd-swatches">
            <span class="sd-swatch" data-color="#006EFF" style="background:#006EFF" title="默认蓝"></span>
            <span class="sd-swatch" data-color="#3491FA" style="background:#3491FA" title="知更鸟蓝"></span>
            <span class="sd-swatch" data-color="#00B42A" style="background:#00B42A" title="翡翠绿"></span>
            <span class="sd-swatch" data-color="#14C9C9" style="background:#14C9C9" title="青碧色"></span>
            <span class="sd-swatch" data-color="#722ED1" style="background:#722ED1" title="酱紫"></span>
            <span class="sd-swatch" data-color="#F53F3F" style="background:#F53F3F" title="朱红"></span>
            <span class="sd-swatch" data-color="#FF7D00" style="background:#FF7D00" title="橙色"></span>
            <span class="sd-swatch" data-color="#F7BA1E" style="background:#F7BA1E" title="金色"></span>
        </div>

        <div class="sd-group-title">基础配置</div>
        <label class="sd-row">开启多标签栏<span class="pref-switch"><input type="checkbox" data-pref="multiTab"><i></i></span></label>
        <label class="sd-row">侧边栏开启手风琴模式<span class="pref-switch"><input type="checkbox" data-pref="accordion"><i></i></span></label>
        <label class="sd-row">显示折叠侧边栏按钮<span class="pref-switch"><input type="checkbox" data-pref="showCollapseBtn"><i></i></span></label>
        <label class="sd-row">显示快速入口<span class="pref-switch"><input type="checkbox" data-pref="showQuick"><i></i></span></label>
        <label class="sd-row">显示重载页面按钮<span class="pref-switch"><input type="checkbox" data-pref="showReload"><i></i></span></label>
        <label class="sd-row">显示全局面包屑导航<span class="pref-switch"><input type="checkbox" data-pref="showBreadcrumb"><i></i></span></label>
        <label class="sd-row">显示多语言选择<span class="pref-switch"><input type="checkbox" data-pref="showLang"><i></i></span></label>
        <label class="sd-row">显示顶部进度条<span class="pref-switch"><input type="checkbox" data-pref="showProgress"><i></i></span></label>
        <label class="sd-row">色弱模式<span class="pref-switch"><input type="checkbox" data-pref="colorWeak"><i></i></span></label>
        <label class="sd-row">全局水印<span class="pref-switch"><input type="checkbox" data-pref="watermark"><i></i></span></label>

        <div class="sd-group-title">布局参数</div>
        <div class="sd-row">
            <span>菜单宽度</span>
            <span class="sd-num"><input type="number" data-num="menuWidth" min="180" max="320" step="2"><em>px</em></span>
        </div>
        <div class="sd-row">
            <span>自定义圆角</span>
            <span class="sd-num"><input type="number" data-num="radius" min="0" max="16"><em>px</em></span>
        </div>
        <div class="sd-row">
            <span>标签页风格</span>
            <div class="sd-chips">
                <button type="button" class="sd-chip" data-pref="tabStyle" data-val="chip">卡片</button>
                <button type="button" class="sd-chip" data-pref="tabStyle" data-val="line">简洁</button>
            </div>
        </div>
        <div class="sd-row">
            <span>页面切换动画</span>
            <div class="sd-chips">
                <button type="button" class="sd-chip" data-pref="pageAnim" data-val="none">无</button>
                <button type="button" class="sd-chip" data-pref="pageAnim" data-val="fade">淡入</button>
                <button type="button" class="sd-chip" data-pref="pageAnim" data-val="slide">上滑</button>
            </div>
        </div>
    </div>
    <div class="sd-foot">
        <button type="button" class="btn-plain" id="settingsReset">恢复默认</button>
    </div>
</aside>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <filter id="adminColorWeakFilter">
        <feColorMatrix type="matrix" values="0.625 0.375 0 0 0  0.7 0.3 0 0 0  0 0.3 0.7 0 0  0 0 0 1 0"/>
    </filter>
</svg>
<div class="toast-box" id="toastBox"></div>
<div class="modal-mask" id="confirmMask">
    <div class="modal-box">
        <div class="modal-title" id="confirmTitle">确认操作</div>
        <div class="modal-text" id="confirmText">确定执行该操作吗？</div>
        <input type="password" class="form-input" id="confirmInput" style="display:none" placeholder="">
        <div class="modal-actions">
            <button type="button" class="btn-plain" id="confirmCancel">取消</button>
            <button type="button" class="btn-danger" id="confirmOk">确认</button>
        </div>
    </div>
</div>
<script src="assets/admin.js"></script>
<script src="../assets/js/ui-shared.js"></script>
<?php foreach ($extraScripts as $src): ?>
<script src="<?php echo e($src); ?>"></script>
<?php endforeach; ?>
</body>
</html>
    <?php
}
