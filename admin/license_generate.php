<?php
// 管理员后台 - 卡密生成
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$act   = (string)($_GET['act'] ?? 'generate');
$error = '';

$methodNames = [1 => '单域名', 2 => '泛域名', 3 => 'IP', 4 => '密钥'];

function gen_card_no(string $prefix): string
{
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'VIP');
    if (strlen($prefix) > 12) {
        $prefix = substr($prefix, 0, 12);
    }
    $hex = strtoupper(bin2hex(random_bytes(8)));
    return $prefix . '-' . implode('-', str_split($hex, 4));
}

function method_name(int $m): string
{
    return [1 => '单域名', 2 => '泛域名', 3 => 'IP', 4 => '密钥'][$m] ?? '未知';
}

if ($act === 'export') {
    $batch  = $_SESSION['gen_batch'] ?? null;
    $format = (string)($_GET['format'] ?? 'txt');
    if (!$batch || empty($batch['cards'])) {
        http_response_code(404);
        exit('没有可导出的批次，请先生成卡密。');
    }
    $cards = $batch['cards'];
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="licenses_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['卡密', '应用', '授权方式', '有效期至', '站点上限', '价格', '生成时间']);
        foreach ($cards as $c) {
            fputcsv($out, [
                $c['card_no'], $c['app_name'], method_name((int)$c['target_type']),
                (int)$c['expire_time'] > 0 ? date('Y-m-d H:i', (int)$c['expire_time']) : '永久',
                $c['max_devices'], $c['price'], date('Y-m-d H:i', (int)$c['created_at']),
            ]);
        }
        fclose($out);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="licenses_' . date('Ymd_His') . '.txt"');
        foreach ($cards as $c) {
            echo $c['card_no'] . PHP_EOL;
        }
    }
    audit('license', 'batch_export', 1, (int)$admin['id'], '导出 ' . count($cards) . ' 张卡密（' . strtoupper($format) . '）');
    exit;
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'generate') {
        $appId      = (int)($_POST['app_id'] ?? 0);
        $authMethod = (int)($_POST['auth_method'] ?? 0);
        $count      = (int)($_POST['count'] ?? 1);
        $prefix     = trim((string)($_POST['prefix'] ?? 'VIP'));
        $days       = (int)($_POST['days'] ?? 0);
        $devices    = (int)($_POST['max_devices'] ?? 0);
        $price      = round((float)($_POST['price'] ?? 0), 2);
        $remark     = trim((string)($_POST['remark'] ?? ''));
        $domainsRaw = preg_split('/[\r\n]+/', trim((string)($_POST['domains'] ?? ''))) ?: [];

        try {
            $stmt = db()->prepare('SELECT * FROM `app` WHERE `id`=?');
            $stmt->execute([$appId]);
            $app = $stmt->fetch();
            if (!$app || (int)$app['status'] !== 1) {
                throw new RuntimeException('应用不存在或已停用，无法生成卡密。');
            }
            $allowed = array_map('intval', array_filter(explode(',', (string)$app['auth_methods'])));
            if (!in_array($authMethod, [1, 2, 3, 4], true) || !in_array($authMethod, $allowed, true)) {
                throw new RuntimeException('该应用未开放所选授权方式，请重新选择。');
            }
            if ($count < 1 || $count > 1000) {
                throw new RuntimeException('生成数量需在 1 ~ 1000 之间。');
            }
            if ($days < 0 || $days > 36500) {
                throw new RuntimeException('授权天数需在 0 ~ 36500 之间（0 表示永久）。');
            }
            if ($devices < 1 || $devices > 999) {
                throw new RuntimeException('站点上限需在 1 ~ 999 之间。');
            }
            if ($price < 0 || $price > 999999) {
                throw new RuntimeException('价格不合法（0 ~ 999999）。');
            }
            if (mb_strlen($remark) > 255) {
                throw new RuntimeException('备注不能超过 255 字。');
            }
            if (count(array_filter($domainsRaw)) > 100) {
                throw new RuntimeException('绑定目标最多 100 个。');
            }
            if ($authMethod !== 4 && count(array_filter(array_map('trim', $domainsRaw))) === 0) {
                throw new RuntimeException('该授权方式需至少填写一个绑定目标（域名 / 泛域名 / IP）。');
            }

            $domains = [];
            foreach ($domainsRaw as $d) {
                $d = trim($d);
                if ($d === '') {
                    continue;
                }
                if ($authMethod === 3) {
                    if (filter_var($d, FILTER_VALIDATE_IP)) {
                        $domains[$d] = true;
                    }
                } elseif ($authMethod === 2) {
                    $n = sec_normalize_domain(ltrim($d, '*.'));
                    if ($n !== '' && !str_contains($n, ':')) {
                        $domains['*.' . $n] = true;
                    }
                } else {
                    $n = sec_normalize_domain($d);
                    if ($n !== '') {
                        $domains[$n] = true;
                    }
                }
            }
            $domains = array_keys($domains);
            if ($authMethod !== 4 && !$domains) {
                throw new RuntimeException('绑定目标格式不合法，请检查域名 / 泛域名 / IP 填写。');
            }

            $now       = time();
            $type      = $days > 0 ? 1 : 3;
            $startTime = $now;
            $expire    = $type === 1 ? $now + $days * 86400 : 0;

            $insert = db()->prepare(
                'INSERT INTO `license` (`card_no`,`app_id`,`type`,`target_type`,`start_time`,`expire_time`,`total_count`,`used_count`,`max_devices`,`price`,`status`,`owner_type`,`owner_id`,`remark`,`created_at`) '
                . 'VALUES (?,?,?,?,?,?,0,0,?,?,1,1,?,?,?)'
            );
            $domainStmt = db()->prepare(
                'INSERT IGNORE INTO `license_domain` (`license_id`,`domain`,`target_type`,`is_https`,`status`,`created_at`) VALUES (?,?,?,0,1,?)'
            );

            $cards     = [];
            $generated = 0;
            $tries     = 0;
            try {
                db()->beginTransaction();
                while ($generated < $count && $tries < $count * 5 + 10) {
                    $tries++;
                    $cardNo = gen_card_no($prefix);
                    try {
                        $insert->execute([$cardNo, $appId, $type, $authMethod, $startTime, $expire, $devices, number_format($price, 2, '.', ''), (int)$admin['id'], $remark, $now]);
                    } catch (PDOException $dup) {
                        if ($dup->getCode() !== '23000') {
                            throw $dup;
                        }
                        continue;
                    }
                    $licenseId = (int)db()->lastInsertId();
                    foreach ($domains as $d) {
                        $domainStmt->execute([$licenseId, $d, $authMethod, $now]);
                    }
                    $cards[] = [
                        'card_no'     => $cardNo,
                        'app_name'    => $app['name'],
                        'target_type' => $authMethod,
                        'days'        => $days,
                        'expire_time' => $expire,
                        'max_devices' => $devices,
                        'price'       => number_format($price, 2, '.', ''),
                        'created_at'  => $now,
                    ];
                    $generated++;
                }
                db()->commit();
            } catch (Throwable $ex) {
                db()->rollBack();
                throw new RuntimeException('生成失败：' . $ex->getMessage());
            }

            $_SESSION['gen_batch'] = ['cards' => $cards];
            audit('license', 'batch_generate', 1, (int)$admin['id'],
                '应用：' . $app['name'] . ' 授权方式：' . method_name($authMethod) . ' 天数：' . $days . ' 数量：' . $generated . ' 绑定：' . ($domains ? implode(',', $domains) : '无'));
            flash_set('ok', '成功生成 ' . $generated . ' 张卡密（' . method_name($authMethod) . ' · ' . ($days > 0 ? $days . ' 天' : '永久') . '）。');
            redirect('license_generate.php?act=result');
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }
}

$batch = $act === 'result' ? ($_SESSION['gen_batch'] ?? null) : null;
if ($act === 'result' && (!$batch || empty($batch['cards']))) {
    $act = 'generate';
}

$apps = db()->query('SELECT `id`,`name`,`max_devices`,`auth_methods` FROM `app` WHERE `status`=1 ORDER BY `id` DESC')->fetchAll();

admin_header('批量生成卡密', 'license', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif;
if ($error !== ''): ?>
<div class="alert alert-error">
    <img src="../assets/svg/warn-circle.svg" alt="">
    <span><?php echo e($error); ?></span>
</div>
<?php endif; ?>

<?php if ($act === 'generate'): ?>
<section class="card">
    <div style="margin-bottom:14px">
        <h2 class="card-title">批量生成卡密</h2>
        <p class="card-desc" style="margin-bottom:0">卡密号规则：前缀-XXXX-XXXX-XXXX-XXXX。授权天数 0 表示永久；单域名 / 泛域名 / IP 授权需填写绑定目标，密钥授权不绑定目标、按站点上限控制。</p>
    </div>
    <?php if (!$apps): ?>
    <div class="alert alert-info">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>暂无启用的应用，请先到「应用管理」新建应用。</span>
    </div>
    <?php endif; ?>
    <form method="post" class="form" style="max-width:760px" id="genForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="generate">
        <div class="form-row">
            <div class="form-item">
                <label class="form-label"><i class="req">*</i>所属应用</label>
                <select class="form-input" name="app_id" id="genApp" required <?php echo !$apps ? 'disabled' : ''; ?>>
                    <?php foreach ($apps as $a): ?>
                    <option value="<?php echo (int)$a['id']; ?>" data-methods="<?php echo e($a['auth_methods']); ?>" data-devices="<?php echo (int)$a['max_devices']; ?>">
                        <?php echo e($a['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-item">
                <label class="form-label">授权方式</label>
                <select class="form-input" name="auth_method" id="genAuthMethod"></select>
                <div class="form-hint" id="genAuthHint"></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">快捷时长</label>
                <div class="quick-days" id="genQuick">
                    <button type="button" data-days="30">1个月</button>
                    <button type="button" data-days="90">3个月</button>
                    <button type="button" data-days="365">1年</button>
                    <button type="button" data-days="0">永久</button>
                </div>
            </div>
            <div class="form-item">
                <label class="form-label">授权天数</label>
                <div class="stepper">
                    <input class="form-input" type="text" inputmode="numeric" name="days" id="genDays" value="30">
                    <span class="step-btns">
                        <button type="button" class="step-up" data-target="genDays" data-step="1" aria-label="增加">▲</button>
                        <button type="button" class="step-down" data-target="genDays" data-step="1" aria-label="减少">▼</button>
                    </span>
                </div>
                <span class="step-hint">0 表示永久</span>
            </div>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">生成数量</label>
                <div class="stepper">
                    <input class="form-input" type="text" inputmode="numeric" name="count" id="genCount" value="10">
                    <span class="step-btns">
                        <button type="button" class="step-up" data-target="genCount" data-step="1" aria-label="增加">▲</button>
                        <button type="button" class="step-down" data-target="genCount" data-step="1" aria-label="减少">▼</button>
                    </span>
                </div>
                <span class="step-hint">1 ~ 1000 张</span>
            </div>
            <div class="form-item">
                <label class="form-label">卡密前缀</label>
                <input class="form-input" type="text" name="prefix" maxlength="12" value="VIP" placeholder="仅字母数字">
            </div>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">站点上限</label>
                <div class="stepper">
                    <input class="form-input" type="text" inputmode="numeric" name="max_devices" id="genDevices" value="1">
                    <span class="step-btns">
                        <button type="button" class="step-up" data-target="genDevices" data-step="1" aria-label="增加">▲</button>
                        <button type="button" class="step-down" data-target="genDevices" data-step="1" aria-label="减少">▼</button>
                    </span>
                </div>
                <span class="step-hint">单卡可绑定站点数</span>
            </div>
            <div class="form-item">
                <label class="form-label">价格（元）</label>
                <div class="stepper">
                    <input class="form-input" type="text" inputmode="decimal" name="price" id="genPrice" value="0.00">
                    <span class="step-btns">
                        <button type="button" class="step-up" data-target="genPrice" data-step="1" aria-label="增加">▲</button>
                        <button type="button" class="step-down" data-target="genPrice" data-step="1" aria-label="减少">▼</button>
                    </span>
                </div>
            </div>
        </div>
        <div class="form-item" id="genRowTargets">
            <label class="form-label" id="genTargetLabel">绑定域名（每行一个）</label>
            <textarea class="form-input" name="domains" rows="3" id="genTargetInput" placeholder="example.com&#10;api.example.com"></textarea>
            <div class="form-hint" id="genTargetHint">绑定后验证接口强制校验授权目标；留空则后续在卡密详情中绑定。</div>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">备注</label>
                <input class="form-input" type="text" name="remark" maxlength="255" placeholder="批次用途、客户信息（可选）">
            </div>
        </div>
        <div class="page-actions">
            <a class="btn-plain" href="license_manage.php">卡密列表</a>
            <button class="btn-primary" type="submit" <?php echo !$apps ? 'disabled' : ''; ?>>立即生成</button>
        </div>
    </form>
</section>

<script>
(function () {
    var appSel = document.getElementById('genApp');
    if (!appSel) return;
    var authSel = document.getElementById('genAuthMethod');
    var authHint = document.getElementById('genAuthHint');
    var daysInput = document.getElementById('genDays');
    var priceInput = document.getElementById('genPrice');
    var devicesInput = document.getElementById('genDevices');
    var quickBox = document.getElementById('genQuick');
    var rowTargets = document.getElementById('genRowTargets');
    var targetLabel = document.getElementById('genTargetLabel');
    var targetInput = document.getElementById('genTargetInput');
    var targetHint = document.getElementById('genTargetHint');

    var methodNames = { 1: '单域名', 2: '泛域名', 3: 'IP', 4: '密钥' };
    var targetMap = {
        1: { label: '绑定域名（每行一个）', ph: 'example.com\napi.example.com', hint: '精确匹配访问域名；绑定后验证接口强制校验，留空则后续在卡密详情中绑定。' },
        2: { label: '泛域名后缀（每行一个）', ph: 'example.com\nshop.example.com', hint: '填写 example.com 即代表 *.example.com，匹配其所有子域名。' },
        3: { label: '服务器 IP（每行一个）', ph: '203.0.113.10', hint: '仅接受合法 IPv4 / IPv6 地址，校验请求来源 serverIp。' }
    };

    function appMethods() {
        var opt = appSel.options[appSel.selectedIndex];
        var raw = (opt && opt.getAttribute('data-methods')) || '';
        return raw ? raw.split(',').filter(function (v) { return ['1', '2', '3', '4'].indexOf(v) >= 0; }) : [];
    }

    function rebuildAuth() {
        var list = appMethods();
        authSel.innerHTML = '';
        list.forEach(function (v) {
            var o = document.createElement('option');
            o.value = v;
            o.textContent = methodNames[v] + '授权';
            authSel.appendChild(o);
        });
        authHint.textContent = list.length
            ? '仅显示当前应用已配置的授权方式：' + list.map(function (v) { return methodNames[v]; }).join('、')
            : '该应用未开放授权方式，请先到「应用管理」勾选。';
        syncTarget();
    }

    function syncTarget() {
        var m = parseInt(authSel.value, 10);
        var hide = m === 4 || isNaN(m);
        rowTargets.style.display = hide ? 'none' : '';
        if (!hide) {
            var conf = targetMap[m] || targetMap[1];
            targetLabel.textContent = conf.label;
            targetInput.placeholder = conf.ph;
            targetHint.textContent = conf.hint;
        }
    }

    function syncQuick() {
        var d = parseInt(daysInput.value, 10);
        if (isNaN(d)) d = -1;
        Array.prototype.forEach.call(quickBox.children, function (b) {
            b.classList.toggle('active', parseInt(b.getAttribute('data-days'), 10) === d);
        });
    }

    appSel.addEventListener('change', function () {
        rebuildAuth();
        var opt = appSel.options[appSel.selectedIndex];
        var dv = parseInt(opt && opt.getAttribute('data-devices'), 10);
        if (!isNaN(dv) && dv > 0) devicesInput.value = String(dv);
    });
    authSel.addEventListener('change', syncTarget);
    daysInput.addEventListener('input', syncQuick);

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        var stepBtn = t && t.closest ? t.closest('.step-btns button') : null;
        if (stepBtn && document.getElementById(stepBtn.getAttribute('data-target'))) {
            var input = document.getElementById(stepBtn.getAttribute('data-target'));
            var step = parseFloat(stepBtn.getAttribute('data-step')) || 1;
            var up = stepBtn.classList.contains('step-up');
            var val = parseFloat(input.value);
            if (isNaN(val)) val = 0;
            val = Math.round((val + (up ? step : -step)) * 100) / 100;
            if (val < 0) val = 0;
            input.value = input === priceInput ? val.toFixed(2) : String(Math.round(val));
            if (input === daysInput) syncQuick();
            return;
        }
        var q = t && t.closest ? t.closest('#genQuick button') : null;
        if (q) {
            daysInput.value = q.getAttribute('data-days');
            syncQuick();
        }
    });

    rebuildAuth();
    syncQuick();
})();
</script>

<?php else:
    $cards = $batch['cards'];
?>
<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">本批次生成结果（<?php echo count($cards); ?> 张）</h2>
            <p class="card-desc" style="margin-bottom:0">批次暂存于当前会话，再次生成将覆盖；请及时导出留存。</p>
        </div>
        <div class="spacer"></div>
        <a class="btn-plain" href="license_generate.php?act=export&format=csv">导出 CSV</a>
        <a class="btn-plain" href="license_generate.php?act=export&format=txt">导出 TXT</a>
        <a class="btn-primary" href="license_generate.php">继续生成</a>
    </div>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>#</th><th>卡密</th><th>授权方式</th><th>有效期至</th><th>站点上限</th><th>价格</th></tr></thead>
            <tbody>
            <?php foreach ($cards as $i => $c): ?>
            <tr>
                <td class="cell-code"><?php echo $i + 1; ?></td>
                <td class="cell-code cell-main"><?php echo e($c['card_no']); ?></td>
                <td><span class="tag tag-info"><?php echo method_name((int)$c['target_type']); ?></span></td>
                <td class="cell-sub"><?php echo (int)$c['expire_time'] > 0 ? date('Y-m-d H:i', (int)$c['expire_time']) : '永久'; ?></td>
                <td><?php echo (int)$c['max_devices']; ?></td>
                <td class="cell-sub"><?php echo e($c['price']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
