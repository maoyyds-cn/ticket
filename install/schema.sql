-- =============================================================
--  工单系统 数据库结构
--  字符集 utf8mb4 / 引擎 InnoDB
-- =============================================================

CREATE TABLE IF NOT EXISTS `tk_settings` (
  `skey`       VARCHAR(64)  NOT NULL COMMENT '配置键',
  `svalue`     TEXT         NULL COMMENT '配置值',
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置';

CREATE TABLE IF NOT EXISTS `tk_admin` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(32)  NOT NULL COMMENT '登录名',
  `password`      VARCHAR(255) NOT NULL COMMENT '密码哈希',
  `realname`      VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '姓名/昵称',
  `email`         VARCHAR(120) NOT NULL DEFAULT '',
  `role`          ENUM('super','admin','supervisor','engineer','operator') NOT NULL DEFAULT 'operator' COMMENT 'super=超管 admin=技术管理员 supervisor=主管 engineer=工程师 operator=客服',
  `status`        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `last_login_at` DATETIME     NULL,
  `last_login_ip` VARCHAR(45)  NOT NULL DEFAULT '',
  `login_fail`    INT          NOT NULL DEFAULT 0,
  `locked_until`  DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台管理员';

CREATE TABLE IF NOT EXISTS `tk_user` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(32)  NOT NULL,
  `password`      VARCHAR(255) NOT NULL,
  `email`         VARCHAR(120) NOT NULL DEFAULT '',
  `qq`            VARCHAR(20)  NOT NULL DEFAULT '',
  `realname`      VARCHAR(50)  NOT NULL DEFAULT '',
  `status`        TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login_at` DATETIME     NULL,
  `last_login_ip` VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_username` (`username`),
  KEY `idx_user_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台用户';

CREATE TABLE IF NOT EXISTS `tk_category` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(50)  NOT NULL COMMENT '名称',
  `slug`       VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '别名',
  `icon`       VARCHAR(20)  NOT NULL DEFAULT '📁',
  `color`      VARCHAR(20)  NOT NULL DEFAULT '#4f46e5',
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `is_ticket`  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否作为工单分类',
  `is_faq`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否作为FAQ分类',
  `sort`       INT          NOT NULL DEFAULT 0,
  `status`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cat_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分类（FAQ与工单共用）';

CREATE TABLE IF NOT EXISTS `tk_faq` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `question`    VARCHAR(255) NOT NULL,
  `answer`      MEDIUMTEXT   NOT NULL,
  `keywords`    VARCHAR(255) NOT NULL DEFAULT '' COMMENT '搜索关键词',
  `is_hot`      TINYINT(1)   NOT NULL DEFAULT 0,
  `is_top`      TINYINT(1)   NOT NULL DEFAULT 0,
  `views`       INT          NOT NULL DEFAULT 0,
  `helpful`     INT          NOT NULL DEFAULT 0,
  `unhelpful`   INT          NOT NULL DEFAULT 0,
  `sort`        INT          NOT NULL DEFAULT 0,
  `status`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1已发布 0草稿',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_faq_cat` (`category_id`),
  KEY `idx_faq_status` (`status`),
  FULLTEXT KEY `ft_faq` (`question`, `answer`, `keywords`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='知识库FAQ';

CREATE TABLE IF NOT EXISTS `tk_ticket` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_no`    VARCHAR(24)  NOT NULL COMMENT '工单编号',
  `user_id`      INT UNSIGNED NOT NULL DEFAULT 0,
  `guest_name`   VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '游客昵称',
  `contact_email` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '联系邮箱',
  `qq`           VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'QQ',
  `category_id`  INT UNSIGNED NOT NULL DEFAULT 0,
  `title`        VARCHAR(200) NOT NULL,
  `content`      MEDIUMTEXT   NOT NULL,
  `attachments`  TEXT         NULL COMMENT 'JSON 数组',
  `priority`     ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `status`       ENUM('pending','processing','replied','resolved','closed','spam') NOT NULL DEFAULT 'pending',
  `assignee_id`  INT UNSIGNED NOT NULL DEFAULT 0,
  `source`       VARCHAR(20)  NOT NULL DEFAULT 'web',
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `user_agent`   VARCHAR(255) NOT NULL DEFAULT '',
  `reply_count`  INT          NOT NULL DEFAULT 0,
  `view_count`   INT          NOT NULL DEFAULT 0,
  `is_read`      TINYINT(1)   NOT NULL DEFAULT 0,
  `access_key`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '访客自设访问密钥的哈希，空=仅登录用户可访问',
  `rating`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '用户评分1-5',
  `rating_note`  VARCHAR(255) NOT NULL DEFAULT '',
  `first_reply_at` DATETIME   NULL,
  `resolved_at`  DATETIME     NULL,
  `closed_at`    DATETIME     NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `idx_ticket_user` (`user_id`),
  KEY `idx_ticket_status` (`status`),
  KEY `idx_ticket_created` (`created_at`),
  KEY `idx_ticket_assignee` (`assignee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工单主表';

CREATE TABLE IF NOT EXISTS `tk_ticket_reply` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`    INT UNSIGNED NOT NULL,
  `admin_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0表示用户回复',
  `author_name`  VARCHAR(50)  NOT NULL DEFAULT '',
  `author_role`  VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '回复时的角色快照，避免角色变动后历史头衔被改写',
  `content`      MEDIUMTEXT   NOT NULL,
  `attachments`  TEXT         NULL,
  `is_internal`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=内部备注(用户不可见)',
  `new_status`   VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '本次回复后的状态',
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reply_ticket` (`ticket_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工单回复/内部备注';

CREATE TABLE IF NOT EXISTS `tk_ticket_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   INT UNSIGNED NOT NULL,
  `admin_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `admin_name`  VARCHAR(50)  NOT NULL DEFAULT '',
  `action`      VARCHAR(40)  NOT NULL COMMENT '动作',
  `detail`      VARCHAR(500) NOT NULL DEFAULT '',
  `ip`          VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_ticket` (`ticket_id`, `id`),
  KEY `idx_log_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工单操作日志';

CREATE TABLE IF NOT EXISTS `tk_email_code` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(120) NOT NULL,
  `code`       CHAR(64)     NOT NULL COMMENT 'HMAC 后的验证码，不存明文',
  `scene`      VARCHAR(32)  NOT NULL DEFAULT 'register',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `expire_at`  DATETIME     NOT NULL,
  `used`       TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ec_email` (`email`,`scene`),
  KEY `idx_ec_ip` (`ip`),
  KEY `idx_ec_exp` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮箱验证码';

CREATE TABLE IF NOT EXISTS `tk_mail_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`  INT UNSIGNED NOT NULL DEFAULT 0,
  `to_email`   VARCHAR(120) NOT NULL,
  `subject`    VARCHAR(200) NOT NULL DEFAULT '',
  `status`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1成功 0失败',
  `error`      VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mail_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件发送记录';

CREATE TABLE IF NOT EXISTS `tk_admin_session` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL,
  `ip`         VARCHAR(45) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_as_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台登录记录';
