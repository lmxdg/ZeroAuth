<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/Security.php';
require_once ROOT_PATH . '/core/Captcha.php';
require dirname(__DIR__) . '/core/Mail.php';

config();

if (!is_dir(RUNTIME_PATH)) {
    @mkdir(RUNTIME_PATH, 0755, true);
}

if (current_agent()) {
    redirect('dashboard.php');
}

$mailOn   = mail_code_available();
$capMode  = captcha_mode();

function agent_forgot_mask_email(string $email): string
{
    return preg_replace('/^(.).*?(@.*)$/', '$1****$2', $email);
}

function agent_forgot_find_agent(string $email): array
{
    if ($email === '') {
        return [];
    }
    $stmt = db()->prepare('SELECT `id`,`username`,`email`,`status` FROM `agent` WHERE `email`=?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['status'] !== 1) {
        return [];
    }
    return $row;
}

if (is_post() && (string)($_POST['action'] ?? '') === 'sendcode') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = static function (bool $ok, string $msg, array $extra = []): never {
        echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (sec_rate_hit('forgot_agent', client_ip(), 10) > 10) {
        $reply(false, '操作过于频繁，请 1 分钟后再试。');
    }
    if (!isset($_POST['csrf']) || !hash_equals(csrf_token(), (string)$_POST['csrf'])) {
        $reply(false, '页面已过期，请刷新后重试。');
    }
    if (!$mailOn) {
        $reply(false, '邮件功能未开启，请联系管理员重置密码。');
    }
    $email = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $reply(false, '请先正确填写邮箱地址。');
    }

    [$vok, $vmsg] = captcha_verify_scene('forgot', $_POST);
    if (!$vok) {
        $reply(false, $vmsg);
    }

    $row = agent_forgot_find_agent($email);
    if (!$row) {
        audit('login', 'agent_forgot_unknown', 2, 0, '忘记密码请求邮箱：' . $email . ' IP：' . client_ip());
        $reply(false, '该邮箱未绑定任何代理商账号，请核对后重试。');
    }

    $res = mail_code_send('agent_forgot', $email, ['userName' => (string)$row['username']]);
    if (!$res['ok']) {
        $reply(false, $res['msg']);
    }
    $_SESSION['agent_forgot_code_wait'] = 60;
    $reply(true, '验证码已发送，请查收邮件（10 分钟内有效）。', ['masked' => agent_forgot_mask_email($email)]);
}

$error = '';
if (is_post()) {
    csrf_check();
    if (sec_rate_hit('forgot_agent', client_ip(), 10) > 10) {
        http_response_code(429);
        exit('操作过于频繁，请 1 分钟后再试。');
    }
    $email = trim((string)($_POST['email'] ?? ''));
    $code  = trim((string)($_POST['email_code'] ?? ''));
    $new   = (string)($_POST['new_password'] ?? '');
    $new2  = (string)($_POST['new_password2'] ?? '');

    $row = agent_forgot_find_agent($email);
    $err = '';
    if ($email === '') {
        $err = '请输入注册邮箱。';
    } elseif ($row === []) {
        $err = '邮箱验证码不正确或已过期。';   
    } elseif ($code === '') {
        $err = '请输入邮箱验证码。';
    } elseif (!mail_code_verify('agent_forgot', $email, $code)) {
        $err = '邮箱验证码不正确或已过期。';
    } elseif (strlen($new) < 6 || strlen($new) > 64) {
        $err = '新密码长度需在 6 ~ 64 位之间。';
    } elseif ($new !== $new2) {
        $err = '两次输入的新密码不一致。';
    }

    if ($err !== '') {
        $error = $err;
    } else {
        db()->prepare('UPDATE `agent` SET `password`=? WHERE `id`=?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$row['id']]);
        audit('login', 'agent_password_reset', 2, (int)$row['id'], '通过邮箱验证码重置密码 IP：' . client_ip());
        unset($_SESSION['agent_forgot_code_wait']);
        flash_set('ok', '密码已重置，请使用新密码登录。');
        redirect('index.php');
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>找回密码</title>
<?php echo site_meta_tags('../'); ?>
<style>

:root{--p:#006EFF;--p-hover:#0052D9;--p-bg:#ECF2FE;--ok:#00B42A;--ok-bg:#E8FFEA;--err:#F53F3F;--err-bg:#FFECE8;--t1:#1D2129;--t2:#4E5969;--t3:#86909C;--bd:#E5E6EB;--bg:#F2F3F5;--card:#FFF;--r:4px}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;font-size:14px;color:var(--t1);background:var(--bg);-webkit-font-smoothing:antialiased;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px}
a{text-decoration:none;color:inherit}img{vertical-align:middle}
.login-panel{width:400px;max-width:100%;background:var(--card);border:1px solid var(--bd);border-radius:var(--r);padding:32px 28px}
.login-brand{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.login-brand img{width:30px;height:30px}
.login-brand span{font-size:18px;font-weight:600;color:var(--t1)}
.login-sub{font-size:13px;color:var(--t3);margin-bottom:22px}
.alert{display:flex;align-items:flex-start;gap:8px;padding:10px 14px;border:1px solid var(--bd);border-radius:var(--r);font-size:13px;margin-bottom:16px;line-height:20px}
.alert img{width:15px;height:15px;flex:none;margin-top:2px}
.alert-ok{background:var(--ok-bg);border-color:#B8EDC3;color:#00881F}
.alert-error{background:var(--err-bg);border-color:#FDC9C9;color:#C22F2F}
.form-item{margin-bottom:16px}
.form-label{display:block;font-size:13px;color:var(--t2);margin-bottom:6px}
.form-input{width:100%;height:38px;padding:0 12px;border:1px solid var(--bd);border-radius:var(--r);color:var(--t1);background:var(--card);outline:none;transition:border-color .15s;font-size:14px}
.form-input:focus{border-color:var(--p);box-shadow:0 0 0 2px rgba(0,110,255,.08)}
.form-hint{font-size:12px;color:var(--t3);margin-top:6px;line-height:18px}
.captcha-row{display:flex;gap:10px;align-items:center}
.captcha-row .form-input{flex:1;min-width:0}
.login-btn{width:100%;height:40px;background:var(--p);color:#FFF;border:none;border-radius:var(--r);font-size:14px;cursor:pointer;margin-top:4px;transition:background .15s}
.login-btn:hover{background:var(--p-hover)}
.login-btn:disabled{opacity:.6;cursor:not-allowed}
.btn-plain{display:inline-flex;align-items:center;justify-content:center;height:38px;padding:0 14px;background:var(--card);border:1px solid var(--bd);border-radius:var(--r);font-size:13px;color:var(--t2);cursor:pointer;white-space:nowrap}
.btn-plain:hover{color:var(--p);border-color:var(--p)}
.btn-plain:disabled{opacity:.6;cursor:not-allowed}
.login-links{display:flex;justify-content:space-between;gap:10px;margin-top:16px;font-size:13px;color:var(--t3);flex-wrap:wrap}
.login-links a{color:var(--p)}

.veil{position:fixed;inset:0;background:rgba(29,33,41,.45);z-index:99990;display:none;align-items:center;justify-content:center;padding:16px}
.veil.on{display:flex}
.vcap{width:330px;max-width:100%;background:var(--card);border:1px solid var(--bd);border-radius:var(--r);padding:20px;box-shadow:0 12px 32px rgba(31,35,41,.16)}
.vcap-title{font-size:15px;font-weight:600;color:var(--t1);margin-bottom:14px}
.vcap-err{display:none;align-items:flex-start;gap:8px;background:var(--err-bg);border:1px solid #FDC9C9;color:#C22F2F;border-radius:var(--r);font-size:12px;padding:8px 10px;margin-bottom:12px;line-height:18px}
.vcap-err img{width:14px;height:14px;flex:none;margin-top:2px}
.vcap-img{height:38px;border-radius:var(--r);cursor:pointer;flex:none;border:1px solid var(--bd);background:#FFF}
.vcap-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px}
.vcap-actions .btn-pri{height:34px;padding:0 16px;background:var(--p);color:#FFF;border:none;border-radius:var(--r);font-size:13px;cursor:pointer}
.vcap-actions .btn-pri:hover{background:var(--p-hover)}

.toast{position:fixed;top:24px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;align-items:center;gap:8px;background:#FFF;border:1px solid var(--bd);border-left:3px solid var(--err);border-radius:var(--r);box-shadow:0 8px 24px rgba(31,35,41,.12);padding:10px 16px;font-size:14px;color:var(--t1);opacity:0;transition:opacity .2s;pointer-events:none}
.toast img{width:16px;height:16px;flex:none}

.auth-intro{flex:1;min-width:0;max-width:460px;color:#FFF}
.auth-intro h1{font-size:30px;font-weight:600;line-height:44px;margin:0 0 14px;text-shadow:0 2px 12px rgba(0,0,0,.35)}
.intro-desc{font-size:15px;line-height:26px;color:rgba(255,255,255,.92);margin:0 0 26px;text-shadow:0 1px 8px rgba(0,0,0,.35)}
.capsule-list{display:flex;flex-wrap:wrap;gap:12px}
.capsule{display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 14px;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.4);color:#FFF;font-size:13px;backdrop-filter:blur(4px)}
.capsule img{width:15px;height:15px;flex:none}
@media (max-width:960px){.auth-intro{display:none}}
</style>
</head>
<body style="background:#F2F3F5 url('../assets/img/wjmmbj.jpg') center/cover no-repeat fixed;gap:72px;">
<div class="auth-intro">
    <h1>代理商开卡<br>与分销管理工作台</h1>
    <p class="intro-desc">通过注册邮箱即可自助找回密码，验证码邮件秒级送达，全程加密保护账号安全。</p>
    <div class="capsule-list">
        <span class="capsule"><img src="../assets/svg/menu-key.svg" alt="">批量开通授权</span>
        <span class="capsule"><img src="../assets/svg/menu-money.svg" alt="">等级折扣结算</span>
        <span class="capsule"><img src="../assets/svg/menu-agent.svg" alt="">下级用户管理</span>
        <span class="capsule"><img src="../assets/svg/menu-pay.svg" alt="">余额快捷支付</span>
    </div>
</div>
<div class="login-panel">
    <div class="login-brand">
        <img src="<?php echo e(site_logo_url('../')); ?>" alt="">
        <span><?php echo e(cfg('site_name', '软件授权管理系统')); ?></span>
    </div>
    <p class="login-sub">找回代理商密码 · V<?php echo SYS_VERSION; ?></p>

    <?php $flash = flash_take(); ?>
    <?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
        <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
        <span><?php echo e($flash['msg']); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
    <div class="alert alert-error">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span><?php echo e($error); ?></span>
    </div>
    <?php endif; ?>

    <form method="post" class="login-form" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="form-item">
            <label class="form-label">注册邮箱</label>
            <input class="form-input" type="email" name="email" id="email-input" value="<?php echo e(trim((string)($_POST['email'] ?? ''))); ?>" maxlength="128" placeholder="请输入注册时绑定的邮箱" required>
        </div>
        <?php if ($capMode === 'turnstile'): ?>
        <div class="form-item">
            <label class="form-label">安全验证</label>
            <?php echo turnstile_widget_html(); ?>
        </div>
        <?php endif; ?>
        <div class="form-item">
            <label class="form-label">邮箱验证码</label>
            <div class="captcha-row">
                <input class="form-input" type="text" name="email_code" maxlength="6" placeholder="6 位数字验证码">
                <button class="btn-plain" type="button" id="btn-sendcode">获取验证码</button>
            </div>
            <div class="form-hint" id="code-hint">点击「获取验证码」，验证码将发送到本邮箱</div>
        </div>
        <div class="form-item">
            <label class="form-label">新密码</label>
            <input class="form-input" type="password" name="new_password" minlength="6" maxlength="64" placeholder="6 ~ 64 位">
        </div>
        <div class="form-item">
            <label class="form-label">确认新密码</label>
            <input class="form-input" type="password" name="new_password2" placeholder="再次输入新密码">
        </div>
        <button class="login-btn" type="submit" name="action" value="reset">重置密码</button>
    </form>
    <div class="login-links">
        <a href="index.php">返回登录</a>
        <a href="../">返回首页</a>
    </div>
</div>

<?php if ($mailOn && $capMode === 'image'): ?>
<div class="veil" id="captcha-veil">
    <div class="vcap">
        <div class="vcap-title">安全验证</div>
        <div class="vcap-err" id="vcap-err"><img src="../assets/svg/warn-circle.svg" alt=""><span></span></div>
        <div class="form-item">
            <label class="form-label">图形验证码</label>
            <div class="captcha-row">
                <input class="form-input" type="text" id="vcap-input" maxlength="4" placeholder="请输入图中数字">
                <img class="vcap-img" id="vcap-img" src="captcha.php?v=<?php echo SYS_VERSION; ?>" alt="验证码" title="点击刷新">
            </div>
        </div>
        <div class="vcap-actions">
            <button class="btn-plain" type="button" id="vcap-cancel">取消</button>
            <button class="btn-pri" type="button" id="vcap-ok">确认</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var emailInput = document.getElementById('email-input');
    var btn = document.getElementById('btn-sendcode');
    var hint = document.getElementById('code-hint');
    var csrf = (document.querySelector('input[name=csrf]') || {}).value || '';
    var veil = null; 


    function toast(msg) {
        var old = document.getElementById('jc-toast');
        if (old && old.parentNode) old.parentNode.removeChild(old);
        var t = document.createElement('div');
        t.id = 'jc-toast';
        t.className = 'toast';
        var icon = document.createElement('img');
        icon.src = '../assets/svg/warn-circle.svg';
        icon.alt = '';
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


    var timer = null;
    function countdown(left) {
        if (timer) { clearInterval(timer); timer = null; }
        btn.disabled = true;
        timer = setInterval(function () {
            if (left <= 0) {
                clearInterval(timer);
                timer = null;
                btn.disabled = false;
                btn.textContent = '获取验证码';
                return;
            }
            btn.textContent = left + ' 秒后重发';
            left--;
        }, 1000);
    }
    var initWait = <?php echo (int)($_SESSION['agent_forgot_code_wait'] ?? 0); ?>;
    if (initWait > 0) countdown(initWait);

    function send(fd, failCb) {
        btn.disabled = true;
        fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.ok) {
                    if (veil) veil.classList.remove('on');
                    hint.textContent = '验证码已发送至 ' + (j.masked || emailInput.value.trim()) + '，10 分钟内有效';
                    toast('验证码已发送，请查收邮件');
                    countdown(60);
                } else {
                    btn.disabled = false;
                    failCb ? failCb(j.msg) : toast(j.msg);
                }
            })
            .catch(function () {
                btn.disabled = false;
                failCb ? failCb('网络异常，请稍后重试') : toast('网络异常，请稍后重试');
            });
    }

    function baseForm() {
        var fd = new FormData();
        fd.append('action', 'sendcode');
        fd.append('ajax', '1');
        fd.append('csrf', csrf);
        fd.append('email', emailInput.value.trim());
        return fd;
    }

    function emailOk() {
        var v = emailInput.value.trim();
        if (!v || v.indexOf('@') < 1) { toast('请先正确填写邮箱地址'); emailInput.focus(); return false; }
        return true;
    }

    <?php if (!$mailOn): ?>

    btn.addEventListener('click', function () { toast('邮件功能未开启，请联系管理员重置密码。'); });
    <?php elseif ($capMode === 'turnstile'): ?>

    btn.addEventListener('click', function () {
        if (btn.disabled || !emailOk()) return;
        var token = '';
        var tEl = document.querySelector('[name=cf-turnstile-response]');
        if (tEl) token = tEl.value;
        if (!token) { toast('请先完成 Turnstile 人机验证'); return; }
        var fd = baseForm();
        fd.append('cf-turnstile-response', token);
        send(fd);
    });
    <?php elseif ($capMode === 'geetest'): ?>

    var gtObj = null, gtReady = false, gtLoading = false, gtInited = false, gtPending = false;
    function gtInit() {
        if (gtInited) return;
        gtInited = true;
        initGeetest4({
            captchaId: '<?php echo e(cfg('geetest_captcha_id', '')); ?>',
            product: 'bind',
            language: 'zho'
        }, function (c) {
            gtObj = c;
            c.onReady(function () {
                gtReady = true;
                if (gtPending) { gtPending = false; c.showCaptcha(); }
            });
            c.onSuccess(function () {
                var r = c.getValidate();
                if (!r) return;
                var fd = baseForm();
                fd.append('gt_lot_number', r.lot_number);
                fd.append('gt_captcha_output', r.captcha_output);
                fd.append('gt_pass_token', r.pass_token);
                fd.append('gt_gen_time', r.gen_time);
                send(fd);
            });
            c.onError(function () { toast('行为验证出错，请重试'); });
        });
    }
    function gtLoad() {
        if (gtInited || gtLoading) return;
        if (window.initGeetest4) { gtInit(); return; }
        gtLoading = true;
        var s = document.createElement('script');
        s.src = 'https://static.geetest.com/v4/gt4.js';
        s.onload = gtInit;
        s.onerror = function () { gtLoading = false; toast('验证组件加载失败，请检查网络后重试'); };
        document.head.appendChild(s);
    }
    btn.addEventListener('click', function () {
        if (btn.disabled || !emailOk()) return;
        if (!gtReady || !gtObj) {

            gtLoad();
            gtPending = true;
            toast('验证组件加载中，请稍候…');
            return;
        }
        gtObj.showCaptcha();
    });

    emailInput.addEventListener('focusin', gtLoad);
    <?php else: ?>

    veil = document.getElementById('captcha-veil');
    var vcapErr = document.getElementById('vcap-err');
    var vcapInput = document.getElementById('vcap-input');
    var vcapImg = document.getElementById('vcap-img');
    function vcapRefresh() { vcapImg.src = 'captcha.php?v=<?php echo SYS_VERSION; ?>&t=' + Date.now(); }
    function vcapFail(msg) {
        vcapErr.style.display = 'flex';
        vcapErr.querySelector('span').textContent = msg;
        vcapRefresh();
        vcapInput.value = '';
        vcapInput.focus();
    }
    btn.addEventListener('click', function () {
        if (btn.disabled || !emailOk()) return;
        vcapErr.style.display = 'none';
        vcapInput.value = '';
        vcapRefresh();
        veil.classList.add('on');
        setTimeout(function () { vcapInput.focus(); }, 50);
    });
    document.getElementById('vcap-cancel').addEventListener('click', function () { veil.classList.remove('on'); });
    veil.addEventListener('click', function (e) { if (e.target === veil) veil.classList.remove('on'); });
    document.getElementById('vcap-ok').addEventListener('click', function () {
        var v = vcapInput.value.trim();
        if (v === '') { vcapFail('请输入图中数字'); return; }
        var fd = baseForm();
        fd.append('captcha', v);
        send(fd, vcapFail);
    });
    vcapInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') document.getElementById('vcap-ok').click(); });
    <?php endif; ?>
})();
</script>
<?php if ($capMode === 'turnstile'): ?>
<?php echo turnstile_script_html(); ?>
<?php endif; ?>
</body>
</html>
