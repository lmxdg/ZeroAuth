<?php
// 管理员后台 - 注册分布地图数据
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

$admin = admin_require_login();

try {
    db()->query("ALTER TABLE `user` ADD COLUMN `country_code` varchar(2) NOT NULL DEFAULT '' COMMENT '注册IP归属国家代码（ISO2，--为内网/无效）' AFTER `reg_ip`");
} catch (Throwable $ex) {  }
try {
    db()->query("ALTER TABLE `user` ADD COLUMN `province` varchar(64) NOT NULL DEFAULT '' COMMENT '注册IP归属省份（中文短名）' AFTER `country_code`");
} catch (Throwable $ex) {  }
try {
    db()->query("ALTER TABLE `user` ADD COLUMN `city` varchar(64) NOT NULL DEFAULT '' COMMENT '注册IP归属城市（中文）' AFTER `province`");
} catch (Throwable $ex) {  }

function province_short(string $s): string
{
    foreach (['特别行政区', '维吾尔自治区', '壮族自治区', '回族自治区', '自治区', '省', '市'] as $suffix) {
        if ($suffix !== '' && str_ends_with($s, $suffix) && mb_strlen($s) > mb_strlen($suffix)) {
            return mb_substr($s, 0, mb_strlen($s) - mb_strlen($suffix));
        }
    }
    return $s;
}

function parse_cn_location(string $location): array
{
    $loc = trim(explode(' ', trim($location))[0]);     $municipal = ['北京市', '上海市', '天津市', '重庆市'];
    foreach ($municipal as $m) {
        if (str_starts_with($loc, $m)) {
            $short = province_short($m);
            return ['CN', $short, $short];
        }
    }
    if (preg_match('/^(.+?(?:省|自治区))/', $loc, $m)) {
        $province = province_short($m[1]);
        $rest = mb_substr($loc, mb_strlen($m[1]));
        $city = '';
        if (preg_match('/^(.+?市)/', $rest, $cm)) {
            $city = province_short($cm[1]);
        } elseif ($rest !== '') {
            $city = $rest;
        }
        return ['CN', $province, $city];
    }
    if (preg_match('/^(香港|澳门)/', $loc, $m)) {
        return ['CN', $m[1], $m[1]];
    }
    return ['--', '', ''];
}

function resolve_reg_ips(): void
{
    $rows = db()->query("SELECT `id`,`reg_ip`,`country_code`,`province` FROM `user` WHERE `country_code`='' OR (`country_code`='CN' AND `province`='') ORDER BY `id` ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }
    $upd = db()->prepare("UPDATE `user` SET `country_code`=?, `province`=?, `city`=? WHERE `id`=?");
    foreach ($rows as $r) {
        $ip = trim((string)$r['reg_ip']);
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($public === false) {
            if ($r['country_code'] === '') {
                $upd->execute(['--', '', '', (int)$r['id']]);
            }
            continue;
        }
        $ch = curl_init('https://opendata.baidu.com/api.php?query=' . urlencode($ip) . '&co=&resource_id=6006&oe=utf8');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => 3000,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (!is_string($resp) || $resp === '') {
            continue; 
        }
        $json = json_decode($resp, true);
        $location = '';
        if (is_array($json) && ($json['status'] ?? '') === '0' && !empty($json['data'][0]['location'])) {
            $location = (string)$json['data'][0]['location'];
        }
        if ($location === '') {
            $upd->execute([$r['country_code'] !== '' ? $r['country_code'] : '--', '', '', (int)$r['id']]);
            continue;
        }
        [$code, $province, $city] = parse_cn_location($location);
        $upd->execute([$code, $province, $city, (int)$r['id']]);
    }
}

resolve_reg_ips();

$stats = []; 
foreach (db()->query("SELECT `province` p, COUNT(*) c, MAX(`created_at`) lr FROM `user` WHERE `country_code`='CN' AND `province`<>'' GROUP BY `province`") as $r) {
    $stats[$r['p']] = ['users' => (int)$r['c'], 'orders' => 0, 'amount' => 0.0, 'online' => 0, 'lastReg' => (int)$r['lr']];
}

foreach (db()->query("SELECT u.`province` p, COUNT(*) c, COALESCE(SUM(CASE WHEN o.`pay_status`=1 THEN o.`amount` ELSE 0 END),0) amt FROM `order` o JOIN `user` u ON u.`id`=o.`user_id` WHERE u.`country_code`='CN' AND u.`province`<>'' GROUP BY u.`province`") as $r) {
    if (!isset($stats[$r['p']])) {
        $stats[$r['p']] = ['users' => 0, 'orders' => 0, 'amount' => 0.0, 'online' => 0, 'lastReg' => 0];
    }
    $stats[$r['p']]['orders'] = (int)$r['c'];
    $stats[$r['p']]['amount'] = round((float)$r['amt'], 2);
}

foreach (db()->query("SELECT u.`province` p, COUNT(*) c FROM `license` l JOIN `user` u ON u.`id`=l.`user_id` WHERE l.`status`=1 AND (l.`type` IN (2,3) OR l.`expire_time`=0 OR l.`expire_time`>UNIX_TIMESTAMP()) AND u.`country_code`='CN' AND u.`province`<>'' GROUP BY u.`province`") as $r) {
    if (!isset($stats[$r['p']])) {
        $stats[$r['p']] = ['users' => 0, 'orders' => 0, 'amount' => 0.0, 'online' => 0, 'lastReg' => 0];
    }
    $stats[$r['p']]['online'] = (int)$r['c'];
}

$citiesByProvince = []; foreach (db()->query("SELECT `province` p, `city` c, COUNT(*) n FROM `user` WHERE `country_code`='CN' AND `province`<>'' GROUP BY `province`, `city` ORDER BY n DESC") as $r) {
    $p = $r['p'];
    $cityName = trim((string)$r['c']);
    if ($cityName === '') {
        $cityName = '其他';
    }
    $citiesByProvince[$p][$cityName] = (int)$r['n'];
}

$regions = [];
foreach ($stats as $province => $s) {
    $cities = [];
    if (isset($citiesByProvince[$province])) {
        $i = 0;
        foreach ($citiesByProvince[$province] as $cityName => $n) {
            if ($i++ >= 6) break;
            $cities[] = ['name' => $cityName, 'users' => $n];
        }
    }
    $regions[] = [
        'name' => $province,           'users' => $s['users'],
        'orders' => $s['orders'],
        'amount' => number_format($s['amount'], 2, '.', ''),
        'online' => $s['online'],
        'lastReg' => $s['lastReg'],
        'cities' => $cities,
    ];
}

$unresolved = (int)db()->query("SELECT COUNT(*) FROM `user` WHERE `country_code`=''")->fetchColumn();

json_out(0, 'success', ['regions' => $regions, 'unresolved' => $unresolved]);
