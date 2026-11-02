<?php
// 邮件引擎：SMTP 发送/H5 模板/场景化通知
declare(strict_types=1);

function mail_cfg(string $key, string $default = ''): string
{
    return cfg($key, $default);
}

function mail_send(string $to, string $subject, string $html): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    try {
        $stmt = db()->prepare('INSERT INTO `email_log` (`to_email`,`subject`,`content`,`status`,`error_msg`,`created_at`) VALUES (?,?,?,0,\'\',?)');
        $stmt->execute([$to, mb_substr($subject, 0, 200), $html, time()]);
        $logId = (int)db()->lastInsertId();
    } catch (Throwable $ex) {
        @file_put_contents(RUNTIME_PATH . '/mail_error.log', date('Y-m-d H:i:s') . ' log写入失败 ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
        return false;
    }

    if (mail_cfg('email_enabled', '1') !== '1' || mail_cfg('smtp_host', '') === '') {
        mail_log_finish($logId, 2, mail_cfg('smtp_host', '') === '' ? 'SMTP 未配置' : '邮件通知已关闭');
        return false;
    }

    $conf = [
        'host'      => mail_cfg('smtp_host', ''),
        'port'      => (int)mail_cfg('smtp_port', '465'),
        'user'      => mail_cfg('smtp_user', ''),
        'pass'      => mail_cfg('smtp_pass', ''),
        'from_name' => mail_cfg('smtp_from_name', '授权管理系统'),
        'from_mail' => mail_cfg('smtp_from_mail', '') !== '' ? mail_cfg('smtp_from_mail', '') : mail_cfg('smtp_user', ''),
        'secure'    => mail_cfg('smtp_secure', 'ssl'),     ];

    try {
        mail_smtp_deliver($conf, $to, $subject, $html);
        mail_log_finish($logId, 1, '');
        return true;
    } catch (Throwable $ex) {
        mail_log_finish($logId, 2, mb_substr(mail_utf8_msg($ex->getMessage()), 0, 240));
        return false;
    }
}

function mail_utf8_msg(string $msg): string
{
    if (!preg_match('//u', $msg)) {
        $conv = @mb_convert_encoding($msg, 'UTF-8', 'GBK');
        if ($conv !== false) {
            $msg = $conv;
        }
    }
    return $msg;
}

function mail_log_finish(int $logId, int $status, string $error): void
{
    try {
        db()->prepare('UPDATE `email_log` SET `status`=?,`error_msg`=? WHERE `id`=?')->execute([$status, $error, $logId]);
    } catch (Throwable $ex) {
        @file_put_contents(RUNTIME_PATH . '/mail_error.log', date('Y-m-d H:i:s') . ' 回写失败 ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

function mail_smtp_read($fp): string
{
    $data = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    if ($data === '') {
        throw new RuntimeException('SMTP 无响应');
    }
    return $data;
}

function mail_smtp_cmd($fp, string $cmd, string $expect): string
{
    fwrite($fp, $cmd . "\r\n");
    $resp = mail_smtp_read($fp);
    if (strpos($resp, $expect) !== 0) {
        throw new RuntimeException('SMTP 命令失败：' . substr($resp, 0, 120));
    }
    return $resp;
}

function mail_smtp_deliver(array $conf, string $to, string $subject, string $html): void
{
    $host = (string)$conf['host'];
    $port = (int)$conf['port'];
    $secure = (string)$conf['secure'];
    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host;
    $fp = @fsockopen($remote, $port, $errno, $errstr, 12);
    if (!$fp) {
        throw new RuntimeException('SMTP 连接失败：' . $errstr . '（' . $errno . '）');
    }
    stream_set_timeout($fp, 12);

    try {
        mail_smtp_read($fp);         $ehloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $resp = mail_smtp_cmd($fp, 'EHLO ' . $ehloHost, '250');

        if ($secure === 'tls') {
            if (stripos($resp, 'STARTTLS') === false) {
                throw new RuntimeException('服务器不支持 STARTTLS');
            }
            mail_smtp_cmd($fp, 'STARTTLS', '220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS 加密协商失败');
            }
            mail_smtp_cmd($fp, 'EHLO ' . $ehloHost, '250');
        }

        if ((string)$conf['user'] !== '') {
            mail_smtp_cmd($fp, 'AUTH LOGIN', '334');
            mail_smtp_cmd($fp, base64_encode((string)$conf['user']), '334');
            mail_smtp_cmd($fp, base64_encode((string)$conf['pass']), '235');
        }

        $from = (string)$conf['from_mail'] !== '' ? (string)$conf['from_mail']
            : ((string)$conf['user'] !== '' ? (string)$conf['user'] : 'noreply@' . $ehloHost);
        mail_smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', '250');
        mail_smtp_cmd($fp, 'RCPT TO:<' . $to . '>', '250');
        mail_smtp_cmd($fp, 'DATA', '354');

        $encName = '=?UTF-8?B?' . base64_encode((string)$conf['from_name']) . '?=';
        $encSub  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = 'From: ' . $encName . ' <' . $from . '>' . "\r\n"
            . 'To: <' . $to . '>' . "\r\n"
            . 'Subject: ' . $encSub . "\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehloHost . '>' . "\r\n"
            . 'MIME-Version: 1.0' . "\r\n"
            . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n";
        $body = chunk_split(base64_encode($html), 76, "\r\n");

        mail_smtp_cmd($fp, $headers . "\r\n" . $body . "\r\n.", '250');
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

function mail_expire_scan(): void
{
    $days = max(0, (int)mail_cfg('email_expire_days', '3'));
    if ($days <= 0 || mail_cfg('email_enabled', '1') !== '1' || mail_cfg('email_expire_enabled', '1') !== '1') {
        return;
    }
    $lockFile = RUNTIME_PATH . '/mail_expire_scan.lock';
    if (is_file($lockFile) && time() - (int)filemtime($lockFile) < 3600) {
        return;
    }
    @file_put_contents($lockFile, (string)time());

    $now = time();
    $end = $now + $days * 86400;
    try {
        $stmt = db()->prepare(
            'SELECT `l`.`id`,`l`.`card_no`,`l`.`expire_time`,`l`.`user_id`,`u`.`email` FROM `license` `l` '
            . 'INNER JOIN `user` `u` ON `u`.`id`=`l`.`user_id` AND `u`.`email`<>\'\' '
            . 'WHERE `l`.`type`=1 AND `l`.`status`=1 AND `l`.`user_id`>0 AND `l`.`expire_time`>? AND `l`.`expire_time`<=? LIMIT 200'
        );
        $stmt->execute([$now, $end]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $ex) {
        return;
    }
    if (!$rows) {
        return;
    }

    $doneFile = RUNTIME_PATH . '/mail_expire_reminded.json';
    $done = [];
    if (is_file($doneFile)) {
        $done = json_decode((string)@file_get_contents($doneFile), true) ?: [];
    }
    foreach ($done as $lid => $t) {
        if ((int)$t < $now - 30 * 86400) {
            unset($done[$lid]);
        }
    }

    $byUser = [];
    foreach ($rows as $r) {
        if (isset($done[(int)$r['id']])) {
            continue;
        }
        $byUser[(int)$r['user_id']][] = $r;
        $done[(int)$r['id']] = $now;
    }

    foreach ($byUser as $uid => $cards) {
        $to = (string)$cards[0]['email'];
        if ($to === '') {
            continue;
        }
        $listHtml = '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
        foreach ($cards as $c) {
            $listHtml .= '<tr><td style="padding:8px 12px;border:1px solid #e5e6eb;background:#f7f8fa;white-space:nowrap;"><span style="font-family:Consolas,Menlo,monospace;color:#0052d9;font-weight:600;">' . e((string)$c['card_no']) . '</span></td>'
                . '<td style="padding:8px 12px;border:1px solid #e5e6eb;">' . date('Y-m-d H:i', (int)$c['expire_time']) . ' 到期</td></tr>';
        }
        $listHtml .= '</table>';
        mail_scene_send('expire', $to, [
            'userName' => (string)$uid,
            'days'     => (string)$days,
            'cardList_html' => $listHtml,
        ]);
    }

    @file_put_contents($doneFile, json_encode($done));
}

function mail_scenes(): array
{
    return [
        'register_code' => [
            'group' => 'account', 'name' => '注册邮箱验证码',
            'desc' => '用户注册时发送到邮箱的验证码',
            'rule' => 'email_notify_register_code',
            'vars' => ['code' => '验证码', 'minutes' => '有效分钟数'],
            'sample' => ['code' => '836421', 'minutes' => '10'],
        ],
        'register_ok' => [
            'group' => 'account', 'name' => '注册成功通知',
            'desc' => '用户注册成功后发送',
            'rule' => 'email_notify_register',
            'vars' => ['userName' => '用户名', 'email' => '绑定邮箱', 'time' => '注册时间'],
            'sample' => ['userName' => 'zhangsan', 'email' => 'zhangsan@qq.com', 'time' => date('Y-m-d H:i')],
        ],
        'user_login' => [
            'group' => 'account', 'name' => '用户登录成功',
            'desc' => '用户中心登录成功后发送',
            'rule' => 'email_notify_login',
            'vars' => ['userName' => '用户名', 'ip' => '登录 IP', 'time' => '登录时间'],
            'sample' => ['userName' => 'zhangsan', 'ip' => '203.0.113.66', 'time' => date('Y-m-d H:i:s')],
        ],
        'user_forgot' => [
            'group' => 'account', 'name' => '用户找回密码验证码',
            'desc' => '用户找回密码 / 敏感操作验证码',
            'rule' => 'email_notify_forgot',
            'vars' => ['userName' => '用户名', 'code' => '验证码', 'minutes' => '有效分钟数'],
            'sample' => ['userName' => 'zhangsan', 'code' => '258941', 'minutes' => '10'],
        ],
        'user_bind' => [
            'group' => 'account', 'name' => '用户绑定邮箱验证码',
            'desc' => '用户中心首次绑定 / 更换邮箱时发送到新邮箱的验证码',
            'rule' => 'email_notify_user_bind',
            'vars' => ['userName' => '用户名', 'code' => '验证码', 'minutes' => '有效分钟数'],
            'sample' => ['userName' => 'zhangsan', 'code' => '258941', 'minutes' => '10'],
        ],
        'agent_login' => [
            'group' => 'account', 'name' => '代理商登录成功',
            'desc' => '代理商中心登录成功后发送',
            'rule' => 'email_notify_agent_login',
            'vars' => ['userName' => '代理商账号', 'ip' => '登录 IP', 'time' => '登录时间'],
            'sample' => ['userName' => 'agent01', 'ip' => '203.0.113.66', 'time' => date('Y-m-d H:i:s')],
        ],
        'agent_forgot' => [
            'group' => 'account', 'name' => '代理商找回密码验证码',
            'desc' => '代理商找回密码验证码',
            'rule' => 'email_notify_agent_forgot',
            'vars' => ['userName' => '代理商账号', 'code' => '验证码', 'minutes' => '有效分钟数'],
            'sample' => ['userName' => 'agent01', 'code' => '258941', 'minutes' => '10'],
        ],
        'agent_bind' => [
            'group' => 'account', 'name' => '代理商绑定邮箱验证码',
            'desc' => '代理商中心首次绑定 / 更换邮箱时发送到新邮箱的验证码',
            'rule' => 'email_notify_agent_bind',
            'vars' => ['userName' => '代理商账号', 'code' => '验证码', 'minutes' => '有效分钟数'],
            'sample' => ['userName' => 'agent01', 'code' => '258941', 'minutes' => '10'],
        ],
        'admin_login' => [
            'group' => 'admin', 'name' => '管理员登录成功',
            'desc' => '后台管理员登录成功后通知管理员邮箱',
            'rule' => 'email_notify_admin_login',
            'vars' => ['userName' => '管理员账号', 'ip' => '登录 IP', 'time' => '登录时间'],
            'sample' => ['userName' => 'admin', 'ip' => '203.0.113.10', 'time' => date('Y-m-d H:i:s')],
        ],
        'admin_forgot' => [
            'group' => 'admin', 'name' => '管理员密码验证码',
            'desc' => '管理员找回密码 / 修改密码验证码',
            'rule' => 'email_notify_admin_forgot',
            'vars' => ['userName' => '管理员账号', 'code' => '验证码', 'minutes' => '有效分钟数'],
            'sample' => ['userName' => 'admin', 'code' => '639102', 'minutes' => '10'],
        ],
        'deliver' => [
            'group' => 'trade', 'name' => '产品开通 / 发卡通知',
            'desc' => '用户支付成功、系统自动发卡后发送',
            'rule' => 'email_notify_deliver',
            'vars' => ['userName' => '用户名', 'appName' => '应用名称', 'cardNo' => '卡密授权码', 'openTime' => '开通时间', 'expireTime' => '到期时间', 'orderNo' => '订单号'],
            'sample' => ['userName' => '张先生', 'appName' => '企业建站系统 V9', 'cardNo' => 'RC-A8F3K2QM9X', 'openTime' => date('Y-m-d H:i'), 'expireTime' => date('Y-m-d H:i', time() + 86400 * 365), 'orderNo' => 'PAY' . date('YmdHis')],
        ],
        'realname' => [
            'group' => 'trade', 'name' => '实名认证审核结果',
            'desc' => '管理员完成实名审核后发送',
            'rule' => 'email_notify_realname',
            'vars' => ['userName' => '用户名', 'result' => '审核结果', 'reason' => '驳回原因', 'time' => '审核时间'],
            'sample' => ['userName' => '张先生', 'result' => '已通过', 'reason' => '无', 'time' => date('Y-m-d H:i')],
        ],
        'withdraw' => [
            'group' => 'trade', 'name' => '提现审核结果',
            'desc' => '用户 / 代理商提现申请完成审核后发送',
            'rule' => 'email_notify_withdraw',
            'vars' => ['userName' => '账号', 'amount' => '提现金额', 'fee' => '手续费', 'receive' => '实际到账', 'account' => '收款账号', 'result' => '审核结果', 'reason' => '驳回原因', 'time' => '审核时间'],
            'sample' => ['userName' => '张先生', 'amount' => '200.00 元', 'fee' => '4.00 元', 'receive' => '196.00 元', 'account' => '支付宝：138****0001', 'result' => '已通过', 'reason' => '无', 'time' => date('Y-m-d H:i')],
        ],
        'recharge' => [
            'group' => 'trade', 'name' => '余额充值到账',
            'desc' => '在线充值支付成功、余额入账后发送',
            'rule' => 'email_notify_recharge',
            'vars' => ['userName' => '用户名', 'amount' => '充值金额', 'balance' => '到账后余额', 'orderNo' => '订单号', 'time' => '到账时间'],
            'sample' => ['userName' => '张先生', 'amount' => '100.00 元', 'balance' => '180.00 元', 'orderNo' => 'RC' . date('YmdHis'), 'time' => date('Y-m-d H:i')],
        ],
        'agent_recharge' => [
            'group' => 'trade', 'name' => '代理商充值到账',
            'desc' => '代理商在线充值到账后发送',
            'rule' => 'email_notify_agent_recharge',
            'vars' => ['userName' => '代理商账号', 'amount' => '充值金额', 'orderNo' => '订单号', 'time' => '到账时间'],
            'sample' => ['userName' => 'agent01', 'amount' => '500.00 元', 'orderNo' => 'RC' . date('YmdHis'), 'time' => date('Y-m-d H:i')],
        ],
        'invite_rebate' => [
            'group' => 'trade', 'name' => '邀请返利到账',
            'desc' => '名下邀请用户支付成功、邀请返利入账后发送给代理商',
            'rule' => 'email_notify_invite_rebate',
            'vars' => ['userName' => '代理商账号', 'buyerName' => '购买用户', 'rate' => '返利比例', 'amount' => '返利金额', 'orderNo' => '订单号', 'balance' => '到账后余额', 'time' => '到账时间'],
            'sample' => ['userName' => 'agent01', 'buyerName' => 'zhangsan', 'rate' => '10%', 'amount' => '29.80 元', 'orderNo' => 'PAY' . date('YmdHis'), 'balance' => '529.80 元', 'time' => date('Y-m-d H:i')],
        ],
        'expire' => [
            'group' => 'trade', 'name' => '卡密到期提醒',
            'desc' => '限时卡密临近到期时发送（后台扫描触发）',
            'rule' => 'email_notify_expire',
            'vars' => ['userName' => '用户名', 'days' => '剩余天数', 'cardList' => '到期卡密列表（HTML 表格）'],
            'sample' => ['userName' => '张先生', 'days' => '3', 'cardList' => '<table style="width:100%;border-collapse:collapse;font-size:14px;"><tr><td style="padding:8px 12px;border:1px solid #e5e6eb;background:#f7f8fa;">RC-A8F3K2QM9X</td><td style="padding:8px 12px;border:1px solid #e5e6eb;">' . date('Y-m-d H:i', time() + 86400 * 3) . ' 到期</td></tr></table>'],
        ],
        'prod_open_admin' => [
            'group' => 'admin', 'name' => '产品开通成功（管理员）',
            'desc' => '用户购买开通产品后同步通知管理员',
            'rule' => 'email_notify_prod_open',
            'vars' => ['userName' => '用户名', 'appName' => '应用名称', 'cardNo' => '卡密授权码', 'amount' => '订单金额', 'orderNo' => '订单号', 'time' => '开通时间'],
            'sample' => ['userName' => 'zhangsan', 'appName' => '企业建站系统 V9', 'cardNo' => 'RC-A8F3K2QM9X', 'amount' => '298.00 元', 'orderNo' => 'PAY' . date('YmdHis'), 'time' => date('Y-m-d H:i')],
        ],
        'alert' => [
            'group' => 'admin', 'name' => '安全告警（管理员）',
            'desc' => '接口高频访问等异常行为时通知管理员',
            'rule' => 'email_notify_alert',
            'vars' => ['title' => '告警标题', 'channel' => '触发通道', 'ip' => '来源 IP', 'count' => '访问频率', 'threshold' => '告警阈值', 'time' => '发生时间'],
            'sample' => ['title' => '接口高频访问', 'channel' => 'API 验证接口', 'ip' => '203.0.113.66', 'count' => '128 次/分钟', 'threshold' => '60 次/分钟', 'time' => date('Y-m-d H:i:s')],
        ],
        'system' => [
            'group' => 'admin', 'name' => '系统通知 / 测试发送',
            'desc' => '通用系统通知与 SMTP 测试发送',
            'rule' => 'email_notify_system',
            'vars' => ['title' => '通知标题', 'content' => '通知内容', 'time' => '发送时间'],
            'sample' => ['title' => '系统测试通知', 'content' => '这是一封测试邮件，收到即表示 SMTP 配置正确。', 'time' => date('Y-m-d H:i:s')],
        ],
        'ticket_new' => [
            'group' => 'ticket', 'name' => '工单提交通知（管理员）',
            'desc' => '用户 / 代理商提交工单或追加回复时通知管理员',
            'rule' => 'email_notify_ticket_new',
            'vars' => ['event' => '事件（提交了新工单/追加了新回复）', 'submitter' => '提交人账号', 'role' => '账号类型', 'ticketId' => '工单编号', 'category' => '问题分类', 'title' => '工单标题', 'content' => '工单内容', 'time' => '提交时间'],
            'sample' => ['event' => '提交了新工单', 'submitter' => 'zhangsan', 'role' => '用户', 'ticketId' => 'GD20260911153045A8F3', 'category' => '授权问题', 'title' => '卡密验证失败求助', 'content' => '卡密在 example.com 验证返回签名错误，请帮忙排查。', 'time' => date('Y-m-d H:i')],
        ],
        'ticket_reply' => [
            'group' => 'ticket', 'name' => '工单回复通知',
            'desc' => '管理员回复工单后通知提交人',
            'rule' => 'email_notify_ticket_reply',
            'vars' => ['userName' => '提交人账号', 'ticketId' => '工单编号', 'title' => '工单标题', 'content' => '回复内容', 'time' => '回复时间'],
            'sample' => ['userName' => 'zhangsan', 'ticketId' => 'GD20260911153045A8F3', 'title' => '卡密验证失败求助', 'content' => '您好，问题已定位并修复，请重新验证。', 'time' => date('Y-m-d H:i')],
        ],
    ];
}

function mail_scene_default_subj(string $scene): string
{
    $map = [
        'register_code'   => '注册验证码：{{code}}',
        'register_ok'     => '注册成功，欢迎使用',
        'user_login'      => '登录成功通知',
        'user_forgot'     => '找回密码验证码：{{code}}',
        'user_bind'       => '邮箱绑定验证码：{{code}}',
        'agent_login'     => '代理商中心登录成功通知',
        'agent_forgot'    => '代理商密码验证码：{{code}}',
        'agent_bind'      => '代理商邮箱绑定验证码：{{code}}',
        'admin_login'     => '后台登录成功通知',
        'admin_forgot'    => '管理员密码验证码：{{code}}',
        'prod_open_admin' => '产品开通成功通知',
        'deliver'         => '购买成功，授权已开通',
        'realname'        => '实名认证审核结果通知',
        'withdraw'        => '提现审核结果通知',
        'recharge'        => '余额充值到账通知',
        'agent_recharge'  => '代理商充值到账通知',
        'invite_rebate'   => '邀请返利到账通知',
        'expire'          => '卡密到期提醒',
        'alert'           => '{{title}}',
        'system'          => '{{title}}',
        'ticket_new'      => '工单通知：{{title}}',
        'ticket_reply'    => '工单新回复：{{title}}',
    ];
    return $map[$scene] ?? '系统通知';
}

function mail_scene_default_html(string $scene): string
{
    $site = '{{siteName}}';
    $cardHead = '<div style="background:#0052d9;border-radius:6px 6px 0 0;padding:18px 24px;font-size:17px;font-weight:600;color:#ffffff;">{{mainTitle}}</div>';
    $cardFoot = '<div style="border-top:1px solid #e5e6eb;padding:14px 24px;font-size:12px;color:#a1a6b3;">此邮件由 ' . $site . ' 系统自动发送，请勿直接回复。</div>';
    $wrap = function (string $inner) use ($site) {
        return '<!DOCTYPE html>' . "\n" . '<html lang="zh-CN">' . "\n" . '<body style="margin:0;padding:0;background-color:#f3f5f7;">' . "\n"
            . '<div style="max-width:640px;margin:0 auto;padding:24px 12px;font-family:\'PingFang SC\',\'Microsoft YaHei\',Arial,sans-serif;">' . "\n"
            . '<div style="font-size:16px;font-weight:600;color:#0052d9;padding:0 4px 12px;">' . $site . '</div>' . "\n"
            . $inner . "\n"
            . '<div style="padding:16px 4px;font-size:12px;color:#a1a6b3;">© {{year}} ' . $site . '</div>' . "\n"
            . '</div>' . "\n" . '</body>' . "\n" . '</html>';
    };
    $row = function (string $k, string $v) {
        return '<tr><td style="padding:8px 12px;border:1px solid #e5e6eb;background:#f7f8fa;width:110px;white-space:nowrap;">' . $k . '</td>'
            . '<td style="padding:8px 12px;border:1px solid #e5e6eb;word-break:break-all;">' . $v . '</td></tr>';
    };

    switch ($scene) {
        case 'register_code':
        case 'user_forgot':
        case 'user_bind':
        case 'agent_forgot':
        case 'agent_bind':
        case 'admin_forgot':
            $who = $scene === 'admin_forgot' ? '管理员账号：<strong>{{userName}}</strong><br>' : '尊敬的 <strong>{{userName}}</strong>，您好！';
            $purpose = ['register_code' => '注册', 'user_forgot' => '找回密码', 'user_bind' => '绑定邮箱', 'agent_forgot' => '找回密码', 'agent_bind' => '绑定邮箱', 'admin_forgot' => '重置 / 修改密码'][$scene];
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">' . $who . '</p>'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">您正在执行「' . $purpose . '」操作，验证码如下：</p>'
                . '<div style="text-align:center;margin:0 0 16px;"><span style="display:inline-block;padding:12px 32px;background:#f2f3f5;border-radius:6px;font-family:Consolas,Menlo,monospace;font-size:28px;font-weight:700;letter-spacing:8px;color:#0052d9;">{{code}}</span></div>'
                . '<p style="margin:0;font-size:12px;color:#86909c;">验证码 {{minutes}} 分钟内有效，请勿泄露给他人。若非本人操作，请忽略此邮件。</p>'
                . '</div>' . $cardFoot;
            break;
        case 'register_ok':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">尊敬的 <strong>{{userName}}</strong>，您好！</p>'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">您的账号注册成功，绑定邮箱：<strong>{{email}}</strong></p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                . $row('注册时间', '{{time}}')
                . '</table>'
                . '<p style="margin:14px 0 0;font-size:12px;color:#86909c;">请妥善保管账号密码，可在用户中心购买与兑换授权。</p>'
                . '</div>' . $cardFoot;
            break;
        case 'user_login':
        case 'agent_login':
        case 'admin_login':
            $roleName = ['user_login' => '用户中心', 'agent_login' => '代理商中心', 'admin_login' => '管理后台'][$scene];
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">账号 <strong>{{userName}}</strong> 刚刚登录了' . $roleName . '：</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                . $row('登录 IP', '<span style="font-family:Consolas,Menlo,monospace;">{{ip}}</span>')
                . $row('登录时间', '{{time}}')
                . '</table>'
                . '<p style="margin:14px 0 0;font-size:12px;color:#86909c;">若非本人操作，请立即修改密码并检查账号安全。</p>'
                . '</div>' . $cardFoot;
            break;
        case 'agent_recharge':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">代理商 <strong>{{userName}}</strong>，您好！</p>'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">您的余额充值已到账：</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                . $row('充值金额', '<span style="color:#0052d9;font-weight:600;">{{amount}}</span>')
                . $row('订单号', '{{orderNo}}')
                . $row('到账时间', '{{time}}')
                . '</table></div>' . $cardFoot;
            break;
        case 'prod_open_admin':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">有用户成功开通产品，订单信息如下：</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                . $row('用户', '{{userName}}')
                . $row('应用名称', '{{appName}}')
                . $row('卡密授权码', '<span style="font-family:Consolas,Menlo,monospace;color:#0052d9;font-weight:600;">{{cardNo}}</span>')
                . $row('订单金额', '{{amount}}')
                . $row('订单号', '{{orderNo}}')
                . $row('开通时间', '{{time}}')
                . '</table></div>' . $cardFoot;
            break;
        case 'deliver':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">尊敬的 <strong>{{userName}}</strong>，您好！</p>'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">您购买的 <strong>{{appName}}</strong> 授权已开通，以下是您的授权信息：</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                . $row('卡密授权码', '<span style="font-family:Consolas,Menlo,monospace;color:#0052d9;font-weight:600;">{{cardNo}}</span>')
                . $row('所属应用', '{{appName}}')
                . $row('订单号', '{{orderNo}}')
                . $row('开通时间', '{{openTime}}')
                . $row('到期时间', '{{expireTime}}')
                . '</table>'
                . '<p style="margin:14px 0 0;font-size:12px;color:#86909c;">请妥善保管您的授权信息，不要泄露给他人。</p>'
                . '</div>' . $cardFoot;
            break;
        case 'realname':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">尊敬的 <strong>{{userName}}</strong>，您好！</p>'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">您的实名认证已完成审核，结果如下：</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                . $row('审核结果', '<span style="color:#00b42a;font-weight:600;">{{result}}</span>')
                . $row('驳回原因', '{{reason}}')
                . $row('审核时间', '{{time}}')
                . '</table></div>' . $cardFoot;
            break;
        case 'withdraw':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">尊敬的 <strong>{{userName}}</strong>，您好！</p>'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">您的提现申请已完成审核，结果如下：</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                . $row('提现金额', '{{amount}}')
                . $row('手续费', '{{fee}}')
                . $row('实际到账', '{{receive}}')
                . $row('收款账号', '{{account}}')
                . $row('审核结果', '<span style="color:#00b42a;font-weight:600;">{{result}}</span>')
                . $row('驳回原因', '{{reason}}')
                . $row('审核时间', '{{time}}')
                . '</table></div>' . $cardFoot;
            break;
        case 'recharge':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">尊敬的 <strong>{{userName}}</strong>，您好！</p>'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">您的余额充值已到账：</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                . $row('充值金额', '<span style="color:#0052d9;font-weight:600;">{{amount}}</span>')
                . $row('到账后余额', '{{balance}}')
                . $row('订单号', '{{orderNo}}')
                . $row('到账时间', '{{time}}')
                . '</table></div>' . $cardFoot;
            break;
        case 'expire':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">尊敬的 <strong>{{userName}}</strong>，您好！</p>'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">您名下有限时卡密将在 <strong>{{days}}</strong> 天内到期，请及时续期：</p>'
                . '{{cardList}}'
                . '<p style="margin:14px 0 0;font-size:12px;color:#86909c;">到期后卡密将无法继续验证，可在用户中心购买新授权。</p>'
                . '</div>' . $cardFoot;
            break;
        case 'invite_rebate':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">尊敬的 <strong>{{userName}}</strong>，您好！</p>'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">您邀请的用户支付成功，邀请返利已入账：</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                . $row('购买用户', '{{buyerName}}')
                . $row('返利比例', '{{rate}}')
                . $row('返利金额', '<span style="color:#00b42a;font-weight:600;">{{amount}}</span>')
                . $row('订单号', '{{orderNo}}')
                . $row('到账后余额', '{{balance}}')
                . $row('到账时间', '{{time}}')
                . '</table></div>' . $cardFoot;
            break;
        case 'alert':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">检测到异常行为，请及时在后台「IP 封禁」中处理：</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                . $row('触发通道', '{{channel}}')
                . $row('来源 IP', '<span style="font-family:Consolas,Menlo,monospace;">{{ip}}</span>')
                . $row('访问频率', '{{count}}')
                . $row('告警阈值', '{{threshold}}')
                . $row('发生时间', '{{time}}')
                . '</table></div>' . $cardFoot;
            break;
        case 'ticket_new':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;"><strong>{{role}}</strong> <strong>{{submitter}}</strong> {{event}}，请及时在后台「工单管理」中处理：</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                . $row('工单编号', '#{{ticketId}}')
                . $row('问题分类', '{{category}}')
                . $row('工单标题', '{{title}}')
                . $row('提交时间', '{{time}}')
                . '</table>'
                . '<p style="margin:14px 0 6px;font-size:14px;color:#1d2129;">工单内容：</p>'
                . '<div style="background:#f7f8fa;border:1px solid #e5e6eb;border-radius:6px;padding:12px 16px;font-size:13px;color:#4e5969;white-space:pre-wrap;word-break:break-all;">{{content}}</div>'
                . '</div>' . $cardFoot;
            break;
        case 'ticket_reply':
            $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">尊敬的 <strong>{{userName}}</strong>，您好！</p>'
                . '<p style="margin:0 0 14px;font-size:14px;color:#1d2129;">您的工单 <strong>#{{ticketId}}「{{title}}」</strong> 有新回复：</p>'
                . '<div style="background:#f7f8fa;border:1px solid #e5e6eb;border-radius:6px;padding:12px 16px;font-size:13px;color:#4e5969;white-space:pre-wrap;word-break:break-all;">{{content}}</div>'
                . '<p style="margin:14px 0 0;font-size:12px;color:#86909c;">回复时间：{{time}}。可登录站点查看完整对话并继续回复。</p>'
                . '</div>' . $cardFoot;
            break;
        default:             $inner = $cardHead
                . '<div style="background:#ffffff;border:1px solid #e5e6eb;border-top:none;border-radius:0 0 6px 6px;padding:20px 24px;">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#1d2129;">{{content}}</p>'
                . '<p style="margin:14px 0 0;font-size:12px;color:#86909c;">发送时间：{{time}}</p>'
                . '</div>' . $cardFoot;
    }
    return $wrap($inner);
}

function mail_scene_render(string $scene, array $vars = []): array
{
    $defs = mail_scenes();
    if (!isset($defs[$scene])) {
        $scene = 'system';
        $defs = mail_scenes();
    }
    $subject = mail_cfg('email_subj_' . $scene, '');
    $tplHtml = mail_cfg('email_tpl_' . $scene, '');
    $custom = $subject !== '' || $tplHtml !== '';
    if ($subject === '') {
        $subject = mail_scene_default_subj($scene);
    }
    if ($tplHtml === '') {
        $tplHtml = mail_scene_default_html($scene);
    }

    $vars += ['siteName' => cfg('site_name', 'ZeroAuth'), 'year' => date('Y')];
    unset($vars['mainTitle']);

    $search = [];
    $replace = [];
    foreach ($vars as $k => $v) {
        $search[] = '{{' . $k . '}}';
        if (substr((string)$k, -5) === '_html') {
            $replace[] = (string)$v;
        } else {
            $replace[] = e((string)$v);
        }
    }
    $subject = str_replace($search, $replace, $subject);
    $tplHtml = str_replace($search, $replace, $tplHtml);
    if (strpos($tplHtml, '{{mainTitle}}') !== false) {
        $tplHtml = str_replace('{{mainTitle}}', str_replace($search, $replace, mail_scene_default_subj($scene)), $tplHtml);
    }
    $subject = preg_replace('/\{\{[a-zA-Z_][a-zA-Z0-9_]*\}\}/', '', $subject);
    $tplHtml = preg_replace('/\{\{[a-zA-Z_][a-zA-Z0-9_]*\}\}/', '', $tplHtml);
    return ['subject' => $subject, 'html' => $tplHtml];
}

function mail_scene_send(string $scene, string $to, array $vars = []): bool
{
    if (cfg('email_enabled', '1') !== '1') {
        return false;
    }
    $defs = mail_scenes();
    if (isset($defs[$scene]) && cfg($defs[$scene]['rule'], '1') !== '1') {
        return false;
    }
    $mail = mail_scene_render($scene, $vars);
    if ($to === '') {
        $addrs = array_filter(array_map('trim', explode(',', mail_cfg('alert_email', ''))));
        if (!$addrs) {
            try {
                $addrs = db()->query('SELECT `email` FROM `admin` WHERE `email`<>\'\'')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $ex) {
                $addrs = [];
            }
        }
        $ok = false;
        foreach (array_unique($addrs) as $addr) {
            $ok = mail_send((string)$addr, $mail['subject'], $mail['html']) || $ok;
        }
        return $ok;
    }
    return mail_send($to, $mail['subject'], $mail['html']);
}

function mail_code_available(): bool
{
    return cfg('email_enabled', '1') === '1';
}

function mail_code_send(string $scene, string $email, array $vars = []): array
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => '该账号未绑定有效邮箱，无法发送验证码。'];
    }
    if (!mail_code_available()) {
        return ['ok' => false, 'msg' => '邮件功能未开启，请联系管理员。'];
    }
    $_SESSION['mail_code'] ??= [];
    $box = &$_SESSION['mail_code'][$scene];
    if (isset($box['last']) && time() - (int)$box['last'] < 60) {
        $wait = 60 - (time() - (int)$box['last']);
        return ['ok' => false, 'msg' => '发送过于频繁，请 ' . $wait . ' 秒后再试。'];
    }
    $code = (string)random_int(100000, 999999);
    $ok = mail_scene_send($scene, $email, $vars + [
        'code'    => $code,
        'minutes' => '10',
    ]);
    if (!$ok) {
        return ['ok' => false, 'msg' => '验证码发送失败，请检查 SMTP 配置或稍后再试。'];
    }
    $box = ['code' => $code, 'email' => $email, 'exp' => time() + 600, 'last' => time()];
    return ['ok' => true, 'msg' => '验证码已发送，请查收邮件（10 分钟内有效）。'];
}

function mail_code_verify(string $scene, string $email, string $input): bool
{
    $box = $_SESSION['mail_code'][$scene] ?? null;
    if (!is_array($box) || (int)$box['exp'] < time()) {
        return false;
    }
    if (!hash_equals((string)$box['email'], $email) || !hash_equals((string)$box['code'], trim($input))) {
        return false;
    }
    unset($_SESSION['mail_code'][$scene]);
    return true;
}
