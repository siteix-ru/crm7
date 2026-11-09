INSERT IGNORE INTO `capabilities` (`code`, `title`) VALUES
  ('stocks.delete', 'Номенклатура: удаление позиций'),
  ('stocks.warehouses.view_all', 'Склады: просмотр остатков по всем складам');

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`)
SELECT DISTINCT `level`, 'stocks.delete'
FROM `level_capabilities`
WHERE `capability_code` = 'stocks.manage';

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`)
SELECT DISTINCT `level`, 'stocks.warehouses.view_all'
FROM `level_capabilities`
WHERE `capability_code` = 'stocks.manage';

