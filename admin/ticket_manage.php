<?php
// 管理员后台 - 工单管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Ticket.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$statusMap = [
    1 => ['待处理', 'tag-warn'],
    2 => ['已回复', 'tag-ok'],
    3 => ['已关闭', 'tag-off'],
];

function ticket_submitter_join(): string
{
    return ' LEFT JOIN `user` `u` ON `t`.`user_type`=1 AND `t`.`user_id`=`u`.`id` LEFT JOIN `agent` `a` ON `t`.`user_type`=2 AND `t`.`user_id`=`a`.`id`';
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    // ===== 工单分类管理（后台可改） =====
    if ($action === 'cat_add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $cats = ticket_categories();
        if ($name === '' || mb_strlen($name) > 20) {
            flash_set('err', '请输入 1~20 字的分类名称。');
        } elseif (count($cats) >= 20) {
            flash_set('err', '最多支持 20 个工单分类。');
        } elseif (in_array($name, $cats, true)) {
            flash_set('err', '已存在同名分类。');
        } else {
            $nextId = (int)max(array_keys($cats)) + 1;
            if ($nextId > 255) {
                flash_set('err', '分类 ID 已达上限，无法继续添加。');
            } else {
                $cats[$nextId] = $name;
                ksort($cats);
                $rows = [];
                foreach ($cats as $id => $n) {
                    $rows[] = ['id' => $id, 'name' => $n];
                }
                if (ticket_category_save($rows)) {
                    audit('ticket', 'category_add', 1, (int)$admin['id'], '新增工单分类：' . $name . '（ID ' . $nextId . '）');
                    flash_set('ok', '分类「' . $name . '」已添加。');
                } else {
                    flash_set('err', '分类保存失败，请重试。');
                }
            }
        }
        redirect('ticket_manage.php#cats');
    }

    if ($action === 'cat_rename') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $cats = ticket_categories();
        if (!isset($cats[$id])) {
            flash_set('err', '分类不存在。');
        } elseif ($name === '' || mb_strlen($name) > 20) {
            flash_set('err', '请输入 1~20 字的分类名称。');
        } elseif ($name !== $cats[$id] && in_array($name, $cats, true)) {
            flash_set('err', '已存在同名分类。');
        } else {
            $cats[$id] = $name;
            ksort($cats);
            $rows = [];
            foreach ($cats as $cid => $n) {
                $rows[] = ['id' => $cid, 'name' => $n];
            }
            if (ticket_category_save($rows)) {
                audit('ticket', 'category_rename', 1, (int)$admin['id'], '修改工单分类：ID ' . $id . ' → ' . $name);
                flash_set('ok', '分类名称已更新。');
            } else {
                flash_set('err', '分类保存失败，请重试。');
            }
        }
        redirect('ticket_manage.php#cats');
    }

    if ($action === 'cat_del') {
        $id = (int)($_POST['id'] ?? 0);
        $cats = ticket_categories();
        if (!isset($cats[$id])) {
            flash_set('err', '分类不存在。');
        } elseif (count($cats) <= 1) {
            flash_set('err', '至少保留一个工单分类。');
        } elseif (ticket_category_used($id) > 0) {
            flash_set('err', '分类「' . $cats[$id] . '」下仍有 ' . ticket_category_used($id) . ' 个工单，无法删除；可先修改名称继续使用。');
        } else {
            $delName = (string)$cats[$id];
            unset($cats[$id]);
            $rows = [];
            foreach ($cats as $cid => $n) {
                $rows[] = ['id' => $cid, 'name' => $n];
            }
            if (ticket_category_save($rows)) {
                audit('ticket', 'category_del', 1, (int)$admin['id'], '删除工单分类：' . $delName . '（ID ' . $id . '）');
                flash_set('ok', '分类「' . $delName . '」已删除。');
            } else {
                flash_set('err', '分类保存失败，请重试。');
            }
        }
        redirect('ticket_manage.php#cats');
    }

    if ($action === 'reply') {
        $id = (int)($_POST['id'] ?? 0);
        $content = trim((string)($_POST['content'] ?? ''));
        $stmt = db()->prepare('SELECT `id`,`title`,`status` FROM `ticket` WHERE `id`=?');
        $stmt->execute([$id]);
        $tk = $stmt->fetch();
        if (!$tk) {
            flash_set('err', '工单不存在或已被删除。');
            redirect('ticket_manage.php');
        }
        if ((int)$tk['status'] === 3) {
            flash_set('err', '工单已关闭，无法回复。');
            redirect('ticket_manage.php?act=view&id=' . $id);
        }
        if ($content === '') {
            flash_set('err', '回复内容不能为空。');
            redirect('ticket_manage.php?act=view&id=' . $id);
        }
        db()->prepare('INSERT INTO `ticket_reply` (`ticket_id`,`sender_type`,`content`,`created_at`) VALUES (?,2,?,?)')
            ->execute([$id, $content, time()]);
        $replyId = (int)db()->lastInsertId();
        db()->prepare('UPDATE `ticket` SET `status`=2,`last_reply`=1,`updated_at`=? WHERE `id`=?')->execute([time(), $id]);
        $attErrors = ticket_attachment_save_all($id, $replyId, 2, (int)$admin['id']);
        audit('ticket', 'reply', 1, (int)$admin['id'], '回复工单 #' . $id . '：' . $tk['title'] . ($attErrors === [] ? '' : '（部分附件上传失败）'));
        flash_set('ok', $attErrors === [] ? '回复已提交。' : '回复已提交，但部分附件上传失败：' . implode(' ', $attErrors));
        redirect('ticket_manage.php?act=view&id=' . $id);
    }

    if ($action === 'qr_save') {
        $raw = (string)($_POST['list'] ?? '');
        $list = array_filter(array_map('trim', explode("\n", str_replace("\r", '', $raw))), 'strlen');
        ticket_quick_replies_save($list);
        audit('ticket', 'quick_reply', 1, (int)$admin['id'], '保存工单快捷回复模板（' . count($list) . ' 条）');
        flash_set('ok', '快捷回复模板已保存。');
        redirect('ticket_manage.php?act=view&id=' . (int)($_POST['back_id'] ?? 0));
    }

    if ($action === 'close') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `id`,`title`,`status` FROM `ticket` WHERE `id`=?');
        $stmt->execute([$id]);
        $tk = $stmt->fetch();
        if ($tk && (int)$tk['status'] !== 3) {
            db()->prepare('UPDATE `ticket` SET `status`=3,`updated_at`=? WHERE `id`=?')->execute([time(), $id]);
            audit('ticket', 'close', 1, (int)$admin['id'], '关闭工单 #' . $id . '：' . $tk['title']);
            flash_set('ok', '工单 #' . $id . ' 已关闭。');
        }
        redirect(isset($_POST['back']) && (string)$_POST['back'] === 'view' ? 'ticket_manage.php?act=view&id=' . $id : 'ticket_manage.php');
    }
}

$viewId = (int)($_GET['id'] ?? 0);
if ((string)($_GET['act'] ?? '') === 'view' && $viewId > 0) {
    $stmt = db()->prepare('SELECT `t`.*, COALESCE(`u`.`username`, `a`.`username`, "未知账号") AS `uname`, COALESCE(`u`.`email`, `a`.`email`, "") AS `aemail` FROM `ticket` `t`' . ticket_submitter_join() . ' WHERE `t`.`id`=?');
    $stmt->execute([$viewId]);
    $tk = $stmt->fetch();
    if (!$tk) {
        flash_set('err', '工单不存在或已被删除。');
        redirect('ticket_manage.php');
    }
    $stmt = db()->prepare('SELECT * FROM `ticket_reply` WHERE `ticket_id`=? ORDER BY `id` ASC');
    $stmt->execute([$viewId]);
    $replies = $stmt->fetchAll();
    $attGroups = ticket_attachments($viewId);

    admin_header('工单详情', 'ticket', $admin);
    $flash = flash_take();
    if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>
    <section class="card">
        <div class="page-actions">
            <h2 class="card-title" style="margin-bottom:0">工单详情 <?php echo e(ticket_no_display($tk)); ?></h2>
            <div class="spacer"></div>
            <a class="btn-plain" href="ticket_manage.php" style="line-height:30px">返回列表</a>
        </div>
        <div class="table-wrap" style="margin-bottom:16px">
            <table class="tb">
                <tbody>
                <tr><td style="width:110px;background:var(--bg-page);white-space:nowrap">标题</td><td><b><?php echo e($tk['title']); ?></b></td></tr>
                <tr><td style="background:var(--bg-page);white-space:nowrap">分类</td>
                    <td><span class="tag <?php echo ticket_category_tag((int)$tk['category']); ?>"><?php echo e(ticket_category_name((int)$tk['category'])); ?></span></td></tr>
                <tr><td style="background:var(--bg-page);white-space:nowrap">提交人</td>
                    <td><span class="tag <?php echo (int)$tk['user_type'] === 2 ? 'tag-warn' : 'tag-info'; ?>"><?php echo (int)$tk['user_type'] === 2 ? '代理商' : '用户'; ?></span>
                        <span class="cell-code" style="margin-left:8px"><?php echo e((string)$tk['uname']); ?>（ID: <?php echo (int)$tk['user_id']; ?>）</span></td></tr>
                <?php [$stName, $stTag] = $statusMap[(int)$tk['status']] ?? ['未知', 'tag-off']; ?>
                <tr><td style="background:var(--bg-page);white-space:nowrap">当前状态</td>
                    <td><span class="tag <?php echo $stTag; ?>"><?php echo e($stName); ?></span></td></tr>
                <tr><td style="background:var(--bg-page);white-space:nowrap">提交时间</td><td class="cell-sub"><?php echo date('Y-m-d H:i:s', (int)$tk['created_at']); ?></td></tr>
                <tr><td style="background:var(--bg-page);white-space:nowrap">更新时间</td><td class="cell-sub"><?php echo date('Y-m-d H:i:s', (int)$tk['updated_at']); ?></td></tr>
                <?php if ((int)$tk['rating'] > 0): ?>
                <tr><td style="background:var(--bg-page);white-space:nowrap">服务评分</td>
                    <td><?php echo ticket_rating_html((int)$tk['rating']); ?>
                        <?php if ((string)$tk['rating_note'] !== ''): ?><span class="cell-sub" style="margin-left:10px">「<?php echo e((string)$tk['rating_note']); ?>」</span><?php endif; ?>
                        <span class="cell-sub" style="margin-left:10px">评分于 <?php echo (int)$tk['rated_at'] > 0 ? date('Y-m-d H:i', (int)$tk['rated_at']) : '-'; ?></span>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h2 class="card-title">对话记录</h2>
        <div class="chat-thread">
            <?php if (!$replies): ?>
            <div class="empty-state">
                <img src="../assets/svg/icon-empty.svg" alt=""><span>暂无对话记录</span>
            </div>
            <?php else: ?>
            <?php foreach ($replies as $rp):
                $isAdmin = (int)$rp['sender_type'] === 2;
                $name = $isAdmin ? '管理员' : (string)$tk['uname'];
                $avatar = $isAdmin ? avatar_img($admin, 'chat-avatar', '管理员') : avatar_img(['email' => (string)$tk['aemail']], 'chat-avatar', $name);
                $time = date('Y-m-d H:i', (int)$rp['created_at']);
                $nameHtml = '<span' . ($isAdmin ? ' class="chat-name-admin"' : '') . '>' . e($name) . '</span>';
                $meta = $isAdmin
                    ? '<span>' . $time . '</span>' . $nameHtml
                    : $nameHtml . '<span>' . $time . '</span>';
            ?>
            <div class="chat-row<?php echo $isAdmin ? ' chat-row-self' : ''; ?>">
                <?php echo $avatar; ?>
                <div class="chat-col">
                    <div class="chat-meta"><?php echo $meta; ?></div>
                    <div class="chat-bubble<?php echo $isAdmin ? ' chat-bubble-admin' : ''; ?>"><?php echo e($rp['content']); ?></div>
                    <?php echo ticket_attachment_html($attGroups[(int)$rp['id']] ?? []); ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ((int)$tk['status'] !== 3): ?>
        <h2 class="card-title">回复工单</h2>
        <div class="qr-bar">
            <?php foreach (ticket_quick_replies() as $tpl): ?>
            <button type="button" class="qr-chip" data-text="<?php echo e($tpl); ?>"><?php echo e(mb_strlen($tpl) > 16 ? mb_substr($tpl, 0, 16) . '…' : $tpl); ?></button>
            <?php endforeach; ?>
            <button type="button" class="qr-manage" data-toggle-qr>管理模板</button>
        </div>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="id" value="<?php echo (int)$tk['id']; ?>">
            <textarea class="form-input" name="content" id="replyContent" rows="4" maxlength="2000" placeholder="请输入回复内容（必填），点击上方快捷模板可快速填入" required style="flex:1;min-width:0"></textarea>
            <div style="display:flex;flex-direction:column;gap:6px;width:220px">
                <input class="form-input" type="file" name="files[]" multiple
                       accept="<?php echo e('.' . implode(',.', ticket_attachment_exts())); ?>">
                <span style="font-size:12px;color:var(--text-third,#86909C);line-height:18px">可附截图 / 日志 / 压缩包等，单文件 ≤10MB，最多 5 个</span>
            </div>
            <button class="btn-primary" type="submit" data-confirm="确认提交回复？">回复</button>
        </form>
        <form method="post" id="qrPanel" style="display:none;margin-top:12px">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="qr_save">
            <input type="hidden" name="back_id" value="<?php echo (int)$tk['id']; ?>">
            <label class="form-label">快捷回复模板（每行一条，最多 30 条，保存后全局生效）</label>
            <textarea class="form-input" name="list" rows="5" style="max-width:640px"><?php echo e(implode("\n", ticket_quick_replies())); ?></textarea>
            <div style="margin-top:8px">
                <button class="btn-primary btn-mini" type="submit">保存模板</button>
            </div>
        </form>
        <form method="post" style="display:inline-block;margin-top:16px">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="id" value="<?php echo (int)$tk['id']; ?>">
            <input type="hidden" name="back" value="view">
            <button class="btn-danger-mini btn-mini" type="submit" data-confirm="确认关闭该工单？关闭后双方均无法继续回复。">关闭工单</button>
        </form>
        <?php else: ?>
        <div class="alert alert-ok">
            <img src="../assets/svg/check-circle.svg" alt="">
            <span><?php echo (int)$tk['rating'] > 0 ? '该工单已关闭并完成评分。' : '该工单已关闭，等待提交人评分（评分后本单完结）。'; ?></span>
        </div>
        <?php endif; ?>
    </section>
    <?php admin_footer();
    exit;
}

$status = (string)($_GET['status'] ?? '');
$utype  = (string)($_GET['utype'] ?? '');
$cat    = (string)($_GET['cat'] ?? '');
$kw     = trim((string)($_GET['kw'] ?? ''));
$categoryMap = ticket_categories();

$where  = '';
$params = [];
if ($status !== '' && isset($statusMap[(int)$status])) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `t`.`status`=?';
    $params[] = (int)$status;
}
if ($utype !== '' && in_array($utype, ['1', '2'], true)) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `t`.`user_type`=?';
    $params[] = (int)$utype;
}
if ($cat !== '' && isset($categoryMap[(int)$cat])) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `t`.`category`=?';
    $params[] = (int)$cat;
}
if ($kw !== '') {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `t`.`title` LIKE ?';
    $params[] = '%' . $kw . '%';
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$stmt = db()->prepare('SELECT COUNT(*) FROM `ticket` `t`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$offset = ($page - 1) * $perPage;
$params[] = $perPage;
$params[] = $offset;
$stmt = db()->prepare("SELECT `t`.*, COALESCE(`u`.`username`, `a`.`username`, '未知账号') AS `uname` FROM `ticket` `t`" . ticket_submitter_join() . $where . ' ORDER BY `t`.`updated_at` DESC, `t`.`id` DESC LIMIT ? OFFSET ?');
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stat = ['pending' => 0, 'today' => 0, 'total' => 0, 'rated' => 0, 'satisfy' => ''];
try {
    $stat['total'] = (int)db()->query('SELECT COUNT(*) FROM `ticket`')->fetchColumn();
    $stat['pending'] = (int)db()->query('SELECT COUNT(*) FROM `ticket` WHERE `status`=1')->fetchColumn();
    $stmt = db()->prepare('SELECT COUNT(*) FROM `ticket` WHERE `created_at`>=?');
    $stmt->execute([strtotime('today')]);
    $stat['today'] = (int)$stmt->fetchColumn();
    $r = db()->query('SELECT COUNT(*) AS `cnt`, COALESCE(AVG(`rating`), 0) AS `avg_rating` FROM `ticket` WHERE `rating`>0')->fetch();
    $stat['rated'] = (int)($r['cnt'] ?? 0);
    $stat['satisfy'] = (int)($r['cnt'] ?? 0) > 0 ? number_format((float)($r['avg_rating'] ?? 0), 1) : '';
} catch (Throwable $ex) {  }

admin_header('工单管理', 'ticket', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<section class="card">
    <h2 class="card-title">工单概览</h2>
    <div style="display:flex;flex-wrap:wrap;gap:48px">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--warning)"><?php echo $stat['pending']; ?></div>
            <div class="form-hint" style="margin-top:2px">待处理</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--primary)"><?php echo $stat['today']; ?></div>
            <div class="form-hint" style="margin-top:2px">今日新增</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $stat['total']; ?></div>
            <div class="form-hint" style="margin-top:2px">总工单</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:#f7ba2a"><?php echo $stat['satisfy'] !== '' ? $stat['satisfy'] . ' ★' : '—'; ?></div>
            <div class="form-hint" style="margin-top:2px">满意度（<?php echo $stat['rated']; ?> 单已评）</div>
        </div>
    </div>
</section>

<section class="card" id="cats">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">工单分类管理</h2>
    </div>
    <p class="card-desc">分类由后台自定义（提交工单 / 筛选 / 详情均实时生效）。有工单的分类不可删除，可改名称继续使用。</p>
    <div class="table-wrap">
        <table class="tb" style="max-width:640px">
            <thead><tr><th style="width:70px">ID</th><th>分类名称</th><th style="width:90px">工单数</th><th style="width:170px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($categoryMap as $cid => $cname): ?>
            <tr>
                <td class="cell-code"><?php echo $cid; ?></td>
                <td>
                    <form method="post" style="display:flex;gap:6px;align-items:center;max-width:300px">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="cat_rename">
                        <input type="hidden" name="id" value="<?php echo $cid; ?>">
                        <input class="form-input" type="text" name="name" value="<?php echo e($cname); ?>" maxlength="20" required style="height:30px">
                        <button class="btn-mini" type="submit">保存</button>
                    </form>
                </td>
                <td><?php echo ticket_category_used($cid); ?></td>
                <td>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="cat_del">
                        <input type="hidden" name="id" value="<?php echo $cid; ?>">
                        <button class="btn-danger-mini btn-mini" type="submit"
                                data-confirm="确认删除分类「<?php echo e($cname); ?>」？仅可删除无工单使用的分类。">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="post" style="display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="cat_add">
        <input class="form-input" type="text" name="name" maxlength="20" placeholder="新增分类名称（1~20 字）" required style="max-width:240px">
        <button class="btn-plain" type="submit">新增分类</button>
    </form>
</section>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">工单列表（共 <?php echo $total; ?> 条）</h2>
    </div>

    <form method="get" class="filter-bar" style="flex-wrap:wrap;margin-bottom:12px">
        <select class="form-input" name="status" style="max-width:140px">
            <option value="">全部状态</option>
            <?php foreach ($statusMap as $k => [$name, $tag]): ?>
            <option value="<?php echo $k; ?>" <?php echo $status === (string)$k ? 'selected' : ''; ?>><?php echo e($name); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="cat" style="max-width:140px">
            <option value="">全部分类</option>
            <?php foreach ($categoryMap as $k => $name): ?>
            <option value="<?php echo $k; ?>" <?php echo $cat === (string)$k ? 'selected' : ''; ?>><?php echo e($name); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="utype" style="max-width:140px">
            <option value="">全部类型</option>
            <option value="1" <?php echo $utype === '1' ? 'selected' : ''; ?>>用户</option>
            <option value="2" <?php echo $utype === '2' ? 'selected' : ''; ?>>代理商</option>
        </select>
        <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="工单标题关键词" style="max-width:240px">
        <button class="btn-plain" type="submit">查询</button>
    </form>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th style="width:180px">工单编号</th><th>提交人</th><th>标题</th><th style="width:90px">分类</th><th style="width:90px">状态</th><th style="width:90px">最后回复</th><th>提交时间</th><th>更新时间</th><th style="width:120px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): [$stName, $stTag] = $statusMap[(int)$row['status']] ?? ['未知', 'tag-off']; ?>
            <tr>
                <td class="cell-code"><?php echo e(ticket_no_display($row)); ?></td>
                <td style="white-space:nowrap">
                    <span class="tag <?php echo (int)$row['user_type'] === 2 ? 'tag-warn' : 'tag-info'; ?>"><?php echo (int)$row['user_type'] === 2 ? '代理商' : '用户'; ?></span>
                    <span class="cell-code" style="margin-left:6px"><?php echo e((string)$row['uname']); ?></span>
                </td>
                <td style="max-width:260px;word-break:break-all"><?php echo e($row['title']); ?></td>
                <td><span class="tag <?php echo ticket_category_tag((int)$row['category']); ?>"><?php echo e(ticket_category_name((int)$row['category'])); ?></span></td>
                <td><span class="tag <?php echo $stTag; ?>"><?php echo e($stName); ?></span></td>
                <td>
                    <?php if ((int)$row['last_reply'] === 1): ?>
                    <span class="tag tag-info">管理员</span>
                    <?php else: ?>
                    <span class="tag tag-off">提交人</span>
                    <?php endif; ?>
                </td>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i', (int)$row['created_at']); ?></td>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i', (int)$row['updated_at']); ?></td>
                <td style="white-space:nowrap">
                    <a class="btn-mini-plain" href="ticket_manage.php?act=view&amp;id=<?php echo (int)$row['id']; ?>" style="text-decoration:none;line-height:26px">查看</a>
                    <?php if (in_array((int)$row['status'], [1, 2], true)): ?>
                    <form method="post" style="display:inline-block">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="close">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-danger-mini btn-mini" type="submit" data-confirm="确认关闭工单 #<?php echo (int)$row['id']; ?>？关闭后双方均无法继续回复。">关闭</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $baseQuery = 'ticket_manage.php?status=' . urlencode($status) . '&utype=' . urlencode($utype) . '&cat=' . urlencode($cat) . '&kw=' . urlencode($kw) . '&';
    render_pagination($total, $page, $perPage, $baseQuery);
    ?>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
