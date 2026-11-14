<?php
// 管理员后台 - Bug 广场管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Bug.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $postId = (int)($_POST['post_id'] ?? 0);

    $find = static function (int $id): ?array {
        $stmt = db()->prepare('SELECT * FROM `bug_post` WHERE `id`=? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    };

    if ($action === 'hide') {
        $post = $find($postId);
        if ($post) {
            db()->prepare('UPDATE `bug_post` SET `status`=2,`updated_at`=? WHERE `id`=?')->execute([time(), $postId]);
            audit('bug', 'hide', 1, (int)$admin['id'], '下架 Bug 分享 #' . $postId . '：' . (string)$post['title']);
            flash_set('ok', '已下架，广场不再展示该分享。');
        }
        redirect('bug_manage.php' . ($postId > 0 && (string)($_POST['back'] ?? '') === 'detail' ? '?id=' . $postId : ''));
    }

    if ($action === 'show') {
        $post = $find($postId);
        if ($post) {
            db()->prepare('UPDATE `bug_post` SET `status`=1,`updated_at`=? WHERE `id`=?')->execute([time(), $postId]);
            audit('bug', 'show', 1, (int)$admin['id'], '恢复 Bug 分享 #' . $postId . '：' . (string)$post['title']);
            flash_set('ok', '已恢复展示。');
        }
        redirect('bug_manage.php' . ($postId > 0 && (string)($_POST['back'] ?? '') === 'detail' ? '?id=' . $postId : ''));
    }

    if ($action === 'resolve' || $action === 'unresolve') {
        $val = bug_resolve_toggle($postId, $action === 'resolve');
        audit('bug', $action, 1, (int)$admin['id'], 'Bug 分享 #' . $postId . ' 解决状态改为 ' . ($val === 1 ? '已解决' : '未解决'));
        flash_set('ok', '已更新解决状态。');
        redirect('bug_manage.php' . ($postId > 0 && (string)($_POST['back'] ?? '') === 'detail' ? '?id=' . $postId : ''));
    }

    if ($action === 'delete') {
        $post = $find($postId);
        if ($post) {
            bug_delete($postId);
            audit('bug', 'delete', 1, (int)$admin['id'], '删除 Bug 分享 #' . $postId . '：' . (string)$post['title']);
            flash_set('ok', '已删除该分享及其回复。');
        }
        redirect('bug_manage.php');
    }

    if ($action === 'reply') {
        $post = $find($postId);
        if (!$post) {
            flash_set('err', '分享不存在。');
            redirect('bug_manage.php');
        }
        $res = bug_reply_add($postId, 2, (int)$admin['id'], '管理员', (string)($_POST['content'] ?? ''));
        if (isset($res['error'])) {
            flash_set('err', $res['error']);
        } else {
            audit('bug', 'reply', 1, (int)$admin['id'], '回复 Bug 分享 #' . $postId);
            flash_set('ok', '回复成功。');
        }
        redirect('bug_manage.php?id=' . $postId);
    }

    if ($action === 'reply_del') {
        $stmt = db()->prepare('SELECT `id`,`post_id` FROM `bug_reply` WHERE `id`=? LIMIT 1');
        $stmt->execute([(int)($_POST['reply_id'] ?? 0)]);
        $rp = $stmt->fetch();
        if ($rp && (int)$rp['post_id'] === $postId) {
            db()->prepare('DELETE FROM `bug_reply` WHERE `id`=?')->execute([(int)$rp['id']]);
            bug_reply_count_refresh($postId);
            audit('bug', 'reply_del', 1, (int)$admin['id'], '删除 Bug 分享 #' . $postId . ' 的回复 #' . (int)$rp['id']);
            flash_set('ok', '回复已删除。');
        }
        redirect('bug_manage.php?id=' . $postId);
    }
}

// ---------- 详情页 ----------
$detailId = (int)($_GET['id'] ?? 0);
if ($detailId > 0) {
    $stmt = db()->prepare('SELECT * FROM `bug_post` WHERE `id`=? LIMIT 1');
    $stmt->execute([$detailId]);
    $bug = $stmt->fetch() ?: null;
    if (!$bug) {
        admin_header('Bug 广场管理', 'bug', $admin);
        echo '<section class="card"><div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>该分享不存在或已删除。</span></div><div style="margin-top:12px"><a class="btn-plain" href="bug_manage.php">返回列表</a></div></section>';
        admin_footer();
        exit;
    }

    $replies = bug_replies((int)$bug['id']);
    $poster  = '匿名';
    if ((int)$bug['user_id'] > 0) {
        $stmt = db()->prepare('SELECT `username`,`email` FROM `user` WHERE `id`=? LIMIT 1');
        $stmt->execute([(int)$bug['user_id']]);
        $u = $stmt->fetch();
        if ($u) {
            $poster = (string)$u['username'] . '（' . ((string)$u['email'] !== '' ? (string)$u['email'] : 'ID ' . (int)$bug['user_id']) . '）';
        } else {
            $poster = '用户 #' . (int)$bug['user_id'];
        }
    }
    $appName = '不限 / 通用';
    if ((int)$bug['app_id'] > 0) {
        $stmt = db()->prepare('SELECT `name` FROM `app` WHERE `id`=? LIMIT 1');
        $stmt->execute([(int)$bug['app_id']]);
        $n = (string)$stmt->fetchColumn();
        $appName = $n !== '' ? $n : '应用 #' . (int)$bug['app_id'];
    }

    admin_header('Bug 详情', 'bug', $admin);
    $flash = flash_take();
    if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
    <?php endif; ?>

<style>

.bug-meta{display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;color:var(--t3);margin-bottom:6px}
.bug-meta b{color:var(--t1);font-weight:500}
.bug-content{margin-top:10px;font-size:13px;line-height:23px;color:var(--t1);white-space:pre-wrap;word-break:break-word;background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:14px 16px}
.chat-thread{display:flex;flex-direction:column;gap:14px;margin-top:14px}
.chat-row{display:flex;gap:10px;align-items:flex-start}
.chat-avatar{width:32px;height:32px;border-radius:50%;flex:none;background:var(--bg)}
.chat-col{max-width:80%;display:flex;flex-direction:column;gap:4px;min-width:0}
.chat-meta{font-size:12px;color:var(--t3);display:flex;gap:8px}
.chat-name-admin{color:var(--primary);font-weight:500}
.chat-bubble{background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:10px 12px;font-size:13px;color:var(--t1);line-height:20px;word-break:break-word;white-space:pre-wrap}
.chat-bubble-admin{background:var(--primary-bg,#ECF2FE);border-color:rgba(0,110,255,.25)}
</style>

<section class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div style="min-width:0">
            <h2 class="card-title" style="margin-bottom:6px"><?php echo e($bug['title']); ?></h2>
            <div class="bug-meta">
                <span class="tag <?php echo bug_resolved_tag((int)$bug['resolved']); ?>"><?php echo e(bug_resolved_name((int)$bug['resolved'])); ?></span>
                <span class="tag <?php echo bug_status_tag((int)$bug['status']); ?>"><?php echo e(bug_status_name((int)$bug['status'])); ?></span>
                <span>分享者：<b><?php echo e($poster); ?></b></span>
                <span>应用：<b><?php echo e($appName); ?></b></span>
                <span>发布：<?php echo e(date('Y-m-d H:i', (int)$bug['created_at'])); ?></span>
                <span>浏览 <?php echo (int)$bug['views']; ?> · 点赞 <?php echo (int)$bug['likes']; ?> · 回复 <?php echo (int)$bug['reply_count']; ?></span>
            </div>
        </div>
        <a class="btn-plain" href="bug_manage.php">返回列表</a>
    </div>

    <div class="bug-content"><?php echo e($bug['content']); ?></div>
    <?php if ((string)$bug['contact'] !== ''): ?>
    <div class="bug-meta" style="margin-top:10px">联系方式：<b><?php echo e($bug['contact']); ?></b></div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
        <form method="post" style="display:inline">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="<?php echo (int)$bug['status'] === 1 ? 'hide' : 'show'; ?>">
            <input type="hidden" name="post_id" value="<?php echo (int)$bug['id']; ?>">
            <input type="hidden" name="back" value="detail">
            <button class="btn-plain" type="submit"><?php echo (int)$bug['status'] === 1 ? '下架（广场隐藏）' : '恢复展示'; ?></button>
        </form>
        <form method="post" style="display:inline">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="<?php echo (int)$bug['resolved'] === 1 ? 'unresolve' : 'resolve'; ?>">
            <input type="hidden" name="post_id" value="<?php echo (int)$bug['id']; ?>">
            <input type="hidden" name="back" value="detail">
            <button class="btn-plain" type="submit"><?php echo (int)$bug['resolved'] === 1 ? '标记为未解决' : '标记为已解决'; ?></button>
        </form>
        <form method="post" data-confirm="确定删除该分享及其全部回复？删除后无法恢复。" style="display:inline">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="post_id" value="<?php echo (int)$bug['id']; ?>">
            <button class="btn-danger" type="submit">删除分享</button>
        </form>
    </div>
</section>

<section class="card" style="margin-top:16px">
    <h2 class="card-title">回复（<?php echo count($replies); ?>）</h2>
    <?php if (!$replies): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无回复。</span></div>
    <?php else: ?>
    <div class="chat-thread">
        <?php foreach ($replies as $rp):
            $isAdmin = (int)$rp['user_type'] === 2;
        ?>
        <div class="chat-row">
            <img class="chat-avatar" src="../assets/svg/mrtx.svg" alt="">
            <div class="chat-col">
                <div class="chat-meta">
                    <span<?php echo $isAdmin ? ' class="chat-name-admin"' : ''; ?>><?php echo e($isAdmin ? '管理员' : ((string)$rp['username'] !== '' ? (string)$rp['username'] : '用户 #' . (int)$rp['user_id'])); ?></span>
                    <span><?php echo e(date('Y-m-d H:i', (int)$rp['created_at'])); ?></span>
                    <form method="post" style="display:inline" onsubmit="return confirm('确定删除该回复？')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="reply_del">
                        <input type="hidden" name="post_id" value="<?php echo (int)$bug['id']; ?>">
                        <input type="hidden" name="reply_id" value="<?php echo (int)$rp['id']; ?>">
                        <button type="submit" style="background:none;border:none;color:var(--err,#F53F3F);cursor:pointer;font-size:12px;padding:0">删除</button>
                    </form>
                </div>
                <div class="chat-bubble<?php echo $isAdmin ? ' chat-bubble-admin' : ''; ?>"><?php echo e($rp['content']); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" style="margin-top:16px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="post_id" value="<?php echo (int)$bug['id']; ?>">
        <textarea class="form-input" name="content" rows="4" required maxlength="2000" placeholder="以管理员身份回复（2 ~ 2000 字）"></textarea>
        <div style="margin-top:10px"><button class="btn-primary" type="submit">回复</button></div>
    </form>
</section>
<?php admin_footer();
    exit;
}

// ---------- 列表页 ----------
$statusF   = (string)($_GET['status'] ?? '');
$resolvedF = (string)($_GET['resolved'] ?? '');
$kw        = trim((string)($_GET['kw'] ?? ''));

$where  = 'WHERE 1=1';
$params = [];
if ($statusF === '1' || $statusF === '2') {
    $where .= ' AND `status`=?';
    $params[] = (int)$statusF;
}
if ($resolvedF === '0' || $resolvedF === '1') {
    $where .= ' AND `resolved`=?';
    $params[] = (int)$resolvedF;
}
if ($kw !== '') {
    $where .= ' AND (`title` LIKE ? OR `content` LIKE ?)';
    $params[] = '%' . $kw . '%';
    $params[] = '%' . $kw . '%';
}

$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$stmt = db()->prepare('SELECT COUNT(*) FROM `bug_post`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$params[] = $perPage;
$params[] = ($page - 1) * $perPage;
$stmt = db()->prepare('SELECT * FROM `bug_post`' . $where . ' ORDER BY `updated_at` DESC LIMIT ? OFFSET ?');
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stats = bug_public_stats();
try {
    $hiddenCnt = (int)db()->query('SELECT COUNT(*) FROM `bug_post` WHERE `status`=2')->fetchColumn();
} catch (Throwable $ex) {
    $hiddenCnt = 0;
}

admin_header('Bug 广场管理', 'bug', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<div class="stat-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px">
    <div class="stat-card" style="position:relative;overflow:hidden;background:linear-gradient(180deg,#FFF,#FBFCFE);border:1px solid #EDEFF4;border-radius:12px;padding:18px 20px">
        <div class="stat-label" style="font-size:13px;color:var(--t3)">已发布分享</div>
        <div class="stat-num" style="font-size:26px;font-weight:700;color:var(--t1);margin-top:6px;font-family:Consolas,Monaco,monospace"><?php echo $stats['total']; ?></div>
    </div>
    <div class="stat-card" style="position:relative;overflow:hidden;background:linear-gradient(180deg,#FFF,#FBFCFE);border:1px solid #EDEFF4;border-radius:12px;padding:18px 20px">
        <div class="stat-label" style="font-size:13px;color:var(--t3)">待解决</div>
        <div class="stat-num" style="font-size:26px;font-weight:700;color:#FF7D00;margin-top:6px;font-family:Consolas,Monaco,monospace"><?php echo $stats['open']; ?></div>
    </div>
    <div class="stat-card" style="position:relative;overflow:hidden;background:linear-gradient(180deg,#FFF,#FBFCFE);border:1px solid #EDEFF4;border-radius:12px;padding:18px 20px">
        <div class="stat-label" style="font-size:13px;color:var(--t3)">已解决</div>
        <div class="stat-num" style="font-size:26px;font-weight:700;color:#00B42A;margin-top:6px;font-family:Consolas,Monaco,monospace"><?php echo $stats['fixed']; ?></div>
    </div>
    <div class="stat-card" style="position:relative;overflow:hidden;background:linear-gradient(180deg,#FFF,#FBFCFE);border:1px solid #EDEFF4;border-radius:12px;padding:18px 20px">
        <div class="stat-label" style="font-size:13px;color:var(--t3)">已下架</div>
        <div class="stat-num" style="font-size:26px;font-weight:700;color:var(--t3);margin-top:6px;font-family:Consolas,Monaco,monospace"><?php echo $hiddenCnt; ?></div>
    </div>
</div>

<section class="card">
    <h2 class="card-title">Bug 广场管理</h2>
    <p class="card-desc">用户分享的 Bug 汇总于此，可下架违规内容、回复处理进展、标记解决状态。</p>
    <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
        <select class="form-input" name="status" style="max-width:150px">
            <option value="">全部状态</option>
            <option value="1" <?php echo $statusF === '1' ? 'selected' : ''; ?>>已发布</option>
            <option value="2" <?php echo $statusF === '2' ? 'selected' : ''; ?>>已下架</option>
        </select>
        <select class="form-input" name="resolved" style="max-width:150px">
            <option value="">解决状态不限</option>
            <option value="0" <?php echo $resolvedF === '0' ? 'selected' : ''; ?>>未解决</option>
            <option value="1" <?php echo $resolvedF === '1' ? 'selected' : ''; ?>>已解决</option>
        </select>
        <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="搜索标题 / 内容" style="max-width:220px">
        <button class="btn-primary" type="submit">筛选</button>
    </form>

    <?php if (!$rows): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据。</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>标题</th><th style="width:120px">状态</th><th style="width:90px">数据</th><th style="width:150px">更新时间</th><th style="width:70px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="cell-main"><?php echo e($row['title']); ?></td>
                <td>
                    <span class="tag <?php echo bug_status_tag((int)$row['status']); ?>"><?php echo e(bug_status_name((int)$row['status'])); ?></span>
                    <span class="tag <?php echo bug_resolved_tag((int)$row['resolved']); ?>"><?php echo e(bug_resolved_name((int)$row['resolved'])); ?></span>
                </td>
                <td class="cell-code">👍<?php echo (int)$row['likes']; ?> 💬<?php echo (int)$row['reply_count']; ?> 👁<?php echo (int)$row['views']; ?></td>
                <td class="cell-code"><?php echo e(date('Y-m-d H:i', (int)$row['updated_at'])); ?></td>
                <td><a class="btn-plain" href="bug_manage.php?id=<?php echo (int)$row['id']; ?>">管理</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, 'bug_manage.php?status=' . urlencode($statusF) . '&resolved=' . urlencode($resolvedF) . '&kw=' . urlencode($kw) . '&'); ?>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
