<?php
// 加密与验证中心：AES-256-GCM / RSA2048 / TOTP 二次验证
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';
require_once ROOT_PATH . '/core/Security.php';
require_once ROOT_PATH . '/core/LicenseApi.php';

$admin = admin_require_login();
$aid = (int)$admin['id'];
$stmt = db()->prepare('SELECT * FROM `admin` WHERE `id`=? LIMIT 1');
$stmt->execute([$aid]);
$info = $stmt->fetch() ?: $admin;

if (isset($_GET['cancel_setup'])) {
    unset($_SESSION['admin_totp_setup']);
    redirect('security_center.php');
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'totp_start') {
        $secret = sec_totp_secret(20);
        $_SESSION['admin_totp_setup'] = ['secret' => $secret, 'time' => time()];
        flash_set('ok', '已生成新的 TOTP 密钥，请在身份验证器中添加后输入验证码完成绑定。');
        redirect('security_center.php');
    }

    if ($action === 'totp_enable') {
        $setup = $_SESSION['admin_totp_setup'] ?? null;
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? '')) ?? '';
        if (!is_array($setup) || empty($setup['secret']) || time() - (int)($setup['time'] ?? 0) > 600) {
            unset($_SESSION['admin_totp_setup']);
            flash_set('err', '绑定会话已过期，请重新生成密钥。');
        } elseif (strlen($code) !== 6 || !sec_totp_verify((string)$setup['secret'], $code)) {
            flash_set('err', '动态验证码不正确，请确认设备时间准确后重试。');
        } else {
            db()->prepare('UPDATE `admin` SET `totp_secret`=?,`totp_enabled`=1 WHERE `id`=?')
                ->execute([(string)$setup['secret'], $aid]);
            unset($_SESSION['admin_totp_setup']);
            audit('security', 'totp_enable', 1, $aid, '开启 TOTP 二次验证');
            flash_set('ok', 'TOTP 二次验证已开启，下次登录需要输入动态验证码。');
        }
        redirect('security_center.php');
    }

    if ($action === 'totp_disable') {
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? '')) ?? '';
        $secret = (string)($info['totp_secret'] ?? '');
        if ((int)($info['totp_enabled'] ?? 0) !== 1 || $secret === '') {
            flash_set('err', '当前未开启 TOTP 二次验证。');
        } elseif (!sec_totp_verify($secret, $code)) {
            flash_set('err', '动态验证码不正确，无法关闭二次验证。');
        } else {
            db()->prepare('UPDATE `admin` SET `totp_secret`=\'\',`totp_enabled`=0 WHERE `id`=?')->execute([$aid]);
            unset($_SESSION['admin_totp_setup']);
            audit('security', 'totp_disable', 1, $aid, '关闭 TOTP 二次验证');
            flash_set('ok', 'TOTP 二次验证已关闭。');
        }
        redirect('security_center.php');
    }
}

$totpEnabled = (int)($info['totp_enabled'] ?? 0) === 1 && (string)($info['totp_secret'] ?? '') !== '';
$setup = $_SESSION['admin_totp_setup'] ?? null;
$setupActive = is_array($setup) && !empty($setup['secret']) && time() - (int)($setup['time'] ?? 0) <= 600;
$publicKey = license_api_rsa_public_pem();
$flash = flash_take();

admin_header('加密与验证', 'security', $admin);
?>
<?php if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<div class="grid-2">
    <div>
        <section class="card">
            <div class="card-head">
                <span class="card-head-icon icon-blue"><?php echo ui_icon('menu-lock', 'security'); ?></span>
                <div class="card-head-text">
                    <div class="card-head-title">敏感字段加密</div>
                    <div class="card-head-desc">AES-256-GCM · 敏感信息落库即为密文</div>
                </div>
                <span class="card-head-tag <?php echo extension_loaded('openssl') ? 'tag-green' : 'tag-orange'; ?>"><?php echo extension_loaded('openssl') ? '运行中' : 'OpenSSL 缺失'; ?></span>
            </div>
            <p class="card-desc">实名身份证等敏感字段使用 <b>AES-256-GCM</b> 加密存储，格式为 <code>enc2:</code>；历史 <code>enc1:</code> 数据保持兼容解密。</p>
            <div class="table-wrap">
                <table class="tb tb-compact">
                    <tbody>
                    <tr><th style="width:120px">当前算法</th><td class="cell-wrap"><b>AES-256-GCM</b>（12 字节 IV + 16 字节认证标签）</td></tr>
                    <tr><th>密钥来源</th><td class="cell-wrap"><code>sys_encrypt_key</code> 经 SHA-256 派生</td></tr>
                    <tr><th>旧数据兼容</th><td class="cell-wrap">支持 <code>enc1:</code> AES-128-CBC 解密</td></tr>
                    <tr><th>OpenSSL</th><td><?php echo extension_loaded('openssl') ? '已加载' : '未加载'; ?></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div>
        <section class="card">
            <div class="card-head">
                <span class="card-head-icon icon-orange"><?php echo ui_icon('menu-key', 'license'); ?></span>
                <div class="card-head-text">
                    <div class="card-head-title">授权 API 加密与签名</div>
                    <div class="card-head-desc">payload 加密请求 · RSA-SHA256 响应验签</div>
                </div>
                <span class="card-head-tag <?php echo $publicKey !== '' ? 'tag-green' : 'tag-orange'; ?>"><?php echo $publicKey !== '' ? '已就绪' : '待生成'; ?></span>
            </div>
            <p class="card-desc">授权校验接口支持 <b>AES-256-GCM</b> 加密请求体，响应自动附带 <b>RSA-2048 / RSA-SHA256</b> 签名；原 HMAC v2 请求签名继续兼容。</p>
            <div class="table-wrap">
                <table class="tb tb-compact">
                    <tbody>
                    <tr><th style="width:120px">请求加密</th><td class="cell-wrap"><code>payload</code> = AES-256-GCM 密文（用 app_secret 派生密钥）</td></tr>
                    <tr><th>响应签名</th><td class="cell-wrap"><code>rsaSign</code> / <code>signAlg=RSA-SHA256</code></td></tr>
                    <tr><th>RSA 密钥</th><td><?php echo $publicKey !== '' ? '已生成（2048 位）' : '未生成（请检查 OpenSSL）'; ?></td></tr>
                    <tr><th>兼容策略</th><td>不传 <code>payload</code> 时保持原明文协议</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<section class="card">
    <div class="card-head">
        <span class="card-head-icon icon-blue"><?php echo ui_icon('menu-code', 'docs_api'); ?></span>
        <div class="card-head-text">
            <div class="card-head-title">RSA 公钥</div>
            <div class="card-head-desc">客户端下载后用于离线验签，可随版本更新</div>
        </div>
        <span class="card-head-tag">PEM · 2048</span>
    </div>
    <textarea class="form-input" readonly rows="8" style="font-family:Consolas,Monaco,monospace;font-size:12px"><?php echo e($publicKey); ?></textarea>
    <p class="card-desc" style="margin:10px 0 0">响应验签原文为移除 <code>rsaSign</code>、<code>signAlg</code> 后的 JSON，字段顺序与接口返回一致。</p>
</section>

<section class="card">
    <div class="card-head">
        <span class="card-head-icon icon-orange"><?php echo ui_icon('menu-lock', 'alert'); ?></span>
        <div class="card-head-text">
            <div class="card-head-title">管理员 TOTP 二次验证</div>
            <div class="card-head-desc">登录密码 + 6 位动态验证码双层校验</div>
        </div>
        <span class="card-head-tag <?php echo $totpEnabled ? 'tag-green' : ''; ?>"><?php echo $totpEnabled ? '已开启' : '未开启'; ?></span>
    </div>
    <p class="card-desc">开启后，管理员登录在密码校验通过后还需输入身份验证器中的 6 位动态验证码。</p>

    <?php if ($totpEnabled): ?>
    <div class="alert alert-ok" style="margin-top:14px">
        <img src="../assets/svg/check-circle.svg" alt="">
        <span>当前账号已开启 TOTP 二次验证。</span>
    </div>
    <form method="post" style="max-width:420px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="totp_disable">
        <div class="form-item">
            <label class="form-label">动态验证码</label>
            <input class="form-input" type="text" name="code" inputmode="numeric" maxlength="6" placeholder="输入 6 位验证码以关闭" required>
        </div>
        <button class="btn-danger" type="submit" data-confirm="确认关闭 TOTP 二次验证？">关闭二次验证</button>
    </form>
    <?php elseif ($setupActive): ?>
    <div class="alert alert-info" style="margin-top:14px">
        <img src="../assets/svg/menu-lock.svg" alt="">
        <span>请在身份验证器中手动添加以下密钥，然后输入动态验证码完成绑定。</span>
    </div>
    <div class="table-wrap" style="max-width:760px">
    <table class="tb">
        <tbody>
        <tr><th style="width:120px">TOTP 密钥</th><td class="cell-wrap"><code><?php echo e((string)$setup['secret']); ?></code></td></tr>
        <tr><th>otpauth URI</th><td class="cell-wrap"><code><?php echo e(sec_totp_uri((string)$setup['secret'], (string)$info['username'])); ?></code></td></tr>
        </tbody>
    </table>
    </div>
    <form method="post" style="max-width:420px;margin-top:14px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="totp_enable">
        <div class="form-item">
            <label class="form-label">动态验证码</label>
            <input class="form-input" type="text" name="code" inputmode="numeric" maxlength="6" placeholder="请输入 6 位动态验证码" required>
        </div>
        <button class="btn-primary" type="submit">完成绑定</button>
        <a class="btn-plain" href="security_center.php?cancel_setup=1">取消</a>
    </form>
    <?php else: ?>
    <form method="post" style="margin-top:14px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="totp_start">
        <button class="btn-primary" type="submit">生成密钥并开启</button>
    </form>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <span class="card-head-icon icon-blue"><?php echo ui_icon('group-safe', 'security'); ?></span>
        <div class="card-head-text">
            <div class="card-head-title">其他安全策略</div>
            <div class="card-head-desc">密码、登录与接口层的默认防护策略</div>
        </div>
    </div>
    <div class="sec-grid">
        <div class="sec-item">
            <span class="sec-item-ico"><?php echo ui_icon('menu-lock', 'security'); ?></span>
            <div class="sec-item-body">
                <div class="sec-item-title">密码存储</div>
                <div class="sec-item-desc">password_hash（bcrypt）单向散列，数据库中不保存明文密码。</div>
            </div>
        </div>
        <div class="sec-item">
            <span class="sec-item-ico"><?php echo ui_icon('menu-key', 'license'); ?></span>
            <div class="sec-item-body">
                <div class="sec-item-title">密码强度</div>
                <div class="sec-item-desc">8 ~ 64 位，至少包含字母、数字、符号中的两类，且不能与账号相同。</div>
            </div>
        </div>
        <div class="sec-item">
            <span class="sec-item-ico"><?php echo ui_icon('menu-activity', 'alert'); ?></span>
            <div class="sec-item-body">
                <div class="sec-item-title">登录失败锁定</div>
                <div class="sec-item-desc">达到系统配置的失败次数后锁定 15 分钟，防止暴力破解。</div>
            </div>
        </div>
        <div class="sec-item">
            <span class="sec-item-ico"><?php echo ui_icon('group-safe', 'health'); ?></span>
            <div class="sec-item-body">
                <div class="sec-item-title">接口防重放</div>
                <div class="sec-item-desc">HMAC-SHA256 v2 + timestamp（±10 分钟）+ nonce + IP 限流。</div>
            </div>
        </div>
    </div>
</section>
<?php admin_footer(); ?>
