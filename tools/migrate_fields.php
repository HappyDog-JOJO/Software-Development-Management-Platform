<?php
declare(strict_types=1);

/**
 * 开发期脚本：补齐缺失的表字段（幂等，可重复执行）。
 *
 * 背景：user.must_change_password 在多角色重构时被误删，
 * 导致「首次登录强制改密」失效、注册/重置密码写入失败。
 * 本脚本用于在已有数据库上补回该字段（新建库请直接用 database/init.sql）。
 *
 * 用法：php -d extension=pdo_mysql tools/migrate_fields.php
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

$changes = 0;

// user.must_change_password
if (!colExists($pdo, 'user', 'must_change_password')) {
    $pdo->exec("ALTER TABLE `user` ADD COLUMN `must_change_password` TINYINT NOT NULL DEFAULT 0 COMMENT '是否必须改密：1是 0否' AFTER `status`");
    echo "added user.must_change_password\n";
    $changes++;
} else {
    echo "user.must_change_password exists\n";
}

// 默认管理员需首次改密（保持与 init.sql 种子一致）
$pdo->exec("UPDATE `user` SET `must_change_password` = 1 WHERE `account` = '商辂'");
echo "admin must_change_password = 1\n";

// user.last_login_at
if (!colExists($pdo, 'user', 'last_login_at')) {
    $pdo->exec('ALTER TABLE `user` ADD COLUMN `last_login_at` DATETIME DEFAULT NULL COMMENT \'最近登录时间\' AFTER `token_expire_at`');
    echo "added user.last_login_at\n";
    $changes++;
} else {
    echo "user.last_login_at exists\n";
}

// user.deleted_at
if (!colExists($pdo, 'user', 'deleted_at')) {
    $pdo->exec('ALTER TABLE `user` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL COMMENT \'逻辑删除时间\' AFTER `last_login_at`');
    echo "added user.deleted_at\n";
    $changes++;
} else {
    echo "user.deleted_at exists\n";
}

// duty_schedule 表
$exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'duty_schedule'")->fetchColumn();
if ($exists === 0) {
    $pdo->exec("CREATE TABLE `duty_schedule` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `duty_date` DATE NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `user_name` VARCHAR(60) NOT NULL,
  `remark` VARCHAR(200) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_duty_date` (`duty_date`),
  KEY `idx_duty_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='售后值班排班'");
    echo "created duty_schedule\n";
    $changes++;
} else {
    echo "duty_schedule exists\n";
}

echo "\n字段/表结构变更数：" . $changes . PHP_EOL;
echo "\n当前 user 表字段：" . PHP_EOL;
foreach ($pdo->query('SHOW COLUMNS FROM `user`') as $c) {
    echo '  ' . $c['Field'] . PHP_EOL;
}
