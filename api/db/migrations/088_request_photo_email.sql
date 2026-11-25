-- Email template for the dedicated public-photo notification (Stage 3.4.281B).
-- Existing installations keep admin-edited templates thanks to the no-op update.
INSERT INTO `email_templates` (`code`,`subject`,`body`) VALUES
  ('request_photo','Фото по заявке {request_number}','Здравствуйте!\n\nК заявке {request_number} добавлено фото: {file_name}.\n{title}\n\n{body}\n\nОткрыть заявку: {link}')
ON DUPLICATE KEY UPDATE `subject` = `subject`;
