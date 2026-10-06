<?php
declare(strict_types=1);

/** 开发期脚本：清理本月之外的排班测试残留，并按日轮转重排本月。 */

$root = dirname(__DIR__);
require_once $root . '/site/inc/db.php';

$start = date('Y-m-01');
$end = date('Y-m-t');

$del = qly_exec('DELETE FROM `duty_schedule` WHERE duty_date < :s OR duty_date > :e', [':s' => $start, ':e' => $end]);
echo 'deleted out-of-month rows: ' . $del . PHP_EOL;

// 按日顺次轮转（含周末），保证两人天数均衡
$users = [
    ['id' => 6, 'name' => '周敏'],
    ['id' => 7, 'name' => '吴涛'],
];
$first = new DateTimeImmutable($start);
$days = (int) $first->format('t');
$now = date('Y-m-d H:i:s');
$saved = 0;
for ($i = 0; $i < $days; $i++) {
    $day = $first->modify('+' . $i . ' day');
    $pick = $users[$i % 2];
    $dow = (int) $day->format('N');
    $exists = qly_one('SELECT id FROM `duty_schedule` WHERE duty_date = :d LIMIT 1', [':d' => $day->format('Y-m-d')]);
    $data = [
        'user_id' => $pick['id'],
        'user_name' => $pick['name'],
        'remark' => $dow >= 6 ? '周末值班' : '',
        'updated_at' => $now,
    ];
    if ($exists) {
        qly_update('duty_schedule', (int) $exists['id'], $data);
    } else {
        $data['duty_date'] = $day->format('Y-m-d');
        $data['created_at'] = $now;
        qly_insert('duty_schedule', $data);
    }
    $saved++;
}
echo 'normalized rows: ' . $saved . PHP_EOL;

$r = qly_one('SELECT MIN(duty_date) AS mn, MAX(duty_date) AS mx, COUNT(*) AS c FROM `duty_schedule`');
echo 'range: ' . $r['mn'] . ' ~ ' . $r['mx'] . ' total ' . $r['c'] . PHP_EOL;
foreach (qly_all('SELECT user_name, COUNT(*) AS c FROM `duty_schedule` GROUP BY user_name ORDER BY c DESC') as $row) {
    echo '  ' . $row['user_name'] . ' = ' . $row['c'] . PHP_EOL;
}
$today = qly_one('SELECT user_name FROM `duty_schedule` WHERE duty_date = :d', [':d' => date('Y-m-d')]);
echo 'today (' . date('Y-m-d') . '): ' . ($today ? $today['user_name'] : '未排班') . PHP_EOL;
