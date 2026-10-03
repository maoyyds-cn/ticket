-- =============================================================
--  升级脚本：访客访问密钥改为「用户提交工单时自行设定」
--
--  用法（MySQL 命令行或 phpMyAdmin 执行）：
--    mysql -u用户名 -p 数据库名 < install/upgrade-access-key.sql
--
--  注意：表前缀若不是 tk_，请把下面的 tk_ticket 替换成你的实际表名
--        （可在 config/config.php 的 db.prefix 查看）。
--
--  说明：
--    新增 access_key 列存放用户自设密钥的哈希。
--    历史工单该列为空，按需求「旧工单一律失效」——
--    这些工单的访客将无法再凭链接查看，登录用户与管理员不受影响。
-- =============================================================

ALTER TABLE `tk_ticket`
  ADD COLUMN `access_key` VARCHAR(64) NOT NULL DEFAULT ''
  COMMENT '访客自设访问密钥的哈希，空=仅登录用户可访问'
  AFTER `is_read`;


-- -------------------------------------------------------------
-- 2) 邮件模板补上 {hint}
--    邮件链接不再携带访问密钥（随邮件外发会在转发中泄露），
--    需提示收件人自行输入工单编号 + 密钥。
--    仅对尚未包含 {hint} 的模板追加，已手动改过的模板不会被动。
-- -------------------------------------------------------------
UPDATE `tk_settings` SET `svalue` = REPLACE(`svalue`, '{link}</p>', '{link}</p><p style="color:#6b7280;font-size:13px">{hint}</p>')
 WHERE `skey` LIKE 'mail_tpl_%'
   AND `svalue` LIKE '%{link}%'
   AND `svalue` NOT LIKE '%{hint}%';

