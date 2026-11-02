<?php
// 腾讯云人脸核身 - 发起核身（用户中心实名认证表单提交到此）
declare(strict_types=1);

require dirname(__DIR__, 2) . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/Security.php';
require_once __DIR__ . '/common.php';
config();

if (!plugin_enabled('tencent_faceid')) {
    exit('实名核身功能未开放。');
}

$user = user_require_login();
$uid  = (int)$user['id'];
$back = base_url() . 'user/profile.php#realname';

if (!is_post()) {
    redirect($back);
}
if (!isset($_POST['csrf']) || !hash_equals(csrf_token(), (string)$_POST['csrf'])) {
    flash_set('err', '页面已过期，请刷新后重试。');
    redirect($back);
}
if (sec_rate_hit('faceid_start', client_ip(), 5) > 5) {
    flash_set('err', '操作过于频繁，请 1 分钟后再试。');
    redirect($back);
}
if (!faceid_ready()) {
    flash_set('err', '人脸核身插件尚未配置完成（SecretId / SecretKey / RuleId），请联系管理员。');
    redirect($back);
}

// 已通过 / 待审核状态不允许重复发起（已驳回可重新发起）
$st = db()->prepare('SELECT `real_status` FROM `user` WHERE `id`=?');
$st->execute([$uid]);
$curStatus = (int)$st->fetchColumn();
if ($curStatus === 1 || $curStatus === 2) {
    flash_set('err', '当前实名状态不允许重复发起核身。');
    redirect($back);
}

$requireName = faceid_require_name();
$realName = trim((string)($_POST['real_name'] ?? ''));
$idCard   = strtoupper(trim((string)($_POST['id_card'] ?? '')));

if ($requireName) {
    if ($realName === '' || mb_strlen($realName) < 2 || mb_strlen($realName) > 32) {
        flash_set('err', '请输入 2 ~ 32 位真实姓名。');
        redirect($back);
    }
    if (!sec_check_idcard($idCard)) {
        flash_set('err', '身份证号格式不正确。');
        redirect($back);
    }
}

$res = faceid_detect_auth($uid, $requireName ? $realName : '', $requireName ? $idCard : '');
if (!$res['ok']) {
    flash_set('err', $res['msg']);
    redirect($back);
}

// 跳转腾讯云核身页（微信内完成活体刷脸后回跳 callback.php）
redirect($res['url']);
