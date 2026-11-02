<?php
// 管理员后台 - 订单管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$error = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'close') {
        $orderId = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM `order` WHERE `id`=?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if ($order && (int)$order['pay_status'] === 0) {
            db()->prepare('UPDATE `order` SET `pay_status`=2 WHERE `id`=?')->execute([$orderId]);
            audit('order', 'close', 1, (int)$admin['id'], '订单：' . $order['order_no']);
            flash_set('ok', '订单 ' . $order['order_no'] . ' 已关闭。');
        } else {
            flash_set('error', '仅未支付订单可关闭。');
        }
    }

    if ($action === 'callback') {
        $orderId = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM `order` WHERE `id`=?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            flash_set('error', '订单不存在。');
        } elseif ((int)$order['pay_status'] === 1) {
            flash_set('error', '该订单已是已支付状态，无需回调。');
        } elseif ((int)$order['pay_status'] !== 0) {
            flash_set('error', '已关闭或已退款订单不能手动回调，请先重新下单。');
        } else {
            require_once ROOT_PATH . '/core/Pay.php';
            $channel = (string)$order['pay_channel'] !== '' ? (string)$order['pay_channel'] : 'manual';
            $result = pay_mark_paid((string)$order['order_no'], $channel, 'MANUAL' . date('YmdHis'));
            audit('order', 'manual_callback', 1, (int)$admin['id'],
                '手动回调订单：' . $order['order_no'] . ' 结果：' . ($result['ok'] ? '成功' : '失败') . ' ' . $result['msg']);
            if ($result['ok']) {
                $cardMsg = $result['cards'] ? '，发放卡密 ' . count($result['cards']) . ' 张' : '';
                flash_set('ok', '订单 ' . $order['order_no'] . ' 已手动完成支付处理' . $cardMsg . '。');
            } else {
                flash_set('error', '手动回调失败：' . $result['msg']);
            }
        }
    }

    if ($action === 'delete') {
        $orderId = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM `order` WHERE `id`=?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if ($order) {
            db()->prepare('DELETE FROM `order` WHERE `id`=?')->execute([$orderId]);
            audit('order', 'delete', 1, (int)$admin['id'],
                '删除订单：' . $order['order_no'] . '（状态：' . (int)$order['pay_status'] . '，金额：' . $order['amount'] . '）');
            flash_set('ok', '订单 ' . $order['order_no'] . ' 已删除。');
        } else {
            flash_set('error', '订单不存在或已删除。');
        }
    }
    redirect('order_manage.php');
}

$statusMap = [0 => '未支付', 1 => '已支付', 2 => '已关闭', 3 => '已退款'];
$channelMap = ['alipay' => '支付宝', 'wechat' => '微信支付', 'epay' => '易支付', 'balance' => '余额支付', 'manual' => '手动回调'];
$typeNames = [1 => '限时', 2 => '计次', 3 => '永久'];

$fStatus  = (string)($_GET['status'] ?? '');
$fChannel = (string)($_GET['channel'] ?? '');
$kw       = trim((string)($_GET['kw'] ?? ''));

$where  = [];
$exec   = [];
if ($fStatus !== '' && in_array($fStatus, ['0', '1', '2', '3'], true)) {
    $where[] = 'o.`pay_status`=?';
    $exec[] = (int)$fStatus;
}
if ($fChannel !== '' && isset($channelMap[$fChannel])) {
    $where[] = 'o.`pay_channel`=?';
    $exec[] = $fChannel;
}
if ($kw !== '') {
    $where[] = '(o.`order_no` LIKE ? OR u.`username` LIKE ?)';
    $exec[] = '%' . $kw . '%';
    $exec[] = '%' . $kw . '%';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$baseQuery = 'order_manage.php?' . http_build_query(array_filter(['status' => $fStatus, 'channel' => $fChannel, 'kw' => $kw])) . '&';

$stmt = db()->prepare('SELECT COUNT(*) FROM `order` o LEFT JOIN `user` u ON u.`id`=o.`user_id`' . $whereSql);
$stmt->execute($exec);
$total = (int)$stmt->fetchColumn();

$orders = [];
if ($total > 0) {
    $stmt = db()->prepare(
        'SELECT o.*, u.`username`, a.`name` AS `app_name` FROM `order` o '
        . 'LEFT JOIN `user` u ON u.`id`=o.`user_id` '
        . 'LEFT JOIN `app` a ON a.`id`=o.`app_id`' . $whereSql . ' '
        . 'ORDER BY o.`id` DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
    );
    $stmt->execute($exec);
    $orders = $stmt->fetchAll();
}

admin_header('订单管理', 'order', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<section class="card">
    <h2 class="card-title">订单列表</h2>
    <p class="card-desc">用户中心购买卡密产生的订单。未支付订单支持「手动回调」（确认收款后自动发卡/入账）与关闭；所有订单可删除。</p>
    <form method="get" class="filter-bar">
        <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="订单号 / 用户名" style="max-width:220px">
        <select class="form-input" name="status" style="max-width:140px">
            <option value="">全部状态</option>
            <?php foreach ($statusMap as $k => $v): ?>
            <option value="<?php echo $k; ?>" <?php echo $fStatus === (string)$k ? 'selected' : ''; ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="channel" style="max-width:140px">
            <option value="">全部渠道</option>
            <?php foreach ($channelMap as $k => $v): ?>
            <option value="<?php echo $k; ?>" <?php echo $fChannel === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn-primary" type="submit">筛选</button>
        <a class="btn-plain" href="order_manage.php">重置</a>
    </form>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>订单号</th><th>用户</th><th>应用 / 商品</th><th>规格</th><th>金额</th><th>渠道</th><th>状态</th><th>创建时间</th><th style="width:190px">操作</th></tr></thead>
            <tbody>
            <?php if (!$orders): ?>
            <tr><td colspan="9"><div class="empty-state">暂无订单。</div></td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td class="cell-code"><?php echo e($o['order_no']); ?></td>
                <td><?php echo e($o['username'] ?? '（已注销）'); ?></td>
                <td class="cell-main"><?php echo e($o['app_name'] ?? '-'); ?> <?php echo e($o['subject']); ?></td>
                <td class="cell-sub">
                    <?php echo $typeNames[(int)$o['license_type']] ?? '未知'; ?> x<?php echo (int)$o['quantity']; ?>
                    <?php if ((int)$o['license_type'] === 1 && (int)$o['duration_days'] > 0): ?>
                    / <?php echo (int)$o['duration_days']; ?> 天
                    <?php elseif ((int)$o['license_type'] === 2 && (int)$o['total_count'] > 0): ?>
                    / <?php echo (int)$o['total_count']; ?> 次
                    <?php endif; ?>
                </td>
                <td>
                    ¥<?php echo e($o['amount']); ?>
                    <?php if ((float)$o['discount_amount'] > 0): ?>
                    <span class="cell-sub">（省 ¥<?php echo e($o['discount_amount']); ?>）</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $channelMap[$o['pay_channel']] ?? ($o['pay_channel'] !== '' ? e($o['pay_channel']) : '-'); ?></td>
                <td>
                    <span class="tag <?php echo [1 => 'tag-ok', 0 => 'tag-warn', 2 => 'tag-off', 3 => 'tag-off'][(int)$o['pay_status']] ?? 'tag-off'; ?>">
                        <?php echo $statusMap[(int)$o['pay_status']] ?? '未知'; ?>
                    </span>
                </td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$o['created_at']); ?></td>
                <td style="white-space:normal">
                    <?php if ((int)$o['pay_status'] === 0): ?>
                    <form method="post" style="display:inline-block" data-confirm="确认将订单「<?php echo e($o['order_no']); ?>」手动标记为已支付并执行发卡/入账吗？请确认已实际收到该笔款项。">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="callback">
                        <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                        <button class="btn-mini" type="submit">手动回调</button>
                    </form>
                    <form method="post" style="display:inline-block" data-confirm="确定关闭订单「<?php echo e($o['order_no']); ?>」吗？关闭后用户不可再支付该订单。">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="close">
                        <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                        <button class="btn-mini-plain" type="submit">关闭</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline-block" data-confirm="确定删除订单「<?php echo e($o['order_no']); ?>」吗？删除后不可恢复。">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                        <button class="btn-danger-mini" type="submit">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, $baseQuery); ?>
</section>
<?php admin_footer(); ?>
