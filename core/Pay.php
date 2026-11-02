<?php
// 支付核心：下单/回调入账/自动发卡（事务+行锁）
declare(strict_types=1);

require_once __DIR__ . '/QREngine.php';

function pay_gen_order_no(string $prefix = 'PAY'): string
{
    return $prefix . date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function pay_balance_enabled(): bool
{
    return plugin_enabled('balance');
}

function pay_gen_card_no(string $prefix = 'VIP'): string
{
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'VIP');
    if (strlen($prefix) > 12) {
        $prefix = substr($prefix, 0, 12);
    }
    $hex = strtoupper(bin2hex(random_bytes(8)));
    return $prefix . '-' . implode('-', str_split($hex, 4));
}

function pay_calc_discount(float $amount, int $quantity, int $userId, int $appId = 0): array
{
    $none = ['discount_id' => 0, 'discount_amount' => 0.0, 'discount_name' => ''];
    $now = time();
    try {
        $stmt = db()->prepare('SELECT * FROM `discount_activity` WHERE `status`=1 AND `start_time`<=? AND (`end_time`=0 OR `end_time`>=?)');
        $stmt->execute([$now, $now]);
        $acts = $stmt->fetchAll();
    } catch (Throwable $ex) {
        return $none;
    }
    if (!$acts) {
        return $none;
    }

    $firstBuy = null;
    $isFirstBuy = null;

    $best = $none;
    foreach ($acts as $a) {
        $scopeIds = array_map('intval', array_filter(explode(',', (string)($a['app_ids'] ?? ''))));
        if ($scopeIds && ($appId <= 0 || !in_array($appId, $scopeIds, true))) {
            continue;
        }
        $ok = false;
        $cut = 0.0;
        switch ((int)$a['type']) {
            case 1:                 $rate = (float)$a['value'];
                if ($rate > 0 && $rate < 1 && $amount > 0) {
                    $ok = true;
                    $cut = round($amount * (1 - $rate), 2);
                }
                break;
            case 2:                 if ($amount >= (float)$a['min_amount'] && (float)$a['value'] > 0) {
                    $ok = true;
                    $cut = min((float)$a['value'], $amount);
                }
                break;
            case 3:                 if ($isFirstBuy === null) {
                    $stmt = db()->prepare('SELECT COUNT(*) FROM `order` WHERE `user_id`=? AND `pay_status`=1');
                    $stmt->execute([$userId]);
                    $isFirstBuy = ((int)$stmt->fetchColumn()) === 0;
                }
                if ($isFirstBuy && (float)$a['value'] > 0 && $amount >= (float)$a['min_amount']) {
                    $ok = true;
                    $cut = min((float)$a['value'], $amount);
                }
                break;
            case 4:                 $rate = (float)$a['value'];
                if ($rate > 0 && $rate < 1 && $quantity >= (int)$a['min_amount'] && $amount > 0) {
                    $ok = true;
                    $cut = round($amount * (1 - $rate), 2);
                }
                break;
        }
        if ($ok && $cut > $best['discount_amount']) {
            $best = [
                'discount_id'     => (int)$a['id'],
                'discount_amount' => $cut,
                'discount_name'   => (string)$a['name'],
            ];
        }
    }
    return $best;
}

function pay_create_order(array $user, array $item): array
{
    $app = $item['app'];
    $pkg = $item['package'] ?? null;
    $quantity = max(1, (int)$item['quantity']);

    if ((int)$app['status'] !== 1) {
        throw new RuntimeException('该应用未上架销售。');
    }
    if (!$pkg || (int)($pkg['app_id'] ?? 0) !== (int)$app['id'] || (int)($pkg['status'] ?? 0) !== 1) {
        throw new RuntimeException('套餐不存在或已下架，请重新选择。');
    }
    if ($quantity > 100) {
        throw new RuntimeException('单笔订单最多购买 100 张。');
    }

    $type = (int)$pkg['license_type'];
    $authMethod = (int)($pkg['auth_method'] ?? 0);
    $maxSites = (int)($pkg['max_sites'] ?? 0);
    $authType = (int)($item['auth_type'] ?? 0);
    $authTarget = trim((string)($item['auth_target'] ?? ''));
    if ($authType <= 0) {
        $authType = $authMethod;
    }
    if (!in_array($authType, [1, 2, 3, 4], true)) {
        throw new RuntimeException('授权方式不合法。');
    }
    if ($authMethod > 0 && $authType !== $authMethod) {
        throw new RuntimeException('该套餐不支持所选授权方式，请重新选择。');
    }
    if (in_array($authType, [1, 2, 3], true) && $authTarget === '') {
        throw new RuntimeException('请填写授权目标。');
    }
    if ($authType === 4 && $authTarget !== '' && $quantity > 1) {
        throw new RuntimeException('自定义密钥仅支持单张购买。');
    }
    $price = (float)$pkg['price'] > 0 ? (float)$pkg['price'] : (float)$app['price'];
    if ($price <= 0) {
        throw new RuntimeException('该套餐未配置售价，暂时无法购买。');
    }
    $duration = $type === 1 ? (int)$pkg['duration_days'] : 0;
    $totalCount = $type === 2 ? (int)$pkg['total_count'] : 0;
    if ($type === 1 && ($duration < 1 || $duration > 36500)) {
        throw new RuntimeException('套餐有效天数配置不合法。');
    }
    if ($type === 2 && ($totalCount < 1 || $totalCount > 100000000)) {
        throw new RuntimeException('套餐总验证次数配置不合法。');
    }

    $total = round($price * $quantity, 2);
    $disc = pay_calc_discount($total, $quantity, (int)$user['id'], (int)$app['id']);
    $payAmount = max(0.01, round($total - $disc['discount_amount'], 2));

    $now = time();
    $orderNo = pay_gen_order_no();
    $stmt = db()->prepare(
        'INSERT INTO `order` (`order_no`,`user_id`,`app_id`,`package_id`,`subject`,`amount`,`discount_id`,`discount_amount`,`discount_name`,'
        . '`license_type`,`target_type`,`target_value`,`max_sites`,`quantity`,`unit_price`,`duration_days`,`total_count`,`created_at`) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $subject = $app['name'] . ' ' . (string)$pkg['name'] . ' x' . $quantity;
    $stmt->execute([
        $orderNo, (int)$user['id'], (int)$app['id'], (int)$pkg['id'], $subject,
        number_format($payAmount, 2, '.', ''),
        $disc['discount_id'], number_format($disc['discount_amount'], 2, '.', ''), (string)$disc['discount_name'],
        $type, $authType, $authTarget, $maxSites, $quantity, number_format($price, 2, '.', ''),
        $duration, $totalCount, $now,
    ]);

    return [
        'id'             => (int)db()->lastInsertId(),
        'order_no'       => $orderNo,
        'subject'        => $subject,
        'amount'         => $payAmount,
        'discount_id'    => $disc['discount_id'],
        'discount_amount'=> $disc['discount_amount'],
        'discount_name'  => $disc['discount_name'],
    ];
}

function pay_create_order_agent(array $agent, array $item): array
{
    $app = $item['app'];
    $pkg = $item['package'] ?? null;
    $quantity = max(1, (int)$item['quantity']);

    if ((int)$app['status'] !== 1) {
        throw new RuntimeException('该应用未上架销售。');
    }
    if (!$pkg || (int)($pkg['app_id'] ?? 0) !== (int)$app['id'] || (int)($pkg['status'] ?? 0) !== 1) {
        throw new RuntimeException('套餐不存在或已下架，请重新选择。');
    }
    if ($quantity > 100) {
        throw new RuntimeException('单笔订单最多购买 100 张。');
    }

    $type = (int)$pkg['license_type'];
    $authMethod = (int)($pkg['auth_method'] ?? 0);
    $maxSites = (int)($pkg['max_sites'] ?? 0);
    $authType = (int)($item['auth_type'] ?? 0);
    $authTarget = trim((string)($item['auth_target'] ?? ''));
    if ($authType <= 0) {
        $authType = $authMethod;
    }
    if (!in_array($authType, [1, 2, 3, 4], true)) {
        throw new RuntimeException('授权方式不合法。');
    }
    if ($authMethod > 0 && $authType !== $authMethod) {
        throw new RuntimeException('该套餐不支持所选授权方式，请重新选择。');
    }
    if (in_array($authType, [1, 2, 3], true) && $authTarget === '') {
        throw new RuntimeException('请填写授权目标。');
    }
    if ($authType === 4 && $authTarget !== '' && $quantity > 1) {
        throw new RuntimeException('自定义密钥仅支持单张购买。');
    }
    $price = (float)$pkg['price'] > 0 ? (float)$pkg['price'] : (float)$app['price'];
    if ($price <= 0) {
        throw new RuntimeException('该套餐未配置售价，暂时无法购买。');
    }
    $duration = $type === 1 ? (int)$pkg['duration_days'] : 0;
    $totalCount = $type === 2 ? (int)$pkg['total_count'] : 0;
    if ($type === 1 && ($duration < 1 || $duration > 36500)) {
        throw new RuntimeException('套餐有效天数配置不合法。');
    }
    if ($type === 2 && ($totalCount < 1 || $totalCount > 100000000)) {
        throw new RuntimeException('套餐总验证次数配置不合法。');
    }

    $total = round($price * $quantity, 2);
    $disc = pay_calc_discount($total, $quantity, 0, (int)$app['id']);
    $agentRate = min(1, max(0.01, agent_effective_discount($agent)));
    $agentCut = round($total * (1 - $agentRate), 2);
    if ($agentCut > $disc['discount_amount']) {
        $disc = ['discount_id' => 0, 'discount_amount' => $agentCut, 'discount_name' => '代理等级折扣'];
    }
    $payAmount = max(0.01, round($total - $disc['discount_amount'], 2));

    $now = time();
    $orderNo = pay_gen_order_no('AGT');
    $stmt = db()->prepare(
        'INSERT INTO `order` (`order_no`,`agent_id`,`app_id`,`package_id`,`subject`,`amount`,`discount_id`,`discount_amount`,`discount_name`,'
        . '`license_type`,`target_type`,`target_value`,`max_sites`,`quantity`,`unit_price`,`duration_days`,`total_count`,`created_at`) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $subject = $app['name'] . ' ' . (string)$pkg['name'] . ' x' . $quantity;
    $stmt->execute([
        $orderNo, (int)$agent['id'], (int)$app['id'], (int)$pkg['id'], $subject,
        number_format($payAmount, 2, '.', ''),
        $disc['discount_id'], number_format($disc['discount_amount'], 2, '.', ''), (string)$disc['discount_name'],
        $type, $authType, $authTarget, $maxSites, $quantity, number_format($price, 2, '.', ''),
        $duration, $totalCount, $now,
    ]);

    return [
        'id'             => (int)db()->lastInsertId(),
        'order_no'       => $orderNo,
        'subject'        => $subject,
        'amount'         => $payAmount,
        'discount_id'    => $disc['discount_id'],
        'discount_amount'=> $disc['discount_amount'],
        'discount_name'  => $disc['discount_name'],
    ];
}

function pay_create_recharge(string $accountType, int $accountId, float $amount): array
{
    if (!in_array($accountType, ['user', 'agent'], true) || $accountId <= 0) {
        throw new RuntimeException('充值账户不合法。');
    }
    if ($amount < 1 || $amount > 50000) {
        throw new RuntimeException('充值金额需在 1 ~ 50000 元之间。');
    }
    $amountStr = number_format($amount, 2, '.', '');

    $table = $accountType === 'user' ? 'user' : 'agent';
    $stmt = db()->prepare("SELECT `id`,`username` FROM `{$table}` WHERE `id`=? AND `status`=1");
    $stmt->execute([$accountId]);
    $account = $stmt->fetch();
    if (!$account) {
        throw new RuntimeException('充值账户不存在或已禁用。');
    }

    $orderNo = pay_gen_order_no('RC');
    $subject = '余额充值（' . $account['username'] . '）';
    $now = time();
    $stmt = db()->prepare(
        'INSERT INTO `order` (`order_no`,`user_id`,`agent_id`,`app_id`,`license_id`,`subject`,`amount`,`license_type`,`quantity`,`unit_price`,`pay_channel`,`pay_status`,`created_at`) '
        . 'VALUES (?,?,?,0,0,?,?,0,0,0,?,0,?)'
    );
    $stmt->execute([
        $orderNo,
        $accountType === 'user' ? $accountId : 0,
        $accountType === 'agent' ? $accountId : 0,
        $subject,
        $amountStr,
        '',
        $now,
    ]);
    audit('pay', 'recharge_create', $accountType === 'user' ? 3 : 2, $accountId,
        '充值订单：' . $orderNo . ' 金额：' . $amountStr);

    return [
        'id'       => (int)db()->lastInsertId(),
        'order_no' => $orderNo,
        'subject'  => $subject,
        'amount'   => $amountStr,
    ];
}

function pay_mark_paid(string $orderNo, string $channel, string $tradeNo): array
{
    $fail = fn (string $msg): array => ['ok' => false, 'duplicate' => false, 'order' => [], 'cards' => [], 'msg' => $msg];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM `order` WHERE `order_no`=? FOR UPDATE');
        $stmt->execute([$orderNo]);
        $order = $stmt->fetch();
        if (!$order) {
            $pdo->rollBack();
            return $fail('订单不存在。');
        }

        if ((int)$order['pay_status'] === 1) {
            $pdo->rollBack();
            $cards = [];
            $st2 = $pdo->prepare('SELECT `card_no` FROM `license` WHERE `remark`=? ORDER BY `id` ASC');
            $st2->execute(['订单购买 ' . $orderNo]);
            $cards = array_column($st2->fetchAll(), 'card_no');
            return ['ok' => true, 'duplicate' => true, 'order' => $order, 'cards' => $cards, 'msg' => '订单已支付，请勿重复处理。'];
        }
        if ((int)$order['pay_status'] !== 0) {
            $pdo->rollBack();
            return $fail('订单已关闭或已退款，无法处理。');
        }

        if ($channel === 'balance') {
            if (!pay_balance_enabled()) {
                $pdo->rollBack();
                return $fail('余额支付未开启。');
            }
            if ((int)$order['license_type'] === 0) {
                $pdo->rollBack();
                return $fail('充值订单不支持余额支付。');
            }
            if ((int)$order['agent_id'] > 0) {
                $u = $pdo->prepare('SELECT `balance` FROM `agent` WHERE `id`=? FOR UPDATE');
                $u->execute([(int)$order['agent_id']]);
                $uRow = $u->fetch();
                if (!$uRow) {
                    $pdo->rollBack();
                    return $fail('支付账户不存在。');
                }
                if ((float)$uRow['balance'] < (float)$order['amount']) {
                    $pdo->rollBack();
                    return $fail('余额不足，请先充值。');
                }
                $pdo->prepare('UPDATE `agent` SET `balance`=`balance`-? WHERE `id`=?')
                    ->execute([(string)$order['amount'], (int)$order['agent_id']]);
            } elseif ((int)$order['user_id'] > 0) {
                $u = $pdo->prepare('SELECT `balance` FROM `user` WHERE `id`=? FOR UPDATE');
                $u->execute([(int)$order['user_id']]);
                $uRow = $u->fetch();
                if (!$uRow) {
                    $pdo->rollBack();
                    return $fail('支付账户不存在。');
                }
                if ((float)$uRow['balance'] < (float)$order['amount']) {
                    $pdo->rollBack();
                    return $fail('余额不足，请先充值。');
                }
                $pdo->prepare('UPDATE `user` SET `balance`=`balance`-? WHERE `id`=?')
                    ->execute([(string)$order['amount'], (int)$order['user_id']]);
            } else {
                $pdo->rollBack();
                return $fail('该订单不支持余额支付。');
            }
        }

        if ((int)$order['license_type'] === 0) {
            $now = time();
            if ((int)$order['agent_id'] > 0) {
                $pdo->prepare('UPDATE `agent` SET `balance`=`balance`+? WHERE `id`=?')
                    ->execute([(string)$order['amount'], (int)$order['agent_id']]);
                $rcType = 2;
                $rcId = (int)$order['agent_id'];
            } elseif ((int)$order['user_id'] > 0) {
                $pdo->prepare('UPDATE `user` SET `balance`=`balance`+? WHERE `id`=?')
                    ->execute([(string)$order['amount'], (int)$order['user_id']]);
                $rcType = 3;
                $rcId = (int)$order['user_id'];
            } else {
                throw new RuntimeException('充值订单缺少入账账户。');
            }
            $upd = $pdo->prepare('UPDATE `order` SET `pay_status`=1,`pay_channel`=?,`trade_no`=?,`paid_time`=? WHERE `id`=?');
            $upd->execute([substr($channel, 0, 20), substr($tradeNo, 0, 64), $now, (int)$order['id']]);
            $pdo->commit();

            audit('pay', 'recharge_paid', $rcType, $rcId,
                '充值到账：' . $orderNo . ' 渠道：' . $channel . ' 金额：' . $order['amount']);

            require_once ROOT_PATH . '/core/schema_upgrade.php';
            if ($rcType === 2) {
                $b = $pdo->prepare('SELECT `balance` FROM `agent` WHERE `id`=?');
                $b->execute([$rcId]);
                finance_flow_add(2, $rcId, 2, (float)$order['amount'], (float)$b->fetchColumn(), $orderNo, '在线充值（' . $channel . '）');
            } else {
                $b = $pdo->prepare('SELECT `balance` FROM `user` WHERE `id`=?');
                $b->execute([$rcId]);
                finance_flow_add(1, $rcId, 2, (float)$order['amount'], (float)$b->fetchColumn(), $orderNo, '在线充值（' . $channel . '）');
            }

            require_once ROOT_PATH . '/core/Mail.php';
            if ($rcType === 3) {
                $uStmt = $pdo->prepare('SELECT `username`,`email`,`balance` FROM `user` WHERE `id`=?');
                $uStmt->execute([$rcId]);
                $uRow = $uStmt->fetch();
                if ($uRow && (string)$uRow['email'] !== '') {
                    mail_scene_send('recharge', (string)$uRow['email'], [
                        'userName' => (string)$uRow['username'],
                        'amount'   => (string)$order['amount'] . ' 元',
                        'balance'  => number_format((float)$uRow['balance'], 2) . ' 元',
                        'orderNo'  => $orderNo,
                        'time'     => date('Y-m-d H:i', $now),
                    ]);
                }
            } elseif ($rcType === 2) {
                $aStmt = $pdo->prepare('SELECT `username`,`email` FROM `agent` WHERE `id`=?');
                $aStmt->execute([$rcId]);
                $aRow = $aStmt->fetch();
                if ($aRow && (string)$aRow['email'] !== '') {
                    mail_scene_send('agent_recharge', (string)$aRow['email'], [
                        'userName' => (string)$aRow['username'],
                        'amount'   => (string)$order['amount'] . ' 元',
                        'orderNo'  => $orderNo,
                        'time'     => date('Y-m-d H:i', $now),
                    ]);
                }
            }

            $order['pay_status'] = 1;
            $order['pay_channel'] = $channel;
            $order['trade_no'] = $tradeNo;
            $order['paid_time'] = $now;
            return ['ok' => true, 'duplicate' => false, 'order' => $order, 'cards' => [], 'msg' => '充值成功'];
        }

        $stmt = $pdo->prepare('SELECT * FROM `app` WHERE `id`=?');
        $stmt->execute([(int)$order['app_id']]);
        $app = $stmt->fetch();
        if (!$app) {
            $pdo->rollBack();
            return $fail('订单关联应用不存在。');
        }

        $now = time();
        $type = (int)$order['license_type'];
        $quantity = max(1, (int)$order['quantity']);
        $duration = (int)$order['duration_days'];
        $totalCount = (int)$order['total_count'];
        $authMethod = (int)$order['target_type'];
        $targetValue = trim((string)($order['target_value'] ?? ''));
        $maxSites = (int)($order['max_sites'] ?? 0);

        $expire = $type === 1 ? $now + $duration * 86400 : 0;
        $total  = $type === 2 ? $totalCount : 0;

        $isAgentOrder = (int)$order['agent_id'] > 0;
        $ownerType = $isAgentOrder ? 2 : 1;
        $ownerId   = $isAgentOrder ? (int)$order['agent_id'] : 0;
        $cardUserId = $isAgentOrder ? 0 : (int)$order['user_id'];

        $insert = $pdo->prepare(
            'INSERT INTO `license` (`card_no`,`app_id`,`type`,`start_time`,`expire_time`,`total_count`,`used_count`,`max_devices`,`target_type`,`price`,`status`,`owner_type`,`owner_id`,`user_id`,`remark`,`created_at`) '
            . 'VALUES (?,?,?,?,?,?,0,?,?,?,1,?,?,?,?,?)'
        );
        $cards = [];
        $licIds = [];
        $firstId = 0;
        $tries = 0;
        $customKey = $authMethod === 4 && $targetValue !== '' ? $targetValue : '';
        while (count($cards) < $quantity && $tries < $quantity * 5 + 10) {
            $tries++;
            $cardNo = $customKey !== '' && count($cards) === 0 ? $customKey : pay_gen_card_no((string)$app['app_id']);
            try {
                $insert->execute([
                    $cardNo, (int)$order['app_id'], $type, $now, $expire, $total,
                    $maxSites > 0 ? $maxSites : (int)$app['max_devices'],
                    $authMethod,
                    number_format((float)$order['unit_price'], 2, '.', ''),
                    $ownerType,
                    $ownerId,
                    $cardUserId,
                    '订单购买 ' . $order['order_no'],
                    $now,
                ]);
            } catch (PDOException $dup) {
                if ($customKey !== '' && count($cards) === 0) {
                    $customKey = '';
                }
                continue;             }
            $lid = (int)$pdo->lastInsertId();
            $licIds[] = $lid;
            if ($firstId === 0) {
                $firstId = $lid;
            }
            $cards[] = $cardNo;
        }
        if (count($cards) < $quantity) {
            throw new RuntimeException('发卡数量不足，已回滚。');
        }

        if (in_array($authMethod, [1, 2, 3], true) && $targetValue !== '') {
            $dom = $pdo->prepare('INSERT IGNORE INTO `license_domain` (`license_id`,`domain`,`target_type`,`is_https`,`status`,`created_at`) VALUES (?,?,?,0,1,?)');
            foreach ($licIds as $lid) {
                $dom->execute([$lid, $targetValue, $authMethod, $now]);
            }
        }

        $upd = $pdo->prepare('UPDATE `order` SET `pay_status`=1,`pay_channel`=?,`trade_no`=?,`license_id`=?,`paid_time`=? WHERE `id`=?');
        $upd->execute([
            substr($channel, 0, 20),
            substr($tradeNo, 0, 64),
            $firstId,
            $now,
            (int)$order['id'],
        ]);

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return $fail('支付处理失败：' . $ex->getMessage());
    }

    $isAgentOrder = (int)$order['agent_id'] > 0;
    audit('pay', 'order_paid', $isAgentOrder ? 2 : 3, $isAgentOrder ? (int)$order['agent_id'] : (int)$order['user_id'],
        '订单：' . $orderNo . ' 渠道：' . $channel . ' 金额：' . $order['amount'] . ' 发卡：' . count($cards) . ' 张');

    require_once ROOT_PATH . '/core/schema_upgrade.php';
    if ($isAgentOrder) {
        $b = $pdo->prepare('SELECT `balance` FROM `agent` WHERE `id`=?');
        $b->execute([(int)$order['agent_id']]);
        finance_flow_add(2, (int)$order['agent_id'], 1, (float)$order['amount'], (float)$b->fetchColumn(), $orderNo,
            '购买 ' . (string)$app['name'] . '（' . $channel . '）');
    } elseif ((int)$order['user_id'] > 0) {
        $b = $pdo->prepare('SELECT `balance` FROM `user` WHERE `id`=?');
        $b->execute([(int)$order['user_id']]);
        finance_flow_add(1, (int)$order['user_id'], 1, (float)$order['amount'], (float)$b->fetchColumn(), $orderNo,
            '购买 ' . (string)$app['name'] . '（' . $channel . '）');

        // 邀请返利：用户由代理商邀请码绑定（user.agent_id）时，按后台配置比例给代理商返余额
        invite_rebate_grant((int)$order['user_id'], (float)$order['amount'], $orderNo, (string)$app['name']);
    }

    if (!empty($cards)) {
        require_once ROOT_PATH . '/core/Mail.php';
        if ($isAgentOrder) {
            $uStmt = $pdo->prepare('SELECT `email`,`username` FROM `agent` WHERE `id`=?');
            $uStmt->execute([(int)$order['agent_id']]);
        } else {
            $uStmt = $pdo->prepare('SELECT `email`,`username` FROM `user` WHERE `id`=?');
            $uStmt->execute([(int)$order['user_id']]);
        }
        $uRow = $uStmt->fetch();
        $expStmt = $pdo->prepare('SELECT `expire_time` FROM `license` WHERE `id`=?');
        $expStmt->execute([$firstId]);
        $expireTime = (int)$expStmt->fetchColumn();
        if ($uRow && (string)$uRow['email'] !== '') {
            mail_scene_send('deliver', (string)$uRow['email'], [
                'userName'   => (string)$uRow['username'],
                'appName'    => (string)$app['name'],
                'cardNo'     => $cards[0],
                'orderNo'    => $orderNo,
                'openTime'   => date('Y-m-d H:i', $now),
                'expireTime' => $expireTime > 0 ? date('Y-m-d H:i', $expireTime) : '永久有效',
            ]);
        }
        mail_scene_send('prod_open_admin', '', [
            'userName' => (string)($uRow['username'] ?? ('用户#' . (int)$order['user_id'])),
            'appName'  => (string)$app['name'],
            'cardNo'   => $cards[0],
            'amount'   => (string)$order['amount'] . ' 元',
            'orderNo'  => $orderNo,
            'time'     => date('Y-m-d H:i', $now),
        ]);
    }

    $order['pay_status'] = 1;
    $order['pay_channel'] = $channel;
    $order['trade_no'] = $tradeNo;
    $order['license_id'] = $firstId;
    $order['paid_time'] = $now;
    return ['ok' => true, 'duplicate' => false, 'order' => $order, 'cards' => $cards, 'msg' => '支付成功'];
}

function pay_channels(): array
{
    $all = [
        'alipay' => ['name' => '支付宝', 'icon' => 'alipay'],
        'wechat' => ['name' => '微信支付', 'icon' => 'wechat'],
        'epay'   => ['name' => '易支付', 'icon' => 'epay'],
    ];
    $out = [];
    foreach ($all as $ch => $info) {
        if (plugin_enabled($ch)) {
            $out[$ch] = $info;
        }
    }
    return $out;
}
