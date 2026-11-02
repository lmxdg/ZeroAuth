<?php
// 腾讯云人脸核身 - 核身完成回跳：拉取结果并写入实名状态
// 腾讯云核身完成后跳转本页（RedirectUrl 会附带本次核身标识参数）
declare(strict_types=1);

require dirname(__DIR__, 2) . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/Security.php';
require_once __DIR__ . '/common.php';
config();

$back = base_url() . 'user/profile.php#realname';
faceid_table();

if (!plugin_enabled('tencent_faceid')) {
    exit('实名核身功能未开放。');
}
$user = user_require_login();
$uid  = (int)$user['id'];

// 兼容多种回跳参数格式：?token=BizToken / ?BizToken=... / ?biz_token=...
$token = trim((string)($_GET['token'] ?? $_GET['BizToken'] ?? $_GET['biz_token'] ?? ''));
if ($token !== '' && preg_match('/[^A-Za-z0-9\-]/', $token)) {
    $token = '';
}

$log = null;
if ($token !== '') {
    $stmt = db()->prepare('SELECT * FROM `tencent_faceid_log` WHERE `biz_token`=? LIMIT 1');
    $stmt->execute([$token]);
    $log = $stmt->fetch() ?: null;
    if ($log && (int)$log['user_id'] !== $uid) {
        flash_set('err', '核身记录与当前账号不一致，无法确认结果。');
        redirect($back);
    }
}
if (!$log) {
    // 回跳未携带标识时，兜底取当前用户有效期内最近一次发起的核身
    $log = faceid_pending_log($uid);
}
if (!$log) {
    flash_set('err', '未找到进行中的核身记录，请重新发起人脸核身。');
    redirect($back);
}
if ((int)$log['status'] !== 0) {
    flash_set('ok', (int)$log['status'] === 1 ? '人脸核身已通过。' : '上次人脸核身未通过，请重新发起。');
    redirect($back);
}

$token = (string)$log['biz_token'];
$res = faceid_get_result($token);

// 拉取失败（网络 / 未完成）不落终态，保留进行中状态供重试
if (!$res['ok']) {
    flash_set('err', $res['msg']);
    redirect($back);
}

$now = time();
$errMsg = mb_substr($res['err_msg'], 0, 250);
$stmt = db()->prepare('UPDATE `tencent_faceid_log` SET `status`=?,`err_code`=?,`err_msg`=?,`extra`=?,`finished_at`=? WHERE `id`=?');

if ($res['passed']) {
    $stmt->execute([1, $res['err_code'], $errMsg, mb_substr($res['extra'], 0, 250), $now, (int)$log['id']]);

    $finalName = $res['name'] !== '' ? $res['name'] : (string)$log['real_name'];
    $encCard   = '';
    $maskedCard = '';
    if ($res['id_card'] !== '') {
        $encCard = sec_id_encrypt($res['id_card']);
        $maskedCard = sec_mask_idcard($res['id_card']);
    } elseif ((string)$log['id_card'] !== '') {
        $encCard = (string)$log['id_card'];
        $maskedCard = sec_mask_idcard(sec_id_decrypt($encCard));
    }

    $newStatus = faceid_auto_pass() ? 2 : 1;
    db()->prepare('UPDATE `user` SET `real_status`=?,`real_name`=?,`id_card`=?,`real_reason`=\'\' WHERE `id`=?')
        ->execute([$newStatus, $finalName, $encCard, $uid]);

    audit('user', 'faceid_pass', 3, $uid, '腾讯云人脸核身通过：' . $finalName . ($maskedCard !== '' ? '（' . $maskedCard . '）' : ''));
    mail_scene_send('faceid_result', '', [
        'event'    => '人脸核身通过',
        'userName' => (string)$user['username'],
        'realName' => $finalName,
        'masked'   => $maskedCard !== '' ? $maskedCard : '未提供',
        'result'   => $newStatus === 2 ? '已自动通过实名认证' : '已写入实名信息，等待管理员审核',
        'time'     => date('Y-m-d H:i:s', $now),
    ]);

    flash_set('ok', $newStatus === 2 ? '人脸核身通过，实名认证已完成！' : '人脸核身通过，实名信息已提交，等待管理员审核。');
} else {
    $stmt->execute([2, $res['err_code'], $errMsg, mb_substr($res['extra'], 0, 250), $now, (int)$log['id']]);
    audit('user', 'faceid_fail', 3, $uid, '腾讯云人脸核身未通过：[' . $res['err_code'] . '] ' . $errMsg);
    flash_set('err', '人脸核身未通过：' . $errMsg . '。如有疑问请重新发起或联系管理员。');
}

redirect($back);
