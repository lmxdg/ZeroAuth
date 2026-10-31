<?php
// 工单核心：创建/回复/关闭/评分（三端共用）
declare(strict_types=1);

require_once __DIR__ . '/Alert.php';
require_once __DIR__ . '/Mail.php';

function ticket_status_name(int $status): string
{
    return [1 => '待处理', 2 => '已回复', 3 => '已关闭'][$status] ?? '未知';
}

function ticket_status_tag(int $status): string
{
    return [1 => 'tag-warn', 2 => 'tag-info', 3 => 'tag-off'][$status] ?? 'tag-off';
}

function ticket_category_name(int $category): string
{
    return [1 => '授权问题', 2 => '支付问题', 3 => '部署咨询', 4 => '其他'][$category] ?? '其他';
}

function ticket_category_tag(int $category): string
{
    return [1 => 'tag-err', 2 => 'tag-warn', 3 => 'tag-info', 4 => 'tag-off'][$category] ?? 'tag-off';
}

function ticket_category_id($value): int
{
    $c = (int)$value;
    return isset([1 => 1, 2 => 2, 3 => 3][$c]) ? $c : 4;
}

function ticket_no_gen(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i = 0; $i < 5; $i++) {
        $rand = '';
        for ($j = 0; $j < 4; $j++) {
            $rand .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $no = 'GD' . date('YmdHis') . $rand;
        try {
            $stmt = db()->prepare('SELECT COUNT(*) FROM `ticket` WHERE `ticket_no`=?');
            $stmt->execute([$no]);
            if ((int)$stmt->fetchColumn() === 0) {
                return $no;
            }
        } catch (Throwable $ex) {
            return $no;
        }
    }
    return 'GD' . date('YmdHis') . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

function ticket_no_display(array $row): string
{
    $no = trim((string)($row['ticket_no'] ?? ''));
    return $no !== '' ? $no : '#' . (int)($row['id'] ?? 0);
}

function ticket_submitter_info(int $userType, int $userId): array
{
    $row = false;
    try {
        $stmt = db()->prepare('SELECT `username`,`email` FROM ' . ($userType === 1 ? '`user`' : '`agent`') . ' WHERE `id`=?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
    } catch (Throwable $ex) {
    }
    return [
        'name'  => $row ? (string)$row['username'] : '#' . $userId,
        'role'  => $userType === 1 ? '用户' : '代理商',
        'email' => $row ? trim((string)$row['email']) : '',
    ];
}

function ticket_quick_reply_defaults(): array
{
    return [
        '您好，已收到您的工单，我们会尽快处理。',
        '您好，请提供相关截图或错误信息，以便我们更快定位问题。',
        '您的问题已处理完成，请确认，如无其他问题可关闭工单。',
        '感谢您的耐心等待，祝您使用愉快。',
    ];
}

function ticket_quick_replies(): array
{
    $raw = cfg('ticket_quick_replies', '');
    if ($raw !== '') {
        $arr = json_decode($raw, true);
        if (is_array($arr)) {
            $arr = array_values(array_filter(array_map('strval', $arr), static fn($s) => trim($s) !== ''));
            if ($arr) {
                return array_slice($arr, 0, 30);
            }
        }
    }
    return ticket_quick_reply_defaults();
}

function ticket_quick_replies_save(array $list): bool
{
    $list = array_values(array_filter(array_map('strval', $list), static fn($s) => trim($s) !== ''));
    $list = array_map(static fn($s) => mb_substr(trim($s), 0, 200), $list);
    $list = array_slice($list, 0, 30);
    try {
        set_cfg('ticket_quick_replies', json_encode($list, JSON_UNESCAPED_UNICODE), '工单快捷回复模板');
        return true;
    } catch (Throwable $ex) {
        return false;
    }
}

function ticket_rating_html(int $rating): string
{
    if ($rating < 1 || $rating > 5) {
        return '';
    }
    $html = '<span class="stars-static" aria-label="' . $rating . '星">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<span class="star-pick' . ($i <= $rating ? ' on' : '') . '">★</span>';
    }
    return $html . '</span>';
}

function ticket_rate(int $userType, int $userId, int $ticketId, int $stars, string $note): array
{
    $stars = (int)$stars;
    if ($stars < 1 || $stars > 5) {
        return ['error' => '请先选择评分星级。'];
    }
    $ticket = ticket_find_mine($userType, $userId, $ticketId);
    if (!$ticket) {
        return ['error' => '工单不存在。'];
    }
    if ((int)$ticket['status'] !== 3) {
        return ['error' => '工单关闭后才能评分。'];
    }
    if ((int)($ticket['rating'] ?? 0) > 0) {
        return ['error' => '该工单已评分，无需重复评分。'];
    }
    $stmt = db()->prepare('UPDATE `ticket` SET `rating`=?, `rating_note`=?, `rated_at`=? WHERE `id`=?');
    $stmt->execute([$stars, mb_substr(trim($note), 0, 190), time(), $ticketId]);
    audit('ticket', 'rate', $userType, $userId, '工单#' . $ticketId . ' 评分 ' . $stars . ' 星');
    return ['ok' => true];
}

function ticket_create(int $userType, int $userId, string $title, string $content, int $category = 0): array
{
    $title = trim($title);
    $content = trim($content);
    if ($title === '' || $content === '') {
        return ['error' => '标题和内容不能为空。'];
    }
    if (mb_strlen($title) > 100) {
        return ['error' => '标题不能超过 100 字。'];
    }
    $category = (int)$category;
    if (!isset([1 => 1, 2 => 2, 3 => 3, 4 => 4][$category])) {
        return ['error' => '请选择问题分类。'];
    }
    $now = time();
    $ticketNo = ticket_no_gen();
    try {
        $stmt = db()->prepare(
            'INSERT INTO `ticket` (`ticket_no`,`user_type`,`user_id`,`title`,`category`,`status`,`last_reply`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,1,0,?,?)'
        );
        $stmt->execute([$ticketNo, $userType, $userId, mb_substr($title, 0, 190), $category, $now, $now]);
        $ticketId = (int)db()->lastInsertId();
        $stmt = db()->prepare(
            'INSERT INTO `ticket_reply` (`ticket_id`,`sender_type`,`content`,`created_at`) VALUES (?,?,?,?)'
        );
        $stmt->execute([$ticketId, 1, $content, $now]);
    } catch (Throwable $ex) {
        @file_put_contents(RUNTIME_PATH . '/schema_upgrade.log', date('Y-m-d H:i:s') . ' 工单提交失败 ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
        return ['error' => '提交失败，请稍后重试。'];
    }
    $sub = ticket_submitter_info($userType, $userId);
    alert_add(1, 'ticket', '收到新工单', $sub['role'] . '「' . $sub['name'] . '」提交了' . ticket_category_name($category) . '工单：' . $title);
    mail_scene_send('ticket_new', '', [
        'event'     => '提交了新工单',
        'submitter' => $sub['name'],
        'role'      => $sub['role'],
        'ticketId'  => $ticketNo,
        'category'  => ticket_category_name($category),
        'title'     => $title,
        'content'   => mb_substr($content, 0, 300),
        'time'      => date('Y-m-d H:i', $now),
    ]);
    audit('ticket', 'create', $userType, $userId, '工单 ' . $ticketNo . ' ' . $title);
    return ['id' => $ticketId, 'no' => $ticketNo];
}

function ticket_find_mine(int $userType, int $userId, int $ticketId): ?array
{
    $stmt = db()->prepare('SELECT * FROM `ticket` WHERE `id`=? AND `user_type`=? AND `user_id`=? LIMIT 1');
    $stmt->execute([$ticketId, $userType, $userId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function ticket_replies(int $ticketId): array
{
    $stmt = db()->prepare('SELECT * FROM `ticket_reply` WHERE `ticket_id`=? ORDER BY `id` ASC');
    $stmt->execute([$ticketId]);
    return $stmt->fetchAll();
}

function ticket_reply_mine(int $userType, int $userId, int $ticketId, string $content): array
{
    $content = trim($content);
    if ($content === '') {
        return ['error' => '回复内容不能为空。'];
    }
    $ticket = ticket_find_mine($userType, $userId, $ticketId);
    if (!$ticket) {
        return ['error' => '工单不存在。'];
    }
    if ((int)$ticket['status'] === 3) {
        return ['error' => '工单已关闭，如仍有问题请提交新工单。'];
    }
    $now = time();
    $stmt = db()->prepare('INSERT INTO `ticket_reply` (`ticket_id`,`sender_type`,`content`,`created_at`) VALUES (?,1,?,?)');
    $stmt->execute([$ticketId, $content, $now]);
    $stmt = db()->prepare('UPDATE `ticket` SET `status`=1, `last_reply`=0, `updated_at`=? WHERE `id`=?');
    $stmt->execute([$now, $ticketId]);
    $sub = ticket_submitter_info($userType, $userId);
    mail_scene_send('ticket_new', '', [
        'event'     => '追加了新回复',
        'submitter' => $sub['name'],
        'role'      => $sub['role'],
        'ticketId'  => ticket_no_display($ticket),
        'category'  => ticket_category_name((int)$ticket['category']),
        'title'     => (string)$ticket['title'],
        'content'   => mb_substr($content, 0, 300),
        'time'      => date('Y-m-d H:i', $now),
    ]);
    audit('ticket', 'reply', $userType, $userId, '工单 ' . ticket_no_display($ticket));
    return ['ok' => true];
}

function ticket_close_mine(int $userType, int $userId, int $ticketId): array
{
    $ticket = ticket_find_mine($userType, $userId, $ticketId);
    if (!$ticket) {
        return ['error' => '工单不存在。'];
    }
    if ((int)$ticket['status'] === 3) {
        return ['error' => '工单已关闭。'];
    }
    $stmt = db()->prepare('UPDATE `ticket` SET `status`=3, `updated_at`=? WHERE `id`=?');
    $stmt->execute([time(), $ticketId]);
    audit('ticket', 'close', $userType, $userId, '工单#' . $ticketId);
    return ['ok' => true];
}

function ticket_reply_admin(int $adminId, int $ticketId, string $content): array
{
    $content = trim($content);
    if ($content === '') {
        return ['error' => '回复内容不能为空。'];
    }
    $stmt = db()->prepare('SELECT * FROM `ticket` WHERE `id`=? LIMIT 1');
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch();
    if (!$ticket) {
        return ['error' => '工单不存在。'];
    }
    if ((int)$ticket['status'] === 3) {
        return ['error' => '工单已关闭，无法回复。'];
    }
    $now = time();
    db()->prepare('INSERT INTO `ticket_reply` (`ticket_id`,`sender_type`,`content`,`created_at`) VALUES (?,2,?,?)')
        ->execute([$ticketId, $content, $now]);
    db()->prepare('UPDATE `ticket` SET `status`=2,`last_reply`=1,`updated_at`=? WHERE `id`=?')
        ->execute([$now, $ticketId]);
    $sub = ticket_submitter_info((int)$ticket['user_type'], (int)$ticket['user_id']);
    if ($sub['email'] !== '') {
        mail_scene_send('ticket_reply', $sub['email'], [
            'userName' => $sub['name'],
            'ticketId' => ticket_no_display($ticket),
            'title'    => (string)$ticket['title'],
            'content'  => mb_substr($content, 0, 300),
            'time'     => date('Y-m-d H:i', $now),
        ]);
    }
    audit('ticket', 'reply', 1, $adminId, '回复工单 ' . ticket_no_display($ticket) . '：' . $ticket['title']);
    return ['ok' => true];
}
