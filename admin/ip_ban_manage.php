<?php
// 管理员后台 - IP 封禁管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$error = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'ban') {
        $ip = trim((string)($_POST['ip'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        $days = (int)($_POST['days'] ?? 0);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $error = 'IP 地址格式不正确。';
        } else {
            try {
                $stmt = db()->prepare('INSERT INTO `ip_ban` (`ip`,`reason`,`ban_time`,`expire_time`) VALUES (?,?,?,?)');
                $stmt->execute([$ip, mb_substr($reason, 0, 200), time(), $days > 0 ? time() + $days * 86400 : 0]);
                audit('security', 'ip_ban', 1, (int)$admin['id'], '封禁 IP：' . $ip . ($reason !== '' ? '（' . $reason . '）' : '') . ($days > 0 ? '，' . $days . ' 天后自动解封' : '，永久'));
                require_once ROOT_PATH . '/core/Mail.php';
                mail_scene_send('system', '', [
                    'title'   => 'IP 封禁通知',
                    'content' => '管理员 ' . $admin['username'] . ' 已将 IP ' . $ip . ' 加入黑名单（' . ($reason !== '' ? '原因：' . $reason : '未填写原因') . '；' . ($days > 0 ? $days . ' 天后自动解封' : '永久封禁') . '）。',
                    'time'    => date('Y-m-d H:i:s'),
                ]);
                flash_set('ok', 'IP ' . $ip . ' 已加入黑名单。');
            } catch (Throwable $ex) {
                $error = '封禁失败：该 IP 可能已在黑名单中。';
            }
            redirect('ip_ban_manage.php');
        }
    }

    if ($action === 'unban') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `ip` FROM `ip_ban` WHERE `id`=?');
        $stmt->execute([$id]);
        $ip = (string)$stmt->fetchColumn();
        if ($ip !== '') {
            db()->prepare('DELETE FROM `ip_ban` WHERE `id`=?')->execute([$id]);
            audit('security', 'ip_unban', 1, (int)$admin['id'], '解封 IP：' . $ip);
            flash_set('ok', 'IP ' . $ip . ' 已解封。');
        }
        redirect('ip_ban_manage.php');
    }
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$stmt = db()->query('SELECT COUNT(*) FROM `ip_ban`');
$total = (int)$stmt->fetchColumn();
$offset = ($page - 1) * $perPage;
$stmt = db()->prepare('SELECT * FROM `ip_ban` ORDER BY `id` DESC LIMIT ? OFFSET ?');
$stmt->execute([$perPage, $offset]);
$rows = $stmt->fetchAll();

admin_header('IP 封禁管理', 'ip_ban', $admin);
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
    <h2 class="card-title">添加封禁</h2>
    <p class="card-desc">黑名单 IP 将被密钥下发与验证接口直接拒绝（返回封禁错误），不影响后台登录。</p>
    <form method="post" class="form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ban">
        <div class="form-item">
            <label class="form-label">IP 地址</label>
            <input class="form-input" type="text" name="ip" maxlength="45" style="max-width:240px" placeholder="例如：192.168.1.100" required>
        </div>
        <div class="form-item">
            <label class="form-label">封禁原因</label>
            <input class="form-input" type="text" name="reason" maxlength="200" style="max-width:360px" placeholder="例如：恶意爆破验证接口">
        </div>
        <div class="form-item">
            <label class="form-label">封禁时长</label>
            <div class="input-group" style="display:flex;max-width:360px">
                <select class="form-input" name="days" style="max-width:160px">
                    <option value="0">永久</option>
                    <option value="1">1 天</option>
                    <option value="3">3 天</option>
                    <option value="7">7 天</option>
                    <option value="30">30 天</option>
                    <option value="90">90 天</option>
                </select>
            </div>
            <p class="form-hint">到期后自动失效（记录保留在列表中可手动清除）</p>
        </div>
        <button class="btn-primary" type="submit" data-confirm="确认将该 IP 加入黑名单？">加入黑名单</button>
    </form>
</section>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">黑名单列表（共 <?php echo $total; ?> 条）</h2>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-setting.svg" alt="">
        <p>黑名单为空，系统正常运行。</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>IP 地址</th><th>封禁原因</th><th>封禁时间</th><th>到期时间</th><th>当前状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                $permanent = (int)$row['expire_time'] === 0;
                $expired = !$permanent && (int)$row['expire_time'] <= time();
            ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-code"><?php echo e($row['ip']); ?></td>
                <td class="cell-sub"><?php echo $row['reason'] !== '' ? e($row['reason']) : '-'; ?></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i', (int)$row['ban_time']); ?></td>
                <td class="cell-sub"><?php echo $permanent ? '永久' : date('Y-m-d H:i', (int)$row['expire_time']); ?></td>
                <td>
                    <?php if ($permanent): ?>
                    <span class="tag tag-err">封禁中</span>
                    <?php elseif ($expired): ?>
                    <span class="tag tag-off">已过期</span>
                    <?php else: ?>
                    <span class="tag tag-warn">临时封禁</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" style="display:inline-block">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="unban">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-mini-plain" type="submit" data-confirm="确认解封 <?php echo e($row['ip']); ?> 并删除该记录？">解封</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, 'ip_ban_manage.php?'); ?>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
