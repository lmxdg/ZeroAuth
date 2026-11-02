<?php
// 危险操作：一键跑路 / 定时跑路（删库 + 清空网站文件，不可恢复）
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

$action = (string)($_POST['action'] ?? '');
$error  = '';
if ($action !== '') {
    csrf_check();

    if ($action === 'ruin_now') {
        $confirm = trim((string)($_POST['ruin_confirm'] ?? ''));
        if ($confirm !== '确认跑路') {
            $error = '确认文字不正确：请输入「确认跑路」四个字后重试。';
        } else {
            audit('config', 'ruin_now', 1, (int)$admin['id'], '管理员执行一键跑路：删库并清空网站文件');
            ruin_execute(); // 内部直接输出完成页并 exit
        }
    }

    if ($action === 'ruin_schedule') {
        $confirm = trim((string)($_POST['ruin_confirm'] ?? ''));
        $timeRaw = trim((string)($_POST['ruin_time'] ?? ''));
        $ts = $timeRaw !== '' ? strtotime($timeRaw) : 0;
        if ($confirm !== '确认跑路') {
            $error = '确认文字不正确：请输入「确认跑路」四个字后重试。';
        } elseif ($ts === false || $ts === 0) {
            $error = '请选择有效的定时跑路时间。';
        } elseif ($ts <= time() + 30) {
            $error = '定时跑路时间必须晚于当前时间至少 30 秒。';
        } elseif ($ts > time() + 86400 * 365) {
            $error = '定时跑路时间不能超过一年。';
        } else {
            ruin_schedule($ts);
            flash_set('ok', '定时跑路已设定：' . date('Y-m-d H:i:s', $ts) . ' 到点后任意请求将自动执行。');
            redirect('ruin.php');
        }
    }

    if ($action === 'ruin_cancel') {
        ruin_schedule(0);
        flash_set('ok', '定时跑路已取消。');
        redirect('ruin.php');
    }
}

$scheduledAt = ruin_scheduled_at();

admin_header('危险操作', 'ruin', $admin);
?>
<div class="card" style="border:1px solid rgba(220,38,38,.35)">
    <h2 class="card-title" style="color:#dc2626">⚠️ 危险操作 · 跑路</h2>
    <div class="alert alert-error" style="margin-bottom:14px">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span><b>跑路 = 删库 + 清空网站文件，立即生效且不可恢复！</b><br>
        执行后将删除当前数据库中的全部数据表，并删除站点根目录下的全部文件（包括系统自身），操作无法撤销。请务必提前完成数据备份。此功能仅限系统所有者在弃用/迁移/紧急止损场景使用。</span>
    </div>
    <?php if ($error !== ''): ?>
    <div class="alert alert-error">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span><?php echo e($error); ?></span>
    </div>
    <?php endif; ?>
</div>

<?php if ($scheduledAt > 0): ?>
<div class="card" style="border:1px solid rgba(220,38,38,.5)">
    <h2 class="card-title" style="color:#dc2626">⏱ 定时跑路已启动</h2>
    <p class="card-desc" style="font-size:15px;margin-bottom:12px">
        系统将于 <b style="color:#dc2626"><?php echo e(date('Y-m-d H:i:s', $scheduledAt)); ?></b>
        自动执行跑路（剩余 <?php
            $left = max(0, $scheduledAt - time());
            echo $left >= 86400 ? floor($left / 86400) . ' 天 ' . floor(($left % 86400) / 3600) . ' 小时' : floor($left / 3600) . ' 小时 ' . floor(($left % 3600) / 60) . ' 分钟';
        ?>），到点后任意请求触发删库与清空网站文件，<b>请尽快备份并确认不再需要本站</b>。
    </p>
    <form method="post" style="display:flex;gap:10px;align-items:center">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ruin_cancel">
        <button class="btn-primary" type="submit" style="background:#0e9f6e">立即取消定时跑路</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">一键跑路</h2>
    <p class="card-desc" style="margin-bottom:14px">
        点击「立即跑路」后将<b>立刻删除数据库全部数据表并清空网站全部文件</b>，系统将不复存在。
        为防止误触，必须输入 <b style="color:#dc2626">确认跑路</b> 四个字才能执行。
    </p>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center" onsubmit="return ruinConfirm(this);">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ruin_now">
        <input class="form-input" type="text" name="ruin_confirm" placeholder="输入：确认跑路" style="max-width:220px" autocomplete="off" required>
        <button class="btn-primary" type="submit" style="background:#dc2626;border-color:#dc2626">立即跑路（不可恢复）</button>
    </form>
    <script>
    function ruinConfirm(f) {
        if (f.ruin_confirm.value.trim() !== '确认跑路') {
            alert('请输入「确认跑路」四个字以确认执行。');
            return false;
        }
        return confirm('最终警告：跑路将删库并清空网站全部文件，立即生效且不可恢复！\n确定要执行吗？');
    }
    </script>
</div>

<div class="card">
    <h2 class="card-title">定时跑路</h2>
    <p class="card-desc" style="margin-bottom:14px">
        设定一个未来时间点，到点后系统将在<b>任意一次请求</b>（首页 / 后台 / 接口均算）进入时自动执行跑路。
        设定后可在上方随时取消；到点前不会执行任何删除动作。
    </p>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center" onsubmit="return ruinScheduleConfirm(this);">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ruin_schedule">
        <input class="form-input" type="datetime-local" name="ruin_time" style="max-width:230px" required>
        <input class="form-input" type="text" name="ruin_confirm" placeholder="输入：确认跑路" style="max-width:220px" autocomplete="off" required>
        <button class="btn-primary" type="submit" style="background:#dc2626;border-color:#dc2626">设定定时跑路</button>
    </form>
    <script>
    function ruinScheduleConfirm(f) {
        if (f.ruin_confirm.value.trim() !== '确认跑路') {
            alert('请输入「确认跑路」四个字以确认设定。');
            return false;
        }
        return confirm('设定定时跑路：到点后任意请求将自动删库并清空网站文件！\n确定要设定吗？');
    }
    </script>
</div>

<?php admin_footer(); ?>
