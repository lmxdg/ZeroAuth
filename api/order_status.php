<?php
// 订单状态查询（支付页轮询，仅限本人订单）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$agent = current_agent();
if (!$user && !$agent) {
    json_out(401, '请先登录用户中心或代理商中心');
}

$orderNo = trim((string)($_GET['order_no'] ?? ''));
if ($orderNo === '' || !preg_match('/^(?:PAY|RC)[0-9]{20}$/', $orderNo)) {
    json_out(400, '订单号格式错误');
}

$order = null;
if ($user) {
    $stmt = db()->prepare('SELECT * FROM `order` WHERE `order_no`=? AND `user_id`=? LIMIT 1');
    $stmt->execute([$orderNo, (int)$user['id']]);
    $order = $stmt->fetch();
}
if (!$order && $agent) {
    $stmt = db()->prepare('SELECT * FROM `order` WHERE `order_no`=? AND `agent_id`=? LIMIT 1');
    $stmt->execute([$orderNo, (int)$agent['id']]);
    $order = $stmt->fetch();
}
if (!$order) {
    json_out(404, '订单不存在');
}

$statusMap = [0 => '未支付', 1 => '已支付', 2 => '已关闭', 3 => '已退款'];
$cards = [];
if ((int)$order['pay_status'] === 1) {
    $stmt = db()->prepare('SELECT `card_no` FROM `license` WHERE `remark`=? ORDER BY `id` ASC');
    $stmt->execute(['订单购买 ' . $orderNo]);
    $cards = array_column($stmt->fetchAll(), 'card_no');
}

json_out(200, 'success', [
    'order_no'        => (string)$order['order_no'],
    'pay_status'      => (int)$order['pay_status'],
    'pay_status_text' => $statusMap[(int)$order['pay_status']] ?? '未知',
    'subject'         => (string)$order['subject'],
    'amount'          => (string)$order['amount'],
    'discount_amount' => (string)$order['discount_amount'],
    'pay_channel'     => (string)$order['pay_channel'],
    'paid_time'       => (int)$order['paid_time'],
    'created_at'      => (int)$order['created_at'],
    'cards'           => $cards,
]);
