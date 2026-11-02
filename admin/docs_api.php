<?php
// 管理员后台 - 开发文档（完整版）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

function doc_pre(string $code): string
{
    return '<pre style="background:#f6f8fa;border:1px solid #e5e6eb;border-radius:4px;padding:12px 14px;font-size:12.5px;line-height:1.7;overflow:auto;margin:8px 0 0;color:#24292f">' . e($code) . '</pre>';
}

$base = rtrim(base_url(), '/');

admin_header('开发文档', 'docs_api', $admin);
?>

<section class="card">
    <h2 class="card-title">开发文档 · 完整版</h2>
    <p class="card-desc">本文档覆盖授权校验、卡密设备校验、密钥下发、云变量、版本更新、订单状态、公钥与加密签名、SDK 模板、插件与主题开发。接口地址均以当前站点 <code><?php echo e($base); ?></code> 为基准。</p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
        <?php
        $navItems = [
            '#sec-overview' => '接口总览',
            '#sec-common' => '通用约定',
            '#sec-prepare' => '准备工作',
            '#sec-license' => '授权校验',
            '#sec-auth' => '卡密设备校验',
            '#sec-secret' => '密钥下发',
            '#sec-cloud' => '云变量',
            '#sec-version' => '版本更新',
            '#sec-order' => '订单状态',
            '#sec-crypto' => '加密与签名',
            '#sec-errors' => '错误码',
            '#sec-sdk' => 'SDK 模板',
            '#sec-plugin' => '插件开发',
            '#sec-theme' => '主题开发',
            '#sec-debug' => '联调排错',
        ];
        foreach ($navItems as $href => $label) {
            echo '<a href="' . e($href) . '" style="font-size:13px;padding:4px 10px;border:1px solid #E5E6EB;border-radius:999px;background:#FAFBFC;color:#4E5969">' . e($label) . '</a>';
        }
        ?>
    </div>
</section>

<section class="card" id="sec-overview">
    <h2 class="card-title">一、接口总览</h2>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>接口</th><th>方法</th><th>鉴权方式</th><th>用途</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">/api/license/verify</td><td>POST</td><td>AppSecret + HMAC-SHA256 v2</td><td>新版授权校验（域名 / 泛域名 / IP / 卡密），推荐新项目使用</td></tr>
            <tr><td class="cell-code">/api/auth.php</td><td>POST</td><td>AppSecret + 参数签名</td><td>卡密 + 域名 + 设备校验，兼容旧版 SDK</td></tr>
            <tr><td class="cell-code">/api/get_secret.php</td><td>POST</td><td>AppSecret + 参数签名</td><td>域名与设备双绑通过后下发 AppKey / AppSecret</td></tr>
            <tr><td class="cell-code">/api/cloud_var.php</td><td>POST</td><td>公开读取 / 私密读取需签名</td><td>读取应用云变量（公告、开关、配置片段）</td></tr>
            <tr><td class="cell-code">/api/app/version/check</td><td>POST</td><td>AppSecret + HMAC-SHA256 v2</td><td>应用版本检查与增量升级 SQL 下发</td></tr>
            <tr><td class="cell-code">/api/app/version/download</td><td>GET</td><td>短期下载令牌</td><td>下载更新包，令牌 10 分钟有效并绑定请求 IP</td></tr>
            <tr><td class="cell-code">/api/public_key.php</td><td>GET</td><td>公开</td><td>获取 RSA 公钥与请求加密参数</td></tr>
            <tr><td class="cell-code">/api/order_status.php</td><td>GET</td><td>用户 / 代理登录态</td><td>支付页轮询订单状态与已发放卡密</td></tr>
            <tr><td class="cell-code">/api/pay_callback.php</td><td>POST</td><td>支付平台签名</td><td>支付异步通知，仅供支付插件内部使用</td></tr>
            </tbody>
        </table>
    </div>
    <p class="card-desc" style="margin-top:10px">所有接口统一返回 JSON；授权校验、卡密校验、密钥下发、云变量四个接口支持可选 <code>payload</code> 加密请求体，详见「十、加密与签名」。</p>
</section>

<section class="card" id="sec-common">
    <h2 class="card-title">二、通用约定</h2>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>约定</th><th>说明</th></tr></thead>
            <tbody>
            <tr><td class="cell-main">请求编码</td><td>UTF-8；<code>Content-Type: application/json</code>，同时兼容表单 <code>application/x-www-form-urlencoded</code> 与 GET 参数</td></tr>
            <tr><td class="cell-main">时间戳</td><td><code>timestamp</code> 为 Unix 秒级整数；新版接口允许 ±10 分钟，旧版接口窗口由后台「签名时间窗口」控制（默认 300 秒）</td></tr>
            <tr><td class="cell-main">随机串</td><td><code>nonce</code> 建议 16~32 位随机字符串，同一 nonce 在有效窗口内只能使用一次</td></tr>
            <tr><td class="cell-main">签名版本</td><td>新版接口必须传 <code>signVersion=v2</code>；<code>v1</code> 仅用于历史绑定站点兼容</td></tr>
            <tr><td class="cell-main">签名大小写</td><td>签名统一输出小写十六进制；服务端按小写比较</td></tr>
            <tr><td class="cell-main">响应结构</td><td><code>{"code":200,"msg":"...","data":{...}}</code>；失败时 <code>code</code> 为 4xx，<code>data</code> 可能包含 <code>result</code> / <code>reason</code></td></tr>
            <tr><td class="cell-main">限流</td><td>按客户端 IP 每分钟计数，超限返回 <code>429</code>；命中黑名单 IP 返回 <code>403</code></td></tr>
            <tr><td class="cell-main">真实 IP</td><td>后台开启「真实 IP 获取」后自动解析 CF-Connecting-IP / X-Real-IP / X-Forwarded-For</td></tr>
            </tbody>
        </table>
    </div>
    <?php echo doc_pre(<<<'TXT'
统一响应示例：

成功：
{ "code": 200, "msg": "success", "data": { ... } }

失败：
{ "code": 403, "msg": "授权无效或已过期", "data": { "result": "fail", "reason": "license_not_found" } }

说明：
1. code 同时作为 HTTP 状态码返回。
2. data 为空时服务端输出空对象 {}，不会输出 null。
3. 新版接口在配置 RSA 公钥后会附加 rsaSign / signAlg，见「十、加密与签名」。
TXT
); ?>
</section>

<section class="card" id="sec-prepare">
    <h2 class="card-title">三、准备工作</h2>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>配置项</th><th>说明</th><th>获取位置</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">appKey</td><td>应用唯一标识，用于定位应用与生成签名</td><td>应用管理 → AppKey</td></tr>
            <tr><td class="cell-code cell-main">appSecret</td><td>应用密钥，用于签名与 payload 加解密，只能保存在服务端</td><td>应用管理 → 查看 / 重置密钥</td></tr>
            <tr><td class="cell-main">授权目标</td><td>域名、泛域名（*.example.com）、IP 或卡密 licenseKey</td><td>批量生成卡密 → 授权目标</td></tr>
            <tr><td class="cell-main">服务地址</td><td>当前授权系统访问地址</td><td><?php echo e($base); ?></td></tr>
            <tr><td class="cell-main">设备指纹</td><td>稳定且可复现的设备标识，建议由机器码 / 网卡 / 授权文件摘要生成</td><td>项目侧生成</td></tr>
            </tbody>
        </table>
    </div>
    <p class="card-desc" style="margin-top:8px">授权目标类型：1 域名（精确匹配）、2 泛域名（匹配子域名）、3 IP、4 纯密钥（不校验目标，仍受设备上限约束）。licenseKey 为空时按已绑定域名 / IP 反查授权。</p>
</section>

<section class="card" id="sec-license">
    <h2 class="card-title">四、授权校验 /api/license/verify</h2>
    <p class="card-desc">请求方式：<b>POST</b>　Content-Type：<b>application/json</b>　签名：<b>HMAC-SHA256 v2</b>　时间窗口：<b>±10 分钟</b></p>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>字段</th><th>必填</th><th>说明</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">appKey</td><td>是</td><td>应用标识</td></tr>
            <tr><td class="cell-code">licenseKey</td><td>否</td><td>卡密；为空时按 domain / serverIp 反查</td></tr>
            <tr><td class="cell-code">domain</td><td>否</td><td>当前访问域名，自动去除协议、端口与路径</td></tr>
            <tr><td class="cell-code">serverIp</td><td>否</td><td>当前服务器 IP，用于 IP 授权与风控追踪</td></tr>
            <tr><td class="cell-code">timestamp</td><td>是</td><td>Unix 秒级时间戳</td></tr>
            <tr><td class="cell-code">nonce</td><td>否</td><td>推荐传入，用于防重放</td></tr>
            <tr><td class="cell-code">signVersion</td><td>是</td><td>新接入固定 <code>v2</code></td></tr>
            <tr><td class="cell-code">sign</td><td>是</td><td>HMAC-SHA256 小写十六进制签名</td></tr>
            <tr><td class="cell-code">payload</td><td>否</td><td>AES-256-GCM 加密请求体，见第十节</td></tr>
            </tbody>
        </table>
    </div>
    <p class="card-desc" style="margin-top:10px"><b>签名规范：</b><code>licenseKey / domain / serverIp</code> 为空时保留空串，规范串固定 6 段。</p>
    <?php echo doc_pre(<<<'TXT'
v2 规范串（固定 6 段，字段为空时保留空串）：
v2\nappKey\nlicenseKey\ndomain\nserverIp\ntimestamp

sign = hex( HMAC-SHA256(appSecret, 规范串) )   // 小写

v1 兼容签名（仅历史站点）：
sign = md5(appKey + target + timestamp + appSecret)
target = licenseKey 非空取 licenseKey，否则取 domain
TXT
); ?>
    <?php echo doc_pre(<<<'TXT'
POST /api/license/verify
Content-Type: application/json

{
  "appKey": "cms_pro",
  "licenseKey": "VIP-2026-0001",
  "domain": "www.example.com",
  "serverIp": "203.0.113.10",
  "timestamp": 1760000000,
  "nonce": "9f2c1a7b3d5e",
  "signVersion": "v2",
  "sign": "hmac_sha256_hex"
}

PHP 签名示例：
$spec = implode("\n", [
    'v2', $appKey, $licenseKey, $domain, $serverIp, (string)$timestamp
]);
$sign = hash_hmac('sha256', $spec, $appSecret);
TXT
); ?>
    <p class="card-desc" style="margin-top:10px"><b>响应示例：</b></p>
    <?php echo doc_pre(<<<'TXT'
// 校验通过
{
  "code": 200,
  "msg": "授权有效",
  "data": {
    "result": "pass",
    "appName": "CMS Pro",
    "expireAt": "2027-12-31 23:59:59",
    "licenseKey": "VIP-2026-0001"
  }
}

// 校验失败
{
  "code": 403,
  "msg": "授权无效或已过期",
  "data": { "result": "fail", "reason": "license_not_found" }
}
TXT
); ?>
    <p class="card-desc" style="margin-top:10px"><b>状态与处理建议：</b></p>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>data.result</th><th>含义</th><th>建议处理</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">pass</td><td>授权有效</td><td>允许继续运行</td></tr>
            <tr><td class="cell-code">fail</td><td>授权不存在、目标不匹配或签名错误</td><td>阻断核心功能，按 reason 排查</td></tr>
            <tr><td class="cell-code">expired</td><td>授权已过期</td><td>提示续费或重新开通</td></tr>
            <tr><td class="cell-code">blacklisted</td><td>卡密 / 域名命中黑名单</td><td>立即阻断，不要重试</td></tr>
            <tr><td class="cell-code">signature_upgrade_required</td><td>新站点首次绑定必须使用 v2 签名</td><td>升级 SDK 后重试</td></tr>
            <tr><td class="cell-code">site_limit_exceeded</td><td>卡密绑定站点数已达上限</td><td>提示用户解绑或升级套餐</td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="card" id="sec-auth">
    <h2 class="card-title">五、卡密设备校验 /api/auth.php</h2>
    <p class="card-desc">兼容旧版 SDK：校验卡密、域名、设备三者关系，并在首次使用时自动绑定设备。<b>所有字段均必填</b>（含 <code>nonce</code>）。</p>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>字段</th><th>说明</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">app_id</td><td>应用标识（注意与新版 appKey 字段名不同）</td></tr>
            <tr><td class="cell-code">card_no</td><td>卡密</td></tr>
            <tr><td class="cell-code">domain</td><td>当前域名，服务端会标准化后匹配 license_domain</td></tr>
            <tr><td class="cell-code">device_code</td><td>设备指纹，首次校验成功会自动写入绑定表</td></tr>
            <tr><td class="cell-code">timestamp</td><td>Unix 秒级时间戳</td></tr>
            <tr><td class="cell-code">nonce</td><td>随机串，窗口内不可重复</td></tr>
            <tr><td class="cell-code">sign</td><td>参数签名，规则见下</td></tr>
            </tbody>
        </table>
    </div>
    <p class="card-desc" style="margin-top:10px"><b>签名规则（与新版 v2 不同）：</b>移除 <code>sign</code> 后，对其余参数按键名升序排序，使用 <code>http_build_query</code> 拼接待签名串，再做 HMAC-SHA256。</p>
    <?php echo doc_pre(<<<'TXT'
规则：
1. 取 app_id / card_no / domain / device_code / timestamp / nonce
2. 去掉 sign 字段
3. 按键名升序排序（ksort）
4. 待签名串 = http_build_query(参数)   // PHP RFC1738 编码，空格转为 +
5. sign = hex( HMAC-SHA256(appSecret, 待签名串) )   // 小写

PHP 示例：
$params = [
    'app_id'      => $appId,
    'card_no'     => $cardNo,
    'domain'      => $domain,
    'device_code' => $deviceCode,
    'timestamp'   => time(),
    'nonce'       => bin2hex(random_bytes(16)),
];
ksort($params);
$sign = hash_hmac('sha256', http_build_query($params), $appSecret);
$params['sign'] = $sign;

// 其他语言需按同名规则实现键名排序与 RFC1738 URL 编码
TXT
); ?>
    <?php echo doc_pre(<<<'TXT'
请求示例：
POST /api/auth.php
Content-Type: application/json

{
  "app_id": "cms_pro",
  "card_no": "VIP-2026-0001",
  "domain": "www.example.com",
  "device_code": "machine-fingerprint",
  "timestamp": 1760000000,
  "nonce": "9f2c1a7b3d5e",
  "sign": "hmac_sha256_hex"
}

成功响应：
{
  "code": 200,
  "msg": "success",
  "data": {
    "card_no": "VIP-2026-0001",
    "app_name": "CMS Pro",
    "type": 1,
    "expire_time": 1814399999,
    "total_count": 0,
    "used_count": 0,
    "max_devices": 3,
    "domain": "www.example.com",
    "server_time": 1760000000
  }
}
TXT
); ?>
    <p class="card-desc" style="margin-top:8px">可能失败信息：参数缺失、时间戳超窗、nonce 重放、签名错误、卡密不存在 / 封禁、应用停用、授权未生效 / 过期、域名未授权、设备已解绑、设备绑定数达上限、验证次数用完。</p>
</section>

<section class="card" id="sec-secret">
    <h2 class="card-title">六、密钥下发 /api/get_secret.php</h2>
    <p class="card-desc">用于客户端首次运行拉取 AppKey / AppSecret。必须同时通过<b>域名授权</b>与<b>设备绑定</b>校验；后台可关闭该接口。签名规则与 <code>/api/auth.php</code> 相同（排序 + http_build_query + HMAC-SHA256），但 <b>HMAC 密钥使用 app_id</b>（此时客户端尚未取得 AppSecret）。</p>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>字段</th><th>必填</th><th>说明</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">app_id</td><td>是</td><td>应用标识</td></tr>
            <tr><td class="cell-code">domain</td><td>是</td><td>域名，需存在启用中的域名授权</td></tr>
            <tr><td class="cell-code">device_code</td><td>是</td><td>设备指纹，需已绑定且状态正常</td></tr>
            <tr><td class="cell-code">timestamp</td><td>是</td><td>Unix 秒级时间戳</td></tr>
            <tr><td class="cell-code">nonce</td><td>是</td><td>随机串</td></tr>
            <tr><td class="cell-code">sign</td><td>是</td><td>参数签名</td></tr>
            </tbody>
        </table>
    </div>
    <?php echo doc_pre(<<<'TXT'
请求签名（HMAC 密钥为 app_id）：
$params = [
    'app_id'      => $appId,
    'domain'      => $domain,
    'device_code' => $deviceCode,
    'timestamp'   => time(),
    'nonce'       => bin2hex(random_bytes(16)),
];
ksort($params);
$params['sign'] = hash_hmac('sha256', http_build_query($params), $appId);

GET /api/get_secret.php?app_id=cms_pro&domain=www.example.com&device_code=...&timestamp=...&nonce=...&sign=...

成功响应：
{
  "code": 200,
  "msg": "success",
  "data": {
    "appkey": "cms_pro",
    "appsecret": "your-app-secret",
    "expire": 1760001800,
    "cache_ttl": 1800
  }
}

说明：
expire     本次下发结果的有效截止时间
cache_ttl  客户端建议缓存秒数
安全要求：
1. 只保存在服务端或加密后的本地配置中，禁止明文写入前端代码。
2. 密钥泄露后立即到「应用管理」重置 AppSecret。
TXT
); ?>
</section>

<section class="card" id="sec-cloud">
    <h2 class="card-title">七、云变量 /api/cloud_var.php</h2>
    <p class="card-desc"><b>公开读取：</b>只传 <code>app_id</code>（可选 <code>keys</code>），仅返回 <code>is_public=1</code> 的变量。<b>私密读取：</b>额外传 <code>private=1</code> 与签名参数，可读取全部变量。</p>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>字段</th><th>必填</th><th>说明</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">app_id</td><td>是</td><td>应用标识</td></tr>
            <tr><td class="cell-code">keys</td><td>否</td><td>逗号分隔的变量名，最多 50 个；为空返回该应用全部可见变量</td></tr>
            <tr><td class="cell-code">private</td><td>否</td><td>传 <code>1</code> 读取私密变量，必须提供签名参数</td></tr>
            <tr><td class="cell-code">timestamp / nonce / sign</td><td>私密必填</td><td>签名参数包含 app_id、keys、private、timestamp、nonce，按排序 + http_build_query 规则</td></tr>
            </tbody>
        </table>
    </div>
    <?php echo doc_pre(<<<'TXT'
公开读取：
POST /api/cloud_var.php
{ "app_id": "cms_pro", "keys": "announce,feature_switch" }

私密读取：
{
  "app_id": "cms_pro",
  "keys": "license_salt,private_endpoint",
  "private": "1",
  "timestamp": 1760000000,
  "nonce": "9f2c1a7b3d5e",
  "sign": "hmac_sha256_hex"
}

响应：
{
  "code": 200,
  "msg": "success",
  "data": {
    "vars": { "announce": "系统维护通知", "feature_switch": "1" },
    "server_time": 1760000000
  }
}
TXT
); ?>
</section>

<section class="card" id="sec-version">
    <h2 class="card-title">八、版本检查与下载</h2>
    <p class="card-desc"><b>检查更新：</b>POST <code>/api/app/version/check</code>，签名与授权校验同属 v2 协议，规范串固定 6 段。</p>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>字段</th><th>必填</th><th>说明</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">appKey</td><td>是</td><td>应用标识</td></tr>
            <tr><td class="cell-code">currentVersion</td><td>是</td><td>客户端当前版本，如 1.2.0</td></tr>
            <tr><td class="cell-code">licenseKey / domain / serverIp</td><td>至少一项</td><td>与有效授权匹配的目标</td></tr>
            <tr><td class="cell-code">timestamp</td><td>是</td><td>Unix 秒级时间戳，±10 分钟</td></tr>
            <tr><td class="cell-code">signVersion</td><td>是</td><td>v2</td></tr>
            <tr><td class="cell-code">sign</td><td>是</td><td>见下方规范串</td></tr>
            </tbody>
        </table>
    </div>
    <?php echo doc_pre(<<<'TXT'
v2 规范串（固定 6 段）：
v2\nappKey\ncurrentVersion\nlicenseKey\ndomain\nserverIp\ntimestamp

sign = hex( HMAC-SHA256(appSecret, 规范串) )

v1 兼容：
sign = hex( HMAC-SHA256(appSecret, "v1\nappKey\ncurrentVersion\ntarget\ntimestamp") )
target = licenseKey 非空取 licenseKey，否则取 domain

请求示例：
POST /api/app/version/check
{
  "appKey": "cms_pro",
  "currentVersion": "1.2.0",
  "licenseKey": "VIP-2026-0001",
  "domain": "www.example.com",
  "serverIp": "203.0.113.10",
  "timestamp": 1760000000,
  "signVersion": "v2",
  "sign": "hmac_sha256_hex"
}
TXT
); ?>
    <?php echo doc_pre(<<<'TXT'
无更新：
{ "code": 200, "msg": "", "data": { "hasUpdate": false, "currentVersion": "1.2.0", "latestVersion": "1.2.0" } }

有更新：
{
  "code": 200,
  "msg": "",
  "data": {
    "hasUpdate": true,
    "currentVersion": "1.2.0",
    "latestVersion": "1.5.0",
    "version": "1.5.0",
    "title": "性能优化与问题修复",
    "changelog": "1. 优化启动速度\n2. 修复已知问题",
    "downloadUrl": "https://你的域名/api/app/version/download?token=短期令牌",
    "fileSizeMb": 18.625,
    "fileMd5": "d41d8cd98f00b204e9800998ecf8427e",
    "forceUpdate": true,
    "minVersion": "1.1.0",
    "publishedAt": "2026-07-23 12:00:00",
    "updates": [
      { "version": "1.3.0", "title": "数据库升级", "changelog": "增加索引", "updateSql": "ALTER TABLE example ADD INDEX idx_enabled (enabled);" },
      { "version": "1.5.0", "title": "性能优化与问题修复", "changelog": "1. 优化启动速度\n2. 修复已知问题", "updateSql": "" }
    ]
  }
}
TXT
); ?>
    <p class="card-desc" style="margin-top:10px"><b>更新包下载：</b>GET <code>/api/app/version/download?token=...</code>，无需额外签名。令牌 10 分钟有效、绑定请求 IP、只能下载对应版本；响应头包含 <code>X-File-Md5</code>，客户端应校验文件大小与 MD5。</p>
    <?php echo doc_pre(<<<'TXT'
客户端升级顺序：
1. 读取 appKey/appSecret、当前版本与授权目标
2. 生成 timestamp 并按 v2 规范串签名
3. POST /api/app/version/check
4. hasUpdate=false 结束；forceUpdate=true 时禁止跳过
5. 按 updates 版本升序执行 updateSql
6. 下载更新包，校验 fileSizeMb 与 fileMd5
7. 安装成功后写入 latestVersion；失败时回滚
TXT
); ?>
</section>

<section class="card" id="sec-order">
    <h2 class="card-title">九、订单状态 /api/order_status.php</h2>
    <p class="card-desc">支付页轮询接口，只能查询当前登录用户或代理商本人的订单。请求方式：<b>GET</b>，参数 <code>order_no</code>，格式必须为 <code>PAY</code> 或 <code>RC</code> 开头加 20 位数字。</p>
    <?php echo doc_pre(<<<'TXT'
GET /api/order_status.php?order_no=PAY20260101000000000001

成功响应：
{
  "code": 200,
  "msg": "success",
  "data": {
    "order_no": "PAY20260101000000000001",
    "pay_status": 1,
    "pay_status_text": "已支付",
    "subject": "VIP 年卡",
    "amount": "99.00",
    "discount_amount": "0.00",
    "pay_channel": "epay",
    "paid_time": 1760000000,
    "created_at": 1760000000,
    "cards": ["VIP-2026-0001"]
  }
}
TXT
); ?>
</section>

<section class="card" id="sec-crypto">
    <h2 class="card-title">十、加密与签名</h2>

    <h3 class="card-title" style="font-size:15px;margin-top:6px">1. 请求体加密 payload（AES-256-GCM）</h3>
    <?php echo doc_pre(<<<'TXT'
密钥派生：
key = SHA-256("ape-api-v2|" . appSecret) 原始 32 字节

加密流程：
明文 = JSON(业务字段, UTF-8, 不转义中文与斜杠)
密文 = AES-256-GCM(key, 12 字节随机 IV, 明文)
payload = base64url(IV || 密文 || 16 字节认证标签)

请求示例：
POST /api/license/verify
{
  "appKey": "cms_pro",
  "payload": "base64url_ciphertext"
}

说明：
1. 外层 appKey / app_id 仅用于定位应用密钥，解密后字段会覆盖同名参数。
2. 解密失败返回 400「加密请求体解析失败」。
3. 加密后仍必须提供 timestamp / sign 等签名参数，签名基于解密后的字段。
TXT
); ?>
    <div class="table-wrap" style="margin-top:8px">
        <table class="tb">
            <thead><tr><th>接口</th><th>payload 内可加密字段</th></tr></thead>
            <tbody>
            <tr><td class="cell-code">/api/license/verify</td><td class="cell-code">appKey / licenseKey / domain / serverIp / timestamp / nonce / signVersion / sign</td></tr>
            <tr><td class="cell-code">/api/auth.php</td><td class="cell-code">app_id / card_no / domain / device_code / timestamp / nonce / sign</td></tr>
            <tr><td class="cell-code">/api/get_secret.php</td><td class="cell-code">app_id / domain / device_code / timestamp / nonce / sign</td></tr>
            <tr><td class="cell-code">/api/cloud_var.php</td><td class="cell-code">app_id / keys / private / timestamp / nonce / sign</td></tr>
            </tbody>
        </table>
    </div>

    <h3 class="card-title" style="font-size:15px;margin-top:14px">2. 响应 RSA 签名（RSA-2048 / RSA-SHA256）</h3>
    <?php echo doc_pre(<<<'TXT'
响应结构：
{
  "code": 200,
  "msg": "ok",
  "data": { "result": "pass", "...": "..." },
  "rsaSign": "base64_signature",
  "signAlg": "RSA-SHA256"
}

验签步骤：
1. GET /api/public_key.php 获取 PEM 公钥
2. 取服务端原始响应文本，删除最外层 rsaSign 与 signAlg 字段
3. 对剩余 {"code":...,"msg":...,"data":...} 原样字节做 RSA-SHA256 验签
4. 验签失败时不得信任响应内容

注意：签名基于服务端原始 JSON 字节，请勿反序列化后重新序列化再验签。
TXT
); ?>
    <?php echo doc_pre(<<<'TXT'
GET /api/public_key.php

响应：
{
  "code": 200,
  "msg": "success",
  "data": {
    "version": "2.0",
    "signAlg": "RSA-SHA256",
    "publicKey": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----\n",
    "requestCrypt": {
      "alg": "AES-256-GCM",
      "keyDerivation": "SHA-256(\"ape-api-v2|\" . appSecret) 原始 32 字节",
      "payloadFormat": "base64url(12字节IV || 密文 || 16字节认证标签)",
      "field": "payload",
      "outerFields": ["appKey / app_id 用于服务端定位应用密钥"],
      "endpoints": [
        "/api/license/verify",
        "/api/auth.php",
        "/api/get_secret.php",
        "/api/cloud_var.php"
      ]
    },
    "serverTime": 1760000000
  }
}
TXT
); ?>
    <p class="card-desc" style="margin-top:8px">公钥同时可在后台「授权与安全 → 加密与验证」查看；私钥经 AES-256-GCM 加密后保存在系统配置中，不会对外输出。</p>
</section>

<section class="card" id="sec-errors">
    <h2 class="card-title">十一、错误码与失败原因</h2>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>HTTP / code</th><th>含义</th><th>常见场景</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">200</td><td>成功</td><td>校验通过、查询成功</td></tr>
            <tr><td class="cell-code">400</td><td>参数错误</td><td>缺少必填字段、timestamp 缺失、signVersion 非 v2、payload 解密失败</td></tr>
            <tr><td class="cell-code">401</td><td>鉴权失败</td><td>签名错误、时间戳超窗、nonce 重放、payload 应用标识不一致</td></tr>
            <tr><td class="cell-code">403</td><td>拒绝访问</td><td>授权无效 / 过期 / 封禁、域名或设备未授权、IP 黑名单、接口关闭</td></tr>
            <tr><td class="cell-code">404</td><td>资源不存在</td><td>应用不存在、卡密不存在、订单不存在、下载令牌失效</td></tr>
            <tr><td class="cell-code">429</td><td>请求过于频繁</td><td>按 IP 每分钟限流</td></tr>
            </tbody>
        </table>
    </div>
    <p class="card-desc" style="margin-top:10px"><b>data.reason 常见值：</b></p>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>reason</th><th>说明</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">missing_app_key</td><td>缺少 appKey</td></tr>
            <tr><td class="cell-code">missing_timestamp</td><td>缺少 timestamp</td></tr>
            <tr><td class="cell-code">missing_target</td><td>licenseKey / domain / serverIp 至少传一项</td></tr>
            <tr><td class="cell-code">missing_current_version</td><td>版本检查缺少 currentVersion</td></tr>
            <tr><td class="cell-code">timestamp_out_of_window</td><td>时间戳超出允许窗口</td></tr>
            <tr><td class="cell-code">nonce_replayed</td><td>nonce 已使用或无效</td></tr>
            <tr><td class="cell-code">sign_error</td><td>签名校验失败</td></tr>
            <tr><td class="cell-code">sign_version_invalid</td><td>signVersion 不是 v1 / v2</td></tr>
            <tr><td class="cell-code">app_not_found</td><td>应用不存在</td></tr>
            <tr><td class="cell-code">license_not_found</td><td>未找到匹配授权</td></tr>
            <tr><td class="cell-code">blacklisted</td><td>命中黑名单</td></tr>
            <tr><td class="cell-code">target_mismatch</td><td>域名 / IP / 卡密与授权目标不匹配</td></tr>
            <tr><td class="cell-code">expired</td><td>授权已过期</td></tr>
            <tr><td class="cell-code">count_exhausted</td><td>验证次数已用完</td></tr>
            <tr><td class="cell-code">site_unbound</td><td>站点未绑定或已解绑</td></tr>
            <tr><td class="cell-code">site_limit_exceeded</td><td>绑定站点数达到上限</td></tr>
            <tr><td class="cell-code">signature_upgrade_required</td><td>需要升级为 v2 签名</td></tr>
            <tr><td class="cell-code">payload_decrypt_failed</td><td>payload 解密失败</td></tr>
            <tr><td class="cell-code">payload_app_mismatch</td><td>payload 解密后的 appKey 与明文不一致</td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="card" id="sec-sdk">
    <h2 class="card-title">十二、SDK 模板</h2>
    <p class="card-desc">后台「SDK 模板管理」内置 <b>PHP / Java / Python / Go / Node.js / C++</b> 六套 V2 协议模板，统一实现 AppSecret 获取、HMAC-SHA256 v2 签名、授权校验与失败处理。</p>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>语言</th><th>模板能力</th><th>导出文件</th></tr></thead>
            <tbody>
            <tr><td class="cell-main">PHP</td><td>verify / versionCheck / fetchSecret</td><td class="cell-code">sdk_{appid}.php</td></tr>
            <tr><td class="cell-main">Java</td><td>verify + fetchSecret（JDK 标准库）</td><td class="cell-code">sdk_{appid}.java</td></tr>
            <tr><td class="cell-main">Python</td><td>verify + fetchSecret（标准库）</td><td class="cell-code">sdk_{appid}.py</td></tr>
            <tr><td class="cell-main">Go</td><td>verify + fetchSecret（标准库）</td><td class="cell-code">sdk_{appid}.go</td></tr>
            <tr><td class="cell-main">Node.js</td><td>verify + fetchSecret（标准库）</td><td class="cell-code">sdk_{appid}.js</td></tr>
            <tr><td class="cell-main">C++</td><td>verify + fetchSecret（C++17，依赖 libcurl / OpenSSL）</td><td class="cell-code">sdk_{appid}.h</td></tr>
            </tbody>
        </table>
    </div>
    <?php echo doc_pre(<<<'TXT'
导出时会自动替换以下占位符：
{{app_id}}    → 应用 AppKey
{{app_key}}   → 应用 AppSecret
{{api_base}}  → 当前站点访问地址

推荐接入顺序：
1. 应用管理创建应用，获取 AppKey / AppSecret
2. SDK 模板管理导入或新建对应语言模板
3. 导出 SDK，填写授权目标（域名 / IP / 卡密）
4. 在项目启动、登录与核心业务入口调用校验
5. 校验失败时关闭核心功能并提示授权状态
TXT
); ?>
</section>

<section class="card" id="sec-plugin">
    <h2 class="card-title">十三、插件开发</h2>
    <p class="card-desc">插件采用文件驱动：系统扫描 <code>plugin/*/Plugin.php</code>，放入目录即被识别，再到后台「应用商店」启用。完整说明见根目录 <code>docs/插件开发文档.md</code>。</p>
    <?php echo doc_pre(<<<'TXT'
plugin/
└── myplug/
    ├── Plugin.php       必需：返回插件元数据数组
    ├── icon.svg         建议：应用商店图标
    └── ...              业务文件

<?php
// Plugin.php
declare(strict_types=1);

return [
    'code'        => 'myplug',              // 全局唯一
    'name'        => '我的插件',
    'cat'         => 'pay',                 // pay/template/captcha/login/realname
    'description' => '插件说明',
    'version'     => '1.0.0',
    'author'      => '开发者',
    'core'        => 0,                     // 1=核心内置，不可卸载
    'installable' => 1,
    'status'      => 2,                     // 1启用 2停用
    'sort'        => 50,
    'config'      => [
        ['key' => 'my_key', 'label' => '配置项', 'type' => 'text', 'hint' => '提示'],
        ['key' => 'my_secret', 'label' => '密钥', 'type' => 'password'],
    ],
];
TXT
); ?>
    <div class="table-wrap" style="margin-top:8px">
        <table class="tb">
            <thead><tr><th>配置类型</th><th>说明</th><th>读取方式</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">text / select / switch / textarea</td><td>普通配置，自动合并进全局 cfg 缓存</td><td class="cell-code">cfg('my_key')</td></tr>
            <tr><td class="cell-code">password</td><td>敏感配置，加密存入 sys_config，界面只写不改</td><td class="cell-code">plugin_cfg_get('myplug', 'my_secret')</td></tr>
            </tbody>
        </table>
    </div>
    <p class="card-desc" style="margin-top:8px"><b>业务文件头部标准写法：</b></p>
    <?php echo doc_pre(<<<'TXT'
<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/Security.php';
config();

常用 API：
e() / db() / cfg() / set_cfg() / plugin_enabled() / plugin_cfg_get() / plugin_cfg_set()
plugin_scan() / plugin_all() / client_ip() / http_get() / http_post()
audit() / csrf_field() / csrf_check() / json_out() / flash_set() / flash_take() / redirect()
sec_rate_hit()
TXT
); ?>
    <p class="card-desc" style="margin-top:8px">验证码插件在 <code>core/Captcha.php</code> 中扩展 <code>captcha_mode()</code> 与 <code>captcha_verify_scene()</code>；登录插件回调地址固定为 <code>base_url() . 'plugin/插件目录/callback.php'</code>；支付插件入口为 <code>pay.php</code>，异步回调验签后再更新订单。</p>
</section>

<section class="card" id="sec-theme">
    <h2 class="card-title">十四、主题开发</h2>
    <p class="card-desc">官网首页由 <code>cat = template</code> 的主题插件渲染，核心文件是 <code>home.php</code>。完整说明见根目录 <code>docs/主题开发文档.md</code>。</p>
    <?php echo doc_pre(<<<'TXT'
plugin/mytheme/
├── Plugin.php     cat 必须为 template，sort 越小优先级越高
├── home.php       首页主文件
├── home.css
├── home.js
└── icon.svg

首页可用变量：
$tplCode        当前主题 code
$tplDir         当前主题目录名
$query_domain   ?q= 查询的域名
$result         查询结果：level(ok/warn/bad) / main / sub，未查询为 null

可用函数：
base_url() / cfg() / site_info() / site_logo_url('../')
site_meta_tags('../') / site_contacts_html() / site_icp_html()
plugin_cfg_get('mytheme', 'key')

资源路径：
<link rel="stylesheet" href="<?php echo base_url(); ?>plugin/<?php echo $tplDir; ?>/home.css">
TXT
); ?>
    <p class="card-desc" style="margin-top:8px">主题切换规则：系统扫描所有启用中的 <code>template</code> 插件，按 <code>sort</code> 升序取第一个可用主题；想优先生效可把 <code>sort</code> 设为更小值，或停用其他主题。页脚请保留系统版权署名。</p>
</section>

<section class="card" id="sec-debug">
    <h2 class="card-title">十五、联调与排错</h2>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>现象</th><th>排查方向</th></tr></thead>
            <tbody>
            <tr><td class="cell-main">签名校验失败</td><td>确认使用小写 hex；v2 规范串字段顺序与空串占位是否正确；AppSecret 是否被重置；域名已标准化</td></tr>
            <tr><td class="cell-main">时间戳超窗</td><td>检查服务器时间与 NTP；新版窗口 ±10 分钟，旧版默认 300 秒</td></tr>
            <tr><td class="cell-main">nonce 重放</td><td>每次请求重新生成随机串，不要复用</td></tr>
            <tr><td class="cell-main">授权无效</td><td>检查应用是否启用、卡密是否封禁 / 过期、域名 / IP 是否在授权目标内</td></tr>
            <tr><td class="cell-main">域名未授权</td><td>域名需与 license_domain 完全匹配；泛域名仅匹配子域名</td></tr>
            <tr><td class="cell-main">设备上限</td><td>到卡密管理解绑旧设备，或调整 max_devices</td></tr>
            <tr><td class="cell-main">429 限流</td><td>降低轮询频率；检查是否共享出口 IP；必要时调整后台限流阈值</td></tr>
            <tr><td class="cell-main">RSA 验签失败</td><td>必须对原始响应字节验签，删除 rsaSign / signAlg 后不要重新序列化</td></tr>
            <tr><td class="cell-main">更新包下载失败</td><td>令牌 10 分钟过期且绑定 IP；确认版本已发布、文件存在</td></tr>
            </tbody>
        </table>
    </div>
    <p class="card-desc" style="margin-top:10px"><b>上线检查清单：</b></p>
    <?php echo doc_pre(<<<'TXT'
[ ] AppSecret 只保存在服务端或加密配置中
[ ] 使用 signVersion=v2，并启用 nonce
[ ] 校验失败时关闭核心功能，不只打印日志
[ ] 开启 payload 加密与 RSA 响应验签（可选但推荐）
[ ] 记录验证日志，关注黑名单与盗版追踪告警
[ ] 更新包下载后校验 MD5 与文件大小
[ ] 生产环境关闭错误回显，删除 install/ 目录
TXT
); ?>
</section>
<?php admin_footer(); ?>
