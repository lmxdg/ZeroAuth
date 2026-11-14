<?php
// 用户中心 - Bug 广场（分享 / 浏览 / 回复 / 点赞 / 标记解决）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Bug.php';
require dirname(__DIR__) . '/core/layout_user.php';

$user = user_require_login();

if (is_post()) {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    $backId = (int)($_POST['post_id'] ?? 0);

    if ($act === 'share') {
        $res = bug_create((int)$user['id'], (string)($_POST['title'] ?? ''), (string)($_POST['content'] ?? ''),
            (int)($_POST['app_id'] ?? 0), (string)($_POST['contact'] ?? ''));
        if (isset($res['error'])) {
            flash_set('err', $res['error']);
            redirect('bugs.php');
        }
        flash_set('ok', 'Bug 已分享到广场，感谢你的反馈！');
        redirect('bugs.php?id=' . $res['id']);
    }

    if ($act === 'reply') {
        $res = bug_reply_add($backId, 1, (int)$user['id'], (string)$user['username'], (string)($_POST['content'] ?? ''));
        flash_set(isset($res['error']) ? 'err' : 'ok', $res['error'] ?? '回复成功。');
        redirect('bugs.php?id=' . $backId . '#bug-reply-list');
    }

    if ($act === 'like') {
        if (empty($_SESSION['bug_likes'])) {
            $_SESSION['bug_likes'] = [];
        }
        if (!isset($_SESSION['bug_likes'][$backId])) {
            bug_like_add($backId);
            $_SESSION['bug_likes'][$backId] = 1;
            flash_set('ok', '已点赞。');
        } else {
            flash_set('err', '你已经点过赞了。');
        }
        redirect('bugs.php?id=' . $backId);
    }

    if ($act === 'resolve' || $act === 'unresolve') {
        $row = db()->prepare('SELECT `id`,`user_id` FROM `bug_post` WHERE `id`=? LIMIT 1');
        $row->execute([$backId]);
        $post = $row->fetch();
        if (!$post || (int)$post['user_id'] !== (int)$user['id']) {
            flash_set('err', '只有分享者本人可以修改解决状态。');
        } else {
            $val = bug_resolve_toggle($backId, $act === 'resolve');
            flash_set('ok', $val === 1 ? '已标记为已解决。' : '已重新标记为未解决。');
        }
        redirect('bugs.php?id=' . $backId);
    }

    if ($act === 'delete') {
        $row = db()->prepare('SELECT `id`,`user_id` FROM `bug_post` WHERE `id`=? LIMIT 1');
        $row->execute([$backId]);
        $post = $row->fetch();
        if (!$post || (int)$post['user_id'] !== (int)$user['id']) {
            flash_set('err', '只有分享者本人可以删除。');
            redirect('bugs.php?id=' . $backId);
        }
        bug_delete($backId);
        flash_set('ok', '分享已删除。');
        redirect('bugs.php');
    }
}

// ---------- 详情页 ----------
$detailId = (int)($_GET['id'] ?? 0);
if ($detailId > 0) {
    $stmt = db()->prepare('SELECT * FROM `bug_post` WHERE `id`=? LIMIT 1');
    $stmt->execute([$detailId]);
    $bug = $stmt->fetch() ?: null;
    if (!$bug || ((int)$bug['status'] !== 1 && (int)$bug['user_id'] !== (int)$user['id'])) {
        user_header('Bug 广场', 'bugs', $user);
        echo '<section class="card"><div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>该分享不存在或已下架。</span></div><div style="margin-top:12px"><a class="btn-plain" href="bugs.php">返回广场</a></div></section>';
        user_footer();
        exit;
    }

    bug_views_inc((int)$bug['id']);
    $replies = bug_replies((int)$bug['id']);
    $isMine  = (int)$bug['user_id'] === (int)$user['id'];
    $liked   = isset($_SESSION['bug_likes'][(int)$bug['id']]);

    $appName = '-';
    if ((int)$bug['app_id'] > 0) {
        $stmt = db()->prepare('SELECT `name` FROM `app` WHERE `id`=? LIMIT 1');
        $stmt->execute([(int)$bug['app_id']]);
        $appName = (string)$stmt->fetchColumn();
        $appName = $appName !== '' ? $appName : '-';
    }

    // 分享者昵称：管理员代发显示「管理员」，否则取用户名（不存在时显示匿名）
    $authorName = '匿名用户';
    if ((int)$bug['user_id'] === 0) {
        $authorName = '管理员';
    } elseif ((int)$bug['user_id'] === (int)$user['id']) {
        $authorName = (string)$user['username'] . '（我）';
    } else {
        try {
            $stmt = db()->prepare('SELECT `username` FROM `user` WHERE `id`=? LIMIT 1');
            $stmt->execute([(int)$bug['user_id']]);
            $un = (string)$stmt->fetchColumn();
            if ($un !== '') {
                $authorName = $un;
            }
        } catch (Throwable $ex) {
        }
    }

    user_header('Bug 详情', 'bugs', $user);
    $flash = flash_take();
    if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
    <?php endif; ?>

<style>

.bug-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--t3);margin-top:4px}
.bug-content{margin-top:14px;font-size:13px;line-height:23px;color:var(--t1);white-space:pre-wrap;word-break:break-word;background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:14px 16px}
.bug-actions{display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap}
.btn-mini{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 14px;border:1px solid var(--bd);border-radius:var(--r);font-size:13px;color:var(--t2);cursor:pointer;transition:all .15s;background:var(--card)}
.btn-mini:hover{color:var(--p);border-color:var(--p)}
.btn-mini.on{color:var(--p);border-color:var(--p);background:var(--p-bg)}
.chat-thread{display:flex;flex-direction:column;gap:16px;margin-top:16px}
.chat-row{display:flex;gap:10px;align-items:flex-start}
.chat-avatar{width:32px;height:32px;border-radius:50%;flex:none;background:#F2F3F5}
.chat-col{max-width:80%;display:flex;flex-direction:column;gap:4px;min-width:0}
.chat-meta{font-size:12px;color:var(--t3);display:flex;gap:8px;align-items:center}
.chat-name-admin{color:var(--p);font-weight:500}
.chat-bubble{background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:10px 12px;font-size:13px;color:var(--t1);line-height:20px;word-break:break-word;white-space:pre-wrap}
.chat-bubble-admin{background:var(--p-bg);border-color:#BEDAFF}
</style>

<section class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div style="min-width:0">
            <h2 class="card-title" style="margin-bottom:4px"><?php echo e($bug['title']); ?></h2>
            <div class="bug-meta">
                <span class="tag <?php echo bug_resolved_tag((int)$bug['resolved']); ?>"><?php echo e(bug_resolved_name((int)$bug['resolved'])); ?></span>
                <?php if ((int)$bug['status'] === 2): ?><span class="tag tag-gray">已下架</span><?php endif; ?>
                <span>关联应用：<?php echo e($appName); ?></span>
                <span>分享者：<?php echo e($authorName); ?></span>
                <span><?php echo date('Y-m-d H:i', (int)$bug['created_at']); ?></span>
                <span>👁 <?php echo (int)$bug['views']; ?></span>
            </div>
        </div>
        <a class="btn-plain" href="bugs.php">返回广场</a>
    </div>

    <div class="bug-content"><?php echo e($bug['content']); ?></div>

    <?php if ((string)$bug['contact'] !== ''): ?>
    <div class="bug-meta" style="margin-top:10px">联系方式：<b style="color:var(--t2)"><?php echo e($bug['contact']); ?></b></div>
    <?php endif; ?>

    <div class="bug-actions">
        <form method="post" style="display:inline">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="like">
            <input type="hidden" name="post_id" value="<?php echo (int)$bug['id']; ?>">
            <button class="btn-mini<?php echo $liked ? ' on' : ''; ?>" type="submit">👍 有帮助 <?php echo (int)$bug['likes']; ?></button>
        </form>
        <span class="btn-mini" style="cursor:default">💬 回复 <?php echo (int)$bug['reply_count']; ?></span>
        <?php if ($isMine): ?>
        <form method="post" style="display:inline">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="<?php echo (int)$bug['resolved'] === 1 ? 'unresolve' : 'resolve'; ?>">
            <input type="hidden" name="post_id" value="<?php echo (int)$bug['id']; ?>">
            <button class="btn-mini" type="submit"><?php echo (int)$bug['resolved'] === 1 ? '标记为未解决' : '标记为已解决'; ?></button>
        </form>
        <form method="post" data-confirm="确定删除该分享？删除后无法恢复。" style="display:inline">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="delete">
            <input type="hidden" name="post_id" value="<?php echo (int)$bug['id']; ?>">
            <button class="btn-mini" type="submit">删除</button>
        </form>
        <?php endif; ?>
    </div>
</section>

<section class="card" id="bug-reply-list" style="margin-top:16px">
    <h2 class="card-title">回复（<?php echo count($replies); ?>）</h2>
    <?php if (!$replies): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>还没有回复，来帮 TA 分析一下？</span>
    </div>
    <?php else: ?>
    <div class="chat-thread">
        <?php foreach ($replies as $rp):
            $isAdmin = (int)$rp['user_type'] === 2;
            $isSelf = !$isAdmin && (int)$rp['user_id'] === (int)$user['id'];
            $name = $isAdmin ? '管理员' : ((string)$rp['username'] !== '' ? (string)$rp['username'] : '用户');
            if ($isSelf) {
                $avatar = avatar_img($user, 'chat-avatar', $name);
            } else {
                $avatar = '<img class="chat-avatar" src="../assets/svg/mrtx.svg" alt="">';
            }
        ?>
        <div class="chat-row<?php echo $isAdmin ? '' : ''; ?>">
            <?php echo $avatar; ?>
            <div class="chat-col">
                <div class="chat-meta">
                    <span<?php echo $isAdmin ? ' class="chat-name-admin"' : ''; ?>><?php echo e($name !== '' ? $name : '用户'); ?></span>
                    <span><?php echo date('Y-m-d H:i', (int)$rp['created_at']); ?></span>
                </div>
                <div class="chat-bubble<?php echo $isAdmin ? ' chat-bubble-admin' : ''; ?>"><?php echo e($rp['content']); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" style="margin-top:18px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="reply">
        <input type="hidden" name="post_id" value="<?php echo (int)$bug['id']; ?>">
        <textarea class="form-input" name="content" rows="4" required maxlength="2000"
                  placeholder="补充线索 / 解决思路 / 相似遭遇…（2 ~ 2000 字）"></textarea>
        <div style="margin-top:10px"><button class="btn-p" type="submit">发表回复</button></div>
    </form>
</section>
<?php user_footer();
    exit;
}

// ---------- 列表页 ----------
$resolved = (string)($_GET['resolved'] ?? '');
$sort     = (string)($_GET['sort'] ?? 'new');

$where = 'WHERE `status`=1';
$params = [];
if ($resolved === '0' || $resolved === '1') {
    $where .= ' AND `resolved`=?';
    $params[] = (int)$resolved;
}
$order = $sort === 'hot' ? 'ORDER BY (`likes`*3 + `reply_count`*2 + `views`) DESC, `created_at` DESC' : 'ORDER BY `created_at` DESC';

$perPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$stmt = db()->prepare('SELECT COUNT(*) FROM `bug_post`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$params[] = $perPage;
$params[] = ($page - 1) * $perPage;
$stmt = db()->prepare('SELECT * FROM `bug_post`' . $where . ' ' . $order . ' LIMIT ? OFFSET ?');
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stats = bug_public_stats();

// 我分享的帖子（作者可看到自己被下架的内容）
$mine = [];
try {
    $stmt = db()->prepare('SELECT * FROM `bug_post` WHERE `user_id`=? ORDER BY `created_at` DESC LIMIT 8');
    $stmt->execute([(int)$user['id']]);
    $mine = $stmt->fetchAll();
} catch (Throwable $ex) {
    $mine = [];
}

user_header('Bug 广场', 'bugs', $user);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<style>

.bug-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px}
.bug-stat{background:linear-gradient(180deg,var(--card),var(--bg));border:1px solid var(--bd);border-radius:12px;padding:16px 20px}
.bug-stat b{display:block;font-size:24px;font-family:Consolas,Monaco,monospace;color:var(--t1)}
.bug-stat b.blue{color:var(--p)}
.bug-stat b.orange{color:var(--warn)}
.bug-stat b.green{color:var(--ok)}
.bug-stat i{display:block;font-style:normal;font-size:12px;color:var(--t3);margin-top:4px}
.bug-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
.bug-card{display:flex;flex-direction:column;gap:8px;background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:16px 18px;transition:border-color .18s,box-shadow .18s,transform .18s;color:inherit}
.bug-card:hover{border-color:rgba(0,110,255,.45);box-shadow:0 10px 26px rgba(0,110,255,.10);transform:translateY(-2px)}
.bug-card h3{font-size:14px;font-weight:600;color:var(--t1);line-height:21px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.bug-card p{font-size:12px;color:var(--t3);line-height:19px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0}
.bug-card-foot{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--t3);flex-wrap:wrap}
.bug-card-foot .num{font-family:Consolas,Monaco,monospace}
.filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
@media (max-width:900px){.bug-grid{grid-template-columns:1fr}.bug-stats{grid-template-columns:repeat(3,1fr)}}
@media (max-width:560px){.bug-stats{grid-template-columns:1fr}}
</style>

<div class="bug-stats">
    <div class="bug-stat"><b class="blue"><?php echo $stats['total']; ?></b><i>累计分享</i></div>
    <div class="bug-stat"><b class="orange"><?php echo $stats['open']; ?></b><i>待解决</i></div>
    <div class="bug-stat"><b class="green"><?php echo $stats['fixed']; ?></b><i>已解决</i></div>
</div>

<section class="card">
    <h2 class="card-title">分享 Bug</h2>
    <p class="card-desc">遇到 Bug？分享到广场，让其他用户帮你定位，也会被开发者看到。请描述<b>复现步骤、期望结果、实际结果</b>，更易获得帮助。</p>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="share">
        <div class="form-item">
            <label class="form-label">问题标题</label>
            <input class="form-input" type="text" name="title" maxlength="100" required placeholder="一句话概括问题（5 ~ 100 字）">
        </div>
        <div class="form-item">
            <label class="form-label">关联应用（选填）</label>
            <select class="form-input" name="app_id" style="max-width:320px">
                <option value="0">不限 / 通用</option>
                <?php foreach (db()->query('SELECT `id`,`name` FROM `app` ORDER BY `id` DESC LIMIT 100')->fetchAll() as $ap): ?>
                <option value="<?php echo (int)$ap['id']; ?>"><?php echo e($ap['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-item">
            <label class="form-label">问题描述</label>
            <textarea class="form-input" name="content" rows="6" required maxlength="5000"
                      placeholder="复现步骤：&#10;期望结果：&#10;实际结果：&#10;环境信息（系统 / 版本 / 浏览器等）："></textarea>
        </div>
        <div class="form-item">
            <label class="form-label">联系方式（选填）</label>
            <input class="form-input" type="text" name="contact" maxlength="120" placeholder="QQ / 邮箱 / 微信，方便他人与你联系（公开可见，请酌情填写）">
        </div>
        <button class="btn-p" type="submit">分享到广场</button>
    </form>
</section>

<?php if ($mine): ?>
<section class="card" style="margin-top:16px">
    <h2 class="card-title">我分享的</h2>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>标题</th><th style="width:86px">状态</th><th style="width:90px">回复 / 点赞</th><th style="width:150px">时间</th><th style="width:70px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($mine as $m): ?>
            <tr>
                <td class="cell-main"><?php echo e($m['title']); ?><?php if ((int)$m['status'] === 2): ?> <span class="tag tag-gray">已下架</span><?php endif; ?></td>
                <td><span class="tag <?php echo bug_resolved_tag((int)$m['resolved']); ?>"><?php echo e(bug_resolved_name((int)$m['resolved'])); ?></span></td>
                <td class="cell-code"><?php echo (int)$m['reply_count']; ?> / <?php echo (int)$m['likes']; ?></td>
                <td class="cell-code"><?php echo e(date('Y-m-d H:i', (int)$m['created_at'])); ?></td>
                <td><a class="btn-plain" href="bugs.php?id=<?php echo (int)$m['id']; ?>">查看</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="card" style="margin-top:16px">
    <h2 class="card-title">广场</h2>
    <form method="get" class="filter-bar">
        <select class="form-input" name="resolved" style="max-width:150px">
            <option value="">全部状态</option>
            <option value="0" <?php echo $resolved === '0' ? 'selected' : ''; ?>>未解决</option>
            <option value="1" <?php echo $resolved === '1' ? 'selected' : ''; ?>>已解决</option>
        </select>
        <select class="form-input" name="sort" style="max-width:150px">
            <option value="new" <?php echo $sort !== 'hot' ? 'selected' : ''; ?>>最新发布</option>
            <option value="hot" <?php echo $sort === 'hot' ? 'selected' : ''; ?>>最热讨论</option>
        </select>
        <button class="btn-plain" type="submit">筛选</button>
    </form>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无分享，来发布第一条 Bug 吧。</span>
    </div>
    <?php else: ?>
    <div class="bug-grid">
        <?php foreach ($rows as $row): ?>
        <a class="bug-card" href="bugs.php?id=<?php echo (int)$row['id']; ?>">
            <h3><?php echo e($row['title']); ?></h3>
            <p><?php echo e(mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$row['content'])), 0, 90)); ?></p>
            <div class="bug-card-foot">
                <span class="tag <?php echo bug_resolved_tag((int)$row['resolved']); ?>"><?php echo e(bug_resolved_name((int)$row['resolved'])); ?></span>
                <span class="num">👍 <?php echo (int)$row['likes']; ?></span>
                <span class="num">💬 <?php echo (int)$row['reply_count']; ?></span>
                <span class="num">👁 <?php echo (int)$row['views']; ?></span>
                <span style="margin-left:auto"><?php echo e(date('Y-m-d H:i', (int)$row['created_at'])); ?></span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php render_pagination($total, $page, $perPage, 'bugs.php?resolved=' . urlencode($resolved) . '&sort=' . urlencode($sort) . '&'); ?>
    <?php endif; ?>
</section>
<?php user_footer(); ?>
