-- 056: доступность группы номенклатуры для выбора.

ALTER TABLE `item_groups`
  ADD COLUMN IF NOT EXISTS `ENABLED` tinyint(1) NOT NULL DEFAULT 1 AFTER `DESCRIPTION`;
