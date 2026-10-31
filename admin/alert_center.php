<?php
// 管理员后台 - 告警中心
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$levelMap = [
    3 => ['严重', 'tag-err'],
    2 => ['警告', 'tag-warn'],
    1 => ['提示', 'tag-off'],
];

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'read') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `id`,`title` FROM `alert_center` WHERE `id`=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row && (int)$row['is_read'] === 0) {
            db()->prepare('UPDATE `alert_center` SET `is_read`=1 WHERE `id`=?')->execute([$id]);
            audit('alert', 'read', 1, (int)$admin['id'], '告警 #' . $id . ' 标记已读：' . $row['title']);
        }
        redirect('alert_center.php');
    }

    if ($action === 'read_all') {
        $stmt = db()->prepare('UPDATE `alert_center` SET `is_read`=1 WHERE `is_read`=0');
        $stmt->execute();
        $n = $stmt->rowCount();
        audit('alert', 'read', 1, (int)$admin['id'], '全部标为已读 ' . $n . ' 条告警');
        flash_set('ok', '已将 ' . $n . ' 条告警标记为已读。');
        redirect('alert_center.php');
    }

    if ($action === 'del') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `title` FROM `alert_center` WHERE `id`=?');
        $stmt->execute([$id]);
        $title = (string)$stmt->fetchColumn();
        db()->prepare('DELETE FROM `alert_center` WHERE `id`=?')->execute([$id]);
        audit('alert', 'clean', 1, (int)$admin['id'], '删除告警 #' . $id . '：' . $title);
        redirect('alert_center.php');
    }
}

$level  = (string)($_GET['level'] ?? '');
$module = (string)($_GET['module'] ?? '');
$isRead = (string)($_GET['read'] ?? '');
$kw     = trim((string)($_GET['kw'] ?? ''));

$where  = '';
$params = [];
if ($level !== '' && isset($levelMap[(int)$level])) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `level`=?';
    $params[] = (int)$level;
}
if ($module !== '') {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `module`=?';
    $params[] = $module;
}
if ($isRead !== '' && in_array($isRead, ['0', '1'], true)) {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' `is_read`=?';
    $params[] = (int)$isRead;
}
if ($kw !== '') {
    $where .= ($where === '' ? ' WHERE' : ' AND') . ' (`title` LIKE ? OR `content` LIKE ?)';
    $params = array_merge($params, ['%' . $kw . '%', '%' . $kw . '%']);
}

$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$stmt = db()->prepare('SELECT COUNT(*) FROM `alert_center`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$offset = ($page - 1) * $perPage;
$params[] = $perPage;
$params[] = $offset;
$stmt = db()->prepare('SELECT * FROM `alert_center`' . $where . ' ORDER BY `id` DESC LIMIT ? OFFSET ?');
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stat = ['unread' => 0, 'severe' => 0, 'warn' => 0, 'info' => 0];
try {
    $stat['unread'] = (int)db()->query('SELECT COUNT(*) FROM `alert_center` WHERE `is_read`=0')->fetchColumn();
    $r = db()->query('SELECT `level`, COUNT(*) AS `c` FROM `alert_center` WHERE `is_read`=0 GROUP BY `level`')->fetchAll();
    foreach ($r as $s) {
        if ((int)$s['level'] === 3) $stat['severe'] = (int)$s['c'];
        elseif ((int)$s['level'] === 2) $stat['warn'] = (int)$s['c'];
        else $stat['info'] += (int)$s['c'];
    }
} catch (Throwable $ex) {  }

$modules = [];
try {
    $modules = db()->query('SELECT DISTINCT `module` FROM `alert_center` ORDER BY `module`')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $ex) {  }

admin_header('告警中心', 'alert', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<section class="card">
    <h2 class="card-title">未读概览</h2>
    <div style="display:flex;flex-wrap:wrap;gap:48px">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $stat['unread']; ?></div>
            <div class="form-hint" style="margin-top:2px">未读总数</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--error)"><?php echo $stat['severe']; ?></div>
            <div class="form-hint" style="margin-top:2px">严重（未读）</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--warning)"><?php echo $stat['warn']; ?></div>
            <div class="form-hint" style="margin-top:2px">警告（未读）</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-secondary)"><?php echo $stat['info']; ?></div>
            <div class="form-hint" style="margin-top:2px">提示（未读）</div>
        </div>
    </div>
</section>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">告警列表（共 <?php echo $total; ?> 条）</h2>
        <div class="spacer"></div>
        <form method="post" style="display:flex">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="read_all">
            <button class="btn-plain" type="submit" data-confirm="确认将全部未读告警标记为已读？">全部标为已读</button>
        </form>
    </div>

    <form method="get" class="filter-bar" style="flex-wrap:wrap;margin-bottom:12px">
        <select class="form-input" name="level" style="max-width:130px">
            <option value="">全部级别</option>
            <?php foreach ($levelMap as $k => [$name, $tag]): ?>
            <option value="<?php echo $k; ?>" <?php echo $level === (string)$k ? 'selected' : ''; ?>><?php echo e($name); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="module" style="max-width:160px">
            <option value="">全部模块</option>
            <?php foreach ($modules as $m): ?>
            <option value="<?php echo e($m); ?>" <?php echo $module === $m ? 'selected' : ''; ?>><?php echo e($m !== '' ? $m : '未知模块'); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="read" style="max-width:130px">
            <option value="">全部状态</option>
            <option value="0" <?php echo $isRead === '0' ? 'selected' : ''; ?>>未读</option>
            <option value="1" <?php echo $isRead === '1' ? 'selected' : ''; ?>>已读</option>
        </select>
        <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="标题 / 内容 关键词" style="max-width:240px">
        <button class="btn-plain" type="submit">查询</button>
    </form>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt=""><span>暂无数据</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>时间</th><th style="width:70px">级别</th><th>标题</th><th>内容</th><th>来源模块</th><th>IP</th><th style="width:80px">状态</th><th style="width:120px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): [$lvName, $lvTag] = $levelMap[(int)$row['level']] ?? ['未知', 'tag-off']; ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-sub" style="white-space:nowrap"><?php echo date('Y-m-d H:i:s', (int)$row['created_at']); ?></td>
                <td><span class="tag <?php echo $lvTag; ?>"><?php echo e($lvName); ?></span></td>
                <td style="max-width:240px;word-break:break-all"><b><?php echo e($row['title']); ?></b></td>
                <td class="cell-sub" style="max-width:260px;word-break:break-all"><?php echo $row['content'] !== '' ? e($row['content']) : '-'; ?></td>
                <td class="cell-code"><?php echo e($row['module'] !== '' ? $row['module'] : '-'); ?></td>
                <td class="cell-code"><?php echo $row['ip'] !== '' ? e($row['ip']) : '-'; ?></td>
                <td>
                    <?php if ((int)$row['is_read'] === 0): ?>
                    <span class="tag tag-info">未读</span>
                    <?php else: ?>
                    <span class="tag tag-off">已读</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int)$row['is_read'] === 0): ?>
                    <form method="post" style="display:inline-block">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="read">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-mini-plain" type="submit">标记已读</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline-block">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="del">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-danger-mini btn-mini" type="submit" data-confirm="确认删除该条告警？该操作不可恢复。">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $baseQuery = 'alert_center.php?level=' . urlencode($level) . '&module=' . urlencode($module)
        . '&read=' . urlencode($isRead) . '&kw=' . urlencode($kw) . '&';
    render_pagination($total, $page, $perPage, $baseQuery);
    ?>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
