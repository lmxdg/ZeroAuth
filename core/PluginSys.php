<?php
// 插件系统：文件驱动扫描/状态/配置存取
declare(strict_types=1);

function &plugin_runtime(): array
{
    static $rt = null;
    if ($rt === null) {
        $rt = [];
        try {
            $rows = db()->query('SELECT `code`,`status`,`cfg` FROM `plugin`')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $cfg = [];
                if ((string)$r['cfg'] !== '') {
                    $j = json_decode((string)$r['cfg'], true);
                    if (is_array($j)) {
                        $cfg = $j;
                    }
                }
                $rt[(string)$r['code']] = ['status' => (int)$r['status'], 'cfg' => $cfg];
            }
        } catch (Throwable $ex) {
            $rt = [];
        }
    }
    return $rt;
}

function plugin_runtime_save(array $rt): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO `plugin` (`code`,`status`,`cfg`,`updated_at`) VALUES (?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE `status`=VALUES(`status`),`cfg`=VALUES(`cfg`),`updated_at`=VALUES(`updated_at`)'
        );
        foreach ($rt as $code => $row) {
            $cfg = (array)($row['cfg'] ?? []);
            $stmt->execute([
                (string)$code,
                (int)($row['status'] ?? 3),
                $cfg === [] ? '' : json_encode($cfg, JSON_UNESCAPED_UNICODE),
                time(),
            ]);
        }
    } catch (Throwable $ex) {
        @file_put_contents(RUNTIME_PATH . '/schema_upgrade.log', date('Y-m-d H:i:s') . ' plugin状态写入失败 ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

function plugin_scan(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $list = [];
    foreach (glob(ROOT_PATH . '/plugin/*/Plugin.php') ?: [] as $file) {
        $meta = require $file;
        if (is_array($meta) && !empty($meta['code'])) {
            $meta['dir'] = basename(dirname($file));   
            $list[$meta['code']] = $meta;
        }
    }
    return $list;
}

function plugin_field_is_secret(string $code, string $key): bool
{
    $scan = plugin_scan();
    if (!isset($scan[$code])) {
        return false;
    }
    foreach (($scan[$code]['config'] ?? []) as $f) {
        if ((string)($f['key'] ?? '') === $key) {
            return ($f['type'] ?? '') === 'password' || !empty($f['secret']);
        }
    }
    return false;
}

function plugin_secret_migrate(): void
{
    static $done = false;
    if ($done || !function_exists('set_cfg')) {
        return;
    }
    $done = true;
    $rt = &plugin_runtime();
    $changed = false;
    foreach (plugin_scan() as $code => $meta) {
        foreach (($meta['config'] ?? []) as $f) {
            $key = (string)($f['key'] ?? '');
            if ($key === '' || (($f['type'] ?? '') !== 'password' && empty($f['secret']))) {
                continue;
            }
            $val = (string)($rt[$code]['cfg'][$key] ?? '');
            if ($val !== '') {
                $cfgKey = 'plugin_cfg_' . $code . '_' . $key;
                if (cfg($cfgKey, '') === '') {
                    set_cfg($cfgKey, $val, '插件[' . $code . ']敏感配置（自动迁移）');
                }
                unset($rt[$code]['cfg'][$key]);
                $changed = true;
            }
        }
    }
    if ($changed) {
        plugin_runtime_save($rt);
    }
}

function plugin_all(): array
{
    plugin_secret_migrate();
    $rt = &plugin_runtime();
    $out = [];
    foreach (plugin_scan() as $code => $meta) {
        $meta['status'] = (int)($rt[$code]['status'] ?? (int)($meta['status'] ?? 3));
        $meta['cfg'] = (array)($rt[$code]['cfg'] ?? []);
        $out[$code] = $meta;
    }
    return $out;
}

function plugin_status_get(string $code): int
{
    $rt = &plugin_runtime();
    if (isset($rt[$code]['status'])) {
        return (int)$rt[$code]['status'];
    }
    $scan = plugin_scan();
    return isset($scan[$code]) ? (int)($scan[$code]['status'] ?? 3) : 3;
}

function plugin_status_enabled(string $code): bool
{
    $rt = &plugin_runtime();
    if (isset($rt[$code]['status'])) {
        return (int)$rt[$code]['status'] === 1;
    }
    $scan = plugin_scan();
    return isset($scan[$code]) && (int)($scan[$code]['status'] ?? 3) === 1;
}

function plugin_status_set(string $code, int $status): void
{
    $rt = &plugin_runtime();
    if (!isset($rt[$code])) {
        $rt[$code] = ['status' => $status, 'cfg' => []];
    } else {
        $rt[$code]['status'] = $status;
    }
    plugin_runtime_save($rt);
}

function plugin_cfg_get(string $code, string $key, string $default = ''): string
{
    plugin_secret_migrate();
    if (plugin_field_is_secret($code, $key)) {
        $v = cfg('plugin_cfg_' . $code . '_' . $key, '');
        return $v !== '' ? (string)$v : $default;
    }
    $rt = &plugin_runtime();
    return (string)($rt[$code]['cfg'][$key] ?? $default);
}

function plugin_cfg_set(string $code, array $kv): void
{
    plugin_secret_migrate();
    $rt = &plugin_runtime();
    if (!isset($rt[$code])) {
        $rt[$code] = ['status' => 1, 'cfg' => []];
    }
    if (!isset($rt[$code]['cfg']) || !is_array($rt[$code]['cfg'])) {
        $rt[$code]['cfg'] = [];
    }
    foreach ($kv as $k => $v) {
        if (plugin_field_is_secret($code, (string)$k)) {
            if (function_exists('set_cfg')) {
                set_cfg('plugin_cfg_' . $code . '_' . $k, (string)$v, '插件[' . $code . ']敏感配置');
            }
            unset($rt[$code]['cfg'][$k]);   
            continue;
        }
        $rt[$code]['cfg'][$k] = (string)$v;
    }
    plugin_runtime_save($rt);
}

function plugin_cfg_del(string $code, array $keys): void
{
    plugin_secret_migrate();
    $rt = &plugin_runtime();
    foreach ($keys as $k) {
        if (plugin_field_is_secret($code, (string)$k) && function_exists('set_cfg')) {
            set_cfg('plugin_cfg_' . $code . '_' . $k, '', '插件[' . $code . ']敏感配置');
        }
        if (isset($rt[$code]['cfg']) && is_array($rt[$code]['cfg'])) {
            unset($rt[$code]['cfg'][$k]);
        }
    }
    plugin_runtime_save($rt);
}
