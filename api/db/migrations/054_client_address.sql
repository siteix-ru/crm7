-- 054: адрес клиента. CITY уже добавлен миграцией 053 для сотрудников и используется клиентами.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `ADDRESS` varchar(255) DEFAULT NULL AFTER `CITY`;
