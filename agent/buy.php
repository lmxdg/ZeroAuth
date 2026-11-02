<?php
// 代理商中心 - 开通授权（四步：选择应用/授权配置/支付购买/生成授权）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Pay.php';
require dirname(__DIR__) . '/core/AgentLevel.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/layout_agent.php';

$agent = agent_require_login();
$aid   = (int)$agent['id'];
$step  = max(1, min(3, (int)($_GET['step'] ?? 1)));
$done  = (string)($_GET['done'] ?? '');

$authTypes = [
    1 => ['label' => '单域名', 'name' => '单域名授权', 'ph' => '如：example.com', 'hint' => '填写一个具体域名，授权后仅该域名可用。'],
    2 => ['label' => '泛域名', 'name' => '泛域名授权', 'ph' => '如：*.example.com', 'hint' => '填写泛域名，自动匹配其所有子域名。'],
    3 => ['label' => 'IP地址', 'name' => 'IP授权', 'ph' => '如：1.2.3.4', 'hint' => '填写服务器公网 IP，仅该 IP 可用。'],
    4 => ['label' => '密钥', 'name' => '密钥授权', 'ph' => '系统自动生成密钥', 'hint' => '留空由系统自动生成密钥，密钥即唯一授权凭证。'],
];

// 归一化并校验授权目标（域名/泛域名/IP/自定义密钥）
$norm_target = static function (int $t, string $raw): string {
    $raw = trim($raw);
    if ($t === 4) {
        if ($raw === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{3,63}$/', $raw)) {
            throw new RuntimeException('自定义密钥需为 4~64 位字母、数字、- 或 _，且以字母或数字开头。');
        }
        return $raw;
    }
    if ($raw === '') {
        throw new RuntimeException('请填写授权目标。');
    }
    if ($t === 3) {
        if (!filter_var($raw, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('IP 地址格式不正确。');
        }
        return $raw;
    }
    if ($t === 2) {
        $n = sec_normalize_domain(ltrim($raw, '*.'));
        if ($n === '' || str_contains($n, ':')) {
            throw new RuntimeException('泛域名格式不正确，如 *.example.com。');
        }
        return '*.' . $n;
    }
    $n = sec_normalize_domain($raw);
    if ($n === '' || str_contains($n, ':')) {
        throw new RuntimeException('域名格式不正确，如 example.com。');
    }
    return $n;
};

if (is_post()) {
    csrf_check();
    $appId     = (int)($_POST['app_id'] ?? 0);
    $packageId = (int)($_POST['package_id'] ?? 0);
    $quantity  = max(1, min(100, (int)($_POST['quantity'] ?? 1)));
    $channel   = (string)($_POST['channel'] ?? '');
    $authType  = (int)($_POST['auth_type'] ?? 0);
    $authRaw   = (string)($_POST['auth_target'] ?? '');

    $back = 'buy.php?step=3&app_id=' . $appId . '&package_id=' . $packageId
        . '&quantity=' . $quantity . '&auth_type=' . $authType . '&auth_target=' . urlencode($authRaw);
    try {
        $stmt = db()->prepare("SELECT * FROM `app` WHERE `id`=? AND `status`=1 AND `auth_methods`<>''");
        $stmt->execute([$appId]);
        $app = $stmt->fetch();
        if (!$app) {
            throw new RuntimeException('应用不存在。');
        }
        $methods = array_map('intval', array_filter(explode(',', (string)$app['auth_methods'])));
        if (!isset($authTypes[$authType]) || !in_array($authType, $methods, true)) {
            throw new RuntimeException('请选择有效的授权类型。');
        }
        $stmt = db()->prepare('SELECT * FROM `app_package` WHERE `id`=?');
        $stmt->execute([$packageId]);
        $pkg = $stmt->fetch();
        if (!$pkg) {
            throw new RuntimeException('套餐不存在，请重新选择。');
        }
        $target = $norm_target($authType, $authRaw);
        if ($authType === 4 && $target !== '') {
            $st = db()->prepare('SELECT COUNT(*) FROM `license` WHERE `card_no`=?');
            $st->execute([$target]);
            if ((int)$st->fetchColumn() > 0) {
                throw new RuntimeException('该密钥已被占用，请更换一个。');
            }
        }
        $order = pay_create_order_agent($agent, [
            'app'         => $app,
            'package'     => $pkg,
            'quantity'    => $quantity,
            'auth_type'   => $authType,
            'auth_target' => $target,
        ]);
        audit('pay', 'agent_order_create', 2, $aid,
            '订单：' . $order['order_no'] . ' 金额：' . $order['amount']
            . ($order['discount_name'] !== '' ? '（优惠：' . $order['discount_name'] . '）' : ''));

        if ($channel === 'balance') {
            $paid = pay_mark_paid($order['order_no'], 'balance', '');
            if (!$paid['ok']) {
                throw new RuntimeException($paid['msg']);
            }
            redirect('buy.php?done=' . $order['order_no']);
        }
        redirect('pay.php?order_no=' . $order['order_no']);
    } catch (RuntimeException $ex) {
        flash_set('err', $ex->getMessage());
        redirect($back);
    }
}

$apps = db()->query(
    'SELECT a.`id`,a.`name`,a.`remark`,COUNT(p.`id`) AS `pkg_count`,'
    . 'MIN(CASE WHEN p.`price`>0 THEN p.`price` ELSE a.`price` END) AS `min_price` '
    . 'FROM `app` a JOIN `app_package` p ON p.`app_id`=a.`id` AND p.`status`=1 '
    . "WHERE a.`status`=1 AND a.`auth_methods`<>'' "
    . 'GROUP BY a.`id`,a.`name`,a.`remark` '
    . 'HAVING `min_price`>0 ORDER BY a.`id` DESC'
)->fetchAll();

$app = null;
$pkgs = [];
$pkg = null;
$preview = null;
$methods = [];
$authType = 0;
$authTarget = '';
$quantity = 1;
$prePkgId = 0;
$channels = pay_channels();
$balanceEnabled = pay_balance_enabled();
$agentRate = min(1, max(0.01, agent_effective_discount($agent)));

if ($step >= 2) {
    $stmt = db()->prepare("SELECT * FROM `app` WHERE `id`=? AND `status`=1 AND `auth_methods`<>''");
    $stmt->execute([(int)($_REQUEST['app_id'] ?? 0)]);
    $app = $stmt->fetch() ?: null;
    if (!$app) {
        $step = 1;
    } else {
        $methods = array_values(array_map('intval', array_filter(explode(',', (string)$app['auth_methods']))));
        $authType = (int)($_REQUEST['auth_type'] ?? 0);
        if (!in_array($authType, $methods, true)) {
            $authType = $methods[0] ?? 0;
        }
        $authTarget = trim((string)($_REQUEST['auth_target'] ?? ''));
        if ($authTarget !== '') {
            try {
                $authTarget = $authType === 4 ? $authTarget : $norm_target($authType, $authTarget);
            } catch (RuntimeException $ex) {
                $authTarget = '';
            }
        }
        $prePkgId = (int)($_REQUEST['package_id'] ?? 0);
        $quantity = max(1, min(100, (int)($_REQUEST['quantity'] ?? 1)));
        $stmt = db()->prepare('SELECT * FROM `app_package` WHERE `app_id`=? AND `status`=1 ORDER BY `sort` DESC,`id` ASC');
        $stmt->execute([(int)$app['id']]);
        $pkgs = $stmt->fetchAll();
        if ($step >= 3) {
            $stmt = db()->prepare('SELECT * FROM `app_package` WHERE `id`=? AND `app_id`=? AND `status`=1');
            $stmt->execute([(int)($_REQUEST['package_id'] ?? 0), (int)$app['id']]);
            $pkg = $stmt->fetch() ?: null;
            if (!$pkg || ((int)$pkg['auth_method'] > 0 && (int)$pkg['auth_method'] !== $authType)) {
                $pkg = null;
                $step = 2;
            } else {
                $price = (float)$pkg['price'] > 0 ? (float)$pkg['price'] : (float)$app['price'];
                $total = round($price * $quantity, 2);
                $disc = pay_calc_discount($total, $quantity, 0, (int)$app['id']);
                $agentCut = round($total * (1 - $agentRate), 2);
                if ($agentCut > $disc['discount_amount']) {
                    $disc = ['discount_id' => 0, 'discount_amount' => $agentCut, 'discount_name' => '代理等级折扣'];
                }
                $preview = [
                    'quantity' => $quantity,
                    'price'    => $price,
                    'total'    => $total,
                    'disc'     => $disc,
                    'pay'      => max(0.01, round($total - $disc['discount_amount'], 2)),
                ];
            }
        }
    }
}

// 时长文案（限时/计次/永久）
$pkgDur = static function (array $p): string {
    $t = (int)$p['license_type'];
    if ($t === 1) {
        return (int)$p['duration_days'] . ' 天';
    }
    if ($t === 2) {
        return '计次 ' . (int)$p['total_count'] . ' 次';
    }
    return '永久';
};

$paidOrder = null;
$paidCards = [];
$doneApp = '';
$donePkg = '';
$balanceNow = null;
if ($done !== '') {
    $stmt = db()->prepare('SELECT * FROM `order` WHERE `order_no`=? AND `agent_id`=? AND `pay_status`=1');
    $stmt->execute([$done, $aid]);
    $paidOrder = $stmt->fetch() ?: null;
    if ($paidOrder) {
        $stmt = db()->prepare('SELECT `card_no` FROM `license` WHERE `remark`=? ORDER BY `id` ASC');
        $stmt->execute(['订单购买 ' . $paidOrder['order_no']]);
        $paidCards = array_column($stmt->fetchAll(), 'card_no');
        $stmt = db()->prepare('SELECT `name` FROM `app` WHERE `id`=?');
        $stmt->execute([(int)$paidOrder['app_id']]);
        $doneApp = (string)$stmt->fetchColumn();
        if ((int)$paidOrder['package_id'] > 0) {
            $stmt = db()->prepare('SELECT `name` FROM `app_package` WHERE `id`=?');
            $stmt->execute([(int)$paidOrder['package_id']]);
            $donePkg = (string)$stmt->fetchColumn();
        }
        if ($donePkg === '') {
            $donePkg = trim(mb_substr((string)$paidOrder['subject'], mb_strlen($doneApp)));
            $donePkg = preg_replace('/\s*x\d+$/u', '', $donePkg);
        }
        $stmt = db()->prepare('SELECT `balance` FROM `agent` WHERE `id`=?');
        $stmt->execute([$aid]);
        $balanceNow = (float)$stmt->fetchColumn();
    } else {
        $done = '';
    }
}

agent_header('开通授权', 'buy', $agent);

// 内联 SVG 图标
$icoSvg = static function (string $inner): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
};
$icoGlobe  = $icoSvg('<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>');
$icoStar   = $icoSvg('<line x1="12" y1="4" x2="12" y2="20"/><line x1="5" y1="8" x2="19" y2="16"/><line x1="19" y1="8" x2="5" y2="16"/>');
$icoIp     = $icoSvg('<rect x="2" y="4" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="18" x2="12" y2="21"/>');
$icoKey    = $icoSvg('<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>');
$icoCheck  = '<svg viewBox="0 0 24 24" fill="none" stroke="#FFF" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
$icoDoc    = $icoSvg('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>');
$icoCard   = $icoSvg('<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>');
$icoShield = $icoSvg('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>');
$authIcons = [1 => $icoGlobe, 2 => $icoStar, 3 => $icoIp, 4 => $icoKey];
$jsMeta = [];
foreach ($authTypes as $k => $t) {
    $jsMeta[$k] = ['label' => $t['label'], 'ph' => $t['ph'], 'hint' => $t['hint']];
}
?>

<style>

.steps{display:flex;align-items:center;max-width:720px;margin:0 auto 24px}
.step{display:flex;align-items:center;gap:8px;flex:none}
.step-num{width:26px;height:26px;border-radius:50%;background:#C9CDD4;color:#FFF;font-size:13px;display:flex;align-items:center;justify-content:center;font-weight:500}
.step.done .step-num{background:var(--ok)}
.step.cur .step-num{background:var(--p)}
.step-name{font-size:14px;color:var(--t3)}
.step.cur .step-name{color:var(--p);font-weight:500}
.step.done .step-name{color:var(--t2)}
.step-line{flex:1;height:2px;background:var(--bd);margin:0 14px;min-width:20px;border-radius:1px}
.step-line.on{background:var(--p)}
.step-btns{display:flex;justify-content:center;gap:12px;margin-top:24px}
.sel-badge{display:none;position:absolute;top:10px;left:10px;width:20px;height:20px;border-radius:50%;background:var(--p);align-items:center;justify-content:center;z-index:1}
.sel-badge svg{width:11px;height:11px}
.app-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;max-width:940px;margin:0 auto}
.app-card{position:relative;background:var(--card);border:1px solid var(--bd);border-radius:8px;padding:26px 20px 20px;cursor:pointer;text-align:center;transition:border-color .15s,box-shadow .15s}
.app-card:hover{border-color:var(--p)}
.app-card.on{border-color:var(--p);box-shadow:0 0 0 1px var(--p)}
.app-card.on .sel-badge{display:flex}
.app-icon{width:64px;height:64px;border-radius:14px;background:var(--p-bg);display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
.app-icon img{width:30px;height:30px}
.app-name{font-size:15px;font-weight:600;color:var(--t1)}
.app-sub{font-size:12px;color:var(--t3);margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.app-foot{display:flex;align-items:baseline;justify-content:space-between;margin-top:20px}
.app-price{font-size:20px;font-weight:700;color:var(--p);font-family:Consolas,Monaco,monospace}
.app-price small{font-size:12px;font-weight:400;color:var(--t3);font-family:inherit;margin-left:2px}
.app-count{font-size:12px;color:var(--t3)}
.price-sale{font-size:12px;font-weight:400;color:var(--t3);text-decoration:line-through;margin-left:6px;font-family:inherit}
.cfg-wrap{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:16px;align-items:start}
.sec-title{font-size:14px;font-weight:600;color:var(--t1);margin:22px 0 12px}
.sec-title:first-child{margin-top:0}
.sec-title.with-ico{display:flex;align-items:center;gap:8px}
.sec-title.with-ico svg{width:17px;height:17px;color:var(--p);flex:none}
.auth-types{display:flex;gap:10px;flex-wrap:wrap}
.auth-type{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 16px;border:1px solid var(--bd);border-radius:999px;background:var(--card);color:var(--t2);font-size:13px;cursor:pointer;transition:all .15s}
.auth-type svg{width:15px;height:15px}
.auth-type:hover{border-color:var(--p);color:var(--p)}
.auth-type.on{border-color:var(--p);background:var(--p-bg);color:var(--p)}
.target-box{position:relative;max-width:420px}
.target-box .tb-ico{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--t3);line-height:0}
.target-box .tb-ico i{display:none;font-style:normal}
.target-box .tb-ico i.show{display:block}
.target-box .tb-ico svg{width:15px;height:15px}
.tb-input{width:100%;height:38px;padding:0 12px 0 36px;border:1px solid var(--bd);border-radius:var(--r);color:var(--t1);background:var(--card);outline:none;transition:border-color .15s}
.tb-input:focus{border-color:var(--p);box-shadow:0 0 0 2px rgba(0,110,255,.08)}
.tb-input:disabled{background:var(--bg);color:var(--t3);cursor:not-allowed}
.pkg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px}
.pkg-card{position:relative;background:var(--card);border:1px solid var(--bd);border-radius:8px;padding:20px;cursor:pointer;transition:border-color .15s,box-shadow .15s}
.pkg-card:hover{border-color:var(--p)}
.pkg-card.on{border-color:var(--p);box-shadow:0 0 0 1px var(--p)}
.pkg-card.on .sel-badge{display:flex}
.pkg-name{font-size:15px;font-weight:600;color:var(--t1)}
.pkg-price{font-size:20px;font-weight:700;color:var(--p);font-family:Consolas,Monaco,monospace;margin:10px 0 4px}
.pkg-dur{font-size:12px;color:var(--t3)}
.pkg-empty{padding:20px 0;color:var(--t3);font-size:13px}
.sum-card{background:var(--card);border:1px solid var(--bd);border-radius:8px;overflow:hidden}
.sum-head{display:flex;align-items:center;gap:10px;background:var(--p-bg);padding:14px 16px;color:var(--p);font-size:14px;font-weight:600}
.sum-head img{width:18px;height:18px;flex:none}
.sum-body{padding:4px 16px}
.sum-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid var(--bd);font-size:13px;color:var(--t3)}
.sum-row b{font-weight:500;color:var(--t1);text-align:right;word-break:break-all}
.qty-ctl{display:inline-flex;align-items:center;border:1px solid var(--bd);border-radius:var(--r);overflow:hidden}
.qty-ctl button{width:26px;height:26px;border:none;background:var(--card);color:var(--t2);font-size:14px;cursor:pointer;line-height:1;padding:0}
.qty-ctl button:hover{background:var(--bg);color:var(--p)}
.qty-ctl input{width:36px;height:26px;border:none;border-left:1px solid var(--bd);border-right:1px solid var(--bd);text-align:center;font-size:13px;color:var(--t1);outline:none;background:var(--card)}
.sum-foot{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid var(--bd);font-size:14px;font-weight:600;color:var(--t1)}
.sum-foot b{color:var(--p);font-family:Consolas,Monaco,monospace;font-size:18px}
.sum-note{padding:0 16px 14px;font-size:12px;color:var(--t3)}
.ord-row{display:flex;justify-content:space-between;gap:16px;padding:13px 0;border-bottom:1px solid var(--bd);font-size:13px}
.ord-row>span{color:var(--t3);flex:none}
.ord-row>b{color:var(--t1);font-weight:500;text-align:right;word-break:break-all}
.ord-row .disc{color:var(--ok)}
.ord-amount{border-bottom:none;padding:16px 0 4px}
.ord-amount>span{color:var(--t1);font-weight:600;font-size:14px}
.ord-amount>b{color:var(--p);font-family:Consolas,Monaco,monospace;font-size:20px;font-weight:700}
.ch-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.ch-item{position:relative;border:1px solid var(--bd);border-radius:8px;padding:18px 12px;display:flex;flex-direction:column;align-items:center;gap:6px;cursor:pointer;transition:border-color .15s,background .15s}
.ch-item:hover{border-color:var(--p)}
.ch-item.on{border-color:var(--p);background:var(--p-bg)}
.ch-item input{position:absolute;opacity:0;pointer-events:none}
.ch-item img{width:26px;height:26px}
.ch-name{font-size:14px;color:var(--t1)}
.ch-bal{font-size:12px;color:var(--ok)}
.ch-link{font-size:12px;color:var(--p)}
.ch-off{opacity:.55;cursor:not-allowed}
.pay-btn{width:100%;height:44px;font-size:15px;margin-top:18px}
.pay-btn svg{width:17px;height:17px}
.pay-note{text-align:center;font-size:12px;color:var(--t3);margin-top:12px}
.done-card{max-width:640px;margin:0 auto}
.done-head{display:flex;flex-direction:column;align-items:center;text-align:center;margin-bottom:20px}
.done-ico{width:56px;height:56px;border-radius:14px;background:var(--ok);display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.done-ico svg{width:26px;height:26px}
.done-title{font-size:18px;font-weight:600;color:var(--t1)}
.done-sub{font-size:13px;color:var(--t3);margin-top:4px}
.done-lic{background:var(--bg);border-radius:8px;padding:14px 16px;margin-bottom:16px}
.done-lic-label{font-size:12px;color:var(--t3);margin-bottom:6px}
.done-lic-no{font-family:Consolas,Monaco,monospace;font-size:16px;font-weight:600;color:var(--p);word-break:break-all}
.done-lic-more{font-size:12px;color:var(--t3);margin-top:8px;line-height:20px;word-break:break-all}
.done-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
.done-cell{border:1px solid var(--bd);border-radius:8px;padding:12px 16px}
.done-cell span{display:block;font-size:12px;color:var(--t3);margin-bottom:4px}
.done-cell b{font-size:14px;color:var(--t1);font-weight:600;word-break:break-all}
.done-pay{display:flex;justify-content:space-between;align-items:center;background:var(--bg);border-radius:8px;padding:14px 16px;font-size:13px;color:var(--t3)}
.done-pay b{color:var(--p);font-family:Consolas,Monaco,monospace;font-size:16px}
@media (max-width:768px){
.steps{margin:0 0 16px;flex-wrap:wrap;gap:8px}
.step-line{display:none}
.cfg-wrap{grid-template-columns:1fr}
.app-grid{grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}
}
</style>

<?php $flash = flash_take(); if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<?php if ($done !== '' && $paidOrder): ?>
<div class="steps">
    <?php foreach ([1 => '选择应用', 2 => '授权配置', 3 => '支付购买', 4 => '生成授权'] as $i => $name): ?>
    <?php if ($i > 1): ?><div class="step-line on"></div><?php endif; ?>
    <div class="step <?php echo $i < 4 ? 'done' : 'cur'; ?>">
        <span class="step-num"><?php echo $i; ?></span>
        <span class="step-name"><?php echo $name; ?></span>
    </div>
    <?php endforeach; ?>
</div>

<section class="card done-card">
    <div class="done-head">
        <span class="done-ico"><?php echo $icoCheck; ?></span>
        <div class="done-title">授权已生成</div>
        <div class="done-sub">购买完成，卡密已归入您的名下，可下发给下级用户</div>
    </div>
    <div class="done-lic">
        <div class="done-lic-label">授权编号</div>
        <div class="done-lic-no"><?php echo e($paidCards[0] ?? (string)$paidOrder['order_no']); ?></div>
        <?php if (count($paidCards) > 1): ?>
        <div class="done-lic-more">其余 <?php echo count($paidCards) - 1; ?> 张：<?php echo e(implode('、', array_slice($paidCards, 1))); ?></div>
        <?php endif; ?>
    </div>
    <div class="done-grid">
        <div class="done-cell"><span>应用</span><b><?php echo e($doneApp !== '' ? $doneApp : (string)$paidOrder['subject']); ?></b></div>
        <div class="done-cell"><span>套餐</span><b><?php echo e($donePkg !== '' ? $donePkg : '—'); ?></b></div>
        <div class="done-cell"><span>授权时长</span><b><?php echo e($pkgDur($paidOrder)); ?></b></div>
        <div class="done-cell"><span>剩余余额</span><b>¥<?php echo e(number_format($balanceNow ?? (float)$agent['balance'], 2)); ?></b></div>
    </div>
    <div class="done-pay"><span>本次扣款</span><b>¥<?php echo e(number_format((float)$paidOrder['amount'], 2)); ?></b></div>
    <div class="step-btns">
        <a class="btn-plain" href="license_manage.php">查看授权</a>
        <a class="btn-p" href="buy.php">继续购买</a>
    </div>
</section>
<?php else: ?>

<div class="steps">
    <?php foreach ([1 => '选择应用', 2 => '授权配置', 3 => '支付购买', 4 => '生成授权'] as $i => $name): ?>
    <?php if ($i > 1): ?><div class="step-line<?php echo $i <= $step ? ' on' : ''; ?>"></div><?php endif; ?>
    <div class="step <?php echo $i < $step ? 'done' : ($i === $step ? 'cur' : ''); ?>">
        <span class="step-num"><?php echo $i; ?></span>
        <span class="step-name"><?php echo $name; ?></span>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($step === 1): ?>
<section class="card">
    <?php if (!$apps): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无可开通的应用，请稍后再来或联系管理员。</span>
    </div>
    <?php else: ?>
    <div class="app-grid" id="appGrid">
        <?php foreach ($apps as $a): ?>
        <div class="app-card" data-id="<?php echo (int)$a['id']; ?>">
            <span class="sel-badge"><?php echo $icoCheck; ?></span>
            <div class="app-icon"><img src="../assets/svg/menu-app.svg" alt=""></div>
            <div class="app-name"><?php echo e((string)$a['name']); ?></div>
            <div class="app-sub"><?php echo e(trim((string)$a['remark']) !== '' ? (string)$a['remark'] : (string)$a['name']); ?></div>
            <div class="app-foot">
                <span class="app-price">¥<?php echo e(rtrim(rtrim(number_format((float)$a['min_price'] * $agentRate, 2, '.', ''), '0'), '.')); ?><small>起</small><span class="price-sale">¥<?php echo e(rtrim(rtrim(number_format((float)$a['min_price'], 2, '.', ''), '0'), '.')); ?></span></span>
                <span class="app-count"><?php echo (int)$a['pkg_count']; ?> 个套餐</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="step-btns"><button class="btn-p" id="next1" disabled>下一步</button></div>
    <?php endif; ?>
</section>

<script>
(function () {
    var grid = document.getElementById('appGrid');
    if (!grid) return;
    var next = document.getElementById('next1');
    var sel = 0;
    grid.addEventListener('click', function (ev) {
        var c = ev.target.closest('.app-card');
        if (!c) return;
        grid.querySelectorAll('.app-card').forEach(function (x) { x.classList.remove('on'); });
        c.classList.add('on');
        sel = c.getAttribute('data-id');
        next.disabled = false;
    });
    next.addEventListener('click', function () {
        if (sel) location.href = 'buy.php?step=2&app_id=' + sel;
    });
})();
</script>

<?php elseif ($step === 2 && $app): ?>
<section class="card">
    <div class="cfg-wrap">
        <div class="cfg-main">
            <div class="sec-title">授权类型</div>
            <div class="auth-types" id="authTypes">
                <?php foreach ($methods as $m): ?>
                <button type="button" class="auth-type<?php echo $m === $authType ? ' on' : ''; ?>" data-v="<?php echo $m; ?>">
                    <?php echo $authIcons[$m] ?? ''; ?><?php echo e($authTypes[$m]['label']); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="sec-title" id="targetLabel"><?php echo e($authTypes[$authType]['label'] ?? ''); ?></div>
            <div class="target-box">
                <span class="tb-ico">
                    <?php foreach ($authIcons as $k => $ic): ?>
                    <i data-v="<?php echo $k; ?>" class="<?php echo $k === $authType ? 'show' : ''; ?>"><?php echo $ic; ?></i>
                    <?php endforeach; ?>
                </span>
                <input type="text" class="tb-input" id="authTarget" value="<?php echo e($authTarget); ?>" placeholder="<?php echo e($authTypes[$authType]['ph'] ?? ''); ?>">
            </div>
            <div class="form-hint" id="targetHint"><?php echo e($authTypes[$authType]['hint'] ?? ''); ?></div>
            <div class="sec-title">选择套餐</div>
            <?php if (!$pkgs): ?>
            <div class="empty-state">
                <img src="../assets/svg/icon-empty.svg" alt="">
                <span>该应用暂未配置套餐，请联系管理员。</span>
            </div>
            <?php else: ?>
            <div class="pkg-grid" id="pkgGrid">
                <?php foreach ($pkgs as $p): $pPrice = (float)$p['price'] > 0 ? (float)$p['price'] : (float)$app['price']; $aPrice = round($pPrice * $agentRate, 2); ?>
                <div class="pkg-card" data-id="<?php echo (int)$p['id']; ?>" data-auth="<?php echo (int)$p['auth_method']; ?>"
                     data-name="<?php echo e((string)$p['name']); ?>" data-dur="<?php echo e($pkgDur($p)); ?>"
                     data-sites="<?php echo (int)($p['max_sites'] ?? 0); ?>" data-price="<?php echo e(number_format($aPrice, 2, '.', '')); ?>" data-sale="<?php echo e(number_format($pPrice, 2, '.', '')); ?>">
                    <span class="sel-badge"><?php echo $icoCheck; ?></span>
                    <div class="pkg-name"><?php echo e((string)$p['name']); ?></div>
                    <div class="pkg-price">¥<?php echo e(number_format($aPrice, 2)); ?><span class="price-sale">¥<?php echo e(number_format($pPrice, 2)); ?></span></div>
                    <div class="pkg-dur"><?php echo e($pkgDur($p)); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="pkg-empty" id="pkgEmpty" style="display:none">该授权方式下暂无可购套餐，请切换授权类型。</div>
            <?php endif; ?>
        </div>
        <aside class="sum-card">
            <div class="sum-head"><img src="../assets/svg/menu-app.svg" alt=""><b><?php echo e((string)$app['name']); ?></b></div>
            <div class="sum-body">
                <div class="sum-row"><span>套餐</span><b id="sumPkg">—</b></div>
                <div class="sum-row"><span>时长</span><b id="sumDur">—</b></div>
                <div class="sum-row"><span>站点</span><b id="sumSites">—</b></div>
                <div class="sum-row"><span>类型</span><b id="sumType">—</b></div>
                <div class="sum-row"><span>目标</span><b id="sumTarget">—</b></div>
                <div class="sum-row"><span>数量</span>
                    <span class="qty-ctl">
                        <button type="button" id="qtyMinus" aria-label="减少">−</button>
                        <input type="text" inputmode="numeric" id="qtyInput" value="<?php echo $quantity; ?>">
                        <button type="button" id="qtyPlus" aria-label="增加">＋</button>
                    </span>
                </div>
                <div class="sum-row"><span>售卖价</span><b><span class="price-sale" id="sumSale">—</span></b></div>
            </div>
            <div class="sum-foot"><span>代理价</span><b id="sumTotal">¥0.00</b></div>
            <div class="sum-note">已按当前代理折扣折算；结算时与活动优惠自动取优</div>
        </aside>
    </div>
    <div class="step-btns">
        <a class="btn-plain" href="buy.php">上一步</a>
        <button class="btn-p" id="next2" disabled>下一步</button>
    </div>
</section>

<script>
(function () {
    var meta = <?php echo json_encode($jsMeta, JSON_UNESCAPED_UNICODE); ?>;
    var typeBox = document.getElementById('authTypes');
    if (!typeBox) return;
    var tLabel = document.getElementById('targetLabel');
    var tInput = document.getElementById('authTarget');
    var tHint = document.getElementById('targetHint');
    var icos = document.querySelectorAll('.tb-ico i');
    var pkgCards = [].slice.call(document.querySelectorAll('.pkg-card'));
    var pkgEmpty = document.getElementById('pkgEmpty');
    var next = document.getElementById('next2');
    var qtyIn = document.getElementById('qtyInput');
    var appId = <?php echo (int)$app['id']; ?>;
    var curType = <?php echo (int)$authType; ?>;
    var curPkg = null;
    function money(n) { return '¥' + (Math.round(n * 100) / 100).toFixed(2); }
    function setIco(t) {
        icos.forEach(function (i) { i.classList.toggle('show', +i.getAttribute('data-v') === t); });
    }
    function visible(p) { var a = +p.getAttribute('data-auth'); return a === 0 || a === curType; }
    function filterPkgs() {
        var any = false;
        pkgCards.forEach(function (p) {
            var v = visible(p);
            p.style.display = v ? '' : 'none';
            if (v) any = true;
            if (!v && curPkg === p) { curPkg = null; p.classList.remove('on'); }
        });
        if (pkgEmpty) pkgEmpty.style.display = any ? 'none' : '';
    }
    function targetText() {
        var v = tInput.value.trim();
        if (curType === 4) return v === '' ? '系统自动生成' : v;
        return v === '' ? '—' : v;
    }
    function refresh() {
        document.getElementById('sumType').textContent = meta[curType] ? meta[curType].label : '—';
        document.getElementById('sumTarget').textContent = targetText();
        var saleEl = document.getElementById('sumSale');
        if (curPkg) {
            var qty = Math.max(1, Math.min(100, parseInt(qtyIn.value, 10) || 1));
            document.getElementById('sumPkg').textContent = curPkg.getAttribute('data-name');
            document.getElementById('sumDur').textContent = curPkg.getAttribute('data-dur');
            var s = +curPkg.getAttribute('data-sites');
            document.getElementById('sumSites').textContent = s > 0 ? s + ' 站点' : '不限站点';
            document.getElementById('sumTotal').textContent = money(+curPkg.getAttribute('data-price') * qty);
            if (saleEl) saleEl.textContent = money(+curPkg.getAttribute('data-sale') * qty);
            next.disabled = false;
        } else {
            document.getElementById('sumPkg').textContent = '—';
            document.getElementById('sumDur').textContent = '—';
            document.getElementById('sumSites').textContent = '—';
            document.getElementById('sumTotal').textContent = '¥0.00';
            if (saleEl) saleEl.textContent = '—';
            next.disabled = true;
        }
    }
    function qtyRule() {
        if (curType === 4) {
            var many = (parseInt(qtyIn.value, 10) || 1) > 1;
            tInput.disabled = many;
            tInput.placeholder = many ? '数量大于 1 时由系统自动生成' : meta[4].ph;
        } else {
            tInput.disabled = false;
            tInput.placeholder = meta[curType] ? meta[curType].ph : '';
        }
    }
    typeBox.addEventListener('click', function (ev) {
        var b = ev.target.closest('.auth-type');
        if (!b) return;
        typeBox.querySelectorAll('.auth-type').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        curType = +b.getAttribute('data-v');
        tLabel.textContent = meta[curType].label;
        tHint.textContent = meta[curType].hint;
        setIco(curType);
        filterPkgs();
        qtyRule();
        refresh();
    });
    var pkgGrid = document.getElementById('pkgGrid');
    if (pkgGrid) {
        pkgGrid.addEventListener('click', function (ev) {
            var c = ev.target.closest('.pkg-card');
            if (!c || c.style.display === 'none') return;
            pkgCards.forEach(function (x) { x.classList.remove('on'); });
            c.classList.add('on');
            curPkg = c;
            refresh();
        });
    }
    tInput.addEventListener('input', refresh);
    document.getElementById('qtyMinus').addEventListener('click', function () {
        qtyIn.value = Math.max(1, (parseInt(qtyIn.value, 10) || 1) - 1);
        qtyRule(); refresh();
    });
    document.getElementById('qtyPlus').addEventListener('click', function () {
        qtyIn.value = Math.min(100, (parseInt(qtyIn.value, 10) || 1) + 1);
        qtyRule(); refresh();
    });
    qtyIn.addEventListener('input', function () { qtyRule(); refresh(); });
    next.addEventListener('click', function () {
        if (!curPkg) return;
        location.href = 'buy.php?step=3&app_id=' + appId + '&auth_type=' + curType
            + '&auth_target=' + encodeURIComponent(tInput.value.trim())
            + '&package_id=' + curPkg.getAttribute('data-id')
            + '&quantity=' + (parseInt(qtyIn.value, 10) || 1);
    });
    setIco(curType);
    filterPkgs();
    qtyRule();
    refresh();
    <?php if ($prePkgId > 0 && $pkgs): ?>
    var pre = document.querySelector('.pkg-card[data-id="<?php echo $prePkgId; ?>"]');
    if (pre && visible(pre)) {
        pre.classList.add('on');
        curPkg = pre;
        refresh();
    }
    <?php endif; ?>
})();
</script>

<?php elseif ($step === 3 && $app && $pkg && $preview): ?>
<section class="card">
    <div class="sec-title with-ico"><?php echo $icoDoc; ?>订单详情</div>
    <div class="ord-row"><span>应用</span><b><?php echo e((string)$app['name']); ?></b></div>
    <div class="ord-row"><span>套餐</span><b><?php echo e((string)$pkg['name']); ?></b></div>
    <div class="ord-row"><span>授权时长</span><b><?php echo e($pkgDur($pkg)); ?></b></div>
    <div class="ord-row"><span>授权类型</span><b><?php echo e($authTypes[$authType]['label'] ?? '—'); ?></b></div>
    <div class="ord-row"><span>授权目标</span><b><?php echo $authType === 4 && $authTarget === '' ? '系统自动生成' : e($authTarget); ?></b></div>
    <div class="ord-row"><span>数量</span><b><?php echo $preview['quantity']; ?> 张</b></div>
    <div class="ord-row"><span>售卖价</span><b>¥<?php echo e(number_format($preview['total'], 2)); ?></b></div>
    <?php if ($preview['disc']['discount_amount'] > 0): ?>
    <div class="ord-row"><span>优惠（<?php echo e($preview['disc']['discount_name']); ?>）</span><b class="disc">-¥<?php echo e(number_format($preview['disc']['discount_amount'], 2)); ?></b></div>
    <?php endif; ?>
    <div class="ord-row ord-amount"><span>应付金额</span><b>¥<?php echo e(number_format($preview['pay'], 2)); ?></b></div>
</section>

<section class="card">
    <div class="sec-title with-ico"><?php echo $icoCard; ?>支付方式</div>
    <form method="post" action="buy.php?step=3" id="payForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="app_id" value="<?php echo (int)$app['id']; ?>">
        <input type="hidden" name="package_id" value="<?php echo (int)$pkg['id']; ?>">
        <input type="hidden" name="quantity" value="<?php echo $preview['quantity']; ?>">
        <input type="hidden" name="auth_type" value="<?php echo (int)$authType; ?>">
        <input type="hidden" name="auth_target" value="<?php echo e($authTarget); ?>">
        <?php $firstCh = $channels ? array_key_first($channels) : ''; $balOk = (float)$agent['balance'] >= $preview['pay']; ?>
        <div class="ch-grid" id="chGrid">
            <?php foreach ($channels as $ch => $info): ?>
            <label class="ch-item<?php echo $ch === $firstCh ? ' on' : ''; ?>">
                <input type="radio" name="channel" value="<?php echo e($ch); ?>" <?php echo $ch === $firstCh ? 'checked' : ''; ?>>
                <img src="../assets/img/<?php echo ['alipay' => 'zfb', 'wechat' => 'wechat', 'epay' => 'epay'][$ch] ?? 'epay'; ?>.svg" alt="">
                <span class="ch-name"><?php echo e($info['name']); ?></span>
            </label>
            <?php endforeach; ?>
            <?php if ($balanceEnabled): ?>
            <label class="ch-item<?php echo $firstCh === '' ? ' on' : ''; ?><?php echo $balOk ? '' : ' ch-off'; ?>">
                <input type="radio" name="channel" value="balance" <?php echo $firstCh === '' ? 'checked' : ''; ?> <?php echo $balOk ? '' : 'disabled'; ?>>
                <img src="../assets/svg/menu-money.svg" alt="">
                <span class="ch-name">余额支付</span>
                <span class="ch-bal">¥<?php echo e(number_format((float)$agent['balance'], 2)); ?></span>
                <a class="ch-link" href="recharge.php">充值余额</a>
            </label>
            <?php endif; ?>
        </div>
        <button class="btn-p pay-btn" type="submit" data-confirm="确认提交订单并支付 ¥<?php echo e(number_format($preview['pay'], 2)); ?> ？"><?php echo $icoShield; ?>确认支付 ¥<?php echo e(number_format($preview['pay'], 2)); ?></button>
        <div class="pay-note">套餐价格以后端为准，余额扣款后卡密即时生成</div>
    </form>
</section>
<script>
(function () {
    var box = document.getElementById('chGrid');
    if (!box) return;
    box.addEventListener('change', function () {
        box.querySelectorAll('.ch-item').forEach(function (l) {
            l.classList.toggle('on', l.querySelector('input').checked);
        });
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php agent_footer(); ?>
