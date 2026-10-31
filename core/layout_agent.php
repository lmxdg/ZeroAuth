<?php
// 代理商中心公共布局
declare(strict_types=1);

function agent_header(string $title, string $active, array $agent): void
{
    $menu = [
        'dashboard'      => ['概览首页', 'dashboard.php', 'menu-home'],
        'license_manage' => ['我的授权', 'license_manage.php', 'menu-key'],
        'users'          => ['名下用户', 'users.php', 'menu-agent'],
        'buy'            => ['开通授权', 'buy.php', 'menu-money'],
        'finance'        => ['我的财务', 'finance.php', 'menu-log'],
        'tickets'        => ['我的工单', 'tickets.php', 'menu-activity'],
        'profile'        => ['个人设置', 'profile.php', 'menu-user'],
    ];
    $discount = rtrim(rtrim(number_format(agent_effective_discount($agent) * 10, 1, '.', ''), '0'), '.');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($title); ?> - 代理商中心</title>
<?php echo site_meta_tags('../'); ?>
<?php echo avatar_fallback_script(); ?>
<style>

:root{--p:#006EFF;--p-hover:#0052D9;--p-bg:#ECF2FE;--ok:#00B42A;--ok-bg:#E8FFEA;--warn:#FF7D00;--warn-bg:#FFF3E8;--err:#F53F3F;--err-bg:#FFECE8;--t1:#1D2129;--t2:#4E5969;--t3:#86909C;--bd:#E5E6EB;--bg:#F2F3F5;--card:#FFF;--r:4px}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;font-size:14px;color:var(--t1);background:var(--bg);-webkit-font-smoothing:antialiased}
a{text-decoration:none;color:inherit}img{vertical-align:middle}
input,select,textarea,button{font-family:inherit;font-size:14px}

.u-wrap{display:flex;min-height:100vh}
.u-side{width:232px;flex:none;background:var(--card);border-right:1px solid var(--bd);position:fixed;top:0;bottom:0;left:0;z-index:30;display:flex;flex-direction:column}
.u-brand{display:flex;align-items:center;gap:10px;padding:0 20px;height:56px;border-bottom:1px solid var(--bd);flex:none}
.u-brand img{width:26px;height:26px}
.u-brand span{font-size:15px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.u-menu{flex:1;overflow-y:auto;padding:12px 12px}
.u-menu a{display:flex;align-items:center;gap:10px;height:40px;padding:0 12px;border-radius:var(--r);color:var(--t2);margin-bottom:2px}
.u-menu a img{width:16px;height:16px;flex:none}
.u-menu a:hover{background:var(--bg);color:var(--t1)}
.u-menu a.on{background:var(--p-bg);color:var(--p);font-weight:500}
.u-side-foot{padding:14px 20px;border-top:1px solid var(--bd);font-size:12px;color:var(--t3);flex:none}
.u-mask{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:25}
.u-main{flex:1;margin-left:232px;min-width:0;display:flex;flex-direction:column}
.u-top{height:56px;background:var(--card);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:12px;padding:0 24px;position:sticky;top:0;z-index:20}
.u-trigger{display:none;width:20px;height:20px;cursor:pointer}
.u-top-title{font-size:14px;font-weight:500;color:var(--t1);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.u-acc{position:relative}
.u-acc-btn{background:none;border:none;padding:4px;border-radius:50%;cursor:pointer;line-height:0}
.u-acc-btn:hover{background:#F2F3F5}
.u-acc-btn img{width:32px;height:32px;border-radius:50%}
.u-acc-panel{display:none;position:absolute;right:0;top:44px;width:200px;background:var(--card);border:1px solid var(--bd);border-radius:var(--r);box-shadow:0 4px 12px rgba(29,33,41,.08);z-index:40}
.u-acc.open .u-acc-panel{display:block}
.u-acc-head{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--bd)}
.u-acc-head img{width:36px;height:36px;border-radius:50%}
.u-acc-head b{display:block;font-size:14px;color:var(--t1)}
.u-acc-head i{display:block;font-style:normal;font-size:12px;color:var(--t3);margin-top:2px}
.u-acc-item{display:flex;align-items:center;gap:8px;padding:10px 16px;color:var(--t2)}
.u-acc-item img{width:14px;height:14px}
.u-acc-item:hover{background:var(--bg);color:var(--t1)}
.u-acc-out{display:block;text-align:center;padding:10px 0;border-top:1px solid var(--bd);color:var(--t2)}
.u-acc-out:hover{color:var(--err)}

.u-body{flex:1;padding:24px}

.card{background:var(--card);border:1px solid var(--bd);border-radius:var(--r);padding:24px;margin-bottom:16px}
.card-title{font-size:16px;font-weight:600;color:var(--t1);margin-bottom:16px}
.card-desc{font-size:13px;color:var(--t3);margin-bottom:16px}
.page-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.page-actions .spacer{flex:1}

.alert{display:flex;align-items:flex-start;gap:8px;padding:10px 14px;border:1px solid var(--bd);border-radius:var(--r);font-size:13px;margin-bottom:16px;line-height:20px}
.alert img{width:15px;height:15px;flex:none;margin-top:2px}
.alert-ok{background:var(--ok-bg);border-color:#B8EDC3;color:#00881F}
.alert-error{background:var(--err-bg);border-color:#FDC9C9;color:#C22F2F}
.alert-info{background:var(--p-bg);border-color:#BEDAFF;color:#0052D9}
.alert-warn{background:var(--warn-bg);border-color:#FFCF8D;color:#873800}

.form-item{margin-bottom:16px}
.form-label{display:block;font-size:13px;color:var(--t2);margin-bottom:6px}
.form-label em{font-style:normal;color:var(--err);margin-left:2px}
.form-input{width:100%;max-width:420px;height:36px;padding:0 12px;border:1px solid var(--bd);border-radius:var(--r);color:var(--t1);background:var(--card);outline:none;transition:border-color .15s}
textarea.form-input{height:auto;min-height:88px;padding:8px 12px;resize:vertical}
.form-input:focus{border-color:var(--p);box-shadow:0 0 0 2px rgba(0,110,255,.08)}
.form-hint{font-size:12px;color:var(--t3);margin-top:6px;line-height:18px}

.btn-p,.btn-plain,.btn-danger{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:34px;padding:0 18px;border-radius:var(--r);font-size:14px;cursor:pointer;border:1px solid transparent;transition:all .15s;white-space:nowrap}
.btn-p{background:var(--p);color:#FFF}
.btn-p:hover{background:var(--p-hover)}
.btn-plain{background:var(--card);color:var(--t2);border-color:var(--bd)}
.btn-plain:hover{color:var(--p);border-color:var(--p)}
.btn-danger{background:var(--err);color:#FFF}
.btn-danger:hover{opacity:.9}
.btn-p:disabled,.btn-plain:disabled{opacity:.5;cursor:not-allowed}

.table-wrap{overflow-x:auto}
.tb{width:100%;border-collapse:collapse;font-size:13px}
.tb th{text-align:left;padding:10px 12px;background:var(--bg);color:var(--t2);font-weight:500;white-space:nowrap}
.tb td{padding:12px;border-bottom:1px solid var(--bd);color:var(--t1);vertical-align:middle}
.tb tr:hover td{background:#FAFBFC}
.cell-code{font-family:Consolas,Monaco,monospace;font-size:12px;color:var(--t2)}

.tag{display:inline-block;padding:1px 8px;border-radius:2px;font-size:12px;line-height:20px}
.tag-ok{background:var(--ok-bg);color:#00881F}
.tag-warn{background:var(--warn-bg);color:#873800}
.tag-err{background:var(--err-bg);color:#C22F2F}
.tag-info{background:var(--p-bg);color:var(--p)}
.tag-gray{background:var(--bg);color:var(--t3)}

.pager{display:flex;gap:6px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}
.pager a,.pager span{min-width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;padding:0 8px;border:1px solid var(--bd);border-radius:var(--r);font-size:13px;color:var(--t2)}
.pager .on{background:var(--p);border-color:var(--p);color:#FFF}
.pager a:hover{color:var(--p);border-color:var(--p)}

.empty-state{display:flex;flex-direction:column;align-items:center;gap:12px;padding:60px 0;color:var(--t3);font-size:13px}
.empty-state img{width:120px;height:120px;opacity:.7}

.modal-mask{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:60;align-items:center;justify-content:center;padding:20px}
.modal-mask.show{display:flex}
.modal-box{width:380px;max-width:100%;background:var(--card);border-radius:var(--r);padding:24px}
.modal-title{font-size:16px;font-weight:600;margin-bottom:12px}
.modal-text{font-size:13px;color:var(--t2);line-height:20px;margin-bottom:20px;word-break:break-all}
.modal-actions{display:flex;justify-content:flex-end;gap:10px}

@media (max-width:768px){
.u-side{transform:translateX(-100%);transition:transform .2s;box-shadow:0 0 20px rgba(0,0,0,.1)}
.u-side.open{transform:translateX(0)}
.u-mask.show{display:block}
.u-main{margin-left:0}
.u-trigger{display:block}
.u-body{padding:16px}
.card{padding:16px}
}
</style>
</head>
<body>
<div class="u-wrap">
    <aside class="u-side" id="uSide">
        <div class="u-brand">
            <img src="<?php echo e(site_logo_url('../')); ?>" alt="">
            <span><?php echo e(cfg('site_name', '软件授权管理系统')); ?></span>
        </div>
        <nav class="u-menu">
            <?php foreach ($menu as $key => [$name, $url, $icon]): ?>
            <a class="<?php echo $active === $key ? 'on' : ''; ?>" href="<?php echo e($url); ?>">
                <img src="../assets/svg/<?php echo $icon; ?>.svg" alt="">
                <span><?php echo e($name); ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="u-side-foot">代理商中心 · <?php echo e(PRODUCT_NAME); ?> V<?php echo e(PRODUCT_VERSION); ?></div>
    </aside>
    <div class="u-mask" id="uMask"></div>
    <div class="u-main">
        <header class="u-top">
            <img class="u-trigger" id="menuTrigger" src="../assets/svg/menu.svg" alt="菜单">
            <div class="u-top-title"><?php echo e($title); ?></div>
            <div class="u-acc" id="uAcc">
                <button class="u-acc-btn" id="uAccBtn" type="button" title="账号菜单">
                    <?php echo avatar_img($agent, ''); ?>
                </button>
                <div class="u-acc-panel">
                    <div class="u-acc-head">
                        <?php echo avatar_img($agent, ''); ?>
                        <div>
                            <b><?php echo e($agent['username']); ?></b>
                            <i>代理商 · <?php echo e($discount); ?> 折扣</i>
                        </div>
                    </div>
                    <a class="u-acc-item" href="profile.php">
                        <img src="../assets/svg/menu-user.svg" alt="">
                        个人设置
                    </a>
                    <a class="u-acc-out" href="logout.php">退出登录</a>
                </div>
            </div>
        </header>
        <?php if (!empty($_SESSION['impersonate']) && $_SESSION['impersonate']['type'] === 'agent'):
            $adminDirImp = (string)(config()['admin_dir'] ?? 'admin'); ?>
        <div style="background:#FFF7E8;border-bottom:1px solid #FFCF8D;color:#873800;padding:8px 24px;font-size:13px;line-height:20px;display:flex;justify-content:space-between;align-items:center;gap:12px">
            <span>管理员「<?php echo e((string)$_SESSION['impersonate']['admin_name']); ?>」正在代登录此账号，所有操作将被记录。</span>
            <a href="../<?php echo e($adminDirImp); ?>/impersonate_exit.php" style="color:#0052D9;white-space:nowrap">结束代登录并返回后台</a>
        </div>
        <?php endif; ?>
        <main class="u-body">
    <?php
}

function agent_footer(): void
{
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
</body>
</html>
    <?php
}
