<?php
// 管理员后台 - 个人资料
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';
require dirname(__DIR__) . '/core/Mail.php';
require_once ROOT_PATH . '/core/Security.php';

$admin = admin_require_login();
$aid = (int)$admin['id'];
$needCode = mail_code_available() && (string)$admin['email'] !== '';

if (isset($_GET['sendcode'])) {
    $t = (string)($_GET['t'] ?? '');
    if ($t === '' || !hash_equals(csrf_token(), $t)) {
        flash_set('err', '页面已过期，请刷新后重试。');
    } else {
        $res = mail_code_send('admin_forgot', (string)$admin['email'], ['userName' => (string)$admin['username']]);
        flash_set($res['ok'] ? 'ok' : 'err', $res['msg']);
    }
    redirect('profile.php');
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'profile') {
        $nickname = trim((string)($_POST['nickname'] ?? ''));
        $qq       = preg_replace('/\D/', '', (string)($_POST['qq'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        if (mb_strlen($nickname) > 32) {
            flash_set('err', '昵称最长 32 个字符。');
        } elseif (strlen($qq) > 12) {
            flash_set('err', 'QQ 号格式不正确。');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('err', '邮箱格式不正确。');
        } else {
            db()->prepare('UPDATE `admin` SET `nickname`=?,`qq`=?,`email`=? WHERE `id`=?')
                ->execute([$nickname, $qq, $email, $aid]);
            audit('config', 'profile_update', 1, $aid, '更新个人资料');
            flash_set('ok', '个人资料已更新。');
        }
        redirect('profile.php');
    }

    if ($action === 'password') {
        $old  = (string)($_POST['old_password'] ?? '');
        $new  = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['new_password2'] ?? '');
        $code = trim((string)($_POST['email_code'] ?? ''));

        $stmt = db()->prepare('SELECT `username`,`password`,`email` FROM `admin` WHERE `id`=?');
        $stmt->execute([$aid]);
        $row = $stmt->fetch();
        [$pwOk, $pwMsg] = sec_password_check($new, (string)($row['username'] ?? ''));

        $err = '';
        if (!password_verify($old, (string)$row['password'])) {
            $err = '当前密码不正确。';
        } elseif (!$pwOk) {
            $err = $pwMsg;
        } elseif ($new !== $new2) {
            $err = '两次输入的新密码不一致。';
        } elseif ($needCode && !mail_code_verify('admin_forgot', (string)$row['email'], $code)) {
            $err = '邮箱验证码不正确或已过期，请重新获取。';
        }
        if ($err !== '') {
            flash_set('err', $err);
        } else {
            db()->prepare('UPDATE `admin` SET `password`=? WHERE `id`=?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $aid]);
            audit('config', 'admin_change_password', 1, $aid);
            flash_set('ok', '密码修改成功，下次登录请使用新密码。');
        }
        redirect('profile.php');
    }
}

$stmt = db()->prepare('SELECT `username`,`email`,`nickname`,`qq`,`status`,`last_login_ip`,`last_login_time` FROM `admin` WHERE `id`=?');
$stmt->execute([$aid]);
$info = $stmt->fetch();

admin_header('个人信息', 'profile', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
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
            <div class="profile-head-name"><?php echo e($info['nickname'] !== '' ? $info['nickname'] : $info['username']); ?><span class="profile-role-tag">超级管理员</span></div>
            <div class="profile-head-sub">
                账号：<?php echo e($info['username']); ?>
                <span class="profile-head-sep">|</span>
                <?php if ((string)$info['email'] !== ''): ?><?php echo e($info['email']); ?><?php else: ?><span class="profile-email-empty">未绑定邮箱</span><?php endif; ?>
                <span class="profile-head-sep">|</span>
                最近登录：<?php echo (int)$info['last_login_time'] > 0 ? date('Y-m-d H:i:s', (int)$info['last_login_time']) : '-'; ?>
                <span class="profile-head-sep">|</span>
                当前 IP：<?php echo (string)$info['last_login_ip'] !== '' ? e((string)$info['last_login_ip']) : '-'; ?>
                <?php if ((string)$info['last_login_ip'] !== ''): ?>
                <span class="profile-head-sep">|</span>
                归属地：<?php echo e(ip_geo_text((string)$info['last_login_ip']) ?: '-'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div class="profile-grid">
    <section class="card" id="base-card">
        <div class="card-head">
            <span class="card-head-icon icon-blue"><img src="../assets/svg/user.svg" alt=""></span>
            <div class="card-head-text">
                <h2 class="card-head-title">基本信息</h2>
                <p class="card-head-desc">管理管理员账号昵称与通知邮箱</p>
            </div>
            <span class="card-head-tag">UID: #<?php echo $aid; ?></span>
        </div>
        <form method="post" class="profile-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="profile">
            <div class="profile-fields">
                <div class="form-item">
                    <label class="form-label">登录账号</label>
                    <input class="form-input" type="text" value="<?php echo e($info['username']); ?>" disabled>
                    <div class="form-hint">账号不可修改</div>
                </div>
                <div class="form-item">
                    <label class="form-label">用户角色</label>
                    <input class="form-input" type="text" value="超级管理员" disabled>
                </div>
                <div class="form-item">
                    <label class="form-label"><span class="req">*</span>个人昵称</label>
                    <input class="form-input" type="text" name="nickname" value="<?php echo e($info['nickname']); ?>" maxlength="32" placeholder="请输入显示昵称">
                </div>
                <div class="form-item">
                    <label class="form-label">QQ 号</label>
                    <input class="form-input" type="text" name="qq" value="<?php echo e($info['qq']); ?>" maxlength="12" placeholder="请输入 QQ 号">
                </div>
                <div class="form-item profile-span2">
                    <label class="form-label">联系邮箱</label>
                    <input class="form-input" type="email" name="email" value="<?php echo e($info['email']); ?>" maxlength="128" placeholder="请输入用于系统接收通知的邮箱">
                    <div class="form-hint">用于接收通知邮件与修改密码验证码</div>
                </div>
            </div>
            <div class="profile-form-actions">
                <button class="btn-primary" type="submit">修改资料</button>
            </div>
        </form>
    </section>

    <section class="card" id="safe-card">
        <div class="card-head">
            <span class="card-head-icon icon-orange"><img src="../assets/svg/menu-lock.svg" alt=""></span>
            <div class="card-head-text">
                <h2 class="card-head-title">安全设置</h2>
                <p class="card-head-desc">定期更改密码提升管理员账号安全性</p>
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
            <span><?php echo (string)$info['email'] === '' ? '尚未绑定邮箱：请先在左侧填写并保存邮箱，修改密码时需邮箱验证码校验。' : '邮件功能未开启（系统配置 → 邮件通知），开启后修改密码需邮箱验证码校验。'; ?></span>
        </div>
        <?php endif; ?>
        <form method="post" class="profile-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="password">
            <div class="profile-fields">
                <div class="form-item profile-span2">
                    <label class="form-label"><span class="req">*</span>当前密码</label>
                    <input class="form-input" type="password" name="old_password" maxlength="64" autocomplete="current-password" placeholder="请输入当前正在使用的密码" required>
                </div>
                <div class="form-item">
                    <label class="form-label"><span class="req">*</span>新密码</label>
                    <input class="form-input" type="password" name="new_password" minlength="8" maxlength="64" placeholder="请输入新密码（8位以上，含字母/数字/符号两类）" autocomplete="new-password" required>
                </div>
                <div class="form-item">
                    <label class="form-label"><span class="req">*</span>确认新密码</label>
                    <input class="form-input" type="password" name="new_password2" maxlength="64" placeholder="请再次输入新密码" autocomplete="new-password" required>
                </div>
                <?php if ($needCode): ?>
                <div class="form-item profile-span2">
                    <label class="form-label"><span class="req">*</span>邮箱验证码</label>
                    <div class="profile-code-row">
                        <input class="form-input" type="text" name="email_code" maxlength="6" placeholder="6 位数字验证码" required>
                        <a class="btn-plain" href="profile.php?sendcode=1&t=<?php echo e(csrf_token()); ?>" style="line-height:32px;white-space:nowrap;text-decoration:none">发送验证码</a>
                    </div>
                    <div class="form-hint">发送至 <?php echo e($info['email']); ?>，10 分钟内有效，间隔 60 秒</div>
                </div>
                <?php endif; ?>
            </div>
            <div class="profile-form-actions">
                <button class="btn-primary" type="submit">修改密码</button>
            </div>
        </form>
    </section>
</div>
<?php admin_footer(); ?>
