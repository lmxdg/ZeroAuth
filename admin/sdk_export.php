<?php
// 管理员后台 - SDK 导出下载
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

$admin = admin_require_login();

if (!is_post()) {
    redirect('sdk_manage.php');
}
csrf_check();

$templateId = (int)($_POST['template_id'] ?? 0);
$appId = (int)($_POST['app_id'] ?? 0);

if (!extension_loaded('zip')) {
    flash_set('err', '服务器未启用 Zip 扩展（php_zip），无法打包下载。');
    redirect('sdk_manage.php');
}

$stmt = db()->prepare('SELECT * FROM `sdk_template` WHERE `id`=? AND `status`=1');
$stmt->execute([$templateId]);
$tpl = $stmt->fetch();
if (!$tpl) {
    flash_set('err', 'SDK 模板不存在或已停用。');
    redirect('sdk_manage.php');
}

$stmt = db()->prepare('SELECT * FROM `app` WHERE `id`=? AND `status`=1');
$stmt->execute([$appId]);
$app = $stmt->fetch();
if (!$app) {
    flash_set('err', '应用不存在或已停用，请先在应用管理中创建应用。');
    redirect('sdk_manage.php');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $scheme = 'https';
}
$siteBase = rtrim($scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');

$language = (string)$tpl['language'];
$langLabel = sdk_language_label($language);
$ext = sdk_language_ext($language);

$content = str_replace(
    ['{{app_id}}', '{{app_key}}', '{{api_base}}'],
    [(string)$app['app_id'], (string)$app['app_key'], $siteBase],
    (string)$tpl['content']
);
$mainFile = 'sdk_' . $app['app_id'] . '.' . $ext;

$readme = "SDK 使用说明（{$langLabel}）\r\n"
    . "==================\r\n"
    . "应用名称：{$app['name']}\r\n"
    . "应用 ID：{$app['app_id']}\r\n"
    . "模板名称：{$tpl['name']}\r\n"
    . "模板语言：{$langLabel}（主文件 {$mainFile}）\r\n"
    . "授权服务：{$siteBase}\r\n"
    . "生成时间：" . date('Y-m-d H:i:s') . "\r\n\r\n"
    . "接入步骤：\r\n"
    . "1. 将 {$mainFile} 放入你的项目并引入。\r\n"
    . "2. 如模板中的 API 地址为空，请将其改为 {$siteBase}。\r\n"
    . "3. 在业务启动或关键入口调用 verify(licenseKey)，result 为 pass 时放行。\r\n\r\n"
    . "安全说明：\r\n"
    . "1. 本 SDK 仅包含 app_id 与 appKey，不包含 AppSecret 硬编码。\r\n"
    . "2. AppSecret 通过授权系统 /api/get_secret.php 动态拉取，仅在内存中临时使用。\r\n"
    . "3. 禁止将密钥写入本地文件、日志或任何持久化存储。\r\n"
    . "4. 密钥会话到达 expire 时间戳后自动失效，SDK 需重新拉取。\r\n";

$zipFile = tempnam(sys_get_temp_dir(), 'sdk_');
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::OVERWRITE) !== true) {
    flash_set('err', '创建 ZIP 包失败。');
    redirect('sdk_manage.php');
}
$zip->addFromString($mainFile, $content);
$zip->addFromString('README.txt', $readme);
$zip->close();

$stmt = db()->prepare('INSERT INTO `sdk_download_log` (`account_type`,`account_id`,`account_name`,`app_id`,`template_id`,`ip`,`created_at`) VALUES (1,?,?,?,?,?,?)');
$stmt->execute([(int)$admin['id'], (string)$admin['username'], $appId, $templateId, client_ip(), time()]);
audit('sdk', 'download', 1, (int)$admin['id'], '应用：' . $app['app_id'] . ' 模板：' . $tpl['name'] . ' 语言：' . $langLabel);

$filename = 'sdk_' . $app['app_id'] . '_' . date('YmdHi') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string)filesize($zipFile));
header('Cache-Control: no-store');
readfile($zipFile);
@unlink($zipFile);
exit;
