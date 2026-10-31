<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/Security.php';
require_once ROOT_PATH . '/core/Captcha.php';

config();

if (!is_dir(RUNTIME_PATH)) {
    @mkdir(RUNTIME_PATH, 0755, true);
}

if (current_agent()) {
    redirect('dashboard.php');
}

$capMode = captcha_mode();
$error = '';
if (is_post()) {
    csrf_check();
    if (sec_rate_hit('login_agent', client_ip(), 20) > 20) {
        http_response_code(429);
        exit('操作过于频繁，请 1 分钟后再试。');
    }
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
        $stmt = db()->prepare('SELECT * FROM `agent` WHERE `username`=?');
        $stmt->execute([$username]);
        $agent = $stmt->fetch();

        if (!$agent) {
            $error = '账号或密码错误。';
            audit('login', 'agent_fail', 2, 0, '未知代理商账号：' . $username);
        } elseif ((int)$agent['status'] !== 1) {
            $error = '该账号已被禁用，请联系管理员。';
            audit('login', 'agent_fail', 2, (int)$agent['id'], '账号已禁用');
        } else {
            $now = time();
            if ((int)$agent['lock_time'] > $now) {
                $error = '失败次数过多，账号已锁定，请 ' . (int)ceil(((int)$agent['lock_time'] - $now) / 60) . ' 分钟后再试。';
                audit('login', 'agent_locked', 2, (int)$agent['id']);
            } elseif (password_verify($password, $agent['password'])) {
                $stmt = db()->prepare('UPDATE `agent` SET `login_fail`=0,`lock_time`=0,`last_login_ip`=?,`last_login_time`=? WHERE `id`=?');
                $stmt->execute([client_ip(), $now, (int)$agent['id']]);
                session_regenerate_id(true);
                $_SESSION['agent_id'] = (int)$agent['id'];
                audit('login', 'agent_success', 2, (int)$agent['id'], 'IP：' . client_ip());
                if ((string)$agent['email'] !== '') {
                    require_once ROOT_PATH . '/core/Mail.php';
                    mail_scene_send('agent_login', (string)$agent['email'], [
                        'userName' => (string)$agent['username'],
                        'ip'       => client_ip(),
                        'time'     => date('Y-m-d H:i:s', $now),
                    ]);
                }
                redirect('dashboard.php');
            } else {
                $fails = (int)$agent['login_fail'] + 1;
                $maxFail = (int)cfg('max_login_fail', '5');
                $lock = $fails >= $maxFail ? $now + 900 : 0;
                $stmt = db()->prepare('UPDATE `agent` SET `login_fail`=?,`lock_time`=? WHERE `id`=?');
                $stmt->execute([$fails, $lock, (int)$agent['id']]);
                $error = '账号或密码错误。';
                if ($lock > 0) {
                    $error = '连续失败 ' . $fails . ' 次，账号已锁定 15 分钟。';
                }
                audit('login', 'agent_fail', 2, (int)$agent['id'], 'IP：' . client_ip() . ' 第' . $fails . '次');
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
<title>代理商登录平台</title>
<?php echo site_meta_tags('../'); ?>
<link rel="preload" as="image" href="../assets/img/dlbj.jpg">
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
.alert-error{background:var(--err-bg);border-color:#FDC9C9;color:#C22F2F}
.form-item{margin-bottom:16px}
.form-label{display:block;font-size:13px;color:var(--t2);margin-bottom:6px}
.form-input{width:100%;height:38px;padding:0 12px;border:1px solid var(--bd);border-radius:var(--r);color:var(--t1);background:var(--card);outline:none;transition:border-color .15s;font-size:14px}
.form-input:focus{border-color:var(--p);box-shadow:0 0 0 2px rgba(0,110,255,.08)}
.captcha-row{display:flex;gap:10px;align-items:center}
.captcha-row .form-input{flex:1;min-width:0}
.captcha-img{height:38px;border-radius:var(--r);cursor:pointer;flex:none;border:1px solid var(--bd);background:#FFF}
.login-btn{width:100%;height:40px;background:var(--p);color:#FFF;border:none;border-radius:var(--r);font-size:14px;cursor:pointer;margin-top:4px;transition:background .15s}
.login-btn:hover{background:var(--p-hover)}
.login-tip{margin-top:16px;font-size:13px;color:var(--t3)}
.login-links{display:flex;justify-content:space-between;gap:10px;margin-top:10px;font-size:13px;flex-wrap:wrap}
.login-links a{color:var(--p)}

.auth-intro{flex:1;min-width:0;max-width:460px;color:#FFF}
.auth-intro h1{font-size:30px;font-weight:600;line-height:44px;margin:0 0 14px;text-shadow:0 2px 12px rgba(0,0,0,.35)}
.intro-desc{font-size:15px;line-height:26px;color:rgba(255,255,255,.92);margin:0 0 26px;text-shadow:0 1px 8px rgba(0,0,0,.35)}
.capsule-list{display:flex;flex-wrap:wrap;gap:12px}
.capsule{display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 14px;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.4);color:#FFF;font-size:13px;backdrop-filter:blur(4px)}
.capsule img{width:15px;height:15px;flex:none}
@media (max-width:960px){.auth-intro{display:none}}
</style>
</head>
<body style="background:#F2F3F5 url('../assets/img/dlbj.jpg') center/cover no-repeat fixed;gap:72px;">
<div class="auth-intro">
    <h1>代理商开卡<br>与分销管理工作台</h1>
    <p class="intro-desc">批量开通授权、等级折扣结算、下级用户统一管理，轻松开展分销业务。</p>
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
        <span>代理商登录平台</span>
    </div>
    <?php if ($error !== ''): ?>
    <div class="alert alert-error">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span><?php echo e($error); ?></span>
    </div>
    <?php endif; ?>

    <form method="post" class="login-form" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="form-item">
            <label class="form-label">代理商账号</label>
            <input class="form-input" type="text" name="username" value="<?php echo e(trim((string)($_POST['username'] ?? ''))); ?>" placeholder="请输入代理商账号" required>
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
        <a href="../user/index.php">用户中心入口</a>
        <a href="../">返回首页</a>
    </div>
    <p class="login-tip">代理商账号由管理员开通，如需开通请联系管理员。</p>
</div>
<?php if ($error !== '' && $capMode === 'image'): ?>
<script>(function () { var i = document.querySelector('.captcha-img'); if (i) { i.src = i.src.split('?')[0] + '?t=' + Date.now(); } })();</script>
<?php endif; ?>
</body>
</html>
<?php if ($capMode === 'turnstile'): ?>
<?php echo turnstile_script_html(); ?>
<?php endif; ?>
