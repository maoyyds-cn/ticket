-- =============================================================
--  升级脚本：五级角色体系（客服 / 工程师 / 主管 / 技术管理员 / 超级管理员）
--
--  用法（二选一）：
--    A. 命令行执行
--       mysql -u用户名 -p 数据库名 < install/upgrade-roles.sql
--    B. 登录后台后访问 /admin/schema-check.php 点「一键升级」（推荐，
--       表前缀会自动读取，无需手工替换）
--
--  注意：表前缀若不是 tk_，请把下面的 tk_ 替换成你的实际表名
--        （可在 config/config.php 的 db.prefix 查看）。
--
--  说明：
--    1) role 枚举扩为 5 值。原 ENUM 只有 super/admin/operator，
--       直接写入 supervisor / engineer 会报 1265 数据截断错误。
--    2) ticket_reply 新增 author_role，保存回复当时的角色快照。
--       若不存快照，客服升为主管后，其历史回复的头衔会跟着变，
--       旧记录被改写成事后才存在的身份。
--    3) 原有 admin 角色语义变为「技术管理员」，权限与数据均不变。
-- =============================================================

-- -------------------------------------------------------------
-- 1) role 枚举扩展
--    MODIFY 而非 CHANGE：只改类型与注释，不动列顺序与其他属性。
-- -------------------------------------------------------------
ALTER TABLE `tk_admin`
  MODIFY COLUMN `role` ENUM('super','admin','supervisor','engineer','operator')
    NOT NULL DEFAULT 'operator'
    COMMENT 'super=超管 admin=技术管理员 supervisor=主管 engineer=工程师 operator=客服';

-- -------------------------------------------------------------
-- 2) 回复记录增加角色快照
-- -------------------------------------------------------------
ALTER TABLE `tk_ticket_reply`
  ADD COLUMN `author_role` VARCHAR(20) NOT NULL DEFAULT ''
    COMMENT '回复时的角色快照，避免角色变动后历史头衔被改写'
    AFTER `author_name`;

-- -------------------------------------------------------------
-- 3) 存量工单回复补齐角色标记
--    历史回复无法回溯当时的角色，因此取该账号「当前」角色回填。
--    不能一律写 'operator'：否则超管、主管写下的历史回复会被
--    永久固化成「【客服】」，比留空更糟。账号已删除的才退回 operator。
-- -------------------------------------------------------------
UPDATE `tk_ticket_reply` r
  LEFT JOIN `tk_admin` a ON a.id = r.admin_id
  SET r.author_role = COALESCE(NULLIF(a.role, ''), 'operator')
 WHERE r.admin_id > 0 AND r.author_role = '';

-- -------------------------------------------------------------
-- 4) 工单列表按处理人筛选需要走索引，补一个复合索引
-- -------------------------------------------------------------
ALTER TABLE `tk_ticket`
  ADD KEY `idx_ticket_assignee_status` (`assignee_id`, `status`);
