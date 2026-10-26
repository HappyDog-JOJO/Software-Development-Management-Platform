<?php
declare(strict_types=1);

/**
 * 开发期脚本：补齐「销售分析（输单）」所需的表结构与字典种子（幂等）。
 *
 * 与 tools/migrate_bugduty.php 同一套用法（线上库通常不可从本地直连，可放站点根由浏览器访问一次）：
 *   /tools/migrate_lostorder.php?token=<MIGRATE_TOKEN>
 * 未配置 MIGRATE_TOKEN 时禁止通过浏览器执行；执行完成后请用 FTP 删除该文件。
 *
 * 用法（本地）：
 *   php -d extension=pdo_mysql tools/migrate_lostorder.php
 *
 * 只做两件事：
 *   1) 建表 lost_order（CREATE TABLE IF NOT EXISTS，已存在则跳过）；
 *   2) 灌入 5 类字典的初始项（dict_item 有 uk_dict_type_name，重复执行只更新排序）。
 * 不动任何现有表、不写入业务数据（演示输单只在 database/init.sql 里）。
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

/** 表是否存在（用 information_schema，避免依赖 SHOW TABLES 的输出格式） */
$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
$st->execute([':t' => 'lost_order']);
$exists = (int) $st->fetchColumn() > 0;

$pdo->exec("CREATE TABLE IF NOT EXISTS `lost_order` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `lost_no` VARCHAR(32) NOT NULL COMMENT '输单编号（SD+时间戳）',
  `customer_name` VARCHAR(120) NOT NULL COMMENT '客户名称',
  `lost_at` DATETIME NOT NULL COMMENT '丢单时间',
  `loss_type_id` INT UNSIGNED DEFAULT NULL COMMENT '输单类型字典ID',
  `loss_type_name` VARCHAR(60) DEFAULT NULL COMMENT '输单类型名称（快照）',
  `industry_id` INT UNSIGNED DEFAULT NULL COMMENT '所属行业字典ID',
  `industry_name` VARCHAR(60) DEFAULT NULL COMMENT '所属行业名称（快照）',
  `company_scale_id` INT UNSIGNED DEFAULT NULL COMMENT '企业规模字典ID',
  `company_scale_name` VARCHAR(60) DEFAULT NULL COMMENT '企业规模名称（快照）',
  `loss_stage_id` INT UNSIGNED DEFAULT NULL COMMENT '丢单阶段字典ID',
  `loss_stage_name` VARCHAR(60) DEFAULT NULL COMMENT '丢单阶段名称（快照）',
  `budget_match_id` INT UNSIGNED DEFAULT NULL COMMENT '预算匹配字典ID',
  `budget_match_name` VARCHAR(60) DEFAULT NULL COMMENT '预算匹配名称（快照）',
  `remote_demo` TINYINT NOT NULL DEFAULT 0 COMMENT '是否远程演示：1是 0否',
  `customized` TINYINT NOT NULL DEFAULT 0 COMMENT '是否涉及定制化：1是 0否',
  `final_quote` DECIMAL(12,2) DEFAULT NULL COMMENT '最终报价（元）',
  `customer_choice` VARCHAR(500) DEFAULT NULL COMMENT '客户最终选择',
  `reason` TEXT NOT NULL COMMENT '输单原因描述',
  `salesman_id` INT UNSIGNED NOT NULL COMMENT '业务员ID（登记人）',
  `salesman_account` VARCHAR(64) NOT NULL COMMENT '业务员账号',
  `salesman_name` VARCHAR(60) NOT NULL COMMENT '业务员姓名',
  `department_id` INT UNSIGNED DEFAULT NULL COMMENT '所属部门ID（登记时快照）',
  `department_name` VARCHAR(60) DEFAULT NULL COMMENT '所属部门名称（登记时快照）',
  `created_at` DATETIME NOT NULL COMMENT '提交时间',
  `updated_at` DATETIME DEFAULT NULL COMMENT '最近更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lost_no` (`lost_no`),
  KEY `idx_lost_at` (`lost_at`),
  KEY `idx_lost_salesman` (`salesman_id`),
  KEY `idx_lost_type` (`loss_type_id`),
  KEY `idx_lost_stage` (`loss_stage_id`),
  KEY `idx_lost_dept` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='输单记录（销售分析）'");
$log[] = $exists ? 'lost_order 已存在（结构未改动）' : 'lost_order 已创建';

/** 字典种子：与 database/init.sql 保持一致 */
$dictSeeds = [
    'loss_type' => ['竞争对手抢单', '客户预算取消', '项目延期', '客户自研', '价格问题', '需求不匹配', '功能缺失', '信任不足', '其他'],
    'industry' => ['机加工', '制造业', '金融', '互联网', '零售', '医疗健康', '教育', '政府与公共事业', '能源', '物流', '其他'],
    'company_scale' => ['大型企业100人以上', '中型企业50-100人', '小型企业10-50人', '初创公司10人以下'],
    'loss_stage' => ['线索', '需求确认', '方案', '演示与试用', '报价', '商务谈判', '合同'],
    'budget_match' => ['符合预算', '报价超预算', '预算未明确'],
];
$ins = $pdo->prepare('INSERT INTO `dict_item` (`type`,`name`,`sort`,`status`,`created_at`) VALUES (:t,:n,:s,1,:c)
    ON DUPLICATE KEY UPDATE `sort`=VALUES(`sort`)');
$now = date('Y-m-d H:i:s');
$added = 0;
foreach ($dictSeeds as $type => $names) {
    foreach ($names as $i => $name) {
        $ins->execute([':t' => $type, ':n' => $name, ':s' => $i + 1, ':c' => $now]);
        $added += $ins->rowCount() > 0 ? 1 : 0;
    }
}
$log[] = 'dict_item 字典种子已就绪（5 类，重复执行只更新排序）';

if ($isCli) {
    echo implode("\n", $log), "\n";
    exit(0);
}
echo json_encode(['code' => 0, 'data' => $log, 'message' => 'ok'], JSON_UNESCAPED_UNICODE);
