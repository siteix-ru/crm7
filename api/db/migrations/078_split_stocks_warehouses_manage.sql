INSERT IGNORE INTO `capabilities` (`code`, `title`) VALUES
  ('stocks.warehouses.create', 'Склады: создание'),
  ('stocks.warehouses.edit', 'Склады: редактирование'),
  ('stocks.warehouses.delete', 'Склады: удаление');

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`)
SELECT DISTINCT `level`, 'stocks.warehouses.create'
FROM `level_capabilities`
WHERE `capability_code` = 'stocks.warehouses.manage';

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`)
SELECT DISTINCT `level`, 'stocks.warehouses.edit'
FROM `level_capabilities`
WHERE `capability_code` = 'stocks.warehouses.manage';

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`)
SELECT DISTINCT `level`, 'stocks.warehouses.delete'
FROM `level_capabilities`
WHERE `capability_code` = 'stocks.warehouses.manage';
