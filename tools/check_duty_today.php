<?php
declare(strict_types=1);

/** 开发期脚本：核对时区修复后「今日值班」是否正确 */

$root = dirname(__DIR__);
require_once $root . '/site/inc/auth.php';

echo 'PHP 今日: ' . date('Y-m-d') . ' ' . date('H:i:s') . PHP_EOL;
echo 'PHP 时区: ' . date_default_timezone_get() . PHP_EOL;
echo 'DB CURDATE: ' . (string) qly_val('SELECT CURDATE()') . PHP_EOL;
$r = qly_one('SELECT MIN(duty_date) AS mn, MAX(duty_date) AS mx, COUNT(*) AS c FROM `duty_schedule`');
echo '排班范围: ' . $r['mn'] . ' ~ ' . $r['mx'] . '（共 ' . $r['c'] . ' 天）' . PHP_EOL;

$today = qly_one('SELECT duty_date, user_name, remark FROM `duty_schedule` WHERE duty_date = :d LIMIT 1', [':d' => date('Y-m-d')]);
echo '今日值班: ' . ($today ? $today['user_name'] . '（' . $today['duty_date'] . '）' : '未排班') . PHP_EOL;

echo PHP_EOL . '最近 5 天排班：' . PHP_EOL;
foreach (qly_all('SELECT duty_date, user_name FROM `duty_schedule` WHERE duty_date >= :d ORDER BY duty_date LIMIT 5', [':d' => date('Y-m-d')]) as $row) {
    echo '  ' . $row['duty_date'] . ' -> ' . $row['user_name'] . PHP_EOL;
}
