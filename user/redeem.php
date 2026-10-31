<?php
// 用户中心 - 卡密兑换
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_user.php';

$user = user_require_login();
$uid  = (int)$user['id'];
$error = '';

if (is_post()) {
    csrf_check();
    $cardNo = strtoupper(trim((string)($_POST['card_no'] ?? '')));

    if ($cardNo === '') {
        $error = '请输入卡密授权码。';
    } else {
        $stmt = db()->prepare('SELECT * FROM `license` WHERE `card_no`=?');
        $stmt->execute([$cardNo]);
        $lic = $stmt->fetch();

        if (!$lic) {
            $error = '卡密不存在，请检查输入。';
        } elseif ((int)$lic['status'] === 2) {
            $error = '该卡密已被封禁，无法兑换。';
        } elseif ((int)$lic['user_id'] > 0 && (int)$lic['user_id'] !== $uid) {
            $error = '该卡密已被其他用户兑换。';
        } else {
            $stmt = db()->prepare('SELECT `name`,`status` FROM `app` WHERE `id`=?');
            $stmt->execute([(int)$lic['app_id']]);
            $app = $stmt->fetch();

            if (!$app || (int)$app['status'] !== 1) {
                $error = '该卡密所属应用已停用，无法兑换。';
            } elseif ((int)$lic['type'] === 1 && (int)$lic['expire_time'] > 0 && (int)$lic['expire_time'] < time()) {
                $error = '该限时卡密已过期，无法兑换。';
            } elseif ((int)$lic['type'] === 2 && (int)$lic['total_count'] > 0 && (int)$lic['used_count'] >= (int)$lic['total_count']) {
                $error = '该计次卡密验证次数已耗尽，无法兑换。';
            } else {
                $already = (int)$lic['user_id'] === $uid;
                if (!$already) {
                    $stmt = db()->prepare('UPDATE `license` SET `user_id`=? WHERE `id`=? AND `user_id`=0');
                    $stmt->execute([$uid, (int)$lic['id']]);
                    if ($stmt->rowCount() !== 1) {
                        $error = '兑换失败：该卡密刚被其他用户兑换。';
                    }
                }
                if ($error === '') {
                    audit('license', 'redeem', 3, $uid, '兑换卡密：' . $lic['card_no'] . '（应用：' . $app['name'] . '）');
                    if ((string)$user['email'] !== '') {
                        require_once ROOT_PATH . '/core/Mail.php';
                        mail_scene_send('deliver', (string)$user['email'], [
                            'userName'   => (string)$user['username'],
                            'appName'    => (string)$app['name'],
                            'cardNo'     => (string)$lic['card_no'],
                            'orderNo'    => '兑换开通',
                            'openTime'   => date('Y-m-d H:i'),
                            'expireTime' => ((int)$lic['type'] === 1 && (int)$lic['expire_time'] > 0) ? date('Y-m-d H:i', (int)$lic['expire_time']) : ((int)$lic['type'] === 2 ? '计次授权' : '永久有效'),
                        ]);
                    }
                    flash_set('ok', ($already ? '该卡密已绑定您的账号。' : '兑换成功！') . '授权应用：' . $app['name']);
                    redirect('licenses.php?act=detail&id=' . (int)$lic['id']);
                }
            }
        }
    }
}

user_header('卡密兑换', 'redeem', $user);
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

<section class="card">
    <h2 class="card-title">兑换卡密</h2>
    <p class="card-desc">输入购买获得的卡密授权码，激活对应应用授权；注意限时卡有效期自生成时刻起算，请及时兑换使用。</p>
    <form method="post" class="form" style="max-width:560px">
        <?php echo csrf_field(); ?>
        <div class="form-item">
            <label class="form-label">卡密授权码</label>
            <input class="form-input" type="text" name="card_no" maxlength="64" placeholder="例如 VIP-1A2B-3C4D-5E6F-7A8B" style="text-transform:uppercase;font-family:Consolas,Monaco,monospace" required>
            <div class="form-hint">卡密格式：前缀-XXXX-XXXX-XXXX-XXXX，不区分大小写。</div>
        </div>
        <button class="btn-p" type="submit">立即兑换</button>
    </form>
</section>
<?php user_footer(); ?>
