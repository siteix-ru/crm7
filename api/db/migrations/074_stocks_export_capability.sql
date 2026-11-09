INSERT IGNORE INTO `capabilities` (`code`, `title`) VALUES
  ('stocks.export', 'Номенклатура: экспорт');

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`)
SELECT DISTINCT `level`, 'stocks.export'
FROM `level_capabilities`
WHERE `capability_code` IN ('stocks.view', 'stocks.edit', 'stocks.import', 'stocks.manage');
