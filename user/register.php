<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/Security.php';
require_once ROOT_PATH . '/core/Captcha.php';
require dirname(__DIR__) . '/core/Mail.php';

config();

if (current_user()) {
    redirect('dashboard.php');
}

if (cfg('register_mode', '1') !== '1') {
    flash_set('err', '当前站点未开放自助注册，如需账号请联系管理员开通。');
    redirect('index.php');
}

$mailOn  = mail_code_available();
$capOn   = captcha_scene_on('register');
$capMode = captcha_mode();
// 极验仅在邮箱验证码发送环节介入；若未开启邮箱验证码，注册提交回退为图形验证码
$regMode = (!$mailOn && $capMode === 'geetest') ? 'image' : $capMode;

function reg_mask_email(string $email): string
{
    return preg_replace('/^(.).*?(@.*)$/', '$1****$2', $email);
}

if (is_post() && (string)($_POST['action'] ?? '') === 'sendcode') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = static function (bool $ok, string $msg, array $extra = []): never {
        echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (sec_rate_hit('register_send', client_ip(), 10) > 10) {
        $reply(false, '操作过于频繁，请 1 分钟后再试。');
    }
    if (!isset($_POST['csrf']) || !hash_equals(csrf_token(), (string)$_POST['csrf'])) {
        $reply(false, '页面已过期，请刷新后重试。');
    }
    $email = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $reply(false, '请先正确填写邮箱地址。');
    }

    if ($capOn) {
        [$vok, $vmsg] = captcha_verify_scene('register', $_POST, $regMode);
        if (!$vok) {
            $reply(false, $vmsg);
        }
    }

    $stmt = db()->prepare('SELECT `id` FROM `user` WHERE `email`=?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        $reply(false, '该邮箱已被注册，请更换邮箱。');
    }

    $res = mail_code_send('register_code', $email);
    if (!$res['ok']) {
        $reply(false, $res['msg']);
    }
    $_SESSION['register_code_wait'] = 60;
    $reply(true, '验证码已发送，请查收邮件（10 分钟内有效）。', ['masked' => reg_mask_email($email)]);
}

$error = '';
if (is_post()) {
    csrf_check();
    if (sec_rate_hit('register_user', client_ip(), 20) > 20) {
        http_response_code(429);
        exit('操作过于频繁，请 1 分钟后再试。');
    }
    $username  = trim((string)($_POST['username'] ?? ''));
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    $email     = trim((string)($_POST['email'] ?? ''));
    $invite    = strtoupper(trim((string)($_POST['invite_code'] ?? '')));

    if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
        $error = '账号需为 3~32 位字母、数字或下划线。';
    } elseif (strlen($password) < 6 || strlen($password) > 64) {
        $error = '密码长度需在 6 ~ 64 位之间。';
    } elseif ($password !== $password2) {
        $error = '两次输入的密码不一致。';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '请输入正确的邮箱地址。';
    } elseif ($mailOn && !mail_code_verify('register_code', $email, trim((string)($_POST['email_code'] ?? '')))) {
        $error = '邮箱验证码不正确或已过期，请重新获取。';
    } elseif (!$mailOn) {
        [$vok2, $vmsg2] = captcha_verify_scene('register', $_POST, $regMode);
        if (!$vok2) {
            $error = $vmsg2;
        }
    }

    $agentId = 0;
    if ($error === '' && $invite !== '') {
        $stmt = db()->prepare('SELECT `id`,`status` FROM `agent` WHERE `invite_code`=?');
        $stmt->execute([$invite]);
        $invAgent = $stmt->fetch();
        if (!$invAgent || (int)$invAgent['status'] !== 1) {
            $error = '邀请码无效，请核对后重新输入或留空。';
        } else {
            $agentId = (int)$invAgent['id'];
        }
    }

    if ($error === '') {
        $stmt = db()->prepare('SELECT `id` FROM `user` WHERE `username`=?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = '该账号已被注册。';
        } else {
            $stmt = db()->prepare('SELECT `id` FROM `user` WHERE `email`=?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = '该邮箱已被注册。';
            } else {
                $stmt = db()->prepare('INSERT INTO `user` (`username`,`password`,`email`,`status`,`agent_id`,`reg_ip`,`created_at`) VALUES (?,?,?,1,?,?,?)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email, $agentId, client_ip(), time()]);
                $uid = (int)db()->lastInsertId();
                audit('user', 'register', 3, $uid, '注册账号：' . $username . ($agentId > 0 ? '（邀请码绑定代理商#' . $agentId . '）' : ''));
                mail_scene_send('register_ok', $email, [
                    'userName' => $username,
                    'email'    => $email,
                    'time'     => date('Y-m-d H:i'),
                ]);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $uid;
                flash_set('ok', '注册成功，欢迎使用！');
                redirect('dashboard.php');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>注册账号</title>
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
.captcha-img{height:38px;border-radius:var(--r);cursor:pointer;flex:none;border:1px solid var(--bd);background:#FFF}
.login-btn{display:inline-block;width:100%;height:40px;line-height:40px;background:var(--p);color:#FFF;border:none;border-radius:var(--r);font-size:14px;cursor:pointer;margin-top:4px;transition:background .15s;text-align:center}
.login-btn:hover{background:var(--p-hover)}
.btn-plain{display:inline-flex;align-items:center;justify-content:center;height:36px;padding:0 14px;background:var(--card);border:1px solid var(--bd);border-radius:var(--r);font-size:13px;color:var(--t2);cursor:pointer;white-space:nowrap;text-decoration:none}
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
<body style="background:#F2F3F5 url('../assets/img/zcbj.jpg') center/cover no-repeat fixed;gap:72px;">
<div class="auth-intro">
    <h1>软件授权与卡密<br>一站式自助管理</h1>
    <p class="intro-desc">注册即可在线购买授权、兑换卡密、管理设备与工单，全流程自助完成。</p>
    <div class="capsule-list">
        <span class="capsule"><img src="../assets/svg/menu-key.svg" alt="">在线购买授权</span>
        <span class="capsule"><img src="../assets/svg/menu-code.svg" alt="">卡密兑换开通</span>
        <span class="capsule"><img src="../assets/svg/menu-app.svg" alt="">多应用统一管理</span>
        <span class="capsule"><img src="../assets/svg/menu-money.svg" alt="">财务明细清晰</span>
    </div>
</div>
<div class="login-panel">
    <div class="login-brand">
        <img src="<?php echo e(site_logo_url('../')); ?>" alt="">
        <span><?php echo e(cfg('site_name', 'ZeroAuth')); ?></span>
    </div>
    <p class="login-sub">注册用户中心账号 · V<?php echo SYS_VERSION; ?></p>

    <?php $flash = flash_take(); ?>
    <?php if ($error !== '' || $flash): ?>
    <div class="alert alert-<?php echo $error !== '' ? 'error' : ($flash['type'] === 'ok' ? 'ok' : 'error'); ?>">
        <img src="../assets/svg/<?php echo $error !== '' || $flash['type'] !== 'ok' ? 'warn-circle' : 'check-circle'; ?>.svg" alt="">
        <span><?php echo $error !== '' ? e($error) : e($flash['msg']); ?></span>
    </div>
    <?php endif; ?>

    <form method="post" class="login-form" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="form-item">
            <label class="form-label">账号</label>
            <input class="form-input" type="text" name="username" value="<?php echo e(trim((string)($_POST['username'] ?? ''))); ?>" minlength="3" maxlength="32" placeholder="3~32 位字母、数字或下划线" required>
        </div>
        <div class="form-item">
            <label class="form-label">设置密码</label>
            <input class="form-input" type="password" name="password" minlength="6" maxlength="64" placeholder="6 位以上" required>
        </div>
        <div class="form-item">
            <label class="form-label">确认密码</label>
            <input class="form-input" type="password" name="password2" placeholder="再次输入密码" required>
        </div>
        <div class="form-item">
            <label class="form-label">邮箱</label>
            <input class="form-input" type="email" name="email" id="reg-email" value="<?php echo e(trim((string)($_POST['email'] ?? ''))); ?>" maxlength="128" placeholder="用于接收通知与找回密码" required>
        </div>
        <div class="form-item">
            <label class="form-label">代理商邀请码（选填）</label>
            <input class="form-input" type="text" name="invite_code" value="<?php echo e(strtoupper(trim((string)($_POST['invite_code'] ?? '')))); ?>" maxlength="10" placeholder="填写后自动绑定邀请您的代理商" style="text-transform:uppercase">
        </div>
        <?php if ($mailOn): ?>
        <?php if ($capOn && $regMode === 'turnstile'): ?>
        <div class="form-item">
            <label class="form-label">安全验证</label>
            <?php echo turnstile_widget_html(); ?>
        </div>
        <?php endif; ?>
        <div class="form-item">
            <label class="form-label">验证码</label>
            <div class="captcha-row">
                <input class="form-input" type="text" name="email_code" maxlength="6" placeholder="6 位数字验证码" required>
                <button class="btn-plain" type="button" id="btn-sendcode">获取验证码</button>
            </div>
            <div class="form-hint" id="code-hint">点击「获取验证码」，验证码将发送到上方邮箱</div>
        </div>
        <?php elseif ($capOn && $regMode === 'turnstile'): ?>
        <div class="form-item">
            <label class="form-label">安全验证</label>
            <?php echo turnstile_widget_html(); ?>
        </div>
        <?php elseif ($capOn): ?>
        <div class="form-item">
            <label class="form-label">图形验证码</label>
            <div class="captcha-row">
                <input class="form-input" type="text" name="captcha" maxlength="4" placeholder="请输入验证码" required>
                <img class="captcha-img" src="captcha.php?v=<?php echo SYS_VERSION; ?>&t=<?php echo time(); ?>" alt="验证码" title="点击刷新" onclick="this.src='captcha.php?t='+Date.now()">
            </div>
        </div>
        <?php endif; ?>
        <button class="login-btn" type="submit">注 册</button>
    </form>
    <div class="login-links">
        <span>已有账号？<a href="index.php">直接登录</a></span>
        <a href="../">返回首页</a>
    </div>
</div>

<?php if ($mailOn && $capOn && $regMode === 'image'): ?>
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
<?php if ($mailOn): ?>
(function () {
    var emailInput = document.getElementById('reg-email');
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
    var initWait = <?php echo (int)($_SESSION['register_code_wait'] ?? 0); ?>;
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

    <?php if ($capOn && $regMode === 'turnstile'): ?>

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
    <?php elseif ($capOn && $regMode === 'geetest'): ?>

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
<?php else: ?>

<?php endif; ?>
</script>
<?php if ($capOn && $regMode === 'turnstile'): ?>
<?php echo turnstile_script_html(); ?>
<?php endif; ?>
<?php if ($error !== '' && !$mailOn && $regMode === 'image'): ?>
<script>(function () { var i = document.querySelector('.captcha-img'); if (i) { i.src = i.src.split('?')[0] + '?t=' + Date.now(); } })();</script>
<?php endif; ?>
</body>
</html>
