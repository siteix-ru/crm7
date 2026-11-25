-- "Партии" becomes a regular toggleable module with its own capabilities.
INSERT IGNORE INTO `capabilities` (`code`, `title`) VALUES
  ('stocks.lots.view', 'Партии: просмотр'),
  ('stocks.lots.manage', 'Партии: приёмка и распределение остатков');

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`) VALUES
  (90, 'stocks.lots.view'), (90, 'stocks.lots.manage'),
  (50, 'stocks.lots.view'), (50, 'stocks.lots.manage'),
  (10, 'stocks.lots.view'), (10, 'stocks.lots.manage');

INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('module.stock_lots', '1');
