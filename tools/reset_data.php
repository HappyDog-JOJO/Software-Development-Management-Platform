<?php
declare(strict_types=1);

/**
 * 开发期脚本：清空并重新导入 database/init.sql，恢复干净的演示数据。
 * 仅用于开发环境（自测后复位），不进入交付包。
 * 用法：php -d extension=pdo_mysql -d extension=mbstring tools/reset_data.php
 */

$root = dirname(__DIR__);
require_once $root . '/site/inc/config.php';

$cfg = qly_db_config();
$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$tables = [
    'handle_attachment',
    'issue_attachment',
    'issue_append',
    'handle_record',
    'issue',
    'duty_schedule',
    'operation_log',
    'dict_item',
    'user',
    'department',
];
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $t) {
    $pdo->exec('TRUNCATE TABLE `' . $t . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "cleared\n";

$sqlFile = $root . '/database/init.sql';
$lines = file($sqlFile, FILE_IGNORE_NEW_LINES);
$stmt = '';
$count = 0;
foreach ($lines as $line) {
    $trim = trim($line);
    if ($trim === '' || str_starts_with($trim, '--')) {
        continue;
    }
    $stmt .= $line . "\n";
    if (!str_ends_with($trim, ';')) {
        continue;
    }
    $pdo->exec($stmt);
    $count++;
    $stmt = '';
}
echo "reimported statements={$count}\n";

foreach (['department', 'user', 'dict_item', 'issue', 'issue_attachment', 'handle_record', 'handle_attachment', 'duty_schedule', 'operation_log', 'issue_append'] as $t) {
    $n = $pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
    echo str_pad($t, 20) . ' = ' . $n . "\n";
}
