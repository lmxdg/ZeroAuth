<?php
// 用户中心 - 个人资料
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_user.php';
require dirname(__DIR__) . '/core/Security.php';
require_once ROOT_PATH . '/core/Captcha.php';
require dirname(__DIR__) . '/core/Mail.php';

$user = user_require_login();
$uid  = (int)$user['id'];
$error = '';

$capMode = captcha_mode();

if (is_post() && (string)($_POST['action'] ?? '') === 'sendcode') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = static function (bool $ok, string $msg, array $extra = []): never {
        echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (sec_rate_hit('user_profile_send', client_ip(), 10) > 10) {
        $reply(false, '操作过于频繁，请 1 分钟后再试。');
    }
    if (!isset($_POST['csrf']) || !hash_equals(csrf_token(), (string)$_POST['csrf'])) {
        $reply(false, '页面已过期，请刷新后重试。');
    }

    $st = db()->prepare('SELECT `email`,`username` FROM `user` WHERE `id`=?');
    $st->execute([$uid]);
    $accRow = $st->fetch();
    $email  = (string)($accRow['email'] ?? '');
    if (!mail_code_available() || $email === '') {
        $reply(false, '邮件功能未开启或账号未绑定邮箱，无法发送验证码。');
    }

    [$vok, $vmsg] = captcha_verify_scene('profile', $_POST);
    if (!$vok) {
        $reply(false, $vmsg);
    }

    $res = mail_code_send('user_forgot', $email, ['userName' => (string)($accRow['username'] ?? '')]);
    if (!$res['ok']) {
        $reply(false, $res['msg']);
    }
    $_SESSION['user_profile_code_wait'] = 60;
    $masked = preg_replace('/^(.).*?(@.*)$/', '$1****$2', $email);
    $reply(true, '验证码已发送，请查收邮件（10 分钟内有效）。', ['masked' => $masked]);
}

if (is_post() && (string)($_POST['action'] ?? '') === 'bindcode') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = static function (bool $ok, string $msg, array $extra = []): never {
        echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (sec_rate_hit('user_profile_bind', client_ip(), 10) > 10) {
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

    $st = db()->prepare('SELECT `email`,`username` FROM `user` WHERE `id`=?');
    $st->execute([$uid]);
    $accRow  = $st->fetch();
    $curMail = (string)($accRow['email'] ?? '');
    if ($curMail !== '' && strcasecmp($curMail, $newEmail) === 0) {
        $reply(false, '该邮箱已是当前绑定邮箱，请填写其他邮箱。');
    }
    $st = db()->prepare('SELECT `id` FROM `user` WHERE `email`=? AND `id`<>?');
    $st->execute([$newEmail, $uid]);
    if ($st->fetch()) {
        $reply(false, '该邮箱已被其他账号使用。');
    }

    [$vok, $vmsg] = captcha_verify_scene('profile', $_POST);
    if (!$vok) {
        $reply(false, $vmsg);
    }

    $res = mail_code_send('user_bind', $newEmail, ['userName' => (string)($accRow['username'] ?? '')]);
    if (!$res['ok']) {
        $reply(false, $res['msg']);
    }
    $_SESSION['user_profile_bind_wait'] = 60;
    $masked = preg_replace('/^(.).*?(@.*)$/', '$1****$2', $newEmail);
    $reply(true, '验证码已发送，请查收邮件（10 分钟内有效）。', ['masked' => $masked]);
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'password') {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['new_password2'] ?? '');
        $code = trim((string)($_POST['email_code'] ?? ''));

        $stmt = db()->prepare('SELECT `password`,`email` FROM `user` WHERE `id`=?');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        $needCode = mail_code_available() && (string)$row['email'] !== '';

        if (!password_verify($old, (string)$row['password'])) {
            $error = '当前密码不正确。';
        } elseif (strlen($new) < 6 || strlen($new) > 64) {
            $error = '新密码长度需在 6 ~ 64 位之间。';
        } elseif ($new !== $new2) {
            $error = '两次输入的新密码不一致。';
        } elseif ($needCode && !mail_code_verify('user_forgot', (string)$row['email'], $code)) {
            $error = '邮箱验证码不正确或已过期，请重新获取。';
        } else {
            db()->prepare('UPDATE `user` SET `password`=? WHERE `id`=?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            audit('user', 'change_password', 3, $uid);
            flash_set('ok', '密码修改成功。');
            redirect('profile.php');
        }
    }

    if ($action === 'realname') {
        if (!plugin_enabled('builtin')) {
            $error = '实名认证功能未开放。';
        } else {
        $st = db()->prepare('SELECT `real_status` FROM `user` WHERE `id`=?');
        $st->execute([$uid]);
        $curStatus = (int)$st->fetchColumn();
        if ($curStatus === 1 || $curStatus === 2) {
            $error = '当前实名状态不允许重复提交。';
        } else {
            $realName = trim((string)($_POST['real_name'] ?? ''));
            $idCard   = strtoupper(trim((string)($_POST['id_card'] ?? '')));
            if ($realName === '' || mb_strlen($realName) < 2 || mb_strlen($realName) > 32) {
                $error = '请输入 2 ~ 32 位真实姓名。';
            } elseif (!sec_check_idcard($idCard)) {
                $error = '身份证号格式不正确。';
            } else {
                $enc = sec_id_encrypt($idCard);
                if ($enc === '') {
                    $error = '加密失败，请稍后重试。';
                } else {
                    db()->prepare('UPDATE `user` SET `real_status`=1,`real_name`=?,`id_card`=?,`real_reason`=\'\' WHERE `id`=?')
                        ->execute([$realName, $enc, $uid]);
                    audit('user', 'realname_submit', 3, $uid, '提交实名认证：' . $realName);
                    require_once ROOT_PATH . '/core/Mail.php';
                    mail_scene_send('system', '', [
                        'title'   => '实名认证待审核',
                        'content' => '用户 ' . $user['username'] . '（' . $realName . '，' . sec_mask_idcard($idCard) . '）提交了实名认证，请到后台「用户管理」审核。',
                        'time'    => date('Y-m-d H:i:s'),
                    ]);
                    flash_set('ok', '实名信息已提交，等待管理员审核。');
                    redirect('profile.php');
                }
            }
        }
        }
    }

    if ($action === 'contact') {
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $bindCode = trim((string)($_POST['email_bind_code'] ?? ''));

        $st = db()->prepare('SELECT `email` FROM `user` WHERE `id`=?');
        $st->execute([$uid]);
        $curEmail = (string)$st->fetchColumn();
        $emailChanged = strcasecmp($email, $curEmail) !== 0;

        if ($email === '' && $curEmail !== '') {
            $error = '已绑定的邮箱不可清空，如需更换请填写新邮箱并完成验证。';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '邮箱格式不正确。';
        } elseif ($phone !== '' && !preg_match('/^1[3-9]\d{9}$/', $phone)) {
            $error = '手机号格式不正确。';
        } elseif ($emailChanged) {
            if (!mail_code_available()) {
                $error = '邮件功能未开启，无法绑定或更换邮箱。';
            } else {
                $stmt = db()->prepare('SELECT `id` FROM `user` WHERE `email`=? AND `id`<>?');
                $stmt->execute([$email, $uid]);
                if ($stmt->fetch()) {
                    $error = '该邮箱已被其他账号使用。';
                } elseif (!mail_code_verify('user_bind', $email, $bindCode)) {
                    $error = '邮箱验证码不正确或已过期，请重新获取。';
                }
            }
        }
        if ($error === '') {
            if ($emailChanged) {
                db()->prepare('UPDATE `user` SET `email`=?,`phone`=? WHERE `id`=?')->execute([$email, $phone, $uid]);
                audit('user', 'bind_email', 3, $uid, $curEmail === '' ? '绑定邮箱：' . $email : '更换邮箱：' . $curEmail . ' → ' . $email);
                flash_set('ok', $curEmail === '' ? '邮箱绑定成功。' : '邮箱已更换，请使用新邮箱接收通知与找回密码。');
            } else {
                db()->prepare('UPDATE `user` SET `phone`=? WHERE `id`=?')->execute([$phone, $uid]);
                audit('user', 'update_contact', 3, $uid);
                flash_set('ok', '联系方式已更新。');
            }
            redirect('profile.php');
        }
    }
}

$stmt = db()->prepare('SELECT `username`,`email`,`phone`,`balance`,`real_status`,`real_name`,`real_reason`,`id_card`,`status`,`created_at`,`reg_ip`,`last_login_ip`,`last_login_time` FROM `user` WHERE `id`=?');
$stmt->execute([$uid]);
$info = $stmt->fetch();

$realMap = [0 => ['未认证', 'tag-gray'], 1 => ['待审核', 'tag-warn'], 2 => ['已通过', 'tag-ok'], 3 => ['已驳回', 'tag-err']];
[$realName, $realTag] = $realMap[(int)$info['real_status']] ?? ['未认证', 'tag-gray'];
$mailOn = mail_code_available();
$needCode = $mailOn && (string)$info['email'] !== '';

$parentAgent = null;
if ((int)($user['agent_id'] ?? 0) > 0) {
    $stmt = db()->prepare('SELECT `id`,`username`,`level_id` FROM `agent` WHERE `id`=? LIMIT 1');
    $stmt->execute([(int)$user['agent_id']]);
    $parentAgent = $stmt->fetch() ?: null;
    if ($parentAgent && (int)$parentAgent['level_id'] > 0) {
        $stmt = db()->prepare('SELECT `name` FROM `agent_level` WHERE `id`=? LIMIT 1');
        $stmt->execute([(int)$parentAgent['level_id']]);
        $parentAgent['level_name'] = (string)$stmt->fetchColumn();
    }
}

$stmt = db()->prepare("SELECT `action`,`detail`,`ip`,`created_at` FROM `audit_log` WHERE `module`='login' AND `actor_type`=3 AND `actor_id`=? ORDER BY `id` DESC LIMIT 10");
$stmt->execute([$uid]);
$loginLogs = $stmt->fetchAll();

user_header('个人设置', 'profile', $user);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif;
if ($error !== ''): ?>
<div class="alert alert-error">
    <img src="../assets/svg/warn-circle.svg" alt="">
    <span><?php echo e($error); ?></span>
</div>
<?php endif; ?>

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
.profile-role-tag{font-size:12px;font-weight:400;border-radius:999px;padding:1px 10px;line-height:20px;flex-shrink:0;color:var(--p);background:var(--p-bg)}
.profile-role-tag.tag-ok{background:var(--ok-bg);color:var(--ok)}
.profile-role-tag.tag-warn{background:var(--warn-bg);color:var(--warn)}
.profile-role-tag.tag-gray{background:var(--bg);color:var(--t3)}
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
.kv-table th{width:120px;text-align:left;padding:11px 12px;color:var(--t3);font-weight:400;vertical-align:top;border-bottom:1px solid var(--bd);white-space:nowrap}
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
@media (max-width:768px){
.profile-grid,.grid-2{grid-template-columns:1fr}
.profile-banner-img{height:130px}
.profile-head{padding:0 16px;margin-top:-34px}
.profile-head-avatar{width:76px;height:76px}
.profile-fields{grid-template-columns:1fr}
}
</style>

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
            <div class="profile-head-name"><?php echo e((string)$user['username']); ?><span class="profile-role-tag">用户</span><span class="profile-role-tag <?php echo $realTag; ?>"><?php echo $realName; ?></span></div>
            <div class="profile-head-sub">
                账号：<?php echo e((string)$info['username']); ?>
                <span class="profile-head-sep">|</span>
                <?php if ((string)$info['email'] !== ''): ?><?php echo e((string)$info['email']); ?><?php else: ?><span class="profile-email-empty">未绑定邮箱</span><?php endif; ?>
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
                <p class="card-head-desc">管理账号绑定邮箱与手机号</p>
            </div>
            <span class="card-head-tag">UID: #<?php echo $uid; ?></span>
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
                    <input class="form-input" type="text" value="<?php echo $parentAgent ? '用户（已绑定上级代理商）' : '用户'; ?>" disabled>
                </div>
                <?php $boundEmail = (string)$info['email'] !== ''; ?>
                <div class="form-item">
                    <label class="form-label">绑定邮箱</label>
                    <?php if ($boundEmail): ?>
                    <div class="profile-code-row">
                        <input class="form-input" type="text" value="<?php echo e((string)$info['email']); ?>" disabled>
                        <?php if ($mailOn): ?><button class="btn-plain" type="button" id="btn-change-email">更换邮箱</button><?php endif; ?>
                    </div>
                    <input type="hidden" name="email" value="<?php echo e((string)$info['email']); ?>">
                    <input type="hidden" name="email_bind_code" value="">
                    <div class="form-hint"><?php echo $mailOn ? '如需更换，点击右侧「更换邮箱」，验证新邮箱后完成换绑' : '用于接收通知邮件与找回密码'; ?></div>
                    <?php else: ?>
                    <input class="form-input" type="email" name="email" maxlength="128" value="" placeholder="请输入邮箱"<?php echo $mailOn ? '' : ' disabled'; ?>>
                    <div class="form-hint"><?php echo $mailOn ? '用于接收通知邮件与找回密码；首次绑定需验证新邮箱' : '邮件功能未开启，暂无法绑定邮箱'; ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-item">
                    <label class="form-label">手机号</label>
                    <input class="form-input" type="text" name="phone" maxlength="11" value="<?php echo e((string)$info['phone']); ?>" placeholder="请输入手机号">
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
            <span class="card-head-icon icon-blue"><img src="../assets/svg/menu-agent.svg" alt=""></span>
            <div class="card-head-text">
                <h2 class="card-head-title">我的上级</h2>
                <p class="card-head-desc">绑定后由上级代理商为您提供服务</p>
            </div>
        </div>
        <?php if ($parentAgent): ?>
        <div class="table-wrap">
            <table class="kv-table">
                <tbody>
                <tr><th>上级代理商</th><td><?php echo e((string)$parentAgent['username']); ?></td></tr>
                <tr><th>代理等级</th><td><?php echo isset($parentAgent['level_name']) && $parentAgent['level_name'] !== '' ? e((string)$parentAgent['level_name']) : '默认等级'; ?></td></tr>
                <tr><th>绑定方式</th><td>注册时通过邀请码绑定</td></tr>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="card-desc" style="margin-bottom:0">暂未绑定上级代理商。注册时填写代理商邀请码即可自动绑定；绑定后由上级为您提供服务。</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <span class="card-head-icon icon-blue"><img src="../assets/svg/menu-money.svg" alt=""></span>
            <div class="card-head-text">
                <h2 class="card-head-title">账号信息</h2>
                <p class="card-head-desc">账号注册与账户概况</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="kv-table">
                <tbody>
                <tr><th>注册时间</th><td><?php echo date('Y-m-d H:i', (int)$info['created_at']); ?></td></tr>
                <tr><th>账户余额</th><td><?php echo e(number_format((float)$info['balance'], 2)); ?> 元</td></tr>
                <tr><th>实名状态</th><td><span class="tag <?php echo $realTag; ?>"><?php echo $realName; ?></span></td></tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php
// 实名认证区块：优先使用「腾讯云人脸核身」插件，未启用时回退「内置实名认证」
$builtinOn = plugin_enabled('builtin');
$faceidOn = false;
$faceidReady = false;
$faceidRequireName = true;
$faceidPending = null;
$faceidLast = null;
if (is_file(ROOT_PATH . '/plugin/tencent_faceid/common.php')) {
    require_once ROOT_PATH . '/plugin/tencent_faceid/common.php';
    $faceidOn = plugin_enabled('tencent_faceid');
    if ($faceidOn) {
        $faceidReady = faceid_ready();
        $faceidRequireName = faceid_require_name();
        $faceidPending = faceid_pending_log($uid);
        $faceidLast = faceid_last_log($uid);
    }
}
?>
<?php if ($faceidOn): ?>
<section class="card" style="margin-top:16px" id="realname">
    <div class="card-head">
        <span class="card-head-icon icon-blue"><img src="../assets/svg/menu-user.svg" alt=""></span>
        <div class="card-head-text">
            <h2 class="card-head-title">实名认证（腾讯云人脸核身）</h2>
            <p class="card-head-desc">通过腾讯云人脸核身 H5 完成活体刷脸，证件号加密存储，后台仅展示脱敏内容</p>
        </div>
        <span class="card-head-tag <?php echo (int)$info['real_status'] === 2 ? 'tag-green' : ''; ?>"><?php echo $realName; ?></span>
    </div>
    <?php $realStatus = (int)$info['real_status'];
    $maskedCard = sec_mask_idcard(sec_id_decrypt((string)$info['id_card']));
    if ($realStatus === 2): ?>
    <div class="table-wrap">
        <table class="kv-table">
            <tbody>
            <tr><th>认证状态</th><td><span class="tag tag-ok">已通过</span></td></tr>
            <tr><th>真实姓名</th><td><?php echo e((string)$info['real_name']); ?></td></tr>
            <tr><th>证件号码</th><td class="cell-code"><?php echo e($maskedCard); ?></td></tr>
            <?php if ($faceidLast && (int)$faceidLast['status'] === 1): ?>
            <tr><th>核身时间</th><td class="cell-sub"><?php echo (int)$faceidLast['finished_at'] > 0 ? date('Y-m-d H:i', (int)$faceidLast['finished_at']) : '-'; ?>（腾讯云人脸核身）</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($realStatus === 1): ?>
    <p style="font-size:14px;color:var(--t1)">
        人脸核身已通过，实名信息<?php echo faceid_auto_pass() ? '将自动完成认证' : '正在等待管理员审核'; ?>（<span class="tag tag-warn">待审核</span>）。
        姓名：<strong><?php echo e((string)$info['real_name']); ?></strong>，证件号码：<span class="cell-code"><?php echo e($maskedCard); ?></span>。
        审核结果将通过邮件通知，请留意资料中填写的邮箱。
    </p>
    <?php else: ?>
    <?php if ($realStatus === 3): ?>
    <div class="alert alert-error">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>上次提交的实名信息未通过审核<?php echo ($info['real_reason'] ?? '') !== '' ? '，驳回理由：' . e((string)$info['real_reason']) : '，请核对后重新发起'; ?>。</span>
    </div>
    <?php elseif ($faceidLast && (int)$faceidLast['status'] === 2): ?>
    <div class="alert alert-error">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>上次人脸核身未通过（<?php echo e((string)$faceidLast['err_msg'] ?: '核验未通过'); ?>），请核对信息后重新发起。</span>
    </div>
    <?php endif; ?>
    <?php if (!$faceidReady): ?>
    <div class="alert alert-info">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>人脸核身服务正在配置中，请稍后再试或联系管理员。</span>
    </div>
    <?php else: ?>
    <?php if ($faceidPending): ?>
    <div class="alert alert-info">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>您有一次进行中的人脸核身（<?php echo date('H:i', (int)$faceidPending['created_at']); ?> 发起）。请完成刷脸后回到本页，结果将自动更新；超过 2 小时未完成请重新发起。</span>
    </div>
    <?php endif; ?>
    <form method="post" action="../plugin/tencent_faceid/start.php" style="max-width:420px">
        <?php echo csrf_field(); ?>
        <?php if ($faceidRequireName): ?>
        <div class="form-item">
            <label class="form-label">真实姓名</label>
            <input class="form-input" type="text" name="real_name" maxlength="32" value="<?php echo e($realStatus === 3 ? (string)$info['real_name'] : ''); ?>" placeholder="请输入真实姓名" required>
        </div>
        <div class="form-item">
            <label class="form-label">身份证号</label>
            <input class="form-input" type="text" name="id_card" maxlength="18" value="" placeholder="请输入18位身份证号" required>
        </div>
        <?php endif; ?>
        <button class="btn-p" type="submit" data-confirm="确认发起腾讯云人脸核身吗？将在新页面完成活体刷脸（建议在微信内打开）。">发起人脸核身</button>
        <div class="form-hint" style="margin-top:10px;line-height:18px">人脸核身由腾讯云提供，点击后将跳转腾讯云核身页面完成活体检测；核验通过后回跳本页自动更新实名状态。</div>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php elseif ($builtinOn): ?>
<section class="card" style="margin-top:16px">
    <div class="card-head">
        <span class="card-head-icon icon-blue"><img src="../assets/svg/menu-user.svg" alt=""></span>
        <div class="card-head-text">
            <h2 class="card-head-title">实名认证</h2>
            <p class="card-head-desc">实名信息仅用于账号认证，证件号码加密存储，后台仅展示脱敏内容</p>
        </div>
        <span class="card-head-tag <?php echo (int)$info['real_status'] === 2 ? 'tag-green' : ''; ?>"><?php echo $realName; ?></span>
    </div>
    <?php $realStatus = (int)$info['real_status'];
    $maskedCard = sec_mask_idcard(sec_id_decrypt((string)$info['id_card']));
    if ($realStatus === 2): ?>
    <div class="table-wrap">
        <table class="kv-table">
            <tbody>
            <tr><th>认证状态</th><td><span class="tag tag-ok">已通过</span></td></tr>
            <tr><th>真实姓名</th><td><?php echo e((string)$info['real_name']); ?></td></tr>
            <tr><th>证件号码</th><td class="cell-code"><?php echo e($maskedCard); ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php elseif ($realStatus === 1): ?>
    <p style="font-size:14px;color:var(--t1)">
        实名信息已提交，正在等待管理员审核（<span class="tag tag-warn">待审核</span>）。
        姓名：<strong><?php echo e((string)$info['real_name']); ?></strong>，证件号码：<span class="cell-code"><?php echo e($maskedCard); ?></span>。
        审核结果将通过邮件通知，请留意资料中填写的邮箱。
    </p>
    <?php else: ?>
    <?php if ($realStatus === 3): ?>
    <div class="alert alert-error">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>上次提交的实名信息未通过审核<?php echo ($info['real_reason'] ?? '') !== '' ? '，驳回理由：' . e((string)$info['real_reason']) : '，请核对后重新提交'; ?>。</span>
    </div>
    <?php endif; ?>
    <form method="post" style="max-width:420px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="realname">
        <div class="form-item">
            <label class="form-label">真实姓名</label>
            <input class="form-input" type="text" name="real_name" maxlength="32" value="<?php echo e($realStatus === 3 ? (string)$info['real_name'] : ''); ?>" placeholder="请输入真实姓名" required>
        </div>
        <div class="form-item">
            <label class="form-label">身份证号</label>
            <input class="form-input" type="text" name="id_card" maxlength="18" value="" placeholder="请输入18位身份证号" required>
        </div>
        <button class="btn-p" type="submit" data-confirm="确认提交实名认证信息吗？提交后需等待管理员审核。">提交实名认证</button>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card" style="margin-top:16px">
    <div class="card-head">
        <span class="card-head-icon icon-blue"><img src="../assets/svg/menu-log.svg" alt=""></span>
        <div class="card-head-text">
            <h2 class="card-head-title">登录日志</h2>
            <p class="card-head-desc">最近 10 条登录记录</p>
        </div>
    </div>
    <?php if (!$loginLogs): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无登录记录。</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>时间</th><th style="width:90px">结果</th><th>IP 地址</th></tr></thead>
            <tbody>
            <?php foreach ($loginLogs as $lg):
                $okLog = (string)$lg['action'] === 'user_success';
                $ip = '';
                if (preg_match('/IP：(.+)$/u', (string)$lg['detail'], $m)) {
                    $ip = trim($m[1]);
                }
            ?>
            <tr>
                <td class="cell-code"><?php echo e(date('Y-m-d H:i:s', (int)$lg['created_at'])); ?></td>
                <td><?php echo $okLog ? '<span class="tag tag-ok">成功</span>' : '<span class="tag tag-err">失败</span>'; ?></td>
                <td class="cell-code"><?php echo e($ip !== '' ? $ip : (string)$lg['ip']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
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

    var pwFlow = mkFlow('btn-sendcode', 'code-hint', <?php echo (int)($_SESSION['user_profile_code_wait'] ?? 0); ?>);
    var bindFlow = mkFlow(document.getElementById('bm-send') ? 'bm-send' : 'btn-bindcode',
        document.getElementById('bm-hint') ? 'bm-hint' : 'bind-hint',
        <?php echo (int)($_SESSION['user_profile_bind_wait'] ?? 0); ?>);

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

<?php user_footer(); ?>
