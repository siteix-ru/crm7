-- 057: доступность типа цен для выбора.

ALTER TABLE `price_types`
  ADD COLUMN IF NOT EXISTS `ENABLED` tinyint(1) NOT NULL DEFAULT 1 AFTER `SORT`;
