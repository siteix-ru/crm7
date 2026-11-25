-- Queue payload for photo attachments in notification emails.
-- Attachments are resolved once when the email is queued and stored as a JSON
-- array of {file_name, mime, content(base64)}. Retries reuse the same payload,
-- so a failed send never resolves or attaches a different set of files.
ALTER TABLE `email_queue`
  ADD COLUMN IF NOT EXISTS `attachments_json` MEDIUMTEXT NULL AFTER `body`;
