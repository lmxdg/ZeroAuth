<?php
// 系统官网首页（域名授权公开查询）
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/core/Security.php';

if (!is_file(ROOT_PATH . '/config.php')) {
    redirect('install/');
}

config();

if (!is_dir(RUNTIME_PATH)) {
    @mkdir(RUNTIME_PATH, 0755, true);
}

$tplCode = '';
$tplDir  = '';
require_once ROOT_PATH . '/core/PluginSys.php';
// 扫描全部「template」类插件，sort 数值越小优先级越高
$tplCandidates = [];
foreach (plugin_scan() as $pCode => $pMeta) {
    if (($pMeta['cat'] ?? '') !== 'template' || !plugin_enabled($pCode)) {
        continue;
    }
    $pDir = (string)($pMeta['dir'] ?? '');
    if ($pDir === '' || !is_file(ROOT_PATH . '/plugin/' . $pDir . '/home.php')) {
        continue;
    }
    $tplCandidates[] = ['code' => (string)$pCode, 'dir' => $pDir, 'sort' => (int)($pMeta['sort'] ?? 100)];
}
usort($tplCandidates, fn($a, $b) => $a['sort'] <=> $b['sort']);
foreach ($tplCandidates as $tpl) {
    $tplCode = $tpl['code'];
    $tplDir  = $tpl['dir'];
    break;
}

$admin_dir = (string)(config()['admin_dir'] ?? 'admin');

$query_domain = '';
$result = null; 
if (isset($_GET['q'])) {
    $query_domain = trim((string)$_GET['q']);

    $hit = sec_rate_hit('home_domain_query', client_ip(), 30);
    if ($hit > 30) {
        $result = ['level' => 'bad', 'main' => '查询过于频繁', 'sub' => '请稍后再试（每分钟最多 30 次）。'];
        $query_domain = '';
    } else {
        $domain = sec_normalize_domain($query_domain);
        if ($domain === '') {
            $result = ['level' => 'warn', 'main' => '域名格式无效', 'sub' => '请输入合法的域名，如 example.com'];
        } else {
            try {
                $stmt = db()->prepare(
                    'SELECT `ld`.`status` AS `d_status`,`l`.`type`,`l`.`status` AS `l_status`,`l`.`expire_time`,`l`.`total_count`,`l`.`used_count`,`a`.`name` AS `app_name` '
                    . 'FROM `license_domain` `ld` '
                    . 'INNER JOIN `license` `l` ON `l`.`id` = `ld`.`license_id` '
                    . 'LEFT JOIN `app` `a` ON `a`.`id` = `l`.`app_id` '
                    . 'WHERE `ld`.`domain` = ? LIMIT 1'
                );
                $stmt->execute([$domain]);
                $row = $stmt->fetch();

                $type_names = [1 => '限时授权', 2 => '计次授权', 3 => '永久授权'];
                $now = time();

                if (!$row) {
                    $result = ['level' => 'bad', 'main' => '未查询到授权记录', 'sub' => '域名 ' . $domain . ' 尚未绑定任何授权。'];
                } elseif ((int)$row['d_status'] === 2) {
                    $result = ['level' => 'bad', 'main' => '该域名的授权已被封禁', 'sub' => '域名 ' . $domain . ' 处于封禁状态，如有疑问请联系管理员。'];
                } elseif ((int)$row['l_status'] === 2) {
                    $result = ['level' => 'bad', 'main' => '授权卡密已被封禁', 'sub' => '域名 ' . $domain . ' 绑定的授权处于封禁状态，如有疑问请联系管理员。'];
                } elseif ((int)$row['type'] === 1 && (int)$row['expire_time'] > 0 && (int)$row['expire_time'] < $now) {
                    $result = ['level' => 'warn', 'main' => '授权已过期', 'sub' => '域名 ' . $domain . ' 的限时授权已于 ' . date('Y-m-d H:i', (int)$row['expire_time']) . ' 过期，请续期后使用。'];
                } elseif ((int)$row['type'] === 2 && (int)$row['total_count'] > 0 && (int)$row['used_count'] >= (int)$row['total_count']) {
                    $result = ['level' => 'warn', 'main' => '授权次数已用尽', 'sub' => '域名 ' . $domain . ' 的计次授权已全部使用（' . (int)$row['used_count'] . '/' . (int)$row['total_count'] . '）。'];
                } else {
                    $detail = '授权类型：' . ($type_names[(int)$row['type']] ?? '未知');
                    if (!empty($row['app_name'])) {
                        $detail .= ' · 所属应用：' . $row['app_name'];
                    }
                    if ((int)$row['type'] === 1 && (int)$row['expire_time'] > 0) {
                        $detail .= ' · 有效期至 ' . date('Y-m-d', (int)$row['expire_time']);
                    } elseif ((int)$row['type'] === 2 && (int)$row['total_count'] > 0) {
                        $detail .= ' · 剩余次数 ' . max(0, (int)$row['total_count'] - (int)$row['used_count']) . ' 次';
                    }
                    $result = ['level' => 'ok', 'main' => '域名 ' . $domain . ' 已授权，状态正常', 'sub' => $detail];
                }
            } catch (Throwable $ex) {
                $result = ['level' => 'bad', 'main' => '查询失败', 'sub' => '系统暂时无法完成查询，请稍后再试。'];
            }
        }
    }
}

if ($tplDir === '') {
    http_response_code(503);
    exit('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>站点维护中</title></head>'
        . '<body style="font-family:sans-serif;text-align:center;padding-top:120px;color:#4E5969">'
        . '<h2 style="color:#1D2129">站点维护中</h2><p>首页模板插件未启用，请管理员在后台「应用商店」中启用。</p></body></html>');
}

require ROOT_PATH . '/plugin/' . $tplDir . '/home.php';
