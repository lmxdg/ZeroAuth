-- ============================================================
-- PHP 多角色软件授权 & 域名授权管理系统 V1.0.0（完整版建表 SQL）
-- 兼容 MySQL 5.6 ~ 5.7，不使用 8.0 特有语法
-- 全部表名使用反引号包裹，order 为保留字
-- 本文件为全量结构（含原 v16~v18 增量迁移内容），全新安装一步到位；
-- 存量库仍由 core/schema_upgrade.php 幂等自愈迁移，二者结构保持一致
-- ============================================================

-- 1. 系统基础配置表（键值对结构：SMTP、安全配置、密钥下发开关、密钥会话有效期、SDK 缓存 TTL、告警阈值、邮件场景模板）
CREATE TABLE IF NOT EXISTS `sys_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `cfg_key` varchar(64) NOT NULL DEFAULT '' COMMENT '配置键',
  `cfg_value` text COMMENT '配置值',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '配置说明',
  `update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cfg_key` (`cfg_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统基础配置表';

-- 2. 管理员账号表
CREATE TABLE IF NOT EXISTS `admin` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `username` varchar(32) NOT NULL DEFAULT '' COMMENT '登录账号',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '密码（password_hash 加盐哈希）',
  `email` varchar(128) NOT NULL DEFAULT '' COMMENT '邮箱',
  `nickname` varchar(32) NOT NULL DEFAULT '' COMMENT '显示昵称',
  `qq` varchar(16) NOT NULL DEFAULT '' COMMENT 'QQ 号（取 QQ 头像）',
  `login_fail` int unsigned NOT NULL DEFAULT 0 COMMENT '连续登录失败次数',
  `lock_time` int unsigned NOT NULL DEFAULT 0 COMMENT '锁定截止时间',
  `last_login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `last_login_time` int unsigned NOT NULL DEFAULT 0 COMMENT '最后登录时间',
  `totp_secret` varchar(64) NOT NULL DEFAULT '' COMMENT 'TOTP 二次验证密钥（Base32）',
  `totp_enabled` tinyint unsigned NOT NULL DEFAULT 0 COMMENT 'TOTP 二次验证 0关闭 1开启',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 1正常 2禁用',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员账号表';

-- 3. 代理商账号表（等级折扣 / 赠送余额 / 邀请码绑定下级）
CREATE TABLE IF NOT EXISTS `agent` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `username` varchar(32) NOT NULL DEFAULT '' COMMENT '登录账号',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '密码（加盐哈希）',
  `reg_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '创建/注册IP',
  `email` varchar(128) NOT NULL DEFAULT '' COMMENT '邮箱',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '余额',
  `discount` decimal(4,2) NOT NULL DEFAULT 1.00 COMMENT '独立折扣（0.01~1.00，等级启用时被等级折扣覆盖）',
  `invite_code` char(10) NOT NULL DEFAULT '' COMMENT '代理商邀请码（名下用户注册绑定时填写，唯一性由业务层查重保证）',
  `level_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属代理商等级ID（0=默认等级）',
  `gift_balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计等级赠送余额',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 1正常 2禁用',
  `login_fail` int unsigned NOT NULL DEFAULT 0 COMMENT '连续登录失败次数',
  `lock_time` int unsigned NOT NULL DEFAULT 0 COMMENT '锁定截止时间',
  `last_login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `last_login_time` int unsigned NOT NULL DEFAULT 0 COMMENT '最后登录时间',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_invite_code` (`invite_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代理商账号表';

-- 4. 普通用户表（含实名信息；agent_id 为注册时邀请码绑定的上级代理商）
CREATE TABLE IF NOT EXISTS `user` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `username` varchar(32) NOT NULL DEFAULT '' COMMENT '登录账号',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '密码（加盐哈希）',
  `email` varchar(128) NOT NULL DEFAULT '' COMMENT '邮箱',
  `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '手机号',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '余额',
  `real_status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '实名状态 0未认证 1待审核 2已通过 3已驳回',
  `real_name` varchar(64) NOT NULL DEFAULT '' COMMENT '实名姓名',
  `real_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '实名驳回理由',
  `id_card` varchar(128) NOT NULL DEFAULT '' COMMENT '身份证号（加密存储）',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 1正常 2禁用',
  `agent_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属代理商ID（0平台直营）',
  `reg_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '注册IP',
  `last_login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `last_login_time` int unsigned NOT NULL DEFAULT 0 COMMENT '最后登录时间',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '注册时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_agent_id` (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='普通用户表';

-- 5. 应用表（多应用隔离，AppKey/AppSecret）
CREATE TABLE IF NOT EXISTS `app` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '应用名称',
  `app_id` varchar(32) NOT NULL DEFAULT '' COMMENT '应用ID（分发到SDK的唯一标识）',
  `app_key` varchar(64) NOT NULL DEFAULT '' COMMENT '应用AppKey',
  `app_secret` varchar(64) NOT NULL DEFAULT '' COMMENT '应用AppSecret（后台脱敏展示）',
  `max_devices` int unsigned NOT NULL DEFAULT 1 COMMENT '默认设备上限',
  `license_types` varchar(32) NOT NULL DEFAULT '1,2,3' COMMENT '支持授权类型 1限时 2计次 3永久',
  `auth_methods` varchar(16) NOT NULL DEFAULT '1,2,3,4' COMMENT '授权方式 1单域名 2泛域名 3IP 4密钥（空=用户端代理端隐藏）',
  `callback_url` varchar(255) NOT NULL DEFAULT '' COMMENT '授权验证回调URL',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '售价（用户中心购买，0=不上架）',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 1启用 2停用',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_id` (`app_id`),
  UNIQUE KEY `uk_app_key` (`app_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用表';

-- 6. 卡密授权主表（验证接口核心表；V2 协议 target_type 区分授权目标）
CREATE TABLE IF NOT EXISTS `license` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `card_no` varchar(64) NOT NULL DEFAULT '' COMMENT '卡密授权码',
  `app_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属应用ID',
  `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '类型 1限时 2计次 3永久',
  `target_type` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '授权目标 0按绑定目标兼容旧数据 1域名 2泛域名 3IP 4纯密钥',
  `start_time` int unsigned NOT NULL DEFAULT 0 COMMENT '生效时间（限时）',
  `expire_time` int unsigned NOT NULL DEFAULT 0 COMMENT '到期时间（限时，0不限）',
  `total_count` int unsigned NOT NULL DEFAULT 0 COMMENT '总验证次数（计次，0不限）',
  `used_count` int unsigned NOT NULL DEFAULT 0 COMMENT '已验证次数',
  `max_devices` int unsigned NOT NULL DEFAULT 1 COMMENT '单卡最大绑定设备数（target_type=2 泛域名时为站点数上限）',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '价格',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 1正常 2封禁',
  `owner_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '归属 1管理员 2代理商',
  `owner_id` int unsigned NOT NULL DEFAULT 0 COMMENT '归属账号ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '持有用户ID（0未兑换）',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注（订单购买单号写入此处）',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card_no` (`card_no`),
  KEY `idx_app_status` (`app_id`, `status`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_owner` (`owner_type`, `owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='卡密授权主表';

-- 7. 授权绑定域名表（V2 协议支持泛域名 / IP 目标）
CREATE TABLE IF NOT EXISTS `license_domain` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `license_id` int unsigned NOT NULL DEFAULT 0 COMMENT '授权ID',
  `domain` varchar(255) NOT NULL DEFAULT '' COMMENT '绑定域名（泛域名存 *.suffix，IP 目标存 IP）',
  `target_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '目标类型 1域名 2泛域名 3IP',
  `is_https` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否校验HTTPS 0否 1是',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 1正常 2封禁',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '绑定时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_license_domain` (`license_id`, `domain`),
  KEY `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权绑定域名表';

-- 8. 设备指纹绑定表
CREATE TABLE IF NOT EXISTS `license_device` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `license_id` int unsigned NOT NULL DEFAULT 0 COMMENT '授权ID',
  `device_code` varchar(64) NOT NULL DEFAULT '' COMMENT '设备指纹（哈希）',
  `device_name` varchar(128) NOT NULL DEFAULT '' COMMENT '设备名称',
  `bind_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '绑定IP',
  `first_bind_time` int unsigned NOT NULL DEFAULT 0 COMMENT '首次绑定时间',
  `last_verify_time` int unsigned NOT NULL DEFAULT 0 COMMENT '最后验证时间',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 1正常 2已解绑',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_license_device` (`license_id`, `device_code`),
  KEY `idx_device_code` (`device_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设备指纹绑定表';

-- 9. 云变量表（公开/私有；app_id=0 var_key=notice 为用户中心系统公告）
CREATE TABLE IF NOT EXISTS `cloud_var` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `app_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属应用ID',
  `var_key` varchar(64) NOT NULL DEFAULT '' COMMENT '变量键名',
  `var_value` text COMMENT '变量值（公告/版本号/链接/JSON）',
  `is_public` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1公开（无需授权） 0私有（需签名鉴权）',
  `update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_var` (`app_id`, `var_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='云变量表';

-- 10. 订单表（order 为 MySQL 保留字，反引号包裹；agent_id 支持代理商充值单/开卡单）
CREATE TABLE IF NOT EXISTS `order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_no` varchar(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID（用户端订单）',
  `agent_id` int unsigned NOT NULL DEFAULT 0 COMMENT '代理商ID（agent 端充值单 / 开卡单归属）',
  `app_id` int unsigned NOT NULL DEFAULT 0 COMMENT '应用ID',
  `package_id` int unsigned NOT NULL DEFAULT 0 COMMENT '套餐ID（0=非套餐订单）',
  `license_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联授权ID',
  `subject` varchar(128) NOT NULL DEFAULT '' COMMENT '订单标题',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '应付金额',
  `discount_id` int unsigned NOT NULL DEFAULT 0 COMMENT '使用的活动/折扣ID',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '优惠金额',
  `discount_name` varchar(64) NOT NULL DEFAULT '' COMMENT '优惠名称（活动名 / 代理等级折扣）',
  `license_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '购买授权类型 0余额充值 1限时 2计次 3永久',
  `quantity` int unsigned NOT NULL DEFAULT 1 COMMENT '购买数量（张）',
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '卡密单价',
  `duration_days` int unsigned NOT NULL DEFAULT 0 COMMENT '限时卡有效天数（type=1 时使用）',
  `total_count` int unsigned NOT NULL DEFAULT 0 COMMENT '计次卡总次数（type=2 时使用）',
  `target_type` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '授权目标 0默认 1域名 2泛域名 3IP 4纯密钥',
  `target_value` varchar(255) NOT NULL DEFAULT '' COMMENT '授权目标值（域名/IP/自定义密钥）',
  `pay_channel` varchar(20) NOT NULL DEFAULT '' COMMENT '支付渠道 balance/alipay/wechat/epay',
  `pay_status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '支付状态 0未支付 1已支付 2已关闭 3已退款',
  `trade_no` varchar(64) NOT NULL DEFAULT '' COMMENT '第三方交易号',
  `paid_time` int unsigned NOT NULL DEFAULT 0 COMMENT '支付时间',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_agent_id` (`agent_id`),
  KEY `idx_pay_status` (`pay_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单表';

-- 11. 折扣与营销活动表
CREATE TABLE IF NOT EXISTS `discount_activity` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '活动名称',
  `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '类型 1折扣 2满减 3首购优惠 4批量优惠',
  `value` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '折扣率/满减金额',
  `min_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '最低满足金额',
  `app_ids` varchar(255) NOT NULL DEFAULT '' COMMENT '适用产品ID（逗号分隔，空=全部产品）',
  `start_time` int unsigned NOT NULL DEFAULT 0 COMMENT '开始时间',
  `end_time` int unsigned NOT NULL DEFAULT 0 COMMENT '结束时间',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 1启用 2停用',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='折扣与营销活动表';

-- 12. 邮件发送日志表
CREATE TABLE IF NOT EXISTS `email_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `to_email` varchar(128) NOT NULL DEFAULT '' COMMENT '收件邮箱',
  `subject` varchar(255) NOT NULL DEFAULT '' COMMENT '邮件主题',
  `content` mediumtext COMMENT '邮件内容（H5）',
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '发送状态 0待发 1成功 2失败',
  `error_msg` varchar(255) NOT NULL DEFAULT '' COMMENT '失败原因',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_to_email` (`to_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件发送日志表';

-- 13. 系统审计操作日志表（含密钥接口高频访问事件、登录日志、忘记密码请求）
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `module` varchar(32) NOT NULL DEFAULT '' COMMENT '模块 auth/secret/pay/login/license/device/level/withdraw/ticket/sdk等',
  `action` varchar(64) NOT NULL DEFAULT '' COMMENT '操作动作',
  `actor_type` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '操作者 1管理员 2代理商 3用户 4系统/SDK',
  `actor_id` int unsigned NOT NULL DEFAULT 0 COMMENT '操作者ID',
  `detail` text COMMENT '详情',
  `ip` varchar(45) NOT NULL DEFAULT '' COMMENT 'IP地址',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '时间',
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module`),
  KEY `idx_actor` (`actor_type`, `actor_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统审计操作日志表';

-- 14. IP 封禁黑名单表
CREATE TABLE IF NOT EXISTS `ip_ban` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `ip` varchar(45) NOT NULL DEFAULT '' COMMENT 'IP地址',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '封禁原因',
  `ban_time` int unsigned NOT NULL DEFAULT 0 COMMENT '封禁时间',
  `expire_time` int unsigned NOT NULL DEFAULT 0 COMMENT '解封时间（0永久）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IP封禁黑名单表';

-- 15. SDK 模板表（V2 协议模板支持 {{app_id}} / {{app_key}} 占位符，禁止硬编码密钥）
CREATE TABLE IF NOT EXISTS `sdk_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '模板名称',
  `language` varchar(20) NOT NULL DEFAULT 'php' COMMENT '模板语言',
  `content` mediumtext COMMENT '模板源码（仅含{{app_id}}/{{app_key}}占位符，appSecret 经 /api/get_secret.php 下发）',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 1启用 2停用',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SDK模板表';

-- 16. SDK 下载日志表
CREATE TABLE IF NOT EXISTS `sdk_download_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `account_type` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '下载者 1管理员 2代理商',
  `account_id` int unsigned NOT NULL DEFAULT 0 COMMENT '下载者ID',
  `account_name` varchar(32) NOT NULL DEFAULT '' COMMENT '下载者账号',
  `app_id` int unsigned NOT NULL DEFAULT 0 COMMENT '下载的应用ID',
  `template_id` int unsigned NOT NULL DEFAULT 0 COMMENT '使用的模板ID',
  `ip` varchar(45) NOT NULL DEFAULT '' COMMENT '下载IP',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '下载时间',
  PRIMARY KEY (`id`),
  KEY `idx_app_id` (`app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SDK下载日志表';

-- 17. 提现申请表（代理商发起提现，管理员后台审核；用户提现预留 user_id）
CREATE TABLE IF NOT EXISTS `withdraw` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `agent_id` int unsigned NOT NULL DEFAULT 0 COMMENT '代理商ID（与 user_id 二选一）',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID（与 agent_id 二选一）',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '提现金额（扣减金额）',
  `fee` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '手续费',
  `fee_rate` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '费率快照（%）',
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '状态 0待审核 1已通过 2已驳回 3已打款',
  `account` varchar(128) NOT NULL DEFAULT '' COMMENT '收款账号（支付宝/银行等）',
  `agent_remark` varchar(255) NOT NULL DEFAULT '' COMMENT '代理商备注',
  `admin_remark` varchar(255) NOT NULL DEFAULT '' COMMENT '管理员审核备注',
  `admin_id` int unsigned NOT NULL DEFAULT 0 COMMENT '审核管理员ID',
  `review_time` int unsigned NOT NULL DEFAULT 0 COMMENT '审核时间',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '申请时间',
  PRIMARY KEY (`id`),
  KEY `idx_agent_id` (`agent_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提现申请表（用户/代理商）';

-- 18. 代理商等级表（用户端「开通代理」仅展示 auto_open=1 且启用的等级；升级仅更新折扣/权益/赠送，不迁移数据）
CREATE TABLE IF NOT EXISTS `agent_level` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '等级名称',
  `discount` decimal(5,2) NOT NULL DEFAULT 1.00 COMMENT '等级折扣（卡密售价系数，0.90=九折）',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '开通价格',
  `gift_balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '开通赠送余额',
  `benefits` text COMMENT '等级权益介绍（纯文本，换行分隔）',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序（越大越靠前）',
  `auto_open` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否在用户端「开通代理」展示 0关闭 1开启',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代理商等级';

-- 19. 财务流水表（用户/代理商统一；amount 记录正数，收支由 type 区分）
CREATE TABLE IF NOT EXISTS `finance_flow` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `user_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '账号类型 1用户 2代理商',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '账号ID',
  `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '类型 1购买授权 2余额充值 3开通等级 4升级等级',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '发生金额',
  `balance_after` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '变动后余额',
  `order_no` varchar(40) NOT NULL DEFAULT '' COMMENT '关联订单号',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注（扣款/赠送/充值区分）',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '时间',
  PRIMARY KEY (`id`),
  KEY `account` (`user_type`,`user_id`),
  KEY `created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='财务流水';

-- 20. 卡密验证日志表（授权验证接口全量落库，量大使用 bigint）
CREATE TABLE IF NOT EXISTS `verify_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `license_id` int unsigned NOT NULL DEFAULT 0 COMMENT '授权ID',
  `app_id` int unsigned NOT NULL DEFAULT 0 COMMENT '应用ID',
  `card_no` varchar(64) NOT NULL DEFAULT '' COMMENT '卡密授权码',
  `ip` varchar(45) NOT NULL DEFAULT '' COMMENT '请求IP',
  `domain` varchar(255) NOT NULL DEFAULT '' COMMENT '请求域名',
  `device_code` varchar(128) NOT NULL DEFAULT '' COMMENT '设备指纹',
  `result` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '结果 1成功 2失败',
  `fail_reason` varchar(120) NOT NULL DEFAULT '' COMMENT '失败原因',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '时间',
  PRIMARY KEY (`id`),
  KEY `lic` (`license_id`,`created_at`),
  KEY `ip` (`ip`,`created_at`),
  KEY `created` (`created_at`),
  KEY `result` (`result`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='卡密验证日志';

-- 21. 黑名单表（设备码/卡密/用户/邮箱/域名统一封禁）
CREATE TABLE IF NOT EXISTS `blacklist` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '类型 1设备码 2卡密 3用户 4邮箱 5域名',
  `value` varchar(191) NOT NULL DEFAULT '' COMMENT '封禁值',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '封禁原因',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 1生效 0停用',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `tv` (`type`,`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='黑名单';

-- 22. 告警中心表
CREATE TABLE IF NOT EXISTS `alert_center` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `level` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '级别 1提示 2警告 3严重',
  `module` varchar(32) NOT NULL DEFAULT '' COMMENT '来源模块',
  `title` varchar(191) NOT NULL DEFAULT '' COMMENT '标题',
  `content` varchar(500) NOT NULL DEFAULT '' COMMENT '内容',
  `ip` varchar(45) NOT NULL DEFAULT '' COMMENT '来源IP',
  `is_read` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '已读 0未读 1已读',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '时间',
  PRIMARY KEY (`id`),
  KEY `read` (`is_read`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='告警中心';

-- 23. 工单表（用户/代理商统一；对外编号 GD+时间+随机码，关闭后可评分）
CREATE TABLE IF NOT EXISTS `ticket` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `ticket_no` varchar(24) NOT NULL DEFAULT '' COMMENT '对外工单编号 GD+年月日时分秒+4位随机码',
  `user_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '提交人类型 1用户 2代理商',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '提交人ID',
  `title` varchar(191) NOT NULL DEFAULT '' COMMENT '工单标题',
  `category` tinyint unsigned NOT NULL DEFAULT 4 COMMENT '分类 1授权问题 2支付问题 3部署咨询 4其他',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 1待处理 2已回复 3已关闭',
  `last_reply` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '最后回复 0提交人 1管理员',
  `rating` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '服务评分 0未评 1-5星',
  `rating_note` varchar(191) NOT NULL DEFAULT '' COMMENT '评分附言',
  `rated_at` int unsigned NOT NULL DEFAULT 0 COMMENT '评分时间',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '提交时间',
  `updated_at` int unsigned NOT NULL DEFAULT 0 COMMENT '最后更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `account` (`user_type`,`user_id`),
  KEY `status` (`status`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工单';

-- 24. 工单回复表
CREATE TABLE IF NOT EXISTS `ticket_reply` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `ticket_id` int unsigned NOT NULL DEFAULT 0 COMMENT '工单ID',
  `sender_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '发送方 1用户/代理商 2管理员',
  `content` text COMMENT '回复内容',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '时间',
  PRIMARY KEY (`id`),
  KEY `ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工单回复';

-- 24.1 工单附件表（V2.1.5 新增：工单提交 / 回复可携带附件）
CREATE TABLE IF NOT EXISTS `ticket_attachment` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `ticket_id` int unsigned NOT NULL DEFAULT 0 COMMENT '工单ID',
  `reply_id` int unsigned NOT NULL DEFAULT 0 COMMENT '回复ID（0=首帖）',
  `user_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '上传方 1用户/代理商 2管理员',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '上传人ID',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `path` varchar(255) NOT NULL DEFAULT '' COMMENT '存储相对路径（uploads/ticket/ 下）',
  `size` int unsigned NOT NULL DEFAULT 0 COMMENT '字节数',
  `ext` varchar(16) NOT NULL DEFAULT '' COMMENT '扩展名',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '上传时间',
  PRIMARY KEY (`id`),
  KEY `ticket` (`ticket_id`),
  KEY `reply` (`reply_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工单附件';

-- 25. 应用套餐表（应用下多档套餐：限时/计次/永久）
CREATE TABLE IF NOT EXISTS `app_package` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `app_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属应用ID',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `license_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '授权类型 1限时 2计次 3永久（天数>0=1，0=3）',
  `auth_method` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '授权方式 1单域名 2泛域名 3IP 4密钥 0=不限制',
  `duration_days` int unsigned NOT NULL DEFAULT 30 COMMENT '授权天数（0=永久）',
  `max_sites` int unsigned NOT NULL DEFAULT 0 COMMENT '最大站点数 0=不限制',
  `total_count` int unsigned NOT NULL DEFAULT 100 COMMENT '计次总次数（旧模型保留）',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '套餐售价（0=按应用定价）',
  `original_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '划线原价（0=不显示）',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序（越大越靠前）',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `app` (`app_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用套餐';

-- 26. 应用版本表（License API V2：/api/app/version/check 版本检查与升级链）
CREATE TABLE IF NOT EXISTS `app_version` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `app_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属应用ID',
  `version` varchar(32) NOT NULL DEFAULT '' COMMENT '版本号（如 1.0.0）',
  `title` varchar(190) NOT NULL DEFAULT '' COMMENT '版本标题',
  `changelog` text COMMENT '更新说明',
  `file_path` varchar(255) NOT NULL DEFAULT '' COMMENT '更新包文件名（runtime/app_files/ 下，HTTP 禁止直读）',
  `file_size` bigint unsigned NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
  `file_md5` char(32) NOT NULL DEFAULT '' COMMENT '文件 MD5',
  `force_update` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '强制更新 0否 1是',
  `min_version` varchar(32) NOT NULL DEFAULT '' COMMENT '最低可用版本（低于此版本强制更新）',
  `update_sql` longtext COMMENT '该版本升级 SQL（分号分隔）',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1已发布 2已下架',
  `published_at` int unsigned NOT NULL DEFAULT 0 COMMENT '发布时间',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_ver` (`app_id`,`version`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用版本表';

-- 27. 插件状态与配置表（对齐魔方财务：状态+普通配置入库；敏感配置仍存 sys_config 的 plugin_cfg_{code}_{key}）
CREATE TABLE IF NOT EXISTS `plugin` (
  `code` varchar(50) NOT NULL COMMENT '插件代码（plugin/{code}/Plugin.php）',
  `status` tinyint unsigned NOT NULL DEFAULT 3 COMMENT '状态 1启用 2停用 3未安装',
  `cfg` text COMMENT '普通配置 JSON（敏感配置一律存 sys_config）',
  `updated_at` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='插件状态与配置';

-- 28. 系统公告表（后台发布，用户中心 / 代理商中心首页展示）
CREATE TABLE IF NOT EXISTS `notice` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `title` varchar(120) NOT NULL DEFAULT '' COMMENT '公告标题',
  `content` text COMMENT '公告内容（纯文本，换行保留）',
  `type` varchar(16) NOT NULL DEFAULT 'info' COMMENT '类型 info/success/warn/danger',
  `target` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '展示范围 1全部 2用户中心 3代理商中心',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序（越大越靠前）',
  `start_time` int unsigned NOT NULL DEFAULT 0 COMMENT '生效时间（0=立即）',
  `end_time` int unsigned NOT NULL DEFAULT 0 COMMENT '失效时间（0=永久）',
  `created_at` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `updated_at` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `status_target` (`status`,`target`),
  KEY `sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统公告';

