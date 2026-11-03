INSERT INTO `capabilities` (`code`, `title`) VALUES
  ('mail.manage', 'Почта: настройка')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`) VALUES
  (90, 'mail.manage');
