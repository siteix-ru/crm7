-- Stage 3.4.282: MAX / Telegram notification channels.
-- Tokens and webhook secrets are stored only as Crypto::encrypt() ciphertext.
CREATE TABLE IF NOT EXISTS `messenger_channels` (
  `channel` varchar(16) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `token_encrypted` text NULL,
  `webhook_secret_encrypted` text NULL,
  `bot_id` varchar(64) NULL,
  `bot_name` varchar(255) NULL,
  `last_check_at` datetime NULL,
  `last_check_status` varchar(32) NULL,
  `last_check_error` varchar(128) NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `messenger_channels` (`channel`) VALUES ('max'), ('telegram');

CREATE TABLE IF NOT EXISTS `user_messenger_links` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `channel` varchar(16) NOT NULL,
  `external_id` varchar(64) NOT NULL,
  `chat_id` varchar(64) NULL,
  `linked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_sent_at` datetime NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_user_channel` (`user_id`, `channel`),
  UNIQUE KEY `uq_channel_external` (`channel`, `external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messenger_bind_codes` (
  `code_hash` char(64) NOT NULL,
  `user_id` int NOT NULL,
  `channel` varchar(16) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime NULL,
  `attempts` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code_hash`),
  KEY `idx_bind_user` (`user_id`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messenger_webhook_events` (
  `provider` varchar(16) NOT NULL,
  `event_id` varchar(128) NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`provider`, `event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One shared queue: existing email rows keep their meaning via the default.
ALTER TABLE `email_queue`
  ADD COLUMN IF NOT EXISTS `channel` varchar(16) NOT NULL DEFAULT 'email' AFTER `body`,
  ADD COLUMN IF NOT EXISTS `event_key` varchar(255) NULL,
  ADD COLUMN IF NOT EXISTS `context_json` MEDIUMTEXT NULL,
  ADD KEY `idx_channel_status` (`channel`, `status`, `created_at`),
  ADD UNIQUE KEY `uq_queue_event` (`channel`, `event_key`);

-- Messenger delivery is opt-in per event; email/in-app defaults stay unchanged.
ALTER TABLE `notification_settings`
  ADD COLUMN IF NOT EXISTS `max` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `telegram` tinyint(1) NOT NULL DEFAULT 0;

INSERT INTO `capabilities` (`code`, `title`) VALUES ('messengers.manage', 'Мессенджеры: настройка')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);
INSERT IGNORE INTO `level_capabilities` (`level`, `capability_code`) VALUES (90, 'messengers.manage'), (50, 'messengers.manage');
