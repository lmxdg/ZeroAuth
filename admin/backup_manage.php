<?php
// 管理员后台 - 数据备份中心
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$backupDir = RUNTIME_PATH . '/backups';
$error = '';

if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0700, true);
}
if (is_dir($backupDir)) {
    if (!is_file($backupDir . '/.htaccess')) {
        @file_put_contents($backupDir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
    if (!is_file($backupDir . '/index.html')) {
        @file_put_contents($backupDir . '/index.html', '');
    }
}

function backup_quote(PDO $pdo, $value): string
{
    return $value === null ? 'NULL' : $pdo->quote((string)$value);
}

function backup_create_file(string $dir): array
{
    $name = 'backup_' . date('Ymd_His') . '.sql';
    $tmp  = $dir . '/.' . $name . '.tmp';
    $path = $dir . '/' . $name;
    $fh   = fopen($tmp, 'wb');
    if ($fh === false) {
        throw new RuntimeException('无法创建备份文件，请检查 runtime/backups 写入权限。');
    }

    try {
        fwrite($fh, "-- ZeroAuth 数据库备份\n");
        fwrite($fh, '-- 生成时间：' . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, '-- 数据库：' . config()['db']['name'] . "\n\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $table = (string)$table;
            $safe  = '`' . str_replace('`', '``', $table) . '`';
            $create = db()->query('SHOW CREATE TABLE ' . $safe)->fetch(PDO::FETCH_NUM);
            if (!is_array($create) || empty($create[1])) {
                continue;
            }
            fwrite($fh, "-- 表结构：" . $table . "\n");
            fwrite($fh, 'DROP TABLE IF EXISTS ' . $safe . ";\n");
            fwrite($fh, $create[1] . ";\n\n");

            $total = (int)db()->query('SELECT COUNT(*) FROM ' . $safe)->fetchColumn();
            if ($total <= 0) {
                continue;
            }
            $columns = [];
            $first = db()->query('SELECT * FROM ' . $safe . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($first)) {
                continue;
            }
            foreach (array_keys($first) as $col) {
                $columns[] = '`' . str_replace('`', '``', (string)$col) . '`';
            }
            $columnSql = implode(',', $columns);
            $chunk = 200;
            for ($offset = 0; $offset < $total; $offset += $chunk) {
                $rows = db()->query('SELECT * FROM ' . $safe . ' LIMIT ' . (int)$offset . ',' . $chunk)->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) {
                    break;
                }
                $lines = [];
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = backup_quote(db(), $value);
                    }
                    $lines[] = '(' . implode(',', $values) . ')';
                }
                fwrite($fh, 'INSERT INTO ' . $safe . ' (' . $columnSql . ") VALUES\n" . implode(",\n", $lines) . ";\n");
            }
            fwrite($fh, "\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('备份文件保存失败。');
        }
        @chmod($path, 0600);

        // 仅保留最近 30 个自动备份，避免占满磁盘
        $old = glob($dir . '/backup_*.sql') ?: [];
        usort($old, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($old, 30) as $file) {
            @unlink($file);
        }

        return [$name, filesize($path) ?: 0];
    } catch (Throwable $ex) {
        if (is_resource($fh)) {
            fclose($fh);
        }
        @unlink($tmp);
        throw $ex;
    }
}

function backup_valid_name(string $name): bool
{
    return (bool)preg_match('/^backup_\d{8}_\d{6}\.sql$/', $name);
}

function backup_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        try {
            [$name, $size] = backup_create_file($backupDir);
            audit('system', 'backup_create', 1, (int)$admin['id'], '创建数据库备份：' . $name . '（' . backup_bytes($size) . '）');
            flash_set('ok', '备份已创建：' . $name . '（' . backup_bytes($size) . '）。');
        } catch (Throwable $ex) {
            flash_set('error', '备份失败：' . $ex->getMessage());
        }
        redirect('backup_manage.php');
    }

    if ($action === 'delete') {
        $name = (string)($_POST['file'] ?? '');
        if (backup_valid_name($name) && is_file($backupDir . '/' . $name)) {
            @unlink($backupDir . '/' . $name);
            audit('system', 'backup_delete', 1, (int)$admin['id'], '删除数据库备份：' . $name);
            flash_set('ok', '备份文件已删除。');
        } else {
            flash_set('error', '备份文件不存在或文件名不合法。');
        }
        redirect('backup_manage.php');
    }
}

if ((string)($_GET['act'] ?? '') === 'download') {
    $name = (string)($_GET['file'] ?? '');
    $csrf = (string)($_GET['csrf'] ?? '');
    if (!hash_equals(csrf_token(), $csrf) || !backup_valid_name($name) || !is_file($backupDir . '/' . $name)) {
        http_response_code(403);
        exit('下载校验失败。');
    }
    audit('system', 'backup_download', 1, (int)$admin['id'], '下载数据库备份：' . $name);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string)filesize($backupDir . '/' . $name));
    readfile($backupDir . '/' . $name);
    exit;
}

$files = glob($backupDir . '/backup_*.sql') ?: [];
usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));

admin_header('数据备份', 'backup', $admin);
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
    <div class="page-actions">
        <div>
            <h2 class="card-title">创建数据库备份</h2>
            <p class="card-desc" style="margin-bottom:0">备份包含全部数据表结构与数据，文件保存在 <code>runtime/backups/</code>，仅后台登录后可下载。</p>
        </div>
        <div class="spacer"></div>
        <form method="post" data-confirm="确定立即创建一份完整数据库备份吗？数据量较大时可能需要几十秒。">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <button class="btn-primary" type="submit">立即备份</button>
        </form>
    </div>
</section>

<section class="card">
    <div class="page-actions">
        <h2 class="card-title" style="margin-bottom:0">备份文件（共 <?php echo count($files); ?> 个）</h2>
    </div>
    <?php if (!$files): ?>
    <div class="empty-state">
        <img src="../assets/svg/menu-download.svg" alt="">
        <p>暂无备份文件，点击上方「立即备份」创建。</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="tb">
            <thead><tr><th>文件名</th><th>大小</th><th>创建时间</th><th style="width:190px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($files as $file):
                $name = basename($file);
                $time = (int)filemtime($file);
            ?>
            <tr>
                <td class="cell-code"><?php echo e($name); ?></td>
                <td class="cell-sub"><?php echo backup_bytes((int)filesize($file)); ?></td>
                <td class="cell-sub"><?php echo date('Y-m-d H:i:s', $time); ?></td>
                <td>
                    <a class="btn-mini" href="backup_manage.php?act=download&file=<?php echo rawurlencode($name); ?>&csrf=<?php echo e(csrf_token()); ?>">下载</a>
                    <form method="post" style="display:inline" data-confirm="确定删除备份「<?php echo e($name); ?>」吗？">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="file" value="<?php echo e($name); ?>">
                        <button class="btn-danger-mini" type="submit">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <div class="alert alert-warn" style="margin-top:16px">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>备份包含账号、密钥等敏感数据，请妥善保管。恢复数据可在 phpMyAdmin 或命令行导入生成的 SQL 文件。</span>
    </div>
</section>
<?php admin_footer(); ?>
