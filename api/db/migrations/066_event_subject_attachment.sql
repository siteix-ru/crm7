ALTER TABLE `request_activities`
  ADD COLUMN IF NOT EXISTS `attachment_id` bigint unsigned NULL DEFAULT NULL AFTER `is_internal`;

ALTER TABLE `request_attachments`
  ADD COLUMN IF NOT EXISTS `is_internal` tinyint(1) NOT NULL DEFAULT 0 AFTER `size`;

INSERT INTO `activity_types` (`code`, `title`, `audience`, `sort`, `is_active`)
VALUES ('custom', 'Событие', 'all', 0, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `audience` = VALUES(`audience`),
  `sort` = VALUES(`sort`),
  `is_active` = 1;
