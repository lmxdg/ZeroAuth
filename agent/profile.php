<?php
// 代理商中心 - 个人资料
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_agent.php';
require_once ROOT_PATH . '/core/Security.php';
require_once ROOT_PATH . '/core/Captcha.php';
require dirname(__DIR__) . '/core/Mail.php';

$agent = agent_require_login();
$aid   = (int)$agent['id'];
$error = '';

$capMode = captcha_mode();

if (is_post() && (string)($_POST['action'] ?? '') === 'sendcode') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = static function (bool $ok, string $msg, array $extra = []): never {
        echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (sec_rate_hit('agent_profile_send', client_ip(), 10) > 10) {
        $reply(false, '操作过于频繁，请 1 分钟后再试。');
    }
    if (!isset($_POST['csrf']) || !hash_equals(csrf_token(), (string)$_POST['csrf'])) {
        $reply(false, '页面已过期，请刷新后重试。');
    }

    $st = db()->prepare('SELECT `email`,`username` FROM `agent` WHERE `id`=?');
    $st->execute([$aid]);
    $accRow = $st->fetch();
    $email  = (string)($accRow['email'] ?? '');
    if (!mail_code_available() || $email === '') {
        $reply(false, '邮件功能未开启或账号未绑定邮箱，无法发送验证码。');
    }

    [$vok, $vmsg] = captcha_verify_scene('profile', $_POST);
    if (!$vok) {
        $reply(false, $vmsg);
    }

    $res = mail_code_send('agent_forgot', $email, ['userName' => (string)($accRow['username'] ?? '')]);
    if (!$res['ok']) {
        $reply(false, $res['msg']);
    }
    $_SESSION['agent_profile_code_wait'] = 60;
    $masked = preg_replace('/^(.).*?(@.*)$/', '$1****$2', $email);
    $reply(true, '验证码已发送，请查收邮件（10 分钟内有效）。', ['masked' => $masked]);
}

if (is_post() && (string)($_POST['action'] ?? '') === 'bindcode') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = static function (bool $ok, string $msg, array $extra = []): never {
        echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (sec_rate_hit('agent_profile_bind', client_ip(), 10) > 10) {
        $reply(false, '操作过于频繁，请 1 分钟后再试。');
    }
    if (!isset($_POST['csrf']) || !hash_equals(csrf_token(), (string)$_POST['csrf'])) {
        $reply(false, '页面已过期，请刷新后重试。');
    }
    if (!mail_code_available()) {
        $reply(false, '邮件功能未开启，无法发送验证码。');
    }

    $newEmail = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $reply(false, '请先填写正确的新邮箱。');
    }

    $st = db()->prepare('SELECT `email`,`username` FROM `agent` WHERE `id`=?');
    $st->execute([$aid]);
    $accRow  = $st->fetch();
    $curMail = (string)($accRow['email'] ?? '');
    if ($curMail !== '' && strcasecmp($curMail, $newEmail) === 0) {
        $reply(false, '该邮箱已是当前绑定邮箱，请填写其他邮箱。');
    }
    $st = db()->prepare('SELECT `id` FROM `agent` WHERE `email`=? AND `id`<>?');
    $st->execute([$newEmail, $aid]);
    if ($st->fetch()) {
        $reply(false, '该邮箱已被其他代理商使用。');
    }

    [$vok, $vmsg] = captcha_verify_scene('profile', $_POST);
    if (!$vok) {
        $reply(false, $vmsg);
    }

    $res = mail_code_send('agent_bind', $newEmail, ['userName' => (string)($accRow['username'] ?? '')]);
    if (!$res['ok']) {
        $reply(false, $res['msg']);
    }
    $_SESSION['agent_profile_bind_wait'] = 60;
    $masked = preg_replace('/^(.).*?(@.*)$/', '$1****$2', $newEmail);
    $reply(true, '验证码已发送，请查收邮件（10 分钟内有效）。', ['masked' => $masked]);
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'password') {
        $old  = (string)($_POST['old_password'] ?? '');
        $new  = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['new_password2'] ?? '');
        $code = trim((string)($_POST['email_code'] ?? ''));

        $stmt = db()->prepare('SELECT `password`,`email` FROM `agent` WHERE `id`=?');
        $stmt->execute([$aid]);
        $row = $stmt->fetch();
        $needCode = mail_code_available() && (string)$row['email'] !== '';

        if (!password_verify($old, (string)$row['password'])) {
            $error = '当前密码不正确。';
        } elseif (strlen($new) < 6 || strlen($new) > 64) {
            $error = '新密码长度需在 6 ~ 64 位之间。';
        } elseif ($new !== $new2) {
            $error = '两次输入的新密码不一致。';
        } elseif ($needCode && !mail_code_verify('agent_forgot', (string)$row['email'], $code)) {
            $error = '邮箱验证码不正确或已过期，请重新获取。';
        } else {
            db()->prepare('UPDATE `agent` SET `password`=? WHERE `id`=?')->execute([password_hash($new, PASSWORD_DEFAULT), $aid]);
            audit('agent', 'change_password', 2, $aid);
            flash_set('ok', '密码修改成功。');
            redirect('profile.php');
        }
    }

    if ($action === 'contact') {
        $email = trim((string)($_POST['email'] ?? ''));
        $bindCode = trim((string)($_POST['email_bind_code'] ?? ''));

        $st = db()->prepare('SELECT `email` FROM `agent` WHERE `id`=?');
        $st->execute([$aid]);
        $curEmail = (string)$st->fetchColumn();
        $emailChanged = strcasecmp($email, $curEmail) !== 0;

        if ($email === '' && $curEmail !== '') {
            $error = '已绑定的邮箱不可清空，如需更换请填写新邮箱并完成验证。';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '邮箱格式不正确。';
        } elseif ($emailChanged) {
            if (!mail_code_available()) {
                $error = '邮件功能未开启，无法绑定或更换邮箱。';
            } else {
                $stmt = db()->prepare('SELECT `id` FROM `agent` WHERE `email`=? AND `id`<>?');
                $stmt->execute([$email, $aid]);
                if ($stmt->fetch()) {
                    $error = '该邮箱已被其他代理商使用。';
                } elseif (!mail_code_verify('agent_bind', $email, $bindCode)) {
                    $error = '邮箱验证码不正确或已过期，请重新获取。';
                }
            }
        }
        if ($error === '') {
            if (!$emailChanged) {
                $error = '邮箱未变化，如需换绑请先填写新邮箱。';
            } else {
                db()->prepare('UPDATE `agent` SET `email`=? WHERE `id`=?')->execute([$email, $aid]);
                audit('agent', 'bind_email', 2, $aid, $curEmail === '' ? '绑定邮箱：' . $email : '更换邮箱：' . $curEmail . ' → ' . $email);
                flash_set('ok', $curEmail === '' ? '邮箱绑定成功。' : '邮箱已更换，请使用新邮箱接收通知与找回密码。');
                redirect('profile.php');
            }
        }
    }

    if ($action === 'invite_reset') {
        for ($i = 0; $i < 5; $i++) {
            $code = invite_code_gen();
            $chk  = db()->prepare('SELECT `id` FROM `agent` WHERE `invite_code`=? AND `id`<>?');
            $chk->execute([$code, $aid]);
            if (!$chk->fetch()) {
                db()->prepare('UPDATE `agent` SET `invite_code`=? WHERE `id`=?')->execute([$code, $aid]);
                audit('agent', 'invite_code_reset', 2, $aid);
                flash_set('ok', '邀请码已重置，原邀请码立即失效。');
                break;
            }
        }
        redirect('profile.php');
    }
}

$stmt = db()->prepare('SELECT `username`,`email`,`balance`,`discount`,`gift_balance`,`level_id`,`invite_code`,`status`,`created_at`,`reg_ip`,`last_login_time`,`last_login_ip` FROM `agent` WHERE `id`=?');
$stmt->execute([$aid]);
$info = $stmt->fetch();

$inviteCode = invite_code_ensure($aid);
$mailOn = mail_code_available();
$needCode = $mailOn && (string)$info['email'] !== '';
// 实际生效折扣：有等级按等级折扣，无等级按独立折扣
$effDiscount = agent_effective_discount($info);

// 邀请返利统计：名下用户数 / 累计返利 / 返利比例配置
$inviteStats = ['users' => 0, 'rebate' => 0.0, 'on' => false, 'rate' => '0'];
try {
    $stmt = db()->prepare('SELECT COUNT(*) FROM `user` WHERE `agent_id`=?');
    $stmt->execute([$aid]);
    $inviteStats['users'] = (int)$stmt->fetchColumn();
    $stmt = db()->prepare("SELECT COALESCE(SUM(`amount`),0) FROM `finance_flow` WHERE `user_type`=2 AND `user_id`=? AND `type`=5");
    $stmt->execute([$aid]);
    $inviteStats['rebate'] = (float)$stmt->fetchColumn();
    $inviteStats['on'] = cfg('invite_rebate_enabled', '0') === '1';
    $inviteStats['rate'] = rtrim(rtrim(number_format((float)cfg('invite_rebate_rate', '0'), 2), '0'), '.');
} catch (Throwable $ex) {
}

$curLevel = null;
if ((int)($info['level_id'] ?? 0) > 0) {
    try {
        $stmt = db()->prepare('SELECT `name`,`discount`,`benefits` FROM `agent_level` WHERE `id`=? LIMIT 1');
        $stmt->execute([(int)$info['level_id']]);
        $curLevel = $stmt->fetch() ?: null;
    } catch (Throwable $ex) {
        $curLevel = null;
    }
}

agent_header('个人设置', 'profile', $agent);
$flash = flash_take();
?>

<style>

.profile-banner-card{padding:0 0 20px;overflow:hidden}
.profile-banner{position:relative}
.profile-banner-img{display:block;width:100%;height:190px;object-fit:cover}
.profile-status{position:absolute;top:14px;right:16px;display:flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;background:rgba(255,255,255,.92);font-size:12px;color:var(--t1)}
.profile-status i{width:8px;height:8px;border-radius:50%;background:var(--ok)}
.profile-status-off i{background:var(--err)}
.profile-head{position:relative;z-index:1;display:flex;align-items:flex-end;gap:16px;padding:0 24px;margin-top:-42px}
.profile-head-avatar{width:96px;height:96px;border-radius:50%;border:4px solid #FFF;background:#FFF;object-fit:cover;flex-shrink:0;box-sizing:border-box}
.profile-head-info{flex:1;min-width:0;padding-bottom:4px}
.profile-head-name{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:600;color:var(--t1);line-height:26px;flex-wrap:wrap}
.profile-role-tag{position:relative;overflow:hidden;display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:500;color:var(--p);background:var(--p-bg);border:1px solid rgba(0,110,255,.22);border-radius:999px;padding:2px 12px;line-height:20px;flex-shrink:0;isolation:isolate}
.profile-role-tag img{width:12px;height:12px;flex:none}
.profile-role-tag::after{content:'';position:absolute;top:-30%;bottom:-30%;left:-90%;width:55%;background:linear-gradient(105deg,transparent,rgba(255,255,255,.85),transparent);transform:skewX(-22deg);animation:roleShine 3s ease-in-out infinite;pointer-events:none}
@keyframes roleShine{0%{left:-90%}40%{left:150%}100%{left:150%}}
.profile-disc{color:var(--p);font-weight:600}
.profile-head-sub{font-size:13px;color:var(--t3);margin-top:4px;line-height:20px}
.profile-head-sep{margin:0 8px;color:var(--bd)}
.profile-email-empty{color:var(--warn)}
.profile-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;margin-top:16px;align-items:stretch}
.profile-grid>.card{margin-bottom:0;display:flex;flex-direction:column}
.profile-form{flex:1;display:flex;flex-direction:column}
.profile-form-actions{margin-top:auto;padding-top:12px;display:flex;justify-content:flex-end}
.card-head{display:flex;align-items:center;gap:12px;margin-bottom:20px}
.card-head-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.card-head-icon img{width:18px;height:18px}
.icon-blue{background:var(--p-bg)}
.icon-orange{background:var(--warn-bg)}
.card-head-text{flex:1;min-width:0}
.card-head-title{font-size:15px;font-weight:600;color:var(--t1);line-height:22px}
.card-head-desc{font-size:12px;color:var(--t3);line-height:18px;margin-top:2px}
.card-head-tag{flex-shrink:0;font-size:12px;line-height:22px;padding:0 10px;border-radius:var(--r);background:var(--bg);color:var(--t2);border:1px solid var(--bd)}
.card-head-tag.tag-green{background:var(--ok-bg);color:var(--ok);border-color:transparent}
.card-head-tag.tag-orange{background:var(--warn-bg);color:var(--warn);border-color:transparent}
.profile-fields{display:grid;grid-template-columns:1fr 1fr;column-gap:16px}
.profile-span2{grid-column:1/-1}
.req{color:var(--err);font-style:normal;margin-right:2px}
.profile-code-row{display:flex;gap:8px;align-items:center}
.profile-code-row .form-input{flex:1;min-width:0}
.grid-2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;margin-top:16px;align-items:stretch}
.grid-2>.card{margin-bottom:0}
.kv-table{width:100%;border-collapse:collapse;font-size:13px}
.kv-table th{width:110px;text-align:left;padding:11px 12px;color:var(--t3);font-weight:400;vertical-align:top;border-bottom:1px solid var(--bd);white-space:nowrap}
.kv-table td{padding:11px 12px;color:var(--t1);border-bottom:1px solid var(--bd)}
.kv-table tr:last-child th,.kv-table tr:last-child td{border-bottom:none}
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
.bmod{width:420px}
.toast{position:fixed;top:24px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;align-items:center;gap:8px;background:#FFF;border:1px solid var(--bd);border-left:3px solid var(--err);border-radius:var(--r);box-shadow:0 8px 24px rgba(31,35,41,.12);padding:10px 16px;font-size:14px;color:var(--t1);opacity:0;transition:opacity .2s;pointer-events:none}
.toast img{width:16px;height:16px;flex:none}
.invite-box{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:14px 16px}
.invite-code{font-family:Consolas,Monaco,monospace;font-size:20px;font-weight:700;letter-spacing:2px;color:var(--p)}
@media (max-width:768px){
.profile-grid,.grid-2{grid-template-columns:1fr}
.profile-banner-img{height:130px}
.profile-head{padding:0 16px;margin-top:-34px}
.profile-head-avatar{width:76px;height:76px}
.profile-fields{grid-template-columns:1fr}
}
</style>

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

<section class="card profile-banner-card">
    <div class="profile-banner">
        <img class="profile-banner-img" src="../assets/img/grxxbj.png" alt="">
        <?php if ((int)$info['status'] === 1): ?>
        <span class="profile-status"><i></i>账号状态正常</span>
        <?php else: ?>
        <span class="profile-status profile-status-off"><i></i>账号已禁用</span>
        <?php endif; ?>
    </div>
    <div class="profile-head">
        <?php echo avatar_img($info, 'profile-head-avatar', '头像'); ?>
        <div class="profile-head-info">
            <div class="profile-head-name"><?php echo e((string)$info['username']); ?><span class="profile-role-tag"><?php echo $curLevel ? e((string)$curLevel['name']) : '代理商'; ?></span></div>
            <div class="profile-head-sub">
                开卡折扣：<span class="profile-disc"><?php echo e(rtrim(rtrim(number_format($effDiscount * 10, 1, '.', ''), '0'), '.')); ?> 折</span>
                <span class="profile-head-sep">|</span>
                最近登录：<?php echo (int)$info['last_login_time'] > 0 ? date('Y-m-d H:i', (int)$info['last_login_time']) : '未登录'; ?>
                <span class="profile-head-sep">|</span>
                <?php
                $loginIp = (string)($info['last_login_ip'] ?? '');
                $regIp = (string)($info['reg_ip'] ?? '');
                $showIp = $loginIp !== '' ? $loginIp : $regIp;
                ?>
                <?php echo $loginIp !== '' ? '当前 IP' : ($regIp !== '' ? '注册 IP' : '当前 IP'); ?>：<?php echo $showIp !== '' ? e($showIp) : '-'; ?>
                <?php if ($showIp !== ''): ?>
                <span class="profile-head-sep">|</span>
                归属地：<?php echo e(ip_geo_text($showIp) ?: '-'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div class="profile-grid">
    <section class="card">
        <div class="card-head">
            <span class="card-head-icon icon-blue"><img src="../assets/svg/user.svg" alt=""></span>
            <div class="card-head-text">
                <h2 class="card-head-title">基本信息</h2>
                <p class="card-head-desc">管理代理商账号通知邮箱</p>
            </div>
            <span class="card-head-tag">UID: #<?php echo $aid; ?></span>
        </div>
        <form method="post" class="profile-form" id="contactForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="contact">
            <div class="profile-fields">
                <div class="form-item">
                    <label class="form-label">登录账号</label>
                    <input class="form-input" type="text" value="<?php echo e((string)$info['username']); ?>" disabled>
                    <div class="form-hint">账号不支持自助修改，如需变更请联系管理员</div>
                </div>
                <div class="form-item">
                    <label class="form-label">用户身份</label>
                    <input class="form-input" type="text" value="<?php echo $curLevel ? '代理商 · ' . e((string)$curLevel['name']) : '代理商（默认等级）'; ?>" disabled>
                </div>
                <?php $boundEmail = (string)$info['email'] !== ''; ?>
                <div class="form-item profile-span2">
                    <label class="form-label">绑定邮箱</label>
                    <?php if ($boundEmail): ?>
                    <div class="profile-code-row">
                        <input class="form-input" type="text" value="<?php echo e((string)$info['email']); ?>" disabled>
                        <?php if ($mailOn): ?><button class="btn-plain" type="button" id="btn-change-email">更换邮箱</button><?php endif; ?>
                    </div>
                    <input type="hidden" name="email" value="<?php echo e((string)$info['email']); ?>">
                    <input type="hidden" name="email_bind_code" value="">
                    <div class="form-hint"><?php echo $mailOn ? '用于接收通知邮件与找回密码；如需更换，点击右侧「更换邮箱」，验证新邮箱后完成换绑' : '用于接收通知邮件与找回密码'; ?></div>
                    <?php else: ?>
                    <input class="form-input" type="email" name="email" maxlength="128" value="" placeholder="请输入邮箱"<?php echo $mailOn ? '' : ' disabled'; ?>>
                    <div class="form-hint"><?php echo $mailOn ? '用于接收通知邮件与找回密码；首次绑定需验证新邮箱' : '邮件功能未开启，暂无法绑定邮箱'; ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($mailOn && !$boundEmail): ?>
                <div class="form-item profile-span2">
                    <label class="form-label">邮箱验证码</label>
                    <div class="profile-code-row">
                        <input class="form-input" type="text" name="email_bind_code" maxlength="6" inputmode="numeric" placeholder="6 位数字验证码">
                        <button class="btn-plain" type="button" id="btn-bindcode">发送验证码</button>
                    </div>
                    <div class="form-hint" id="bind-hint">发送至上方填写的新邮箱，10 分钟内有效，间隔 60 秒</div>
                </div>
                <?php endif; ?>
            </div>
            <div class="profile-form-actions">
                <button class="btn-p" type="submit">修改资料</button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <span class="card-head-icon icon-orange"><img src="../assets/svg/menu-lock.svg" alt=""></span>
            <div class="card-head-text">
                <h2 class="card-head-title">安全设置</h2>
                <p class="card-head-desc">定期更改密码提升账号安全性</p>
            </div>
            <?php if ($needCode): ?>
            <span class="card-head-tag tag-green">密码受保护</span>
            <?php else: ?>
            <span class="card-head-tag tag-orange">未受保护</span>
            <?php endif; ?>
        </div>
        <?php if (!$needCode): ?>
        <div class="alert alert-error">
            <img src="../assets/svg/warn-circle.svg" alt="">
            <span><?php echo (string)$info['email'] === '' ? '尚未绑定邮箱：请先在左侧「基本信息」中填写新邮箱，通过邮箱验证码完成绑定后，修改密码需邮箱验证码校验。' : '邮件功能未开启（后台 → 系统配置 → 邮件通知），开启后才能绑定邮箱；修改密码需邮箱验证码校验。'; ?></span>
        </div>
        <?php endif; ?>
        <form method="post" class="profile-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="password">
            <div class="profile-fields">
                <div class="form-item profile-span2">
                    <label class="form-label"><em>*</em>当前密码</label>
                    <input class="form-input" type="password" name="old_password" maxlength="64" autocomplete="current-password" placeholder="请输入当前正在使用的密码" required>
                </div>
                <div class="form-item">
                    <label class="form-label"><em>*</em>新密码</label>
                    <input class="form-input" type="password" name="new_password" minlength="6" maxlength="64" placeholder="请输入新密码（至少6位）" autocomplete="new-password" required>
                </div>
                <div class="form-item">
                    <label class="form-label"><em>*</em>确认新密码</label>
                    <input class="form-input" type="password" name="new_password2" maxlength="64" placeholder="请再次输入新密码" autocomplete="new-password" required>
                </div>
                <?php if ($needCode): ?>
                <div class="form-item profile-span2">
                    <label class="form-label"><em>*</em>邮箱验证码</label>
                    <div class="profile-code-row">
                        <input class="form-input" type="text" name="email_code" maxlength="6" inputmode="numeric" placeholder="6 位数字验证码" required>
                        <button class="btn-plain" type="button" id="btn-sendcode">发送验证码</button>
                    </div>
                    <div class="form-hint" id="code-hint">发送至 <?php echo e((string)$info['email']); ?>，10 分钟内有效，间隔 60 秒</div>
                </div>
                <?php endif; ?>
            </div>
            <div class="profile-form-actions">
                <button class="btn-p" type="submit">修改密码</button>
            </div>
        </form>
    </section>
</div>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <span class="card-head-icon icon-blue"><img src="../assets/svg/menu-key.svg" alt=""></span>
            <div class="card-head-text">
                <h2 class="card-head-title">邀请码绑定</h2>
                <p class="card-head-desc">用户注册时填写邀请码即自动绑定为您名下用户</p>
            </div>
        </div>
        <div class="invite-box">
            <span class="invite-code" id="inviteCode"><?php echo e($inviteCode !== '' ? $inviteCode : '生成失败，请刷新重试'); ?></span>
            <button type="button" class="btn-plain" id="inviteCopyBtn" data-copy="<?php echo e($inviteCode); ?>">复制</button>
            <form method="post" style="display:inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="invite_reset">
                <button class="btn-plain" type="submit" data-confirm="确认重置邀请码吗？原邀请码将立即失效，已绑定用户不受影响。">重置邀请码</button>
            </form>
        </div>
        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:14px">
            <div>
                <div style="font-size:20px;font-weight:600;color:var(--t1);font-family:Consolas,Monaco,monospace"><?php echo (int)$inviteStats['users']; ?></div>
                <div class="form-hint" style="margin-top:2px">名下用户数</div>
            </div>
            <div>
                <div style="font-size:20px;font-weight:600;color:var(--ok);font-family:Consolas,Monaco,monospace">¥<?php echo e(number_format((float)$inviteStats['rebate'], 2)); ?></div>
                <div class="form-hint" style="margin-top:2px">累计邀请返利</div>
            </div>
        </div>
        <p class="card-desc" style="margin:12px 0 0">将邀请码发给您的用户即可完成绑定；名下用户只能通过邀请码绑定，无法手动添加。</p>
        <?php if ($inviteStats['on'] && (float)$inviteStats['rate'] > 0): ?>
        <div class="alert alert-ok" style="margin:12px 0 0">
            <img src="../assets/svg/check-circle.svg" alt="">
            <span>邀请返利已开启：名下用户每笔支付成功，将按实付金额的 <b><?php echo e($inviteStats['rate']); ?>%</b> 自动返入您的账户余额，明细见「我的财务 → 邀请返利」。</span>
        </div>
        <?php else: ?>
        <p class="card-desc" style="margin:8px 0 0">当前站点未开启邀请返利，绑定用户消费不产生返利收益。</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <span class="card-head-icon icon-blue"><img src="../assets/svg/menu-agent.svg" alt=""></span>
            <div class="card-head-text">
                <h2 class="card-head-title">账号与等级信息</h2>
                <p class="card-head-desc">当前等级权益与账户概况</p>
            </div>
            <a class="btn-p" href="level.php" style="text-decoration:none">前往等级升级</a>
        </div>
        <div class="table-wrap">
            <table class="kv-table">
                <tbody>
                <tr><th>当前等级</th><td><?php echo $curLevel ? e((string)$curLevel['name']) : '默认等级（未开通等级）'; ?></td></tr>
                <tr><th>开卡折扣</th><td><?php echo e(rtrim(rtrim(number_format($effDiscount * 10, 1, '.', ''), '0'), '.')); ?> 折</td></tr>
                <tr><th>等级权益</th><td><?php echo $curLevel && (string)$curLevel['benefits'] !== '' ? e((string)$curLevel['benefits']) : '—'; ?></td></tr>
                <tr><th>累计赠送余额</th><td>¥<?php echo e(number_format((float)($info['gift_balance'] ?? 0), 2)); ?></td></tr>
                <tr><th>开通时间</th><td><?php echo date('Y-m-d H:i', (int)$info['created_at']); ?></td></tr>
                <tr><th>最后登录</th><td><?php echo (int)$info['last_login_time'] > 0 ? e(date('Y-m-d H:i', (int)$info['last_login_time'])) . '（' . e((string)$info['last_login_ip']) . '）' . ((string)$info['last_login_ip'] !== '' ? ' · ' . e(ip_geo_text((string)$info['last_login_ip']) ?: '未知') : '') : '从未登录'; ?></td></tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>

(function () {
    var btn = document.getElementById('inviteCopyBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy') || '';
        var done = function () {
            var old = btn.textContent;
            btn.textContent = '已复制';
            setTimeout(function () { btn.textContent = old; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () { fallback(); });
        } else {
            fallback();
        }
        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch (e) {  }
            document.body.removeChild(ta);
        }
    });
})();
</script>
<?php if ($mailOn && (string)$info['email'] !== ''): ?>
<div class="veil" id="bind-veil">
    <div class="vcap bmod">
        <div class="vcap-title">更换绑定邮箱</div>
        <div class="vcap-err" id="bm-err" style="display:none"><img src="../assets/svg/warn-circle.svg" alt=""><span></span></div>
        <div class="form-item">
            <label class="form-label">新邮箱</label>
            <input class="form-input" type="email" id="bm-email" maxlength="128" placeholder="请输入新邮箱">
        </div>
        <div class="form-item">
            <label class="form-label">邮箱验证码</label>
            <div class="profile-code-row">
                <input class="form-input" type="text" id="bm-code" maxlength="6" inputmode="numeric" placeholder="6 位数字验证码">
                <button class="btn-plain" type="button" id="bm-send">发送验证码</button>
            </div>
            <div class="form-hint" id="bm-hint">发送至上方填写的新邮箱，10 分钟内有效，间隔 60 秒</div>
        </div>
        <div class="vcap-actions">
            <button class="btn-plain" type="button" id="bm-cancel">取消</button>
            <button class="btn-pri" type="button" id="bm-ok">确认更换</button>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if ($mailOn && $capMode !== 'geetest'): ?>
<div class="veil" id="captcha-veil">
    <div class="vcap">
        <div class="vcap-title">安全验证</div>
        <div class="vcap-err" id="vcap-err"><img src="../assets/svg/warn-circle.svg" alt=""><span></span></div>
        <?php if ($capMode === 'turnstile'): ?>
        <div class="form-item">
            <label class="form-label">人机验证</label>
            <?php echo turnstile_widget_html(); ?>
        </div>
        <?php else: ?>
        <div class="form-item">
            <label class="form-label">图形验证码</label>
            <div class="profile-code-row">
                <input class="form-input" type="text" id="vcap-input" maxlength="4" placeholder="请输入图中数字">
                <img class="vcap-img" id="vcap-img" src="captcha.php?v=<?php echo SYS_VERSION; ?>" alt="验证码" title="点击刷新">
            </div>
        </div>
        <?php endif; ?>
        <div class="vcap-actions">
            <button class="btn-plain" type="button" id="vcap-cancel">取消</button>
            <button class="btn-pri" type="button" id="vcap-ok">确认</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($mailOn): ?>
<script>
(function () {
    var csrf = (document.querySelector('input[name=csrf]') || {}).value || '';
    var veil = document.getElementById('captcha-veil');
    var current = null;

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

    function mkFlow(btnId, hintId, waitInit) {
        var btn = document.getElementById(btnId);
        if (!btn) return null;
        var timer = null;
        var f = {
            btn: btn,
            hint: document.getElementById(hintId),
            countdown: function (left) {
                if (timer) { clearInterval(timer); timer = null; }
                btn.disabled = true;
                timer = setInterval(function () {
                    if (left <= 0) {
                        clearInterval(timer);
                        timer = null;
                        btn.disabled = false;
                        btn.textContent = '发送验证码';
                        return;
                    }
                    btn.textContent = left + ' 秒后重发';
                    left--;
                }, 1000);
            }
        };
        if (waitInit > 0) f.countdown(waitInit);
        return f;
    }

    var pwFlow = mkFlow('btn-sendcode', 'code-hint', <?php echo (int)($_SESSION['agent_profile_code_wait'] ?? 0); ?>);
    var bindFlow = mkFlow(document.getElementById('bm-send') ? 'bm-send' : 'btn-bindcode',
        document.getElementById('bm-hint') ? 'bm-hint' : 'bind-hint',
        <?php echo (int)($_SESSION['agent_profile_bind_wait'] ?? 0); ?>);

    function buildForm() {
        var fd = new FormData();
        fd.append('action', current === bindFlow ? 'bindcode' : 'sendcode');
        fd.append('csrf', csrf);
        if (current === bindFlow) {
            var bm = document.getElementById('bm-email');
            var em = bm || document.querySelector('input[name=email]');
            fd.append('email', em ? em.value.trim() : '');
        }
        return fd;
    }

    function send(fd, failCb) {
        var f = current;
        f.btn.disabled = true;
        fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.ok) {
                    if (veil) veil.classList.remove('on');
                    if (f.hint) f.hint.textContent = '验证码已发送至 ' + (j.masked || '') + '，10 分钟内有效';
                    toast('验证码已发送，请查收邮件');
                    f.countdown(60);
                } else {
                    f.btn.disabled = false;
                    failCb ? failCb(j.msg) : toast(j.msg);
                }
            })
            .catch(function () {
                f.btn.disabled = false;
                failCb ? failCb('网络异常，请稍后重试') : toast('网络异常，请稍后重试');
            });
    }

    function begin(f) {
        current = f;
        <?php if ($capMode === 'geetest'): ?>
        if (!gtReady || !gtObj) {
            gtLoad();
            gtPending = true;
            toast('验证组件加载中，请稍候…');
            return;
        }
        gtObj.showCaptcha();
        <?php else: ?>
        if (!veil) return;
        vcapErr.style.display = 'none';
        veil.classList.add('on');
        <?php if ($capMode === 'turnstile'): ?>
        if (window.turnstile) { turnstile.reset(); }
        <?php else: ?>
        vcapInput.value = '';
        vcapRefresh();
        setTimeout(function () { vcapInput.focus(); }, 50);
        <?php endif; ?>
        <?php endif; ?>
    }

    var bmErr = document.getElementById('bm-err');
    function bmFail(msg) {
        if (!bmErr) { toast(msg); return; }
        bmErr.style.display = 'flex';
        bmErr.querySelector('span').textContent = msg;
    }
    function bindEmailOk() {
        var bm = document.getElementById('bm-email');
        var em = bm || document.querySelector('input[name=email]');
        var v = em ? em.value.trim() : '';
        return v !== '' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    }

    <?php if ($capMode === 'geetest'): ?>
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
                if (!r || !current) return;
                var fd = buildForm();
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
    <?php else: ?>
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
    document.getElementById('vcap-cancel').addEventListener('click', function () { veil.classList.remove('on'); });
    veil.addEventListener('click', function (e) { if (e.target === veil) veil.classList.remove('on'); });
    document.getElementById('vcap-ok').addEventListener('click', function () {
        var fd = buildForm();
        <?php if ($capMode === 'turnstile'): ?>
        var token = '';
        var tEl = veil.querySelector('[name=cf-turnstile-response]');
        if (tEl) token = tEl.value;
        if (!token) { vcapFail('请先完成 Turnstile 人机验证'); return; }
        fd.append('cf-turnstile-response', token);
        if (window.turnstile) { turnstile.reset(); }
        <?php else: ?>
        var v = vcapInput.value.trim();
        if (v === '') { vcapFail('请输入图中数字'); return; }
        fd.append('captcha', v);
        <?php endif; ?>
        send(fd, vcapFail);
    });
    vcapInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') document.getElementById('vcap-ok').click(); });
    <?php endif; ?>

    if (pwFlow) pwFlow.btn.addEventListener('click', function () {
        if (pwFlow.btn.disabled) return;
        begin(pwFlow);
    });
    if (bindFlow) bindFlow.btn.addEventListener('click', function () {
        if (bindFlow.btn.disabled) return;
        if (!bindEmailOk()) { bmFail('请输入正确的新邮箱'); return; }
        begin(bindFlow);
    });

    var bindVeil = document.getElementById('bind-veil');
    if (bindVeil) {
        var bmEmail = document.getElementById('bm-email');
        var bmCode = document.getElementById('bm-code');
        var bmOkBtn = document.getElementById('bm-ok');
        var bmForm = document.getElementById('contactForm');
        var bmCurEmail = <?php echo json_encode((string)$info['email']); ?>;
        var bmReset = function () {
            if (bmErr) bmErr.style.display = 'none';
            bmCode.value = '';
        };
        var bmClose = function () {
            bindVeil.classList.remove('on');
            var he = bmForm.querySelector('input[name=email]');
            var hc = bmForm.querySelector('input[name=email_bind_code]');
            if (he) he.value = bmCurEmail;
            if (hc) hc.value = '';
        };
        document.getElementById('btn-change-email').addEventListener('click', function () {
            bmReset();
            bmEmail.value = '';
            bindVeil.classList.add('on');
            setTimeout(function () { bmEmail.focus(); }, 50);
        });
        document.getElementById('bm-cancel').addEventListener('click', bmClose);
        bindVeil.addEventListener('click', function (e) { if (e.target === bindVeil) bmClose(); });
        bmCode.addEventListener('keydown', function (e) { if (e.key === 'Enter') bmOkBtn.click(); });
        bmEmail.addEventListener('focusin', function () { <?php if ($capMode === 'geetest'): ?>gtLoad();<?php endif; ?> });
        bmOkBtn.addEventListener('click', function () {
            var em = bmEmail.value.trim();
            var cd = bmCode.value.trim();
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) { bmFail('请输入正确的新邮箱'); return; }
            if (!/^\d{4,8}$/.test(cd)) { bmFail('请输入收到的邮箱验证码'); return; }
            var he = bmForm.querySelector('input[name=email]');
            var hc = bmForm.querySelector('input[name=email_bind_code]');
            if (he) he.value = em;
            if (hc) hc.value = cd;
            bmForm.submit();
        });
    }
    var codeInput = document.querySelector('input[name=email_code]');
    if (codeInput) codeInput.addEventListener('focusin', function () { <?php if ($capMode === 'geetest'): ?>gtLoad();<?php endif; ?> });
})();
</script>
<?php endif; ?>
<?php if ($capMode === 'turnstile'): ?>
<?php echo turnstile_script_html(); ?>
<?php endif; ?>

<?php agent_footer(); ?>
