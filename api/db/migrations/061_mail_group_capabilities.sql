INSERT INTO `capabilities` (`code`, `title`) VALUES
  ('mail.send', 'Почта: отправка писем и уведомлений'),
  ('mail.recovery', 'Почта: восстановление пароля')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`) VALUES
  (90, 'mail.send'),
  (90, 'mail.recovery'),
  (50, 'mail.send'),
  (50, 'mail.recovery'),
  (10, 'mail.recovery'),
  (1, 'mail.recovery');
