-- =============================================================
--  升级脚本：Cloudflare Turnstile 人机验证 + 注册邮箱验证码
--
--  用法（MySQL 命令行或 phpMyAdmin 执行）：
--    mysql -u用户名 -p 数据库名 < install/upgrade-turnstile.sql
--
--  注意：表前缀若不是 tk_，请把下面的 tk_ 替换成你的实际表名
--        （可在 config/config.php 的 db.prefix 查看）。
--
--  说明：
--    1) 新增 tk_email_code 表存放邮箱验证码
--    2) 写入 Turnstile 站点密钥与私密密钥
--    3) 打开注册邮箱验证开关
--    4) 注册接口增加频控上限
--
--  私密密钥若已被他人获取，请到 Cloudflare 控制台
--  「Turnstile → 站点 → 密钥」重新生成后再改这里。
-- =============================================================

-- -------------------------------------------------------------
-- 1) 邮箱验证码表
--    code 存 HMAC 摘要而非明文，数据库泄露也无法反推验证码。
-- -------------------------------------------------------------
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

-- -------------------------------------------------------------
-- 2) Turnstile 密钥
-- -------------------------------------------------------------
INSERT INTO `tk_settings` (`skey`, `svalue`) VALUES
  ('turnstile_site_key',   '0x4AAAAAAFLyRh01wP_T51-y'),
  ('turnstile_secret_key', '0x4AAAAAAFLyRj-voMFpbc2_lmVbN804O-A')
ON DUPLICATE KEY UPDATE `svalue` = VALUES(`svalue`);

-- -------------------------------------------------------------
-- 3) 打开注册邮箱验证（注册表单强制填写验证码）
-- -------------------------------------------------------------
INSERT INTO `tk_settings` (`skey`, `svalue`) VALUES
  ('reg_require_email_code', '1')
ON DUPLICATE KEY UPDATE `svalue` = VALUES(`svalue`);
