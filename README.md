# ZeroAuth - 软件授权 & 域名授权管理系统

> 原作者：APE开发项目组-玄猿工坊 · 修改维护：MEMZ-LMXDG · **二次开发请保留版权**
> 官方 QQ 群：1125324975

ZeroAuth 是一套基于 PHP + MySQL 的多角色软件授权管理系统，支持**卡密（License Key）授权**与**域名授权**两种模式，内置完整的**用户中心 / 代理商体系 / 管理后台**三端，并配备签名校验 API、SDK 导出、支付充值、插件与主题扩展机制，可用于商业软件的授权分发、防盗版追踪与销售管理。

## 功能特性

### 授权核心
- **多应用隔离**：每个应用独立的 AppID / AppKey / AppSecret，支持独立售价、授权类型与授权方式配置
- **三种授权类型**：限时授权、计次授权、永久授权
- **四种授权方式**：单域名、泛域名、IP 授权、密钥（卡密）
- **卡密体系**：批量生成、列表管理、绑定域名 / 设备、黑名单封禁、防盗版追踪
- **云端变量**：通过 API 下发和管理云端变量（`api/cloud_var.php`）
- **应用版本管理**：版本检查与更新包下载接口（`api/app/version/check|download`）

### 三端体系
| 端 | 目录 | 说明 |
|---|---|---|
| 管理后台 | `admin/` | 仪表盘、应用/卡密/订单/用户/代理管理、财务流水、审计日志、告警中心、IP 封禁、黑名单、SDK 管理、世界地图统计等 |
| 代理商中心 | `agent/` | 等级折扣、邀请码绑定下级用户、购卡、余额与提现、工单 |
| 用户中心 | `user/` | 注册登录、实名认证、购买与卡密兑换、我的授权、余额财务、工单 |

公开首页（`index.php`）提供**域名授权状态公开查询**，并支持通过 `template` 类主题插件自定义首页模板。

### 授权验证 API
- `api/license/verify` —— 新版授权校验接口，支持 v1 / v2 HMAC-SHA256 签名，±10 分钟时间戳窗口，可按卡密 / 域名 / IP 校验
- `api/auth.php` —— 卡密核验接口（HMAC 签名 + nonce 防重放）
- `api/order_status.php` / `api/pay_callback.php` —— 订单状态查询与支付回调
- `api/get_secret.php` —— 密钥安全下发

全部 API 均有请求频率限制、IP 封禁检查与验证日志（`verify_log`）记录。

### 支付与增值
- 内置支付插件：**支付宝**（alipay）、**微信**（wechat）、**易支付**（epay）、**余额支付**（balance）
- 优惠折扣活动、财务流水、代理商提现
- 邮件通知（SMTP，含场景化邮件模板与发送日志）

### 安全机制
- 密码 `password_hash` 加盐哈希，敏感字段（如身份证号）加密存储
- 登录失败锁定、CSRF 防护、XSS 转义、会话 Cookie `httponly + SameSite`
- 真实 IP 解析（`CF-Connecting-IP → X-Real-IP → X-Forwarded-For`，自动过滤内网地址）
- 多维黑名单（设备码 / 卡密 / 域名）与 IP 封禁，命中自动触发告警中心
- API 签名时间戳窗口 + nonce 防重放
- 审计日志全程留痕；管理端支持自定义后台目录名（安装时自动复制 `admin/`）

### 验证码
- GD 主路径生成 132×44 PNG 验证码（干扰线 + 噪点 + 随机倾角），无字体环境自动回退 `imagechar`
- GD 缺失或绘制失败自动降级为 SVG 数字验证码，彻底杜绝 1×1 空白图问题
- 可选插件：**极验 4.0**（Geetest）、**Cloudflare Turnstile**

### 插件与主题
采用**文件驱动的插件系统**：系统自动扫描 `/plugin/*/Plugin.php`，目录放入即被识别，再到后台「应用商店」启用。插件分类：`pay` 支付 / `template` 主题 / `captcha` 验证 / `login` 登录 / `realname` 实名。

内置插件：alipay、wechat、epay、balance（支付）；geetest、turnstile（验证）；rainbow（彩虹聚合登录，支持 QQ / 微信 / 支付宝等 16 种第三方登录）；default、tpl_v1、tpl_v2（首页主题）。

详见 [docs/插件开发文档.md](docs/插件开发文档.md) 与 [docs/主题开发文档.md](docs/主题开发文档.md)。

## 环境要求

| 项目 | 要求 |
|---|---|
| PHP | **8.0 ~ 8.2** |
| 必需扩展 | PDO、PDO MySQL、JSON、MBString |
| 推荐扩展 | cURL（支付/邮件）、Zip（SDK 打包）、OpenSSL（安全签名）、GD（验证码） |
| 数据库 | MySQL 5.6 ~ 5.7（兼容，不使用 8.0 特有语法） |
| 目录权限 | `runtime/` 可写；根目录可写（生成 `config.php` / `install.lock` / 自定义后台目录） |

## 安装

1. 将全部文件上传至网站根目录（如宝塔站点目录）；
2. 创建 MySQL 数据库；
3. 浏览器访问 `https://你的域名/install/`，按向导完成环境检测、数据库配置与管理员创建；
4. 安装完成后访问自定义后台目录（默认 `admin/`，安装时可自定义）进入管理后台；
5. 在「应用商店」按需启用支付 / 验证码 / 第三方登录插件与首页主题。

> 覆盖升级时请保留 `runtime/`、`config.php`、`install.lock`，并将新版 `admin/` 内文件同步覆盖到实际后台目录。存量数据库结构由 `core/schema_upgrade.php` 幂等自愈迁移。

## 目录结构

```
ZeroAuth/
├── admin/            # 管理后台
├── agent/            # 代理商中心
├── user/             # 用户中心
├── api/              # 授权验证 / 云变量 / 支付回调 / 版本更新 API
├── core/             # 内核：引导、安全、支付、邮件、验证码、插件系统、授权 API 封装
├── plugin/           # 插件与主题（文件驱动，放入即识别）
├── install/          # 安装向导与全量建表 SQL（27 张表）
├── assets/           # 静态资源（含地理数据、JS 库、SVG）
├── docs/             # 插件 / 主题开发文档与更新说明
├── runtime/          # 运行时日志与缓存（需可写）
├── uploads/          # 上传目录（需可写）
└── index.php         # 公开首页（域名授权查询）
```

## 数据库概览

共 27 张表，核心包括：`sys_config`（系统配置）、`admin` / `agent` / `user`（三端账号）、`app`（应用）、`license` / `license_domain` / `license_device`（授权）、`order` / `finance_flow` / `withdraw`（交易）、`verify_log`（验证日志）、`blacklist` / `ip_ban`（封禁）、`audit_log`（审计）、`ticket`（工单）、`plugin`（插件）、`app_version` / `app_package`（版本分发）等。全新安装由向导执行 `install/schema.sql` 一步到位。

## 版权声明

本系统原作者为 **APE开发项目组-玄猿工坊**，由 **MEMZ-LMXDG** 修改维护。二次开发请保留版权信息（含「关于系统」页面与页脚署名），感谢尊重开发者劳动成果。
