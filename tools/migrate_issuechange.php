<?php
declare(strict_types=1);

/**
 * 开发期脚本：补齐「问题字段变更历史」表（幂等）。
 * 用法（本地）：php -d extension=pdo_mysql tools/migrate_issuechange.php
 * 用法（站点根，浏览器访问一次）：/tools/migrate_issuechange.php?token=<MIGRATE_TOKEN>
 * 执行完成后请用 FTP 删除该文件。
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
$st->execute([':t' => 'issue_change']);
$exists = (int) $st->fetchColumn() > 0;

$pdo->exec("CREATE TABLE IF NOT EXISTS `issue_change` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `issue_id` INT UNSIGNED NOT NULL COMMENT '问题ID',
  `issue_no` VARCHAR(32) NOT NULL COMMENT '问题编号（冗余）',
  `user_id` INT UNSIGNED NOT NULL COMMENT '操作人ID',
  `account` VARCHAR(64) NOT NULL COMMENT '操作人账号',
  `user_name` VARCHAR(60) NOT NULL COMMENT '操作人姓名',
  `role` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '操作人角色（快照）',
  `fields` VARCHAR(255) NOT NULL COMMENT '变更字段中文名',
  `changes_json` TEXT NOT NULL COMMENT '变更明细 JSON',
  `created_at` DATETIME NOT NULL COMMENT '变更时间',
  PRIMARY KEY (`id`),
  KEY `idx_ic_issue` (`issue_id`),
  KEY `idx_ic_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='问题字段变更历史（改前/改后）'");

$log = [$exists ? 'issue_change 已存在（结构未改动）' : 'issue_change 已创建'];
if ($isCli) { echo implode("\n", $log), "\n"; exit(0); }
echo json_encode(['code' => 0, 'data' => $log, 'message' => 'ok'], JSON_UNESCAPED_UNICODE);
