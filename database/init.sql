-- 售后服务平台 · 数据库初始化脚本（开发期持续维护，打包时以本文件为准）
-- 规则：UTF-8；不写 USE；不建库删库；无存储过程/触发器/函数；可重复执行。
SET NAMES utf8mb4;

START TRANSACTION;

-- 表：department
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

-- 表：user
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

-- 表：user_token
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

-- 表：dict_item
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

-- 表：issue
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
  `pingcode_task_id` VARCHAR(64) DEFAULT NULL COMMENT 'PingCode 工作项ID（同步后回写）',
  `pingcode_task_no` VARCHAR(64) DEFAULT NULL COMMENT 'PingCode 工作项编号（如 #123）',
  `pingcode_task_url` VARCHAR(255) DEFAULT NULL COMMENT 'PingCode 工作项链接',
  `pingcode_synced_at` DATETIME DEFAULT NULL COMMENT '最近一次同步到 PingCode 的时间',
  `pingcode_assignee_id` VARCHAR(64) DEFAULT NULL COMMENT '同步时的 PingCode 负责人ID',
  `pingcode_assignee_name` VARCHAR(60) DEFAULT NULL COMMENT '同步时的 BUG 处理人姓名（排班顺位快照）',
  `created_at` DATETIME NOT NULL COMMENT '提交时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_issue_no` (`issue_no`),
  KEY `idx_issue_submitter` (`submitter_id`),
  KEY `idx_issue_dept` (`department_id`),
  KEY `idx_issue_status` (`status`),
  KEY `idx_issue_created` (`created_at`),
  KEY `idx_issue_pingcode` (`pingcode_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='问题';

-- 表：issue_attachment
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

-- 表：issue_append
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

-- 表：handle_record
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

-- 表：handle_attachment
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

-- 表：duty_schedule
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

-- 表：bug_duty_order
CREATE TABLE IF NOT EXISTS `bug_duty_order` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '系统用户ID（历史字段；改成 PingCode 成员排班后固定为 0）',
  `user_name` VARCHAR(60) NOT NULL COMMENT '参与人姓名（取自 PingCode 成员名，冗余展示）',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '顺位（从 1 开始，数值越小越靠前）',
  `pingcode_member_id` VARCHAR(64) DEFAULT NULL COMMENT 'PingCode 成员ID（人员来源与指派依据）',
  `created_at` DATETIME NOT NULL COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bug_duty_member` (`pingcode_member_id`),
  KEY `idx_bug_duty_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='BUG 修复排班顺序（按顺位轮值，不排日期）';

-- 表：notice
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

-- 表：operation_log
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

-- 表：notification
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

-- 表：site_config
CREATE TABLE IF NOT EXISTS `site_config` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `config_key` VARCHAR(64) NOT NULL COMMENT '配置键',
  `config_value` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '配置值',
  `updated_by` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '最后修改人',
  `updated_at` DATETIME NOT NULL COMMENT '最后修改时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='站点级配置';

-- 表：dev_request
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
  `review_pending` TINYINT NOT NULL DEFAULT 0 COMMENT '评审待定标记：0否/1待定（待定暂留评审池，之后仍可通过/驳回）',
  `pm_id` INT UNSIGNED DEFAULT NULL COMMENT '认领的产品经理ID',
  `pm_name` VARCHAR(60) DEFAULT NULL COMMENT '产品经理姓名',
  `design_doc` TEXT COMMENT '设计说明',
  `design_url` VARCHAR(500) DEFAULT NULL COMMENT '设计文档链接（网址）',
  `branch` VARCHAR(100) DEFAULT NULL COMMENT '开发分支',
  `dev_leader_id` INT UNSIGNED DEFAULT NULL COMMENT '负责人ID（开发工程师或管理员）',
  `dev_leader_name` VARCHAR(60) DEFAULT NULL COMMENT '负责人姓名',
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
  `pingcode_task_id` VARCHAR(64) DEFAULT NULL COMMENT 'PingCode 任务ID（同步后）',
  `pingcode_task_no` VARCHAR(64) DEFAULT NULL COMMENT 'PingCode 任务编号（如 KG-123）',
  `pingcode_task_url` VARCHAR(500) DEFAULT NULL COMMENT 'PingCode 任务链接',
  `pingcode_synced_at` DATETIME DEFAULT NULL COMMENT '最近同步到 PingCode 时间',
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

-- 表：dev_request_attachment
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

-- 表：lost_order
CREATE TABLE IF NOT EXISTS `lost_order` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='输单记录（销售分析）';

-- 初始账号：仅保留管理员（账号 商辂 / 密码 1994，登录后请及时修改）
INSERT INTO `user` (`id`,`account`,`password_hash`,`name`,`phone`,`avatar`,`department_id`,`roles`,`status`,`must_change_password`,`created_at`) VALUES (1,'商辂','$2y$12$G1RU.aMf12XTNZonKSefyu.akj9kuL9rSzqtvMOhIMkj5eNSfSmcm','商辂','13375666190',NULL,NULL,'管理员',1,0, '2026-06-01 09:30:00') ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`phone`=VALUES(`phone`),`roles`=VALUES(`roles`),`status`=VALUES(`status`),`must_change_password`=VALUES(`must_change_password`);

-- 需求管理模块演示账号（密码均 123456；方案见 docs/需求管理模块-需求方案.md）
INSERT INTO `user` (`id`,`account`,`password_hash`,`name`,`phone`,`department_id`,`roles`,`status`,`must_change_password`,`created_at`) VALUES (901,'committee01','$2y$12$UFBpa/JJ4byZf8u821DrbeaYtpq7q63zQEABz3aX51s.l6dv8x21W','评审委员甲',NULL,NULL,'评审委员',1,0,'2026-10-06 09:00:00'),(902,'pm01','$2y$12$UFBpa/JJ4byZf8u821DrbeaYtpq7q63zQEABz3aX51s.l6dv8x21W','产品小王',NULL,NULL,'产品经理',1,0,'2026-10-06 09:00:00'),(903,'dev01','$2y$12$UFBpa/JJ4byZf8u821DrbeaYtpq7q63zQEABz3aX51s.l6dv8x21W','开发老张',NULL,NULL,'开发工程师',1,0,'2026-10-06 09:00:00'),(904,'dev02','$2y$12$UFBpa/JJ4byZf8u821DrbeaYtpq7q63zQEABz3aX51s.l6dv8x21W','开发小李',NULL,NULL,'开发工程师',1,0,'2026-10-06 09:00:00') ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`roles`=VALUES(`roles`),`status`=VALUES(`status`),`must_change_password`=VALUES(`must_change_password`);

-- 需求管理模块样例需求（各环节一条；module_id 留空，仅写模块名称快照，避免依赖字典种子）
INSERT INTO `dev_request` (`id`,`req_no`,`title`,`description`,`module_id`,`module_name`,`priority`,`status`,`submitter_id`,`submitter_account`,`submitter_name`,`reviewer_id`,`reviewer_name`,`review_comment`,`reviewed_at`,`pm_id`,`pm_name`,`design_doc`,`branch`,`dev_leader_id`,`dev_leader_name`,`designed_at`,`dev_done_note`,`dev_done_at`,`inspector_id`,`inspector_name`,`inspect_result`,`inspect_comment`,`inspected_at`,`reject_count`,`released_at`,`released_by`,`pingcode_task_id`,`pingcode_task_no`,`pingcode_task_url`,`pingcode_synced_at`,`pool_at`,`design_at`,`battle_at`,`inspect_at`,`frontline_at`,`created_at`,`updated_at`,`design_url`) VALUES (9001,'XQDEMO01','客户对账单导出增加筛选条件','销售导出对账单时希望按时间范围与客户名称筛选，减少手工整理工作量。',NULL,'综合','紧急','评审池',1,'商辂','商辂',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-06 09:10:00',NULL,NULL), (9002,'XQDEMO02','售后报表支持按部门导出','管理员希望把售后报表按部门拆分导出，方便月度经营分析会直接使用。',NULL,'报表','高','需求池',1,'商辂','商辂',901,'评审委员甲','需求明确，价值清晰，通过。','2026-10-06 09:20:00',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-06 09:20:00',NULL,NULL,NULL,NULL,'2026-10-06 09:15:00','2026-10-06 09:20:00',NULL), (9003,'XQDEMO03','值班排班支持批量周轮值','售后排班目前逐日点击，希望支持按周批量轮值并跳过周末，减轻管理员负担。',NULL,'排班','高','开发中心',1,'商辂','商辂',901,'评审委员甲','演示样例：通过。','2026-10-06 09:30:00',902,'产品小王','排班批量生成采用周为单位循环轮值，前端日历直接标出预览，确认后一次性写入；接口复用 duty/batch。','feature/batch-duty',903,'开发老张','2026-10-06 10:00:00',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-06 09:30:00','2026-10-06 09:40:00','2026-10-06 10:00:00',NULL,NULL,'2026-10-06 09:25:00','2026-10-06 10:00:00','https://docs.example.com/prd/batch-duty'), (9004,'XQDEMO04','登录页品牌视觉升级','登录页沿用旧品牌名，需要统一升级平台视觉与文案。',NULL,'综合','中','已上线',1,'商辂','商辂',901,'评审委员甲','演示样例：通过。','2026-10-06 10:10:00',902,'产品小王','沿用夜色星空插画，仅替换品牌文案与副标题。','feature/brand-2026q4',904,'开发小李','2026-10-06 10:30:00','品牌文案与副标题替换完成，登录页两处入口已回归验证。','2026-10-06 11:00:00',NULL,NULL,NULL,NULL,NULL,0,'2026-10-06 11:30:00','商辂',NULL,NULL,NULL,NULL,'2026-10-06 10:10:00','2026-10-06 10:20:00','2026-10-06 10:30:00',NULL,'2026-10-06 11:00:00','2026-10-06 10:05:00','2026-10-06 11:00:00','https://docs.example.com/prd/brand-2026q4'), (9005,'XQDEMO05','消息通知支持点击跳转','站内消息点击后直达对应页面，减少查找步骤。',NULL,'综合','中','已上线',1,'商辂','商辂',901,'评审委员甲','演示样例：通过。','2026-10-06 08:10:00',902,'产品小王','消息体携带前端路由，轮询弹窗与列表点击均跳转。','feature/notify-link',903,'开发老张','2026-10-06 08:30:00','消息跳转逻辑完成，站内信列表与弹窗点击均回归通过。','2026-10-06 08:55:00',NULL,NULL,NULL,NULL,NULL,0,'2026-10-06 09:00:00','商辂',NULL,NULL,NULL,NULL,'2026-10-06 08:10:00','2026-10-06 08:20:00','2026-10-06 08:30:00',NULL,'2026-10-06 08:55:00','2026-10-06 08:05:00','2026-10-06 09:00:00','https://docs.example.com/prd/notify-link') ON DUPLICATE KEY UPDATE `title`=VALUES(`title`),`description`=VALUES(`description`),`priority`=VALUES(`priority`),`status`=VALUES(`status`),`design_url`=VALUES(`design_url`),`updated_at`=VALUES(`updated_at`);


-- 销售分析字典种子（输单类型/所属行业/企业规模/丢单阶段/预算匹配；重复执行只更新排序）
INSERT INTO `dict_item` (`type`,`name`,`sort`,`status`,`created_at`) VALUES ('loss_type','竞争对手抢单',1,1,'2026-10-23 09:00:00'),('loss_type','客户预算取消',2,1,'2026-10-23 09:00:00'),('loss_type','项目延期',3,1,'2026-10-23 09:00:00'),('loss_type','客户自研',4,1,'2026-10-23 09:00:00'),('loss_type','价格问题',5,1,'2026-10-23 09:00:00'),('loss_type','需求不匹配',6,1,'2026-10-23 09:00:00'),('loss_type','功能缺失',7,1,'2026-10-23 09:00:00'),('loss_type','信任不足',8,1,'2026-10-23 09:00:00'),('loss_type','其他',9,1,'2026-10-23 09:00:00'),('industry','机加工',1,1,'2026-10-23 09:00:00'),('industry','制造业',2,1,'2026-10-23 09:00:00'),('industry','金融',3,1,'2026-10-23 09:00:00'),('industry','互联网',4,1,'2026-10-23 09:00:00'),('industry','零售',5,1,'2026-10-23 09:00:00'),('industry','医疗健康',6,1,'2026-10-23 09:00:00'),('industry','教育',7,1,'2026-10-23 09:00:00'),('industry','政府与公共事业',8,1,'2026-10-23 09:00:00'),('industry','能源',9,1,'2026-10-23 09:00:00'),('industry','物流',10,1,'2026-10-23 09:00:00'),('industry','其他',11,1,'2026-10-23 09:00:00'),('company_scale','大型企业100人以上',1,1,'2026-10-23 09:00:00'),('company_scale','中型企业50-100人',2,1,'2026-10-23 09:00:00'),('company_scale','小型企业10-50人',3,1,'2026-10-23 09:00:00'),('company_scale','初创公司10人以下',4,1,'2026-10-23 09:00:00'),('loss_stage','线索',1,1,'2026-10-23 09:00:00'),('loss_stage','需求确认',2,1,'2026-10-23 09:00:00'),('loss_stage','方案',3,1,'2026-10-23 09:00:00'),('loss_stage','演示与试用',4,1,'2026-10-23 09:00:00'),('loss_stage','报价',5,1,'2026-10-23 09:00:00'),('loss_stage','商务谈判',6,1,'2026-10-23 09:00:00'),('loss_stage','合同',7,1,'2026-10-23 09:00:00'),('budget_match','符合预算',1,1,'2026-10-23 09:00:00'),('budget_match','报价超预算',2,1,'2026-10-23 09:00:00'),('budget_match','预算未明确',3,1,'2026-10-23 09:00:00') ON DUPLICATE KEY UPDATE `sort`=VALUES(`sort`);

-- 销售分析演示输单（业务员统一挂管理员账号；字典 ID 由下方回填语句补齐，避免依赖自增 ID）
INSERT INTO `lost_order` (`id`,`lost_no`,`customer_name`,`lost_at`,`loss_type_id`,`loss_type_name`,`industry_id`,`industry_name`,`company_scale_id`,`company_scale_name`,`loss_stage_id`,`loss_stage_name`,`budget_match_id`,`budget_match_name`,`remote_demo`,`customized`,`final_quote`,`customer_choice`,`reason`,`salesman_id`,`salesman_account`,`salesman_name`,`department_id`,`department_name`,`created_at`,`updated_at`) VALUES (9101,'SDDEMO01','江苏西赛铭过滤','2026-10-08 14:51:00',NULL,'其他',NULL,'制造业',NULL,'小型企业10-50人',NULL,'方案',NULL,'符合预算',1,0,'10000.00','无','客户已付款 10000 元，但客户没有独立服务器，现有服务器还有其他系统，我们无法部署，客户认为再买服务器不划算，最终退单。',1,'商辂','商辂',NULL,NULL,'2026-10-08 14:51:00',NULL),(9102,'SDDEMO02','杭州锦程包装','2026-10-09 14:58:00',NULL,'客户预算取消',NULL,'零售',NULL,'小型企业10-50人',NULL,'商务谈判',NULL,'符合预算',1,0,'9800.00','2000 元每年的售后系统','功能试用都能满足，老板觉得买断不划算，更愿意按年付费；给了意向金方案后仍选择竞品。',1,'商辂','商辂',NULL,NULL,'2026-10-09 14:58:00',NULL),(9103,'SDDEMO03','永康市谦益工贸有限公司','2026-10-12 15:04:00',NULL,'价格问题',NULL,'制造业',NULL,'小型企业10-50人',NULL,'报价',NULL,'预算未明确',0,0,'19800.00','不详','晚上咨询联系，初步沟通生产保温杯；告知买断费用 1-2 万后微信介绍无回应，电话沟通称价格太高不考虑。',1,'商辂','商辂',NULL,NULL,'2026-10-12 15:04:00',NULL),(9104,'SDDEMO04','鼎派实业金衣包装有限公司','2026-10-15 15:13:00',NULL,'价格问题',NULL,'制造业',NULL,'小型企业10-50人',NULL,'报价',NULL,'报价超预算',1,0,'8800.00','不详','已远程演示，做了两份报价；客户称预算只有千把块，认为开发成本很低，仍沿用原作业模式。',1,'商辂','商辂',NULL,NULL,'2026-10-15 15:13:00',NULL),(9105,'SDDEMO05','厦门正水炭纤维科技有限公司','2026-10-16 15:20:00',NULL,'信任不足',NULL,'制造业',NULL,'小型企业10-50人',NULL,'演示与试用',NULL,'预算未明确',0,0,'39600.00','后续联系不上','客户要求免费试用一个月，我方要求交定金或押金并按比例扣费，客户不接受，之后失联。',1,'商辂','商辂',NULL,NULL,'2026-10-16 15:20:00',NULL),(9106,'SDDEMO06','宁波海曙鞋业','2026-10-18 10:54:00',NULL,'竞争对手抢单',NULL,'制造业',NULL,'中型企业50-100人',NULL,'商务谈判',NULL,'报价超预算',1,1,'25000.00','竞品（年费两三千）','客户执着按年付费且预算卡得紧，对比多家后选择年费仅两三千的竞品。',1,'商辂','商辂',NULL,NULL,'2026-10-18 10:54:00',NULL),(9107,'SDDEMO07','上海睿信医疗器械','2026-10-20 09:30:00',NULL,'功能缺失',NULL,'医疗健康',NULL,'中型企业50-100人',NULL,'需求确认',NULL,'预算未明确',0,1,'56000.00','自研','客户要求器械追溯编码与批次校验，现有功能缺失且定制度大，客户决定自研。',1,'商辂','商辂',NULL,NULL,'2026-10-20 09:30:00',NULL),(9108,'SDDEMO08','广州云栈网络科技','2026-10-22 16:10:00',NULL,'项目延期',NULL,'互联网',NULL,'初创公司10人以下',NULL,'线索',NULL,'符合预算',0,0,'12000.00','明年再启动','客户融资推迟，内部预算冻结，项目整体延期到明年一季度。',1,'商辂','商辂',NULL,NULL,'2026-10-22 16:10:00',NULL) ON DUPLICATE KEY UPDATE `customer_name`=VALUES(`customer_name`),`lost_at`=VALUES(`lost_at`),`reason`=VALUES(`reason`),`final_quote`=VALUES(`final_quote`);
-- 演示数据回填字典 ID（仅在为空时回填，可重复执行）
UPDATE `lost_order` l JOIN `dict_item` d ON d.`type`='loss_type' AND d.`name`=l.`loss_type_name` SET l.`loss_type_id`=d.`id` WHERE l.`loss_type_id` IS NULL;
UPDATE `lost_order` l JOIN `dict_item` d ON d.`type`='industry' AND d.`name`=l.`industry_name` SET l.`industry_id`=d.`id` WHERE l.`industry_id` IS NULL;
UPDATE `lost_order` l JOIN `dict_item` d ON d.`type`='company_scale' AND d.`name`=l.`company_scale_name` SET l.`company_scale_id`=d.`id` WHERE l.`company_scale_id` IS NULL;
UPDATE `lost_order` l JOIN `dict_item` d ON d.`type`='loss_stage' AND d.`name`=l.`loss_stage_name` SET l.`loss_stage_id`=d.`id` WHERE l.`loss_stage_id` IS NULL;
UPDATE `lost_order` l JOIN `dict_item` d ON d.`type`='budget_match' AND d.`name`=l.`budget_match_name` SET l.`budget_match_id`=d.`id` WHERE l.`budget_match_id` IS NULL;

COMMIT;
