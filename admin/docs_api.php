<?php
// 管理员后台 - API 文档
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

function doc_pre(string $code): string
{
    return '<pre style="background:#f6f8fa;border:1px solid #e5e6eb;border-radius:4px;padding:12px 14px;font-size:12.5px;line-height:1.7;overflow:auto;margin:8px 0 0;color:#24292f">' . e($code) . '</pre>';
}

$host = (string)($_SERVER['HTTP_HOST'] ?? 'your-domain.com');
$base = 'http://' . $host;

admin_header('开发文档', 'docs_api', $admin);
?>

<section class="card">
    <h2 class="card-title">一、对接目标</h2>
    <p class="card-desc">业务项目在运行时向授权系统发起校验请求，授权系统根据应用、域名/IP/密钥、授权状态和到期时间返回校验结果。项目侧只需要在关键入口增加一次校验，即可控制系统是否允许继续运行。</p>
    <p class="card-desc"><b>本系统对接地址：</b><?php echo e($base); ?>/api/license/verify（授权校验）、<?php echo e($base); ?>/api/app/version/check（版本检查）。</p>
</section>

<section class="card">
    <h2 class="card-title">二、准备工作</h2>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>配置项</th><th>说明</th><th>获取位置</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">appKey</td><td>应用唯一标识</td><td>应用管理 → 对应应用的 AppKey</td></tr>
            <tr><td class="cell-code cell-main">appSecret</td><td>应用密钥，用于签名（服务端保管，禁止下发到客户端代码）</td><td>应用管理 → 重置/查看</td></tr>
            <tr><td class="cell-main">授权目标</td><td>域名、泛域名（*.example.com）、IP 或 licenseKey（卡密）</td><td>批量生成卡密 → 授权目标</td></tr>
            <tr><td class="cell-main">服务地址</td><td>授权系统后端访问地址，即本站地址</td><td><?php echo e($base); ?></td></tr>
            </tbody>
        </table>
    </div>
    <p class="card-desc" style="margin-top:8px">授权目标类型在「批量生成卡密」时选择：1 域名（精确匹配）/ 2 泛域名（匹配子域名）/ 3 IP / 4 纯密钥（不校验目标，多站点受设备上限约束）。licenseKey 为空时支持按已绑定域名/IP 反查授权。</p>
</section>

<section class="card">
    <h2 class="card-title">三、授权校验接口</h2>
    <p class="card-desc">请求方式：<b>POST</b>　接口地址：<b>/api/license/verify</b>　Content-Type：<b>application/json</b></p>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>字段</th><th>是否必填</th><th>说明</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">appKey</td><td>是</td><td>应用标识</td></tr>
            <tr><td class="cell-code">domain</td><td>否</td><td>当前访问域名，域名/泛域名授权使用</td></tr>
            <tr><td class="cell-code">serverIp</td><td>否</td><td>当前服务器 IP，IP 授权或风控追踪使用</td></tr>
            <tr><td class="cell-code">licenseKey</td><td>否</td><td>密钥（卡密）授权时传入；为空时按已绑定域名/IP 反查</td></tr>
            <tr><td class="cell-code">timestamp</td><td>是</td><td>Unix 秒级时间戳，服务端允许前后 10 分钟偏差</td></tr>
            <tr><td class="cell-code">signVersion</td><td>是</td><td>新接入必须传 "v2"；v1 仅兼容历史绑定站点</td></tr>
            <tr><td class="cell-code">sign</td><td>是</td><td>签名，见下方规范串</td></tr>
            </tbody>
        </table>
    </div>
    <p class="card-desc" style="margin-top:10px"><b>签名规范：</b></p>
    <?php echo doc_pre("v2: HMAC-SHA256(appSecret, \"v2\\nappKey\\nlicenseKey\\ndomain\\nserverIp\\ntimestamp\")  hex 小写
v1: MD5(appKey + target + timestamp + appSecret)  target = licenseKey 优先否则 domain（仅历史绑定站点可用）"); ?>
    <?php echo doc_pre('POST /api/license/verify
Content-Type: application/json

{
  "appKey": "cms_pro",
  "domain": "www.example.com",
  "serverIp": "203.0.113.10",
  "licenseKey": "",
  "timestamp": 1760000000,
  "signVersion": "v2",
  "sign": "hmac_sha256_hex"
}

// v2 规范串（固定 6 段，字段为空时保留空串）：
// v2\ncms_pro\n\nwww.example.com\n203.0.113.10\n1760000000
// sign = HMAC-SHA256(appSecret, 上述规范串)

// PHP 签名示例：
$sign = hash_hmac(\'sha256\', implode("\n", [\'v2\', $appKey, $licenseKey, $domain, $serverIp, $timestamp]), $appSecret);'); ?>
    <p class="card-desc" style="margin-top:10px"><b>响应结构：</b></p>
    <?php echo doc_pre('// 校验通过
{ "code": 200, "msg": "授权有效",
  "data": { "result": "pass", "appName": "CMS Pro", "expireAt": "2027-12-31 23:59:59", "licenseKey": "VIP-..." } }

// 校验失败
{ "code": 403, "msg": "授权无效或已过期",
  "data": { "result": "fail", "reason": "license_not_found" } }'); ?>
</section>

<section class="card">
    <h2 class="card-title">四、状态说明</h2>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>状态（data.result）</th><th>说明</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">pass</td><td>授权有效，可以继续运行。</td></tr>
            <tr><td class="cell-code">fail</td><td>授权不存在、目标不匹配或签名错误（reason：app_not_found / license_not_found / target_mismatch / sign_error / count_exhausted / site_unbound 等）。</td></tr>
            <tr><td class="cell-code">expired</td><td>授权已过期，需要续费或重新开通。</td></tr>
            <tr><td class="cell-code">blacklisted</td><td>目标命中黑名单，应立即阻断访问。</td></tr>
            <tr><td class="cell-code">signature_upgrade_required</td><td>密钥新站点首次绑定必须使用 v2 签名，当前 SDK 需升级。</td></tr>
            <tr><td class="cell-code">site_limit_exceeded</td><td>密钥已绑定站点数达到上限，拒绝新站点，不吊销已绑定站点。</td></tr>
            </tbody>
        </table>
    </div>
    <p class="card-desc" style="margin-top:8px">站点绑定复用设备上限（单卡最大绑定站点数），绑定后可在「卡密管理」中查看与解绑。每个请求都会写入「验证日志」，可在后台审计。</p>
</section>

<section class="card">
    <h2 class="card-title">五、推荐接入流程</h2>
    <p class="card-desc">1. 创建应用：在「应用管理」创建应用，获得 appKey/appSecret。<br>2. 创建授权：在「批量生成卡密」为用户或项目创建域名、泛域名、IP 或密钥授权。<br>3. 项目启动校验：业务项目启动时请求授权校验接口。<br>4. 关键操作二次校验：建议在登录、核心业务入口或定时任务中进行二次校验。<br>5. 失败熔断：校验失败时停止关键功能，并提示授权状态。</p>
    <?php echo doc_pre('启动项目
  读取 appKey/appSecret/licenseKey
  获取当前 domain/serverIp
  生成 timestamp
  按 "v2\nappKey\nlicenseKey\ndomain\nserverIp\ntimestamp" 生成 HMAC-SHA256 v2 签名
  请求 /api/license/verify
  如果 result == pass:
    允许系统继续运行
  否则:
    关闭核心功能并展示授权异常提示'); ?>
    <p class="card-desc" style="margin-top:8px">可以直接到「SDK 模板管理」下载各语言（PHP / Java / Python / Go / Node.js / C++）接入模板，模板已按 v2 协议实现签名与校验流程。</p>
</section>

<section class="card">
    <h2 class="card-title">六、应用版本检查</h2>
    <p class="card-desc">请求方式：<b>POST</b>　接口地址：<b>/api/app/version/check</b>　鉴权方式：有效授权 + 应用密钥 HMAC-SHA256 签名</p>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>字段</th><th>是否必填</th><th>说明</th></tr></thead>
            <tbody>
            <tr><td class="cell-code cell-main">appKey</td><td>是</td><td>应用管理中生成的应用标识</td></tr>
            <tr><td class="cell-code">currentVersion</td><td>是</td><td>客户端当前版本号，例如 1.2.0</td></tr>
            <tr><td class="cell-code">domain / serverIp / licenseKey</td><td>三选一</td><td>与有效授权匹配的授权目标；licenseKey 为空时按域名/IP 反查</td></tr>
            <tr><td class="cell-code">timestamp</td><td>是</td><td>Unix 秒级时间戳，服务端允许前后 10 分钟偏差</td></tr>
            <tr><td class="cell-code">signVersion</td><td>是</td><td>新接入必须传 "v2"</td></tr>
            <tr><td class="cell-code">sign</td><td>是</td><td>签名，见下方规范串</td></tr>
            </tbody>
        </table>
    </div>
    <?php echo doc_pre('v2: HMAC-SHA256(appSecret, "v2\nappKey\ncurrentVersion\nlicenseKey\ndomain\nserverIp\ntimestamp")  hex 小写
v1: HMAC-SHA256(appSecret, "v1\nappKey\ncurrentVersion\ntarget\ntimestamp")

// 请求示例
POST /api/app/version/check
Content-Type: application/json

{
  "appKey": "cms_pro",
  "currentVersion": "1.2.0",
  "licenseKey": "license_xxx",
  "domain": "www.example.com",
  "serverIp": "203.0.113.10",
  "timestamp": 1784788800,
  "signVersion": "v2",
  "sign": "hmac_sha256_hex"
}

// v2 规范串（固定 7 段，字段为空时保留空串）：
// v2\ncms_pro\n1.2.0\nlicense_xxx\nwww.example.com\n203.0.113.10\n1784788800'); ?>
    <p class="card-desc" style="margin-top:10px"><b>响应示例（有更新）：</b></p>
    <?php echo doc_pre('{
  "code": 200, "msg": "",
  "data": {
    "hasUpdate": true,
    "currentVersion": "1.2.0",
    "latestVersion": "1.5.0",
    "version": "1.5.0",
    "title": "性能优化与问题修复",
    "changelog": "1. 优化启动速度\n2. 修复已知问题",
    "downloadUrl": "' . e($base) . '/api/app/version/download?token=短期签名令牌",
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
}'); ?>
    <p class="card-desc" style="margin-top:10px"><b>客户端升级顺序：</b></p>
    <?php echo doc_pre('检查更新
  读取 appKey/appSecret、当前版本和授权目标
  获取当前 domain/serverIp
  生成 timestamp
  按 "v2\nappKey\ncurrentVersion\nlicenseKey\ndomain\nserverIp\ntimestamp" 生成 HMAC-SHA256 v2 签名
  POST /api/app/version/check
  如果 hasUpdate == false:
    结束更新
  如果 forceUpdate == true:
    禁止用户跳过本次更新
  按 updates 的版本升序执行每一步 updateSql
  下载 latestVersion 更新包并校验文件大小和 MD5
  安装成功后，将本地版本写为 latestVersion
  任一步失败时回滚并保留原版本

下载地址是短期令牌地址（10 分钟有效、绑定请求 IP）。客户端应及时下载并校验文件大小与 MD5，不要持久化该地址。'); ?>
    <p class="card-desc" style="margin-top:8px">版本在「应用版本管理」中发布：填写版本号、标题、更新说明、升级 SQL，上传更新包后系统自动计算文件大小与 MD5。</p>
</section>
<?php admin_footer(); ?>
