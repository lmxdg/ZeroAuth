<?php
// 管理员后台 - 系统公告管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$error = '';

function notice_parse_time(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $ts = strtotime(str_replace('T', ' ', $value));
    return $ts === false ? -1 : $ts;
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $title   = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $type    = (string)($_POST['type'] ?? 'info');
        $target  = (int)($_POST['target'] ?? 1);
        $status  = (int)($_POST['status'] ?? 1) === 1 ? 1 : 0;
        $sort    = (int)($_POST['sort'] ?? 0);
        $start   = notice_parse_time((string)($_POST['start_time'] ?? ''));
        $end     = notice_parse_time((string)($_POST['end_time'] ?? ''));

        if (!in_array($type, ['info', 'success', 'warn', 'danger'], true)) {
            $type = 'info';
        }
        if (!in_array($target, [1, 2, 3], true)) {
            $target = 1;
        }

        if ($title === '' || mb_strlen($title) > 120) {
            $error = '公告标题不能为空且不超过 120 字。';
        } elseif (mb_strlen($content) > 5000) {
            $error = '公告内容不能超过 5000 字。';
        } elseif ($start < 0 || $end < 0) {
            $error = '生效或失效时间格式不正确。';
        } elseif ($start > 0 && $end > 0 && $end < $start) {
            $error = '失效时间不能早于生效时间。';
        } else {
            $data = [$title, $content, $type, $target, $status, $sort, max(0, $start), max(0, $end), time()];
            if ($id > 0) {
                $stmt = db()->prepare('UPDATE `notice` SET `title`=?,`content`=?,`type`=?,`target`=?,`status`=?,`sort`=?,`start_time`=?,`end_time`=?,`updated_at`=? WHERE `id`=?');
                $stmt->execute([...$data, $id]);
                audit('notice', 'update', 1, (int)$admin['id'], '公告#' . $id . '：' . $title);
                flash_set('ok', '公告已更新。');
            } else {
                $stmt = db()->prepare('INSERT INTO `notice` (`title`,`content`,`type`,`target`,`status`,`sort`,`start_time`,`end_time`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$title, $content, $type, $target, $status, $sort, max(0, $start), max(0, $end), time(), time()]);
                audit('notice', 'create', 1, (int)$admin['id'], '发布公告：' . $title);
                flash_set('ok', '公告已发布。');
            }
            redirect('notice_manage.php');
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $to = (int)($_POST['to'] ?? 1) === 1 ? 1 : 0;
        db()->prepare('UPDATE `notice` SET `status`=?,`updated_at`=? WHERE `id`=?')->execute([$to, time(), $id]);
        audit('notice', 'toggle', 1, (int)$admin['id'], '公告#' . $id . ' ' . ($to === 1 ? '启用' : '停用'));
        flash_set('ok', $to === 1 ? '公告已启用。' : '公告已停用。');
        redirect('notice_manage.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `title` FROM `notice` WHERE `id`=?');
        $stmt->execute([$id]);
        $title = (string)$stmt->fetchColumn();
        if ($title !== '') {
            db()->prepare('DELETE FROM `notice` WHERE `id`=?')->execute([$id]);
            audit('notice', 'delete', 1, (int)$admin['id'], '删除公告：' . $title);
            flash_set('ok', '公告已删除。');
        }
        redirect('notice_manage.php');
    }
}

$edit = null;
if ((int)($_GET['id'] ?? 0) > 0) {
    $stmt = db()->prepare('SELECT * FROM `notice` WHERE `id`=?');
    $stmt->execute([(int)$_GET['id']]);
    $edit = $stmt->fetch() ?: null;
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$total   = (int)db()->query('SELECT COUNT(*) FROM `notice`')->fetchColumn();
$offset  = ($page - 1) * $perPage;
$stmt    = db()->prepare('SELECT * FROM `notice` ORDER BY `status` DESC,`sort` DESC,`id` DESC LIMIT ? OFFSET ?');
$stmt->execute([$perPage, $offset]);
$rows = $stmt->fetchAll();

$typeNames   = ['info' => '通知', 'success' => '成功', 'warn' => '提醒', 'danger' => '重要'];
$targetNames = [1 => '全部', 2 => '用户中心', 3 => '代理商中心'];
$now = time();

admin_header('公告管理', 'notice', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif;
if ($error !== ''): ?>
<div class="alert alert-error">
    <img src="../assets/svg/warn-circle.svg" alt="">
    <span><?php echo e($error); ?></span>
</div>
<?php endif; ?>

<section class="card">
    <h2 class="card-title"><?php echo $edit ? '编辑公告' : '发布公告'; ?></h2>
    <p class="card-desc">公告会显示在用户中心 / 代理商中心首页，可设置展示范围、有效期与排序。</p>
    <form method="post" class="form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo $edit ? (int)$edit['id'] : 0; ?>">
        <div class="form-item">
            <label class="form-label">公告标题</label>
            <input class="form-input" type="text" name="title" maxlength="120" value="<?php echo e((string)($edit['title'] ?? '')); ?>" placeholder="例如：系统维护通知" required>
        </div>
        <div class="form-item">
            <label class="form-label">公告内容</label>
            <textarea class="form-input" name="content" rows="5" maxlength="5000" placeholder="支持纯文本，换行会保留展示"><?php echo e((string)($edit['content'] ?? '')); ?></textarea>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">公告类型</label>
                <select class="form-input" name="type">
                    <?php foreach ($typeNames as $key => $name): ?>
                    <option value="<?php echo e($key); ?>" <?php echo (($edit['type'] ?? 'info') === $key) ? 'selected' : ''; ?>><?php echo e($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-item">
                <label class="form-label">展示范围</label>
                <select class="form-input" name="target">
                    <?php foreach ($targetNames as $key => $name): ?>
                    <option value="<?php echo $key; ?>" <?php echo (int)($edit['target'] ?? 1) === $key ? 'selected' : ''; ?>><?php echo e($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-item">
                <label class="form-label">状态</label>
                <select class="form-input" name="status">
                    <option value="1" <?php echo (int)($edit['status'] ?? 1) === 1 ? 'selected' : ''; ?>>启用</option>
                    <option value="0" <?php echo (int)($edit['status'] ?? 1) === 0 ? 'selected' : ''; ?>>停用</option>
                </select>
            </div>
            <div class="form-item">
                <label class="form-label">排序</label>
                <input class="form-input" type="number" name="sort" value="<?php echo (int)($edit['sort'] ?? 0); ?>" placeholder="越大越靠前">
            </div>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">生效时间</label>
                <input class="form-input" type="datetime-local" name="start_time" value="<?php echo !empty($edit['start_time']) ? date('Y-m-d\TH:i', (int)$edit['start_time']) : ''; ?>">
                <p class="form-hint">留空表示立即生效</p>
            </div>
            <div class="form-item">
                <label class="form-label">失效时间</label>
                <input class="form-input" type="datetime-local" name="end_time" value="<?php echo !empty($edit['end_time']) ? date('Y-m-d\TH:i', (int)$edit['end_time']) : ''; ?>">
                <p class="form-hint">留空表示永久有效</p>
            </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center">
            <button class="btn-primary" type="submit"><?php echo $edit ? '保存修改' : '发布公告'; ?></button>
            <?php if ($edit): ?>
            <a class="btn-plain" href="notice_manage.php">取消编辑</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">公告列表（共 <?php echo $total; ?> 条）</h2>
    </div>
    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-log.svg" alt="">
        <p>暂无公告，发布后将展示在用户端首页。</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>标题</th><th>类型</th><th>范围</th><th>有效期</th><th>排序</th><th>状态</th><th>更新时间</th><th style="width:210px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                $st = (int)$row['start_time'];
                $et = (int)$row['end_time'];
                $isLive = (int)$row['status'] === 1 && ($st === 0 || $st <= $now) && ($et === 0 || $et >= $now);
            ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-main">
                    <?php echo e($row['title']); ?>
                    <div class="cell-sub" style="margin-top:3px"><?php echo e(mb_substr((string)$row['content'], 0, 60)); ?><?php echo mb_strlen((string)$row['content']) > 60 ? '…' : ''; ?></div>
                </td>
                <td><span class="tag tag-info"><?php echo e($typeNames[$row['type']] ?? '通知'); ?></span></td>
                <td class="cell-sub"><?php echo e($targetNames[(int)$row['target']] ?? '全部'); ?></td>
                <td class="cell-sub">
                    <?php echo $st > 0 ? date('Y-m-d H:i', $st) : '立即'; ?>
                    ~
                    <?php echo $et > 0 ? date('Y-m-d H:i', $et) : '永久'; ?>
                </td>
                <td><?php echo (int)$row['sort']; ?></td>
                <td>
                    <?php if ($isLive): ?>
                    <span class="tag tag-ok">展示中</span>
                    <?php elseif ((int)$row['status'] !== 1): ?>
                    <span class="tag tag-off">已停用</span>
                    <?php else: ?>
                    <span class="tag tag-warn">未生效/已过期</span>
                    <?php endif; ?>
                </td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$row['updated_at']); ?></td>
                <td>
                    <a class="btn-mini" href="notice_manage.php?id=<?php echo (int)$row['id']; ?>">编辑</a>
                    <form method="post" style="display:inline" data-confirm="确定<?php echo (int)$row['status'] === 1 ? '停用' : '启用'; ?>公告「<?php echo e($row['title']); ?>」吗？">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <input type="hidden" name="to" value="<?php echo (int)$row['status'] === 1 ? 0 : 1; ?>">
                        <button class="btn-mini-plain" type="submit"><?php echo (int)$row['status'] === 1 ? '停用' : '启用'; ?></button>
                    </form>
                    <form method="post" style="display:inline" data-confirm="确定删除公告「<?php echo e($row['title']); ?>」吗？删除后不可恢复。">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-danger-mini" type="submit">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, 'notice_manage.php?'); ?>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
