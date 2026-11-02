<?php
// SDK 模板语言注册表与内置多语言模板
declare(strict_types=1);

/**
 * 支持的 SDK 模板语言。
 *
 * @return array<string,array{label:string,ext:string,hl:string,desc:string}>
 */
function sdk_language_map(): array
{
    return [
        'php'    => ['label' => 'PHP',     'ext' => 'php',  'hl' => 'php',        'desc' => 'PHP 8.x，curl / 文件流双通道，适合 Web 与 CLI'],
        'java'   => ['label' => 'Java',    'ext' => 'java', 'hl' => 'java',       'desc' => 'Java 8+，JDK 标准库，无第三方依赖'],
        'python' => ['label' => 'Python',  'ext' => 'py',   'hl' => 'python',     'desc' => 'Python 3.8+，仅依赖标准库'],
        'go'     => ['label' => 'Go',      'ext' => 'go',   'hl' => 'go',         'desc' => 'Go 1.18+，标准库 net/http'],
        'nodejs' => ['label' => 'Node.js', 'ext' => 'js',   'hl' => 'javascript', 'desc' => 'Node.js 16+，内置 http / https，无需 npm 依赖'],
        'cpp'    => ['label' => 'C++',     'ext' => 'h',    'hl' => 'cpp',        'desc' => 'C++17 头文件，依赖 libcurl 与 OpenSSL'],
    ];
}

function sdk_language_label(string $lang): string
{
    $map = sdk_language_map();
    return $map[$lang]['label'] ?? strtoupper($lang);
}

function sdk_language_ext(string $lang): string
{
    $map = sdk_language_map();
    return $map[$lang]['ext'] ?? 'txt';
}

function sdk_language_hl(string $lang): string
{
    $map = sdk_language_map();
    return $map[$lang]['hl'] ?? 'text';
}

function sdk_language_valid(string $lang): bool
{
    return isset(sdk_language_map()[$lang]);
}

/**
 * 内置多语言 SDK 模板（导出时替换 {{app_id}} / {{app_key}} / {{api_base}}）。
 *
 * @return array<int,array{name:string,language:string,content:string}>
 */
function sdk_builtin_templates(): array
{
    $php = <<<'PHP_SDK'
<?php
/**
 * ZeroAuth 云鉴权 V2 SDK（PHP 8.x）
 * 导出时自动替换：{{app_id}} / {{app_key}} / {{api_base}}
 * 安全说明：AppKey 为应用公开标识；AppSecret 由 /api/get_secret.php 动态下发，仅在内存中缓存。
 */
class LicenseSdkV2
{
    private string $appId = '{{app_id}}';
    private string $appKey = '{{app_key}}';
    private string $apiBase = '{{api_base}}';

    private static ?array $secretCache = null;
    private static int $cacheExpireAt = 0;
    private int $cacheTtl = 1800;

    public function verify(string $licenseKey = ''): array
    {
        $secret = $this->fetchSecret();
        if ($secret === null) {
            return ['ok' => false, 'result' => 'fail', 'reason' => 'secret_fetch_failed', 'msg' => '密钥拉取失败'];
        }
        [$domain, $serverIp] = $this->siteInfo();
        $ts   = time();
        $sign = hash_hmac('sha256', implode("\n", ['v2', $this->appKey, $licenseKey, $domain, $serverIp, (string)$ts]), (string)$secret['appsecret']);

        $resp = $this->httpPostJson($this->apiBase . '/api/license/verify', [
            'appKey'      => $this->appKey,
            'licenseKey'  => $licenseKey,
            'domain'      => $domain,
            'serverIp'    => $serverIp,
            'timestamp'   => $ts,
            'signVersion' => 'v2',
            'sign'        => $sign,
        ]);
        if ($resp === null) {
            return ['ok' => false, 'result' => 'fail', 'reason' => 'network_error', 'msg' => '授权服务不可达'];
        }
        $result = (string)($resp['data']['result'] ?? 'fail');
        return [
            'ok'     => $result === 'pass',
            'result' => $result,
            'reason' => (string)($resp['data']['reason'] ?? ''),
            'msg'    => (string)($resp['msg'] ?? ''),
            'data'   => is_array($resp['data'] ?? null) ? $resp['data'] : [],
        ];
    }

    public function versionCheck(string $currentVersion, string $licenseKey = ''): array
    {
        $secret = $this->fetchSecret();
        if ($secret === null) {
            return ['ok' => false, 'msg' => '密钥拉取失败'];
        }
        [$domain, $serverIp] = $this->siteInfo();
        $ts   = time();
        $sign = hash_hmac('sha256', implode("\n", ['v2', $this->appKey, $currentVersion, $licenseKey, $domain, $serverIp, (string)$ts]), (string)$secret['appsecret']);

        $resp = $this->httpPostJson($this->apiBase . '/api/app/version/check', [
            'appKey'         => $this->appKey,
            'currentVersion' => $currentVersion,
            'licenseKey'     => $licenseKey,
            'domain'         => $domain,
            'serverIp'       => $serverIp,
            'timestamp'      => $ts,
            'signVersion'    => 'v2',
            'sign'           => $sign,
        ]);
        if ($resp === null) {
            return ['ok' => false, 'msg' => '授权服务不可达'];
        }
        if ((int)($resp['code'] ?? 0) !== 200) {
            return ['ok' => false, 'result' => (string)($resp['data']['result'] ?? 'fail'), 'msg' => (string)($resp['msg'] ?? '版本检查失败')];
        }
        $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        return ['ok' => true, 'hasUpdate' => !empty($data['hasUpdate']), 'data' => $data];
    }

    public function fetchSecret(): ?array
    {
        $now = time();
        if (self::$secretCache !== null && $now < self::$cacheExpireAt) {
            return self::$secretCache;
        }

        $params = [
            'app_id'      => $this->appId,
            'domain'      => $_SERVER['HTTP_HOST'] ?? '',
            'device_code' => $this->deviceCode(),
            'timestamp'   => $now,
            'nonce'       => bin2hex(random_bytes(8)),
        ];
        ksort($params);
        $params['sign'] = hash_hmac('sha256', http_build_query($params), $this->appId);

        $resp = $this->httpGet($this->apiBase . '/api/get_secret.php?' . http_build_query($params));
        if ($resp === null || (int)($resp['code'] ?? 0) !== 200 || !is_array($resp['data'] ?? null)) {
            return null;
        }

        $data = $resp['data'];
        $ttl  = (int)($data['cache_ttl'] ?? $this->cacheTtl);
        self::$secretCache   = $data;
        self::$cacheExpireAt = $now + $ttl;
        return $data;
    }

    private function siteInfo(): array
    {
        $domain   = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        $serverIp = (string)($_SERVER['SERVER_ADDR'] ?? '');
        if ($serverIp === '') {
            $serverIp = (string)(gethostbyname((string)gethostname()) ?: '');
        }
        return [$domain, $serverIp];
    }

    private function deviceCode(): string
    {
        $raw = php_uname('m') . '|' . php_uname('s') . '|' . php_uname('r') . '|' . ($_SERVER['SERVER_NAME'] ?? '');
        return hash('sha256', $raw);
    }

    private function httpGet(string $url): ?array
    {
        $raw = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
            $raw = curl_exec($ch);
            $err = curl_errno($ch);
            curl_close($ch);
            if ($err !== 0 || $raw === false) {
                $raw = false;
            }
        }
        if ($raw === false && ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $raw = @file_get_contents($url, false, $ctx);
        }
        if ($raw === false || $raw === '') {
            return null;
        }
        return json_decode($raw, true);
    }

    private function httpPostJson(string $url, array $body): ?array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $raw  = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($json)],
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $raw = curl_exec($ch);
            $err = curl_errno($ch);
            curl_close($ch);
            if ($err !== 0 || $raw === false) {
                $raw = false;
            }
        }
        if ($raw === false && ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 8, 'ignore_errors' => true, 'header' => "Content-Type: application/json\r\n", 'content' => $json]]);
            $raw = @file_get_contents($url, false, $ctx);
        }
        if ($raw === false || $raw === '') {
            return null;
        }
        return json_decode($raw, true);
    }
}
PHP_SDK;

    $python = <<<'PY_SDK'
# -*- coding: utf-8 -*-
# ZeroAuth 云鉴权 V2 SDK（Python 3.8+，仅标准库）
# 导出时自动替换：{{app_id}} / {{app_key}} / {{api_base}}
import hashlib
import hmac
import json
import os
import platform
import time
import urllib.parse
import urllib.request

APP_ID = "{{app_id}}"
APP_KEY = "{{app_key}}"
API_BASE = "{{api_base}}"
DOMAIN = ""       # 留空则使用环境变量 HTTP_HOST 或 socket 主机名
SERVER_IP = ""    # 留空由服务端按授权密钥反查

_secret_cache = {"data": None, "expire": 0}


def _http_json(url, payload=None, timeout=8):
    body = None if payload is None else json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=body,
        headers={"Content-Type": "application/json", "User-Agent": "APEAuth-SDK/2.0"},
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except Exception:
        return None


def _device_code():
    raw = "|".join([
        platform.machine(),
        platform.system(),
        platform.release(),
        platform.node(),
    ])
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def site_info():
    domain = DOMAIN or os.environ.get("HTTP_HOST", "") or platform.node()
    server_ip = SERVER_IP
    return domain.split(":")[0].lower(), server_ip


def fetch_secret(force=False):
    now = int(time.time())
    if not force and _secret_cache["data"] and now < _secret_cache["expire"]:
        return _secret_cache["data"]

    params = {
        "app_id": APP_ID,
        "domain": site_info()[0],
        "device_code": _device_code(),
        "timestamp": str(now),
        "nonce": os.urandom(8).hex(),
    }
    base_query = urllib.parse.urlencode(sorted(params.items()))
    params["sign"] = hmac.new(APP_ID.encode("utf-8"), base_query.encode("utf-8"), hashlib.sha256).hexdigest()

    resp = _http_json(API_BASE + "/api/get_secret.php?" + urllib.parse.urlencode(params), timeout=5)
    if not resp or int(resp.get("code", 0)) != 200 or not isinstance(resp.get("data"), dict):
        return None

    data = resp["data"]
    _secret_cache["data"] = data
    _secret_cache["expire"] = now + int(data.get("cache_ttl", 1800))
    return data


def verify(license_key=""):
    secret = fetch_secret()
    if not secret:
        return {"ok": False, "result": "fail", "reason": "secret_fetch_failed", "msg": "密钥拉取失败"}

    domain, server_ip = site_info()
    ts = int(time.time())
    sign_src = "\n".join(["v2", APP_KEY, license_key, domain, server_ip, str(ts)])
    sign = hmac.new(str(secret.get("appsecret", "")).encode("utf-8"), sign_src.encode("utf-8"), hashlib.sha256).hexdigest()

    payload = {
        "appKey": APP_KEY,
        "licenseKey": license_key,
        "domain": domain,
        "serverIp": server_ip,
        "timestamp": ts,
        "signVersion": "v2",
        "sign": sign,
    }
    resp = _http_json(API_BASE + "/api/license/verify", payload, timeout=8)
    if not resp:
        return {"ok": False, "result": "fail", "reason": "network_error", "msg": "授权服务不可达"}

    data = resp.get("data") if isinstance(resp.get("data"), dict) else {}
    return {
        "ok": data.get("result") == "pass",
        "result": data.get("result", "fail"),
        "reason": data.get("reason", ""),
        "msg": resp.get("msg", ""),
        "data": data,
    }


if __name__ == "__main__":
    print(verify(os.environ.get("LICENSE_KEY", "")))
PY_SDK;

    $java = <<<'JAVA_SDK'
// ZeroAuth 云鉴权 V2 SDK（Java 8+，仅 JDK 标准库）
// 导出时自动替换：{{app_id}} / {{app_key}} / {{api_base}}
// 说明：本类未声明 public，可直接放入任意包；如需 public 请将文件名改为 LicenseSdkV2.java
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.security.SecureRandom;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

final class LicenseSdkV2 {
    private static final String APP_ID = "{{app_id}}";
    private static final String APP_KEY = "{{app_key}}";
    private static final String API_BASE = "{{api_base}}";
    private static final String DOMAIN = "";
    private static final String SERVER_IP = "";
    private static final int HTTP_TIMEOUT = 8000;

    private static Map<String, String> secretCache = null;
    private static long cacheExpireAt = 0;
    private static final SecureRandom RANDOM = new SecureRandom();

    public static final class Result {
        public boolean ok;
        public String result = "fail";
        public String reason = "";
        public String msg = "";
        public String appSecret = "";
    }

    public static Result verify(String licenseKey) {
        Result out = new Result();
        Map<String, String> secret = fetchSecret();
        if (secret == null) {
            out.reason = "secret_fetch_failed";
            out.msg = "密钥拉取失败";
            return out;
        }

        long ts = System.currentTimeMillis() / 1000;
        String key = licenseKey == null ? "" : licenseKey;
        String signSrc = String.join("\n", "v2", APP_KEY, key, DOMAIN, SERVER_IP, Long.toString(ts));
        String sign = hmacSha256(secret.get("appsecret"), signSrc);

        Map<String, Object> body = new LinkedHashMap<>();
        body.put("appKey", APP_KEY);
        body.put("licenseKey", key);
        body.put("domain", DOMAIN);
        body.put("serverIp", SERVER_IP);
        body.put("timestamp", ts);
        body.put("signVersion", "v2");
        body.put("sign", sign);

        String raw = httpPostJson(API_BASE + "/api/license/verify", body);
        if (raw == null) {
            out.reason = "network_error";
            out.msg = "授权服务不可达";
            return out;
        }
        out.result = jsonString(raw, "result", "fail");
        out.reason = jsonString(raw, "reason", "");
        out.msg = jsonString(raw, "msg", "");
        out.appSecret = secret.get("appsecret");
        out.ok = "pass".equals(out.result);
        return out;
    }

    public static Map<String, String> fetchSecret() {
        long now = System.currentTimeMillis() / 1000;
        if (secretCache != null && now < cacheExpireAt) {
            return secretCache;
        }

        Map<String, String> params = new LinkedHashMap<>();
        params.put("app_id", APP_ID);
        params.put("device_code", deviceCode());
        params.put("domain", DOMAIN);
        params.put("nonce", randomHex(8));
        params.put("timestamp", Long.toString(now));
        String query = sortedQuery(params);
        params.put("sign", hmacSha256(APP_ID, query));

        String raw = httpGet(API_BASE + "/api/get_secret.php?" + urlEncode(params));
        if (raw == null || jsonLong(raw, "code", 0) != 200) {
            return null;
        }
        String appSecret = jsonString(raw, "appsecret", "");
        if (appSecret.isEmpty()) {
            return null;
        }
        Map<String, String> data = new LinkedHashMap<>();
        data.put("appsecret", appSecret);
        secretCache = data;
        cacheExpireAt = now + jsonLong(raw, "cache_ttl", 1800);
        return data;
    }

    private static String deviceCode() {
        String raw = System.getProperty("os.arch", "") + "|" + System.getProperty("os.name", "")
                + "|" + System.getProperty("os.version", "") + "|" + System.getProperty("user.name", "");
        return sha256(raw);
    }

    private static String hmacSha256(String key, String data) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(key.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
            byte[] bytes = mac.doFinal(data.getBytes(StandardCharsets.UTF_8));
            StringBuilder sb = new StringBuilder();
            for (byte b : bytes) {
                sb.append(String.format("%02x", b & 0xff));
            }
            return sb.toString();
        } catch (Exception ex) {
            throw new IllegalStateException("HMAC 计算失败", ex);
        }
    }

    private static String sha256(String data) {
        try {
            java.security.MessageDigest digest = java.security.MessageDigest.getInstance("SHA-256");
            byte[] bytes = digest.digest(data.getBytes(StandardCharsets.UTF_8));
            StringBuilder sb = new StringBuilder();
            for (byte b : bytes) {
                sb.append(String.format("%02x", b & 0xff));
            }
            return sb.toString();
        } catch (Exception ex) {
            throw new IllegalStateException("SHA-256 计算失败", ex);
        }
    }

    private static String randomHex(int len) {
        byte[] bytes = new byte[len];
        RANDOM.nextBytes(bytes);
        StringBuilder sb = new StringBuilder();
        for (byte b : bytes) {
            sb.append(String.format("%02x", b));
        }
        return sb.toString();
    }

    private static String sortedQuery(Map<String, String> params) {
        List<String> keys = new ArrayList<>(params.keySet());
        Collections.sort(keys);
        StringBuilder sb = new StringBuilder();
        for (String k : keys) {
            if (sb.length() > 0) {
                sb.append('&');
            }
            sb.append(urlEncode(k)).append('=').append(urlEncode(params.get(k)));
        }
        return sb.toString();
    }

    private static String urlEncode(Map<String, String> params) {
        StringBuilder sb = new StringBuilder();
        for (Map.Entry<String, String> e : params.entrySet()) {
            if (sb.length() > 0) {
                sb.append('&');
            }
            sb.append(urlEncode(e.getKey())).append('=').append(urlEncode(e.getValue()));
        }
        return sb.toString();
    }

    private static String urlEncode(String s) {
        try {
            return URLEncoder.encode(s == null ? "" : s, "UTF-8");
        } catch (Exception ex) {
            return "";
        }
    }

    private static String httpGet(String url) {
        try {
            HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
            conn.setRequestMethod("GET");
            conn.setConnectTimeout(HTTP_TIMEOUT);
            conn.setReadTimeout(HTTP_TIMEOUT);
            conn.setRequestProperty("User-Agent", "APEAuth-SDK/2.0");
            return readBody(conn);
        } catch (Exception ex) {
            return null;
        }
    }

    private static String httpPostJson(String url, Map<String, Object> body) {
        try {
            String json = toJson(body);
            HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
            conn.setRequestMethod("POST");
            conn.setDoOutput(true);
            conn.setConnectTimeout(HTTP_TIMEOUT);
            conn.setReadTimeout(HTTP_TIMEOUT);
            conn.setRequestProperty("Content-Type", "application/json");
            conn.setRequestProperty("User-Agent", "APEAuth-SDK/2.0");
            try (OutputStream os = conn.getOutputStream()) {
                os.write(json.getBytes(StandardCharsets.UTF_8));
            }
            return readBody(conn);
        } catch (Exception ex) {
            return null;
        }
    }

    private static String readBody(HttpURLConnection conn) throws Exception {
        try (InputStream is = conn.getInputStream()) {
            ByteArrayOutputStream buffer = new ByteArrayOutputStream();
            byte[] buf = new byte[4096];
            int n;
            while ((n = is.read(buf)) != -1) {
                buffer.write(buf, 0, n);
            }
            return new String(buffer.toByteArray(), StandardCharsets.UTF_8);
        }
    }

    private static String toJson(Map<String, Object> map) {
        StringBuilder sb = new StringBuilder("{");
        for (Map.Entry<String, Object> e : map.entrySet()) {
            if (sb.length() > 1) {
                sb.append(',');
            }
            sb.append('"').append(escapeJson(e.getKey())).append("\":");
            Object v = e.getValue();
            if (v instanceof Number || v instanceof Boolean) {
                sb.append(v);
            } else {
                sb.append('"').append(escapeJson(String.valueOf(v))).append('"');
            }
        }
        return sb.append('}').toString();
    }

    private static String escapeJson(String s) {
        return s.replace("\\", "\\\\").replace("\"", "\\\"").replace("\n", "\\n").replace("\r", "\\r");
    }

    private static String jsonString(String raw, String key, String def) {
        Matcher m = Pattern.compile("\"" + Pattern.quote(key) + "\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"").matcher(raw);
        if (m.find()) {
            return m.group(1).replace("\\\"", "\"").replace("\\/", "/").replace("\\\\", "\\");
        }
        return def;
    }

    private static long jsonLong(String raw, String key, long def) {
        Matcher m = Pattern.compile("\"" + Pattern.quote(key) + "\"\\s*:\\s*([0-9]+)").matcher(raw);
        return m.find() ? Long.parseLong(m.group(1)) : def;
    }

    public static void main(String[] args) {
        Result r = verify(System.getenv("LICENSE_KEY") == null ? "" : System.getenv("LICENSE_KEY"));
        System.out.println((r.ok ? "PASS" : "FAIL") + " " + r.result + " " + r.reason);
    }
}
JAVA_SDK;

    $go = <<<'GO_SDK'
// ZeroAuth 云鉴权 V2 SDK（Go 1.18+，仅标准库）
// 导出时自动替换：{{app_id}} / {{app_key}} / {{api_base}}
package sdk

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	appID    = "{{app_id}}"
	appKey   = "{{app_key}}"
	apiBase  = "{{api_base}}"
	domain   = ""
	serverIP = ""
)

type Secret struct {
	AppSecret string `json:"appsecret"`
	CacheTTL  int64  `json:"cache_ttl"`
}

type VerifyResult struct {
	OK     bool                   `json:"ok"`
	Result string                 `json:"result"`
	Reason string                 `json:"reason"`
	Msg    string                 `json:"msg"`
	Data   map[string]interface{} `json:"data"`
}

var (
	secretMu       sync.Mutex
	secretCache    *Secret
	secretExpireAt int64
)

func deviceCode() string {
	raw := fmt.Sprintf("%s|%s|%s|%s", runtime.GOARCH, runtime.GOOS, runtime.Version(), hostName())
	sum := sha256.Sum256([]byte(raw))
	return hex.EncodeToString(sum[:])
}

func hostName() string {
	name, _ := os.Hostname()
	return name
}

func siteInfo() (string, string) {
	d := domain
	if d == "" {
		d = hostName()
	}
	return strings.ToLower(strings.Split(d, ":")[0]), serverIP
}

func hmacSHA256(key, data string) string {
	mac := hmac.New(sha256.New, []byte(key))
	mac.Write([]byte(data))
	return hex.EncodeToString(mac.Sum(nil))
}

func FetchSecret() (*Secret, error) {
	secretMu.Lock()
	defer secretMu.Unlock()

	now := time.Now().Unix()
	if secretCache != nil && now < secretExpireAt {
		return secretCache, nil
	}

	d, _ := siteInfo()
	nonce := make([]byte, 8)
	_, _ = rand.Read(nonce)
	params := map[string]string{
		"app_id":      appID,
		"domain":      d,
		"device_code": deviceCode(),
		"timestamp":   strconv.FormatInt(now, 10),
		"nonce":       hex.EncodeToString(nonce),
	}
	keys := make([]string, 0, len(params))
	for k := range params {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	parts := make([]string, 0, len(keys))
	for _, k := range keys {
		parts = append(parts, url.QueryEscape(k)+"="+url.QueryEscape(params[k]))
	}
	query := strings.Join(parts, "&")
	params["sign"] = hmacSHA256(appID, query)

	resp, err := httpGet(apiBase + "/api/get_secret.php?" + query + "&sign=" + url.QueryEscape(params["sign"]))
	if err != nil {
		return nil, err
	}
	var envelope struct {
		Code int          `json:"code"`
		Msg  string       `json:"msg"`
		Data *Secret      `json:"data"`
	}
	if err := json.Unmarshal(resp, &envelope); err != nil || envelope.Code != 200 || envelope.Data == nil {
		return nil, fmt.Errorf("secret 接口返回异常")
	}
	ttl := envelope.Data.CacheTTL
	if ttl <= 0 {
		ttl = 1800
	}
	secretCache = envelope.Data
	secretExpireAt = now + ttl
	return secretCache, nil
}

func Verify(licenseKey string) VerifyResult {
	secret, err := FetchSecret()
	if err != nil || secret == nil {
		return VerifyResult{Result: "fail", Reason: "secret_fetch_failed", Msg: "密钥拉取失败"}
	}

	d, ip := siteInfo()
	ts := time.Now().Unix()
	signSrc := strings.Join([]string{"v2", appKey, licenseKey, d, ip, strconv.FormatInt(ts, 10)}, "\n")
	body := map[string]interface{}{
		"appKey":      appKey,
		"licenseKey":  licenseKey,
		"domain":      d,
		"serverIp":    ip,
		"timestamp":   ts,
		"signVersion": "v2",
		"sign":        hmacSHA256(secret.AppSecret, signSrc),
	}

	raw, err := httpPostJSON(apiBase+"/api/license/verify", body)
	if err != nil {
		return VerifyResult{Result: "fail", Reason: "network_error", Msg: "授权服务不可达"}
	}
	var envelope struct {
		Code int                    `json:"code"`
		Msg  string                 `json:"msg"`
		Data map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal(raw, &envelope); err != nil {
		return VerifyResult{Result: "fail", Reason: "bad_response", Msg: "响应解析失败"}
	}
	result, _ := envelope.Data["result"].(string)
	reason, _ := envelope.Data["reason"].(string)
	return VerifyResult{
		OK:     result == "pass",
		Result: result,
		Reason: reason,
		Msg:    envelope.Msg,
		Data:   envelope.Data,
	}
}

func httpGet(target string) ([]byte, error) {
	client := &http.Client{Timeout: 8 * time.Second}
	req, err := http.NewRequest(http.MethodGet, target, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", "APEAuth-SDK/2.0")
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	return io.ReadAll(resp.Body)
}

func httpPostJSON(target string, body map[string]interface{}) ([]byte, error) {
	payload, _ := json.Marshal(body)
	client := &http.Client{Timeout: 8 * time.Second}
	req, err := http.NewRequest(http.MethodPost, target, strings.NewReader(string(payload)))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", "APEAuth-SDK/2.0")
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	return io.ReadAll(resp.Body)
}
GO_SDK;

    $node = <<<'NODE_SDK'
// ZeroAuth 云鉴权 V2 SDK（Node.js 16+，内置模块，无需 npm 依赖）
// 导出时自动替换：{{app_id}} / {{app_key}} / {{api_base}}
'use strict';

const crypto = require('crypto');
const http = require('http');
const https = require('https');
const os = require('os');
const { URL } = require('url');

const APP_ID = '{{app_id}}';
const APP_KEY = '{{app_key}}';
const API_BASE = '{{api_base}}';
const DOMAIN = '';
const SERVER_IP = '';

let secretCache = null;
let cacheExpireAt = 0;

function httpJson(target, payload = null, timeout = 8000) {
    return new Promise((resolve) => {
        let url;
        try {
            url = new URL(target);
        } catch (e) {
            resolve(null);
            return;
        }
        const body = payload === null ? null : JSON.stringify(payload);
        const lib = url.protocol === 'https:' ? https : http;
        const req = lib.request({
            hostname: url.hostname,
            port: url.port || (url.protocol === 'https:' ? 443 : 80),
            path: url.pathname + url.search,
            method: body === null ? 'GET' : 'POST',
            headers: {
                'User-Agent': 'APEAuth-SDK/2.0',
                ...(body === null ? {} : { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) }),
            },
            timeout,
        }, (res) => {
            const chunks = [];
            res.on('data', (c) => chunks.push(c));
            res.on('end', () => {
                try {
                    resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
                } catch (e) {
                    resolve(null);
                }
            });
        });
        req.on('error', () => resolve(null));
        req.on('timeout', () => {
            req.destroy();
            resolve(null);
        });
        if (body !== null) {
            req.write(body);
        }
        req.end();
    });
}

function deviceCode() {
    const raw = [os.arch(), os.type(), os.release(), os.hostname()].join('|');
    return crypto.createHash('sha256').update(raw).digest('hex');
}

function siteInfo() {
    const domain = DOMAIN || os.hostname();
    return [domain.toLowerCase().replace(/:\d+$/, ''), SERVER_IP];
}

function hmacSha256(key, data) {
    return crypto.createHmac('sha256', key).update(data, 'utf8').digest('hex');
}

async function fetchSecret(force = false) {
    const now = Math.floor(Date.now() / 1000);
    if (!force && secretCache && now < cacheExpireAt) {
        return secretCache;
    }

    const params = {
        app_id: APP_ID,
        domain: siteInfo()[0],
        device_code: deviceCode(),
        timestamp: String(now),
        nonce: crypto.randomBytes(8).toString('hex'),
    };
    const sorted = Object.keys(params).sort().map((k) => `${encodeURIComponent(k)}=${encodeURIComponent(params[k])}`).join('&');
    params.sign = hmacSha256(APP_ID, sorted);

    const query = Object.keys(params).map((k) => `${encodeURIComponent(k)}=${encodeURIComponent(params[k])}`).join('&');
    const resp = await httpJson(`${API_BASE}/api/get_secret.php?${query}`, null, 5000);
    if (!resp || Number(resp.code) !== 200 || !resp.data || typeof resp.data !== 'object') {
        return null;
    }
    secretCache = resp.data;
    const ttl = Number(resp.data.cache_ttl || 1800);
    cacheExpireAt = now + ttl;
    return secretCache;
}

async function verify(licenseKey = '') {
    const secret = await fetchSecret();
    if (!secret) {
        return { ok: false, result: 'fail', reason: 'secret_fetch_failed', msg: '密钥拉取失败' };
    }

    const [domain, serverIp] = siteInfo();
    const ts = Math.floor(Date.now() / 1000);
    const signSrc = ['v2', APP_KEY, licenseKey, domain, serverIp, String(ts)].join('\n');
    const sign = hmacSha256(String(secret.appsecret || ''), signSrc);

    const resp = await httpJson(`${API_BASE}/api/license/verify`, {
        appKey: APP_KEY,
        licenseKey,
        domain,
        serverIp,
        timestamp: ts,
        signVersion: 'v2',
        sign,
    });
    if (!resp) {
        return { ok: false, result: 'fail', reason: 'network_error', msg: '授权服务不可达' };
    }
    const data = resp.data && typeof resp.data === 'object' ? resp.data : {};
    return {
        ok: data.result === 'pass',
        result: data.result || 'fail',
        reason: data.reason || '',
        msg: resp.msg || '',
        data,
    };
}

module.exports = { verify, fetchSecret };

if (require.main === module) {
    verify(process.env.LICENSE_KEY || '').then((r) => console.log(r));
}
NODE_SDK;

    $cpp = <<<'CPP_SDK'
// ZeroAuth 云鉴权 V2 SDK（C++17，头文件，依赖 libcurl + OpenSSL）
// 导出时自动替换：{{app_id}} / {{app_key}} / {{api_base}}
// 编译依赖示例：-lcurl -lssl -lcrypto
#pragma once

#include <openssl/hmac.h>
#include <openssl/sha.h>
#include <curl/curl.h>

#include <chrono>
#include <cstdio>
#include <ctime>
#include <map>
#include <random>
#include <regex>
#include <sstream>
#include <string>
#include <vector>

namespace ape_sdk {

static const std::string APP_ID = "{{app_id}}";
static const std::string APP_KEY = "{{app_key}}";
static const std::string API_BASE = "{{api_base}}";
static const std::string DOMAIN = "";
static const std::string SERVER_IP = "";

struct VerifyResult {
    bool ok = false;
    std::string result = "fail";
    std::string reason;
    std::string msg;
};

static std::string hex(const unsigned char* data, size_t len) {
    std::string out;
    char buf[8];
    for (size_t i = 0; i < len; ++i) {
        std::snprintf(buf, sizeof(buf), "%02x", data[i]);
        out += buf;
    }
    return out;
}

static std::string sha256(const std::string& data) {
    unsigned char digest[SHA256_DIGEST_LENGTH];
    SHA256(reinterpret_cast<const unsigned char*>(data.c_str()), data.size(), digest);
    return hex(digest, SHA256_DIGEST_LENGTH);
}

static std::string hmacSha256(const std::string& key, const std::string& data) {
    unsigned char* out = nullptr;
    unsigned int len = 0;
    out = HMAC(EVP_sha256(), key.c_str(), static_cast<int>(key.size()),
               reinterpret_cast<const unsigned char*>(data.c_str()), data.size(), out, &len);
    return hex(out, len);
}

static std::string urlEncode(const std::string& value) {
    char* encoded = curl_easy_escape(nullptr, value.c_str(), static_cast<int>(value.size()));
    std::string out = encoded == nullptr ? value : encoded;
    if (encoded != nullptr) {
        curl_free(encoded);
    }
    return out;
}

static std::size_t writeCallback(void* ptr, std::size_t size, std::size_t nmemb, std::ostream* stream) {
    stream->write(static_cast<char*>(ptr), static_cast<std::size_t>(size * nmemb));
    return size * nmemb;
}

static std::string httpRequest(const std::string& url, const std::string& body = "") {
    CURL* curl = curl_easy_init();
    if (curl == nullptr) {
        return "";
    }
    std::ostringstream response;
    struct curl_slist* headers = nullptr;
    if (!body.empty()) {
        headers = curl_slist_append(headers, "Content-Type: application/json");
    }
    curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, writeCallback);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &response);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 8L);
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 3L);
    curl_easy_setopt(curl, CURLOPT_FOLLOWLOCATION, 1L);
    curl_easy_setopt(curl, CURLOPT_USERAGENT, "APEAuth-SDK/2.0");
    if (!body.empty()) {
        curl_easy_setopt(curl, CURLOPT_POST, 1L);
        curl_easy_setopt(curl, CURLOPT_POSTFIELDS, body.c_str());
        curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    }
    CURLcode code = curl_easy_perform(curl);
    if (headers != nullptr) {
        curl_slist_free_all(headers);
    }
    curl_easy_cleanup(curl);
    return code == CURLE_OK ? response.str() : "";
}

static std::string jsonString(const std::string& raw, const std::string& key, const std::string& def = "") {
    std::regex re("\"" + key + "\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"");
    std::smatch m;
    if (std::regex_search(raw, m, re)) {
        return m[1].str();
    }
    return def;
}

static long jsonLong(const std::string& raw, const std::string& key, long def = 0) {
    std::regex re("\"" + key + "\"\\s*:\\s*([0-9]+)");
    std::smatch m;
    if (std::regex_search(raw, m, re)) {
        return std::stol(m[1].str());
    }
    return def;
}

static std::string deviceCode() {
    std::string raw = std::string("cpp|") + (DOMAIN.empty() ? "local" : DOMAIN) + "|" + SERVER_IP;
    return sha256(raw);
}

static std::string randomHex() {
    std::mt19937 rng(static_cast<unsigned>(std::chrono::steady_clock::now().time_since_epoch().count()));
    std::ostringstream out;
    for (int i = 0; i < 16; ++i) {
        out << std::hex << (rng() % 16);
    }
    return out.str();
}

static std::map<std::string, std::string> cachedSecret;
static long cacheExpireAt = 0;

static std::map<std::string, std::string> fetchSecret() {
    long now = static_cast<long>(std::time(nullptr));
    if (!cachedSecret.empty() && now < cacheExpireAt) {
        return cachedSecret;
    }

    std::map<std::string, std::string> params = {
        {"app_id", APP_ID},
        {"device_code", deviceCode()},
        {"domain", DOMAIN},
        {"nonce", randomHex()},
        {"timestamp", std::to_string(now)},
    };
    std::string query;
    for (const auto& item : params) {
        if (!query.empty()) {
            query += "&";
        }
        query += urlEncode(item.first) + "=" + urlEncode(item.second);
    }
    std::string sign = hmacSha256(APP_ID, query);
    std::string raw = httpRequest(API_BASE + "/api/get_secret.php?" + query + "&sign=" + urlEncode(sign));
    if (raw.empty() || jsonLong(raw, "code") != 200) {
        return {};
    }
    std::string secret = jsonString(raw, "appsecret");
    if (secret.empty()) {
        return {};
    }
    cachedSecret.clear();
    cachedSecret["appsecret"] = secret;
    cacheExpireAt = now + jsonLong(raw, "cache_ttl", 1800);
    return cachedSecret;
}

static VerifyResult verify(const std::string& licenseKey = "") {
    VerifyResult out;
    auto secret = fetchSecret();
    if (secret.empty() || secret.find("appsecret") == secret.end()) {
        out.reason = "secret_fetch_failed";
        out.msg = "密钥拉取失败";
        return out;
    }

    long ts = static_cast<long>(std::time(nullptr));
    std::string signSrc = "v2\n" + APP_KEY + "\n" + licenseKey + "\n" + DOMAIN + "\n" + SERVER_IP + "\n" + std::to_string(ts);
    std::string sign = hmacSha256(secret["appsecret"], signSrc);

    std::ostringstream body;
    body << "{\"appKey\":\"" << APP_KEY << "\",\"licenseKey\":\"" << licenseKey << "\","
         << "\"domain\":\"" << DOMAIN << "\",\"serverIp\":\"" << SERVER_IP << "\","
         << "\"timestamp\":" << ts << ",\"signVersion\":\"v2\",\"sign\":\"" << sign << "\"}";

    std::string raw = httpRequest(API_BASE + "/api/license/verify", body.str());
    if (raw.empty()) {
        out.reason = "network_error";
        out.msg = "授权服务不可达";
        return out;
    }
    out.result = jsonString(raw, "result", "fail");
    out.reason = jsonString(raw, "reason");
    out.msg = jsonString(raw, "msg");
    out.ok = out.result == "pass";
    return out;
}

} // namespace ape_sdk
CPP_SDK;

    return [
        ['name' => 'PHP 授权 SDK（V2 新协议）',     'language' => 'php',    'content' => $php],
        ['name' => 'Python 授权 SDK（V2 新协议）',  'language' => 'python', 'content' => $python],
        ['name' => 'Java 授权 SDK（V2 新协议）',    'language' => 'java',   'content' => $java],
        ['name' => 'Go 授权 SDK（V2 新协议）',      'language' => 'go',     'content' => $go],
        ['name' => 'Node.js 授权 SDK（V2 新协议）', 'language' => 'nodejs', 'content' => $node],
        ['name' => 'C++ 授权 SDK（V2 新协议）',     'language' => 'cpp',    'content' => $cpp],
    ];
}
