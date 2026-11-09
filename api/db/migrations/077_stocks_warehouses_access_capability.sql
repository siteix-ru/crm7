INSERT IGNORE INTO `capabilities` (`code`, `title`) VALUES
  ('stocks.warehouses.access', 'Склады: управление доступом');

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`)
SELECT DISTINCT `level`, 'stocks.warehouses.access'
FROM `level_capabilities`
WHERE `capability_code` = 'stocks.warehouses.manage';
