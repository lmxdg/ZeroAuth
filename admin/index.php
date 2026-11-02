<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/Security.php';
require_once ROOT_PATH . '/core/Captcha.php';

config();

if (!is_dir(RUNTIME_PATH)) {
    @mkdir(RUNTIME_PATH, 0755, true);
}

if (current_admin()) {
    redirect('dashboard.php');
}

if (isset($_GET['reset'])) {
    unset($_SESSION['admin_totp_pending']);
    redirect('index.php');
}

$capMode = captcha_mode();
$error = '';
$loginSuccess = function (array $admin, string $detail = ''): void {
    $now = time();
    db()->prepare('UPDATE `admin` SET `login_fail`=0,`lock_time`=0,`last_login_ip`=?,`last_login_time`=? WHERE `id`=?')
        ->execute([client_ip(), $now, (int)$admin['id']]);
    unset($_SESSION['admin_totp_pending']);
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];
    audit('login', 'admin_success', 1, (int)$admin['id'], $detail !== '' ? $detail : 'IP：' . client_ip());
    require_once ROOT_PATH . '/core/Mail.php';
    mail_scene_send('admin_login', '', [
        'userName' => (string)$admin['username'],
        'ip'       => client_ip(),
        'time'     => date('Y-m-d H:i:s', $now),
    ]);
    redirect('dashboard.php');
};

if (is_post()) {
    csrf_check();
    if (sec_rate_hit('login_admin', client_ip(), 20) > 20) {
        http_response_code(429);
        exit('操作过于频繁，请 1 分钟后再试。');
    }

    if (isset($_POST['totp_code'])) {
        $code = preg_replace('/\D/', '', (string)$_POST['totp_code']) ?? '';
        $pending = $_SESSION['admin_totp_pending'] ?? null;
        if (!is_array($pending) || empty($pending['id']) || time() - (int)($pending['time'] ?? 0) > 600) {
            unset($_SESSION['admin_totp_pending']);
            $error = '二次验证已过期，请重新登录。';
        } else {
            $stmt = db()->prepare('SELECT * FROM `admin` WHERE `id`=? LIMIT 1');
            $stmt->execute([(int)$pending['id']]);
            $admin = $stmt->fetch();
            if (!$admin || (int)$admin['status'] !== 1 || (int)($admin['totp_enabled'] ?? 0) !== 1 || (string)($admin['totp_secret'] ?? '') === '') {
                unset($_SESSION['admin_totp_pending']);
                $error = '账号状态异常，请重新登录。';
            } elseif (strlen($code) !== 6 || !sec_totp_verify((string)$admin['totp_secret'], $code)) {
                $error = '动态验证码不正确或已过期。';
                audit('login', 'admin_totp_fail', 1, (int)$admin['id'], 'IP：' . client_ip());
            } else {
                $loginSuccess($admin, '二次验证通过 · IP：' . client_ip());
            }
        }
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        // 统一验证码校验：Turnstile（插件）/ 极验（插件）/ 图形验证码
        [$verifyPassed, $verifyMsg] = captcha_verify_scene('login', $_POST);
        if (!$verifyPassed) {
            $error = $verifyMsg;
        }
        if (!$verifyPassed) {
        } elseif ($username === '' || $password === '') {
            $error = '请输入账号与密码。';
        } else {
            $stmt = db()->prepare('SELECT * FROM `admin` WHERE `username`=?');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if (!$admin) {
                $error = '账号或密码错误。';
                audit('login', 'admin_fail', 1, 0, '未知账号：' . $username);
            } elseif ((int)$admin['status'] !== 1) {
                $error = '该账号已被禁用，请联系超级管理员。';
                audit('login', 'admin_fail', 1, (int)$admin['id'], '账号已禁用');
            } else {
                $now = time();
                $lockTime = (int)$admin['lock_time'];
                if ($lockTime > $now) {
                    $error = '失败次数过多，账号已锁定，请 ' . (int)ceil(($lockTime - $now) / 60) . ' 分钟后再试。';
                    audit('login', 'admin_locked', 1, (int)$admin['id']);
                } elseif (password_verify($password, $admin['password'])) {
                    if ((int)($admin['totp_enabled'] ?? 0) === 1 && (string)($admin['totp_secret'] ?? '') !== '') {
                        session_regenerate_id(true);
                        $_SESSION['admin_totp_pending'] = ['id' => (int)$admin['id'], 'time' => $now];
                    } else {
                        $loginSuccess($admin);
                    }
                } else {
                    $fails = (int)$admin['login_fail'] + 1;
                    $maxFail = (int)cfg('max_login_fail', '5');
                    $lock = $fails >= $maxFail ? $now + 900 : 0;
                    $stmt = db()->prepare('UPDATE `admin` SET `login_fail`=?,`lock_time`=? WHERE `id`=?');
                    $stmt->execute([$fails, $lock, (int)$admin['id']]);
                    $error = '账号或密码错误。';
                    if ($lock > 0) {
                        $error = '连续失败 ' . $fails . ' 次，账号已锁定 15 分钟。';
                    }
                    audit('login', 'admin_fail', 1, (int)$admin['id'], 'IP：' . client_ip() . ' 第' . $fails . '次');
                }
            }
        }
    }
}

$totpPending = $_SESSION['admin_totp_pending'] ?? null;
$totpActive = is_array($totpPending) && !empty($totpPending['id']) && time() - (int)($totpPending['time'] ?? 0) <= 600;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>管理员登录</title>
<?php echo site_meta_tags('../'); ?>
<link rel="preload" as="image" href="../assets/img/dlbj.jpg">
<link rel="stylesheet" href="assets/admin.css">
<style>

.auth-intro{flex:1;min-width:0;max-width:460px;color:#FFF}
.auth-intro h1{font-size:30px;font-weight:600;line-height:44px;margin:0 0 14px;text-shadow:0 2px 12px rgba(0,0,0,.35)}
.intro-desc{font-size:15px;line-height:26px;color:rgba(255,255,255,.92);margin:0 0 26px;text-shadow:0 1px 8px rgba(0,0,0,.35)}
.capsule-list{display:flex;flex-wrap:wrap;gap:12px}
.capsule{display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 14px;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.4);color:#FFF;font-size:13px;backdrop-filter:blur(4px)}
.capsule img{width:15px;height:15px;flex:none}
@media (max-width:960px){.auth-intro{display:none}}
</style>
</head>
<body class="login-body" style="background:#F2F3F5 url('../assets/img/dlbj.jpg') center/cover no-repeat fixed;display:flex;align-items:center;justify-content:center;gap:72px;min-height:100vh;padding:24px 16px;box-sizing:border-box;">
<div class="auth-intro">
    <h1>软件授权运营<br>统一管理中心</h1>
    <p class="intro-desc">应用、套餐、订单、财务、插件一体化管理，运营状况一目了然。</p>
    <div class="capsule-list">
        <span class="capsule"><img src="../assets/svg/menu-app.svg" alt="">应用套餐配置</span>
        <span class="capsule"><img src="../assets/svg/menu-pay.svg" alt="">订单支付管理</span>
        <span class="capsule"><img src="../assets/svg/menu-money.svg" alt="">财务实时结算</span>
        <span class="capsule"><img src="../assets/svg/menu-log.svg" alt="">操作安全审计</span>
    </div>
</div>
<div class="login-panel">
    <div class="login-brand">
        <img src="<?php echo e(site_logo_url('../')); ?>" alt="">
        <span><?php echo e(cfg('site_name', 'ZeroAuth')); ?></span>
    </div>
    <p class="login-sub">管理员后台 · V<?php echo SYS_VERSION; ?></p>

    <?php if ($error !== ''): ?>
    <div class="alert alert-error">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span><?php echo e($error); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($totpActive): ?>
    <div class="alert alert-info">
        <img src="../assets/svg/menu-lock.svg" alt="">
        <span>账号已开启动态口令二次验证，请输入身份验证器中的 6 位验证码。</span>
    </div>
    <form method="post" class="login-form" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="form-item">
            <label class="form-label">动态验证码</label>
            <input class="form-input" type="text" name="totp_code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="请输入 6 位动态验证码" autocomplete="one-time-code" autofocus required style="letter-spacing:4px;text-align:center">
        </div>
        <button class="login-btn" type="submit">验证并登录</button>
    </form>
    <div class="login-links">
        <a href="index.php?reset=1">返回重新登录</a>
        <a href="../">返回首页</a>
    </div>
    <?php else: ?>
    <form method="post" class="login-form" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="form-item">
            <label class="form-label">管理员账号</label>
            <input class="form-input" type="text" name="username" value="<?php echo e(trim((string)($_POST['username'] ?? ''))); ?>" placeholder="请输入管理员账号" required>
        </div>
        <div class="form-item">
            <label class="form-label">登录密码</label>
            <input class="form-input" type="password" name="password" placeholder="请输入登录密码" required>
        </div>
        <?php if ($capMode === 'turnstile'): ?>
        <div class="form-item">
            <label class="form-label">安全验证</label>
            <?php echo turnstile_widget_html(); ?>
        </div>
        <?php elseif ($capMode === 'geetest'): ?>
        <div class="form-item">
            <input type="hidden" name="gt_lot_number">
            <input type="hidden" name="gt_captcha_output">
            <input type="hidden" name="gt_pass_token">
            <input type="hidden" name="gt_gen_time">
        </div>
        <?php else: ?>
        <div class="form-item">
            <label class="form-label">验证码</label>
            <div class="captcha-row">
                <input class="form-input" type="text" name="captcha" maxlength="4" placeholder="请输入验证码" required>
                <img class="captcha-img" id="captcha-img" src="captcha.php?v=<?php echo SYS_VERSION; ?>&t=<?php echo time(); ?>" alt="验证码" title="点击刷新" onclick="this.src='captcha.php?t='+Date.now()">
            </div>
        </div>
        <?php endif; ?>
        <button class="login-btn" type="<?php echo $capMode === 'geetest' ? 'button' : 'submit'; ?>" id="login-btn">登 录</button>
        <?php if ($capMode === 'geetest'): ?>
        <script>

        (function () {
            var form = document.querySelector('.login-form');
            var btn = document.getElementById('login-btn');
            var lot = form.querySelector('[name=gt_lot_number]');
            var out = form.querySelector('[name=gt_captcha_output]');
            var pt = form.querySelector('[name=gt_pass_token]');
            var gt = form.querySelector('[name=gt_gen_time]');
            var obj = null, ready = false, sent = false, loading = false, inited = false, pendingShow = false;


            function toast(msg) {
                var old = document.getElementById('gt-toast');
                if (old && old.parentNode) old.parentNode.removeChild(old);
                var t = document.createElement('div');
                t.id = 'gt-toast';
                t.style.cssText = 'position:fixed;top:24px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;align-items:center;gap:8px;background:#FFFFFF;border:1px solid #E5E7EB;border-left:3px solid #E34D59;border-radius:6px;box-shadow:0 8px 24px rgba(31,35,41,.12);padding:10px 16px;font-size:14px;color:#1F2329;opacity:0;transition:opacity .2s;';
                var icon = document.createElement('img');
                icon.src = '../assets/svg/warn-circle.svg';
                icon.alt = '';
                icon.style.cssText = 'width:16px;height:16px;flex:none;';
                var span = document.createElement('span');
                span.textContent = msg;
                t.appendChild(icon);
                t.appendChild(span);
                document.body.appendChild(t);
                requestAnimationFrame(function () { t.style.opacity = '1'; });
                setTimeout(function () {
                    t.style.opacity = '0';
                    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 250);
                }, 3000);
            }


            function showVerify() {
                if (sent) return;
                if (!form.checkValidity()) {
                    var first = form.querySelector('input:invalid');
                    toast(first && first.name === 'username' ? '请输入账号' : '请输入登录密码');
                    if (first) first.focus();
                    return;
                }
                if (!ready || !obj) {

                    gtLoad();
                    pendingShow = true;
                    toast('验证组件加载中，请稍候…');
                    return;
                }
                obj.showCaptcha();
            }


            function gtInit() {
                if (inited) return;
                inited = true;
                initGeetest4({
                    captchaId: '<?php echo e(cfg('geetest_captcha_id', '')); ?>',
                    product: 'bind',
                    language: 'zho'
                }, function (captchaObj) {
                    obj = captchaObj;
                    captchaObj.onReady(function () {
                        ready = true;
                        if (pendingShow && !sent) { pendingShow = false; captchaObj.showCaptcha(); }
                    });
                    captchaObj.onSuccess(function () {
                        var r = captchaObj.getValidate();
                        if (!r || sent) return;
                        sent = true;
                        lot.value = r.lot_number;
                        out.value = r.captcha_output;
                        pt.value = r.pass_token;
                        gt.value = r.gen_time;
                        form.submit();
                    });
                    captchaObj.onError(function () {
                        toast('行为验证出错，请重试');
                    });
                });
            }
            function gtLoad() {
                if (inited || loading) return;
                if (window.initGeetest4) { gtInit(); return; }
                loading = true;
                var s = document.createElement('script');
                s.src = 'https://static.geetest.com/v4/gt4.js';
                s.onload = gtInit;
                s.onerror = function () { loading = false; toast('验证组件加载失败，请检查网络后重试'); };
                document.head.appendChild(s);
            }

            btn.addEventListener('click', showVerify);

            form.addEventListener('focusin', gtLoad);

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                showVerify();
            });
        })();
        </script>
        <?php endif; ?>
    </form>
    <div class="login-links">
        <a href="forgot.php">忘记密码？</a>
        <a href="../">返回首页</a>
    </div>
    <?php endif; ?>
<?php if ($error !== '' && $capMode === 'image' && !$totpActive): ?>
<script>(function () { var i = document.querySelector('.captcha-img'); if (i) { i.src = i.src.split('?')[0] + '?t=' + Date.now(); } })();</script>
<?php endif; ?>
</body>
</html>
<?php if ($capMode === 'turnstile'): ?>
<?php echo turnstile_script_html(); ?>
<?php endif; ?>
