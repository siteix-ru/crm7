ALTER TABLE `requests`
  ADD COLUMN IF NOT EXISTS `price_type_id` int NULL DEFAULT NULL AFTER `priority`;

ALTER TABLE `request_drafts`
  ADD COLUMN IF NOT EXISTS `price_type_id` int NULL DEFAULT NULL AFTER `priority`;
