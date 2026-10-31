<?php
// 安装向导（环境检测/建库/写入配置，含安装锁）
declare(strict_types=1);

session_start();

define('ROOT_PATH', dirname(__DIR__));
define('LOCK_FILE', ROOT_PATH . '/install.lock');
define('CONFIG_FILE', ROOT_PATH . '/config.php');
define('SCHEMA_FILE', __DIR__ . '/schema.sql');
define('RUNTIME_PATH', ROOT_PATH . '/runtime');
define('PRODUCT_FULLNAME', 'ZeroAuth 软件授权 & 域名授权管理系统');
define('PRODUCT_VERSION', '2.0.0');

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function currentStep(): int
{
    return max(1, min(6, (int)($_GET['step'] ?? 1)));
}

function csrfToken(): string
{
    if (empty($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['install_csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function checkCsrf(): void
{
    if (!isset($_POST['csrf']) || !hash_equals(csrfToken(), (string)$_POST['csrf'])) {
        die('非法请求（CSRF 校验失败），请返回重试。');
    }
}

$installed = is_file(LOCK_FILE);
$step = currentStep();

function checkEnv(): array
{
    $items = [];

    $ver = PHP_VERSION;
    $ok = version_compare($ver, '8.0.0', '>=') && version_compare($ver, '8.2.99', '<=');
    $items[] = ['label' => 'PHP 版本（要求 8.0 ~ 8.2）', 'value' => $ver, 'ok' => $ok, 'required' => true];

    foreach (['pdo' => 'PDO 扩展', 'pdo_mysql' => 'PDO MySQL 驱动', 'json' => 'JSON 扩展', 'mbstring' => 'MBString 扩展'] as $ext => $label) {
        $items[] = ['label' => $label, 'value' => extension_loaded($ext) ? '已加载' : '未加载', 'ok' => extension_loaded($ext), 'required' => true];
    }

    foreach (['curl' => 'cURL（支付/邮件需要）', 'zip' => 'Zip（SDK打包下载）', 'openssl' => 'OpenSSL（安全签名）', 'gd' => 'GD（验证码）'] as $ext => $label) {
        $items[] = ['label' => $label, 'value' => extension_loaded($ext) ? '已加载' : '未加载', 'ok' => extension_loaded($ext), 'required' => false];
    }

    if (!is_dir(RUNTIME_PATH)) {
        @mkdir(RUNTIME_PATH, 0755, true);
    }
    $items[] = ['label' => 'runtime/ 目录可写（日志、缓存）', 'value' => is_writable(RUNTIME_PATH) ? '可写' : '不可写', 'ok' => is_writable(RUNTIME_PATH), 'required' => true];
    $items[] = ['label' => '根目录可写（生成 config.php / install.lock / 后台目录）', 'value' => is_writable(ROOT_PATH) ? '可写' : '不可写', 'ok' => is_writable(ROOT_PATH), 'required' => true];
    $items[] = ['label' => '建表 SQL 文件存在', 'value' => is_file(SCHEMA_FILE) ? '存在' : '缺失', 'ok' => is_file(SCHEMA_FILE), 'required' => true];

    return $items;
}

$envItems = checkEnv();
$envAllRequiredOk = !array_filter($envItems, fn($i) => $i['required'] && !$i['ok']);

$reservedDirs = ['install', 'api', 'assets', 'user', 'agent', 'plugin', 'runtime', 'core', 'email_tpl', 'static', 'public', 'upload', 'uploads', 'data', 'config', 'admin'];

$_SESSION['install_data'] = $_SESSION['install_data'] ?? [];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'db') {
        $db = [
            'host'    => trim((string)($_POST['db_host'] ?? '127.0.0.1')),
            'port'    => (int)($_POST['db_port'] ?? 3306),
            'name'    => trim((string)($_POST['db_name'] ?? '')),
            'user'    => trim((string)($_POST['db_user'] ?? '')),
            'pass'    => (string)($_POST['db_pass'] ?? ''),
            'prefix'  => '',
        ];
        if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
            $error = '请完整填写数据库地址、数据库名与用户名。';
        } else {
            try {
                $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $serverVer = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
                if (version_compare($serverVer, '5.6.0', '<')) {
                    $error = "数据库版本 {$serverVer} 过低，要求 MySQL 5.6 ~ 5.7。";
                } else {
                    $_SESSION['install_data']['db'] = $db;
                    header('Location: ?step=3');
                    exit;
                }
            } catch (PDOException $ex) {
                $error = '数据库连接失败：' . $ex->getMessage();
            }
        }
        $step = 2;
    }

    if ($action === 'admin') {
        $username = trim((string)($_POST['admin_user'] ?? ''));
        $password = (string)($_POST['admin_pass'] ?? '');
        $repass   = (string)($_POST['admin_repass'] ?? '');
        $email    = trim((string)($_POST['admin_email'] ?? ''));
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{3,19}$/', $username)) {
            $error = '管理员账号需以字母开头，4~20位字母、数字或下划线。';
        } elseif (strlen($password) < 8) {
            $error = '密码长度至少 8 位。';
        } elseif ($password !== $repass) {
            $error = '两次输入的密码不一致。';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '管理员邮箱格式不正确。';
        } else {
            $_SESSION['install_data']['admin'] = compact('username', 'password', 'email');
            header('Location: ?step=4');
            exit;
        }
        $step = 3;
    }

    if ($action === 'admindir') {
        $dir = trim((string)($_POST['admin_dir'] ?? ''));
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,29}$/', $dir)) {
            $error = '后台目录名需以字母开头，3~30位字母、数字或下划线。';
        } elseif (in_array(strtolower($dir), $reservedDirs, true)) {
            $error = '该目录名为系统保留目录，请更换（保留：' . implode('、', $reservedDirs) . '）。';
        } elseif (is_dir(ROOT_PATH . '/' . $dir)) {
            $error = "根目录下已存在同名目录 {$dir}/，请更换。";
        } else {
            $_SESSION['install_data']['admin_dir'] = $dir;
            header('Location: ?step=5');
            exit;
        }
        $step = 4;
    }

    if ($action === 'install') {
        $data = $_SESSION['install_data'];
        $result = doInstall($data);
        if ($result === true) {
            header('Location: ?step=6');
            exit;
        }
        $error = is_string($result) ? $result : '安装失败，请检查配置后重试。';
        $step = 5;
    }
}

function copyDir(string $src, string $dst): bool
{
    if (!is_dir($src)) {
        return false;
    }
    if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
        return false;
    }
    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            if (!copyDir($s, $d)) {
                return false;
            }
        } elseif (!@copy($s, $d)) {
            return false;
        }
    }
    return true;
}

function seedSdkTemplate(PDO $pdo): void
{
    $content = <<<'SDKTPL'
<?php
class LicenseSdkV2
{
        private string $appKey = '{{app_key}}';
    private string $appId  = '{{app_id}}';

        private string $apiBase = 'http://your-auth-domain.com';

        private static ?array $secretCache = null;
    private static int $cacheExpireAt = 0;
    private int $cacheTtl = 1800;

        public function verify(string $licenseKey = ''): array
    {
        $secret = $this->fetchSecret();
        if ($secret === null) {
            return ['ok' => false, 'result' => 'fail', 'reason' => 'secret_fetch_failed', 'msg' => '密钥拉取失败'];
        }
        [$domain, $serverIp] = $this->siteInfo();
        $ts   = time();
        $sign = hash_hmac('sha256', implode("\n", ['v2', $this->appKey, $licenseKey, $domain, $serverIp, (string)$ts]), (string)$secret['appsecret']);

        $resp = $this->httpPostJson($this->apiBase . '/api/license/verify', [
            'appKey'      => $this->appKey,
            'licenseKey'  => $licenseKey,
            'domain'      => $domain,
            'serverIp'    => $serverIp,
            'timestamp'   => $ts,
            'signVersion' => 'v2',
            'sign'        => $sign,
        ]);
        if ($resp === null) {
            return ['ok' => false, 'result' => 'fail', 'reason' => 'network_error', 'msg' => '授权服务不可达'];
        }
        $result = (string)($resp['data']['result'] ?? 'fail');
        return [
            'ok'     => $result === 'pass',
            'result' => $result,
            'reason' => (string)($resp['data']['reason'] ?? ''),
            'msg'    => (string)($resp['msg'] ?? ''),
            'data'   => is_array($resp['data'] ?? null) ? $resp['data'] : [],
        ];
    }

        public function versionCheck(string $currentVersion, string $licenseKey = ''): array
    {
        $secret = $this->fetchSecret();
        if ($secret === null) {
            return ['ok' => false, 'msg' => '密钥拉取失败'];
        }
        [$domain, $serverIp] = $this->siteInfo();
        $ts   = time();
        $sign = hash_hmac('sha256', implode("\n", ['v2', $this->appKey, $currentVersion, $licenseKey, $domain, $serverIp, (string)$ts]), (string)$secret['appsecret']);

        $resp = $this->httpPostJson($this->apiBase . '/api/app/version/check', [
            'appKey'         => $this->appKey,
            'currentVersion' => $currentVersion,
            'licenseKey'     => $licenseKey,
            'domain'         => $domain,
            'serverIp'       => $serverIp,
            'timestamp'      => $ts,
            'signVersion'    => 'v2',
            'sign'           => $sign,
        ]);
        if ($resp === null) {
            return ['ok' => false, 'msg' => '授权服务不可达'];
        }
        if ((int)($resp['code'] ?? 0) !== 200) {
            return ['ok' => false, 'result' => (string)($resp['data']['result'] ?? 'fail'), 'msg' => (string)($resp['msg'] ?? '版本检查失败')];
        }
        $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        return ['ok' => true, 'hasUpdate' => !empty($data['hasUpdate']), 'data' => $data];
    }

        public function download(string $downloadUrl, string $savePath, string $expectMd5 = '', int $expectBytes = 0): bool
    {
        $ok = false;
        if (function_exists('curl_init')) {
            $fp = fopen($savePath, 'wb');
            if ($fp !== false) {
                $ch = curl_init($downloadUrl);
                curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 300, CURLOPT_FOLLOWLOCATION => true]);
                curl_exec($ch);
                $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && curl_errno($ch) === 0;
                curl_close($ch);
                fclose($fp);
            }
        }
                if (!$ok && ini_get('allow_url_fopen')) {
            $raw = @file_get_contents($downloadUrl);
            $ok  = $raw !== false;
            if ($ok) {
                file_put_contents($savePath, $raw);
            }
        }
        if (!$ok || !is_file($savePath)) {
            return false;
        }
        if ($expectBytes > 0 && (int)filesize($savePath) !== $expectBytes) {
            @unlink($savePath);
            return false;
        }
        if ($expectMd5 !== '' && strcasecmp(md5_file($savePath), $expectMd5) !== 0) {
            @unlink($savePath);
            return false;
        }
        return true;
    }

        public function fetchSecret(): ?array
    {
        $now = time();
        if (self::$secretCache !== null && $now < self::$cacheExpireAt) {
            return self::$secretCache;
        }

        $params = [
            'app_id'      => $this->appId,
            'domain'      => $_SERVER['HTTP_HOST'] ?? '',
            'device_code' => $this->deviceCode(),
            'timestamp'   => $now,
            'nonce'       => bin2hex(random_bytes(8)),
        ];
        ksort($params);
        $params['sign'] = hash_hmac('sha256', http_build_query($params), $this->appId);

        $resp = $this->httpGet($this->apiBase . '/api/get_secret.php?' . http_build_query($params));
        if ($resp === null || ($resp['code'] ?? 0) !== 200) {
            return null;
        }

        $data = $resp['data'];
        $ttl  = (int)($data['cache_ttl'] ?? $this->cacheTtl);
        self::$secretCache   = $data;
        self::$cacheExpireAt = $now + $ttl;
        return $data;
    }

        private function siteInfo(): array
    {
        $domain   = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        $serverIp = (string)($_SERVER['SERVER_ADDR'] ?? '');
        if ($serverIp === '') {
            $serverIp = (string)(gethostbyname((string)gethostname()) ?: '');
        }
        return [$domain, $serverIp];
    }

        private function deviceCode(): string
    {
        $raw = php_uname('m') . '|' . php_uname('s') . '|' . php_uname('r') . '|' . ($_SERVER['SERVER_NAME'] ?? '');
        return hash('sha256', $raw);
    }

        private function httpGet(string $url): ?array
    {
        $raw = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
            $raw = curl_exec($ch);
            $err = curl_errno($ch);
            curl_close($ch);
            if ($err !== 0 || $raw === false) {
                $raw = false;
            }
        }
        if ($raw === false && ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $raw = @file_get_contents($url, false, $ctx);
        }
        if ($raw === false || $raw === '') {
            return null;
        }
        return json_decode($raw, true);
    }

        private function httpPostJson(string $url, array $body): ?array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $raw  = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($json)],
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $raw = curl_exec($ch);
            $err = curl_errno($ch);
            curl_close($ch);
            if ($err !== 0 || $raw === false) {
                $raw = false;
            }
        }
        if ($raw === false && ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 8, 'ignore_errors' => true, 'header' => "Content-Type: application/json\r\n", 'content' => $json]]);
            $raw = @file_get_contents($url, false, $ctx);
        }
        if ($raw === false || $raw === '') {
            return null;
        }
        return json_decode($raw, true);
    }
}


SDKTPL;

    $count = (int)$pdo->query('SELECT COUNT(*) FROM `sdk_template`')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO `sdk_template` (`name`,`language`,`content`,`status`,`created_at`,`update_time`) VALUES (?,?,?,1,?,?)');
        $stmt->execute(['PHP 授权 SDK（V2 新协议）', 'php', $content, time(), time()]);
    }
}

function doInstall(array $data): bool|string
{
    if (empty($data['db']) || empty($data['admin']) || empty($data['admin_dir'])) {
        return '安装数据不完整，请按步骤重新填写。';
    }
    $db = $data['db'];

    try {
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $ex) {
        return '数据库连接失败：' . $ex->getMessage();
    }

    $sql = file_get_contents(SCHEMA_FILE);
    if ($sql === false || trim($sql) === '') {
        return '读取建表 SQL 文件失败。';
    }
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    try {
        foreach ($statements as $st) {
            if ($st !== '') {
                $pdo->exec($st);
            }
        }
    } catch (PDOException $ex) {
        return '建表失败：' . $ex->getMessage();
    }

    $admin = $data['admin'];
    try {
        $stmt = $pdo->prepare('INSERT INTO `admin` (`username`,`password`,`email`,`status`,`created_at`) VALUES (?,?,?,?,?)');
        $stmt->execute([$admin['username'], password_hash($admin['password'], PASSWORD_BCRYPT), $admin['email'], 1, time()]);
    } catch (PDOException $ex) {
        return '写入管理员账号失败：' . $ex->getMessage();
    }

    $now = time();
    $defaults = [
        ['secret_api_enabled',   '1',    '密钥下发接口全局开关 1开启 0关闭'],
        ['secret_session_ttl',   '1800', '密钥会话有效时长（秒），默认30分钟'],
        ['sdk_cache_ttl',        '1800', 'SDK 客户端内存缓存 TTL（秒），默认30分钟'],
        ['secret_alert_threshold','60',  '密钥接口单IP高频告警阈值（次/分钟）'],
        ['sign_time_window',     '300',  '签名时间窗口（秒）'],
        ['max_login_fail',       '5',    '后台登录最大失败次数（超过锁定）'],
        ['realname_required',    '0',    '购买产品是否强制实名 0否 1是'],
        ['smtp_host',            '',     'SMTP 服务器'],
        ['smtp_port',            '465',  'SMTP 端口'],
        ['smtp_user',            '',     'SMTP 账号'],
        ['smtp_pass',            '',     'SMTP 密码'],
        ['smtp_from_name',       'ZeroAuth', '发件人名称'],
        ['site_name',            'ZeroAuth 授权管理系统', '站点名称'],
        ['contact_qq_group',     '1125324975', '官方QQ群'],
        ['register_captcha',     '1',   '注册验证码开关 1开启 0关闭'],
        ['trust_proxy_ip',       '1',   '真实IP获取 1开启 0关闭'],
    ];
    try {
        $stmt = $pdo->prepare('INSERT IGNORE INTO `sys_config` (`cfg_key`,`cfg_value`,`remark`,`update_time`) VALUES (?,?,?,?)');
        foreach ($defaults as $row) {
            $stmt->execute([$row[0], $row[1], $row[2], $now]);
        }
    } catch (PDOException $ex) {
        return '写入默认配置失败：' . $ex->getMessage();
    }

    $adminDir = ROOT_PATH . '/' . $data['admin_dir'];
    if (is_dir($adminDir)) {
        return "根目录下已存在同名目录 {$data['admin_dir']}/，请返回更换目录名。";
    }
    if (!copyDir(ROOT_PATH . '/admin', $adminDir)) {
        return "创建后台目录 {$data['admin_dir']}/ 并复制后台页面失败，请检查根目录写权限。";
    }

    try {
        seedSdkTemplate($pdo);
    } catch (PDOException $ex) {
        return '预置 SDK 模板失败：' . $ex->getMessage();
    }

    $configPhp = "<?php\n"
        . "// 自动生成于安装程序 " . date('Y-m-d H:i:s') . "\n"
        . "// ZeroAuth 软件授权 & 域名授权管理系统\n"
        . "// 原作者：APE开发项目组-玄猿工坊 · 修改：MEMZ-LMXDG · 二开请保留版权\n"
        . "return [\n"
        . "    'version'   => '" . PRODUCT_VERSION . "',\n"
        . "    'db'        => [\n"
        . "        'host' => '" . addslashes($db['host']) . "',\n"
        . "        'port' => " . (int)$db['port'] . ",\n"
        . "        'name' => '" . addslashes($db['name']) . "',\n"
        . "        'user' => '" . addslashes($db['user']) . "',\n"
        . "        'pass' => '" . addslashes($db['pass']) . "',\n"
        . "        'charset' => 'utf8mb4',\n"
        . "    ],\n"
        . "    'admin_dir' => '" . addslashes($data['admin_dir']) . "',\n"
        . "];\n";
    if (@file_put_contents(CONFIG_FILE, $configPhp) === false) {
        return '生成配置文件 config.php 失败，请检查根目录写权限。';
    }
    @chmod(CONFIG_FILE, 0644);

    $lockContent = 'product: ' . PRODUCT_FULLNAME . "\ninstalled_at: " . date('Y-m-d H:i:s') . "\nadmin_dir: " . $data['admin_dir'] . "\n"
        . 'author: 原作者 ' . 'APE开发项目组-玄猿工坊' . ' · 修改 MEMZ-LMXDG' . "\n";
    if (@file_put_contents(LOCK_FILE, $lockContent) === false) {
        return '写入安装锁 install.lock 失败，请检查根目录写权限。';
    }

    @file_put_contents(RUNTIME_PATH . '/index.html', '');

    return true;
}

$steps = [1 => '环境检测', 2 => '数据库配置', 3 => '管理员账号', 4 => '后台目录', 5 => '执行安装', 6 => '安装完成'];
$data  = $_SESSION['install_data'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>系统安装 - ZeroAuth 软件授权 &amp; 域名授权管理系统 V<?php echo PRODUCT_VERSION; ?></title>
<link rel="stylesheet" href="../assets/install.css">
</head>
<body>
<div class="install-page">

    <header class="install-header">
        <img class="brand-logo" src="../assets/img/zeroauth-logo.png" alt="logo">
        <div class="brand-text">
            <h1>ZeroAuth 软件授权 &amp; 域名授权管理系统</h1>
            <p>数据库安装向导 · V<?php echo PRODUCT_VERSION; ?></p>
        </div>
    </header>

    <?php if ($installed): ?>
    <div class="lock-panel">
        <img src="../assets/svg/warn-circle.svg" alt="" class="lock-icon">
        <h2>系统已安装，安装向导已锁定</h2>
        <p>检测到根目录下存在 <code>install.lock</code> 锁文件，为保障系统安全已禁止重新安装。</p>
        <p class="lock-tip">如需重新安装，请通过 FTP 或文件管理器删除根目录下的 <code>install.lock</code> 文件后再访问本页面。</p>
    </div>
    </div></body></html>
    <?php exit; endif; ?>

    <nav class="step-bar">
        <?php foreach ($steps as $i => $name): ?>
        <div class="step-item <?php echo $i < $step ? 'done' : ($i === $step ? 'active' : ''); ?>">
            <span class="step-no">
                <?php if ($i < $step): ?>
                    <img src="../assets/svg/check-circle.svg" alt="" class="step-done-icon">
                <?php else: echo $i; endif; ?>
            </span>
            <span class="step-name"><?php echo e($name); ?></span>
        </div>
        <?php if ($i < 6): ?><span class="step-line"></span><?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php if ($error !== ''): ?>
    <div class="alert-error">
        <img src="../assets/svg/warn-circle.svg" alt="" class="alert-icon">
        <span><?php echo e($error); ?></span>
    </div>
    <?php endif; ?>

    <main class="install-body">

    <?php if ($step === 1): ?>
    <section class="card">
        <h2 class="card-title">环境检测</h2>
        <p class="card-desc">安装程序将检测服务器环境是否满足系统运行要求，全部必需项通过后才能继续。</p>
        <table class="env-table">
            <thead><tr><th>检测项目</th><th>当前状态</th><th>检测结果</th></tr></thead>
            <tbody>
            <?php foreach ($envItems as $item): ?>
            <tr class="<?php echo $item['ok'] ? '' : ($item['required'] ? 'row-fail' : 'row-warn'); ?>">
                <td><?php echo e($item['label']); ?></td>
                <td><?php echo e($item['value']); ?></td>
                <td>
                    <?php if ($item['ok']): ?>
                        <span class="tag tag-ok"><img src="../assets/svg/check-circle.svg" alt="">通过</span>
                    <?php elseif ($item['required']): ?>
                        <span class="tag tag-fail"><img src="../assets/svg/close-circle.svg" alt="">未通过</span>
                    <?php else: ?>
                        <span class="tag tag-warn"><img src="../assets/svg/warn-circle.svg" alt="">建议安装</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="card-actions">
            <?php if ($envAllRequiredOk): ?>
            <a class="btn-primary" href="?step=2">下一步：数据库配置</a>
            <?php else: ?>
            <span class="btn-disabled">存在未通过的必需项，无法继续安装</span>
            <?php endif; ?>
        </div>
    </section>

    <?php elseif ($step === 2):
        $db = $data['db'] ?? []; ?>
    <section class="card">
        <h2 class="card-title">数据库配置</h2>
        <p class="card-desc">填写 MySQL 5.6 ~ 5.7 数据库连接信息，安装程序将自动完成全部 16 张数据表建表。</p>
        <form method="post" action="?step=2" class="form" autocomplete="off">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="db">
            <div class="form-item">
                <label class="form-label">数据库地址</label>
                <input class="form-input" type="text" name="db_host" value="<?php echo e($db['host'] ?? '127.0.0.1'); ?>" required>
            </div>
            <div class="form-item">
                <label class="form-label">端口</label>
                <input class="form-input" type="number" name="db_port" value="<?php echo e((string)($db['port'] ?? 3306)); ?>" placeholder="3306" required>
            </div>
            <div class="form-item">
                <label class="form-label">数据库名</label>
                <input class="form-input" type="text" name="db_name" value="<?php echo e($db['name'] ?? ''); ?>" placeholder="请输入数据库名" required>
            </div>
            <div class="form-item">
                <label class="form-label">数据库用户名</label>
                <input class="form-input" type="text" name="db_user" value="<?php echo e($db['user'] ?? ''); ?>" placeholder="请输入数据库用户名" required>
            </div>
            <div class="form-item">
                <label class="form-label">数据库密码</label>
                <input class="form-input" type="password" name="db_pass" value="<?php echo e($db['pass'] ?? ''); ?>" placeholder="请输入数据库密码" required>
            </div>
            <div class="card-actions">
                <a class="btn-plain" href="?step=1">上一步</a>
                <button class="btn-primary" type="submit">测试连接并继续</button>
            </div>
        </form>
    </section>

    <?php elseif ($step === 3):
        $admin = $data['admin'] ?? []; ?>
    <section class="card">
        <h2 class="card-title">设置管理员账号</h2>
        <p class="card-desc">超级管理员拥有全部权限，密码使用加盐哈希算法加密存储。</p>
        <form method="post" action="?step=3" class="form" autocomplete="off">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="admin">
            <div class="form-item">
                <label class="form-label">管理员账号</label>
                <input class="form-input" type="text" name="admin_user" value="<?php echo e($admin['username'] ?? ''); ?>" placeholder="请输入字母开头+数字或下划线，4~20位账号" required>
            </div>
            <div class="form-item">
                <label class="form-label">登录密码</label>
                <input class="form-input" type="password" name="admin_pass" placeholder="请输入至少 8 位密码" required>
            </div>
            <div class="form-item">
                <label class="form-label">确认密码</label>
                <input class="form-input" type="password" name="admin_repass" placeholder="请确认登录密码" required>
            </div>
            <div class="form-item">
                <label class="form-label">管理员邮箱 <span class="optional">（用于接收告警邮件）</span></label>
                <input class="form-input" type="text" name="admin_email" value="<?php echo e($admin['email'] ?? ''); ?>" placeholder="请输入管理员邮箱">
            </div>
            <div class="card-actions">
                <a class="btn-plain" href="?step=2">上一步</a>
                <button class="btn-primary" type="submit">下一步：后台目录</button>
            </div>
        </form>
    </section>

    <?php elseif ($step === 4): ?>
    <section class="card">
        <h2 class="card-title">自定义后台目录</h2>
        <p class="card-desc">自定义管理员后台文件夹名称，防止固定路径被恶意扫描；安装完成后可在后台随时修改目录名称，系统自动迁移目录文件并更新路由。</p>
        <form method="post" action="?step=4" class="form" autocomplete="off">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="admindir">
            <div class="form-item">
                <label class="form-label">后台目录名称</label>
                <div class="input-group">
                    <input class="form-input" type="text" name="admin_dir" placeholder="请输入后台目录名称" required>
                    <span class="input-suffix">/</span>
                </div>
            </div>
            <div class="form-tips">
                <p>· 以字母开头，3~30 位字母、数字或下划线</p>
                <p>· 禁止使用系统保留目录：<?php echo e(implode('、', $reservedDirs)); ?></p>
                <p>· 请牢记该目录名，安装后后台入口为：http://您的域名/<?php echo '目录名'; ?>/</p>
            </div>
            <div class="card-actions">
                <a class="btn-plain" href="?step=3">上一步</a>
                <button class="btn-primary" type="submit">下一步：执行安装</button>
            </div>
        </form>
    </section>

    <?php elseif ($step === 5):
        $db = $data['db'] ?? [];
        $admin = $data['admin'] ?? []; ?>
    <section class="card">
        <h2 class="card-title">确认安装信息</h2>
        <p class="card-desc">请核对以下配置信息，确认无误后开始安装。安装过程将自动建表、写入管理员账号并生成安装锁。</p>
        <table class="env-table">
            <tbody>
            <tr><th>数据库地址</th><td><?php echo e(($db['host'] ?? '') . ':' . ($db['port'] ?? '')); ?></td></tr>
            <tr><th>数据库名</th><td><?php echo e($db['name'] ?? ''); ?></td></tr>
            <tr><th>管理员账号</th><td><?php echo e($admin['username'] ?? ''); ?></td></tr>
            <tr><th>后台目录</th><td><?php echo e(($data['admin_dir'] ?? '') . '/'); ?></td></tr>
            <tr><th>安装内容</th><td>16 张数据表 · 默认系统配置 · 自定义后台目录 · config.php · install.lock</td></tr>
            </tbody>
        </table>
        <form method="post" action="?step=5">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="install">
            <div class="card-actions">
                <a class="btn-plain" href="?step=4">上一步</a>
                <button class="btn-primary" type="submit">开始安装</button>
            </div>
        </form>
    </section>

    <?php else: ?>
    <section class="card">
        <div class="done-head">
            <img src="../assets/svg/check-circle.svg" alt="" class="done-icon">
            <h2 class="card-title">安装完成</h2>
        </div>
        <div class="alert-ok">
            <span>系统已成功安装，<code>install.lock</code> 锁文件已生成，安装页面已自动锁定。</span>
        </div>
        <table class="env-table">
            <tbody>
            <tr><th>后台入口</th><td><code>http://您的域名/<?php echo e($data['admin_dir'] ?? ''); ?>/</code>（后台功能于阶段2交付）</td></tr>
            <tr><th>数据表</th><td>16 张核心表已创建完成</td></tr>
            <tr><th>默认配置</th><td>密钥会话有效期 1800 秒 · SDK 缓存 TTL 1800 秒 · 密钥接口告警阈值 60 次/分钟</td></tr>
            <tr><th>安全提醒</th><td>建议删除本 install 目录或通过服务器规则禁止外部访问</td></tr>
            </tbody>
        </table>
        <div class="card-actions">
            <a class="btn-primary" href="../">返回首页</a>
        </div>
    </section>
    <?php endif; ?>

    </main>

    <footer class="install-footer">
        <p>ZeroAuth 软件授权 &amp; 域名授权管理系统 V<?php echo PRODUCT_VERSION; ?> · 原生 PHP <?php echo e(PHP_VERSION); ?> · MySQL 5.6~5.7</p>
        <p>原作者：APE开发项目组-玄猿工坊 · 修改：MEMZ-LMXDG · 官方QQ群：1125324975 · 二开请保留版权</p>
    </footer>
</div>
</body>
</html>
