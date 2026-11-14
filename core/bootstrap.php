<?php
// 全局引导：会话/数据库/配置/鉴权/CSRF/审计
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

define('ROOT_PATH', dirname(__DIR__));
define('RUNTIME_PATH', ROOT_PATH . '/runtime');
define('PRODUCT_NAME', 'ZeroAuth');
define('PRODUCT_FULLNAME', 'ZeroAuth 云鉴权 软件授权 & 域名授权管理系统');
define('PRODUCT_VERSION', '2.2.4');
define('SYS_VERSION', '2.1.1');
define('PRODUCT_AUTHOR_ORIGINAL', 'APE开发项目组-玄猿工坊');
define('PRODUCT_AUTHOR_DEV', 'MEMZ-LMXDG');
define('PRODUCT_GITHUB', 'https://github.com/lmxdg/zeroauth');
define('PRODUCT_QQ_GROUP', '882138945');

// 合作伙伴（后台顶部广告位 / 关于系统页展示）
define('PARTNER_NAME', '冥雾云');
define('PARTNER_SLOGAN', '高性能云服务器 · 多线 BGP · 多区可选');
define('PARTNER_URL', 'https://www.cnmrnmb.online/aff/UFFQUCLQ');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function client_ip(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = $remote;

    // 真实 IP 获取：站点开启 trust_proxy_ip 后，从代理/CDN 传递的头中解析真实客户端 IP。
    // 覆盖 Cloudflare / 百度云 CDN / 阿里云 CDN / 宝塔反代 / Nginx 反代等常见场景，
    // 支持 IPv4/IPv6、X-Forwarded-For 多级代理链、RFC 7239 Forwarded 头，自动剥离端口。
    $trust = true;
    try {
        if (function_exists('config') && is_file(ROOT_PATH . '/config.php')) {
            $trust = cfg('trust_proxy_ip', '1') === '1';
        }
    } catch (Throwable $ex) {
        $trust = true;
    }
    if ($trust) {
        // 归一化候选值：剥端口、去 IPv6 方括号与引号，输出纯 IP 文本
        $normalize = static function (string $raw): string {
            $raw = trim($raw, " \t\"'");
            if ($raw === '' || strcasecmp($raw, 'unknown') === 0) {
                return '';
            }
            if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $raw, $m)) {
                return $m[1];                       // [2001:db8::1]:443
            }
            if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?$/', $raw, $m)) {
                return $m[1];                       // 1.2.3.4:5678
            }
            if (strpos($raw, ':') !== false && preg_match('/^([0-9a-fA-F:]+)$/', $raw, $m)) {
                return $m[1];                       // 裸 IPv6
            }
            return filter_var($raw, FILTER_VALIDATE_IP) !== false ? $raw : '';
        };
        // 仅接受公网 IP，排除内网/保留段伪造（REMOTE_ADDR 本身是内网时按严格校验处理）
        $isValid = static function (string $c) use ($remote): bool {
            if ($c === '' || filter_var($c, FILTER_VALIDATE_IP) === false) {
                return false;
            }
            // REMOTE_ADDR 为公网时只接受与其同族校验通过且非私有/保留段的候选
            if (filter_var($c, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return true;
            }
            // 候选为内网地址时，仅当与 REMOTE_ADDR 完全一致才可作为兜底（否则视为伪造）
            return $c === $remote;
        };

        $candidates = [];
        // 常见 CDN / 反代传递的“单值真实 IP”头
        foreach ([
            'HTTP_CF_CONNECTING_IP',        // Cloudflare
            'HTTP_CF_TRUE_CLIENT_IP',       // Cloudflare Enterprise
            'HTTP_TRUE_CLIENT_IP',          // Cloudflare / Akamai
            'HTTP_ALI_CDN_REAL_IP',         // 阿里云 CDN
            'HTTP_X_REAL_IP',               // Nginx 反代（proxy_set_header X-Real-IP）
            'HTTP_X_CLIENT_IP',             // 通用
            'HTTP_X_CLUSTER_CLIENT_IP',     // 集群/LB
            'HTTP_CLIENT_IP',               // 通用
            'HTTP_INCAP_CLIENT_IP',         // Incapsula
        ] as $key) {
            if (!empty($_SERVER[$key])) {
                foreach (explode(',', (string)$_SERVER[$key]) as $part) {
                    $candidates[] = $part;
                }
            }
        }
        // X-Forwarded-For：多级代理链，从左到右第一个公网 IP 即客户端真实 IP
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $h) {
                $candidates[] = $h;
            }
        }
        // RFC 7239 Forwarded: for=1.2.3.4;by=example.com（可能带引号/端口/IPv6 方括号）
        if (!empty($_SERVER['HTTP_FORWARDED'])) {
            foreach (explode(',', (string)$_SERVER['HTTP_FORWARDED']) as $h) {
                if (preg_match('/(?:^|;)\s*for\s*=\s*("?)((?:\[)?[0-9a-fA-F:.]+(?:\])?(?::\d+)?|"unknown")\1/i', $h, $m)) {
                    $candidates[] = trim($m[2], '"');
                }
            }
        }

        foreach ($candidates as $c) {
            $c = $normalize((string)$c);
            if ($isValid($c)) {
                $ip = $c;
                break;
            }
        }
    }
    return $cached = $ip;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function base_url(): string
{
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $scriptFile = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = str_replace('\\', '/', ROOT_PATH);
    $basePath = '';
    $resolved = false;

    // 优先用「当前脚本在站点根目录中的相对路径」反推出站点根，兼容后台自定义目录名
    if ($script !== '' && $scriptFile !== '' && str_starts_with($scriptFile, $root)) {
        $rel = substr($scriptFile, strlen($root));
        if ($rel !== '' && str_ends_with($script, $rel)) {
            $basePath = substr($script, 0, -strlen($rel));
            $resolved = true;
        }
    }

    if (!$resolved) {
        $basePath = str_replace('\\', '/', dirname($script));
        if (preg_match('#^(.*?)/(?:core|api|agent|user|plugin|install)(/.*)?$#i', $basePath, $m)) {
            $basePath = $m[1];
        }
    }

    return $scheme . '://' . $host . rtrim($basePath, '/') . '/';
}

function account_avatar_urls(array $acc): array
{
    $qq = preg_replace('/\D/', '', (string)($acc['qq'] ?? ''));
    if ($qq === '') {
        $prefix = explode('@', (string)($acc['email'] ?? ''))[0];
        if (preg_match('/^\d{5,12}$/', $prefix)) {
            $qq = $prefix;
        }
    }
    if ($qq === '') {
        return [];
    }
    return [
        'https://q1.qlogo.cn/g?b=qq&nk=' . $qq . '&s=100',
        'https://q2.qlogo.cn/g?b=qq&nk=' . $qq . '&s=100',
    ];
}

function avatar_img(array $acc, string $class, string $alt = ''): string
{
    $urls = account_avatar_urls($acc);
    if (!$urls) {
        return '<img class="' . e($class) . '" src="../assets/svg/mrtx.svg" alt="' . e($alt) . '">';
    }
    $first = array_shift($urls);
    $urls[] = '../assets/svg/mrtx.svg';
    return '<img class="' . e($class) . '" src="' . e($first) . '" data-fallback="' . e(json_encode($urls)) . '" alt="' . e($alt) . '" referrerpolicy="no-referrer">';
}

function avatar_fallback_script(): string
{
    return '<script>(function(){document.addEventListener("error",function(ev){var t=ev.target;if(t&&t.tagName==="IMG"&&t.dataset.fallback){try{var list=JSON.parse(t.dataset.fallback);if(list.length){t.src=list.shift();t.dataset.fallback=JSON.stringify(list);}else{t.dataset.fallback="";}}catch(e){t.dataset.fallback="";}}},true);})();</script>';
}

function is_api_request(): bool
{
    if (preg_match('#/api/#i', (string)($_SERVER['REQUEST_URI'] ?? ''))) {
        return true;
    }
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
}

/**
 * 系统尚未安装时输出品牌化引导页（API/CLI 场景保持轻量返回）。
 */
function render_not_installed_page(): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "系统尚未安装，请先访问 install/ 完成安装。\n");
        exit(1);
    }

    http_response_code(503);
    if (is_api_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 503, 'msg' => '系统尚未安装，请先完成安装。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    $base = base_url();
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>系统尚未安装 - <?php echo e(PRODUCT_NAME); ?></title>
<link rel="stylesheet" href="<?php echo e($base . 'assets/install.css'); ?>">
</head>
<body class="install-standalone">
<div class="install-ambient" aria-hidden="true"><span></span><span></span><span></span></div>
<div class="install-page not-installed-page">
    <header class="install-header">
        <img class="brand-logo" src="<?php echo e($base . 'assets/img/logo.png'); ?>" alt="logo">
        <div class="brand-text">
            <h1><?php echo e(PRODUCT_FULLNAME); ?></h1>
            <p>安装引导 · V<?php echo e(PRODUCT_VERSION); ?></p>
        </div>
        <span class="install-badge">待安装</span>
    </header>

    <main class="notinstalled-card">
        <section class="notinstalled-main">
            <span class="status-pill"><i></i>系统尚未初始化</span>
            <h2>完成安装后即可开始使用</h2>
            <p class="notinstalled-desc">当前目录缺少 <code>config.php</code>，系统还没有完成初始化。运行安装向导将自动创建数据表、管理员账号与安全配置。</p>
            <ol class="install-steps">
                <li>
                    <span class="step-index">01</span>
                    <div><b>环境检测</b><span>检查 PHP、MySQL、扩展与目录权限</span></div>
                </li>
                <li>
                    <span class="step-index">02</span>
                    <div><b>数据库与管理员</b><span>填写数据库信息，设置管理员账号与后台目录</span></div>
                </li>
                <li>
                    <span class="step-index">03</span>
                    <div><b>自动生成配置</b><span>写入 config.php 与 install.lock，安装完成后自动锁定</span></div>
                </li>
            </ol>
            <div class="notinstalled-actions">
                <a class="btn-primary" href="<?php echo e($base . 'install/'); ?>">开始安装</a>
                <a class="btn-plain" href="<?php echo e($base . 'install/'); ?>">查看环境要求</a>
            </div>
            <p class="notinstalled-note">安装过程约需 1 分钟，请提前准备好 MySQL 数据库信息。</p>
        </section>

        <aside class="notinstalled-visual" aria-hidden="true">
            <div class="visual-ring visual-ring-a"></div>
            <div class="visual-ring visual-ring-b"></div>
            <div class="visual-logo-wrap"><img src="<?php echo e($base . 'assets/img/logo.png'); ?>" alt=""></div>
            <div class="visual-title">ZeroAuth 云鉴权</div>
            <div class="visual-sub">软件授权 · 域名授权 · 安全验证</div>
            <div class="visual-tags"><span>V<?php echo e(PRODUCT_VERSION); ?></span><span>PHP 8.x</span><span>MySQL 5.6+</span></div>
        </aside>
    </main>

    <footer class="install-footer">
        <p>© <?php echo date('Y'); ?> <?php echo e(PRODUCT_AUTHOR_ORIGINAL); ?> · 二次开发：<?php echo e(PRODUCT_AUTHOR_DEV); ?> · 二开请保留版权 · 官方 QQ 群 <?php echo e(PRODUCT_QQ_GROUP); ?></p>
        <p>开源地址：<a href="<?php echo e(PRODUCT_GITHUB); ?>" target="_blank" rel="noopener noreferrer" style="color:#0052D9"><?php echo e(PRODUCT_GITHUB); ?></a></p>
    </footer>
</div>
</body>
</html>
    <?php
    exit;
}

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        if (!is_file(ROOT_PATH . '/config.php')) {
            render_not_installed_page();
        }
        $cfg = require ROOT_PATH . '/config.php';
        if (!is_array($cfg)) {
            http_response_code(500);
            exit('config.php 配置格式错误。');
        }
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config()['db'];
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function &cfg_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $rows = db()->query('SELECT `cfg_key`,`cfg_value` FROM `sys_config`')->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($rows !== false) {
            $cache = $rows;
        }
        require_once ROOT_PATH . '/core/PluginSys.php';
        foreach (plugin_runtime() as $pc) {
            if (isset($pc['cfg']) && is_array($pc['cfg'])) {
                foreach ($pc['cfg'] as $k => $v) {
                    if ((string)$v !== '') {
                        $cache[$k] = (string)$v;
                    }
                }
            }
        }
    }
    return $cache;
}

function cfg(string $key, string $default = ''): string
{
    $cache = cfg_all();
    return $cache[$key] ?? $default;
}

function set_cfg(string $key, string $value, string $remark = ''): void
{
    $stmt = db()->prepare('INSERT INTO `sys_config` (`cfg_key`,`cfg_value`,`remark`,`update_time`) VALUES (?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE `cfg_value`=VALUES(`cfg_value`),`update_time`=VALUES(`update_time`)');
    $stmt->execute([$key, $value, $remark, time()]);
    $cache = &cfg_all();
    $cache[$key] = (string)$value;
}

function site_info(string $key, string $default = ''): string
{
    return cfg('site_' . $key, $default);
}

function site_icon_url(string $prefix = ''): string
{
    $v = cfg('site_ico', '');
    return $v !== '' ? $v : $prefix . 'assets/img/logo.png';
}

function site_logo_url(string $prefix = ''): string
{
    $v = cfg('site_logo', '');
    return $v !== '' ? $v : $prefix . 'assets/img/logo.png';
}

function site_meta_tags(string $prefix = ''): string
{
    $html = '<link rel="icon" href="' . e(site_icon_url($prefix)) . '">' . "\n";
    $kw = trim(cfg('site_keywords', ''));
    $desc = trim(cfg('site_description', ''));
    if ($kw !== '') {
        $html .= '<meta name="keywords" content="' . e(mb_substr($kw, 0, 200)) . '">' . "\n";
    }
    if ($desc !== '') {
        $html .= '<meta name="description" content="' . e(mb_substr($desc, 0, 300)) . '">' . "\n";
    }
    return $html;
}

function site_contacts(): array
{
    return [
        'qq'       => trim(cfg('contact_qq', '')),
        'phone'    => trim(cfg('contact_phone', '')),
        'email'    => trim(cfg('contact_email', '')),
        'qq_group' => trim(cfg('contact_qq_group', PRODUCT_QQ_GROUP)),
    ];
}

function site_contacts_html(): string
{
    $c   = site_contacts();
    $out = [];
    if ($c['qq'] !== '') {
        $out[] = '<a class="foot-contact-item" href="https://wpa.qq.com/msgrd?v=3&site=qq&menu=yes&uin=' . e($c['qq']) . '" target="_blank" rel="noopener noreferrer">QQ：' . e($c['qq']) . '</a>';
    }
    if ($c['phone'] !== '') {
        $out[] = '<span class="foot-contact-item">手机：' . e($c['phone']) . '</span>';
    }
    if ($c['email'] !== '') {
        $out[] = '<a class="foot-contact-item" href="mailto:' . e($c['email']) . '">邮箱：' . e($c['email']) . '</a>';
    }
    if ($c['qq_group'] !== '') {
        $out[] = '<span class="foot-contact-item">QQ群：' . e($c['qq_group']) . '</span>';
    }
    return $out === [] ? '' : implode('', $out);
}

function site_icp(): string
{
    return trim(cfg('site_icp', ''));
}

function site_icp_html(): string
{
    $icp = site_icp();
    return $icp === '' ? '' : '<a class="foot-icp" href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">' . e($icp) . '</a>';
}

function audit(string $module, string $action, int $actorType, int $actorId, string $detail = ''): void
{
    try {
        $stmt = db()->prepare('INSERT INTO `audit_log` (`module`,`action`,`actor_type`,`actor_id`,`detail`,`ip`,`created_at`) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$module, $action, $actorType, $actorId, $detail, client_ip(), time()]);
    } catch (Throwable $ex) {
        @file_put_contents(RUNTIME_PATH . '/audit_error.log', date('Y-m-d H:i:s') . ' ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!isset($_POST['csrf']) || !hash_equals(csrf_token(), (string)$_POST['csrf'])) {
        http_response_code(403);
        exit('非法请求（CSRF 校验失败）');
    }
}

function json_out(int $code, string $msg = 'success', array $data = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => (object)$data], JSON_UNESCAPED_UNICODE);
    exit;
}

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    static $admin = false;
    if ($admin === false) {
        $stmt = db()->prepare('SELECT `id`,`username`,`nickname`,`qq`,`email`,`status` FROM `admin` WHERE `id`=?');
        $stmt->execute([(int)$_SESSION['admin_id']]);
        $admin = $stmt->fetch() ?: null;
    }
    return ($admin && (int)$admin['status'] === 1) ? $admin : null;
}

function admin_require_login(): array
{
    $admin = current_admin();
    if (!$admin) {
        redirect('index.php');
    }
    return $admin;
}

function current_agent(): ?array
{
    if (empty($_SESSION['agent_id'])) {
        return null;
    }
    static $agent = false;
    if ($agent === false) {
        $stmt = db()->prepare('SELECT `id`,`username`,`email`,`balance`,`discount`,`level_id`,`gift_balance`,`status` FROM `agent` WHERE `id`=?');
        $stmt->execute([(int)$_SESSION['agent_id']]);
        $agent = $stmt->fetch() ?: null;
    }
    return ($agent && (int)$agent['status'] === 1) ? $agent : null;
}

function agent_require_login(): array
{
    $agent = current_agent();
    if (!$agent) {
        redirect('index.php');
    }
    return $agent;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = false;
    if ($user === false) {
        $stmt = db()->prepare('SELECT `id`,`username`,`email`,`phone`,`balance`,`real_status`,`real_name`,`status`,`agent_id` FROM `user` WHERE `id`=?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return ($user && (int)$user['status'] === 1) ? $user : null;
}

function user_require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('index.php');
    }
    return $user;
}

function http_get(string $url, int $timeout = 5, array $headers = []): string|false
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        ];
        if ($headers !== []) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);
        if ($err === 0 && $resp !== false) {
            return (string)$resp;
        }
    }
    $ctx = stream_context_create(['http' => [
        'timeout'         => $timeout,
        'ignore_errors'   => true,
        'follow_location' => 1,
        'header'          => implode("\r\n", $headers),
    ]]);
    return @file_get_contents($url, false, $ctx);
}

function http_post(string $url, string $body, array $headers = [], int $timeout = 10): string|false
{
    $headers = $headers ?: ['Content-Type: application/x-www-form-urlencoded'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);
        if ($err === 0 && $resp !== false) {
            return (string)$resp;
        }
    }
    $ctx = stream_context_create(['http' => [
        'method'          => 'POST',
        'timeout'         => $timeout,
        'ignore_errors'   => true,
        'follow_location' => 1,
        'header'          => implode("\r\n", $headers),
        'content'         => $body,
    ]]);
    return @file_get_contents($url, false, $ctx);
}

// 验证码输出前清理：关闭错误回显 + 清空所有输出缓冲，
// 防止 open_basedir 等运行环境产生的 Warning 文本混入图片二进制流（导致验证码乱码/空白）
function captcha_clean_output_buffers(): void
{
    @ini_set('display_errors', '0');
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

// 验证码响应通用头：严禁任何层级的缓存（浏览器/CDN/代理），防止旧响应（如历史版本的 1x1 占位图）被复用
function captcha_no_cache_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// 限流占位图（SVG）：避免前端把空响应/破图显示成“验证码加载失败”
function captcha_busy_output(): void
{
    captcha_clean_output_buffers();
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Retry-After: 30');
    captcha_no_cache_headers();
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="132" height="44" viewBox="0 0 132 44">'
        . '<rect width="132" height="44" fill="#F6F7FB"/>'
        . '<text x="66" y="27" text-anchor="middle" style="font:13px sans-serif;fill:#86909C">请稍后点击刷新</text></svg>';
    header('Content-Length: ' . strlen($svg));
    echo $svg;
    exit;
}

// SVG 验证码兜底输出：GD 缺失或绘制失败时仍能展示可校验的验证码
function captcha_svg_output(string $code): void
{
    captcha_clean_output_buffers();
    header('Content-Type: image/svg+xml; charset=utf-8');
    captcha_no_cache_headers();
    $digits = [];
    $len = strlen($code);
    for ($i = 0; $i < $len; $i++) {
        $digits[] = '<text x="' . (14 + $i * 28) . '" y="' . random_int(22, 34) . '" rotate="' . random_int(-10, 10) . '" style="font:' . random_int(17, 22) . 'px Verdana,DejaVu Sans,sans-serif;font-weight:bold;fill:#37496A">' . $code[$i] . '</text>';
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="132" height="44" viewBox="0 0 132 44">'
        . '<rect width="132" height="44" fill="#F6F7FB"/>'
        . '<path d="M' . random_int(20, 50) . ' ' . random_int(4, 40) . ' Q 66 ' . random_int(-8, 52) . ' ' . random_int(82, 112) . ' ' . random_int(4, 40) . '" stroke="#D5DCED" fill="none"/>'
        . implode('', $digits)
        . '</svg>';
    header('Content-Length: ' . strlen($svg));
    echo $svg;
}

function captcha_render(): void
{
    $code = '';
    for ($i = 0; $i < 4; $i++) {
        $code .= (string)random_int(2, 9);
    }
    $_SESSION['captcha'] = $code;

    // 主路径：使用 GD 生成 PNG 验证码；产物经输出缓冲捕获并做尺寸校验，
    // 若产物异常（如过小的退化图）或任一步失败，自动降级为 SVG，绝不输出空白图
    if (extension_loaded('gd') && function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
        $w = 132; $h = 44;
        $img = @imagecreatetruecolor($w, $h);
        if ($img !== false) {
            $bg   = @imagecolorallocate($img, 246, 247, 251);
            $ink  = @imagecolorallocate($img, 55, 73, 106);
            $soft = @imagecolorallocate($img, 213, 220, 237);
            @imagefilledrectangle($img, 0, 0, $w, $h, $bg);

            // 干扰元素：弧线 + 折线 + 噪点
            @imagearc($img, random_int(30, 100), random_int(10, 34), random_int(70, 110), random_int(28, 48), 0, 360, $soft);
            for ($i = 0; $i < 3; $i++) {
                @imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $soft);
            }
            for ($i = 0; $i < 30; $i++) {
                @imagesetpixel($img, random_int(2, $w - 3), random_int(2, $h - 3), $soft);
            }

            $fonts = [
                'C:\\Windows\\Fonts\\arialbd.ttf',
                'C:\\Windows\\Fonts\\arial.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/System/Library/Fonts/Helvetica.ttc',
            ];
            $font = '';
            foreach ($fonts as $f) {
                // @ 抑制：open_basedir 环境下探测受限路径会触发 Warning，绝不能让其混入图片输出
                if (@is_file($f)) {
                    $font = $f;
                    break;
                }
            }
            for ($i = 0; $i < 4; $i++) {
                $x = 18 + $i * 28;
                if ($font !== '' && function_exists('imagettftext')) {
                    @imagettftext($img, 21, random_int(-8, 8), $x, 31 + random_int(-2, 2), $ink, $font, $code[$i]);
                } else {
                    @imagechar($img, 5, $x + 6, 13 + random_int(-2, 2), $code[$i], $ink);
                }
            }

            $png = '';
            if (ob_start()) {
                @imagepng($img);
                $png = (string)ob_get_clean();
            }
            @imagedestroy($img);

            // 132x44 的正常 PNG 远大于 100 字节；历史上 1x1 占位图仅 70 字节，据此拦截一切退化产物
            if ($png !== '' && strlen($png) > 100) {
                captcha_clean_output_buffers();
                header('Content-Type: image/png');
                captcha_no_cache_headers();
                header('Content-Length: ' . strlen($png));
                echo $png;
                exit;
            }
        }
    }

    captcha_svg_output($code);
    exit;
}

function plugin_enabled(string $code): bool
{
    require_once ROOT_PATH . '/core/PluginSys.php';
    return plugin_status_enabled($code);
}

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_take(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function invite_code_gen(): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < 10; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function invite_code_ensure(int $agentId): string
{
    if ($agentId <= 0) {
        return '';
    }
    $stmt = db()->prepare('SELECT `invite_code` FROM `agent` WHERE `id`=?');
    $stmt->execute([$agentId]);
    $code = (string)$stmt->fetchColumn();
    if ($code !== '') {
        return $code;
    }
    try {
        for ($i = 0; $i < 5; $i++) {
            $try = invite_code_gen();
            $chk = db()->prepare('SELECT `id` FROM `agent` WHERE `invite_code`=? AND `id`<>?');
            $chk->execute([$try, $agentId]);
            if (!$chk->fetch()) {
                db()->prepare('UPDATE `agent` SET `invite_code`=? WHERE `id`=?')->execute([$try, $agentId]);
                return $try;
            }
        }
    } catch (Throwable $ex) {
    }
    return '';
}

function render_pagination(int $total, int $page, int $perPage, string $baseQuery): void
{
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1) {
        return;
    }
    $page  = max(1, min($page, $pages));
    $win   = 2;     $link  = function (int $p) use ($baseQuery): string {
        return e($baseQuery . 'page=' . $p);
    };

    echo '<div class="pagination">';
    if ($page > 1) {
        echo '<a class="page-btn" href="' . $link($page - 1) . '">上一页</a>';
    } else {
        echo '<span class="page-btn disabled">上一页</span>';
    }

    $shown = [];
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= $win) {
            $shown[] = $i;
        }
    }

    $prev = 0;
    foreach ($shown as $i) {
        if ($i - $prev > 1) {
            $jump = $i > $page ? min($pages, $page + 3) : max(1, $page - 3);
            echo '<a class="page-btn" href="' . $link($jump) . '" title="跳转">...</a>';
        }
        if ($i === $page) {
            echo '<span class="page-btn active">' . $i . '</span>';
        } else {
            echo '<a class="page-btn" href="' . $link($i) . '">' . $i . '</a>';
        }
        $prev = $i;
    }

    if ($page < $pages) {
        echo '<a class="page-btn" href="' . $link($page + 1) . '">下一页</a>';
    } else {
        echo '<span class="page-btn disabled">下一页</span>';
    }
    echo '<span class="page-info">共 ' . $total . ' 条 / ' . $pages . ' 页</span>';
    echo '</div>';
}

require_once ROOT_PATH . '/core/schema_upgrade.php';
schema_upgrade_boot();

require_once ROOT_PATH . '/core/IpGeo.php';
require_once ROOT_PATH . '/core/Sms.php';
require_once ROOT_PATH . '/core/SdkTemplates.php';
require_once ROOT_PATH . '/core/RemoteNotice.php';
require_once ROOT_PATH . '/core/Ruin.php';
require_once ROOT_PATH . '/core/UpdateCheck.php';

// 定时跑路惰性触发：任意请求进入时检查是否到达设定时间（内部自校验，异常静默跳过）
ruin_tick();
