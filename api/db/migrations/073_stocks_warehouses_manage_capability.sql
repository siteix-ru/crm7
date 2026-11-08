INSERT IGNORE INTO `capabilities` (`code`, `title`) VALUES
  ('stocks.warehouses.manage', 'Склады: управление складами');

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`)
SELECT DISTINCT `level`, 'stocks.warehouses.manage'
FROM `level_capabilities`
WHERE `capability_code` IN ('stocks.edit', 'stocks.import', 'stocks.manage');
