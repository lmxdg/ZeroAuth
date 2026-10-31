<?php
// 管理员后台 - 代理等级管理
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$act   = (string)($_GET['act'] ?? 'list');
$id    = (int)($_GET['id'] ?? 0);
$error = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $idPost      = (int)($_POST['id'] ?? 0);
        $name        = trim((string)($_POST['name'] ?? ''));
        $discount    = round((float)($_POST['discount'] ?? 0), 2);
        $price       = round((float)($_POST['price'] ?? 0), 2);
        $giftBalance = round((float)($_POST['gift_balance'] ?? 0), 2);
        $benefits    = trim((string)($_POST['benefits'] ?? ''));
        $sort        = (int)($_POST['sort'] ?? 0);
        $autoOpen    = (int)($_POST['auto_open'] ?? 0) === 1 ? 1 : 0;
        $status      = (int)($_POST['status'] ?? 1) === 1 ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 64) {
            $error = '等级名称不能为空且不超过 64 字。';
        } elseif ($discount < 0.01 || $discount > 1.00) {
            $error = '折扣不合法（0.01 ~ 1.00，0.90=九折）。';
        } elseif ($price < 0 || $price > 999999) {
            $error = '开通价格不合法（须大于等于 0）。';
        } elseif ($giftBalance < 0 || $giftBalance > 999999) {
            $error = '赠送余额不合法（须大于等于 0）。';
        } else {
            if ($idPost > 0) {
                $stmt = db()->prepare('UPDATE `agent_level` SET `name`=?,`discount`=?,`price`=?,`gift_balance`=?,`benefits`=?,`sort`=?,`auto_open`=?,`status`=? WHERE `id`=?');
                $stmt->execute([$name, number_format($discount, 2, '.', ''), number_format($price, 2, '.', ''), number_format($giftBalance, 2, '.', ''), $benefits, $sort, $autoOpen, $status, $idPost]);
                audit('level', 'update', 1, (int)$admin['id'], '等级ID：' . $idPost . ' 名称：' . $name . ' 折扣：' . $discount . ' 开通价格：' . $price);
                flash_set('ok', '等级已保存。');
            } else {
                $stmt = db()->prepare('INSERT INTO `agent_level` (`name`,`discount`,`price`,`gift_balance`,`benefits`,`sort`,`auto_open`,`status`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$name, number_format($discount, 2, '.', ''), number_format($price, 2, '.', ''), number_format($giftBalance, 2, '.', ''), $benefits, $sort, $autoOpen, $status, time()]);
                audit('level', 'create', 1, (int)$admin['id'], '等级：' . $name . ' 折扣：' . $discount . ' 开通价格：' . $price);
                flash_set('ok', '等级「' . $name . '」已创建。');
            }
            redirect('level_manage.php');
        }
        $act = $idPost > 0 ? 'edit' : 'new';
        $id  = $idPost;
    }

    if ($action === 'toggle') {
        $idPost = (int)($_POST['id'] ?? 0);
        $to     = (int)($_POST['to'] ?? 1) === 1 ? 1 : 0;
        $stmt = db()->prepare('UPDATE `agent_level` SET `status`=? WHERE `id`=?');
        $stmt->execute([$to, $idPost]);
        audit('level', 'toggle', 1, (int)$admin['id'], '等级ID：' . $idPost . ' 状态：' . ($to === 1 ? '启用' : '停用'));
        flash_set('ok', $to === 1 ? '等级已启用。' : '等级已停用，用户端不再展示该等级。');
        redirect('level_manage.php');
    }

    if ($action === 'delete') {
        $idPost = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT `name` FROM `agent_level` WHERE `id`=?');
        $stmt->execute([$idPost]);
        $name = (string)$stmt->fetchColumn();
        if ($name !== '') {
            $stmt = db()->prepare('SELECT COUNT(*) FROM `agent` WHERE `level_id`=?');
            $stmt->execute([$idPost]);
            if ((int)$stmt->fetchColumn() > 0) {
                flash_set('err', '该等级已有代理商开通使用，禁止删除；如需下线请改为「停用」。');
            } else {
                db()->prepare('DELETE FROM `agent_level` WHERE `id`=?')->execute([$idPost]);
                audit('level', 'delete', 1, (int)$admin['id'], '等级ID：' . $idPost . ' 名称：' . $name);
                flash_set('ok', '等级「' . $name . '」已删除。');
            }
        }
        redirect('level_manage.php');
    }
}

$level = null;
if ($id > 0 && $act === 'edit') {
    $stmt = db()->prepare('SELECT * FROM `agent_level` WHERE `id`=?');
    $stmt->execute([$id]);
    $level = $stmt->fetch();
    if (!$level) {
        $error = '等级不存在或已删除。';
        $act = 'list';
    }
}

$rows = [];
if ($act === 'list') {
    $rows = db()->query(
        'SELECT l.*, (SELECT COUNT(*) FROM `agent` a WHERE a.`level_id`=l.`id`) AS `agent_count` '
        . 'FROM `agent_level` l ORDER BY l.`sort` DESC, l.`id` DESC'
    )->fetchAll();
}

$stat = ['total' => 0, 'enabled' => 0, 'agents' => 0, 'auto' => 0];
try {
    $stat['total']   = (int)db()->query('SELECT COUNT(*) FROM `agent_level`')->fetchColumn();
    $stat['enabled'] = (int)db()->query('SELECT COUNT(*) FROM `agent_level` WHERE `status`=1')->fetchColumn();
    $stat['auto']    = (int)db()->query('SELECT COUNT(*) FROM `agent_level` WHERE `auto_open`=1')->fetchColumn();
    $stat['agents']  = (int)db()->query('SELECT COUNT(*) FROM `agent` WHERE `level_id`>0')->fetchColumn();
} catch (Throwable $ex) {  }

admin_header('等级管理', 'level', $admin);
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

<?php 
if ($act === 'list'): ?>
<section class="card">
    <h2 class="card-title">等级概览</h2>
    <div style="display:flex;flex-wrap:wrap;gap:48px">
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--text-main)"><?php echo $stat['total']; ?></div>
            <div class="form-hint" style="margin-top:2px">等级总数</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--success)"><?php echo $stat['enabled']; ?></div>
            <div class="form-hint" style="margin-top:2px">启用中</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--primary)"><?php echo $stat['agents']; ?></div>
            <div class="form-hint" style="margin-top:2px">已开通代理商</div>
        </div>
        <div>
            <div style="font-size:22px;font-weight:600;color:var(--warning)"><?php echo $stat['auto']; ?></div>
            <div class="form-hint" style="margin-top:2px">自动开通等级</div>
        </div>
    </div>
</section>

<section class="card">
    <div class="page-actions">
        <div>
            <h2 class="card-title" style="margin-bottom:0">等级列表</h2>
        </div>
        <div class="spacer"></div>
        <a class="btn-primary" href="level_manage.php?act=new">新建等级</a>
    </div>

    <?php if (!$rows): ?>
    <div class="empty-state">
        <img src="../assets/svg/icon-empty.svg" alt="">
        <span>暂无数据</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>ID</th><th>等级名称</th><th>折扣</th><th>开通价格</th><th>赠送余额</th><th>权益</th><th>排序</th><th style="width:80px">自动开通</th><th style="width:70px">状态</th><th>已开通人数</th><th style="width:190px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                $benefitsOne = trim(preg_replace('/\s+/u', ' / ', trim((string)$row['benefits'])));
                if (mb_strlen($benefitsOne) > 50) {
                    $benefitsOne = mb_substr($benefitsOne, 0, 50) . '…';
                }
            ?>
            <tr>
                <td class="cell-code"><?php echo (int)$row['id']; ?></td>
                <td class="cell-main"><?php echo e($row['name']); ?></td>
                <td><?php echo e(number_format((float)$row['discount'] * 10, 1)); ?>折</td>
                <td>¥<?php echo e(number_format((float)$row['price'], 2)); ?></td>
                <td>¥<?php echo e(number_format((float)$row['gift_balance'], 2)); ?></td>
                <td class="cell-sub" style="max-width:220px"><?php echo $benefitsOne !== '' ? e($benefitsOne) : '-'; ?></td>
                <td><?php echo (int)$row['sort']; ?></td>
                <td><span class="tag <?php echo (int)$row['auto_open'] === 1 ? 'tag-info' : 'tag-off'; ?>"><?php echo (int)$row['auto_open'] === 1 ? '开' : '关'; ?></span></td>
                <td><span class="tag <?php echo (int)$row['status'] === 1 ? 'tag-ok' : 'tag-off'; ?>"><?php echo (int)$row['status'] === 1 ? '启用' : '停用'; ?></span></td>
                <td><?php echo (int)$row['agent_count']; ?></td>
                <td>
                    <a class="btn-mini-plain" href="level_manage.php?act=edit&id=<?php echo (int)$row['id']; ?>" style="text-decoration:none;line-height:26px">编辑</a>
                    <form method="post" style="display:inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <input type="hidden" name="to" value="<?php echo (int)$row['status'] === 1 ? 0 : 1; ?>">
                        <button class="btn-mini-plain" type="submit" data-confirm="确定<?php echo (int)$row['status'] === 1 ? '停用' : '启用'; ?>等级「<?php echo e($row['name']); ?>」吗？"><?php echo (int)$row['status'] === 1 ? '停用' : '启用'; ?></button>
                    </form>
                    <form method="post" style="display:inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn-danger-mini" type="submit" data-confirm="确定删除等级「<?php echo e($row['name']); ?>」吗？删除后不可恢复。">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php 
elseif ($act === 'new' || $act === 'edit'): ?>
<section class="card">
    <h2 class="card-title"><?php echo $act === 'new' ? '新建等级' : '编辑等级'; ?></h2>
    <p class="card-desc">折扣为卡密售价系数，0.90 表示九折；开通后代理商按等级折扣进货，并获得赠送余额。</p>
    <form method="post" class="form" style="max-width:640px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int)($level['id'] ?? 0); ?>">
        <div class="form-item">
            <label class="form-label">等级名称</label>
            <input class="form-input" type="text" name="name" maxlength="64" value="<?php echo e($level['name'] ?? ''); ?>" placeholder="例如：黄金代理" required>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">折扣系数</label>
                <input class="form-input" type="number" name="discount" min="0.01" max="1.00" step="0.01" value="<?php echo e($level['discount'] ?? '0.90'); ?>" required>
                <div class="form-hint">0.01 ~ 1.00，0.90=九折。</div>
            </div>
            <div class="form-item">
                <label class="form-label">开通价格（元）</label>
                <input class="form-input" type="number" name="price" min="0" max="999999" step="0.01" value="<?php echo e($level['price'] ?? '0.00'); ?>" required>
                <div class="form-hint">用户开通该等级需支付的金额，须大于等于 0。</div>
            </div>
        </div>
        <div class="form-item">
            <label class="form-label">开通赠送余额（元）</label>
            <input class="form-input" type="number" name="gift_balance" min="0" max="999999" step="0.01" value="<?php echo e($level['gift_balance'] ?? '0.00'); ?>" required>
            <div class="form-hint">开通成功后自动到账的代理商余额，须大于等于 0。</div>
        </div>
        <div class="form-item">
            <label class="form-label">权益介绍</label>
            <textarea class="form-input" name="benefits" rows="4" placeholder="例如：&#10;卡密九折进货&#10;专属客服支持"><?php echo e($level['benefits'] ?? ''); ?></textarea>
            <div class="form-hint">每行一条权益说明，展示在用户端等级信息中。</div>
        </div>
        <div class="form-row">
            <div class="form-item">
                <label class="form-label">排序</label>
                <input class="form-input" type="number" name="sort" value="<?php echo (int)($level['sort'] ?? 0); ?>">
                <div class="form-hint">数值越大越靠前。</div>
            </div>
            <div class="form-item">
                <label class="form-label">状态</label>
                <div style="display:flex;gap:22px;font-size:14px">
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="radio" name="status" value="1" <?php echo (int)($level['status'] ?? 1) === 1 ? 'checked' : ''; ?>> 启用
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="radio" name="status" value="0" <?php echo (int)($level['status'] ?? 1) === 0 ? 'checked' : ''; ?>> 停用
                    </label>
                </div>
            </div>
        </div>
        <div class="form-item">
            <label class="form-label">自动开通</label>
            <div style="display:flex;gap:22px;font-size:14px">
                <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                    <input type="radio" name="auto_open" value="1" <?php echo (int)($level['auto_open'] ?? 0) === 1 ? 'checked' : ''; ?>> 开
                </label>
                <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                    <input type="radio" name="auto_open" value="0" <?php echo (int)($level['auto_open'] ?? 0) === 0 ? 'checked' : ''; ?>> 关
                </label>
            </div>
            <div class="form-hint">开启后该等级在用户端「开通代理」页展示，用户可自助付费开通。</div>
        </div>
        <div class="page-actions">
            <a class="btn-plain" href="level_manage.php">返回列表</a>
            <button class="btn-primary" type="submit">保存等级</button>
        </div>
    </form>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
