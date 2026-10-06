<?php
declare(strict_types=1);

/**
 * 开发期脚本：生成 database/init.sql（表结构 + 演示种子数据）。
 * 不进入交付包（放在 tools/ 下）。
 * 用法：php -d extension=mbstring tools/gen_init_sql.php
 */

$root = dirname(__DIR__);
$outFile = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'init.sql';
if (!is_dir(dirname($outFile))) {
    mkdir(dirname($outFile), 0755, true);
}

mt_srand(20260921);

function h(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

function q($v): string
{
    if ($v === null) {
        return 'NULL';
    }
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $v) . "'";
}

function pick(array $arr)
{
    return $arr[mt_rand(0, count($arr) - 1)];
}

function weighted(array $items, array $weights)
{
    $r = mt_rand(1, 1000) / 1000;
    $acc = 0.0;
    foreach ($items as $i => $item) {
        $acc += $weights[$i];
        if ($r <= $acc) {
            return $item;
        }
    }
    return $items[count($items) - 1];
}

$sql = [];
$sql[] = '-- 售后服务平台 · 数据库初始化脚本（开发期持续维护，打包时以本文件为准）';
$sql[] = '-- 规则：UTF-8；不写 USE；不建库删库；无存储过程/触发器/函数；可重复执行。';
$sql[] = 'SET NAMES utf8mb4;';
$sql[] = '';
$sql[] = 'START TRANSACTION;';
$sql[] = '';

/* ---------------- 表结构 ---------------- */
$ddl = [];
$ddl['department'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `department` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(60) NOT NULL COMMENT '部门名称',
  `leader` VARCHAR(60) DEFAULT NULL COMMENT '负责人',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1启用 0停用',
  `created_at` DATETIME NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dept_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='部门';
SQL;

$ddl['user'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `user` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `account` VARCHAR(64) NOT NULL COMMENT '账号',
  `password_hash` VARCHAR(255) NOT NULL COMMENT '密码哈希',
  `name` VARCHAR(60) DEFAULT NULL COMMENT '姓名',
  `phone` VARCHAR(20) DEFAULT NULL COMMENT '联系电话',
  `avatar` VARCHAR(255) DEFAULT NULL COMMENT '头像地址',
  `department_id` INT UNSIGNED DEFAULT NULL COMMENT '所属部门',
  `roles` VARCHAR(120) NOT NULL DEFAULT '销售员' COMMENT '角色（可多个，逗号分隔）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1启用 0禁用',
  `must_change_password` TINYINT NOT NULL DEFAULT 0 COMMENT '是否必须改密：1是 0否',
  `token` VARCHAR(80) DEFAULT NULL COMMENT '登录令牌',
  `token_expire_at` DATETIME DEFAULT NULL COMMENT '令牌过期时间',
  `last_login_at` DATETIME DEFAULT NULL COMMENT '最近登录时间',
  `deleted_at` DATETIME DEFAULT NULL COMMENT '逻辑删除时间',
  `created_at` DATETIME NOT NULL COMMENT '注册时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_account` (`account`),
  KEY `idx_user_dept` (`department_id`),
  KEY `idx_user_roles` (`roles`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='用户';
SQL;

$ddl['user_token'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `user_token` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `token` VARCHAR(80) NOT NULL COMMENT '登录令牌',
  `expire_at` DATETIME NOT NULL COMMENT '过期时间',
  `created_at` DATETIME NOT NULL COMMENT '签发时间',
  `last_used_at` DATETIME NOT NULL COMMENT '最近使用时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_ut_user` (`user_id`),
  KEY `idx_ut_expire` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='登录会话令牌（支持多端同时在线）';
SQL;

$ddl['notice'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `notice` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '公告标题（可空）',
  `content` TEXT NOT NULL COMMENT '公告内容',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态：1启用 0停用',
  `publisher_id` INT UNSIGNED NOT NULL COMMENT '发布人ID',
  `publisher_name` VARCHAR(60) NOT NULL COMMENT '发布人姓名',
  `created_at` DATETIME NOT NULL COMMENT '发布时间',
  `updated_at` DATETIME NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_notice_status` (`status`),
  KEY `idx_notice_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='公告';
SQL;

$ddl['dict_item'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `dict_item` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `type` VARCHAR(20) NOT NULL COMMENT '字典类型：module/value/handle/category',
  `name` VARCHAR(60) NOT NULL COMMENT '字典项名称',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1启用 0停用',
  `created_at` DATETIME NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dict_type_name` (`type`,`name`),
  KEY `idx_dict_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='字典项';
SQL;

$ddl['issue'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `issue` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `issue_no` VARCHAR(32) NOT NULL COMMENT '问题编号',
  `submitter_id` INT UNSIGNED NOT NULL COMMENT '提交人ID',
  `submitter_account` VARCHAR(64) NOT NULL COMMENT '提交人账号',
  `submitter_name` VARCHAR(60) NOT NULL COMMENT '提交人姓名',
  `department_id` INT UNSIGNED DEFAULT NULL COMMENT '提交时所属部门',
  `module_id` INT UNSIGNED DEFAULT NULL COMMENT '所属模块',
  `category_id` INT UNSIGNED DEFAULT NULL COMMENT '最新问题分类',
  `category_name` VARCHAR(60) DEFAULT NULL COMMENT '最新问题分类名称',
  `description` TEXT NOT NULL COMMENT '问题描述',
  `status` VARCHAR(20) NOT NULL DEFAULT '待处理' COMMENT '状态：待处理/处理中/已处理/已驳回',
  `training_status` TINYINT NOT NULL DEFAULT 0 COMMENT '是否已培训：1是 0否（仅用于值得培训学习的问题）',
  `value_id` INT UNSIGNED DEFAULT NULL COMMENT '最新问题价值',
  `value_name` VARCHAR(60) DEFAULT NULL COMMENT '最新问题价值名称',
  `handler_id` INT UNSIGNED DEFAULT NULL COMMENT '最新处理人ID',
  `handler_name` VARCHAR(60) DEFAULT NULL COMMENT '最新处理人',
  `handled_at` DATETIME DEFAULT NULL COMMENT '最新处理时间',
  `created_at` DATETIME NOT NULL COMMENT '提交时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_issue_no` (`issue_no`),
  KEY `idx_issue_submitter` (`submitter_id`),
  KEY `idx_issue_dept` (`department_id`),
  KEY `idx_issue_status` (`status`),
  KEY `idx_issue_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='问题';
SQL;

$ddl['issue_attachment'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `issue_attachment` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `issue_id` INT UNSIGNED NOT NULL COMMENT '问题ID',
  `url` VARCHAR(255) NOT NULL COMMENT '文件地址',
  `name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '文件名',
  `size` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小(字节)',
  `mime_type` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '文件类型',
  `uploader_id` INT UNSIGNED DEFAULT NULL COMMENT '上传人',
  `created_at` DATETIME NOT NULL COMMENT '上传时间',
  PRIMARY KEY (`id`),
  KEY `idx_ia_issue` (`issue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='问题附件';
SQL;

$ddl['issue_append'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `issue_append` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `issue_id` INT UNSIGNED NOT NULL COMMENT '问题ID',
  `content` TEXT NOT NULL COMMENT '补充说明内容',
  `author_id` INT UNSIGNED NOT NULL COMMENT '补充人ID',
  `author_name` VARCHAR(60) NOT NULL COMMENT '补充人',
  `author_role` VARCHAR(20) NOT NULL COMMENT '补充人角色',
  `created_at` DATETIME NOT NULL COMMENT '补充时间',
  PRIMARY KEY (`id`),
  KEY `idx_iap_issue` (`issue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='问题补充说明';
SQL;

$ddl['handle_record'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `handle_record` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `issue_id` INT UNSIGNED NOT NULL COMMENT '问题ID',
  `handle_status_id` INT UNSIGNED NOT NULL COMMENT '处理情况字典ID',
  `handle_status_name` VARCHAR(60) NOT NULL COMMENT '处理情况名称',
  `category_id` INT UNSIGNED DEFAULT NULL COMMENT '问题分类字典ID',
  `category_name` VARCHAR(60) DEFAULT NULL COMMENT '问题分类名称',
  `remark` TEXT NOT NULL COMMENT '处理备注',
  `value_id` INT UNSIGNED NOT NULL COMMENT '问题价值字典ID',
  `value_name` VARCHAR(60) NOT NULL COMMENT '问题价值名称',
  `handler_id` INT UNSIGNED NOT NULL COMMENT '处理人ID',
  `handler_name` VARCHAR(60) NOT NULL COMMENT '处理人',
  `handler_role` VARCHAR(20) NOT NULL COMMENT '处理人角色',
  `created_at` DATETIME NOT NULL COMMENT '处理时间',
  PRIMARY KEY (`id`),
  KEY `idx_hr_issue` (`issue_id`),
  KEY `idx_hr_handler` (`handler_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='售后处理记录';
SQL;

$ddl['handle_attachment'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `handle_attachment` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `record_id` INT UNSIGNED NOT NULL COMMENT '处理记录ID',
  `issue_id` INT UNSIGNED NOT NULL COMMENT '问题ID',
  `url` VARCHAR(255) NOT NULL COMMENT '文件地址',
  `name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '文件名',
  `size` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小(字节)',
  `mime_type` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '文件类型',
  `created_at` DATETIME NOT NULL COMMENT '上传时间',
  PRIMARY KEY (`id`),
  KEY `idx_ha_record` (`record_id`),
  KEY `idx_ha_issue` (`issue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='处理附件';
SQL;

$ddl['duty_schedule'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `duty_schedule` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `duty_date` DATE NOT NULL COMMENT '值班日期（一天一个值班人）',
  `user_id` INT UNSIGNED NOT NULL COMMENT '值班人ID（仅售后服务人员角色）',
  `user_name` VARCHAR(60) NOT NULL COMMENT '值班人姓名（冗余，便于展示）',
  `remark` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '备注（如休假、换班说明）',
  `created_at` DATETIME NOT NULL COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_duty_date` (`duty_date`),
  KEY `idx_duty_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='售后值班排班';
SQL;

$ddl['operation_log'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `operation_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `account` VARCHAR(64) NOT NULL COMMENT '操作人账号',
  `role` VARCHAR(20) NOT NULL COMMENT '操作人角色',
  `type` VARCHAR(20) NOT NULL COMMENT '操作类型',
  `target` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '操作对象',
  `content` TEXT COMMENT '操作内容',
  `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT 'IP',
  `created_at` DATETIME NOT NULL COMMENT '操作时间',
  PRIMARY KEY (`id`),
  KEY `idx_log_type` (`type`),
  KEY `idx_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='操作日志';
SQL;

$ddl['notification'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `notification` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` INT UNSIGNED NOT NULL COMMENT '接收人ID',
  `title` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '通知标题',
  `content` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '通知正文',
  `link` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '点击跳转的前端路由',
  `is_read` TINYINT NOT NULL DEFAULT 0 COMMENT '是否已读：1是 0否',
  `notified` TINYINT NOT NULL DEFAULT 0 COMMENT '是否已弹过系统通知：1是 0否',
  `created_at` DATETIME NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_notify_user` (`user_id`),
  KEY `idx_notify_pending` (`user_id`,`notified`),
  KEY `idx_notify_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='站内消息通知';
SQL;

$ddl['site_config'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `site_config` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `config_key` VARCHAR(64) NOT NULL COMMENT '配置键',
  `config_value` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '配置值',
  `updated_by` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '最后修改人',
  `updated_at` DATETIME NOT NULL COMMENT '最后修改时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='站点级配置';
SQL;

$ddl['dev_request'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `dev_request` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `req_no` VARCHAR(32) NOT NULL COMMENT '需求编号（XQ+时间戳）',
  `title` VARCHAR(100) NOT NULL COMMENT '需求标题',
  `description` TEXT NOT NULL COMMENT '需求描述',
  `module_id` INT UNSIGNED DEFAULT NULL COMMENT '所属模块字典ID',
  `module_name` VARCHAR(60) DEFAULT NULL COMMENT '所属模块名称（提交时快照）',
  `priority` VARCHAR(10) NOT NULL DEFAULT '中' COMMENT '优先级：紧急/高/中/低',
  `status` VARCHAR(20) NOT NULL DEFAULT '评审池' COMMENT '状态：评审池/需求池/设计中心/开发中心/待发布/已上线/已驳回/已取消',
  `submitter_id` INT UNSIGNED NOT NULL COMMENT '提交人ID',
  `submitter_account` VARCHAR(64) NOT NULL COMMENT '提交人账号',
  `submitter_name` VARCHAR(60) NOT NULL COMMENT '提交人姓名',
  `department_id` INT UNSIGNED DEFAULT NULL COMMENT '提交时所属部门',
  `reviewer_id` INT UNSIGNED DEFAULT NULL COMMENT '评审人ID（最后一次）',
  `reviewer_name` VARCHAR(60) DEFAULT NULL COMMENT '评审人姓名',
  `review_comment` VARCHAR(500) DEFAULT NULL COMMENT '评审意见',
  `reviewed_at` DATETIME DEFAULT NULL COMMENT '最近评审时间',
  `pm_id` INT UNSIGNED DEFAULT NULL COMMENT '认领的产品经理ID',
  `pm_name` VARCHAR(60) DEFAULT NULL COMMENT '产品经理姓名',
  `design_doc` TEXT COMMENT '设计说明',
  `branch` VARCHAR(100) DEFAULT NULL COMMENT '开发分支',
  `dev_leader_id` INT UNSIGNED DEFAULT NULL COMMENT '开发负责人ID',
  `dev_leader_name` VARCHAR(60) DEFAULT NULL COMMENT '开发负责人姓名',
  `designed_at` DATETIME DEFAULT NULL COMMENT '设计完成时间（进入开发中心）',
  `dev_done_note` VARCHAR(1000) DEFAULT NULL COMMENT '开发完成说明（提交开发完成时填写）',
  `dev_done_at` DATETIME DEFAULT NULL COMMENT '开发完成时间（提交开发完成）',
  `inspector_id` INT UNSIGNED DEFAULT NULL COMMENT '废弃（质量中心已下线，仅保留历史数据）',
  `inspector_name` VARCHAR(60) DEFAULT NULL COMMENT '废弃（质量中心已下线，仅保留历史数据）',
  `inspect_result` VARCHAR(10) DEFAULT NULL COMMENT '废弃（质量中心已下线，仅保留历史数据）',
  `inspect_comment` VARCHAR(500) DEFAULT NULL COMMENT '废弃（质量中心已下线，仅保留历史数据）',
  `inspected_at` DATETIME DEFAULT NULL COMMENT '废弃（质量中心已下线，仅保留历史数据）',
  `reject_count` INT NOT NULL DEFAULT 0 COMMENT '废弃（质量中心已下线，仅保留历史数据）',
  `released_at` DATETIME DEFAULT NULL COMMENT '发布上线时间',
  `released_by` VARCHAR(60) DEFAULT NULL COMMENT '发布操作人',
  `pool_at` DATETIME DEFAULT NULL COMMENT '进入需求池时间（评审通过）',
  `design_at` DATETIME DEFAULT NULL COMMENT '进入设计中心时间（PM认领）',
  `battle_at` DATETIME DEFAULT NULL COMMENT '进入开发中心时间',
  `inspect_at` DATETIME DEFAULT NULL COMMENT '废弃（质量中心已下线，仅保留历史数据）',
  `frontline_at` DATETIME DEFAULT NULL COMMENT '进入待发布时间',
  `created_at` DATETIME NOT NULL COMMENT '提交时间（进入评审池）',
  `updated_at` DATETIME DEFAULT NULL COMMENT '最近更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_req_no` (`req_no`),
  KEY `idx_devreq_status` (`status`),
  KEY `idx_devreq_submitter` (`submitter_id`),
  KEY `idx_devreq_pm` (`pm_id`),
  KEY `idx_devreq_leader` (`dev_leader_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='需求主任务';
SQL;

$ddl['dev_request_attachment'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `dev_request_attachment` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `request_id` INT UNSIGNED NOT NULL COMMENT '需求ID',
  `scene` VARCHAR(20) NOT NULL DEFAULT 'submit' COMMENT '场景：submit提交/design设计/reject驳回/done完成/release上线',
  `url` VARCHAR(255) NOT NULL COMMENT '文件地址',
  `name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '文件名',
  `size` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小(字节)',
  `mime_type` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '文件类型',
  `uploader_id` INT UNSIGNED DEFAULT NULL COMMENT '上传人',
  `created_at` DATETIME NOT NULL COMMENT '上传时间',
  PRIMARY KEY (`id`),
  KEY `idx_da_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='需求附件';
SQL;

foreach (['department', 'user', 'user_token', 'dict_item', 'issue', 'issue_attachment', 'issue_append', 'handle_record', 'handle_attachment', 'duty_schedule', 'notice', 'operation_log', 'notification', 'site_config', 'dev_request', 'dev_request_attachment'] as $t) {
    $sql[] = '-- 表：' . $t;
    $sql[] = $ddl[$t];
    $sql[] = '';
}

/* ---------------- 初始数据：仅管理员账号 ----------------
 * 说明：本应用交付时为空库（不预置业务数据与演示数据），
 * 仅保留唯一管理员账号用于首次登录，部门/字典等由管理员登录后自行维护。
 * 账号：商辂 / 1994（首次登录后请修改密码）
 */
$sql[] = '-- 初始账号：仅保留管理员（账号 商辂 / 密码 1994，登录后请及时修改）';
$sql[] = 'INSERT INTO `user` (`id`,`account`,`password_hash`,`name`,`phone`,`avatar`,`department_id`,`roles`,`status`,`must_change_password`,`created_at`) VALUES (1,'
    . q('商辂') . ',' . q(h('1994')) . ',' . q('商辂') . ',' . q('13375666190') . ',NULL,NULL,' . q('管理员') . ',1,0, '
    . q('2026-06-01 09:30:00') . ')'
    . ' ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`phone`=VALUES(`phone`),`roles`=VALUES(`roles`),'
    . '`status`=VALUES(`status`),`must_change_password`=VALUES(`must_change_password`);';

/* ---------------- 初始数据：需求管理模块演示账号与样例需求 ---------------- */
$demoPass = h('123456');
$demoUsers = [
    [901, 'committee01', '评审委员甲', '评审委员'],
    [902, 'pm01', '产品小王', '产品经理'],
    [903, 'dev01', '开发老张', '开发工程师'],
    [904, 'dev02', '开发小李', '开发工程师'],
    [905, 'inspector01', '质量老刘', '质量审核员'],
];
$sql[] = '';
$sql[] = '-- 需求管理模块演示账号（密码均 123456；方案见 docs/需求管理模块-需求方案.md）';
$sql[] = 'INSERT INTO `user` (`id`,`account`,`password_hash`,`name`,`phone`,`department_id`,`roles`,`status`,`must_change_password`,`created_at`) VALUES '
    . implode(',', array_map(fn(array $u): string => '(' . $u[0] . ',' . q($u[1]) . ',' . q($demoPass) . ',' . q($u[2]) . ',NULL,NULL,' . q($u[3]) . ',1,0,' . q('2026-10-06 09:00:00') . ')', $demoUsers))
    . ' ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`roles`=VALUES(`roles`),`status`=VALUES(`status`),`must_change_password`=VALUES(`must_change_password`);';
$sql[] = '';
$sql[] = '-- 需求管理模块样例需求（各环节一条；module_id 留空，仅写模块名称快照，避免依赖字典种子）';
$sql[] = 'INSERT INTO `dev_request` (`id`,`req_no`,`title`,`description`,`module_id`,`module_name`,`priority`,`status`,`submitter_id`,`submitter_account`,`submitter_name`,`reviewer_id`,`reviewer_name`,`review_comment`,`reviewed_at`,`pm_id`,`pm_name`,`design_doc`,`branch`,`dev_leader_id`,`dev_leader_name`,`designed_at`,`dev_done_note`,`dev_done_at`,`inspector_id`,`inspector_name`,`inspect_result`,`inspect_comment`,`inspected_at`,`reject_count`,`released_at`,`released_by`,`pool_at`,`design_at`,`battle_at`,`inspect_at`,`frontline_at`,`created_at`,`updated_at`) VALUES'
    . ' (9001,\'XQDEMO01\',\'客户对账单导出增加筛选条件\',\'销售导出对账单时希望按时间范围与客户名称筛选，减少手工整理工作量。\',NULL,\'综合\',\'紧急\',\'评审池\',1,\'商辂\',\'商辂\',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,\'2026-10-06 09:10:00\',NULL),'
    . ' (9002,\'XQDEMO02\',\'售后报表支持按部门导出\',\'管理员希望把售后报表按部门拆分导出，方便月度经营分析会直接使用。\',NULL,\'报表\',\'高\',\'需求池\',1,\'商辂\',\'商辂\',901,\'评审委员甲\',\'需求明确，价值清晰，通过。\',\'2026-10-06 09:20:00\',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,\'2026-10-06 09:20:00\',NULL,NULL,NULL,NULL,\'2026-10-06 09:15:00\',\'2026-10-06 09:20:00\'),'
    . ' (9003,\'XQDEMO03\',\'值班排班支持批量周轮值\',\'售后排班目前逐日点击，希望支持按周批量轮值并跳过周末，减轻管理员负担。\',NULL,\'排班\',\'高\',\'开发中心\',1,\'商辂\',\'商辂\',901,\'评审委员甲\',\'演示样例：通过。\',\'2026-10-06 09:30:00\',902,\'产品小王\',\'排班批量生成采用周为单位循环轮值，前端日历直接标出预览，确认后一次性写入；接口复用 duty/batch。\',\'feature/batch-duty\',903,\'开发老张\',\'2026-10-06 10:00:00\',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,\'2026-10-06 09:30:00\',\'2026-10-06 09:40:00\',\'2026-10-06 10:00:00\',NULL,NULL,\'2026-10-06 09:25:00\',\'2026-10-06 10:00:00\'),'
    . ' (9004,\'XQDEMO04\',\'登录页品牌视觉升级\',\'登录页沿用旧品牌名，需要统一升级平台视觉与文案。\',NULL,\'综合\',\'中\',\'待发布\',1,\'商辂\',\'商辂\',901,\'评审委员甲\',\'演示样例：通过。\',\'2026-10-06 10:10:00\',902,\'产品小王\',\'沿用夜色星空插画，仅替换品牌文案与副标题。\',\'feature/brand-2026q4\',904,\'开发小李\',\'2026-10-06 10:30:00\',\'品牌文案与副标题替换完成，登录页两处入口已回归验证。\',\'2026-10-06 11:00:00\',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,\'2026-10-06 10:10:00\',\'2026-10-06 10:20:00\',\'2026-10-06 10:30:00\',NULL,\'2026-10-06 11:00:00\',\'2026-10-06 10:05:00\',\'2026-10-06 11:00:00\'),'
    . ' (9005,\'XQDEMO05\',\'消息通知支持点击跳转\',\'站内消息点击后直达对应页面，减少查找步骤。\',NULL,\'综合\',\'中\',\'已上线\',1,\'商辂\',\'商辂\',901,\'评审委员甲\',\'演示样例：通过。\',\'2026-10-06 08:10:00\',902,\'产品小王\',\'消息体携带前端路由，轮询弹窗与列表点击均跳转。\',\'feature/notify-link\',903,\'开发老张\',\'2026-10-06 08:30:00\',\'消息跳转逻辑完成，站内信列表与弹窗点击均回归通过。\',\'2026-10-06 08:55:00\',NULL,NULL,NULL,NULL,NULL,0,\'2026-10-06 09:00:00\',\'商辂\',\'2026-10-06 08:10:00\',\'2026-10-06 08:20:00\',\'2026-10-06 08:30:00\',NULL,\'2026-10-06 08:55:00\',\'2026-10-06 08:05:00\',\'2026-10-06 09:00:00\')'
    . ' ON DUPLICATE KEY UPDATE `title`=VALUES(`title`),`description`=VALUES(`description`),`priority`=VALUES(`priority`),`status`=VALUES(`status`),`updated_at`=VALUES(`updated_at`);';
$sql[] = '';

$sql[] = '';
$sql[] = 'COMMIT;';
$sql[] = '';

$content = implode("\n", $sql);
file_put_contents($outFile, $content);
echo 'written: ' . $outFile . PHP_EOL;
echo 'bytes: ' . strlen($content) . PHP_EOL;
echo 'users=6（管理员 + 需求演示账号 x5）' . PHP_EOL;
