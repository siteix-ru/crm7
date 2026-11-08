INSERT INTO `settings` (`key`, `value`)
VALUES ('module.warehouses', '1')
ON DUPLICATE KEY UPDATE `value` = '1';
