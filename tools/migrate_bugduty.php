<?php
declare(strict_types=1);

/**
 * 开发期脚本：补齐「BUG 修复排班」与「问题同步 PingCode」所需的表结构（幂等）。
 *
 * 线上库通常不可从本地直连，本脚本放在站点根由浏览器访问一次即可完成建表/补字段：
 *   /tools/migrate_bugduty.php?token=<MIGRATE_TOKEN>
 * 未配置 MIGRATE_TOKEN 时禁止执行；执行完成后请用 FTP 删除该文件。
 *
 * 用法（本地）：
 *   php -d extension=pdo_mysql tools/migrate_bugduty.php
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

$log = [];

function hasCol(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
    $st->execute([':t' => $table, ':c' => $col]);
    return (int) $st->fetchColumn() > 0;
}

/** issue 表：PingCode 同步字段 */
$issueCols = [
    'pingcode_task_id' => "VARCHAR(64) DEFAULT NULL COMMENT 'PingCode 工作项ID（同步后）'",
    'pingcode_task_no' => "VARCHAR(64) DEFAULT NULL COMMENT 'PingCode 工作项编号（如 KG-123）'",
    'pingcode_task_url' => "VARCHAR(500) DEFAULT NULL COMMENT 'PingCode 工作项链接'",
    'pingcode_synced_at' => "DATETIME DEFAULT NULL COMMENT '最近同步到 PingCode 时间'",
    'pingcode_assignee_id' => "VARCHAR(64) DEFAULT NULL COMMENT 'PingCode 处理人成员ID'",
    'pingcode_assignee_name' => "VARCHAR(60) DEFAULT NULL COMMENT 'PingCode 处理人姓名'",
];
foreach ($issueCols as $col => $def) {
    if (hasCol($pdo, 'issue', $col)) {
        $log[] = 'issue.' . $col . ' 已存在';
        continue;
    }
    $pdo->exec('ALTER TABLE `issue` ADD COLUMN `' . $col . '` ' . $def);
    $log[] = 'issue.' . $col . ' 已添加';
}

/** BUG 修复排班顺序表 */
$pdo->exec("CREATE TABLE IF NOT EXISTS `bug_duty_order` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` INT UNSIGNED NOT NULL COMMENT '人员ID',
  `user_name` VARCHAR(60) NOT NULL COMMENT '姓名（冗余）',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '顺位，从1开始',
  `pingcode_member_id` VARCHAR(64) DEFAULT NULL COMMENT '对应 PingCode 项目成员ID（可空）',
  `created_at` DATETIME NOT NULL COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bug_duty_user` (`user_id`),
  KEY `idx_bug_duty_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='BUG修复排班顺序（按顺位依次轮）'");
$log[] = 'bug_duty_order 已就绪';

if ($isCli) {
    echo implode("\n", $log), "\n";
    exit(0);
}
echo json_encode(['code' => 0, 'data' => $log, 'message' => 'ok'], JSON_UNESCAPED_UNICODE);
