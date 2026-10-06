<?php
declare(strict_types=1);

/**
 * 开发期脚本：新增「问题分类」字典类型及其关联字段（幂等，可重复执行）。
 *
 * 变更内容：
 * - dict_item：字典类型说明补充 category（字段本身无需改，type 为 VARCHAR）。
 * - issue：新增 category_id / category_name（最新问题分类，冗余便于列表展示）。
 * - handle_record：新增 category_id / category_name（每条处理记录的分类）。
 * - 重建两个视图类字段的索引（idx_issue_category / idx_hr_category）。
 *
 * 用法：php -d extension=pdo_mysql -d extension=mbstring tools/migrate_category.php
 */

$root = dirname(__DIR__);
require_once $root . '/site/inc/config.php';

$cfg = qly_db_config();
$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function colExists(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
    $st->execute([':t' => $table, ':c' => $col]);
    return (int) $st->fetchColumn() > 0;
}

function idxExists(PDO $pdo, string $table, string $idx): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i');
    $st->execute([':t' => $table, ':i' => $idx]);
    return (int) $st->fetchColumn() > 0;
}

$changes = 0;

// issue.category_id / category_name
if (!colExists($pdo, 'issue', 'category_id')) {
    $pdo->exec("ALTER TABLE `issue` ADD COLUMN `category_id` INT UNSIGNED DEFAULT NULL COMMENT '最新问题分类' AFTER `module_id`");
    echo "added issue.category_id\n";
    $changes++;
} else {
    echo "issue.category_id exists\n";
}
if (!colExists($pdo, 'issue', 'category_name')) {
    $pdo->exec("ALTER TABLE `issue` ADD COLUMN `category_name` VARCHAR(60) DEFAULT NULL COMMENT '最新问题分类名称' AFTER `category_id`");
    echo "added issue.category_name\n";
    $changes++;
} else {
    echo "issue.category_name exists\n";
}

// handle_record.category_id / category_name
if (!colExists($pdo, 'handle_record', 'category_id')) {
    $pdo->exec("ALTER TABLE `handle_record` ADD COLUMN `category_id` INT UNSIGNED DEFAULT NULL COMMENT '问题分类字典ID' AFTER `handle_status_name`");
    echo "added handle_record.category_id\n";
    $changes++;
} else {
    echo "handle_record.category_id exists\n";
}
if (!colExists($pdo, 'handle_record', 'category_name')) {
    $pdo->exec("ALTER TABLE `handle_record` ADD COLUMN `category_name` VARCHAR(60) DEFAULT NULL COMMENT '问题分类名称' AFTER `category_id`");
    echo "added handle_record.category_name\n";
    $changes++;
} else {
    echo "handle_record.category_name exists\n";
}

// 索引（便于按分类筛选）
if (!idxExists($pdo, 'issue', 'idx_issue_category')) {
    $pdo->exec('ALTER TABLE `issue` ADD KEY `idx_issue_category` (`category_id`)');
    echo "added index idx_issue_category\n";
    $changes++;
} else {
    echo "index idx_issue_category exists\n";
}
if (!idxExists($pdo, 'handle_record', 'idx_hr_category')) {
    $pdo->exec('ALTER TABLE `handle_record` ADD KEY `idx_hr_category` (`category_id`)');
    echo "added index idx_hr_category\n";
    $changes++;
} else {
    echo "index idx_hr_category exists\n";
}

// 预置演示分类（仅当该类型下还没有任何项时写入，避免覆盖管理员已有维护）
$catCount = (int) $pdo->query("SELECT COUNT(*) FROM `dict_item` WHERE `type` = 'category'")->fetchColumn();
if ($catCount === 0) {
    $seeds = ['产品缺陷', '使用咨询', '功能建议', '服务态度', '交付问题', '其他'];
    $st = $pdo->prepare('INSERT INTO `dict_item` (`type`,`name`,`sort`,`status`,`created_at`) VALUES (\'category\', :n, :s, 1, :t)');
    $s = 0;
    foreach ($seeds as $name) {
        $s++;
        $st->execute([':n' => $name, ':s' => $s, ':t' => date('Y-m-d H:i:s')]);
    }
    echo "seeded " . count($seeds) . " category dict items\n";
    $changes++;
} else {
    echo "category dict has {$catCount} items, skip seeding\n";
}

echo "\n字段/索引变更数：" . $changes . PHP_EOL;
