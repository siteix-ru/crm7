ALTER TABLE `request_attachments`
  ADD COLUMN IF NOT EXISTS `comment` varchar(1000) NULL DEFAULT NULL AFTER `is_internal`;
