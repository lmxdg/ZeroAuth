<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/PluginSys.php';
require dirname(__DIR__) . '/core/Mail.php';
require dirname(__DIR__) . '/core/layout_admin.php';

$admin = admin_require_login();
$error = '';
$okMsg = '';
$scenes = mail_scenes();

$reservedDirs = ['install', 'api', 'assets', 'user', 'agent', 'plugin', 'runtime', 'core', 'email_tpl', 'static', 'public', 'upload', 'uploads', 'data', 'config', 'admin_shell'];

function rewrite_config(string $newAdminDir): bool
{
    $cfg = config();
    $cfg['admin_dir'] = $newAdminDir;
    $export = "<?php\n// 自动生成于 " . date('Y-m-d H:i:s') . "\nreturn " . var_export($cfg, true) . ";\n";
    return @file_put_contents(ROOT_PATH . '/config.php', $export) !== false;
}

if (($_GET['act'] ?? '') === 'mail_preview') {
    $scene = (string)($_GET['scene'] ?? 'system');
    if (!isset($scenes[$scene])) {
        $scene = 'system';
    }
    echo mail_scene_render($scene, $scenes[$scene]['sample'])['html'];
    exit;
}

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'security') {
        $fields = [
            ['key' => 'secret_api_enabled',    'min' => 0,     'max' => 1],
            ['key' => 'secret_session_ttl',    'min' => 60,    'max' => 86400],
            ['key' => 'sdk_cache_ttl',         'min' => 60,    'max' => 86400],
            ['key' => 'secret_alert_threshold','min' => 10,    'max' => 100000],
            ['key' => 'sign_time_window',      'min' => 60,    'max' => 600],
            ['key' => 'max_login_fail',        'min' => 3,     'max' => 20],
        ];
        $remarks = [
            'secret_api_enabled'     => '密钥下发接口全局开关 1开启 0关闭',
            'secret_session_ttl'     => '密钥会话有效时长（秒），到期后 SDK 必须重新拉取密钥',
            'sdk_cache_ttl'          => 'SDK 客户端内存缓存 TTL（秒），仅内存缓存禁止落地存储',
            'secret_alert_threshold' => '密钥接口单IP高频告警阈值（次/分钟），超阈值写审计日志并发送告警邮件',
            'sign_time_window'       => 'HMAC 签名时间戳校验窗口（秒）',
            'max_login_fail'         => '后台登录最大失败次数（超过锁定15分钟）',
        ];
        foreach ($fields as $f) {
            $val = (int)($_POST[$f['key']] ?? 0);
            $val = max($f['min'], min($f['max'], $val));
            set_cfg($f['key'], (string)$val, $remarks[$f['key']]);
        }
        audit('config', 'security_update', 1, (int)$admin['id']);
        flash_set('ok', '安全参数已保存。');
        redirect('settings.php#security');
    }

    if ($action === 'verify') {
        set_cfg('trust_proxy_ip', (($_POST['trust_proxy_ip'] ?? '1') === '1') ? '1' : '0', '真实IP获取 1开启(解析CF/X-Forwarded-For等代理头) 0关闭(仅用REMOTE_ADDR)');
        set_cfg('ip_geo_enabled', (($_POST['ip_geo_enabled'] ?? '1') === '1') ? '1' : '0', 'IP归属地识别 1开启(调用ip9.com.cn自动识别访客省市/境外地区) 0关闭');
        $noticeUrl = trim((string)($_POST['remote_notice_url'] ?? ''));
        if ($noticeUrl !== '' && filter_var($noticeUrl, FILTER_VALIDATE_URL) === false) {
            $error = '远程公告地址格式不正确，请填写完整的 http(s) 地址。';
        } else {
            set_cfg('remote_notice_url', $noticeUrl, '远程公告地址(返回JSON数组,留空使用系统默认) - 控制台展示远程公告');
        }
        if ($error === '') {
            audit('config', 'verify_update', 1, (int)$admin['id'], '真实IP/IP归属地/远程公告设置已更新');
            flash_set('ok', '验证设置已保存。');
            redirect('settings.php#geetest');
        }
    }

    // 验证码设置：注册验证码开关 + 极验 / Cloudflare Turnstile 人机验证（V2.2.0 自应用商店迁移）
    if ($action === 'captcha_save') {
        set_cfg('register_captcha', (($_POST['register_captcha'] ?? '1') === '1') ? '1' : '0', '注册验证码开关 1开启 0关闭（开启时注册流程需通过当前验证方式）');
        plugin_status_set('turnstile', isset($_POST['turnstile_enabled']) ? 1 : 2);
        $tkv = ['turnstile_site_key' => trim((string)($_POST['turnstile_site_key'] ?? ''))];
        $tsk = trim((string)($_POST['turnstile_secret_key'] ?? ''));
        if ($tsk !== '') {
            $tkv['turnstile_secret_key'] = $tsk;
        }
        plugin_cfg_set('turnstile', $tkv);
        plugin_status_set('geetest', isset($_POST['geetest_enabled']) ? 1 : 2);
        $gkv = ['geetest_captcha_id' => trim((string)($_POST['geetest_captcha_id'] ?? ''))];
        $gkey = trim((string)($_POST['geetest_captcha_key'] ?? ''));
        if ($gkey !== '') {
            $gkv['geetest_captcha_key'] = $gkey;
        }
        plugin_cfg_set('geetest', $gkv);
        audit('config', 'captcha_update', 1, (int)$admin['id'], '验证码设置已更新（Turnstile：' . (isset($_POST['turnstile_enabled']) ? '启用' : '停用') . '，极验：' . (isset($_POST['geetest_enabled']) ? '启用' : '停用') . '）');
        flash_set('ok', '验证码设置已保存，保存即生效。');
        redirect('settings.php#captcha');
    }

    // 实名设置：内置实名认证 + 腾讯云人脸核身（V2.2.0 自应用商店迁移）
    if ($action === 'realname_save') {
        plugin_status_set('builtin', isset($_POST['builtin_enabled']) ? 1 : 2);
        plugin_status_set('tencent_faceid', isset($_POST['faceid_enabled']) ? 1 : 2);
        $fkv = [
            'secret_id'    => trim((string)($_POST['faceid_secret_id'] ?? '')),
            'rule_id'      => trim((string)($_POST['faceid_rule_id'] ?? '')),
            'auto_pass'    => isset($_POST['faceid_auto_pass']) ? '1' : '0',
            'require_name' => isset($_POST['faceid_require_name']) ? '1' : '0',
        ];
        $fkey = trim((string)($_POST['faceid_secret_key'] ?? ''));
        if ($fkey !== '') {
            $fkv['secret_key'] = $fkey;
        }
        plugin_cfg_set('tencent_faceid', $fkv);
        audit('config', 'realname_update', 1, (int)$admin['id'], '实名设置已更新（内置实名：' . (isset($_POST['builtin_enabled']) ? '启用' : '停用') . '，人脸核身：' . (isset($_POST['faceid_enabled']) ? '启用' : '停用') . '）');
        flash_set('ok', '实名设置已保存，保存即生效。');
        redirect('settings.php#realname');
    }

    // 短信设置：手机号注册开关 + 短信宝 / 赛邮 SUBMAIL 服务商配置
    if ($action === 'sms_save') {
        set_cfg('register_phone_enabled', (($_POST['register_phone_enabled'] ?? '0') === '1') ? '1' : '0', '手机号注册开关 1开启 0关闭（需同时配置短信服务商）');
        $provider = strtolower(trim((string)($_POST['sms_provider'] ?? '')));
        set_cfg('sms_provider', in_array($provider, ['smsbao', 'submail'], true) ? $provider : '', '短信服务商 smsbao=短信宝 submail=赛邮SUBMAIL 空=未配置');
        set_cfg('sms_sign', mb_substr(trim((string)($_POST['sms_sign'] ?? '')), 0, 16), '短信签名（如：短信宝，勿带【】书名号）');
        set_cfg('smsbao_user', trim((string)($_POST['smsbao_user'] ?? '')), '短信宝账号');
        $smsbaoPass = trim((string)($_POST['smsbao_pass'] ?? ''));
        if ($smsbaoPass !== '') {
            set_cfg('smsbao_pass', $smsbaoPass, '短信宝密码');
        }
        set_cfg('submail_appid', trim((string)($_POST['submail_appid'] ?? '')), '赛邮 SUBMAIL AppID');
        $submailKey = trim((string)($_POST['submail_appkey'] ?? ''));
        if ($submailKey !== '') {
            set_cfg('submail_appkey', $submailKey, '赛邮 SUBMAIL AppKey');
        }
        set_cfg('submail_project', trim((string)($_POST['submail_project'] ?? '')), '赛邮 SUBMAIL 模板ID（可选，配置后走 xsend 模板模式，模板需含 @var(code) 变量）');
        audit('config', 'sms_update', 1, (int)$admin['id'], '短信设置已更新（服务商：' . ($provider !== '' ? $provider : '未配置') . '）');
        flash_set('ok', '短信设置已保存，保存即生效。');
        redirect('settings.php#sms');
    }

    // 短信测试发送
    if ($action === 'sms_test') {
        $testPhone = trim((string)($_POST['sms_test_phone'] ?? ''));
        if (!sms_is_phone($testPhone)) {
            $error = '请输入正确的 11 位手机号。';
        } else {
            $res = sms_code_send('sms_test', $testPhone);
            flash_set($res['ok'] ? 'ok' : 'error', $res['msg']);
            redirect('settings.php#sms');
        }
    }

    if ($action === 'notice_publish') {
        $publish = (($_POST['remote_notice_publish'] ?? '1') === '1') ? '1' : '0';
        $content = trim((string)($_POST['remote_notice_content'] ?? '[]'));
        $decoded = json_decode($content === '' ? '[]' : $content, true);
        if (!is_array($decoded)) {
            $error = '公告列表不是合法的 JSON，请检查格式（示例见输入框下方说明）。';
        } elseif (count($decoded) > 10) {
            $error = '公告最多发布 10 条。';
        } else {
            set_cfg('remote_notice_publish', $publish, '远程公告分发 1开启(api/notice.php对外返回公告) 0关闭(返回空)');
            set_cfg('remote_notice_content', json_encode(array_values($decoded), JSON_UNESCAPED_UNICODE), '远程公告分发内容(JSON数组) - 下游系统通过 api/notice.php 拉取');
            set_cfg('remote_notice_updated', (string)time(), '远程公告最后更新时间');
            audit('config', 'notice_publish', 1, (int)$admin['id'], '远程公告分发设置已更新');
            flash_set('ok', '远程公告分发设置已保存，下游系统拉取 /api/notice.php 即可获取最新公告。');
            redirect('settings.php#notice-publish');
        }
    }

    if ($action === 'site') {
        $siteName = trim((string)($_POST['site_name'] ?? ''));
        $siteEmail = trim((string)($_POST['contact_email'] ?? ''));
        if ($siteName === '' || mb_strlen($siteName) > 32) {
            $error = '站点名称不能为空且不超过 32 字。';
        } elseif ($siteEmail !== '' && !filter_var($siteEmail, FILTER_VALIDATE_EMAIL)) {
            $error = '联系邮箱格式不正确。';
        } else {
            $rules = [
                'site_ico'  => ['exts' => ['ico', 'png'], 'max' => 3145728, 'label' => '网站图标', 'save' => 'favicon'],
                'site_logo' => ['exts' => ['png', 'jpg', 'jpeg', 'webp'], 'max' => 5242880, 'label' => '网站 Logo', 'save' => 'logo'],
            ];
            foreach ($rules as $field => $rule) {
                $f = $_FILES[$field] ?? null;
                if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ((int)$f['error'] !== UPLOAD_ERR_OK) {
                    $error = $rule['label'] . '上传失败（错误码 ' . (int)$f['error'] . '）。';
                    break;
                }
                if ((int)$f['size'] <= 0 || (int)$f['size'] > $rule['max']) {
                    $error = $rule['label'] . '大小需在 0 ~ ' . round($rule['max'] / 1024) . 'KB 之间。';
                    break;
                }
                $ext = strtolower((string)pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $rule['exts'], true)) {
                    $error = $rule['label'] . '仅支持 ' . implode(' / ', $rule['exts']) . ' 格式。';
                    break;
                }
                if ($ext !== 'ico') {
                    $imgInfo = @getimagesize($f['tmp_name']);
                    if ($imgInfo === false) {
                        $error = $rule['label'] . '内容不是有效图片。';
                        break;
                    }
                }
                $dir = ROOT_PATH . '/uploads/site';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                foreach (glob($dir . '/' . $rule['save'] . '.*') ?: [] as $old) {
                    @unlink($old);
                }
                $newName = $rule['save'] . '.' . $ext;
                if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $newName)) {
                    $error = $rule['label'] . '保存失败，请检查 uploads/site 目录权限。';
                    break;
                }
                @chmod($dir . '/' . $newName, 0644);
                set_cfg($field, '/uploads/site/' . $newName, $rule['label'] . '地址');
            }
        }
        if ($error === '') {
            $siteName = trim((string)($_POST['site_name'] ?? ''));
            set_cfg('site_name', $siteName, '站点名称');
            set_cfg('site_keywords', mb_substr(trim((string)($_POST['site_keywords'] ?? '')), 0, 200), '网站关键词（SEO）');
            set_cfg('site_description', mb_substr(trim((string)($_POST['site_description'] ?? '')), 0, 300), '网站简介（SEO 描述）');
            set_cfg('contact_qq', mb_substr(trim((string)($_POST['contact_qq'] ?? '')), 0, 20), '联系QQ号');
            set_cfg('contact_phone', mb_substr(trim((string)($_POST['contact_phone'] ?? '')), 0, 20), '联系电话');
            set_cfg('contact_email', mb_substr(trim((string)($_POST['contact_email'] ?? '')), 0, 128), '联系邮箱');
            set_cfg('contact_qq_group', mb_substr(trim((string)($_POST['contact_qq_group'] ?? '')), 0, 32), 'QQ群号');
            set_cfg('site_icp', mb_substr(trim((string)($_POST['site_icp'] ?? '')), 0, 100), '网站备案号');
            set_cfg('register_mode', (($_POST['register_mode'] ?? '1') === '1') ? '1' : '0', '用户注册方式 1开放自助注册 0仅管理员添加');
            audit('config', 'site_update', 1, (int)$admin['id'], '站点信息已更新');
            flash_set('ok', '站点信息已保存。');
            redirect('settings.php#site');
        }
    }

    if ($action === 'site_clear_asset') {
        $field = (($_POST['field'] ?? '') === 'site_ico') ? 'site_ico' : 'site_logo';
        $save = $field === 'site_ico' ? 'favicon' : 'logo';
        foreach (glob(ROOT_PATH . '/uploads/site/' . $save . '.*') ?: [] as $old) {
            @unlink($old);
        }
        set_cfg($field, '', $field === 'site_ico' ? '网站图标地址' : '网站 Logo 地址');
        audit('config', 'site_update', 1, (int)$admin['id'], ($field === 'site_ico' ? '网站图标' : '网站 Logo') . '已移除，恢复默认');
        flash_set('ok', ($field === 'site_ico' ? '网站图标' : '网站 Logo') . '已移除，恢复使用默认。');
        redirect('settings.php#site');
    }

    if ($action === 'rebate') {
        $rate = round((float)($_POST['invite_rebate_rate'] ?? 0), 2);
        if ($rate < 0 || $rate > 100) {
            $error = '邀请返利比例需在 0 ~ 100 之间。';
        } else {
            set_cfg('invite_rebate_enabled', isset($_POST['invite_rebate_enabled']) ? '1' : '0', '邀请返利开关 1开启 0关闭');
            set_cfg('invite_rebate_rate', (string)$rate, '邀请返利比例（%，按订单实付金额）');
            audit('config', 'invite_rebate', 1, (int)$admin['id'], '保存邀请返利配置（开关：' . (isset($_POST['invite_rebate_enabled']) ? '开' : '关') . '，比例：' . $rate . '%）');
            flash_set('ok', '邀请返利配置已保存。');
            redirect('settings.php#rebate');
        }
    }

    if ($action === 'mail_smtp') {
        $preset = trim((string)($_POST['smtp_preset'] ?? ''));
        set_cfg('email_enabled', isset($_POST['email_enabled']) ? '1' : '0', '邮件通知总开关 1开启 0关闭');
        set_cfg('smtp_host', trim((string)($_POST['smtp_host'] ?? '')), 'SMTP 服务器');
        set_cfg('smtp_port', (string)max(1, min(65535, (int)($_POST['smtp_port'] ?? 465))), 'SMTP 端口');
        set_cfg('smtp_user', trim((string)($_POST['smtp_user'] ?? '')), 'SMTP 账号');
        $smtpPass = trim((string)($_POST['smtp_pass'] ?? ''));
        if ($smtpPass !== '') {
            set_cfg('smtp_pass', $smtpPass, 'SMTP 授权码/密码');
        }
        set_cfg('smtp_from_name', trim((string)($_POST['smtp_from_name'] ?? '')) ?: '授权管理系统', '发件人名称');
        set_cfg('smtp_from_mail', trim((string)($_POST['smtp_from_mail'] ?? '')), '发件人邮箱（空=与 SMTP 账号一致）');
        set_cfg('smtp_secure', in_array($_POST['smtp_secure'] ?? 'ssl', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_secure'] : 'ssl', 'SMTP 加密方式 ssl/tls/none');
        set_cfg('alert_email', trim((string)($_POST['alert_email'] ?? '')), '管理员告警收件邮箱（多个逗号分隔）');
        set_cfg('email_notify_alert', isset($_POST['email_notify_alert']) ? '1' : '0', '告警邮件通知规则 1开启 0关闭');
        set_cfg('email_expire_enabled', isset($_POST['email_expire_enabled']) ? '1' : '0', '卡密到期邮件提醒开关 1开启 0关闭');
        set_cfg('email_expire_days', (string)max(0, min(90, (int)($_POST['email_expire_days'] ?? 3))), '卡密到期提醒提前天数（0=不提醒）');
        if (in_array($preset, ['qq', '163', '126', 'exmail', 'outlook', 'gmail', 'custom'], true)) {
            set_cfg('smtp_preset', $preset, '邮件服务商预设');
        }
        audit('config', 'mail_smtp', 1, (int)$admin['id'], '保存邮件服务商配置');
        flash_set('ok', '邮件服务商配置已保存。');
        redirect('settings.php#mail');
    }

    if ($action === 'mail_rule') {
        $scene = (string)($_POST['scene'] ?? '');
        if (isset($scenes[$scene])) {
            $enabled = (($_POST['enabled'] ?? '') === '1') ? '1' : '0';
            set_cfg($scenes[$scene]['rule'], $enabled, '通知规则 ' . $scenes[$scene]['name']);
            audit('config', 'mail_rule', 1, (int)$admin['id'], '通知规则 ' . $scenes[$scene]['name'] . ' → ' . ($enabled === '1' ? '开启' : '关闭'));
            flash_set('ok', '「' . $scenes[$scene]['name'] . '」通知规则已更新。');
        }
        redirect('settings.php#rules');
    }

    if ($action === 'mail_tpl') {
        $scene = (string)($_POST['scene'] ?? '');
        if (!isset($scenes[$scene])) {
            $error = '场景不存在。';
        } else {
            set_cfg('email_subj_' . $scene, trim((string)($_POST['subject'] ?? '')), '邮件自定义标题 ' . $scenes[$scene]['name']);
            set_cfg('email_tpl_' . $scene, (string)($_POST['tpl'] ?? ''), '邮件自定义H5模板 ' . $scenes[$scene]['name']);
            audit('config', 'mail_tpl', 1, (int)$admin['id'], '自定义邮件模板：' . $scenes[$scene]['name']);
            flash_set('ok', '「' . $scenes[$scene]['name'] . '」邮件模板已保存，已启用自定义内容。');
            redirect('settings.php?scene=' . e($scene) . '#mailtpl');
        }
    }

    if ($action === 'mail_tpl_reset') {
        $scene = (string)($_POST['scene'] ?? '');
        if (isset($scenes[$scene])) {
            set_cfg('email_subj_' . $scene, '', '邮件自定义标题 ' . $scenes[$scene]['name']);
            set_cfg('email_tpl_' . $scene, '', '邮件自定义H5模板 ' . $scenes[$scene]['name']);
            audit('config', 'mail_tpl_reset', 1, (int)$admin['id'], '恢复默认邮件模板：' . $scenes[$scene]['name']);
            flash_set('ok', '「' . $scenes[$scene]['name'] . '」已恢复为系统默认模板。');
            redirect('settings.php?scene=' . e($scene) . '#mailtpl');
        }
    }

    if ($action === 'mail_test') {
        $to = trim((string)($_POST['test_to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $error = '请输入合法的收件邮箱地址。';
        } else {
            $mail = mail_scene_render('system', ['title' => 'SMTP 配置测试邮件', 'content' => '恭喜！收到此邮件说明您的 SMTP 服务商配置正确，系统邮件可正常送达。', 'time' => date('Y-m-d H:i:s')]);
            $ok = mail_send($to, $mail['subject'], $mail['html']);
            flash_set($ok ? 'ok' : 'err', $ok ? '测试邮件已发送至 ' . $to . '，请查收（若未收到请检查垃圾箱或 SMTP 日志）。' : '测试邮件发送失败，请检查 SMTP 配置（可在邮件发送日志或 runtime/mail_error.log 查看原因）。');
            redirect('settings.php#mail');
        }
    }

    if ($action === 'rename_admin_dir') {
        $newDir = trim((string)($_POST['new_dir'] ?? ''));
        $oldDir = basename(__DIR__);
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,29}$/', $newDir)) {
            $error = '后台目录名需以字母开头，3~30位字母、数字或下划线。';
        } elseif (strcasecmp($newDir, $oldDir) === 0) {
            $error = '新目录名与当前目录名相同。';
        } elseif (in_array(strtolower($newDir), $reservedDirs, true)) {
            $error = '该目录名为系统保留目录，请更换。';
        } elseif (is_dir(ROOT_PATH . '/' . $newDir)) {
            $error = "根目录下已存在同名目录 {$newDir}/，请更换。";
        } elseif (!@rename(__DIR__, ROOT_PATH . '/' . $newDir)) {
            $error = '目录迁移失败，请检查根目录写权限（确认无进程占用该目录）。';
        } elseif (!rewrite_config($newDir)) {
            @rename(ROOT_PATH . '/' . $newDir, __DIR__);
            $error = '目录已迁移但 config.php 更新失败，已自动回滚，请检查文件写权限。';
        } else {
            audit('config', 'admin_dir_rename', 1, (int)$admin['id'], $oldDir . ' → ' . $newDir);
            if (is_file(ROOT_PATH . '/install.lock')) {
                $lock = (string)@file_get_contents(ROOT_PATH . '/install.lock');
                $lock = preg_replace('/^admin_dir:.*$/m', 'admin_dir: ' . $newDir, $lock);
                @file_put_contents(ROOT_PATH . '/install.lock', $lock);
            }
            flash_set('ok', "后台目录已迁移为 {$newDir}/，请使用新地址访问后台。");
            redirect($newDir . '/settings.php');
        }
    }
}

$editScene = (string)($_GET['scene'] ?? 'deliver');
if (!isset($scenes[$editScene])) {
    $editScene = 'deliver';
}
$curSubj = cfg('email_subj_' . $editScene, '');
$curTpl = cfg('email_tpl_' . $editScene, '');
$isCustom = $curSubj !== '' || $curTpl !== '';

$previewSample = $scenes[$editScene]['sample'];
$previewSample += ['siteName' => cfg('site_name', 'ZeroAuth'), 'year' => date('Y')];

$presets = [
    'qq'       => ['name' => 'QQ 邮箱', 'host' => 'smtp.qq.com', 'port' => 465, 'secure' => 'ssl'],
    '163'      => ['name' => '163 网易邮箱', 'host' => 'smtp.163.com', 'port' => 465, 'secure' => 'ssl'],
    '126'      => ['name' => '126 网易邮箱', 'host' => 'smtp.126.com', 'port' => 465, 'secure' => 'ssl'],
    'exmail'   => ['name' => '腾讯企业邮箱', 'host' => 'smtp.exmail.qq.com', 'port' => 465, 'secure' => 'ssl'],
    'outlook'  => ['name' => 'Outlook', 'host' => 'smtp.office365.com', 'port' => 587, 'secure' => 'tls'],
    'gmail'    => ['name' => 'Gmail', 'host' => 'smtp.gmail.com', 'port' => 465, 'secure' => 'ssl'],
    'custom'   => ['name' => '自定义服务商', 'host' => '', 'port' => 465, 'secure' => 'ssl'],
];
$curPreset = cfg('smtp_preset', '');
if (!isset($presets[$curPreset])) {
    $curPreset = cfg('smtp_host', '') === '' ? 'qq' : 'custom';
}

admin_header('系统配置', 'settings', $admin);
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

<div class="settings-head">
    <div>
        <h1 class="settings-title">系统设置</h1>
        <p class="settings-sub">按分区管理安全参数、验证码、实名、短信、站点信息与邮件通知</p>
    </div>
</div>

<div class="settings-tabs" id="settingsTabs">
    <a class="settings-tab" data-tab="security" href="#security">安全参数</a>
    <a class="settings-tab" data-tab="captcha" href="#captcha">验证码设置</a>
    <a class="settings-tab" data-tab="geetest" href="#geetest">真实IP与公告</a>
    <a class="settings-tab" data-tab="realname" href="#realname">实名设置</a>
    <a class="settings-tab" data-tab="sms" href="#sms">短信设置</a>
    <a class="settings-tab" data-tab="rebate" href="#rebate">邀请返利</a>
    <a class="settings-tab" data-tab="site" href="#site">站点信息</a>
    <a class="settings-tab" data-tab="mail" href="#mail">邮件通知</a>
    <a class="settings-tab" data-tab="rules" href="#rules">通知规则</a>
    <a class="settings-tab" data-tab="mailtpl" href="#mailtpl">邮件模板</a>
    <a class="settings-tab" data-tab="dir" href="#dir">后台目录</a>
</div>

<section class="card settings-panel" id="panel-security">
    <h2 class="card-title">安全参数</h2>
    <p class="card-desc">密钥下发与 SDK 客户端缓存相关全局配置，修改后立即生效。</p>
    <form method="post" class="form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="security">
        <div class="form-item">
            <label class="form-label">密钥下发接口</label>
            <select class="form-input" name="secret_api_enabled">
                <option value="1" <?php echo cfg('secret_api_enabled', '1') === '1' ? 'selected' : ''; ?>>全局启用</option>
                <option value="0" <?php echo cfg('secret_api_enabled', '1') === '0' ? 'selected' : ''; ?>>全局关闭</option>
            </select>
            <p class="form-hint">关闭后 /api/get_secret.php 拒绝所有密钥拉取请求</p>
        </div>
        <div class="form-item">
            <label class="form-label">密钥会话有效时长</label>
            <div class="input-unit" style="max-width:240px">
                <input class="form-input" type="number" name="secret_session_ttl" min="60" max="86400" value="<?php echo e(cfg('secret_session_ttl', '1800')); ?>" required>
                <span class="unit">秒</span>
            </div>
            <p class="form-hint">服务端下发的 AppKey/AppSecret 绑定会话过期时间，到期后 SDK 必须重新拉取（默认 1800 秒 = 30 分钟）</p>
        </div>
        <div class="form-item">
            <label class="form-label">SDK 内存缓存 TTL</label>
            <div class="input-unit" style="max-width:240px">
                <input class="form-input" type="number" name="sdk_cache_ttl" min="60" max="86400" value="<?php echo e(cfg('sdk_cache_ttl', '1800')); ?>" required>
                <span class="unit">秒</span>
            </div>
            <p class="form-hint">SDK 仅在运行内存内缓存密钥，缓存过期自动清空并重新拉取；强制禁止写入本地文件与日志</p>
        </div>
        <div class="form-item">
            <label class="form-label">密钥接口高频告警阈值</label>
            <div class="input-unit" style="max-width:240px">
                <input class="form-input" type="number" name="secret_alert_threshold" min="10" max="100000" value="<?php echo e(cfg('secret_alert_threshold', '60')); ?>" required>
                <span class="unit">次/分钟</span>
            </div>
            <p class="form-hint">单 IP 短时间超过阈值，写入审计日志并向管理员发送 H5 告警邮件（识别爬虫、爆破、攻击行为）</p>
        </div>
        <div class="form-item">
            <label class="form-label">签名时间窗口</label>
            <div class="input-unit" style="max-width:240px">
                <input class="form-input" type="number" name="sign_time_window" min="60" max="600" value="<?php echo e(cfg('sign_time_window', '300')); ?>" required>
                <span class="unit">秒</span>
            </div>
            <p class="form-hint">请求 timestamp 与服务器时间允许的最大偏差（防重放攻击）</p>
        </div>
        <div class="form-item">
            <label class="form-label">后台登录最大失败次数</label>
            <div class="input-unit" style="max-width:240px">
                <input class="form-input" type="number" name="max_login_fail" min="3" max="20" value="<?php echo e(cfg('max_login_fail', '5')); ?>" required>
                <span class="unit">次</span>
            </div>
            <p class="form-hint">连续失败达到次数后锁定账号 15 分钟</p>
        </div>
        <button class="btn-primary" type="submit">保存安全参数</button>
    </form>
</section>

<section class="card settings-panel" id="panel-captcha">
    <h2 class="card-title">验证码设置</h2>
    <p class="card-desc">统一管理三端登录 / 注册 / 找回密码等场景的人机验证：同时启用时 <b>Cloudflare Turnstile 优先</b>，其次<b>极验 4.0</b>，均未启用（或密钥未配置完整）时自动使用内置图形验证码。V2.2.0 起，极验与 Cloudflare Turnstile 小组件统一在此配置（原「插件商店」中的配置入口已迁移至此）。</p>

    <form method="post" class="form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="captcha_save">
        <div class="form-item">
            <label class="form-label">注册验证码</label>
            <select class="form-input" name="register_captcha" style="max-width:420px">
                <option value="1" <?php echo cfg('register_captcha', '1') === '1' ? 'selected' : ''; ?>>开启（注册流程需通过验证码/行为验证）</option>
                <option value="0" <?php echo cfg('register_captcha', '1') !== '1' ? 'selected' : ''; ?>>关闭（注册流程跳过验证码，仅保留邮箱/短信验证码）</option>
            </select>
            <p class="form-hint">关闭后，用户注册环节不再要求图形验证码或行为验证（发送邮箱/短信验证码时同样跳过），请权衡防刷风险</p>
        </div>

        <div class="form-group-title">Cloudflare Turnstile 人机验证</div>
        <div class="form-item">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" name="turnstile_enabled" value="1" <?php echo plugin_status_get('turnstile') === 1 ? 'checked' : ''; ?>>
                <span>启用 Turnstile 验证（启用前请先完成下方密钥配置）</span>
            </label>
        </div>
        <div class="form-item">
            <label class="form-label">站点密钥（Site Key）</label>
            <input class="form-input" type="text" name="turnstile_site_key" style="max-width:420px" value="<?php echo e(plugin_cfg_get('turnstile', 'turnstile_site_key', '')); ?>" placeholder="Cloudflare Dashboard → Turnstile 创建站点获取">
        </div>
        <div class="form-item">
            <label class="form-label">密钥（Secret Key）</label>
            <input class="form-input" type="password" name="turnstile_secret_key" style="max-width:420px" value="" placeholder="已配置则留空保持不变" autocomplete="new-password">
            <p class="form-hint">仅用于服务端校验，保存后不再回显</p>
        </div>

        <div class="form-group-title">极验 4.0 行为验证</div>
        <div class="form-item">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" name="geetest_enabled" value="1" <?php echo plugin_status_get('geetest') === 1 ? 'checked' : ''; ?>>
                <span>启用极验行为验证（启用前请先完成下方密钥配置）</span>
            </label>
        </div>
        <div class="form-item">
            <label class="form-label">验证 ID（captchaId）</label>
            <input class="form-input" type="text" name="geetest_captcha_id" style="max-width:420px" value="<?php echo e(plugin_cfg_get('geetest', 'geetest_captcha_id', '')); ?>" placeholder="极验后台（geetest.com）创建「行为验证4.0 - Web」应用获取">
        </div>
        <div class="form-item">
            <label class="form-label">验证 Key（captchaKey）</label>
            <input class="form-input" type="password" name="geetest_captcha_key" style="max-width:420px" value="" placeholder="已配置则留空保持不变" autocomplete="new-password">
            <p class="form-hint">仅用于服务端二次校验，保存后不再回显</p>
        </div>

        <button class="btn-primary" type="submit">保存验证码设置</button>
    </form>
</section>

<section class="card settings-panel" id="panel-geetest">
    <h2 class="card-title">真实 IP 与公告</h2>
    <p class="card-desc">V2.2.0 起，人机验证小组件（极验 4.0 / Cloudflare Turnstile）的启用与配置已统一迁移至「验证码设置」分区；本分区保留真实 IP 获取、IP 归属地识别与远程公告设置。</p>

    <form method="post" class="form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="verify">
        <div class="form-item">
            <label class="form-label">真实 IP 获取</label>
            <select class="form-input" name="trust_proxy_ip" style="max-width:420px">
                <option value="1" <?php echo cfg('trust_proxy_ip', '1') === '1' ? 'selected' : ''; ?>>开启（推荐：站点位于 CDN / 反向代理之后时开启）</option>
                <option value="0" <?php echo cfg('trust_proxy_ip', '1') !== '1' ? 'selected' : ''; ?>>关闭（仅记录直连 REMOTE_ADDR）</option>
            </select>
            <p class="form-hint">开启后系统按 CF-Connecting-IP → X-Real-IP → X-Forwarded-For 顺序解析真实客户端 IP（自动过滤内网/保留地址），登录、注册、验证码限流、审计日志均使用真实 IP，避免 CDN 场景下全站共用 IP 触发误限流</p>
        </div>
        <div class="form-item">
            <label class="form-label">IP 归属地识别</label>
            <select class="form-input" name="ip_geo_enabled" style="max-width:420px">
                <option value="1" <?php echo cfg('ip_geo_enabled', '1') === '1' ? 'selected' : ''; ?>>开启（调用 ip9.com.cn 自动识别归属地）</option>
                <option value="0" <?php echo cfg('ip_geo_enabled', '1') !== '1' ? 'selected' : ''; ?>>关闭（不请求外部接口，仅显示 IP）</option>
            </select>
            <p class="form-hint">开启后审计日志、验证日志、SDK 下载日志、告警与个人资料中的 IP 会自动调用 ip9.com.cn 识别地区：国内显示「省 市」，港澳台与境外显示国家/地区名称。识别结果本地缓存 30 天，同一 IP 不会重复请求；接口异常时自动降级为「-」，不影响页面打开速度</p>
        </div>
        <div class="form-item">
            <label class="form-label">远程公告地址</label>
            <input class="form-input" type="text" name="remote_notice_url" style="max-width:420px"
                value="<?php echo e(cfg('remote_notice_url', 'https://raw.githubusercontent.com/lmxdg/zeroauth/main/notice.json')); ?>"
                placeholder="https://example.com/notice.json">
            <p class="form-hint">控制台「远程公告」卡片从该地址拉取公告（JSON 数组，缓存 1 小时），留空恢复系统默认。JSON 格式：[{"id":"20261101","title":"标题","content":"内容","level":"info|ok|warn|error","date":"2026-11-01","url":"详情链接"}]</p>
        </div>
        <button class="btn-primary" type="submit">保存验证设置</button>
    </form>
</section>

<section class="card settings-panel" id="panel-realname">
    <h2 class="card-title">实名设置</h2>
    <p class="card-desc">统一管理实名认证插件（V2.2.0 起，原「插件商店」中的两个实名插件配置入口迁移至此）：<b>内置实名认证</b>为姓名 + 身份证号本地校验、后台人工审核；<b>腾讯云人脸核身</b>启用后用户端实名优先走刷脸核身流程，停用后自动回退内置实名。审核记录与统计见「用户中心 → 实名认证审核」。</p>

    <form method="post" class="form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="realname_save">

        <div class="form-group-title">内置实名认证</div>
        <div class="form-item">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" name="builtin_enabled" value="1" <?php echo plugin_status_get('builtin') === 1 ? 'checked' : ''; ?>>
                <span>启用内置实名认证（停用后用户端实名入口关闭）</span>
            </label>
            <p class="form-hint">姓名 + 身份证号（GB11643 校验位）本地校验，证件号 AES 加密存储，提交后进入后台人工审核，无需任何外部服务</p>
        </div>

        <div class="form-group-title">腾讯云人脸核身</div>
        <div class="form-item">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" name="faceid_enabled" value="1" <?php echo plugin_status_get('tencent_faceid') === 1 ? 'checked' : ''; ?>>
                <span>启用人脸核身（启用时优先于内置实名，用户端自动切换为刷脸流程）</span>
            </label>
        </div>
        <div class="form-item">
            <label class="form-label">SecretId</label>
            <input class="form-input" type="text" name="faceid_secret_id" style="max-width:420px" value="<?php echo e(plugin_cfg_get('tencent_faceid', 'secret_id', '')); ?>" placeholder="腾讯云控制台 → 访问管理 → API 密钥管理">
        </div>
        <div class="form-item">
            <label class="form-label">SecretKey</label>
            <input class="form-input" type="password" name="faceid_secret_key" style="max-width:420px" value="" placeholder="已配置则留空保持不变" autocomplete="new-password">
            <p class="form-hint">仅服务端使用（TC3-HMAC-SHA256 签名），保存后不再回显</p>
        </div>
        <div class="form-item">
            <label class="form-label">RuleId（业务流程 ID）</label>
            <input class="form-input" type="text" name="faceid_rule_id" style="max-width:420px" value="<?php echo e(plugin_cfg_get('tencent_faceid', 'rule_id', '')); ?>" placeholder="人脸核身控制台「自助接入」创建的业务流程 ID（纯数字）">
        </div>
        <div class="form-item">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" name="faceid_auto_pass" value="1" <?php echo plugin_cfg_get('tencent_faceid', 'auto_pass', '1') === '1' ? 'checked' : ''; ?>>
                <span>核验通过后自动完成实名（关闭则转为「待审核」人工复核）</span>
            </label>
        </div>
        <div class="form-item">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" name="faceid_require_name" value="1" <?php echo plugin_cfg_get('tencent_faceid', 'require_name', '1') === '1' ? 'checked' : ''; ?>>
                <span>发起核身前要求填写姓名与证件号（权威源比对，推荐）</span>
            </label>
        </div>

        <button class="btn-primary" type="submit">保存实名设置</button>
    </form>
</section>

<section class="card settings-panel" id="panel-sms">
    <h2 class="card-title">短信与手机号注册</h2>
    <p class="card-desc">开启手机号注册后，用户中心注册页支持「手机号 + 短信验证码」注册（邮箱转为选填）。支持对接<b>短信宝（smsbao.com）</b>与<b>赛邮 SUBMAIL（mysubmail.com）</b>；短信验证码 60 秒重发限频、10 分钟有效、验证一次即销毁。</p>

    <div style="display:flex;flex-wrap:wrap;gap:32px;align-items:flex-start">
        <form method="post" class="form" style="flex:1;min-width:420px;max-width:640px">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="sms_save">
            <div class="form-item">
                <label class="form-label">手机号注册</label>
                <select class="form-input" name="register_phone_enabled" style="max-width:420px">
                    <option value="1" <?php echo cfg('register_phone_enabled', '0') === '1' ? 'selected' : ''; ?>>开启（注册页展示手机号注册方式）</option>
                    <option value="0" <?php echo cfg('register_phone_enabled', '0') !== '1' ? 'selected' : ''; ?>>关闭（仅邮箱注册）</option>
                </select>
                <p class="form-hint">需同时配置下方短信服务商并确保账户可用，否则注册页会自动隐藏手机号注册入口</p>
            </div>
            <div class="form-item">
                <label class="form-label">短信服务商</label>
                <select class="form-input" name="sms_provider" id="sms-provider" style="max-width:420px">
                    <option value="" <?php echo cfg('sms_provider', '') === '' ? 'selected' : ''; ?>>未配置（不发送短信）</option>
                    <option value="smsbao" <?php echo cfg('sms_provider', '') === 'smsbao' ? 'selected' : ''; ?>>短信宝（smsbao.com）</option>
                    <option value="submail" <?php echo cfg('sms_provider', '') === 'submail' ? 'selected' : ''; ?>>赛邮 SUBMAIL（mysubmail.com）</option>
                </select>
            </div>

            <div id="smsBox-smsbao" style="display:none">
                <div class="form-group-title">短信宝配置</div>
                <div class="form-item">
                    <label class="form-label">短信宝账号</label>
                    <input class="form-input" type="text" name="smsbao_user" style="max-width:420px" value="<?php echo e(cfg('smsbao_user', '')); ?>" placeholder="短信宝用户名">
                </div>
                <div class="form-item">
                    <label class="form-label">短信宝密码</label>
                    <input class="form-input" type="password" name="smsbao_pass" style="max-width:420px" value="" placeholder="已配置则留空保持不变" autocomplete="new-password">
                    <p class="form-hint">接口调用使用密码 MD5，保存后不再回显</p>
                </div>
            </div>

            <div id="smsBox-submail" style="display:none">
                <div class="form-group-title">赛邮 SUBMAIL 配置</div>
                <div class="form-item">
                    <label class="form-label">AppID</label>
                    <input class="form-input" type="text" name="submail_appid" style="max-width:420px" value="<?php echo e(cfg('submail_appid', '')); ?>" placeholder="SUBMAIL 应用集成中创建的短信应用 ID">
                </div>
                <div class="form-item">
                    <label class="form-label">AppKey</label>
                    <input class="form-input" type="password" name="submail_appkey" style="max-width:420px" value="" placeholder="已配置则留空保持不变" autocomplete="new-password">
                </div>
                <div class="form-item">
                    <label class="form-label">模板 ID（选填）</label>
                    <input class="form-input" type="text" name="submail_project" style="max-width:420px" value="<?php echo e(cfg('submail_project', '')); ?>" placeholder="留空走内容模式；填写后走 xsend 模板模式">
                    <p class="form-hint">模板模式需在 SUBMAIL 创建含 <code>@var(code)</code> 文本变量的短信模板；内容模式直接发送验证码正文（正文须含已报备的短信签名）</p>
                </div>
            </div>

            <div class="form-item">
                <label class="form-label">短信签名（共用）</label>
                <input class="form-input" type="text" name="sms_sign" style="max-width:420px" value="<?php echo e(cfg('sms_sign', '')); ?>" placeholder="如：ZeroAuth（勿带【】书名号）">
                <p class="form-hint">将以「【签名】您的验证码:123456，10分钟内有效…」拼接发送；短信宝必须在平台报备过签名，赛邮内容模式同样要求正文携带签名</p>
            </div>

            <button class="btn-primary" type="submit">保存短信设置</button>
        </form>

        <div style="width:300px;flex-shrink:0;min-width:260px;display:flex;flex-direction:column;gap:24px">
            <div>
                <div style="font-size:14px;font-weight:600;margin-bottom:10px">配置状态</div>
                <div style="display:flex;flex-direction:column;gap:10px;font-size:14px">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                        <span style="color:var(--text-sub,#4E5969)">手机号注册</span>
                        <span class="tag <?php echo cfg('register_phone_enabled', '0') === '1' ? 'tag-ok' : 'tag-off'; ?>"><?php echo cfg('register_phone_enabled', '0') === '1' ? '已开启' : '已关闭'; ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                        <span style="color:var(--text-sub,#4E5969)">短信服务商</span>
                        <span style="font-weight:600"><?php echo ['' => '未配置', 'smsbao' => '短信宝', 'submail' => '赛邮 SUBMAIL'][(string)cfg('sms_provider', '')] ?? '未配置'; ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                        <span style="color:var(--text-sub,#4E5969)">服务状态</span>
                        <span class="tag <?php echo sms_provider_ready() ? 'tag-ok' : 'tag-off'; ?>"><?php echo sms_provider_ready() ? '配置完整' : '未就绪'; ?></span>
                    </div>
                </div>
            </div>
            <div style="border-top:1px solid var(--border,#E5E6EB);padding-top:20px">
                <div style="font-size:14px;font-weight:600;margin-bottom:10px">测试发送</div>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="sms_test">
                    <input class="form-input" type="text" name="sms_test_phone" placeholder="用于测试的手机号" style="margin-bottom:10px" maxlength="11">
                    <button class="btn-primary" type="submit" style="width:100%">发送测试短信</button>
                </form>
                <div class="form-hint" style="margin-top:8px">按验证码模板向该手机号发送一条测试短信（60 秒限频）；失败原因见 runtime/sms_error.log</div>
            </div>
        </div>
    </div>
</section>

<section class="card settings-panel" id="panel-rebate">
    <h2 class="card-title">邀请返利</h2>
    <p class="card-desc">用户注册时填写代理商邀请码即绑定为其名下用户；名下用户支付成功后（在线支付 / 余额支付均生效），系统按订单实付金额的一定比例自动向该代理商账户返余额。返利记录写入代理商财务流水（类型：邀请返利），同一订单仅返利一次，可通过「通知规则」配置代理商返利到账邮件。</p>
    <form method="post" class="form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="rebate">
        <div class="form-item">
            <label class="form-label">邀请返利开关</label>
            <select class="form-input" name="invite_rebate_enabled" style="max-width:420px">
                <option value="1" <?php echo cfg('invite_rebate_enabled', '0') === '1' ? 'selected' : ''; ?>>开启（名下用户支付成功后自动返利给绑定代理商）</option>
                <option value="0" <?php echo cfg('invite_rebate_enabled', '0') !== '1' ? 'selected' : ''; ?>>关闭（不发放邀请返利）</option>
            </select>
            <p class="form-hint">仅对「用户购买授权」订单生效；代理商自有充值与购买、余额充值订单不参与返利</p>
        </div>
        <div class="form-item">
            <label class="form-label">返利比例</label>
            <div class="input-unit" style="max-width:240px">
                <input class="form-input" type="number" name="invite_rebate_rate" min="0" max="100" step="0.01" value="<?php echo e(cfg('invite_rebate_rate', '0')); ?>" required>
                <span class="unit">%</span>
            </div>
            <p class="form-hint">按订单实付金额计算（如填写 10，用户实付 100 元则返利 10 元），保留 2 位小数，不足 0.01 元不发放</p>
        </div>
        <button class="btn-primary" type="submit">保存邀请返利配置</button>
    </form>
</section>

<section class="card settings-panel" id="panel-notice-publish">
    <h2 class="card-title">远程公告分发</h2>
    <p class="card-desc" style="margin-bottom:14px">
        本系统作为<b>授权站</b>时，可通过公告分发接口向所有接入授权的下游系统下发公告：
        下游系统在「系统设置 → 远程公告地址」填入 <code><?php echo e(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '你的授权站域名') . '/api/notice.php'); ?></code>，
        其控制台即自动展示此处发布的公告。
    </p>
    <form method="post" class="form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="notice_publish">
        <div class="form-item">
            <label class="form-label">公告分发开关</label>
            <select class="form-input" name="remote_notice_publish" style="max-width:420px">
                <option value="1" <?php echo cfg('remote_notice_publish', '1') === '1' ? 'selected' : ''; ?>>开启（接口正常返回公告列表）</option>
                <option value="0" <?php echo cfg('remote_notice_publish', '1') !== '1' ? 'selected' : ''; ?>>关闭（接口返回空公告）</option>
            </select>
        </div>
        <div class="form-item">
            <label class="form-label">公告内容（JSON 数组，最多 10 条，按 date 倒序展示）</label>
            <textarea class="form-input" name="remote_notice_content" rows="8" style="max-width:760px;font-family:Consolas,monospace"
                placeholder='[{"id":"20261101","title":"公告标题","content":"公告内容","level":"info","date":"2026-11-01","url":"https://..."}]'><?php
                $__noticeContent = json_decode((string)cfg('remote_notice_content', '[]'), true);
                echo e(is_array($__noticeContent) ? json_encode($__noticeContent, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '[]');
            ?></textarea>
            <p class="form-hint">
                字段说明：<code>id</code> 唯一标识（用于下游关闭去重）、<code>title</code> 标题（必填）、<code>content</code> 内容（≤500 字）、
                <code>level</code> 等级 info/ok/warn/error（配色）、<code>date</code> 日期、<code>url</code> 详情链接（可空）。
                下游控制台缓存 1 小时，保存后最迟 1 小时生效（下游也可点「检查新公告」立即刷新）。
            </p>
        </div>
        <button class="btn-primary" type="submit">保存公告分发设置</button>
    </form>
</section>

<?php
$siteBuildTime = '-';
try {
    $_bt = (int)db()->query('SELECT MIN(`created_at`) FROM `admin`')->fetchColumn();
    if ($_bt > 0) {
        $siteBuildTime = date('Y-m-d H:i', $_bt);
    }
} catch (Throwable $ex) {
}
$_siteIcoSet = cfg('site_ico', '') !== '';
$_siteLogoSet = cfg('site_logo', '') !== '';
?>
<section class="card settings-panel" id="panel-site">
    <h2 class="card-title">站点信息</h2>
    <div class="site-setting-grid">
        <form method="post" class="form site-setting-form" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="site">
            <div class="form-group-title">基础信息</div>
            <div class="form-row">
                <div class="form-item">
                    <label class="form-label">站点名称</label>
                    <input class="form-input" type="text" id="siteNameInput" name="site_name" maxlength="32" value="<?php echo e(cfg('site_name', 'ZeroAuth')); ?>" required>
                </div>
                <div class="form-item">
                    <label class="form-label">网站关键词</label>
                    <input class="form-input" type="text" name="site_keywords" maxlength="200" value="<?php echo e(cfg('site_keywords', '')); ?>" placeholder="多个关键词用英文逗号分隔">
                </div>
            </div>
            <div class="form-item">
                <label class="form-label">网站简介</label>
                <textarea class="form-input" name="site_description" maxlength="300" rows="3" placeholder="一句话介绍你的网站，约 100 字效果最佳"><?php echo e(cfg('site_description', '')); ?></textarea>
                <div class="form-hint">关键词和简介会展示在搜索引擎结果里，帮助网站被收录。</div>
            </div>
            <div class="form-group-title">联系方式</div>
            <div class="form-row">
                <div class="form-item">
                    <label class="form-label">联系 QQ</label>
                    <input class="form-input" type="text" id="contactQqInput" name="contact_qq" maxlength="20" value="<?php echo e(cfg('contact_qq', '')); ?>" placeholder="QQ 号码">
                </div>
                <div class="form-item">
                    <label class="form-label">联系电话</label>
                    <input class="form-input" type="text" id="contactPhoneInput" name="contact_phone" maxlength="20" value="<?php echo e(cfg('contact_phone', '')); ?>" placeholder="手机号或座机">
                </div>
                <div class="form-item">
                    <label class="form-label">联系邮箱</label>
                    <input class="form-input" type="text" name="contact_email" maxlength="128" value="<?php echo e(cfg('contact_email', '')); ?>" placeholder="name@example.com">
                </div>
            </div>
            <div class="form-row">
                <div class="form-item">
                    <label class="form-label">QQ 群号</label>
                    <input class="form-input" type="text" name="contact_qq_group" maxlength="32" value="<?php echo e(cfg('contact_qq_group', '')); ?>" placeholder="交流群群号">
                </div>
                <div class="form-item">
                    <label class="form-label">网站备案号</label>
                    <input class="form-input" type="text" id="siteIcpInput" name="site_icp" maxlength="100" value="<?php echo e(cfg('site_icp', '')); ?>" placeholder="例如：京ICP备2026xxxxxxx号">
                    <div class="form-hint">填写后展示在三端页脚，自动附带工信部查询链接。</div>
                </div>
            </div>
            <div class="form-group-title">品牌图片</div>
            <div class="form-row">
                <div class="form-item">
                    <label class="form-label">网站 Logo</label>
                    <div class="upload-row">
                        <div class="upload-box" id="logoBox" title="点击选择图片">
                            <img id="logoPreview" src="<?php echo e(site_logo_url('')); ?>" alt="">
                        </div>
                        <div class="upload-meta">
                            <div class="upload-title"><?php echo $_siteLogoSet ? '已启用自定义' : '当前使用默认'; ?></div>
                            <div class="upload-hint">png / jpg / webp，不超过 5MB</div>
                            <div class="upload-actions">
                                <button class="btn-plain" type="button" id="logoPick">选择图片</button>
                                <?php if ($_siteLogoSet): ?>
                                <button class="btn-plain" type="submit" form="siteClearForm" name="field" value="site_logo">移除</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <input type="file" name="site_logo" id="logoInput" accept=".png,.jpg,.jpeg,.webp" hidden>
                </div>
                <div class="form-item">
                    <label class="form-label">浏览器图标<span class="form-label-opt">选填</span></label>
                    <div class="upload-row">
                        <div class="upload-box" id="icoBox" title="点击选择图片">
                            <img id="icoPreview" src="<?php echo e(site_icon_url('')); ?>" alt="">
                        </div>
                        <div class="upload-meta">
                            <div class="upload-title"><?php echo $_siteIcoSet ? '已启用自定义' : '当前使用默认'; ?></div>
                            <div class="upload-hint">ico / png，不超过 3MB，标签页小图标</div>
                            <div class="upload-actions">
                                <button class="btn-plain" type="button" id="icoPick">选择图片</button>
                                <?php if ($_siteIcoSet): ?>
                                <button class="btn-plain" type="submit" form="siteClearForm" name="field" value="site_ico">移除</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <input type="file" name="site_ico" id="icoInput" accept=".ico,.png" hidden>
                </div>
            </div>
            <div class="form-group-title">注册方式</div>
            <div class="form-item">
                <div class="disc-chips">
                    <label class="app-picker-item" style="max-width:280px">
                        <input type="radio" name="register_mode" value="1" <?php echo cfg('register_mode', '1') === '1' ? 'checked' : ''; ?>>
                        <span>允许用户自己注册</span>
                    </label>
                    <label class="app-picker-item" style="max-width:280px">
                        <input type="radio" name="register_mode" value="0" <?php echo cfg('register_mode', '1') !== '1' ? 'checked' : ''; ?>>
                        <span>关闭注册，账号由管理员创建</span>
                    </label>
                </div>
                <div class="form-hint">关闭后，注册页会提示「请联系管理员开通账号」。</div>
            </div>
            <button class="btn-primary" type="submit">保存站点信息</button>
        </form>
        <aside class="site-preview">
            <div class="sp-title">配置预览</div>
            <div class="sp-brand">
                <img id="pvLogo" src="<?php echo e(site_logo_url('')); ?>" alt="">
                <div>
                    <div class="sp-name" id="pvName"><?php echo e(cfg('site_name', 'ZeroAuth')); ?></div>
                    <div class="sp-sub"><?php echo e(cfg('site_description', '') !== '' ? mb_substr(cfg('site_description'), 0, 24) : '软件授权与域名授权管理平台'); ?></div>
                </div>
            </div>
            <table class="sp-table">
                <tr><th>建站时间</th><td><?php echo e($siteBuildTime); ?></td></tr>
                <tr><th>联系 QQ</th><td id="pvQq"><?php echo e(cfg('contact_qq', '') !== '' ? cfg('contact_qq') : '-'); ?></td></tr>
                <tr><th>联系电话</th><td id="pvPhone"><?php echo e(cfg('contact_phone', '') !== '' ? cfg('contact_phone') : '-'); ?></td></tr>
                <tr><th>备案号</th><td id="pvIcp"><?php echo e(cfg('site_icp', '') !== '' ? cfg('site_icp') : '-'); ?></td></tr>
                <tr><th>用户注册</th><td><span class="sp-tag <?php echo cfg('register_mode', '1') === '1' ? 'sp-tag-ok' : 'sp-tag-off'; ?>" id="pvReg"><?php echo cfg('register_mode', '1') === '1' ? '允许' : '关闭'; ?></span></td></tr>
            </table>
            <div class="sp-hint">预览随左侧输入实时更新，最终以保存结果为准。</div>
        </aside>
    </div>
</section>
<form id="siteClearForm" method="post" action="" hidden><?php echo csrf_field(); ?><input type="hidden" name="action" value="site_clear_asset"></form>

<section class="card settings-panel" id="panel-mail">
    <h2 class="card-title">邮件通知</h2>
    <p class="card-desc">选择邮件服务商并填写 SMTP 授权信息，系统通过该通道发送全部 H5 通知邮件；各场景模板可在下方「邮件内容自定义」中修改。</p>

    <div style="display:flex;flex-wrap:wrap;gap:32px;align-items:flex-start">
        <form id="mail-smtp-form" method="post" class="form" style="flex:1;min-width:420px;max-width:640px">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="mail_smtp">
            <input type="hidden" name="scene" value="<?php echo e($editScene); ?>">

            <div class="form-item">
                <label class="form-label">服务商预设</label>
                <select class="form-input" name="smtp_preset" id="smtp-preset" style="max-width:320px">
                    <?php foreach ($presets as $key => $p): $key = (string)$key; ?>
                    <option value="<?php echo e($key); ?>" data-host="<?php echo e($p['host']); ?>" data-port="<?php echo (int)$p['port']; ?>" data-secure="<?php echo e($p['secure']); ?>" <?php echo $curPreset === $key ? 'selected' : ''; ?>><?php echo e($p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint">选择常用服务商后自动填入服务器地址、端口与加密方式，也可切换「自定义服务商」手动填写</div>
            </div>

            <div class="form-row" style="flex-wrap:wrap">
                <div class="form-item" style="flex:2;min-width:200px">
                    <label class="form-label">SMTP 服务器</label>
                    <input class="form-input" type="text" name="smtp_host" id="smtp-host" value="<?php echo e(cfg('smtp_host', '')); ?>" placeholder="smtp.qq.com">
                </div>
                <div class="form-item" style="width:120px">
                    <label class="form-label">端口</label>
                    <input class="form-input" type="number" name="smtp_port" id="smtp-port" value="<?php echo e(cfg('smtp_port', '465')); ?>">
                </div>
                <div class="form-item" style="width:120px">
                    <label class="form-label">加密方式</label>
                    <select class="form-input" name="smtp_secure" id="smtp-secure">
                        <?php foreach (['ssl' => 'SSL', 'tls' => 'TLS', 'none' => '无'] as $k => $n): ?>
                        <option value="<?php echo $k; ?>" <?php echo cfg('smtp_secure', 'ssl') === $k ? 'selected' : ''; ?>><?php echo $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row" style="flex-wrap:wrap">
                <div class="form-item" style="flex:1;min-width:200px">
                    <label class="form-label">SMTP 账号</label>
                    <input class="form-input" type="text" name="smtp_user" value="<?php echo e(cfg('smtp_user', '')); ?>" placeholder="用于登录 SMTP 的邮箱地址">
                </div>
                <div class="form-item" style="flex:1;min-width:200px">
                    <label class="form-label">授权码 / 密码</label>
                    <input class="form-input" type="password" name="smtp_pass" value="" placeholder="已配置则留空保持不变" autocomplete="new-password">
                </div>
            </div>

            <div class="form-row" style="flex-wrap:wrap">
                <div class="form-item" style="flex:1;min-width:200px">
                    <label class="form-label">发件人名称</label>
                    <input class="form-input" type="text" name="smtp_from_name" value="<?php echo e(cfg('smtp_from_name', '授权管理系统')); ?>">
                </div>
                <div class="form-item" style="flex:1;min-width:200px">
                    <label class="form-label">发件人邮箱</label>
                    <input class="form-input" type="text" name="smtp_from_mail" value="<?php echo e(cfg('smtp_from_mail', cfg('smtp_user', ''))); ?>" placeholder="默认与 SMTP 账号一致">
                </div>
            </div>

            <div class="form-item">
                <label class="form-label">通知开关</label>
                <div style="display:flex;gap:28px;flex-wrap:wrap">
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" name="email_enabled" value="1" <?php echo cfg('email_enabled', '1') === '1' ? 'checked' : ''; ?>>
                        <span>邮件通知（总开关）</span>
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" name="email_notify_alert" value="1" <?php echo cfg('email_notify_alert', '1') === '1' ? 'checked' : ''; ?>>
                        <span>安全告警邮件</span>
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" name="email_expire_enabled" value="1" <?php echo cfg('email_expire_enabled', '1') === '1' ? 'checked' : ''; ?>>
                        <span>到期提醒扫描</span>
                    </label>
                </div>
                <div class="form-hint">总开关关闭后系统停止发送一切邮件；各场景的独立开关在下方「通知规则」中控制</div>
            </div>

            <div class="form-row" style="flex-wrap:wrap">
                <div class="form-item" style="flex:1;min-width:240px">
                    <label class="form-label">告警收件邮箱</label>
                    <input class="form-input" type="text" name="alert_email" value="<?php echo e(cfg('alert_email', '')); ?>" placeholder="admin@example.com（多个用英文逗号分隔）">
                    <div class="form-hint">接收安全告警、IP 封禁通知；为空时发给「管理员资料」中填了邮箱的账号</div>
                </div>
                <div class="form-item" style="width:220px">
                    <label class="form-label">到期提醒提前量</label>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input class="form-input" type="number" name="email_expire_days" min="0" max="90" value="<?php echo e(cfg('email_expire_days', '3')); ?>" style="width:80px">
                        <span style="font-size:14px;color:var(--text-sub,#4E5969)">天前提醒</span>
                    </div>
                    <div class="form-hint">0 = 不提醒，每小时扫描一次</div>
                </div>
            </div>

            <button class="btn-primary" type="submit">保存 SMTP 配置</button>
        </form>

        <div style="width:300px;flex-shrink:0;min-width:260px;display:flex;flex-direction:column;gap:24px">
            <div>
                <div style="font-size:14px;font-weight:600;margin-bottom:10px">配置状态</div>
                <div style="display:flex;flex-direction:column;gap:10px;font-size:14px">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                        <span style="color:var(--text-sub,#4E5969)">邮件通知</span>
                        <span class="tag <?php echo cfg('email_enabled', '1') === '1' ? 'tag-ok' : 'tag-off'; ?>"><?php echo cfg('email_enabled', '1') === '1' ? '已开启' : '已关闭'; ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                        <span style="color:var(--text-sub,#4E5969)">服务商</span>
                        <span style="font-weight:600"><?php echo e($presets[$curPreset]['name']); ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                        <span style="color:var(--text-sub,#4E5969)">SMTP 服务器</span>
                        <span style="font-weight:600;word-break:break-all;text-align:right"><?php echo cfg('smtp_host', '') !== '' ? e(cfg('smtp_host', '')) : '未配置'; ?></span>
                    </div>
                </div>
            </div>
            <div style="border-top:1px solid var(--border,#E5E6EB);padding-top:20px">
                <div style="font-size:14px;font-weight:600;margin-bottom:10px">测试发送</div>
                <form id="mail-test-form" method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="mail_test">
                    <input type="hidden" name="scene" value="<?php echo e($editScene); ?>">
                    <input class="form-input" type="text" name="test_to" placeholder="用于测试的收件邮箱地址" style="margin-bottom:10px">
                    <button class="btn-primary" type="submit" style="width:100%">发送测试邮件</button>
                </form>
                <div class="form-hint" style="margin-top:8px">按「系统通知」场景向该邮箱发送一封测试邮件；若未收到请检查垃圾箱或 SMTP 授权码是否有效，失败原因见 runtime/mail_error.log</div>
            </div>
        </div>
    </div>
</section>

<section class="card settings-panel" id="panel-rules">
    <h2 class="card-title">通知规则</h2>
    <p class="card-desc">控制各类事件是否触发邮件发送；「邮件通知」总开关关闭时全部邮件不发送。</p>
    <?php
    $ruleGroups = ['account' => '账户安全', 'trade' => '交易与卡密', 'admin' => '管理与告警', 'ticket' => '工单服务'];
    $onCount = 0;
    foreach ($scenes as $sc) { if (cfg($sc['rule'], '1') === '1') { $onCount++; } }
    ?>
    <div style="margin-bottom:16px;font-size:13px;color:var(--text-third)">共 <?php echo count($scenes); ?> 个场景，已开启 <?php echo $onCount; ?> 个</div>
    <?php foreach ($ruleGroups as $rgKey => $rgName): ?>
    <div class="rule-group">
        <div class="rule-group-head"><?php echo e($rgName); ?><span><?php $n = 0; foreach ($scenes as $sc) { if (($sc['group'] ?? '') === $rgKey) { $n++; } } echo $n; ?> 个场景</span></div>
        <?php foreach ($scenes as $code => $sc): if (($sc['group'] ?? '') !== $rgKey) continue; $on = cfg($sc['rule'], '1') === '1'; ?>
        <div class="rule-row">
            <div class="rule-info">
                <div class="rule-name"><?php echo e($sc['name']); ?></div>
                <div class="rule-desc"><?php echo e($sc['desc']); ?></div>
            </div>
            <span class="tag <?php echo $on ? 'tag-ok' : 'tag-off'; ?>"><?php echo $on ? '已开启' : '已关闭'; ?></span>
            <form method="post" style="margin:0;flex-shrink:0">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="mail_rule">
                <input type="hidden" name="scene" value="<?php echo e($code); ?>">
                <input type="hidden" name="back_scene" value="<?php echo e($editScene); ?>">
                <input type="hidden" name="enabled" value="<?php echo $on ? '0' : '1'; ?>">
                <button class="<?php echo $on ? 'rule-btn-off' : 'rule-btn-on'; ?>" type="submit"><?php echo $on ? '关闭' : '开启'; ?></button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</section>

<section class="card settings-panel" id="panel-mailtpl">
    <h2 class="card-title">邮件内容自定义</h2>
    <p class="card-desc">为不同通知场景自定义邮件标题与 H5 内容，支持 HTML 模板和变量替换；未自定义的场景使用系统默认模板。右侧预览随编辑实时刷新，无需先保存。</p>

    <div style="margin-bottom:16px">
        <?php
        $sceneGroups = ['account' => '账户安全', 'trade' => '交易与卡密', 'admin' => '管理与告警', 'ticket' => '工单服务'];
        foreach ($sceneGroups as $sgKey => $sgName): ?>
        <div class="tpl-group">
            <div class="tpl-group-head"><?php echo e($sgName); ?></div>
            <div class="tpl-chips">
                <?php foreach ($scenes as $code => $sc): if (($sc['group'] ?? '') !== $sgKey) continue;
                $customized = cfg('email_tpl_' . $code, '') !== '' || cfg('email_subj_' . $code, '') !== ''; ?>
                <a class="tpl-chip <?php echo $editScene === $code ? 'active' : ''; ?>" href="settings.php?scene=<?php echo e($code); ?>"><?php echo e($sc['name']); ?><?php echo $customized ? '<i class="tpl-dot"></i>' : ''; ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <div style="font-size:12px;color:var(--text-third);margin-top:2px"><i class="tpl-dot"></i> 绿点 = 该场景正在使用自定义模板</div>
    </div>

    <?php if ($isCustom): ?>
    <div class="alert alert-ok">
        <img src="../assets/svg/check-circle.svg" alt="">
        <span>「<?php echo e($scenes[$editScene]['name']); ?>」正在使用自定义模板。</span>
    </div>
    <?php endif; ?>

    <form id="mail-tpl-form" method="post" class="form" style="max-width:none">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="mail_tpl">
        <input type="hidden" name="scene" value="<?php echo e($editScene); ?>">

        <div class="form-item">
            <label class="form-label">邮件标题</label>
            <input class="form-input" type="text" name="subject" id="mail-subject" value="<?php echo e($curSubj !== '' ? $curSubj : mail_scene_default_subj($editScene)); ?>" maxlength="200">
        </div>

        <div class="form-item">
            <label class="form-label">可用变量（点击插入到光标处）</label>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
                <?php foreach ($scenes[$editScene]['vars'] as $vk => $vlabel): ?>
                <button type="button" class="btn-mini-plain var-chip" data-var="{{<?php echo e($vk); ?>}}" style="cursor:pointer"><?php echo e($vk); ?>｜<?php echo e($vlabel); ?></button>
                <?php endforeach; ?>
                <button type="button" class="btn-mini-plain var-chip" data-var="{{siteName}}">siteName｜站点名称</button>
            </div>
        </div>

        <div class="form-item">
            <label class="form-label">邮件 H5 内容（完整 HTML，占位符 {{变量}} 发送时自动替换）</label>
            <textarea class="form-input" name="tpl" id="tpl-editor" rows="16"
                style="font-family:Consolas,Menlo,monospace;font-size:13px;white-space:pre-wrap;word-break:break-all;overflow-y:auto"
                placeholder="<?php echo e(htmlspecialchars(mb_substr(mail_scene_default_html($editScene), 0, 160))); ?>..."><?php echo e($curTpl !== '' ? $curTpl : mail_scene_default_html($editScene)); ?></textarea>
            <div class="form-hint">提示：系统会在替换变量后直接发送此 HTML。含 HTML 的变量（如 cardList）请直接使用变量名。清空标题与内容并「恢复默认」可回到系统模板。</div>
        </div>
    </form>

    <div style="display:flex;gap:10px;margin:0 0 20px">
        <button class="btn-primary" form="mail-tpl-form" type="submit">保存模板</button>
        <button class="btn-plain" type="button" id="btn-refresh-preview">刷新预览</button>
        <form method="post" style="margin:0">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="mail_tpl_reset">
            <input type="hidden" name="scene" value="<?php echo e($editScene); ?>">
            <button class="btn-plain" type="submit"
                data-confirm="确认恢复「<?php echo e($scenes[$editScene]['name']); ?>」为系统默认模板？当前自定义内容将删除。">恢复默认</button>
        </form>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <h3 style="font-size:14px;font-weight:600;margin:0">实时预览（示例数据）</h3>
        <span style="font-size:12px;color:var(--text-third,#86909C)">邮件标题：<?php echo e($curSubj !== '' ? $curSubj : mail_scene_default_subj($editScene)); ?></span>
    </div>
    <iframe id="tpl-preview" style="width:100%;height:560px;border:1px solid var(--border,#E5E6EB);border-radius:6px;background:#F2F3F5" title="邮件实时预览"></iframe>
</section>

<section class="card settings-panel" id="panel-dir">
    <h2 class="card-title">后台目录管理</h2>
    <p class="card-desc">修改管理员后台文件夹名称，防止固定路径被扫描。系统自动迁移目录内全部文件并更新路由配置。</p>
    <form method="post" class="form" data-confirm="确定修改后台目录名吗？迁移后需使用新地址登录后台。">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="rename_admin_dir">
        <div class="form-item">
            <label class="form-label">新目录名称（当前：<code><?php echo e(basename(__DIR__)); ?>/</code>）</label>
            <div class="input-group" style="display:flex">
                <input class="form-input" type="text" name="new_dir" placeholder="例如：manager2026" required>
                <span class="input-unit" style="margin-left:-1px"><span class="unit" style="border-radius:0 var(--radius) var(--radius) 0;border:1px solid var(--border);border-left:none">/</span></span>
            </div>
            <p class="form-hint">以字母开头，3~30 位字母、数字或下划线；禁止系统保留目录名</p>
        </div>
        <button class="btn-primary" type="submit">迁移后台目录</button>
    </form>
</section>
<script>

(function () {
    var tabs = document.querySelectorAll('.settings-tab');
    var panels = document.querySelectorAll('.settings-panel');
    function activate(key, save) {
        var hit = false;
        tabs.forEach(function (t) {
            var on = t.dataset.tab === key;
            t.classList.toggle('active', on);
            if (on) { hit = true; }
        });
        if (!hit) { key = 'security'; tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === key); }); }
        panels.forEach(function (p) { p.style.display = p.id === 'panel-' + key ? '' : 'none'; });
        if (save !== false) {
            try { localStorage.setItem('settings_tab', key); } catch (e) {}
            if (location.hash !== '#' + key) { history.replaceState(null, '', '#' + key); }
        }
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function (ev) {
            ev.preventDefault();
            activate(t.dataset.tab);
        });
    });
    window.addEventListener('hashchange', function () {
        var k = location.hash.replace('#', '');
        if (k) { activate(k, false); }
    });

    var init = 'security';
    if (location.hash !== '') {
        init = location.hash.replace('#', '');
    } else if (<?php echo (isset($_GET['scene']) && isset($scenes[(string)$_GET['scene']])) ? 'true' : 'false'; ?>) {
        init = 'mailtpl';
    } else {
        try { init = localStorage.getItem('settings_tab') || 'security'; } catch (e) {}
    }
    activate(init, false);
})();

document.getElementById('smtp-preset').addEventListener('change', function () {
    var o = this.options[this.selectedIndex];
    if (o.dataset.host) { document.getElementById('smtp-host').value = o.dataset.host; }
    document.getElementById('smtp-port').value = o.dataset.port || '465';
    document.getElementById('smtp-secure').value = o.dataset.secure || 'ssl';
});

(function () {
    var smsProv = document.getElementById('sms-provider');
    if (!smsProv) return;
    function smsToggle() {
        var v = smsProv.value;
        var b1 = document.getElementById('smsBox-smsbao');
        var b2 = document.getElementById('smsBox-submail');
        if (b1) { b1.style.display = v === 'smsbao' ? '' : 'none'; }
        if (b2) { b2.style.display = v === 'submail' ? '' : 'none'; }
    }
    smsProv.addEventListener('change', smsToggle);
    smsToggle();
})();

document.querySelectorAll('.var-chip').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var ta = document.getElementById('tpl-editor');
        var pos = ta.selectionStart || ta.value.length;
        ta.value = ta.value.slice(0, pos) + btn.dataset.var + ta.value.slice(pos);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = pos + btn.dataset.var.length;
        mailRenderPreview();
    });
});

var MAIL_SAMPLE = <?php echo json_encode($previewSample, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function mailEsc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function mailRenderPreview() {
    var html = document.getElementById('tpl-editor').value;
    var subj = document.getElementById('mail-subject').value;
    Object.keys(MAIL_SAMPLE).forEach(function (k) {
        var raw = /_html$|List$/.test(k);
        var val = raw ? String(MAIL_SAMPLE[k]) : mailEsc(MAIL_SAMPLE[k]);
        html = html.split('{{' + k + '}}').join(val);
        subj = subj.split('{{' + k + '}}').join(val);
    });
    html = html.split('{{mainTitle}}').join(mailEsc(subj));
    html = html.replace(/\{\{[a-zA-Z_][a-zA-Z0-9_]*\}\}/g, '');
    document.getElementById('tpl-preview').srcdoc = html;
}
var mailPreviewTimer = null;
document.getElementById('tpl-editor').addEventListener('input', function () {
    clearTimeout(mailPreviewTimer);
    mailPreviewTimer = setTimeout(mailRenderPreview, 400);
});
document.getElementById('btn-refresh-preview').addEventListener('click', mailRenderPreview);
mailRenderPreview();

(function () {
    function $(id) { return document.getElementById(id); }
    function bindText(inputId, targetId, dash) {
        var el = $(inputId), t = $(targetId);
        if (!el || !t) return;
        function up() { var v = el.value.trim(); t.textContent = v !== '' ? v : (dash || '-'); }
        el.addEventListener('input', up);
        up();
    }
    bindText('siteNameInput', 'pvName', 'ZeroAuth');
    bindText('contactQqInput', 'pvQq');
    bindText('contactPhoneInput', 'pvPhone');
    bindText('siteIcpInput', 'pvIcp');
    function regUp() {
        var yes = document.querySelector('input[name="register_mode"][value="1"]');
        var tag = $('pvReg');
        if (!yes || !tag) return;
        var on = yes.checked;
        tag.textContent = on ? '已开启注册功能' : '已关闭注册功能';
        tag.className = on ? 'sp-tag sp-tag-ok' : 'sp-tag sp-tag-off';
    }
    Array.prototype.forEach.call(document.querySelectorAll('input[name="register_mode"]'), function (r) {
        r.addEventListener('change', regUp);
    });
    regUp();
    function bindImg(inputId, imgIds) {
        var el = $(inputId);
        if (!el) return;
        el.addEventListener('change', function () {
            var f = el.files && el.files[0];
            if (!f || !window.FileReader) return;
            var rd = new FileReader();
            rd.onload = function () {
                imgIds.forEach(function (id) {
                    var im = $(id);
                    if (im) { im.src = rd.result; }
                });
            };
            rd.readAsDataURL(f);
        });
    }
    bindImg('icoInput', ['icoPreview']);
    bindImg('logoInput', ['logoPreview', 'pvLogo']);
    function pick(boxId, btnId, inputId) {
        var box = $(boxId), btn = $(btnId), input = $(inputId);
        if (box && input) box.addEventListener('click', function () { input.click(); });
        if (btn && input) btn.addEventListener('click', function () { input.click(); });
    }
    pick('logoBox', 'logoPick', 'logoInput');
    pick('icoBox', 'icoPick', 'icoInput');
})();
</script>
<?php admin_footer(); ?>
