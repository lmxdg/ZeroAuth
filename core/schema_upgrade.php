<?php
// 数据库结构自动迁移（版本比对增量执行）
declare(strict_types=1);

function schema_upgrade_boot(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $lock = RUNTIME_PATH . '/.schema_v20.lock';

    $needTables = [
        'agent_level',
        'finance_flow',
        'email_log',
        'verify_log',
        'blacklist',
        'alert_center',
        'ticket',
        'ticket_reply',
        'app_package',
        'app_version',
        'plugin',
    ];
    try {
        $ph = implode(',', array_fill(0, count($needTables), '?'));
        $st = db()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($ph)");
        $st->execute($needTables);
        $complete = (int)$st->fetchColumn() === count($needTables);
    } catch (Throwable $ex) {
        $complete = false; 
    }
    if ($complete && is_file($lock)) {
        return;
    }
    @file_put_contents($lock, (string)time());
    $ddl = [
        "CREATE TABLE IF NOT EXISTS `agent_level` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(64) NOT NULL DEFAULT '' COMMENT '等级名称',
            `discount` decimal(5,2) NOT NULL DEFAULT 1.00 COMMENT '等级折扣（卡密售价系数，0.90=九折）',
            `price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '开通价格',
            `gift_balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '开通赠送余额',
            `benefits` text COMMENT '等级权益介绍（纯文本，换行分隔）',
            `sort` int NOT NULL DEFAULT 0 COMMENT '排序（越大越靠前）',
            `auto_open` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否在用户端「开通代理」展示 0关闭 1开启',
            `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `sort` (`sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代理商等级'",
        "CREATE TABLE IF NOT EXISTS `finance_flow` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `user_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1用户 2代理商',
            `user_id` int unsigned NOT NULL DEFAULT 0,
            `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1购买授权 2余额充值 3开通等级 4升级等级',
            `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '发生金额',
            `balance_after` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '变动后余额',
            `order_no` varchar(40) NOT NULL DEFAULT '' COMMENT '关联订单号',
            `remark` varchar(255) NOT NULL DEFAULT '',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `account` (`user_type`,`user_id`),
            KEY `created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='财务流水'",
        "CREATE TABLE IF NOT EXISTS `email_log` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `to_email` varchar(128) NOT NULL DEFAULT '' COMMENT '收件邮箱',
            `subject` varchar(255) NOT NULL DEFAULT '' COMMENT '邮件主题',
            `content` mediumtext COMMENT '邮件内容（H5）',
            `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '发送状态 0待发 1成功 2失败',
            `error_msg` varchar(255) NOT NULL DEFAULT '' COMMENT '失败原因',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_to_email` (`to_email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件发送日志'",
        "CREATE TABLE IF NOT EXISTS `verify_log` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `license_id` int unsigned NOT NULL DEFAULT 0,
            `app_id` int unsigned NOT NULL DEFAULT 0,
            `card_no` varchar(64) NOT NULL DEFAULT '',
            `ip` varchar(45) NOT NULL DEFAULT '',
            `domain` varchar(255) NOT NULL DEFAULT '',
            `device_code` varchar(128) NOT NULL DEFAULT '',
            `result` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1成功 2失败',
            `fail_reason` varchar(120) NOT NULL DEFAULT '',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `lic` (`license_id`,`created_at`),
            KEY `ip` (`ip`,`created_at`),
            KEY `created` (`created_at`),
            KEY `result` (`result`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='卡密验证日志'",
        "CREATE TABLE IF NOT EXISTS `blacklist` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1设备码 2卡密 3用户 4邮箱 5域名',
            `value` varchar(191) NOT NULL DEFAULT '',
            `reason` varchar(255) NOT NULL DEFAULT '',
            `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1生效 0停用',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tv` (`type`,`value`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='黑名单'",
        "CREATE TABLE IF NOT EXISTS `alert_center` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `level` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1提示 2警告 3严重',
            `module` varchar(32) NOT NULL DEFAULT '' COMMENT '来源模块',
            `title` varchar(191) NOT NULL DEFAULT '',
            `content` varchar(500) NOT NULL DEFAULT '',
            `ip` varchar(45) NOT NULL DEFAULT '',
            `is_read` tinyint unsigned NOT NULL DEFAULT 0,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `read` (`is_read`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='告警中心'",
        "CREATE TABLE IF NOT EXISTS `ticket` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `ticket_no` varchar(24) NOT NULL DEFAULT '' COMMENT '对外工单编号 GD+年月日时分秒+4位随机码',
            `user_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1用户 2代理商',
            `user_id` int unsigned NOT NULL DEFAULT 0,
            `title` varchar(191) NOT NULL DEFAULT '',
            `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1待处理 2已回复 3已关闭',
            `last_reply` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '最后回复 0提交人 1管理员',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_ticket_no` (`ticket_no`),
            KEY `account` (`user_type`,`user_id`),
            KEY `status` (`status`,`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工单'",
        "CREATE TABLE IF NOT EXISTS `ticket_reply` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `ticket_id` int unsigned NOT NULL DEFAULT 0,
            `sender_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1用户/代理商 2管理员',
            `content` text,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `ticket` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工单回复'",
        "CREATE TABLE IF NOT EXISTS `app_package` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `app_id` int unsigned NOT NULL DEFAULT 0,
            `name` varchar(64) NOT NULL DEFAULT '' COMMENT '套餐名称',
            `license_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1限时 2计次 3永久',
            `duration_days` int unsigned NOT NULL DEFAULT 30 COMMENT '限时有效天数',
            `total_count` int unsigned NOT NULL DEFAULT 100 COMMENT '计次总次数',
            `price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '套餐售价（0=按应用定价）',
            `original_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '划线原价（0=不显示）',
            `sort` int NOT NULL DEFAULT 0 COMMENT '排序（越大越靠前）',
            `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `app` (`app_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用套餐'",
        "CREATE TABLE IF NOT EXISTS `app_version` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `app_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属应用ID',
            `version` varchar(32) NOT NULL DEFAULT '' COMMENT '版本号（如 1.5.0）',
            `title` varchar(190) NOT NULL DEFAULT '' COMMENT '版本标题',
            `changelog` text COMMENT '更新说明',
            `file_path` varchar(255) NOT NULL DEFAULT '' COMMENT '更新包文件名（runtime/app_files/ 下）',
            `file_size` bigint unsigned NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
            `file_md5` char(32) NOT NULL DEFAULT '' COMMENT '文件 MD5',
            `force_update` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '强制更新 0否 1是',
            `min_version` varchar(32) NOT NULL DEFAULT '' COMMENT '最低可用版本（低于此版本强制更新）',
            `update_sql` longtext COMMENT '该版本升级 SQL（分号分隔）',
            `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1已发布 2已下架',
            `published_at` int unsigned NOT NULL DEFAULT 0 COMMENT '发布时间',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_app_ver` (`app_id`,`version`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用版本表'",
        "CREATE TABLE IF NOT EXISTS `plugin` (
            `code` varchar(50) NOT NULL COMMENT '插件代码（plugin/{code}/Plugin.php）',
            `status` tinyint unsigned NOT NULL DEFAULT 3 COMMENT '1启用 2停用 3未安装',
            `cfg` text COMMENT '普通配置 JSON（敏感配置一律存 sys_config）',
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='插件状态与配置'",
    ];
    foreach ($ddl as $sql) {
        try {
            db()->exec($sql);
        } catch (Throwable $ex) {
            @file_put_contents(RUNTIME_PATH . '/schema_upgrade.log', date('Y-m-d H:i:s') . ' ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
        }
    }
    $legacyPluginFile = RUNTIME_PATH . '/plugins.json';
    if (is_file($legacyPluginFile)) {
        try {
            $j = json_decode((string)@file_get_contents($legacyPluginFile), true);
            if (is_array($j)) {
                $st = db()->prepare(
                    'INSERT INTO `plugin` (`code`,`status`,`cfg`,`updated_at`) VALUES (?,?,?,?) '
                    . 'ON DUPLICATE KEY UPDATE `status`=VALUES(`status`),`cfg`=VALUES(`cfg`),`updated_at`=VALUES(`updated_at`)'
                );
                foreach ($j as $code => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $cfg = (array)($row['cfg'] ?? []);
                    $st->execute([
                        (string)$code,
                        (int)($row['status'] ?? 3),
                        $cfg === [] ? '' : json_encode($cfg, JSON_UNESCAPED_UNICODE),
                        time(),
                    ]);
                }
            }
            @unlink($legacyPluginFile);
        } catch (Throwable $ex) {
            @file_put_contents(RUNTIME_PATH . '/schema_upgrade.log', date('Y-m-d H:i:s') . ' plugins.json迁移失败 ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
        }
    }
    try {
        db()->exec("ALTER TABLE `agent` ADD COLUMN `level_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属等级（0=默认）' AFTER `discount`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `agent` ADD COLUMN `gift_balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计等级赠送余额' AFTER `level_id`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `ticket` ADD COLUMN `category` tinyint unsigned NOT NULL DEFAULT 4 COMMENT '分类 1授权问题 2支付问题 3部署咨询 4其他' AFTER `title`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `ticket` ADD COLUMN `rating` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '服务评分 0未评 1-5星' AFTER `last_reply`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `ticket` ADD COLUMN `rating_note` varchar(191) NOT NULL DEFAULT '' COMMENT '评分附言' AFTER `rating`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `ticket` ADD COLUMN `rated_at` int unsigned NOT NULL DEFAULT 0 COMMENT '评分时间' AFTER `rating_note`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `ticket` ADD COLUMN `ticket_no` varchar(24) NOT NULL DEFAULT '' COMMENT '对外工单编号' AFTER `id`");
        $rows = db()->query('SELECT `id`,`created_at` FROM `ticket` WHERE `ticket_no`=\'\'')->fetchAll();
        $st = db()->prepare('UPDATE `ticket` SET `ticket_no`=? WHERE `id`=?');
        foreach ($rows as $r) {
            $no = 'GD' . date('YmdHis', (int)$r['created_at']) . str_pad((string)((int)$r['id'] % 10000), 4, '0', STR_PAD_LEFT);
            $st->execute([$no, (int)$r['id']]);
        }
    } catch (Throwable $ex) {
    }
    try {
        db()->exec('ALTER TABLE `ticket` ADD UNIQUE KEY `uk_ticket_no` (`ticket_no`)');
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `license` ADD COLUMN `target_type` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '授权目标 0按绑定目标 1域名 2泛域名 3IP 4纯密钥' AFTER `type`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `license_domain` ADD COLUMN `target_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '目标类型 1域名 2泛域名 3IP' AFTER `domain`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `agent` ADD COLUMN `invite_code` char(10) NOT NULL DEFAULT '' COMMENT '代理商邀请码（名下用户绑定时填写）' AFTER `discount`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `agent` ADD INDEX `idx_invite_code` (`invite_code`)");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `order` ADD COLUMN `agent_id` int unsigned NOT NULL DEFAULT 0 COMMENT '代理商ID（agent 端充值单归属）' AFTER `user_id`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `order` ADD INDEX `idx_agent_id` (`agent_id`)");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `order` ADD COLUMN `discount_name` varchar(64) NOT NULL DEFAULT '' COMMENT '优惠名称（活动名 / 代理等级折扣）' AFTER `discount_amount`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `user` ADD COLUMN `last_login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP' AFTER `reg_ip`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `order` ADD COLUMN `package_id` int unsigned NOT NULL DEFAULT 0 COMMENT '套餐ID（0=非套餐订单）' AFTER `app_id`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `order` ADD COLUMN `target_type` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '授权目标 0默认 1域名 2泛域名 3IP 4纯密钥' AFTER `total_count`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `order` ADD COLUMN `target_value` varchar(255) NOT NULL DEFAULT '' COMMENT '授权目标值（域名/IP/自定义密钥）' AFTER `target_type`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `order` ADD COLUMN `max_sites` int unsigned NOT NULL DEFAULT 0 COMMENT '最大站点数 0=不限制' AFTER `target_value`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `app_package` ADD COLUMN `auth_method` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '授权方式 1单域名 2泛域名 3IP 4密钥 0=不限制' AFTER `license_type`");
    } catch (Throwable $ex) {
    }
    try {
        db()->exec("ALTER TABLE `app_package` ADD COLUMN `max_sites` int unsigned NOT NULL DEFAULT 0 COMMENT '最大站点数 0=不限制' AFTER `auth_method`");
    } catch (Throwable $ex) {
    }
}

function finance_flow_add(int $userType, int $userId, int $type, float $amount, float $balanceAfter, string $orderNo = '', string $remark = ''): void
{
    try {
        db()->prepare(
            'INSERT INTO `finance_flow` (`user_type`,`user_id`,`type`,`amount`,`balance_after`,`order_no`,`remark`,`created_at`) VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$userType, $userId, $type, number_format($amount, 2, '.', ''), number_format($balanceAfter, 2, '.', ''), mb_substr($orderNo, 0, 40), mb_substr($remark, 0, 250), time()]);
    } catch (Throwable $ex) {
        @file_put_contents(RUNTIME_PATH . '/schema_upgrade.log', date('Y-m-d H:i:s') . ' flow写入失败 ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

function agent_effective_discount(array $agent): float
{
    $lid = (int)($agent['level_id'] ?? 0);
    if ($lid <= 0) {
        return (float)($agent['discount'] ?? 1);
    }
    try {
        $stmt = db()->prepare('SELECT `discount` FROM `agent_level` WHERE `id`=? AND `status`=1 LIMIT 1');
        $stmt->execute([$lid]);
        $d = $stmt->fetchColumn();
        return $d !== false ? (float)$d : (float)($agent['discount'] ?? 1);
    } catch (Throwable $ex) {
        return (float)($agent['discount'] ?? 1);
    }
}

function finance_type_name(int $type): string
{
    return [1 => '购买授权', 2 => '余额充值', 3 => '开通等级', 4 => '升级等级'][$type] ?? '其他';
}

function blacklist_type_name(int $type): string
{
    return [1 => '设备码', 2 => '卡密', 3 => '用户', 4 => '邮箱', 5 => '域名'][$type] ?? '未知';
}

function blacklist_hit(array $pairs): ?array
{
    if (!$pairs) {
        return null;
    }
    $where = [];
    $params = [];
    foreach ($pairs as $type => $values) {
        if (!$values) {
            continue;
        }
        $values = array_values(array_unique(array_filter(array_map('strval', $values), static fn ($v) => $v !== '')));
        if (!$values) {
            continue;
        }
        $where[] = '(`type`=? AND `value` IN (' . implode(',', array_fill(0, count($values), '?')) . '))';
        $params[] = $type;
        array_push($params, ...$values);
    }
    if (!$where) {
        return null;
    }
    try {
        $stmt = db()->prepare('SELECT * FROM `blacklist` WHERE `status`=1 AND (' . implode(' OR ', $where) . ') LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $ex) {
        return null;
    }
}
