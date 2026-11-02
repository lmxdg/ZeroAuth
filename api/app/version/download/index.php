<?php
// 应用更新包下载（短期令牌，绑定 IP）
declare(strict_types=1);

require dirname(__DIR__, 4) . '/core/bootstrap.php';
require dirname(__DIR__, 4) . '/core/Security.php';
require dirname(__DIR__, 4) . '/core/LicenseApi.php';

$ip    = client_ip();
$token = trim((string)($_GET['token'] ?? ''));

$fail = function (string $msg): never {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 404, 'msg' => $msg, 'data' => new stdClass()], JSON_UNESCAPED_UNICODE);
    exit;
};

if ($token === '' || substr_count($token, '.') !== 1) {
    $fail('下载令牌无效或已过期');
}

$payload = strtr(explode('.', $token)[0], '-_', '+/');
$head    = json_decode((string)base64_decode($payload, true), true);
if (!is_array($head) || empty($head['ak'])) {
    $fail('下载令牌无效或已过期');
}

$stmt = db()->prepare('SELECT * FROM `app` WHERE `app_key`=? LIMIT 1');
$stmt->execute([(string)$head['ak']]);
$app = $stmt->fetch();
if (!$app) {
    $fail('下载令牌无效或已过期');
}

$claim = license_api_verify_download_token($app, $token, $ip);
if (!$claim) {
    $fail('下载令牌无效或已过期');
}

$stmt = db()->prepare('SELECT * FROM `app_version` WHERE `app_id`=? AND `version`=? AND `status`=1 LIMIT 1');
$stmt->execute([(int)$app['id'], (string)$claim['version']]);
$ver = $stmt->fetch();
if (!$ver) {
    $fail('版本不存在或已下架');
}

$fileName = basename((string)$ver['file_path']);
$filePath = RUNTIME_PATH . '/app_files/' . $fileName;
if ($fileName === '' || $fileName === '.' || !is_file($filePath)) {
    $fail('更新包文件缺失，请联系管理员重新上传');
}

audit('license', 'app_download', 4, 0, 'appKey：' . $app['app_key'] . ' 版本：' . $ver['version'] . ' IP：' . $ip);

$ext      = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
$downName = preg_replace('/[^A-Za-z0-9._-]/', '', $app['app_key'] . '-' . $ver['version']) . '.' . ($ext !== '' ? $ext : 'bin');

header('Content-Type: application/octet-stream');
header('Content-Length: ' . (string)filesize($filePath));
header('X-File-Md5: ' . (string)$ver['file_md5']);
header('Content-Disposition: attachment; filename="' . $downName . '"');
header('Cache-Control: no-store');
readfile($filePath);
exit;
