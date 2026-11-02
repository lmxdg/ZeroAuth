<?php
// 腾讯云人脸核身插件公共函数：TC3-HMAC-SHA256 签名请求 / DetectAuth / GetDetectInfoEnhanced / 核身日志
// 接口文档：https://cloud.tencent.com/document/product/1007
//   实名核身鉴权（发起）：https://cloud.tencent.com/document/product/1007/31816
//   获取实名核身结果信息增强版：https://cloud.tencent.com/document/product/1007/41957
declare(strict_types=1);

if (!defined('ZEROAUTH_TENCENT_FACEID_LOADED')) {
    define('ZEROAUTH_TENCENT_FACEID_LOADED', 1);
}

const FACEID_API_HOST = 'faceid.tencentcloudapi.com';
const FACEID_API_SERVICE = 'faceid';
const FACEID_API_VERSION = '2018-03-01';
const FACEID_TOKEN_TTL = 7200; // BizToken 有效时间 7200 秒

/** 插件配置读取 */
function faceid_cfg(string $key, string $default = ''): string
{
    return plugin_cfg_get('tencent_faceid', $key, $default);
}

/** 插件是否可用（已启用且密钥配置完整） */
function faceid_ready(): bool
{
    if (!plugin_enabled('tencent_faceid')) {
        return false;
    }
    return faceid_cfg('secret_id') !== ''
        && faceid_cfg('secret_key') !== ''
        && faceid_cfg('rule_id') !== '';
}

/** 是否要求先填姓名 + 证件号 */
function faceid_require_name(): bool
{
    return faceid_cfg('require_name', '1') === '1';
}

/** 核验通过后是否自动完成实名 */
function faceid_auto_pass(): bool
{
    return faceid_cfg('auto_pass', '1') === '1';
}

/** 核身日志表懒创建 */
function faceid_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS `tencent_faceid_log` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
            `biz_token` varchar(64) NOT NULL DEFAULT '' COMMENT '腾讯云 BizToken',
            `real_name` varchar(64) NOT NULL DEFAULT '' COMMENT '提交姓名（脱敏展示）',
            `id_card` varchar(255) NOT NULL DEFAULT '' COMMENT '证件号（AES 加密存储）',
            `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0进行中 1通过 2未通过 3已失效',
            `err_code` int NOT NULL DEFAULT 0 COMMENT '腾讯云结果 ErrCode',
            `err_msg` varchar(255) NOT NULL DEFAULT '' COMMENT '结果描述',
            `extra` varchar(255) NOT NULL DEFAULT '' COMMENT '透传信息',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `finished_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_biz_token` (`biz_token`),
            KEY `user` (`user_id`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='腾讯云人脸核身记录'");
    } catch (Throwable $ex) {
        @file_put_contents(RUNTIME_PATH . '/schema_upgrade.log', date('Y-m-d H:i:s') . ' tencent_faceid_log: ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

/**
 * 腾讯云 API 3.0 请求（TC3-HMAC-SHA256 签名）
 * 返回 ['ok' => bool, 'msg' => string, 'data' => array]
 */
function faceid_api(string $action, array $payload): array
{
    $secretId  = faceid_cfg('secret_id');
    $secretKey = faceid_cfg('secret_key');
    if ($secretId === '' || $secretKey === '') {
        return ['ok' => false, 'msg' => '尚未配置腾讯云 API 密钥。', 'data' => []];
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['ok' => false, 'msg' => '请求参数序列化失败。', 'data' => []];
    }

    $timestamp = time();
    $date = gmdate('Y-m-d', $timestamp);
    $contentType = 'application/json; charset=utf-8';

    $hashedPayload = hash('sha256', $body);
    $canonicalHeaders = 'content-type:' . $contentType . "\n" . 'host:' . FACEID_API_HOST . "\n";
    $canonicalRequest = 'POST' . "\n" . '/' . "\n" . '' . "\n" . $canonicalHeaders . "\n"
        . 'content-type;host' . "\n" . $hashedPayload;
    $stringToSign = 'TC3-HMAC-SHA256' . "\n" . (string)$timestamp . "\n"
        . $date . '/' . FACEID_API_SERVICE . '/tc3_request' . "\n" . hash('sha256', $canonicalRequest);

    $secretDate    = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
    $secretService = hash_hmac('sha256', FACEID_API_SERVICE, $secretDate, true);
    $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
    $signature     = hash_hmac('sha256', $stringToSign, $secretSigning);

    $authorization = 'TC3-HMAC-SHA256'
        . ' Credential=' . $secretId . '/' . $date . '/' . FACEID_API_SERVICE . '/tc3_request'
        . ', SignedHeaders=content-type;host'
        . ', Signature=' . $signature;

    $headers = [
        'Authorization: ' . $authorization,
        'Content-Type: ' . $contentType,
        'X-TC-Action: ' . $action,
        'X-TC-Version: ' . FACEID_API_VERSION,
        'X-TC-Timestamp: ' . (string)$timestamp,
        'X-TC-Language: zh-CN',
    ];

    $resp = http_post('https://' . FACEID_API_HOST . '/', $body, $headers, 15);
    if ($resp === false || $resp === '') {
        return ['ok' => false, 'msg' => '腾讯云接口连接失败，请稍后重试。', 'data' => []];
    }
    $j = json_decode((string)$resp, true);
    if (!is_array($j) || !isset($j['Response'])) {
        return ['ok' => false, 'msg' => '腾讯云接口返回异常，请稍后重试。', 'data' => []];
    }
    $r = $j['Response'];
    if (isset($r['Error'])) {
        $code = (string)($r['Error']['Code'] ?? '');
        $msg  = (string)($r['Error']['Message'] ?? '未知错误');
        return ['ok' => false, 'msg' => '腾讯云接口错误[' . $code . ']：' . $msg, 'data' => $r];
    }
    return ['ok' => true, 'msg' => 'success', 'data' => $r];
}

/**
 * 发起实名核身（DetectAuth）
 * 返回 ['ok' => bool, 'msg' => string, 'url' => string, 'token' => string, 'log_id' => int]
 */
function faceid_detect_auth(int $userId, string $userName = '', string $idCard = ''): array
{
    faceid_table();
    $ruleId = faceid_cfg('rule_id');
    if ($ruleId === '') {
        return ['ok' => false, 'msg' => '插件未配置 RuleId（业务流程 ID）。', 'url' => '', 'token' => '', 'log_id' => 0];
    }

    $payload = ['RuleId' => $ruleId];
    if ($userName !== '') {
        $payload['Name'] = $userName;
    }
    if ($idCard !== '') {
        $payload['IdCard'] = $idCard;
    }
    // H5 场景：核身完成后回跳站点，腾讯云会在回跳地址上携带本次核身标识
    $payload['RedirectUrl'] = base_url() . 'plugin/tencent_faceid/callback.php';
    $payload['Extra'] = 'uid=' . $userId;

    $res = faceid_api('DetectAuth', $payload);
    if (!$res['ok']) {
        return ['ok' => false, 'msg' => $res['msg'], 'url' => '', 'token' => '', 'log_id' => 0];
    }
    $url   = (string)($res['data']['Url'] ?? '');
    $token = (string)($res['data']['BizToken'] ?? '');
    if ($url === '' || $token === '') {
        return ['ok' => false, 'msg' => '腾讯云未返回核身链接，请稍后重试。', 'url' => '', 'token' => '', 'log_id' => 0];
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO `tencent_faceid_log` (`user_id`,`biz_token`,`real_name`,`id_card`,`status`,`err_code`,`err_msg`,`extra`,`created_at`) '
            . 'VALUES (?,?,?,?,0,0,?,?,?)'
        );
        $enc = $idCard !== '' ? sec_id_encrypt($idCard) : '';
        $stmt->execute([$userId, $token, $userName, $enc, '', 'uid=' . $userId, time()]);
        $logId = (int)db()->lastInsertId();
    } catch (Throwable $ex) {
        @file_put_contents(RUNTIME_PATH . '/schema_upgrade.log', date('Y-m-d H:i:s') . ' faceid日志写入失败 ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
        $logId = 0;
    }
    audit('user', 'faceid_start', 3, $userId, '发起腾讯云人脸核身（BizToken ' . substr($token, 0, 8) . '…）');
    return ['ok' => true, 'msg' => 'success', 'url' => $url, 'token' => $token, 'log_id' => $logId];
}

/**
 * 拉取核身结果（GetDetectInfoEnhanced，InfoType=1 文本类）
 * 返回 ['ok' => bool, 'msg' => string, 'passed' => bool, 'err_code' => int, 'err_msg' => string,
 *       'name' => string, 'id_card' => string, 'extra' => string]
 */
function faceid_get_result(string $bizToken): array
{
    $ruleId = faceid_cfg('rule_id');
    $res = faceid_api('GetDetectInfoEnhanced', [
        'BizToken' => $bizToken,
        'RuleId'   => $ruleId !== '' ? $ruleId : '0',
        'InfoType' => '1',
    ]);
    $fail = static function (string $msg): array {
        return ['ok' => false, 'msg' => $msg, 'passed' => false, 'err_code' => -1, 'err_msg' => $msg,
            'name' => '', 'id_card' => '', 'extra' => ''];
    };
    if (!$res['ok']) {
        return $fail($res['msg']);
    }
    $text = $res['data']['Text'] ?? null;
    if (!is_array($text)) {
        return $fail('核身流程尚未完成或结果未生成，请完成刷脸后重试。');
    }
    $errCode = (int)($text['ErrCode'] ?? -1);
    $errMsg  = (string)($text['ErrMsg'] ?? '');
    return [
        'ok'      => true,
        'msg'     => 'success',
        'passed'  => $errCode === 0,
        'err_code'=> $errCode,
        'err_msg' => $errMsg !== '' ? $errMsg : ($errCode === 0 ? '核验通过' : '核验未通过'),
        'name'    => (string)($text['Name'] ?? ''),
        'id_card' => (string)($text['IdCard'] ?? ''),
        'extra'   => (string)($text['Extra'] ?? ''),
    ];
}

/** 用户当前进行中的核身记录（BizToken 有效期内） */
function faceid_pending_log(int $userId): ?array
{
    faceid_table();
    try {
        $stmt = db()->prepare(
            'SELECT * FROM `tencent_faceid_log` WHERE `user_id`=? AND `status`=0 AND `created_at`>? ORDER BY `id` DESC LIMIT 1'
        );
        $stmt->execute([$userId, time() - FACEID_TOKEN_TTL]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    } catch (Throwable $ex) {
        return null;
    }
}

/** 用户最近一次已完成的核身记录 */
function faceid_last_log(int $userId): ?array
{
    faceid_table();
    try {
        $stmt = db()->prepare(
            'SELECT * FROM `tencent_faceid_log` WHERE `user_id`=? AND `status` IN (1,2) ORDER BY `id` DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    } catch (Throwable $ex) {
        return null;
    }
}
