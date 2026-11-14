<?php
// 用户中心公共布局
declare(strict_types=1);
require_once __DIR__ . '/Ui.php';
require_once __DIR__ . '/AiChat.php';

function user_header(string $title, string $active, array $user): void
{
    $menu = [
        'dashboard'  => ['概览首页', 'dashboard.php', 'menu-home'],
        'licenses'   => ['我的授权', 'licenses.php', 'menu-key'],
        'redeem'     => ['卡密兑换', 'redeem.php', 'menu-code'],
        'buy'        => ['购买授权', 'buy.php', 'menu-money'],
        'finance'    => ['我的财务', 'finance.php', 'menu-log'],
        'tickets'    => ['我的工单', 'tickets.php', 'menu-activity'],
        'bugs'       => ['Bug 广场', 'bugs.php', 'menu-var'],
        'profile'    => ['个人设置', 'profile.php', 'menu-user'],
        'agent_open' => ['开通代理商', 'agent_open.php', 'menu-agent'],
    ];
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<title><?php echo e($title); ?> - 用户中心</title>
<?php echo site_meta_tags('../'); ?>
<?php echo avatar_fallback_script(); ?>
<script>
(function () { try {
    var t = localStorage.getItem('centerTheme') || 'light';
    if (t === 'dark') { document.documentElement.classList.add('theme-dark'); }
} catch (e) { } })();
</script>
<link rel="stylesheet" href="../assets/css/ui-shared.css">
<link rel="stylesheet" href="../assets/css/center.css">
</head>
<body>
<div class="u-wrap">
    <aside class="u-side" id="uSide">
        <div class="u-brand">
            <img src="<?php echo e(site_logo_url('../')); ?>" alt="">
            <span><?php echo e(cfg('site_name', 'ZeroAuth')); ?></span>
        </div>
        <nav class="u-menu">
            <?php foreach ($menu as $key => [$name, $url, $icon]): ?>
            <a class="<?php echo $active === $key ? 'on' : ''; ?>" href="<?php echo e($url); ?>">
                <?php echo ui_icon($icon, $key); ?>
                <span><?php echo e($name); ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="u-side-foot">用户中心 · <?php echo e(PRODUCT_NAME); ?> V<?php echo e(PRODUCT_VERSION); ?></div>
    </aside>
    <div class="u-mask" id="uMask"></div>
    <div class="u-main">
        <header class="u-top">
            <?php echo ui_icon('menu', 'top_menu', 'u-trigger', ['id' => 'menuTrigger']); ?>
            <div class="u-top-title"><?php echo e($title); ?></div>
            <button class="u-theme-btn" id="uThemeBtn" type="button" title="切换深色 / 浅色模式" aria-label="切换主题">
                <?php echo ui_icon('moon', 'top_theme', 'u-theme-img', [
                    'id' => 'uThemeIcon',
                    'data-src-light' => ui_icon_src('moon', 'top_theme'),
                    'data-src-dark' => ui_icon_src('sun', 'top_theme'),
                ]); ?>
            </button>
            <div class="u-acc" id="uAcc">
                <button class="u-acc-btn" id="uAccBtn" type="button" title="账号菜单">
                    <?php echo avatar_img($user, ''); ?>
                </button>
                <div class="u-acc-panel">
                    <div class="u-acc-head">
                        <?php echo avatar_img($user, ''); ?>
                        <div>
                            <b><?php echo e($user['username']); ?></b>
                            <i>用户中心</i>
                        </div>
                    </div>
                    <a class="u-acc-item" href="profile.php">
                        <?php echo ui_icon('menu-user', 'user'); ?>
                        个人设置
                    </a>
                    <a class="u-acc-out" href="logout.php">退出登录</a>
                </div>
            </div>
        </header>
        <?php if (!empty($_SESSION['impersonate']) && $_SESSION['impersonate']['type'] === 'user'):
            $adminDirImp = (string)(config()['admin_dir'] ?? 'admin'); ?>
        <div style="background:#FFF7E8;border-bottom:1px solid #FFCF8D;color:#873800;padding:8px 24px;font-size:13px;line-height:20px;display:flex;justify-content:space-between;align-items:center;gap:12px">
            <span>管理员「<?php echo e((string)$_SESSION['impersonate']['admin_name']); ?>」正在代登录此账号，所有操作将被记录。</span>
            <a href="../<?php echo e($adminDirImp); ?>/impersonate_exit.php" style="color:#0052D9;white-space:nowrap">结束代登录并返回后台</a>
        </div>
        <?php endif; ?>
        <main class="u-body">
    <?php
}

function user_footer(): void
{
    // AI 客服悬浮窗（后台开启且配置完整时渲染）
    ai_cs_widget();
    ?>
        </main>
    </div>
</div>
<div class="modal-mask" id="confirmMask">
    <div class="modal-box">
        <div class="modal-title" id="confirmTitle">确认操作</div>
        <div class="modal-text" id="confirmText">确定执行该操作吗？</div>
        <input type="password" class="form-input" id="confirmInput" style="display:none;max-width:100%" placeholder="请输入登录密码确认">
        <div class="modal-actions">
            <button type="button" class="btn-plain" id="confirmCancel">取消</button>
            <button type="button" class="btn-danger" id="confirmOk">确认</button>
        </div>
    </div>
</div>
<script>

(function () {
    var trigger = document.getElementById('menuTrigger');
    var side = document.getElementById('uSide');
    var mask = document.getElementById('uMask');
    if (trigger && side && mask) {
        trigger.addEventListener('click', function () { side.classList.add('open'); mask.classList.add('show'); });
        mask.addEventListener('click', function () { side.classList.remove('open'); mask.classList.remove('show'); });
    }
    var acc = document.getElementById('uAcc');
    var accBtn = document.getElementById('uAccBtn');
    if (acc && accBtn) {
        accBtn.addEventListener('click', function (e) { e.stopPropagation(); acc.classList.toggle('open'); });
        document.addEventListener('click', function (e) { if (!acc.contains(e.target)) acc.classList.remove('open'); });
    }
    var maskEl = document.getElementById('confirmMask');
    var titleEl = document.getElementById('confirmTitle');
    var textEl = document.getElementById('confirmText');
    var inputEl = document.getElementById('confirmInput');
    var okBtn = document.getElementById('confirmOk');
    var cancelBtn = document.getElementById('confirmCancel');
    var pendingForm = null;
    function showConfirm(txt, needPwd, form) {
        titleEl.textContent = needPwd ? '身份验证' : '确认操作';
        textEl.textContent = txt;
        inputEl.style.display = needPwd ? '' : 'none';
        inputEl.value = '';
        inputEl.name = needPwd ? 'confirm_password' : '';
        pendingForm = form;
        maskEl.classList.add('show');
        if (needPwd) { inputEl.focus(); } else { okBtn.focus(); }
    }
    function hideConfirmInput() {
        inputEl.style.display = 'none';
        inputEl.value = '';
        inputEl.name = '';
    }
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmed === '1') { return; }
            e.preventDefault();
            showConfirm(form.getAttribute('data-confirm') || '确定执行该操作吗？', form.hasAttribute('data-password'), form);
        });
    });
    if (cancelBtn) cancelBtn.addEventListener('click', function () { maskEl.classList.remove('show'); hideConfirmInput(); pendingForm = null; });
    if (maskEl) maskEl.addEventListener('click', function (e) { if (e.target === maskEl) { maskEl.classList.remove('show'); hideConfirmInput(); pendingForm = null; } });
    if (okBtn) okBtn.addEventListener('click', function () {
        if (!pendingForm) { maskEl.classList.remove('show'); return; }
        pendingForm.dataset.confirmed = '1';
        maskEl.classList.remove('show');
        pendingForm.submit();
        pendingForm = null;
    });
})();
</script>
<script src="../assets/js/ui-shared.js"></script>
</body>
</html>
    <?php
}
