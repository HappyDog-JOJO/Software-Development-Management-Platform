<?php
declare(strict_types=1);

/**
 * 开发期脚本：补齐「需求流转备注」表（幂等）。
 *
 * 用法（本地）：php -d extension=pdo_mysql tools/migrate_devcomment.php
 * 用法（站点根，浏览器访问一次）：/tools/migrate_devcomment.php?token=<MIGRATE_TOKEN>
 * 执行完成后请用 FTP 删除该文件。
 *
 * 只做一件事：建表 dev_request_comment（CREATE TABLE IF NOT EXISTS），不动任何现有表与数据。
 */

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

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
$st->execute([':t' => 'dev_request_comment']);
$exists = (int) $st->fetchColumn() > 0;

$pdo->exec("CREATE TABLE IF NOT EXISTS `dev_request_comment` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `request_id` INT UNSIGNED NOT NULL COMMENT '需求ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '留言人ID',
  `account` VARCHAR(64) NOT NULL COMMENT '留言人账号',
  `user_name` VARCHAR(60) NOT NULL COMMENT '留言人姓名',
  `role` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '留言人角色（快照，多角色逗号分隔）',
  `content` VARCHAR(1000) NOT NULL COMMENT '备注内容',
  `created_at` DATETIME NOT NULL COMMENT '留言时间',
  PRIMARY KEY (`id`),
  KEY `idx_dc_request` (`request_id`),
  KEY `idx_dc_user` (`user_id`),
  KEY `idx_dc_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='需求流转备注（人工留言）'");

$log = [$exists ? 'dev_request_comment 已存在（结构未改动）' : 'dev_request_comment 已创建'];

if ($isCli) {
    echo implode("\n", $log), "\n";
    exit(0);
}
echo json_encode(['code' => 0, 'data' => $log, 'message' => 'ok'], JSON_UNESCAPED_UNICODE);
