<?php
// 统一验证码抽象：Turnstile（插件）/ 极验（插件）/ 传统图形验证码
// 场景：login 登录 / register 注册（受后台「注册验证码」开关控制）/ forgot 找回密码 / profile 敏感操作
declare(strict_types=1);

require_once ROOT_PATH . '/core/Geetest.php';
require_once ROOT_PATH . '/core/PluginSys.php';

/* ================= Cloudflare Turnstile（独立插件） ================= */

function turnstile_site_key(): string
{
    return function_exists('plugin_cfg_get') ? trim(plugin_cfg_get('turnstile', 'turnstile_site_key', '')) : '';
}

function turnstile_secret_key(): string
{
    return function_exists('plugin_cfg_get') ? trim(plugin_cfg_get('turnstile', 'turnstile_secret_key', '')) : '';
}

function turnstile_enabled(): bool
{
    if (!function_exists('plugin_status_enabled') || !plugin_status_enabled('turnstile')) {
        return false;
    }
    return turnstile_site_key() !== '' && turnstile_secret_key() !== '';
}

function turnstile_validate(string $token): bool
{
    $secret = turnstile_secret_key();
    if ($secret === '' || $token === '') {
        return false;
    }
    $resp = http_post(
        'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => client_ip(),
        ]),
        ['Content-Type: application/x-www-form-urlencoded'],
        8
    );
    if ($resp === false) {
        return false;
    }
    $data = json_decode((string)$resp, true);
    return is_array($data) && ($data['success'] ?? false) === true;
}

function turnstile_script_html(): string
{
    if (!turnstile_enabled()) {
        return '';
    }
    return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
}

function turnstile_widget_html(string $theme = 'auto'): string
{
    if (!turnstile_enabled()) {
        return '';
    }
    return '<div class="cf-turnstile" data-sitekey="' . e(turnstile_site_key()) . '" data-theme="' . e($theme) . '" data-language="zh-cn"></div>';
}

/* ================= 统一场景验证 ================= */

// 当前验证码模式：turnstile / geetest / image
function captcha_mode(): string
{
    if (turnstile_enabled()) {
        return 'turnstile';
    }
    if (geetest_enabled()) {
        return 'geetest';
    }
    return 'image';
}

// 场景是否需要验证码（注册场景受后台「注册验证码」开关控制）
function captcha_scene_on(string $scene): bool
{
    if ($scene === 'register') {
        return cfg('register_captcha', '1') === '1';
    }
    return true;
}

// 对指定场景执行验证，返回 [是否通过, 错误提示]；$forceMode 可强制指定验证方式（如注册页极验降级为图形验证码）
function captcha_verify_scene(string $scene, array $post, ?string $forceMode = null): array
{
    if (!captcha_scene_on($scene)) {
        return [true, ''];
    }
    $mode = $forceMode ?? captcha_mode();

    if ($mode === 'turnstile') {
        $ok = turnstile_validate((string)($post['cf-turnstile-response'] ?? ''));
        return $ok ? [true, ''] : [false, '人机验证未通过，请完成 Turnstile 验证后重试。'];
    }

    if ($mode === 'geetest') {
        $ok = geetest_validate(
            (string)($post['gt_lot_number'] ?? ''),
            (string)($post['gt_captcha_output'] ?? ''),
            (string)($post['gt_pass_token'] ?? ''),
            (string)($post['gt_gen_time'] ?? '')
        );
        return $ok ? [true, ''] : [false, '行为验证未通过，请重新验证。'];
    }

    $captcha     = trim((string)($post['captcha'] ?? ''));
    $sessCaptcha = (string)($_SESSION['captcha'] ?? '');
    unset($_SESSION['captcha']);
    $ok = $captcha !== '' && $sessCaptcha !== '' && hash_equals(strtoupper($sessCaptcha), strtoupper($captcha));
    return $ok ? [true, ''] : [false, '验证码不正确，请重新输入。'];
}
