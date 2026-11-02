<?php
// 用户中心 - 我的工单
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Ticket.php';
require dirname(__DIR__) . '/core/layout_user.php';

$user = user_require_login();

if (is_post()) {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'create') {
        $res = ticket_create(1, (int)$user['id'], (string)($_POST['title'] ?? ''), (string)($_POST['content'] ?? ''), (int)($_POST['category'] ?? 0));
        if (isset($res['error'])) {
            flash_set('err', $res['error']);
        } else {
            $attErrors = ticket_attachment_save_all((int)$res['id'], (int)$res['reply_id'], 1, (int)$user['id']);
            flash_set('ok', $attErrors === []
                ? '工单已提交，管理员会尽快处理。'
                : '工单已提交，但部分附件上传失败：' . implode(' ', $attErrors));
            redirect('tickets.php?id=' . $res['id']);
        }
    } elseif ($act === 'reply') {
        $res = ticket_reply_mine(1, (int)$user['id'], (int)($_POST['ticket_id'] ?? 0), (string)($_POST['content'] ?? ''));
        if (isset($res['error'])) {
            flash_set('err', $res['error']);
        } else {
            $attErrors = ticket_attachment_save_all((int)($_POST['ticket_id'] ?? 0), (int)$res['reply_id'], 1, (int)$user['id']);
            flash_set('ok', $attErrors === [] ? '回复成功。' : '回复成功，但部分附件上传失败：' . implode(' ', $attErrors));
        }
        redirect('tickets.php?id=' . (int)($_POST['ticket_id'] ?? 0));
    } elseif ($act === 'close') {
        $res = ticket_close_mine(1, (int)$user['id'], (int)($_POST['ticket_id'] ?? 0));
        flash_set(isset($res['error']) ? 'err' : 'ok', $res['error'] ?? '工单已关闭。');
        redirect('tickets.php?id=' . (int)($_POST['ticket_id'] ?? 0));
    } elseif ($act === 'rate') {
        $res = ticket_rate(1, (int)$user['id'], (int)($_POST['ticket_id'] ?? 0), (int)($_POST['stars'] ?? 0), (string)($_POST['note'] ?? ''));
        flash_set(isset($res['error']) ? 'err' : 'ok', $res['error'] ?? '感谢您的评价！');
        redirect('tickets.php?id=' . (int)($_POST['ticket_id'] ?? 0));
    }
}

$detailId = (int)($_GET['id'] ?? 0);
$ticket = $detailId ? ticket_find_mine(1, (int)$user['id'], $detailId) : null;
if ($ticket) {
    $replies = ticket_replies((int)$ticket['id']);
    $attGroups = ticket_attachments((int)$ticket['id']);
    user_header('工单详情', 'tickets', $user);
    $flash = flash_take();
    if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
    <?php endif; ?>

<style>

.chat-thread{display:flex;flex-direction:column;gap:16px;margin-top:16px;max-height:480px;overflow-y:auto;padding-right:6px}
.chat-row{display:flex;gap:10px;align-items:flex-start}
.chat-row-self{flex-direction:row-reverse}
.chat-avatar{width:34px;height:34px;border-radius:50%;flex:none;background:#F2F3F5}
.chat-col{max-width:72%;display:flex;flex-direction:column;gap:4px;min-width:0}
.chat-row-self .chat-col{align-items:flex-end}
.chat-meta{font-size:12px;color:var(--t3);display:flex;gap:8px}
.chat-name-admin{color:var(--p);font-weight:500}
.chat-bubble{background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:10px 12px;font-size:13px;color:var(--t1);line-height:20px;word-break:break-word;white-space:pre-wrap}
.chat-bubble-admin{background:var(--p-bg);border-color:#BEDAFF}
.stars-static .star-pick{cursor:default;font-size:16px}
.star-picker{display:flex;align-items:center;gap:4px}
.star-pick{font-size:24px;line-height:1;color:#C9CDD4;cursor:pointer;transition:color .15s;user-select:none}
.star-pick.on{color:#FF7D00}
.star-hint{font-size:12px;color:var(--t3);margin-left:8px}
.chat-atts{display:flex;flex-direction:column;gap:6px;margin-top:8px}
.chat-att{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:6px 10px;border:1px solid var(--bd);border-radius:var(--r);background:var(--card);font-size:12px;color:var(--t2);text-decoration:none;transition:border-color .15s,color .15s}
.chat-att:hover{border-color:var(--p);color:var(--p)}
.chat-att-name{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;word-break:break-all}
.chat-att-size{color:var(--t3);flex:none}
.upl-hint{font-size:12px;color:var(--t3);margin-top:6px;line-height:18px}
</style>

<section class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div>
            <h2 class="card-title" style="margin-bottom:4px"><?php echo e($ticket['title']); ?></h2>
            <p class="card-desc">工单 <?php echo e(ticket_no_display($ticket)); ?> · 提交于 <?php echo date('Y-m-d H:i', (int)$ticket['created_at']); ?></p>
        </div>
        <span class="tag <?php echo ticket_status_tag((int)$ticket['status']); ?>"><?php echo e(ticket_status_name((int)$ticket['status'])); ?></span>
    </div>

    <div class="chat-thread">
        <?php foreach ($replies as $rp):
            $isAdmin = (int)$rp['sender_type'] === 2;
            $name = $isAdmin ? '管理员' : (string)$user['username'];
            $avatar = $isAdmin ? avatar_img([], 'chat-avatar', '管理员') : avatar_img($user, 'chat-avatar', $name);
            $time = date('Y-m-d H:i', (int)$rp['created_at']);
            $nameHtml = '<span' . ($isAdmin ? ' class="chat-name-admin"' : '') . '>' . e($name) . '</span>';
            $meta = $isAdmin
                ? $nameHtml . '<span>' . $time . '</span>'
                : '<span>' . $time . '</span>' . $nameHtml;
        ?>
        <div class="chat-row<?php echo $isAdmin ? '' : ' chat-row-self'; ?>">
            <?php echo $avatar; ?>
            <div class="chat-col">
                <div class="chat-meta"><?php echo $meta; ?></div>
                <div class="chat-bubble<?php echo $isAdmin ? ' chat-bubble-admin' : ''; ?>"><?php echo e($rp['content']); ?></div>
                <?php echo ticket_attachment_html($attGroups[(int)$rp['id']] ?? []); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ((int)$ticket['status'] !== 3): ?>
    <form method="post" enctype="multipart/form-data" style="margin-top:16px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="reply">
        <input type="hidden" name="ticket_id" value="<?php echo (int)$ticket['id']; ?>">
        <textarea class="form-input" name="content" rows="4" required placeholder="补充问题说明或回复管理员…" style="flex:1;min-width:0"></textarea>
        <div style="display:flex;flex-direction:column;gap:6px;width:220px">
            <input class="form-input" type="file" name="files[]" multiple
                   accept="<?php echo e('.' . implode(',.', ticket_attachment_exts())); ?>">
            <span class="upl-hint">可附截图 / 日志 / 压缩包等，单文件 ≤10MB，最多 5 个</span>
        </div>
        <button class="btn-p" type="submit">回复</button>
    </form>
    <?php else: ?>
    <div style="margin-top:16px;display:flex;gap:8px">
        <a class="btn-plain" href="tickets.php">返回列表</a>
    </div>
    <?php endif; ?>
</section>
<?php if ((int)$ticket['status'] !== 3): ?>
<section class="card" style="margin-top:16px">
    <h2 class="card-title">问题已解决？</h2>
    <p class="card-desc">关闭后如仍有问题可重新提交工单。</p>
    <div style="display:flex;gap:8px;align-items:center">
        <form method="post" data-confirm="确定关闭该工单？关闭后双方均无法继续回复。">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="close">
            <input type="hidden" name="ticket_id" value="<?php echo (int)$ticket['id']; ?>">
            <button class="btn-plain" type="submit">关闭工单</button>
        </form>
        <a class="btn-plain" href="tickets.php">返回列表</a>
    </div>
</section>
<?php else: ?>
<section class="card" style="margin-top:16px">
    <?php if ((int)$ticket['rating'] > 0): ?>
    <h2 class="card-title">服务评分</h2>
    <p class="card-desc">您对本单服务的评价<?php echo (int)$ticket['rated_at'] > 0 ? '（' . date('Y-m-d H:i', (int)$ticket['rated_at']) . '）' : ''; ?></p>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <?php echo ticket_rating_html((int)$ticket['rating']); ?>
        <?php if ((string)$ticket['rating_note'] !== ''): ?><span style="font-size:13px;color:var(--t3)">「<?php echo e((string)$ticket['rating_note']); ?>」</span><?php endif; ?>
    </div>
    <?php else: ?>
    <h2 class="card-title">为本次服务评分</h2>
    <p class="card-desc">工单已关闭，欢迎评价管理员的服务态度与处理结果，提交后不可修改。</p>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="rate">
        <input type="hidden" name="ticket_id" value="<?php echo (int)$ticket['id']; ?>">
        <input type="hidden" name="stars" value="0" id="starsInput">
        <div class="star-picker" id="starPicker">
            <span class="star-pick" data-val="1">★</span><span class="star-pick" data-val="2">★</span><span class="star-pick" data-val="3">★</span><span class="star-pick" data-val="4">★</span><span class="star-pick" data-val="5">★</span>
            <span class="star-hint" id="starHint">点击星星评分</span>
        </div>
        <input class="form-input" name="note" maxlength="190" placeholder="写点评价（选填）" style="max-width:420px;margin-top:10px">
        <div style="margin-top:10px"><button class="btn-p" type="submit">提交评分</button></div>
    </form>
    <script>

    (function () {
        var picker = document.getElementById('starPicker');
        if (!picker) return;
        var stars = picker.querySelectorAll('.star-pick');
        var input = document.getElementById('starsInput');
        var hint = document.getElementById('starHint');
        function paint(val) {
            stars.forEach(function (s) { s.classList.toggle('on', parseInt(s.dataset.val, 10) <= val); });
        }
        stars.forEach(function (s) {
            s.addEventListener('click', function () {
                var val = parseInt(s.dataset.val, 10);
                input.value = val;
                paint(val);
                hint.textContent = val + ' 星';
            });
        });
    })();
    </script>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php user_footer();
    exit;
}

$status = (string)($_GET['status'] ?? '');
$statusMap = [1 => '待处理', 2 => '已回复', 3 => '已关闭'];
$categoryMap = ticket_categories();

$where = 'WHERE `user_type`=1 AND `user_id`=?';
$params = [(int)$user['id']];
if ($status !== '' && isset($statusMap[(int)$status])) {
    $where .= ' AND `status`=?';
    $params[] = (int)$status;
}
if (isset($_GET['cat']) && isset($categoryMap[(int)$_GET['cat']]) && (string)$_GET['cat'] !== '') {
    $where .= ' AND `category`=?';
    $params[] = (int)$_GET['cat'];
}

$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$stmt = db()->prepare('SELECT COUNT(*) FROM `ticket`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$params[] = $perPage;
$params[] = ($page - 1) * $perPage;
$stmt = db()->prepare('SELECT * FROM `ticket`' . $where . ' ORDER BY `updated_at` DESC LIMIT ? OFFSET ?');
$stmt->execute($params);
$rows = $stmt->fetchAll();

user_header('我的工单', 'tickets', $user);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<style>

.filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.cell-main{color:var(--t1)}
.cell-sub{color:var(--t3);font-size:12px}
.btn-mini{display:inline-flex;align-items:center;height:26px;padding:0 12px;border:1px solid var(--bd);border-radius:var(--r);font-size:12px;color:var(--t2);cursor:pointer;transition:all .15s;background:var(--card)}
.btn-mini:hover{color:var(--p);border-color:var(--p)}
</style>

<section class="card">
    <h2 class="card-title">提交工单</h2>
    <p class="card-desc">使用中遇到问题或需要帮助，请提交工单，管理员会尽快处理。可上传截图、日志或压缩包等附件帮助定位问题。</p>
    <form method="post" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="create">
        <div class="form-item">
            <label class="form-label">问题分类</label>
            <select class="form-input" name="category" style="max-width:220px" required>
                <option value="" selected>请选择问题分类</option>
                <?php foreach ($categoryMap as $k => $name): ?>
                <option value="<?php echo $k; ?>"><?php echo e($name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-item">
            <label class="form-label">标题</label>
            <input class="form-input" type="text" name="title" maxlength="100" required placeholder="简要描述问题（100 字内）">
        </div>
        <div class="form-item">
            <label class="form-label">问题描述</label>
            <textarea class="form-input" name="content" rows="4" required placeholder="请详细描述遇到的问题、涉及的应用或卡密、复现步骤等"></textarea>
        </div>
        <div class="form-item">
            <label class="form-label">附件（选填）</label>
            <input class="form-input" type="file" name="files[]" multiple style="max-width:420px"
                   accept="<?php echo e('.' . implode(',.', ticket_attachment_exts())); ?>">
            <div class="upl-hint">支持截图 / 日志 / 压缩包 / 文档等，单文件 ≤10MB，最多 5 个</div>
        </div>
        <button class="btn-p" type="submit">提交工单</button>
    </form>
</section>

<section class="card" style="margin-top:16px">
    <h2 class="card-title">我的工单</h2>
    <form method="get" class="filter-bar">
        <select class="form-input" name="status" style="max-width:150px">
            <option value="">全部状态</option>
            <?php foreach ($statusMap as $k => $name): ?>
            <option value="<?php echo $k; ?>" <?php echo $status === (string)$k ? 'selected' : ''; ?>><?php echo e($name); ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn-plain" type="submit">筛选</button>
    </form>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无工单记录。</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>工单</th><th style="width:90px">分类</th><th>状态</th><th>最后更新</th><th style="width:70px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="cell-main"><?php echo e(ticket_no_display($row)); ?> · <?php echo e($row['title']); ?></td>
                <td><span class="tag <?php echo ticket_category_tag((int)$row['category']); ?>"><?php echo e(ticket_category_name((int)$row['category'])); ?></span></td>
                <td><span class="tag <?php echo ticket_status_tag((int)$row['status']); ?>"><?php echo e(ticket_status_name((int)$row['status'])); ?></span><?php if ((int)$row['status'] === 3 && (int)($row['rating'] ?? 0) === 0): ?> <span class="tag tag-warn">待评分</span><?php elseif ((int)$row['status'] === 3 && (int)$row['rating'] > 0): ?> <span class="tag tag-ok">★ <?php echo (int)$row['rating']; ?></span><?php endif; ?></td>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i', (int)$row['updated_at']); ?></td>
                <td><a class="btn-mini" href="tickets.php?id=<?php echo (int)$row['id']; ?>">查看</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, 'tickets.php?cat=' . urlencode((string)($_GET['cat'] ?? '')) . '&status=' . urlencode($status) . '&'); ?>
    <?php endif; ?>
</section>
<?php user_footer(); ?>
