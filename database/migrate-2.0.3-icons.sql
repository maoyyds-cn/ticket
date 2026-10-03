-- =====================================================================
--  迁移 2.0.3：分类图标从 emoji 改为图标名
--
--  背景：分类原先用 emoji（🚀🎮💎…）当图标。emoji 的字形与颜色由操作系统
--  字体决定，因此既无法跟随分类自己在后台配置的主题色，也无法在深色模式下
--  调色；七个彩色贴图并列时视觉重量接近，读者抓不到重点。
--
--  现在 icon 列存的是「图标名」，由 Support\icons.php 渲染成同一套线性 SVG，
--  颜色走分类的主题色。本脚本把已有数据里的 emoji 翻译成对应的图标名。
--
--  幂等：已经是图标名的行不会被改动。
-- =====================================================================

SET NAMES utf8mb4;

UPDATE `category` SET `icon` = 'rocket'  WHERE `icon` = '🚀';
UPDATE `category` SET `icon` = 'gamepad' WHERE `icon` = '🎮';
UPDATE `category` SET `icon` = 'diamond' WHERE `icon` = '💎';
UPDATE `category` SET `icon` = 'account' WHERE `icon` IN ('🔐', '👤', '🔑');
UPDATE `category` SET `icon` = 'shield'  WHERE `icon` IN ('🛡️', '🛡');
UPDATE `category` SET `icon` = 'server'  WHERE `icon` IN ('🖥️', '🖥', '💻');
UPDATE `category` SET `icon` = 'help'    WHERE `icon` IN ('❓', '❔', '⚠️');
UPDATE `category` SET `icon` = 'book'    WHERE `icon` IN ('📚', '📖');
UPDATE `category` SET `icon` = 'bolt'    WHERE `icon` IN ('⚡', '🚀');
UPDATE `category` SET `icon` = 'folder'  WHERE `icon` IN ('📁', '🗂️', '🗂');

-- 兜底：任何仍然不是合法图标名的值，统一回退到 folder，
-- 避免页面上出现一个渲染不出来的空白图标。
UPDATE `category` SET `icon` = 'folder'
WHERE `icon` NOT IN (
  'ticket','chat','book','send','search','check','check-circle','warn','info','bang',
  'clock','bolt','bell','shield','lock','folder','user','users','mail','chart','list',
  'gear','clipboard','key','clip','file','image','download','plus','close','arrow-right',
  'external','refresh','trash','sun','moon','logout','inbox','compass','star','rocket',
  'gamepad','diamond','account','server','help','palette','wrench'
);

INSERT INTO `schema_migration` (`version`) VALUES ('2.0.3-icons')
ON DUPLICATE KEY UPDATE `applied_at` = `applied_at`;

SELECT `id`, `slug`, `name`, `icon`, `color` FROM `category` ORDER BY `sort` DESC;
