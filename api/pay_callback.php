<?php
// 统一支付回调入口（按渠道分发验签）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Pay.php';

$channel = (string)($_GET['channel'] ?? '');
if (!in_array($channel, ['epay', 'wechat', 'alipay'], true)) {
    http_response_code(400);
    exit('unknown channel');
}

require dirname(__DIR__) . "/plugin/{$channel}/pay.php";

switch ($channel) {
    case 'epay':
        $req = array_merge($_GET, $_POST);
        $notice = plugin_epay_verify($req);
        $ackOk = $ackFail = plugin_epay_ack();
        break;

    case 'wechat':
        $raw = (string)file_get_contents('php://input');
        $notice = plugin_wechat_verify($raw);
        $ackOk = plugin_wechat_ack();
        $ackFail = '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[VERIFY FAILED]]></return_msg></xml>';
        break;

    case 'alipay':
        $notice = plugin_alipay_verify($_POST);
        $ackOk = $ackFail = plugin_alipay_ack();
        break;
}

header('Content-Type: text/plain; charset=utf-8');

if (!$notice) {
    audit('pay', 'callback_invalid', 4, 0, '渠道：' . $channel . ' IP：' . client_ip());
    echo $ackFail;
    exit;
}

$stmt = db()->prepare('SELECT * FROM `order` WHERE `order_no`=? LIMIT 1');
$stmt->execute([$notice['order_no']]);
$order = $stmt->fetch();
if (!$order) {
    audit('pay', 'callback_noorder', 4, 0, '渠道：' . $channel . ' 订单号：' . $notice['order_no']);
    echo $ackFail;
    exit;
}

if (abs(((float)$order['amount']) - (float)$notice['money']) > 0.011) {
    audit('pay', 'callback_amount_mismatch', 4, 0,
        '订单：' . $notice['order_no'] . ' 应付：' . $order['amount'] . ' 实收：' . $notice['money']);
    echo $ackFail;
    exit;
}

$result = pay_mark_paid($notice['order_no'], $channel, $notice['trade_no']);
if (!$result['ok']) {
    audit('pay', 'callback_fail', 4, (int)$order['user_id'],
        '订单：' . $notice['order_no'] . ' 原因：' . $result['msg']);
    echo $ackFail;
    exit;
}

echo $ackOk;
