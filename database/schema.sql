-- =====================================================================
--  Roblox 查询机器人 · 工单与知识库系统
--  数据库结构  v2.0.0
--
--  目标环境：MySQL 5.7.44 / InnoDB / utf8mb4
--  刻意不使用 MySQL 8 才支持的语法（窗口函数、CTE、CHECK 约束、
--  函数索引、utf8mb4_0900_* 排序规则），以便同一份 SQL 在 5.7 与 8.0
--  上都能原样导入。
--
--  与 v1 的三处关键差异：
--   1) 工单号不再用 COUNT(*) 推序号（并发下必然重复），改为「日期 + 随机段」，
--      并由唯一索引兜底，冲突时重试。
--   2) 不再对中文内容建 FULLTEXT 索引：InnoDB 默认分词器按空格切词，
--      中文整句会成为一个 token，MATCH...AGAINST 对中文实际检索不到结果。
--      改为 question/keywords 上的前缀索引 + LIKE 检索，行为可预期。
--   3) 角色精简为四种（operator/supervisor/admin/super）。
--      v1 的 engineer 与 operator 权限完全等价，是纯粹的多余分支。
-- =====================================================================

--
-- 排序规则显式写死为 utf8mb4_unicode_ci，不用「跟随服务器默认」：
-- MySQL 5.7 的 utf8mb4 默认是 general_ci，8.0 则是 0900_ai_ci，
-- 同一份 SQL 在两版上建出的表排序规则不同，一旦将来 5.7 → 8.0 迁移，
-- 表之间 JOIN 会直接抛 1267（Illegal mix of collations）。
-- 显式声明后两版行为一致，迁移不再有这类暗坑。
--
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 迁移版本
--
-- v1 没有版本概念：三次结构变更靠三个手工 SQL 文件 + 一个超管页面，
-- 且那两个 SQL 文件在全新安装上必然报 1060（列已存在）而中断。
-- 这里记录已执行的迁移，让结构演进可查、可重复执行。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migration` (
  `version`    VARCHAR(64) NOT NULL COMMENT '迁移标识',
  `applied_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='结构迁移记录';

-- ---------------------------------------------------------------------
-- 站点配置（键值对）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `k`          VARCHAR(64)  NOT NULL COMMENT '配置键',
  `v`          TEXT         NULL     COMMENT '配置值',
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='站点配置';

-- ---------------------------------------------------------------------
-- 前台用户
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(32)  NOT NULL,
  `password`      VARCHAR(255) NOT NULL COMMENT 'password_hash 结果',
  `email`         VARCHAR(120) NOT NULL DEFAULT '',
  `qq`            VARCHAR(20)  NOT NULL DEFAULT '',
  `realname`      VARCHAR(50)  NOT NULL DEFAULT '',
  `status`        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `login_fail`    INT          NOT NULL DEFAULT 0 COMMENT '连续登录失败次数',
  `locked_until`  DATETIME     NULL COMMENT '锁定到期时间',
  `last_login_at` DATETIME     NULL,
  `last_login_ip` VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_username` (`username`),
  -- 邮箱必须是唯一键，而不是普通索引：
  -- 它同时是「找回工单」的凭据。只在应用层做「先查后插」的检查挡不住并发，
  -- 两个同时到达的注册请求会各查到「没人用」，于是同一个邮箱绑两个账号，
  -- 而登录用的是 `WHERE username = ? OR email = ?` 取第一条——用户会被随机登进其中一个。
  UNIQUE KEY `uk_user_email` (`email`),
  KEY `idx_user_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='前台用户';

-- ---------------------------------------------------------------------
-- 后台账号（客服 / 主管 / 管理员 / 超管）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `staff` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(32)  NOT NULL,
  `password`      VARCHAR(255) NOT NULL,
  `realname`      VARCHAR(50)  NOT NULL DEFAULT '',
  `email`         VARCHAR(120) NOT NULL DEFAULT '',
  `role`          ENUM('operator','supervisor','admin','super') NOT NULL DEFAULT 'operator'
                  COMMENT 'operator=客服 supervisor=主管 admin=管理员 super=超级管理员',
  `status`        TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login_at` DATETIME     NULL,
  `last_login_ip` VARCHAR(45)  NOT NULL DEFAULT '',
  `login_fail`    INT          NOT NULL DEFAULT 0 COMMENT '连续失败次数',
  `locked_until`  DATETIME     NULL COMMENT '锁定到期时间',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_staff_username` (`username`),
  KEY `idx_staff_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台账号';

-- ---------------------------------------------------------------------
-- 分类（工单分类与知识库分类共用一张表，用两个开关区分用途）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `category` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50)  NOT NULL,
  `slug`        VARCHAR(60)  NOT NULL DEFAULT '',
  `icon`        VARCHAR(20)  NOT NULL DEFAULT '📁',
  `color`       VARCHAR(20)  NOT NULL DEFAULT '#4f46e5',
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `is_ticket`   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '可作为工单分类',
  `is_faq`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '可作为知识库分类',
  `sort`        INT          NOT NULL DEFAULT 0,
  `status`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cat_sort` (`sort`, `id`),
  KEY `idx_cat_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分类';

-- ---------------------------------------------------------------------
-- 知识库条目
--
-- keywords 刻意放宽到 500：中文检索不做分词，靠「问题 + 关键词 + 正文」
-- 的 LIKE 匹配，关键词列越完整，搜索结果越准。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `faq` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `question`    VARCHAR(255) NOT NULL,
  `answer`      MEDIUMTEXT   NOT NULL,
  `keywords`    VARCHAR(500) NOT NULL DEFAULT '' COMMENT '检索关键词，空格分隔',
  `is_hot`      TINYINT(1)   NOT NULL DEFAULT 0,
  `is_top`      TINYINT(1)   NOT NULL DEFAULT 0,
  `views`       INT UNSIGNED NOT NULL DEFAULT 0,
  `helpful`     INT UNSIGNED NOT NULL DEFAULT 0,
  `unhelpful`   INT UNSIGNED NOT NULL DEFAULT 0,
  `sort`        INT          NOT NULL DEFAULT 0,
  `status`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1已发布 0草稿',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_faq_cat` (`category_id`, `status`),
  KEY `idx_faq_status_sort` (`status`, `is_top`, `sort`),
  KEY `idx_faq_hot` (`status`, `is_hot`, `views`),
  KEY `idx_faq_question` (`question`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识库条目';

-- ---------------------------------------------------------------------
-- 工单主表
--
-- ticket_no 形如 RB20261003-7F3A2C：日期 + 随机段。
-- 随机段由唯一索引保证不重复，插入冲突时应用层重试，
-- 因此并发提交不会产生重号（v1 用 COUNT(*) 推序号，并发下必然撞号）。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_no`     VARCHAR(32)  NOT NULL COMMENT '工单编号',
  `user_id`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=访客提交',
  `guest_name`    VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '访客昵称',
  `contact_email` VARCHAR(120) NOT NULL DEFAULT '',
  `qq`            VARCHAR(20)  NOT NULL DEFAULT '',
  `category_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `title`         VARCHAR(200) NOT NULL,
  `content`       MEDIUMTEXT   NOT NULL,
  `attachments`   TEXT         NULL COMMENT 'JSON 数组 [{name,path,size}]',
  `priority`      ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `status`        ENUM('pending','processing','replied','resolved','closed','spam')
                  NOT NULL DEFAULT 'pending',
  `assignee_id`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '处理人，0=未指派',
  `source`        VARCHAR(20)  NOT NULL DEFAULT 'web',
  `ip`            VARCHAR(45)  NOT NULL DEFAULT '',
  `user_agent`    VARCHAR(255) NOT NULL DEFAULT '',
  `reply_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `view_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `is_read`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '后台是否已读',
  `access_hash`   VARCHAR(64)  NOT NULL DEFAULT ''
                  COMMENT '访客访问密钥的 HMAC，空=仅登录用户与后台可访问',
  `rating`        TINYINT      NOT NULL DEFAULT 0 COMMENT '1-5，0=未评分',
  `rating_note`   VARCHAR(255) NOT NULL DEFAULT '',
  `first_reply_at` DATETIME    NULL COMMENT '首次客服回复时间，用于首响统计',
  `resolved_at`   DATETIME     NULL,
  `closed_at`     DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `idx_tk_user` (`user_id`, `created_at`),
  KEY `idx_tk_email` (`contact_email`, `created_at`),
  KEY `idx_tk_status` (`status`, `created_at`),
  KEY `idx_tk_assignee` (`assignee_id`, `status`),
  KEY `idx_tk_category` (`category_id`),
  KEY `idx_tk_priority` (`priority`, `status`),
  KEY `idx_tk_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单主表';

-- ---------------------------------------------------------------------
-- 工单回复 / 内部备注
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_reply` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   INT UNSIGNED NOT NULL,
  `staff_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=用户或访客回复',
  `author_name` VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '回复时姓名快照',
  `author_role` VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '回复时角色快照，避免升职改写历史头衔',
  `content`     MEDIUMTEXT   NOT NULL,
  `attachments` TEXT         NULL,
  `is_internal` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=内部备注，用户不可见',
  `new_status`  VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '本次回复后工单状态',
  `ip`          VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rp_ticket` (`ticket_id`, `id`),
  KEY `idx_rp_internal` (`ticket_id`, `is_internal`),
  KEY `idx_rp_staff` (`staff_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单回复';

-- ---------------------------------------------------------------------
-- 工单操作日志（审计）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`  INT UNSIGNED NOT NULL,
  `staff_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `staff_name` VARCHAR(50)  NOT NULL DEFAULT '',
  `action`     VARCHAR(40)  NOT NULL,
  `detail`     VARCHAR(500) NOT NULL DEFAULT '',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lg_ticket` (`ticket_id`, `id`),
  KEY `idx_lg_time` (`created_at`),
  KEY `idx_lg_action` (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单操作日志';

-- ---------------------------------------------------------------------
-- 邮箱验证码（注册 / 找回密码）
-- 只存 HMAC，不存明文；一次性使用。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_code` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(120) NOT NULL,
  `code_hash`  CHAR(64)     NOT NULL COMMENT '验证码的 HMAC-SHA256（十六进制）',
  `salt`       CHAR(32)     NOT NULL COMMENT '每行独立盐（16 字节的十六进制）',
  `scene`      VARCHAR(32)  NOT NULL DEFAULT 'register',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `tries`      TINYINT      NOT NULL DEFAULT 0 COMMENT '校验失败次数，防暴力猜码',
  `expire_at`  DATETIME     NOT NULL,
  `used`       TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ec_lookup` (`email`, `scene`, `used`),
  KEY `idx_ec_ip` (`ip`, `created_at`),
  KEY `idx_ec_exp` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮箱验证码';

-- ---------------------------------------------------------------------
-- 邮件发送记录
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mail_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`  INT UNSIGNED NOT NULL DEFAULT 0,
  `to_email`   VARCHAR(120) NOT NULL,
  `subject`    VARCHAR(200) NOT NULL DEFAULT '',
  `ok`         TINYINT(1)   NOT NULL DEFAULT 0,
  `error`      VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ml_time` (`created_at`),
  KEY `idx_ml_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮件发送记录';

-- ---------------------------------------------------------------------
-- 后台登录审计
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `staff_session` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id`   INT UNSIGNED NOT NULL,
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ss_staff` (`staff_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台登录记录';

SET FOREIGN_KEY_CHECKS = 1;
