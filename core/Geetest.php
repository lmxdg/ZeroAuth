<?php
// 极验行为验证封装（独立插件驱动，兼容旧版 sys_config 配置）
declare(strict_types=1);

// 极验插件是否激活：插件启用 或 旧版配置 geetest_enabled=1（升级兼容）
function geetest_plugin_on(): bool
{
    if (function_exists('plugin_status_enabled') && plugin_status_enabled('geetest')) {
        return true;
    }
    return cfg('geetest_enabled', '0') === '1';
}

function geetest_enabled(): bool
{
    if (!geetest_plugin_on()) {
        return false;
    }
    $captchaId  = trim(cfg('geetest_captcha_id', ''));
    $captchaKey = '';
    if (function_exists('plugin_cfg_get')) {
        $captchaKey = trim(plugin_cfg_get('geetest', 'geetest_captcha_key', ''));
    }
    if ($captchaKey === '') {
        $captchaKey = trim(cfg('geetest_captcha_key', ''));
    }
    return $captchaId !== '' && $captchaKey !== '';
}

function geetest_validate(string $lotNumber, string $captchaOutput, string $passToken, string $genTime): bool
{
    $captchaId  = trim(cfg('geetest_captcha_id', ''));
    $captchaKey = '';
    if (function_exists('plugin_cfg_get')) {
        $captchaKey = trim(plugin_cfg_get('geetest', 'geetest_captcha_key', ''));
    }
    if ($captchaKey === '') {
        $captchaKey = trim(cfg('geetest_captcha_key', ''));
    }
    if ($captchaId === '' || $captchaKey === ''
        || $lotNumber === '' || $captchaOutput === '' || $passToken === '' || $genTime === '') {
        return false;
    }

    $query = http_build_query([
        'lot_number'     => $lotNumber,
        'captcha_output' => $captchaOutput,
        'pass_token'     => $passToken,
        'gen_time'       => $genTime,
        'captcha_id'     => $captchaId,
        'sign_token'     => hash_hmac('sha256', $lotNumber, $captchaKey),
    ]);
    $resp = http_get('https://gcaptcha4.geetest.com/validate?' . $query, 5);
    if ($resp === false) {
        return false;
    }
    $data = json_decode($resp, true);
    return is_array($data) && ($data['result'] ?? '') === 'success';
}
