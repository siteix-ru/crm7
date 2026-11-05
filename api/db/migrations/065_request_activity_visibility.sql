ALTER TABLE `request_activities`
  ADD COLUMN IF NOT EXISTS `is_internal` tinyint(1) NULL DEFAULT NULL AFTER `body`;
