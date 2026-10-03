-- =====================================================================
--  迁移 2.0.1 → 2.0.2
--
--  修复一批在实测与审计中确认的缺陷。这个脚本是**幂等**的：
--  重复执行不会报错，也不会重复改数据。
--
--  用法（在项目根目录）：
--      mysql -uticket -p ticket < database/migrate-2.0.2.sql
--  或直接执行 bin/setup.php --force（它只处理结构，不会补下面的数据修正）。
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1) email_code：把「盐」从哈希里拆出来单独一列
--
--    原来的代码先写 64 位 HMAC，再把 32 位盐 CONCAT 到前面，
--    实际需要 96 个字符，而列宽是 CHAR(64)：
--      · 严格模式 → 1406 Data too long，注册流程直接 500；
--      · 非严格模式 → 静默截断，验证永远失败。
--    两种结果都会让「邮箱验证码」这个功能完全不可用。
-- ---------------------------------------------------------------------
SET @has_salt := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_code' AND COLUMN_NAME = 'salt'
);
SET @sql := IF(@has_salt = 0,
  'ALTER TABLE `email_code` ADD COLUMN `salt` CHAR(32) NOT NULL DEFAULT '''' COMMENT ''每行独立盐（16 字节的十六进制）'' AFTER `code_hash`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 列宽保持 64（新的写入方式只需要 64），顺势把注释补上
SET @sql := 'ALTER TABLE `email_code` MODIFY COLUMN `code_hash` CHAR(64) NOT NULL COMMENT ''验证码的 HMAC-SHA256（十六进制）''';
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 旧数据无法验证（盐信息已丢失或被截断），直接作废，避免留下永远验证不过的记录
UPDATE `email_code` SET `used` = 1 WHERE `salt` = '';

-- ---------------------------------------------------------------------
-- 2) user：登录失败计数 + 锁定时间 + 邮箱唯一
--
--    前台登录的「防爆破」原本只存在会话里，清掉 Cookie 就归零，
--    等于可以无限猜密码。计数必须落库，所以需要这两列。
--
--    邮箱加唯一键：它同时是「找回工单」的凭据，只在应用层做先查后插
--    挡不住并发——两个同时到达的注册会让同一个邮箱绑到两个账号上。
-- ---------------------------------------------------------------------
SET @has_login_fail := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'login_fail'
);
SET @sql := IF(@has_login_fail = 0,
  'ALTER TABLE `user` ADD COLUMN `login_fail` INT NOT NULL DEFAULT 0 COMMENT ''连续登录失败次数'' AFTER `status`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_locked := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'locked_until'
);
SET @sql := IF(@has_locked = 0,
  'ALTER TABLE `user` ADD COLUMN `locked_until` DATETIME NULL COMMENT ''锁定到期时间'' AFTER `login_fail`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 邮箱去重（保留最早注册的那个账号，其余置空而不是删除账号）：
-- 不先处理重复值的话，加唯一键会直接失败。
UPDATE `user` u
JOIN (
  SELECT `email`, MIN(`id`) AS keep_id
  FROM `user`
  WHERE `email` <> ''
  GROUP BY `email`
  HAVING COUNT(*) > 1
) d ON d.`email` = u.`email` AND u.`id` <> d.keep_id
SET u.`email` = '';

-- 换唯一键（先看旧的普通索引在不在，在就删掉）
SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND INDEX_NAME = 'idx_user_email'
);
SET @sql := IF(@has_idx > 0, 'ALTER TABLE `user` DROP INDEX `idx_user_email`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_uk := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND INDEX_NAME = 'uk_user_email'
);
SET @sql := IF(@has_uk = 0, 'ALTER TABLE `user` ADD UNIQUE KEY `uk_user_email` (`email`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3) ticket.updated_at 索引
--
--    工单列表、我的工单、后台最新工单都按 updated_at DESC 排序，
--    而这张表上没有任何以 updated_at 开头的索引 → 每次都做 filesort。
-- ---------------------------------------------------------------------
SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket' AND INDEX_NAME = 'idx_tk_updated'
);
SET @sql := IF(@has_idx = 0, 'ALTER TABLE `ticket` ADD KEY `idx_tk_updated` (`updated_at`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4) 数据修正：reply_count 与实际公开回复数对齐
--
--    删除内部备注时旧代码会无条件把 reply_count 减一，
--    而该字段只统计「对外可见的回复」，于是计数会永久偏离真实值。
-- ---------------------------------------------------------------------
UPDATE `ticket` t
SET t.`reply_count` = (
  SELECT COUNT(*) FROM `ticket_reply` r
  WHERE r.`ticket_id` = t.`id` AND r.`is_internal` = 0
)
WHERE t.`reply_count` <> (
  SELECT COUNT(*) FROM `ticket_reply` r2
  WHERE r2.`ticket_id` = t.`id` AND r2.`is_internal` = 0
);

-- ---------------------------------------------------------------------
-- 5) 数据修正：清理指向不存在处理人的指派
-- ---------------------------------------------------------------------
UPDATE `ticket` SET `assignee_id` = 0
WHERE `assignee_id` > 0
  AND `assignee_id` NOT IN (SELECT `id` FROM `staff`);

-- ---------------------------------------------------------------------
-- 6) 数据修正：已解决但缺少 resolved_at 的工单补上时间
-- ---------------------------------------------------------------------
UPDATE `ticket`
SET `resolved_at` = `updated_at`
WHERE `status` = 'resolved' AND `resolved_at` IS NULL;

-- ---------------------------------------------------------------------
-- 7) 记录迁移版本
-- ---------------------------------------------------------------------
INSERT INTO `schema_migration` (`version`) VALUES ('2.0.2-fixes')
ON DUPLICATE KEY UPDATE `applied_at` = `applied_at`;

SET FOREIGN_KEY_CHECKS = 1;

SELECT '迁移完成' AS result,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_code' AND COLUMN_NAME = 'salt') AS email_code_salt,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'login_fail') AS user_login_fail,
       (SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND INDEX_NAME = 'uk_user_email') AS user_email_unique,
       (SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket' AND INDEX_NAME = 'idx_tk_updated') AS ticket_updated_index;
