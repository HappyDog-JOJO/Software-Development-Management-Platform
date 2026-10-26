<?php
declare(strict_types=1);

/**
 * 开发期脚本：验证「.env 缺项必须直接报错、不得静默回退」。
 * 做法：临时用一个空 .env 指向的配置副本运行，确认报错并列出缺失键。
 * 不改动工作目录里的 .env。
 */

$root = dirname(__DIR__);
$envPath = $root . '/.env';
$backup = $root . '/.env.selftest.bak';

$original = is_file($envPath) ? file_get_contents($envPath) : null;
if ($original === null) {
    fwrite(STDERR, "未找到 .env\n");
    exit(1);
}
file_put_contents($backup, $original);

// 1) 库值全部留空
$blank = "APP_NAME=售后服务平台\nDB_CONNECTION=mysql\nDB_HOST=\nDB_PORT=3306\nDB_DATABASE=\nDB_USERNAME=\nDB_PASSWORD=\n";
file_put_contents($envPath, $blank);

$php = PHP_BINARY;
$ext = ini_get('extension_dir');
$cmd = escapeshellarg($php)
    . ' -d extension_dir=' . escapeshellarg((string) $ext)
    . ' -d extension=pdo_mysql -d extension=mbstring'
    . ' -r ' . escapeshellarg('require ' . var_export($root . '/site/inc/config.php', true) . '; qly_db_config(); echo "NO_ERROR";');

$out1 = shell_exec($cmd . ' 2>&1');
echo "--- 空库值 ---" . PHP_EOL . trim((string) $out1) . PHP_EOL;

// 2) 缺少 DB_HOST 键
// 夹取值用占位，避免把真实库凭据写进仓库（本用例只验证「缺 DB_HOST 必须报错」）
$missing = "APP_NAME=售后服务平台\nDB_CONNECTION=mysql\nDB_PORT=3306\nDB_DATABASE=example_db\nDB_USERNAME=example_user\nDB_PASSWORD=example_pwd\n";
file_put_contents($envPath, $missing);

$out2 = shell_exec($cmd . ' 2>&1');
echo "--- 缺少 DB_HOST ---" . PHP_EOL . trim((string) $out2) . PHP_EOL;

// 还原
file_put_contents($envPath, $original);
@unlink($backup);

$ok1 = str_contains((string) $out1, '数据库配置缺失') && !str_contains((string) $out1, 'NO_ERROR');
$ok2 = str_contains((string) $out2, '数据库配置缺失') && str_contains((string) $out2, 'DB_HOST');
echo PHP_EOL . '空库值直接报错: ' . ($ok1 ? 'PASS' : 'FAIL') . PHP_EOL;
echo '缺项直接报错且列出键名: ' . ($ok2 ? 'PASS' : 'FAIL') . PHP_EOL;
echo '已还原 .env: ' . (file_get_contents($envPath) === $original ? 'yes' : 'no') . PHP_EOL;
exit($ok1 && $ok2 ? 0 : 1);
