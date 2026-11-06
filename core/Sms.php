<?php
// 短信引擎：短信宝 / 赛邮 SUBMAIL 对接、场景短信验证码
// 配置项存于 sys_config：sms_provider（smsbao/submail）、sms_sign（短信签名）、
// smsbao_user / smsbao_pass、submail_appid / submail_appkey / submail_project、
// register_phone_enabled（手机号注册开关）
declare(strict_types=1);

function sms_cfg(string $key, string $default = ''): string
{
    return cfg($key, $default);
}

// 当前短信服务商：'' 未配置 | 'smsbao' 短信宝 | 'submail' 赛邮 SUBMAIL
function sms_provider(): string
{
    $p = strtolower(trim((string)cfg('sms_provider', '')));
    return in_array($p, ['smsbao', 'submail'], true) ? $p : '';
}

function sms_sign(): string
{
    return trim((string)cfg('sms_sign', ''));
}

// 服务商账号参数是否已配置完整
function sms_provider_ready(): bool
{
    switch (sms_provider()) {
        case 'smsbao':
            return trim((string)cfg('smsbao_user', '')) !== '' && trim((string)cfg('smsbao_pass', '')) !== '';
        case 'submail':
            return trim((string)cfg('submail_appid', '')) !== '' && trim((string)cfg('submail_appkey', '')) !== '';
        default:
            return false;
    }
}

// 手机号注册是否可用（开关开启且服务商配置完整）
function sms_code_available(): bool
{
    return cfg('register_phone_enabled', '0') === '1' && sms_provider_ready();
}

function sms_is_phone(string $phone): bool
{
    return preg_match('/^1[3-9]\d{9}$/', $phone) === 1;
}

function sms_mask_phone(string $phone): string
{
    return strlen($phone) === 11 ? substr($phone, 0, 3) . '****' . substr($phone, 7) : $phone;
}

function sms_error_log(string $msg): void
{
    @file_put_contents(RUNTIME_PATH . '/sms_error.log', date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}

// 组装短信正文（验证码场景，短信宝与赛邮内容模式通用，须带签名前缀）
function sms_build_content(string $code): string
{
    $sign = sms_sign();
    $prefix = $sign !== '' ? '【' . $sign . '】' : '';
    return $prefix . '您的验证码:' . $code . '，10分钟内有效，请勿泄露给他人。';
}

// 短信宝发送：GET https://api.smsbao.com/sms?u=账号&p=md5密码&m=手机号&c=内容
// 成功返回 0，其余为错误码
function sms_send_smsbao(string $phone, string $content): array
{
    $query = http_build_query([
        'u' => trim((string)cfg('smsbao_user', '')),
        'p' => md5(trim((string)cfg('smsbao_pass', ''))),
        'm' => $phone,
        'c' => $content,
    ]);
    $resp = http_get('https://api.smsbao.com/sms?' . $query, 10);
    if ($resp === false || trim((string)$resp) === '') {
        return ['ok' => false, 'msg' => '短信网关无响应，请稍后再试。'];
    }
    $code = trim((string)$resp);
    $map = [
        '0'  => '',
        '30' => '账号密码错误',
        '40' => '短信宝账号不存在',
        '41' => '短信宝余额不足',
        '42' => '短信宝账号已过期',
        '43' => '短信宝 IP 地址限制',
        '44' => '短信宝账号已被禁用',
        '50' => '短信内容含有敏感词',
        '51' => '手机号码不正确',
    ];
    if ($code === '0') {
        return ['ok' => true, 'msg' => ''];
    }
    return ['ok' => false, 'msg' => '短信发送失败：' . ($map[$code] ?? '网关返回错误码 ' . $code)];
}

// 赛邮 SUBMAIL 发送：
//  - 配置了模板 ID（submail_project）→ sms/xsend 模板模式（模板内使用 @var(code) 文本变量）
//  - 未配置模板 ID → sms/send 内容模式（正文须含签名）
function sms_send_submail(string $phone, string $content, string $code = ''): array
{
    $post = [
        'appid'     => trim((string)cfg('submail_appid', '')),
        'to'        => $phone,
        'signature' => trim((string)cfg('submail_appkey', '')),
    ];
    $project = trim((string)cfg('submail_project', ''));
    if ($project !== '' && $code !== '') {
        $post['project'] = $project;
        $post['vars']    = json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);
        $url = 'https://api-v4.mysubmail.com/sms/xsend.json';
    } else {
        $post['content'] = $content;
        $url = 'https://api-v4.mysubmail.com/sms/send.json';
    }
    $resp = http_post($url, http_build_query($post), ['Content-Type: application/x-www-form-urlencoded'], 10);
    if ($resp === false || trim((string)$resp) === '') {
        return ['ok' => false, 'msg' => '短信网关无响应，请稍后再试。'];
    }
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) {
        return ['ok' => false, 'msg' => '短信网关返回异常，请检查服务商配置。'];
    }
    if (($j['status'] ?? '') === 'success') {
        return ['ok' => true, 'msg' => ''];
    }
    $msg = trim((string)($j['msg'] ?? ''));
    if ($msg === '') {
        $msg = '网关返回错误码 ' . (string)($j['code'] ?? '未知');
    }
    return ['ok' => false, 'msg' => '短信发送失败：' . $msg];
}

// 统一发送入口：按配置分发到对应服务商
function sms_send_text(string $phone, string $content, string $code = ''): array
{
    if (!sms_is_phone($phone)) {
        return ['ok' => false, 'msg' => '手机号格式不正确。'];
    }
    $provider = sms_provider();
    if ($provider === '') {
        return ['ok' => false, 'msg' => '短信服务商未配置，请联系管理员。'];
    }
    try {
        return $provider === 'smsbao'
            ? sms_send_smsbao($phone, $content)
            : sms_send_submail($phone, $content, $code);
    } catch (Throwable $ex) {
        sms_error_log('provider=' . $provider . ' phone=' . sms_mask_phone($phone) . ' 异常：' . $ex->getMessage());
        return ['ok' => false, 'msg' => '短信发送异常，请稍后再试。'];
    }
}

// 发送场景短信验证码（60 秒重发限频，10 分钟有效，会话内存储、校验后销毁）
function sms_code_send(string $scene, string $phone): array
{
    if (!sms_is_phone($phone)) {
        return ['ok' => false, 'msg' => '请先正确填写手机号。'];
    }
    if (!sms_provider_ready()) {
        return ['ok' => false, 'msg' => '短信服务未配置，请联系管理员。'];
    }
    $_SESSION['sms_code'] ??= [];
    $box = &$_SESSION['sms_code'][$scene];
    if (isset($box['last']) && time() - (int)$box['last'] < 60) {
        $wait = 60 - (time() - (int)$box['last']);
        return ['ok' => false, 'msg' => '发送过于频繁，请 ' . $wait . ' 秒后再试。'];
    }
    $code = (string)random_int(100000, 999999);
    $res = sms_send_text($phone, sms_build_content($code), $code);
    if (!$res['ok']) {
        sms_error_log('scene=' . $scene . ' phone=' . sms_mask_phone($phone) . ' ' . $res['msg']);
        return ['ok' => false, 'msg' => $res['msg']];
    }
    $box = ['code' => $code, 'phone' => $phone, 'exp' => time() + 600, 'last' => time()];
    return ['ok' => true, 'msg' => '验证码已发送，请查收短信（10 分钟内有效）。'];
}

// 校验场景短信验证码（验证通过即销毁，一次性使用）
function sms_code_verify(string $scene, string $phone, string $input): bool
{
    $box = $_SESSION['sms_code'][$scene] ?? null;
    if (!is_array($box) || (int)$box['exp'] < time()) {
        return false;
    }
    if (!hash_equals((string)$box['phone'], $phone) || !hash_equals((string)$box['code'], trim($input))) {
        return false;
    }
    unset($_SESSION['sms_code'][$scene]);
    return true;
}
