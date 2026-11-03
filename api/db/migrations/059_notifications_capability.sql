INSERT INTO `capabilities` (`code`, `title`) VALUES
  ('notifications.use', 'Уведомления: использование')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`) VALUES
  (90, 'notifications.use'),
  (50, 'notifications.use'),
  (10, 'notifications.use'),
  (5, 'notifications.use');
