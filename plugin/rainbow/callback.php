<?php
// 彩虹聚合登录：回调（Step4 通过 Authorization Code 获取用户信息并登录/绑定）
declare(strict_types=1);

require dirname(__DIR__, 2) . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/Security.php';
require_once __DIR__ . '/common.php';

config();

header('Content-Type: text/html; charset=utf-8');

$fail = function (string $msg): never {
    flash_set('err', $msg);
    redirect('../user/index.php');
};

if (!plugin_enabled('rainbow')) {
    $fail('彩虹聚合登录插件未启用。');
}

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$code = trim((string)($_GET['code'] ?? ''));
if ($type === '' || $code === '') {
    $fail('回调参数缺失，登录未完成。');
}

$appid  = trim(plugin_cfg_get('rainbow', 'rainbow_appid', ''));
$appkey = trim(plugin_cfg_get('rainbow', 'rainbow_appkey', ''));

$query = http_build_query([
    'act'     => 'callback',
    'appid'   => $appid,
    'appkey'  => $appkey,
    'type'    => $type,
    'code'    => $code,
]);
$resp = http_get(rainbow_api_base() . '/connect.php?' . $query, 8);
$data = is_string($resp) ? json_decode($resp, true) : null;

if (!is_array($data) || (int)($data['code'] ?? -1) !== 0 || empty($data['social_uid'])) {
    $msg = is_array($data) && isset($data['msg']) ? (string)$data['msg'] : '获取第三方用户信息失败。';
    $fail('第三方登录失败：' . $msg);
}

$socialUid = (string)$data['social_uid'];
$nickname  = mb_substr((string)($data['nickname'] ?? ''), 0, 64);
$faceimg   = mb_substr((string)($data['faceimg'] ?? ''), 0, 255);

try {
    rainbow_table_ensure();
} catch (Throwable $ex) {
    $fail('初始化绑定数据表失败：' . $ex->getMessage());
}

// 查询绑定关系
$stmt = db()->prepare('SELECT `user_id` FROM `rainbow_bind` WHERE `login_type`=? AND `social_uid`=? LIMIT 1');
$stmt->execute([$type, $socialUid]);
$bindUserId = (int)($stmt->fetchColumn() ?: 0);

// 已绑定：直接登录
if ($bindUserId > 0) {
    $stmt = db()->prepare('SELECT * FROM `user` WHERE `id`=?');
    $stmt->execute([$bindUserId]);
    $user = $stmt->fetch();
    if (!$user || (int)$user['status'] !== 1) {
        $fail('该第三方账号绑定的站点账号不可用，请联系管理员。');
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    audit('login', 'user_rainbow', 3, (int)$user['id'], '彩虹聚合登录（' . $type . '）IP：' . client_ip());
    flash_set('ok', '第三方登录成功，欢迎回来！');
    redirect('../user/dashboard.php');
}

// 未绑定但已登录站点账号：绑定当前账号
$cur = current_user();
if ($cur) {
    $stmt = db()->prepare('SELECT `id` FROM `rainbow_bind` WHERE `user_id`=? AND `login_type`=? LIMIT 1');
    $stmt->execute([(int)$cur['id'], $type]);
    if ($stmt->fetch()) {
        $fail('当前账号已绑定过该登录方式。');
    }
    db()->prepare('INSERT INTO `rainbow_bind` (`user_id`,`login_type`,`social_uid`,`nickname`,`faceimg`,`created_at`) VALUES (?,?,?,?,?,?)')
        ->execute([(int)$cur['id'], $type, $socialUid, $nickname, $faceimg, time()]);
    audit('user', 'rainbow_bind', 3, (int)$cur['id'], '绑定 ' . $type . '：' . $socialUid);
    flash_set('ok', '已成功绑定「' . rainbow_types_map()[$type] . '」登录。');
    redirect('../user/profile.php');
}

// 未登录且未绑定：按插件配置决定是否自动注册
if (plugin_cfg_get('rainbow', 'rainbow_autoreg', '0') !== '1') {
    $fail('该第三方账号尚未绑定站点账号，请先使用账号密码登录，再到「个人设置」完成绑定。');
}

if (cfg('register_mode', '1') !== '1') {
    $fail('当前站点未开放自助注册，无法自动创建账号，请联系管理员。');
}

// 生成唯一账号名
$username = '';
for ($i = 0; $i < 8; $i++) {
    $try = 'zb' . substr(bin2hex(random_bytes(5)), 0, 8);
    $stmt = db()->prepare('SELECT `id` FROM `user` WHERE `username`=?');
    $stmt->execute([$try]);
    if (!$stmt->fetch()) {
        $username = $try;
        break;
    }
}
if ($username === '') {
    $fail('自动注册失败：账号名生成冲突，请稍后再试。');
}

$password = bin2hex(random_bytes(12));
$email    = $socialUid . '@rainbow.oauth';
try {
    $stmt = db()->prepare('INSERT INTO `user` (`username`,`password`,`email`,`status`,`reg_ip`,`created_at`) VALUES (?,?,?,1,?,?)');
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email, client_ip(), time()]);
    $uid = (int)db()->lastInsertId();
} catch (Throwable $ex) {
    $fail('自动创建账号失败：' . $ex->getMessage());
}

db()->prepare('INSERT INTO `rainbow_bind` (`user_id`,`login_type`,`social_uid`,`nickname`,`faceimg`,`created_at`) VALUES (?,?,?,?,?,?)')
    ->execute([$uid, $type, $socialUid, $nickname, $faceimg, time()]);

audit('user', 'register_rainbow', 3, $uid, '彩虹聚合登录自动注册（' . $type . '）：' . $username);
session_regenerate_id(true);
$_SESSION['user_id'] = $uid;
flash_set('ok', '第三方登录成功，已自动创建账号「' . $username . '」，请前往「个人设置」绑定邮箱并完善资料。');
redirect('../user/dashboard.php');
