<?php
declare(strict_types=1);

/**
 * 开发期脚本：给 dev_request 补「开发排期」需要的字段（幂等）。
 *
 * 用法（本地）：
 *   php -d extension=pdo_mysql tools/migrate_schedule.php
 * 用法（线上，浏览器执行一次后请用 FTP 删除本文件）：
 *   /tools/migrate_schedule.php?token=<MIGRATE_TOKEN>
 *
 * 做两件事：加字段 plan_release_date / plan_release_by / plan_release_at /
 * plan_release_precision，加索引 idx_devreq_plan。字段或索引已存在则跳过，可重复执行。
 * 不动任何现有数据。
 */

/**
 * 执行迁移步骤（供 CLI / 浏览器 / 临时脚本共用）。
 * @return array<int,string> 执行日志
 */
function qly_migrate_schedule_run(PDO $pdo): array
{
    $log = [];
    $table = 'dev_request';

    // 字段：名称 => DDL 片段
    $columns = [
        'plan_release_date' => "ALTER TABLE `dev_request` ADD COLUMN `plan_release_date` DATE DEFAULT NULL COMMENT '预计上线日期（开发排期；NULL=未排期）' AFTER `released_by`",
        'plan_release_by' => "ALTER TABLE `dev_request` ADD COLUMN `plan_release_by` VARCHAR(60) DEFAULT NULL COMMENT '排期设置人（姓名快照）' AFTER `plan_release_date`",
        'plan_release_at' => "ALTER TABLE `dev_request` ADD COLUMN `plan_release_at` DATETIME DEFAULT NULL COMMENT '排期设置时间' AFTER `plan_release_by`",
        'plan_release_precision' => "ALTER TABLE `dev_request` ADD COLUMN `plan_release_precision` VARCHAR(8) NOT NULL DEFAULT 'day' COMMENT '排期精度：day=精确到日，month=只到月（月排期统一存为该月 1 日）' AFTER `plan_release_at`",
    ];
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
    foreach ($columns as $name => $ddl) {
        $st->execute([':t' => $table, ':c' => $name]);
        if ((int) $st->fetchColumn() > 0) {
            $log[] = $name . ' 已存在（跳过）';
            continue;
        }
        $pdo->exec($ddl);
        $log[] = $name . ' 已添加';
    }

    // 索引
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i');
    $st->execute([':t' => $table, ':i' => 'idx_devreq_plan']);
    if ((int) $st->fetchColumn() > 0) {
        $log[] = 'idx_devreq_plan 已存在（跳过）';
    } else {
        $pdo->exec('ALTER TABLE `dev_request` ADD KEY `idx_devreq_plan` (`plan_release_date`)');
        $log[] = 'idx_devreq_plan 已添加';
    }

    return $log;
}

// 作为库被临时脚本引用时（QLY_MIGRATE_SCHEDULE_LIB），只提供函数
if (defined('QLY_MIGRATE_SCHEDULE_LIB')) {
    return;
}

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $token = (string) ($_GET['token'] ?? '');
    $expect = (string) (getenv('MIGRATE_TOKEN') ?: '');
    if ($expect === '' || $token === '' || !hash_equals($expect, $token)) {
        http_response_code(403);
        echo json_encode(['code' => 403, 'message' => '禁止访问：口令不正确'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$root = dirname(__DIR__);
$siteDir = is_dir($root . '/site') ? $root . '/site' : $root;
require_once $siteDir . '/inc/config.php';

$cfg = qly_db_config();
$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$log = qly_migrate_schedule_run($pdo);

if ($isCli) {
    echo "迁移完成：\n" . implode("\n", $log) . "\n";
    echo "提示：可在执行完成后用 FTP 删除本文件。\n";
} else {
    echo json_encode(['code' => 0, 'message' => '迁移完成', 'data' => $log], JSON_UNESCAPED_UNICODE);
}
