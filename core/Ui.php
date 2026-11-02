<?php
// 通用 UI 组件辅助
declare(strict_types=1);

/**
 * 菜单 / 功能图标配色。保证深浅色模式下都有足够对比度。
 */
function ui_color(string $key): string
{
    static $map = [
        // 通用
        'dashboard' => '#2563EB',
        'store' => '#F97316',
        'report' => '#8B5CF6',
        'app' => '#0EA5E9',
        'package' => '#06B6D4',
        'license' => '#F59E0B',
        'order' => '#10B981',
        'discount' => '#EC4899',
        'level' => '#8B5CF6',
        'finance' => '#22C55E',
        'user' => '#6366F1',
        'agent' => '#F97316',
        'realname' => '#14B8A6',
        'withdraw' => '#EAB308',
        'ticket' => '#F43F5E',
        'notice' => '#EF4444',
        'app_ver' => '#3B82F6',
        'sdk' => '#A855F7',
        'docs_api' => '#0891B2',
        'sdk_logs' => '#64748B',
        'cloud_var' => '#0EA5E9',
        'lic_over' => '#F59E0B',
        'lic_list' => '#F97316',
        'verify_logs' => '#64748B',
        'piracy' => '#DC2626',
        'ip_ban' => '#B91C1C',
        'blacklist' => '#7C3AED',
        'alert' => '#F59E0B',
        'audit' => '#475569',
        'security' => '#16A34A',
        'settings' => '#64748B',
        'health' => '#10B981',
        'backup' => '#0EA5E9',
        'profile' => '#6366F1',
        'mail_log' => '#8B5CF6',
        'about' => '#64748B',
        // 用户 / 代理商
        'licenses' => '#F59E0B',
        'redeem' => '#8B5CF6',
        'buy' => '#10B981',
        'tickets' => '#F43F5E',
        'agent_open' => '#F97316',
        'license_manage' => '#F59E0B',
        'users' => '#6366F1',
        // 分组
        'group-biz' => '#F59E0B',
        'group-user' => '#6366F1',
        'group-dev' => '#8B5CF6',
        'group-safe' => '#16A34A',
        'group-sys' => '#64748B',
        // 顶栏工具按钮
        'top_collapse' => '#64748B',
        'top_quick' => '#2563EB',
        'top_reload' => '#0891B2',
        'top_fullscreen' => '#8B5CF6',
        'top_lang' => '#F59E0B',
        'top_settings' => '#10B981',
        'top_theme' => '#F97316',
        'top_menu' => '#2563EB',
    ];
    return $map[$key] ?? '#64748B';
}

/**
 * 生成彩色 SVG 图标的 data URI（同一图标+颜色只读取一次源文件）。
 */
function ui_icon_src(string $name, string $key): string
{
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name) ?? '';
    if ($name === '') {
        return '';
    }

    static $cache = [];
    $color = ui_color($key);
    $cacheKey = $name . '|' . $color;

    if (!isset($cache[$cacheKey])) {
        $file = dirname(__DIR__) . '/assets/svg/' . $name . '.svg';
        $svg  = is_file($file) ? (string)@file_get_contents($file) : '';
        if ($svg === '') {
            // 资源缺失时退回一个通用方块图标，避免菜单出现空洞
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16">'
                . '<rect x="2.5" y="2.5" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.4"/></svg>';
        }
        $svg = str_replace('currentColor', $color, $svg);
        $cache[$cacheKey] = 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    return $cache[$cacheKey];
}

/**
 * 输出彩色 SVG 图标。
 *
 * 直接读取 assets/svg 源文件，把 currentColor 替换为映射颜色后以内联
 * data URI 图片输出。相比 CSS mask 方案，不依赖相对路径解析，深浅色模式
 * 下都能稳定显示品牌色，也不会产生额外 HTTP 请求。
 *
 * @param array<string,string> $attrs 追加到 img 上的属性（如 id、data-src-*）
 */
function ui_icon(string $name, string $key, string $class = 'side-icon', array $attrs = []): string
{
    $src = ui_icon_src($name, $key);
    if ($src === '') {
        return '';
    }

    $attrStr = '';
    foreach ($attrs as $k => $v) {
        $k = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$k) ?? '';
        if ($k === '') {
            continue;
        }
        $attrStr .= ' ' . $k . '="' . e((string)$v) . '"';
    }

    return '<img class="ui-ico ' . e($class) . '" src="' . $src . '" alt="" aria-hidden="true"' . $attrStr . '>';
}
