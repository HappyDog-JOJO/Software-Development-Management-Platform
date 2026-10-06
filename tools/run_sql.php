<?php
declare(strict_types=1);

/**
 * 开发期脚本：把 database/init.sql 执行到 .env 指向的数据库。
 * 用法：php -d extension=pdo_mysql tools/run_sql.php
 */

$root = dirname(__DIR__);
require_once $root . '/site/inc/config.php';

$sqlFile = $root . '/database/init.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "missing init.sql\n");
    exit(1);
}
$cfg = qly_db_config();
$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$lines = file($sqlFile, FILE_IGNORE_NEW_LINES);
$stmt = '';
$count = 0;
$errors = 0;
foreach ($lines as $no => $line) {
    $trim = trim($line);
    if ($trim === '' || str_starts_with($trim, '--')) {
        continue;
    }
    $stmt .= $line . "\n";
    if (!str_ends_with($trim, ';')) {
        continue;
    }
    try {
        $pdo->exec($stmt);
        $count++;
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, "line " . ($no + 1) . " FAIL: " . $e->getMessage() . "\n");
        fwrite(STDERR, "  stmt head: " . mb_substr($stmt, 0, 160) . "\n");
    }
    $stmt = '';
}
echo "executed={$count} errors={$errors}\n";

foreach (['department', 'user', 'dict_item', 'issue', 'issue_attachment', 'handle_record', 'handle_attachment', 'operation_log', 'issue_append'] as $t) {
    $n = $pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
    echo str_pad($t, 20) . ' = ' . $n . "\n";
}
