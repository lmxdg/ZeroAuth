# API 接口文档

所有 API 均为 JSON 输出，统一限流 + IP 封禁检查，验证行为记录到 `verify_log`。

---

## 1. 授权校验接口 `POST /api/license/verify/`

新版协议（v1 / v2 双签名版本），推荐所有新接入使用 **v2**。

### 请求（JSON Body）

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `appKey` | string | 是 | 应用 AppKey |
| `licenseKey` | string | 二选一 | 卡密（与 domain / serverIp 至少传一项） |
| `domain` | string | 二选一 | 域名（自动归一化，小写去 www） |
| `serverIp` | string | 二选一 | 服务器 IP（IP 授权方式） |
| `timestamp` | int | 是 | Unix 秒级时间戳（±10 分钟窗口） |
| `signVersion` | string | 是 | `v1` 或 `v2` |
| `sign` | string | 是 | 签名（小写十六进制） |

### 签名算法

```text
v2: sign = hash_hmac('sha256', "v2\n" + appKey + "\n" + licenseKey + "\n" + domain + "\n" + serverIp + "\n" + timestamp, appSecret)
v1（兼容旧 SDK）: sign = md5(appKey + target + timestamp + appSecret)   // target = licenseKey 或 domain
```

### 响应

```jsonc
// 200 验证通过
{ "code": 200, "msg": "授权有效", "data": { "result": "pass", "appName": "我的应用", "expireAt": "2026-12-31 23:59:59", "licenseKey": "XXXX-XXXX-..." } }

// 403 验证失败
{ "code": 403, "msg": "授权已过期，需要续费或重新开通", "data": { "result": "expired", "reason": "expired" } }
```

### result / reason 码表

| result | 含义 |
|---|---|
| `pass` | 授权有效 |
| `fail` | 无效（配合 reason 定位） |
| `expired` | 已过期 |
| `blacklisted` | 命中黑名单，应立即阻断访问 |

常见 `reason`：`app_not_found`、`app_disabled`、`license_not_found`、`license_disabled`、`not_started`、`expired`、`target_mismatch`、`count_exhausted`、`site_limit_exceeded`、`site_unbound`、`signature_upgrade_required`（v1 密钥授权新站点首次绑定必须升级 v2）、`sign_error`、`timestamp_out_of_window`、`missing_*`。

### 服务端行为

- 按 `sha256(appKey|licenseKey|target)` 自动绑定站点（受 `max_devices` 上限约束）
- 计次授权验证一次扣减一次（`used_count < total_count` 原子扣减）
- 限流：120 次/分钟/IP

---

## 2. 卡密核验接口 `POST /api/auth.php`（v1 旧协议）

参数通过 `sec_params()` 接收：`app_id`、`card_no`、`domain`、`device_code`、`timestamp`、`nonce`、`sign`（HMAC 签名 + nonce 防重放）。黑名单命中（设备码 / 卡密 / 域名）返回 403 并写告警中心。兼容老版本 SDK，新接入请使用 `api/license/verify`。

---

## 3. 云变量接口 `POST /api/cloud_var.php`

- 参数：`app_id`（必填）
- **公开变量**：无需签名，直接返回该应用公开云变量
- **私有变量**：`private=1` 时需附 `timestamp`、`nonce`、`sign`（HMAC + nonce 防重放）
- 限流与封禁检查同上；云变量在后台「云变量管理」中维护

---

## 4. 应用版本更新 `POST /api/app/version/check/` 与 `POST /api/app/version/download/`

`check` 接口请求体与 `verify` 类似（`appKey`、`currentVersion` 必填，可选 `licenseKey` / `domain` / `serverIp` + v2 签名），返回是否有新版本及版本信息；`download` 使用服务端签发的一次性下载令牌获取更新包（`license_api_download_token`，绑定 IP 与时效）。

---

## 5. 支付相关

- `POST /api/pay_callback.php`：各支付插件的统一回调入口
- `POST /api/order_status.php`：订单状态查询

---

## 调用示例（v2 签名）

```php
$appKey = '你的AppKey'; $appSecret = '你的AppSecret';
$ts = time();
$sign = hash_hmac('sha256', "v2\n$appKey\n\nexample.com\n\n$ts", $appSecret);
$resp = file_get_contents('https://你的授权站/api/license/verify/', false, stream_context_create([
    'http' => ['method' => 'POST', 'header' => 'Content-Type: application/json',
        'content' => json_encode(['appKey' => $appKey, 'domain' => 'example.com',
            'timestamp' => $ts, 'signVersion' => 'v2', 'sign' => $sign])]
]));
$data = json_decode($resp, true);
if (($data['data']['result'] ?? '') === 'pass') { /* 授权有效 */ }
```
