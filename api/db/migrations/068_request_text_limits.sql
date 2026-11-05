ALTER TABLE `request_activities`
  MODIFY COLUMN `body` text DEFAULT NULL;

ALTER TABLE `request_history`
  MODIFY COLUMN `comment` text DEFAULT NULL;

ALTER TABLE `request_assignments`
  MODIFY COLUMN `comment` text DEFAULT NULL;
