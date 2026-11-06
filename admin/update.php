<?php
// 管理员后台 - 一键在线更新
// 流程：检测新版本 → 自动下载最新安装包（GitHub Release zip 资产，缺失时回退仓库源码包）
//      → 校验并解压 → 自动覆盖替换系统文件（保护 config.php / install.lock / runtime / uploads，
//         自定义后台目录自动适配）→ 更新完成后自动删除下载的安装包与临时文件
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();

@set_time_limit(600);
@ignore_user_abort(true);

const UPDATE_SKIP_FILES   = ['config.php', 'install.lock'];
const UPDATE_SKIP_PREFIX  = ['runtime/', 'uploads/'];
const UPDATE_MAX_SIZE     = 200 * 1024 * 1024; // 安装包体积上限 200MB

function update_admin_dir(): string
{
    $dir = 'admin';
    try {
        $cfg = config();
        if (is_array($cfg) && isset($cfg['admin_dir']) && is_string($cfg['admin_dir']) && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,29}$/', $cfg['admin_dir'])) {
            $dir = $cfg['admin_dir'];
        }
    } catch (Throwable $ex) {
    }
    return $dir;
}

function update_rmdir(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                return false;
            }
        } elseif (is_dir($path)) {
            if (!update_rmdir($path)) {
                return false;
            }
        }
    }
    return @rmdir($dir);
}

function update_download(string $url, string $dest, int $timeout = 600): array
{
    $fp = @fopen($dest, 'wb');
    if (!$fp) {
        return ['ok' => false, 'msg' => '无法创建本地文件 ' . $dest . '，请检查 runtime/ 目录写权限。'];
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $fp,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 8,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => 15,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_USERAGENT       => 'ZeroAuth-Updater/' . PRODUCT_VERSION,
            CURLOPT_HTTPHEADER      => ['Accept: application/zip, */*'],
        ]);
        $ok = curl_exec($ch) !== false;
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($fp);
        if ($ok && $code >= 200 && $code < 300 && $size > 0) {
            return ['ok' => true, 'msg' => '（' . round($size / 1048576, 2) . ' MB）'];
        }
        @unlink($dest);
        return ['ok' => false, 'msg' => '下载失败：HTTP ' . ($code ?: 0) . ($err !== '' ? '，' . $err : '')];
    }
    // 无 cURL 时使用数据流下载（自动跟随重定向）
    $ctx = stream_context_create(['http' => [
        'timeout'         => $timeout,
        'follow_location' => 1,
        'max_redirects'   => 8,
        'header'          => "User-Agent: ZeroAuth-Updater/" . PRODUCT_VERSION . "\r\nAccept: application/zip, */*\r\n",
    ], 'ssl' => []]);
    $src = @fopen($url, 'rb', false, $ctx);
    if (!$src) {
        fclose($fp);
        @unlink($dest);
        return ['ok' => false, 'msg' => '下载失败：无法连接下载源。'];
    }
    $size = (int)stream_copy_to_stream($src, $fp);
    fclose($src);
    fclose($fp);
    if ($size <= 0) {
        @unlink($dest);
        return ['ok' => false, 'msg' => '下载失败：未接收到任何数据。'];
    }
    return ['ok' => true, 'msg' => '（' . round($size / 1048576, 2) . ' MB）'];
}

// 递归覆盖复制（含保护规则），返回 [复制文件数, 跳过数]
function update_copy_tree(string $srcDir, string $destBase, string $adminDir, array &$log, string $rel = ''): array
{
    $copied = 0;
    $skipped = 0;
    $items = scandir($srcDir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $srcPath = $srcDir . '/' . $item;
        $relPath = $rel !== '' ? $rel . '/' . $item : $item;
        $isDir = is_dir($srcPath);

        // 保护文件：配置 / 锁 / 运行时 / 上传目录
        if (!$isDir && in_array($relPath, UPDATE_SKIP_FILES, true)) {
            $skipped++;
            $log[] = '已保护（跳过）：' . $relPath;
            continue;
        }
        foreach (UPDATE_SKIP_PREFIX as $prefix) {
            if (str_starts_with($relPath . ($isDir ? '/' : ''), $prefix)) {
                $skipped++;
                if (!$isDir) {
                    $log[] = '已保护（跳过）：' . $relPath;
                }
                continue 2;
            }
        }

        // 自定义后台目录适配：包内 admin/ 全部迁往实际后台目录
        $destRel = $relPath;
        if ($relPath === 'admin' && $isDir && $adminDir !== 'admin') {
            $skipped++;
            continue;
        }
        if (str_starts_with($relPath, 'admin/') && $adminDir !== 'admin') {
            $destRel = $adminDir . substr($relPath, strlen('admin'));
        }

        $destPath = $destBase . '/' . $destRel;
        if ($isDir) {
            if (!is_dir($destPath) && !@mkdir($destPath, 0755, true) && !is_dir($destPath)) {
                $log[] = '创建目录失败：' . $destRel;
                $skipped++;
                continue;
            }
            [$c, $s] = update_copy_tree($srcPath, $destBase, $adminDir, $log, $relPath);
            $copied += $c;
            $skipped += $s;
        } else {
            $destDir = dirname($destPath);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                $log[] = '创建目录失败：' . dirname($destRel);
                $skipped++;
                continue;
            }
            if (!@copy($srcPath, $destPath)) {
                $log[] = '覆盖失败（权限？）：' . $destRel;
                $skipped++;
                continue;
            }
            @chmod($destPath, 0644);
            $copied++;
        }
    }
    return [$copied, $skipped];
}

$log = [];
$done = false;
$failed = false;

$info = [];
try {
    $info = update_check_fetch();
} catch (Throwable $ex) {
}
$updateOk = !empty($info['ok']);
$updateNew = $updateOk && version_compare((string)($info['version'] ?? ''), (string)PRODUCT_VERSION, '>');
$zipSrc = $updateOk ? update_zip_source($info) : ['url' => '', 'name' => '', 'size' => 0, 'from' => ''];

if (is_post() && (string)($_POST['action'] ?? '') === 'start_update') {
    csrf_check();
    try {
        $info = update_check_fetch(true);
    } catch (Throwable $ex) {
        $info = [];
    }
    $updateOk = !empty($info['ok']);
    $updateNew = $updateOk && version_compare((string)($info['version'] ?? ''), (string)PRODUCT_VERSION, '>');

    if (!$updateOk) {
        $failed = true;
        $log[] = '更新失败：无法连接 GitHub Releases 获取最新版本信息，请稍后重试。';
    } elseif (!$updateNew) {
        $failed = true;
        $log[] = '当前已是最新版本 V' . PRODUCT_VERSION . '，无需更新。';
    } else {
        $zipSrc = update_zip_source($info);
        if ($zipSrc['url'] === '') {
            $failed = true;
            $log[] = '更新失败：该 Release 未提供 zip 安装包，且无法回退为源码包。';
        } else {
            // 并发锁：10 分钟内不允许重复执行
            $workDir = RUNTIME_PATH . '/update';
            $lockFile = $workDir . '/.lock';
            if (!is_dir($workDir) && !@mkdir($workDir, 0755, true)) {
                $failed = true;
                $log[] = '更新失败：无法创建 runtime/update 工作目录，请检查写权限。';
            } elseif (is_file($lockFile) && time() - (int)@filemtime($lockFile) < 600) {
                $failed = true;
                $log[] = '更新正在进行中（10 分钟内已启动过一次）。若确认没有更新在执行，请稍后重试或手动删除 runtime/update/.lock。';
            } else {
                @file_put_contents($lockFile, (string)time());
                $pkgFile = $workDir . '/update_' . preg_replace('/[^0-9A-Za-z.\-]/', '', (string)$info['version']) . '.zip';
                $extDir = $workDir . '/extract';

                $log[] = '目标版本：V' . $info['version'] . '，安装包：' . ($zipSrc['name'] !== '' ? $zipSrc['name'] : basename($zipSrc['url'])) . ($zipSrc['from'] === 'source' ? '（源码包回退）' : '');
                $log[] = '开始下载安装包…';
                $dl = update_download($zipSrc['url'], $pkgFile);
                if (!$dl['ok']) {
                    $failed = true;
                    $log[] = $dl['msg'];
                } else {
                    $log[] = '安装包下载完成 ' . $dl['msg'];
                    if (!class_exists('ZipArchive')) {
                        $failed = true;
                        $log[] = '更新失败：服务器未启用 Zip 扩展（php-zip），无法解压安装包。';
                    } elseif ((int)@filesize($pkgFile) > UPDATE_MAX_SIZE) {
                        $failed = true;
                        $log[] = '更新失败：安装包体积超出安全上限（200MB）。';
                    } else {
                        $zip = new ZipArchive();
                        if ($zip->open($pkgFile) !== true) {
                            $failed = true;
                            $log[] = '更新失败：安装包不是有效的 zip 文件或已损坏。';
                        } else {
                            if (is_dir($extDir)) {
                                update_rmdir($extDir);
                            }
                            if (!$zip->extractTo($extDir)) {
                                $failed = true;
                                $log[] = '更新失败：安装包解压失败。';
                            } else {
                                $zip->close();
                                // 定位包根目录（标准包为单层文件夹，源码包同层）
                                $root = $extDir;
                                $entries = array_values(array_diff(scandir($extDir) ?: ['.', '..'], ['.', '..']));
                                if (count($entries) === 1 && is_dir($extDir . '/' . $entries[0])) {
                                    $root = $extDir . '/' . $entries[0];
                                }
                                $sanity = is_file($root . '/core/bootstrap.php') || is_file($root . '/index.php') || is_dir($root . '/admin') || is_dir($root . '/core');
                                if (!$sanity) {
                                    $failed = true;
                                    $log[] = '更新失败：安装包内容与系统结构不符，已中止替换（站点文件未改动）。';
                                } else {
                                    $adminDir = update_admin_dir();
                                    $log[] = '开始覆盖替换文件（后台目录：' . $adminDir . '/，自动保护 config.php / install.lock / runtime / uploads）…';
                                    [$copied, $skipped] = update_copy_tree($root, ROOT_PATH, $adminDir, $log);
                                    if ($copied === 0) {
                                        $failed = true;
                                        $log[] = '更新失败：未能替换任何文件，请检查站点根目录写权限。';
                                    } else {
                                        $log[] = '文件替换完成：更新 ' . $copied . ' 个文件' . ($skipped > 0 ? '，保护/跳过 ' . $skipped . ' 项' : '') . '。';
                                        // 清理结构迁移锁，确保新版 schema 增量迁移可重新执行
                                        foreach (glob(RUNTIME_PATH . '/.schema_*.lock') ?: [] as $lock) {
                                            @unlink($lock);
                                        }
                                        $done = true;
                                    }
                                }
                            }
                        }
                    }
                    // 更新完成后自动删除下载的安装包与解压临时目录
                    if (is_file($pkgFile)) {
                        @unlink($pkgFile);
                        $log[] = '已自动删除下载的安装包。';
                    }
                    if (is_dir($extDir)) {
                        update_rmdir($extDir);
                        $log[] = '已清理解压临时目录。';
                    }
                    @unlink($lockFile);
                }
            }
        }
    }

    // 清除更新检测缓存，促使下次进入控制台重新检测版本
    if ($done || $failed) {
        @unlink(RUNTIME_PATH . '/update_check.json');
    }
    if ($done) {
        audit('system', 'online_update', 1, (int)$admin['id'], '一键在线更新：V' . PRODUCT_VERSION . ' → V' . ($info['version'] ?? '?') . '，更新 ' . $copied . ' 个文件');
        $log[] = '✅ 更新完成！新版本 V' . ($info['version'] ?? '') . ' 将在下次刷新页面时生效，请刷新浏览器（Ctrl+F5）确认。';
    } elseif ($failed) {
        $log[] = '❌ 更新未完成，站点文件保持原状或部分更新，请根据上方日志排查后重试。';
    }
}

admin_header('在线更新', 'settings', $admin);
$flash = flash_take();
if ($flash): ?>
<div class="alert alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>">
    <img src="../assets/svg/<?php echo $flash['type'] === 'ok' ? 'check-circle' : 'warn-circle'; ?>.svg" alt="">
    <span><?php echo e($flash['msg']); ?></span>
</div>
<?php endif; ?>

<div class="settings-head">
    <div>
        <h1 class="settings-title">在线更新</h1>
        <p class="settings-sub">自动下载最新安装包并替换系统文件，更新完成后自动清理安装包</p>
    </div>
</div>

<section class="card" style="max-width:860px">
    <div class="page-actions">
        <div>
            <h2 class="card-title"><?php echo $updateNew ? '🚀 发现新版本' : '版本状态'; ?></h2>
            <p class="card-desc" style="margin-bottom:0">
                <?php if ($updateOk): ?>
                    当前版本 <b>V<?php echo e(PRODUCT_VERSION); ?></b>（内核 V<?php echo e(SYS_VERSION); ?>）
                    · GitHub 最新 Release：<b>V<?php echo e((string)$info['version']); ?></b>
                    <?php if (($info['published_at'] ?? '') !== ''): ?>
                        <span style="color:var(--text-third,#86909C)">（发布于 <?php echo e(substr((string)$info['published_at'], 0, 10)); ?>）</span>
                    <?php endif; ?>
                    <?php if ($updateNew): ?>
                        <br><b style="color:#D25F00">远程版本高于本地，可一键在线更新。</b>
                        <?php if ($zipSrc['name'] !== ''): ?>
                            <br>安装包：<?php echo e($zipSrc['name']); ?><?php echo $zipSrc['size'] > 0 ? '（' . e((string)round($zipSrc['size'] / 1048576, 2)) . ' MB）' : ''; ?><?php echo $zipSrc['from'] === 'source' ? '（无 Release 资产，自动回退为仓库源码包）' : ''; ?>
                        <?php endif; ?>
                        <?php if (($info['name'] ?? '') !== ''): ?>
                            <br>Release：<?php echo e((string)$info['name']); ?>
                        <?php endif; ?>
                        <?php if (($info['summary'] ?? '') !== ''): ?>
                        <span style="display:block;margin-top:4px;white-space:pre-wrap"><?php echo e(mb_substr(trim((string)$info['summary']), 0, 400)); ?><?php echo mb_strlen((string)$info['summary']) > 400 ? '…' : ''; ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        · 当前已是最新版本 ✓
                    <?php endif; ?>
                <?php else: ?>
                    当前版本 <b>V<?php echo e(PRODUCT_VERSION); ?></b>（内核 V<?php echo e(SYS_VERSION); ?>）
                    · 暂未能连接 GitHub Releases 检测最新版本，请稍后重试或前往 <a href="dashboard.php">控制台</a> 手动「检查更新」。
                <?php endif; ?>
            </p>
        </div>
        <div class="spacer"></div>
        <?php if (!$updateNew): ?>
        <a class="btn-plain" href="dashboard.php" style="text-decoration:none;line-height:32px">返回控制台</a>
        <?php endif; ?>
    </div>

    <?php if ($updateNew): ?>
    <div class="alert alert-warn" style="margin-bottom:18px">
        <img src="../assets/svg/warn-circle.svg" alt="">
        <span>更新前请确认已完成<b>数据备份</b>（系统设置 → 数据备份）。更新过程会自动覆盖系统文件并保护 <code>config.php</code>、<code>install.lock</code>、<code>runtime/</code>、<code>uploads/</code> 与您自定义的后台目录；第三方插件与站点数据不受影响。更新完成后安装包与临时文件会自动删除。</span>
    </div>
    <form method="post" data-confirm="确认开始在线更新吗？更新期间请勿关闭页面。">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="start_update">
        <button class="btn-primary" type="submit" <?php echo $zipSrc['url'] === '' ? 'disabled' : ''; ?>><?php echo $zipSrc['url'] === '' ? '暂无可用安装包' : ('一键更新至 V' . e((string)$info['version'])); ?></button>
    </form>
    <?php endif; ?>
</section>

<?php if ($log): ?>
<section class="card" style="max-width:860px">
    <h2 class="card-title">更新日志</h2>
    <div style="background:var(--bg,#F7F8FA);border:1px solid var(--border,#E5E6EB);border-radius:6px;padding:14px 18px;font-family:Consolas,Menlo,monospace;font-size:13px;line-height:22px;color:var(--text-2,#4E5969);max-height:420px;overflow:auto;white-space:pre-wrap;word-break:break-all">
        <?php echo e(implode("\n", $log)); ?>
    </div>
    <?php if ($done): ?>
    <div style="margin-top:14px;display:flex;gap:10px">
        <a class="btn-primary" href="dashboard.php" style="text-decoration:none;line-height:36px;padding:0 18px;display:inline-flex;align-items:center">返回控制台</a>
        <a class="btn-plain" href="about.php" style="text-decoration:none;line-height:36px">查看系统信息</a>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
