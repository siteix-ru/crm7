INSERT INTO `capabilities` (`code`, `title`) VALUES
  ('registration.use', 'Регистрация клиентов: самостоятельная регистрация')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`) VALUES
  (1, 'registration.use');
