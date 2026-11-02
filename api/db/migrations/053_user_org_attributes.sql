-- 053: организационные атрибуты сотрудников.
--   users.DEPARTMENT — отдел, CITY — город, OFFICE — офис,
--   WAREHOUSE_ID — склад сотрудника (ссылка на stocks.ID, без FK по стилю проекта).

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `DEPARTMENT` varchar(255) DEFAULT NULL AFTER `DOLGNOST`,
  ADD COLUMN IF NOT EXISTS `CITY` varchar(128) DEFAULT NULL AFTER `DEPARTMENT`,
  ADD COLUMN IF NOT EXISTS `OFFICE` varchar(128) DEFAULT NULL AFTER `CITY`,
  ADD COLUMN IF NOT EXISTS `WAREHOUSE_ID` int DEFAULT NULL AFTER `OFFICE`,
  ADD KEY IF NOT EXISTS `idx_users_warehouse` (`WAREHOUSE_ID`);
