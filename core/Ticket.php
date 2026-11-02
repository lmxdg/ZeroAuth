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

function ticket_category_defaults(): array
{
    return [1 => '授权问题', 2 => '支付问题', 3 => '部署咨询', 4 => '其他'];
}

/**
 * 工单分类（后台可改）：存储于 sys_config 的 ticket_categories（JSON：[{"id":1,"name":"授权问题"},…]），
 * 未配置时回落到默认四分类。id 对应 ticket.category 字段（tinyint，1~255）。
 */
function ticket_categories(): array
{
    static $cats = null;
    if ($cats !== null) {
        return $cats;
    }
    $cats = ticket_category_defaults();
    $raw = cfg('ticket_categories', '');
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $out = [];
            foreach ($j as $row) {
                $id = (int)($row['id'] ?? 0);
                $name = trim((string)($row['name'] ?? ''));
                if ($id > 0 && $id <= 255 && $name !== '' && !isset($out[$id])) {
                    $out[$id] = mb_substr($name, 0, 20);
                }
            }
            if ($out !== []) {
                ksort($out);
                $cats = $out;
            }
        }
    }
    return $cats;
}

/** 保存工单分类（后台分类管理调用），成功返回 true */
function ticket_category_save(array $cats): bool
{
    $out = [];
    foreach ($cats as $row) {
        $id = (int)($row['id'] ?? 0);
        $name = trim((string)($row['name'] ?? ''));
        if ($id > 0 && $id <= 255 && $name !== '') {
            if (isset($out[$id])) {
                return false;
            }
            $out[$id] = mb_substr($name, 0, 20);
        }
    }
    if ($out === []) {
        return false;
    }
    ksort($out);
    $json = [];
    foreach ($out as $id => $name) {
        $json[] = ['id' => $id, 'name' => $name];
    }
    try {
        set_cfg('ticket_categories', json_encode($json, JSON_UNESCAPED_UNICODE), '工单分类配置');
        return true;
    } catch (Throwable $ex) {
        return false;
    }
}

function ticket_category_name(int $category): string
{
    $cats = ticket_categories();
    return $cats[$category] ?? ($cats[min(array_keys($cats))] ?? '其他');
}

function ticket_category_tag(int $category): string
{
    // 按分类顺序循环取色，保证新增分类也有稳定颜色
    $palette = ['tag-err', 'tag-warn', 'tag-info', 'tag-ok', 'tag-off'];
    $ids = array_keys(ticket_categories());
    $idx = array_search($category, $ids, true);
    return $palette[$idx === false ? count($palette) - 1 : $idx % count($palette)];
}

function ticket_category_id($value): int
{
    $c = (int)$value;
    return isset(ticket_categories()[$c]) ? $c : (int)(array_key_first(ticket_categories()));
}

/** 指定分类已被多少工单使用（删除前校验） */
function ticket_category_used(int $category): int
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM `ticket` WHERE `category`=?');
        $stmt->execute([$category]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
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
    if (!isset(ticket_categories()[$category])) {
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
        $replyId = (int)db()->lastInsertId();
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
    return ['id' => $ticketId, 'no' => $ticketNo, 'reply_id' => $replyId];
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
    $replyId = (int)db()->lastInsertId();
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
    return ['ok' => true, 'reply_id' => $replyId];
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
    $stmt = db()->prepare('INSERT INTO `ticket_reply` (`ticket_id`,`sender_type`,`content`,`created_at`) VALUES (?,2,?,?)');
    $stmt->execute([$ticketId, $content, $now]);
    $replyId = (int)db()->lastInsertId();
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
    return ['ok' => true, 'reply_id' => $replyId];
}

/* ============ 工单附件 ============ */

const TICKET_ATTACHMENT_MAX_SIZE = 10 * 1024 * 1024;   // 单文件 10MB
const TICKET_ATTACHMENT_MAX_COUNT = 5;                 // 每次提交最多 5 个附件

/** 附件允许的扩展名白名单（禁止可执行脚本与 HTML/SVG 等可直接渲染的文件） */
function ticket_attachment_exts(): array
{
    return [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'zip', 'rar', '7z', 'gz', 'tar',
        'txt', 'log', 'json', 'xml', 'csv',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'apk',
    ];
}

/** 附件表懒创建（随 schema_upgrade 迁移，这里兜底） */
function ticket_attachment_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS `ticket_attachment` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `ticket_id` int unsigned NOT NULL DEFAULT 0 COMMENT '工单ID',
            `reply_id` int unsigned NOT NULL DEFAULT 0 COMMENT '回复ID（0=首帖）',
            `user_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '上传方 1用户/代理商 2管理员',
            `user_id` int unsigned NOT NULL DEFAULT 0,
            `name` varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
            `path` varchar(255) NOT NULL DEFAULT '' COMMENT '存储相对路径（uploads/ticket/ 下）',
            `size` int unsigned NOT NULL DEFAULT 0 COMMENT '字节数',
            `ext` varchar(16) NOT NULL DEFAULT '' COMMENT '扩展名',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `ticket` (`ticket_id`),
            KEY `reply` (`reply_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工单附件'");
    } catch (Throwable $ex) {
        @file_put_contents(RUNTIME_PATH . '/schema_upgrade.log', date('Y-m-d H:i:s') . ' ticket_attachment: ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

/** 保存单个上传附件，返回 ['ok'=>bool,'msg'=>string] */
function ticket_attachment_save(int $ticketId, int $replyId, int $userType, int $userId, array $file): array
{
    ticket_attachment_table();
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'msg' => ''];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => '附件上传失败（错误码 ' . $err . '）。'];
    }
    if ((int)$file['size'] <= 0 || (int)$file['size'] > TICKET_ATTACHMENT_MAX_SIZE) {
        return ['ok' => false, 'msg' => '「' . (string)$file['name'] . '」超过 10MB 限制。'];
    }
    $origName = trim((string)$file['name']);
    $ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, ticket_attachment_exts(), true)) {
        return ['ok' => false, 'msg' => '「' . $origName . '」类型不支持，允许：' . implode(' / ', ticket_attachment_exts())];
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        return ['ok' => false, 'msg' => '附件数据无效。'];
    }

    $dir = ROOT_PATH . '/uploads/ticket/' . date('Ym');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'msg' => '附件目录创建失败，请检查 uploads/ 目录权限。'];
    }
    $stored = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!@move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) {
        return ['ok' => false, 'msg' => '附件保存失败，请稍后重试。'];
    }
    @chmod($dir . '/' . $stored, 0644);
    $rel = 'uploads/ticket/' . date('Ym') . '/' . $stored;

    try {
        db()->prepare(
            'INSERT INTO `ticket_attachment` (`ticket_id`,`reply_id`,`user_type`,`user_id`,`name`,`path`,`size`,`ext`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $ticketId, $replyId, $userType, $userId,
            mb_substr($origName, 0, 250), $rel, (int)$file['size'], $ext, time(),
        ]);
    } catch (Throwable $ex) {
        @unlink($dir . '/' . $stored);
        @file_put_contents(RUNTIME_PATH . '/schema_upgrade.log', date('Y-m-d H:i:s') . ' 工单附件记录失败 ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
        return ['ok' => false, 'msg' => '附件记录失败，请稍后重试。'];
    }
    return ['ok' => true, 'msg' => ''];
}

/**
 * 批量保存本次提交的附件（$_FILES['files']），返回错误列表（空数组表示全部成功）。
 * 成功项不回滚，失败项逐条提示。
 */
function ticket_attachment_save_all(int $ticketId, int $replyId, int $userType, int $userId): array
{
    $files = $_FILES['files'] ?? null;
    if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
        return [];
    }
    $errors = [];
    $saved = 0;
    $count = count($files['name']);
    for ($i = 0; $i < $count && $saved < TICKET_ATTACHMENT_MAX_COUNT; $i++) {
        $one = [
            'name'     => $files['name'][$i] ?? '',
            'type'     => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $files['size'][$i] ?? 0,
        ];
        if ((int)$one['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($saved >= TICKET_ATTACHMENT_MAX_COUNT) {
            $errors[] = '每次最多上传 ' . TICKET_ATTACHMENT_MAX_COUNT . ' 个附件。';
            break;
        }
        $res = ticket_attachment_save($ticketId, $replyId, $userType, $userId, $one);
        if ($res['ok']) {
            $saved++;
        } elseif ($res['msg'] !== '') {
            $errors[] = $res['msg'];
        }
    }
    if ($count > TICKET_ATTACHMENT_MAX_COUNT) {
        $errors[] = '每次最多上传 ' . TICKET_ATTACHMENT_MAX_COUNT . ' 个附件，超出部分已忽略。';
    }
    return $errors;
}

/** 工单全部附件，按回复分组：[reply_id => [attachment, …]] */
function ticket_attachments(int $ticketId): array
{
    ticket_attachment_table();
    try {
        $stmt = db()->prepare('SELECT * FROM `ticket_attachment` WHERE `ticket_id`=? ORDER BY `id` ASC');
        $stmt->execute([$ticketId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
    $grouped = [];
    foreach ($rows as $r) {
        $grouped[(int)$r['reply_id']][] = $r;
    }
    return $grouped;
}

/** 附件下载地址 */
function ticket_attachment_url(array $a): string
{
    $path = ltrim((string)($a['path'] ?? ''), '/');
    if ($path === '' || str_contains($path, '..')) {
        return '';
    }
    return base_url() . $path;
}

/** 附件大小展示 */
function ticket_attachment_size(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}

/** 附件展示 HTML（对话气泡内调用） */
function ticket_attachment_html(array $atts): string
{
    if (!$atts) {
        return '';
    }
    $html = '<div class="chat-atts">';
    foreach ($atts as $a) {
        $url = ticket_attachment_url($a);
        if ($url === '') {
            continue;
        }
        $html .= '<a class="chat-att" href="' . e($url) . '" target="_blank" rel="noopener noreferrer" download>'
            . '<span class="chat-att-icon">📎</span>'
            . '<span class="chat-att-name">' . e((string)$a['name']) . '</span>'
            . '<span class="chat-att-size">' . e(ticket_attachment_size((int)$a['size'])) . '</span>'
            . '</a>';
    }
    return $html . '</div>';
}
