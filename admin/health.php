<?php
// 管理员后台 - 系统健康检查
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

function health_bytes(float $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return (string)(int)$bytes . ' B';
}

function health_tag(bool $ok, string $okText = '正常', string $badText = '异常'): string
{
    return '<span class="tag ' . ($ok ? 'tag-ok' : 'tag-err') . '">' . e($ok ? $okText : $badText) . '</span>';
}

$dbVersion = '';
$dbName    = (string)(config()['db']['name'] ?? '');
$tableCount = 0;
$dbSize     = 0;
$dbOk       = false;
try {
    $dbVersion = (string)db()->query('SELECT VERSION()')->fetchColumn();
    $tableCount = (int)db()->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
    $dbSize = (float)db()->query('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
    $dbOk = true;
} catch (Throwable $ex) {
    $dbVersion = $ex->getMessage();
}

$extensions = [
    'pdo_mysql' => '数据库连接',
    'openssl'   => 'AES / RSA 加密',
    'curl'      => '外部 HTTP 请求',
    'mbstring'  => '中文长度处理',
    'gd'        => '验证码绘制',
    'zip'       => 'SDK / 更新包解压',
    'fileinfo'  => '上传文件类型检测',
];
$extRows = [];
foreach ($extensions as $ext => $desc) {
    $extRows[] = [$ext, $desc, extension_loaded($ext)];
}

$writablePaths = [
    'runtime'              => RUNTIME_PATH,
    'runtime/app_files'    => RUNTIME_PATH . '/app_files',
    'runtime/backups'      => RUNTIME_PATH . '/backups',
    'runtime/nonce'        => RUNTIME_PATH . '/nonce',
    'runtime/ratelimit'    => RUNTIME_PATH . '/ratelimit',
];
$pathRows = [];
foreach ($writablePaths as $label => $path) {
    if (!is_dir($path)) {
        @mkdir($path, 0700, true);
    }
    $pathRows[] = [$label, is_dir($path), is_dir($path) ? is_writable($path) : false];
}

$logFiles = glob(RUNTIME_PATH . '/*.log') ?: [];
$diskFree = @disk_free_space(ROOT_PATH);
$diskTotal = @disk_total_space(ROOT_PATH);
$diskRate = ($diskTotal && $diskFree !== false && $diskTotal > 0) ? (1 - $diskFree / $diskTotal) * 100 : 0;

$https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
$adminDir = (string)(config()['admin_dir'] ?? 'admin');
$uploadMax = (string)ini_get('upload_max_filesize');
$postMax = (string)ini_get('post_max_size');
$memory = (string)ini_get('memory_limit');
$maxExec = (string)ini_get('max_execution_time');

$suggestions = [];
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    $suggestions[] = ['PHP 版本低于 8.0，建议升级到 PHP 8.1 / 8.2。', 'warn'];
}
if (!extension_loaded('openssl')) {
    $suggestions[] = ['OpenSSL 扩展未加载，加密与 RSA 签名功能不可用。', 'err'];
}
if (!extension_loaded('pdo_mysql')) {
    $suggestions[] = ['PDO MySQL 扩展未加载，系统无法连接数据库。', 'err'];
}
if (!is_writable(RUNTIME_PATH)) {
    $suggestions[] = ['runtime 目录不可写，验证码、限流、备份等功能会异常。', 'err'];
}
if (!$https) {
    $suggestions[] = ['当前未启用 HTTPS，建议生产环境开启 HTTPS 保护登录与接口传输。', 'warn'];
}
if ($diskFree !== false && $diskFree < 524288000) {
    $suggestions[] = ['磁盘剩余空间不足 500 MB，请及时清理备份或日志。', 'warn'];
}
if ($adminDir === 'admin') {
    $suggestions[] = ['后台目录仍为默认 admin，建议在安装或系统配置中改为自定义目录。', 'warn'];
}
if (!extension_loaded('gd')) {
    $suggestions[] = ['GD 扩展未加载，图形验证码可能无法正常显示。', 'warn'];
}
if ($suggestions === []) {
    $suggestions[] = ['系统运行环境检查全部通过，无待处理项。', 'ok'];
}

admin_header('系统健康检查', 'health', $admin);
?>

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">运行环境</h2>
            <p class="card-desc" style="margin-bottom:0">当前服务器与 PHP 运行参数概览，所有检查均为实时读取。</p>
        </div>
        <div class="spacer"></div>
        <a class="btn-plain" href="health.php">刷新检测</a>
    </div>
    <div class="table-wrap">
        <table class="tb">
            <tbody>
            <tr><th style="width:200px">PHP 版本</th><td><?php echo e(PHP_VERSION); ?> <?php echo version_compare(PHP_VERSION, '8.0.0', '>=') ? health_tag(true, '推荐') : health_tag(false, '', '建议升级'); ?></td></tr>
            <tr><th>运行方式</th><td><?php echo e(PHP_SAPI); ?></td></tr>
            <tr><th>服务器软件</th><td><?php echo e((string)($_SERVER['SERVER_SOFTWARE'] ?? '未知')); ?></td></tr>
            <tr><th>操作系统</th><td><?php echo e(PHP_OS_FAMILY . ' / ' . php_uname('r')); ?></td></tr>
            <tr><th>时区</th><td><?php echo e(date_default_timezone_get()); ?></td></tr>
            <tr><th>HTTPS</th><td><?php echo $https ? health_tag(true, '已启用') : health_tag(false, '', '未启用'); ?></td></tr>
            <tr><th>后台目录</th><td><code><?php echo e($adminDir); ?></code></td></tr>
            <tr><th>内存限制</th><td><?php echo e($memory); ?></td></tr>
            <tr><th>最大执行时间</th><td><?php echo e($maxExec); ?> 秒</td></tr>
            <tr><th>上传限制</th><td>upload_max_filesize <?php echo e($uploadMax); ?> / post_max_size <?php echo e($postMax); ?></td></tr>
            <tr><th>OpenSSL 版本</th><td><?php echo extension_loaded('openssl') ? e((string)OPENSSL_VERSION_TEXT) : '-'; ?></td></tr>
            </tbody>
        </table>
    </div>
</section>

<div class="grid-2">
    <section class="card">
        <h2 class="card-title">PHP 扩展</h2>
        <div class="table-wrap">
            <table class="tb">
                <thead><tr><th>扩展</th><th>用途</th><th>状态</th></tr></thead>
                <tbody>
                <?php foreach ($extRows as [$ext, $desc, $ok]): ?>
                <tr>
                    <td class="cell-code"><?php echo e($ext); ?></td>
                    <td class="cell-sub"><?php echo e($desc); ?></td>
                    <td><?php echo health_tag($ok, '已加载', '未加载'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2 class="card-title">目录写入权限</h2>
        <div class="table-wrap">
            <table class="tb">
                <thead><tr><th>目录</th><th>存在</th><th>可写</th></tr></thead>
                <tbody>
                <?php foreach ($pathRows as [$label, $exists, $writable]): ?>
                <tr>
                    <td class="cell-code"><?php echo e($label); ?></td>
                    <td><?php echo health_tag($exists, '是', '否'); ?></td>
                    <td><?php echo health_tag($writable, '是', '否'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="grid-2">
    <section class="card">
        <h2 class="card-title">数据库状态</h2>
        <div class="table-wrap">
            <table class="tb">
                <tbody>
                <tr><th style="width:160px">连接状态</th><td><?php echo health_tag($dbOk, '已连接', '连接失败'); ?></td></tr>
                <tr><th>数据库名</th><td><code><?php echo e($dbName); ?></code></td></tr>
                <tr><th>MySQL 版本</th><td><?php echo e($dbOk ? $dbVersion : '不可用'); ?></td></tr>
                <tr><th>数据表数量</th><td><?php echo $tableCount; ?> 张</td></tr>
                <tr><th>数据总大小</th><td><?php echo health_bytes($dbSize); ?></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2 class="card-title">磁盘空间</h2>
        <div class="table-wrap">
            <table class="tb">
                <tbody>
                <tr><th style="width:160px">剩余空间</th><td><?php echo $diskFree !== false ? e(health_bytes((float)$diskFree)) : '无法读取'; ?></td></tr>
                <tr><th>总空间</th><td><?php echo $diskTotal ? e(health_bytes((float)$diskTotal)) : '无法读取'; ?></td></tr>
                <tr><th>使用率</th><td><?php echo number_format($diskRate, 1); ?>%</td></tr>
                <tr><th>runtime 日志</th><td><?php echo count($logFiles); ?> 个文件</td></tr>
                </tbody>
            </table>
        </div>
        <div style="height:8px;background:#F2F3F5;border-radius:999px;overflow:hidden;margin-top:12px">
            <div style="height:100%;width:<?php echo min(100, max(0, $diskRate)); ?>%;background:linear-gradient(90deg,#006EFF,#4F46E5);border-radius:999px"></div>
        </div>
    </section>
</div>

<section class="card">
    <h2 class="card-title">健康建议</h2>
    <?php foreach ($suggestions as [$text, $level]):
        $cls = $level === 'err' ? 'alert-error' : ($level === 'warn' ? 'alert-warn' : 'alert-ok');
    ?>
    <div class="alert <?php echo $cls; ?>" style="margin-bottom:10px">
        <img src="../assets/svg/<?php echo $level === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
        <span><?php echo e($text); ?></span>
    </div>
    <?php endforeach; ?>
</section>

<?php if ($logFiles): ?>
<section class="card">
    <h2 class="card-title">运行日志</h2>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>文件</th><th>大小</th><th>最后写入</th></tr></thead>
            <tbody>
            <?php foreach ($logFiles as $log):
                $file = basename($log);
            ?>
            <tr>
                <td class="cell-code"><?php echo e($file); ?></td>
                <td class="cell-sub"><?php echo health_bytes((float)filesize($log)); ?></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i:s', (int)filemtime($log)); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
