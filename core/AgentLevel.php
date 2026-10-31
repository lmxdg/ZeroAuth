<?php
// 代理等级：折扣/权益计算
declare(strict_types=1);

function agent_level_find(int $levelId, bool $forUser = false): ?array
{
    $sql = 'SELECT * FROM `agent_level` WHERE `id`=? AND `status`=1' . ($forUser ? ' AND `auto_open`=1' : '') . ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$levelId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function agent_level_unique_username(string $username, int $userId): string
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM `agent` WHERE `username`=?');
    $stmt->execute([$username]);
    return (int)$stmt->fetchColumn() > 0 ? mb_substr($username, 0, 48) . '.' . $userId : $username;
}

function agent_migrate_user_data(int $userId, int $agentId): void
{
    try {
        db()->prepare('UPDATE `license` SET `owner_type`=2,`owner_id`=? WHERE `user_id`=? AND `owner_type`=1 AND `user_id`=?')
            ->execute([$agentId, $userId, $userId]);
        $licCount = db()->prepare('SELECT COUNT(*) FROM `license` WHERE `owner_type`=2 AND `owner_id`=?');
        $licCount->execute([$agentId]);
        db()->prepare('UPDATE `ticket` SET `user_type`=2,`user_id`=? WHERE `user_type`=1 AND `user_id`=?')
            ->execute([$agentId, $userId]);
        audit('level', 'migrate_user_data', 1, $userId,
            '用户数据迁移至代理商#' . $agentId . '（授权、工单）');
    } catch (Throwable $ex) {
        @file_put_contents(RUNTIME_PATH . '/schema_upgrade.log', date('Y-m-d H:i:s') . ' 用户数据迁移失败 ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

function agent_level_purchase(int $accountType, array $account, int $levelId): array
{
    $level = agent_level_find($levelId, $accountType === 1);
    if (!$level) {
        return ['error' => '等级不存在或未开放。'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $now = time();
        $newAgent = false;
        $plainPassword = '';
        $userPaid = false;   
        $agentPaid = false;  
        $price = (float)$level['price'];

        if ($accountType === 2) {
            $stmt = $pdo->prepare('SELECT `id`,`username`,`balance`,`level_id` FROM `agent` WHERE `id`=? FOR UPDATE');
            $stmt->execute([(int)$account['id']]);
            $agent = $stmt->fetch();
            if (!$agent) {
                throw new RuntimeException('代理商账号不存在。');
            }
            if ((int)$agent['level_id'] === (int)$level['id']) {
                throw new RuntimeException('您已经是该等级，无需重复开通。');
            }
            if ((float)$agent['balance'] < $price) {
                throw new RuntimeException('余额不足，请先充值（需 ' . number_format($price, 2) . ' 元）。');
            }
            $pdo->prepare('UPDATE `agent` SET `balance`=`balance`-?,`level_id`=?,`discount`=?,`gift_balance`=`gift_balance`+? WHERE `id`=?')
                ->execute([
                    number_format($price, 2, '.', ''),
                    (int)$level['id'],
                    number_format((float)$level['discount'], 2, '.', ''),
                    number_format((float)$level['gift_balance'], 2, '.', ''),
                    (int)$agent['id'],
                ]);
            $stmt = $pdo->prepare('SELECT `balance` FROM `agent` WHERE `id`=?');
            $stmt->execute([(int)$agent['id']]);
            $after = (float)$stmt->fetchColumn();
            $flowType = (int)$agent['level_id'] > 0 ? 4 : 3;
            $accountId = (int)$agent['id'];
            $agentPaid = true;
        } else {
            $stmt = $pdo->prepare('SELECT `id`,`username`,`balance` FROM `user` WHERE `id`=? FOR UPDATE');
            $stmt->execute([(int)$account['id']]);
            $user = $stmt->fetch();
            if (!$user) {
                throw new RuntimeException('用户账号不存在。');
            }
            if ((float)$user['balance'] < $price) {
                throw new RuntimeException('余额不足，请先充值（需 ' . number_format($price, 2) . ' 元）。');
            }
            $stmt = $pdo->prepare('UPDATE `user` SET `balance`=`balance`-? WHERE `id`=? AND `balance`>=?');
            $stmt->execute([number_format($price, 2, '.', ''), (int)$user['id'], number_format($price, 2, '.', '')]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('余额扣减失败，请重试。');
            }
            $agentUserId = (int)$user['id'];
            $userPaid = true;

            $stmt = $pdo->prepare('SELECT `id`,`username`,`level_id` FROM `agent` WHERE `username`=? LIMIT 1');
            $stmt->execute([(string)$user['username']]);
            $existAgent = $stmt->fetch();
            if ($existAgent) {
                if ((int)$existAgent['level_id'] === (int)$level['id']) {
                    throw new RuntimeException('您已经是该等级，无需重复开通。');
                }
                $flowType = (int)$existAgent['level_id'] > 0 ? 4 : 3;
                $pdo->prepare('UPDATE `agent` SET `level_id`=?,`discount`=?,`gift_balance`=`gift_balance`+? WHERE `id`=?')
                    ->execute([
                        (int)$level['id'],
                        number_format((float)$level['discount'], 2, '.', ''),
                        number_format((float)$level['gift_balance'], 2, '.', ''),
                        (int)$existAgent['id'],
                    ]);
                $accountId = (int)$existAgent['id'];
            } else {
                $plainPassword = '';
                $agentName = agent_level_unique_username((string)$user['username'], (int)$user['id']);
                $flowType = 3;
                $stmt = $pdo->prepare('SELECT `password` FROM `user` WHERE `id`=?');
                $stmt->execute([$agentUserId]);
                $userHash = (string)$stmt->fetchColumn();
                $stmt = $pdo->prepare(
                    'INSERT INTO `agent` (`username`,`password`,`email`,`balance`,`discount`,`level_id`,`gift_balance`,`status`,`created_at`) VALUES (?,?,?,?,?,?,0,1,?)'
                )->execute([
                    $agentName,
                    $userHash !== '' ? $userHash : password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
                    (string)($user['email'] ?? ''),
                    '0.00',
                    number_format((float)$level['discount'], 2, '.', ''),
                    (int)$level['id'],
                    number_format((float)$level['gift_balance'], 2, '.', ''),
                    $now,
                ]);
                $accountId = (int)$pdo->lastInsertId();
                $newAgent = true;
            }
            $stmt = $pdo->prepare('SELECT `balance` FROM `user` WHERE `id`=?');
            $stmt->execute([$agentUserId]);
            $afterUserBalance = (float)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT `balance` FROM `agent` WHERE `id`=?');
            $stmt->execute([$accountId]);
            $after = (float)$stmt->fetchColumn();
        }

        $pdo->commit();
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['error' => $ex->getMessage()];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['error' => '开通失败：' . $ex->getMessage()];
    }

    if ($accountType === 1) {
        agent_migrate_user_data($agentUserId, $accountId);
    }

    require_once ROOT_PATH . '/core/schema_upgrade.php';
    $payLabel = $flowType === 3 ? '开通' : '升级';
    if ($userPaid) {
        finance_flow_add(1, $agentUserId, $flowType, $price, $afterUserBalance, '', $payLabel . '代理等级「' . $level['name'] . '」扣款');
    }
    if ($agentPaid) {
        finance_flow_add(2, $accountId, $flowType, $price, $after, '', $payLabel . '代理等级「' . $level['name'] . '」扣款');
    }
    finance_flow_add(2, $accountId, $flowType, (float)$level['gift_balance'], $after, '', '等级「' . $level['name'] . '」赠送余额');
    audit('level', $newAgent ? 'user_open_agent' : 'agent_upgrade', $accountType, (int)$account['id'],
        '等级：' . $level['name'] . ' 金额：' . number_format($price, 2));

    return [
        'newAgent' => $newAgent,
        'password' => $plainPassword,
        'level'    => $level,
        'gift'     => (float)$level['gift_balance'],
    ];
}
