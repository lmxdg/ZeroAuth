<?php
// Bug 广场核心：状态字典 / 发布 / 回复 / 点赞 / 统计（用户中心与后台共用）
declare(strict_types=1);

function bug_resolved_name(int $resolved): string
{
    return [0 => '未解决', 1 => '已解决'][$resolved] ?? '未解决';
}

function bug_resolved_tag(int $resolved): string
{
    return $resolved === 1 ? 'tag-ok' : 'tag-warn';
}

function bug_status_name(int $status): string
{
    return [1 => '已发布', 2 => '已下架'][$status] ?? '未知';
}

function bug_status_tag(int $status): string
{
    return [1 => 'tag-ok', 2 => 'tag-gray'][$status] ?? 'tag-gray';
}

/**
 * 发布 Bug 分享。
 * 成功返回 ['id' => int]；失败返回 ['error' => string]。
 */
function bug_create(int $userId, string $title, string $content, int $appId = 0, string $contact = ''): array
{
    $title  = trim($title);
    $content = trim($content);
    $contact = trim(mb_substr($contact, 0, 120));

    if (mb_strlen($title) < 5 || mb_strlen($title) > 100) {
        return ['error' => '标题长度需在 5 ~ 100 字之间。'];
    }
    if (mb_strlen($content) < 10 || mb_strlen($content) > 5000) {
        return ['error' => '问题描述长度需在 10 ~ 5000 字之间。'];
    }
    // 单用户每日最多分享 10 条，防灌水
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM `bug_post` WHERE `user_id`=? AND `created_at`>?');
        $stmt->execute([$userId, strtotime('today midnight')]);
        if ((int)$stmt->fetchColumn() >= 10) {
            return ['error' => '今日分享已达上限（10 条 / 天），请明天再试或先整理好问题再分享。'];
        }
    } catch (Throwable $ex) {
    }

    $now = time();
    try {
        $stmt = db()->prepare('INSERT INTO `bug_post` (`user_id`,`app_id`,`title`,`content`,`contact`,`status`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,1,?,?)');
        $stmt->execute([$userId, max(0, $appId), $title, $content, $contact, $now, $now]);
        return ['id' => (int)db()->lastInsertId()];
    } catch (Throwable $ex) {
        return ['error' => '发布失败，请稍后重试。'];
    }
}

/** 广场可见帖子（已发布），返回帖子或 null */
function bug_find_visible(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM `bug_post` WHERE `id`=? AND `status`=1 LIMIT 1');
    $stmt->execute([max(0, $id)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 楼层回复列表（旧→新） */
function bug_replies(int $postId): array
{
    $stmt = db()->prepare('SELECT * FROM `bug_reply` WHERE `post_id`=? ORDER BY `id` ASC LIMIT 200');
    $stmt->execute([max(0, $postId)]);
    return $stmt->fetchAll();
}

/** 重算帖子回复数（删除回复后调用） */
function bug_reply_count_refresh(int $postId): void
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM `bug_reply` WHERE `post_id`=?');
    $stmt->execute([max(0, $postId)]);
    $cnt = (int)$stmt->fetchColumn();
    db()->prepare('UPDATE `bug_post` SET `reply_count`=? WHERE `id`=?')->execute([$cnt, max(0, $postId)]);
}

/**
 * 添加回复。
 * 成功返回 ['id' => int]；失败返回 ['error' => string]。
 */
function bug_reply_add(int $postId, int $userType, int $userId, string $username, string $content): array
{
    $content = trim($content);
    if (mb_strlen($content) < 2 || mb_strlen($content) > 2000) {
        return ['error' => '回复内容长度需在 2 ~ 2000 字之间。'];
    }
    try {
        $stmt = db()->prepare('INSERT INTO `bug_reply` (`post_id`,`user_type`,`user_id`,`username`,`content`,`created_at`) VALUES (?,?,?,?,?,?)');
        $stmt->execute([max(0, $postId), $userType, $userId, mb_substr(trim($username), 0, 64), $content, time()]);
        db()->prepare('UPDATE `bug_post` SET `reply_count`=`reply_count`+1,`updated_at`=? WHERE `id`=?')
            ->execute([time(), max(0, $postId)]);
        return ['id' => (int)db()->lastInsertId()];
    } catch (Throwable $ex) {
        return ['error' => '回复失败，请稍后重试。'];
    }
}

/** 点赞（+1），防刷去重由调用方通过会话保证 */
function bug_like_add(int $postId): void
{
    db()->prepare('UPDATE `bug_post` SET `likes`=`likes`+1 WHERE `id`=?')->execute([max(0, $postId)]);
}

/** 浏览数 +1 */
function bug_views_inc(int $postId): void
{
    db()->prepare('UPDATE `bug_post` SET `views`=`views`+1 WHERE `id`=?')->execute([max(0, $postId)]);
}

/** 作者或管理员切换解决状态，返回最终状态（0/1） */
function bug_resolve_toggle(int $postId, bool $resolved): int
{
    $val = $resolved ? 1 : 0;
    db()->prepare('UPDATE `bug_post` SET `resolved`=?,`updated_at`=? WHERE `id`=?')->execute([$val, time(), max(0, $postId)]);
    return $val;
}

/** 删除帖子及其回复 */
function bug_delete(int $postId): void
{
    db()->prepare('DELETE FROM `bug_reply` WHERE `post_id`=?')->execute([max(0, $postId)]);
    db()->prepare('DELETE FROM `bug_post` WHERE `id`=?')->execute([max(0, $postId)]);
}

/** 广场统计：总分享 / 未解决 / 已解决 */
function bug_public_stats(): array
{
    try {
        $row = db()->query('SELECT COUNT(*) AS total, SUM(CASE WHEN `resolved`=0 THEN 1 ELSE 0 END) AS open, SUM(CASE WHEN `resolved`=1 THEN 1 ELSE 0 END) AS fixed FROM `bug_post` WHERE `status`=1')->fetch();
        return [
            'total' => (int)($row['total'] ?? 0),
            'open'  => (int)($row['open'] ?? 0),
            'fixed' => (int)($row['fixed'] ?? 0),
        ];
    } catch (Throwable $ex) {
        return ['total' => 0, 'open' => 0, 'fixed' => 0];
    }
}
