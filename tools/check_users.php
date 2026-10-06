<?php
declare(strict_types=1);

/** 开发期脚本：核对用户关键字段的实际存储值 */

$root = dirname(__DIR__);
require_once $root . '/site/inc/auth.php';

echo 'account     | must_change | status | roles' . PHP_EOL;
echo str_repeat('-', 62) . PHP_EOL;
foreach (qly_all('SELECT account, must_change_password, status, roles FROM `user` ORDER BY id') as $r) {
    echo str_pad((string) $r['account'], 12) . '| '
        . str_pad(var_export((int) $r['must_change_password'], true), 12) . '| '
        . str_pad((string) $r['status'], 7) . '| ' . $r['roles'] . PHP_EOL;
}
