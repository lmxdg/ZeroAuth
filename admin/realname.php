<?php
// 管理员后台 - 实名审核
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/Security.php';
require dirname(__DIR__) . '/core/Mail.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$error = '';

try {
    db()->query("ALTER TABLE `user` ADD COLUMN `real_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '实名驳回理由' AFTER `real_name`");
} catch (Throwable $ex) {
}

$realMap = [0 => ['未认证', 'tag-off'], 1 => ['待审核', 'tag-warn'], 2 => ['已通过', 'tag-ok'], 3 => ['已驳回', 'tag-err']];

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $uid    = (int)($_POST['id'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM `user` WHERE `id`=?');
    $stmt->execute([$uid]);
    $target = $stmt->fetch();

    if (!$target) {
        $error = '用户不存在。';
    } elseif ($action === 'pass') {
        if ((int)$target['real_status'] !== 1) {
            $error = '仅待审核状态可执行通过。';
        } else {
            db()->prepare('UPDATE `user` SET `real_status`=2,`real_reason`=\'\' WHERE `id`=?')->execute([$uid]);
            audit('realname', 'pass', 1, (int)$admin['id'], '实名通过：' . $target['username'] . '（' . $target['real_name'] . '）');
            if ((string)$target['email'] !== '') {
                mail_scene_send('realname', (string)$target['email'], [
                    'userName' => (string)$target['username'],
                    'result'   => '已通过',
                    'reason'   => '无',
                    'time'     => date('Y-m-d H:i'),
                ]);
            }
            flash_set('ok', '已通过 ' . $target['username'] . ' 的实名认证。');
            redirect('realname.php');
        }
    } elseif ($action === 'reject') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ((int)$target['real_status'] !== 1) {
            $error = '仅待审核状态可执行驳回。';
        } elseif ($reason === '' || mb_strlen($reason) > 200) {
            $error = '驳回必须填写理由（200 字以内）。';
        } else {
            db()->prepare('UPDATE `user` SET `real_status`=3,`real_reason`=? WHERE `id`=?')->execute([$reason, $uid]);
            audit('realname', 'reject', 1, (int)$admin['id'], '实名驳回：' . $target['username'] . ' 理由：' . $reason);
            if ((string)$target['email'] !== '') {
                mail_scene_send('realname', (string)$target['email'], [
                    'userName' => (string)$target['username'],
                    'result'   => '已驳回',
                    'reason'   => $reason,
                    'time'     => date('Y-m-d H:i'),
                ]);
            }
            flash_set('ok', '已驳回 ' . $target['username'] . ' 的实名认证，驳回理由已通知用户。');
            redirect('realname.php');
        }
    } elseif ($action === 'delete') {
        db()->prepare('UPDATE `user` SET `real_status`=0,`real_name`=\'\',`id_card`=\'\',`real_reason`=\'\' WHERE `id`=?')->execute([$uid]);
        audit('realname', 'delete', 1, (int)$admin['id'], '删除实名信息：' . $target['username'] . '（' . $target['real_name'] . '）');
        flash_set('ok', '已删除 ' . $target['username'] . ' 的实名认证信息。');
        redirect('realname.php');
    }
}

$pageSize  = 15;
$page      = max(1, (int)($_GET['page'] ?? 1));
$filterSt  = (int)($_GET['status'] ?? 0);
$filterKw  = trim((string)($_GET['kw'] ?? ''));

function page_url(int $p): string
{
    global $filterSt, $filterKw;
    return 'realname.php?' . http_build_query(['status' => $filterSt ?: '', 'kw' => $filterKw, 'page' => $p]);
}

$where  = ['u.`real_status`>0'];
$params = [];
if (in_array($filterSt, [1, 2, 3], true)) {
    $where[]  = 'u.`real_status`=?';
    $params[] = $filterSt;
}
if ($filterKw !== '') {
    $where[]  = '(u.`username` LIKE ? OR u.`email` LIKE ? OR u.`real_name` LIKE ?)';
    $params[] = '%' . $filterKw . '%';
    $params[] = '%' . $filterKw . '%';
    $params[] = '%' . $filterKw . '%';
}
$whereStr = implode(' AND ', $where);

$stmt = db()->prepare('SELECT COUNT(*) FROM `user` u WHERE ' . $whereStr);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $pageSize));
$page  = min($page, $pages);

$stmt = db()->prepare('SELECT u.`id`,u.`username`,u.`email`,u.`real_status`,u.`real_name`,u.`real_reason`,u.`id_card`,u.`created_at`,g.`username` AS `agent_name` FROM `user` u LEFT JOIN `agent` g ON g.`id`=u.`agent_id` WHERE ' . $whereStr . ' ORDER BY FIELD(u.`real_status`,1,3,2), u.`id` DESC LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize));
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statStmt = db()->query('SELECT `real_status`, COUNT(*) AS `c` FROM `user` WHERE `real_status`>0 GROUP BY `real_status`');
$stat = [1 => 0, 2 => 0, 3 => 0];
foreach ($statStmt->fetchAll() as $s) {
    $stat[(int)$s['real_status']] = (int)$s['c'];
}

admin_header('实名认证审核', 'realname', $admin);
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

<div class="stat-grid">
    <div class="stat-card"><div class="stat-label">待审核</div><div class="stat-value" style="color:var(--warning)"><?php echo $stat[1]; ?></div></div>
    <div class="stat-card"><div class="stat-label">已通过</div><div class="stat-value" style="color:var(--success)"><?php echo $stat[2]; ?></div></div>
    <div class="stat-card"><div class="stat-label">已驳回</div><div class="stat-value" style="color:var(--error)"><?php echo $stat[3]; ?></div></div>
</div>

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">实名认证列表（共 <?php echo $total; ?> 条）</h2>
            <p class="card-desc" style="margin-bottom:0">待审核记录优先展示；身份证号为解密后明文，仅管理员可见。审核结果自动邮件通知用户。</p>
        </div>
        <div class="spacer"></div>
        <div class="filter-bar" style="margin-bottom:0">
            <a class="btn-plain" href="realname.php" style="text-decoration:none;line-height:32px">全部</a>
            <a class="btn-mini-plain <?php echo $filterSt === 1 ? 'btn-mini' : ''; ?>" href="realname.php?status=1" style="line-height:30px">待审核 <?php echo $stat[1]; ?></a>
            <a class="btn-mini-plain <?php echo $filterSt === 2 ? 'btn-mini' : ''; ?>" href="realname.php?status=2" style="line-height:30px">已通过</a>
            <a class="btn-mini-plain <?php echo $filterSt === 3 ? 'btn-mini' : ''; ?>" href="realname.php?status=3" style="line-height:30px">已驳回</a>
            <form method="get" style="display:flex">
                <?php if ($filterSt): ?><input type="hidden" name="status" value="<?php echo $filterSt; ?>"><?php endif; ?>
                <input class="form-input" type="text" name="kw" value="<?php echo e($filterKw); ?>" placeholder="账号 / 邮箱 / 姓名">
                <button class="btn-plain" type="submit">搜索</button>
            </form>
        </div>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-user.svg" alt="">
        <p>暂无实名记录。</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>账号</th><th>邮箱</th><th>姓名</th><th>身份证号</th><th>状态</th><th>所属代理商</th><th style="width:220px">操作</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="8"><div class="empty-state">暂无符合条件的记录。</div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row):
                [$rName, $rTag] = $realMap[(int)$row['real_status']] ?? ['未知', 'tag-off'];
                $idCardPlain = sec_id_decrypt((string)$row['id_card']);
                $pending = (int)$row['real_status'] === 1;
            ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-main"><?php echo e($row['username']); ?></td>
                <td class="cell-sub"><?php echo $row['email'] !== '' ? e($row['email']) : '-'; ?></td>
                <td><?php echo e($row['real_name']); ?></td>
                <td class="cell-code"><?php echo e($idCardPlain); ?></td>
                <td>
                    <span class="tag <?php echo $rTag; ?>"><?php echo $rName; ?></span>
                    <?php if ((int)$row['real_status'] === 3 && $row['real_reason'] !== ''): ?>
                    <div class="cell-sub" style="margin-top:4px;max-width:200px">理由：<?php echo e($row['real_reason']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="cell-sub"><?php echo $row['agent_name'] !== '' ? e($row['agent_name']) : '平台直营'; ?></td>
                <td style="white-space:normal">
                    <?php if ($pending): ?>
                    <form method="post" style="display:inline-block" data-confirm="确认通过「<?php echo e($row['username']); ?>」的实名认证？">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="pass">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-mini" type="submit">通过</button>
                    </form>
                    <button class="btn-mini-plain" type="button" onclick="openReject(<?php echo (int)$row['id']; ?>, '<?php echo e($row['username']); ?>')">驳回</button>
                    <?php endif; ?>
                    <form method="post" style="display:inline-block" data-confirm="确定删除「<?php echo e($row['username']); ?>」的实名信息吗？删除后状态回到未认证，用户可重新提交。">
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

    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?><a class="page-btn" href="<?php echo page_url($page - 1); ?>">上一页</a><?php else: ?><span class="page-btn disabled">上一页</span><?php endif; ?>
        <?php
        $pageList = [];
        for ($p = 1; $p <= $pages; $p++) {
            if ($p === 1 || $p === $pages || abs($p - $page) <= 2) {
                $pageList[] = $p;
            }
        }
        $prev = 0;
        foreach ($pageList as $p):
            if ($prev && $p - $prev > 1): ?>
            <a class="page-btn" href="<?php echo page_url((int)ceil(($prev + $p) / 2)); ?>">...</a>
            <?php endif;
            $prev = $p; ?>
            <?php if ($p === $page): ?>
            <span class="page-btn active"><?php echo $p; ?></span>
            <?php else: ?>
            <a class="page-btn" href="<?php echo page_url($p); ?>"><?php echo $p; ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < $pages): ?><a class="page-btn" href="<?php echo page_url($page + 1); ?>">下一页</a><?php else: ?><span class="page-btn disabled">下一页</span><?php endif; ?>
        <span class="page-info">共 <?php echo $total; ?> 条 / <?php echo $pages; ?> 页</span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</section>

<div class="modal-mask" id="rjMask">
    <div class="modal-box" style="width:440px;max-width:calc(100vw - 40px)">
        <div class="modal-title">驳回实名认证</div>
        <div class="modal-text" id="rjWho"></div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="rjId" value="0">
            <textarea class="form-input" id="rjReason" name="reason" rows="3" maxlength="200" placeholder="请填写驳回理由（必填，将以邮件通知用户）" required style="height:auto;min-height:80px;resize:vertical;margin-bottom:18px"></textarea>
            <div class="modal-actions" style="margin-bottom:0">
                <button class="btn-plain" type="button" onclick="closeReject()">取消</button>
                <button class="btn-danger-mini" type="submit">确认驳回</button>
            </div>
        </form>
    </div>
</div>
<script>
function openReject(id, name) {
    document.getElementById('rjId').value = id;
    document.getElementById('rjWho').textContent = '确定驳回「' + name + '」的实名认证吗？驳回后用户需重新提交，驳回理由将通过邮件通知。';
    document.getElementById('rjReason').value = '';
    document.getElementById('rjMask').classList.add('show');
    setTimeout(function () { document.getElementById('rjReason').focus(); }, 50);
}
function closeReject() {
    document.getElementById('rjMask').classList.remove('show');
}
document.getElementById('rjMask').addEventListener('click', function (e) { if (e.target === this) closeReject(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeReject(); });
</script>
<?php admin_footer(); ?>
