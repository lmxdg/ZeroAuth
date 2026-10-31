<?php
// 代理商中心 - 用户管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_agent.php';

$agent = agent_require_login();
$aid   = (int)$agent['id'];

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'invite_reset') {
        for ($i = 0; $i < 5; $i++) {
            $code = invite_code_gen();
            $chk  = db()->prepare('SELECT `id` FROM `agent` WHERE `invite_code`=? AND `id`<>?');
            $chk->execute([$code, $aid]);
            if (!$chk->fetch()) {
                db()->prepare('UPDATE `agent` SET `invite_code`=? WHERE `id`=?')->execute([$code, $aid]);
                audit('agent', 'invite_code_reset', 2, $aid);
                flash_set('ok', '邀请码已重置，原邀请码立即失效。');
                break;
            }
        }
        redirect('users.php');
    }
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$kw      = trim((string)($_GET['kw'] ?? ''));

$where  = ' WHERE `agent_id`=?';
$params = [$aid];
if ($kw !== '') {
    $where .= ' AND `username` LIKE ?';
    $params[] = '%' . $kw . '%';
}
$stmt = db()->prepare('SELECT COUNT(*) FROM `user`' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$params[] = $perPage;
$params[] = ($page - 1) * $perPage;
$stmt = db()->prepare('SELECT * FROM `user`' . $where . ' ORDER BY `id` DESC LIMIT ? OFFSET ?');
$stmt->execute($params);
$rows = $stmt->fetchAll();

$inviteCode = invite_code_ensure($aid);

agent_header('名下用户', 'users', $agent);
?>

<style>

.invite-box{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:14px 16px;margin-bottom:16px}
.invite-code{font-family:Consolas,Monaco,monospace;font-size:20px;font-weight:700;letter-spacing:2px;color:var(--p)}
.filter-bar{display:flex;gap:10px;flex-wrap:wrap}
.filter-bar .form-input{max-width:200px}
</style>

<?php $flash = flash_take(); ?>
<?php if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<section class="card">
    <h2 class="card-title">邀请码绑定</h2>
    <p class="card-desc">将邀请码发给您的用户，用户注册时填写该邀请码即自动绑定为您名下用户。名下用户只能通过邀请码绑定，无法手动添加。</p>
    <div class="invite-box">
        <span class="invite-code" id="inviteCode"><?php echo e($inviteCode !== '' ? $inviteCode : '生成失败，请刷新重试'); ?></span>
        <button type="button" class="btn-plain" id="inviteCopyBtn" data-copy="<?php echo e($inviteCode); ?>">复制</button>
        <form method="post" style="display:inline">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="invite_reset">
            <button class="btn-plain" type="submit" data-confirm="确认重置邀请码吗？原邀请码将立即失效，已绑定用户不受影响。">重置邀请码</button>
        </form>
    </div>
</section>

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title">用户列表（共 <?php echo $total; ?> 个）</h2>
        </div>
        <div class="spacer"></div>
        <form method="get" class="filter-bar" style="margin-bottom:0">
            <input class="form-input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="按账号搜索">
            <button class="btn-plain" type="submit">搜索</button>
        </form>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state"><img src="../assets/svg/icon-empty.svg" alt=""><span>暂无名下用户。</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>账号</th><th>邮箱</th><th>状态</th><th>注册时间</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td><?php echo e($row['username']); ?></td>
                <td class="cell-code"><?php echo $row['email'] !== '' ? e($row['email']) : '-'; ?></td>
                <td><span class="tag <?php echo (int)$row['status'] === 1 ? 'tag-ok' : 'tag-err'; ?>"><?php echo (int)$row['status'] === 1 ? '正常' : '已禁用'; ?></span></td>
                <td class="cell-code"><?php echo date('Y-m-d H:i', (int)$row['created_at']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($total, $page, $perPage, 'users.php?kw=' . urlencode($kw) . '&'); ?>
    <?php endif; ?>
</section>

<script>

(function () {
    var btn = document.getElementById('inviteCopyBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy') || '';
        var done = function () {
            var old = btn.textContent;
            btn.textContent = '已复制';
            setTimeout(function () { btn.textContent = old; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () { fallback(); });
        } else {
            fallback();
        }
        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch (e) {  }
            document.body.removeChild(ta);
        }
    });
})();
</script>
<?php agent_footer(); ?>
