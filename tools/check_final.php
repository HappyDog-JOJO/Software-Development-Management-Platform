<?php
declare(strict_types=1);

/** 开发期脚本：最终交付核对（演示账号 / 角色 / 排班 / 密码）。 */

$root = dirname(__DIR__);
require_once $root . '/site/inc/auth.php';

$a = qly_one('SELECT * FROM `user` WHERE account = :a', [':a' => '商辂']);
echo 'admin_password_1994_ok=' . (password_verify('1994', (string) $a['password_hash']) ? 'yes' : 'no') . PHP_EOL;
echo 'admin_roles=' . $a['roles'] . PHP_EOL;
$s = qly_one('SELECT * FROM `user` WHERE account = :a', [':a' => 'service02']);
echo 'service02_roles=' . $s['roles'] . PHP_EOL;
$sales = qly_one('SELECT * FROM `user` WHERE account = :a', [':a' => 'sales01']);
echo 'sales01_password_ok=' . (password_verify('123456', (string) $sales['password_hash']) ? 'yes' : 'no') . PHP_EOL;

echo 'duty_count=' . qly_val('SELECT COUNT(*) FROM `duty_schedule`') . PHP_EOL;
foreach (qly_all('SELECT user_name, COUNT(*) AS c FROM `duty_schedule` GROUP BY user_name ORDER BY c DESC') as $r) {
    echo '  ' . $r['user_name'] . ' = ' . $r['c'] . PHP_EOL;
}
echo 'duty_candidates=' . count(qly_all("SELECT id FROM `user` WHERE status = 1 AND deleted_at IS NULL AND roles LIKE '%售后服务人员%'")) . PHP_EOL;

foreach (['department', 'user', 'dict_item', 'issue', 'handle_record', 'operation_log', 'duty_schedule'] as $t) {
    echo str_pad($t, 16) . '=' . qly_val('SELECT COUNT(*) FROM `' . $t . '`') . PHP_EOL;
}
