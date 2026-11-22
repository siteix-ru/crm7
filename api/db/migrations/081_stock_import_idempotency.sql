-- Идемпотентность импорта остатков.
-- Аддитивная миграция: новые таблицы и nullable-колонки; существующие данные не изменяются.

CREATE TABLE IF NOT EXISTS `stock_import_operations` (
  `ID` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `IDEMPOTENCY_TOKEN` varchar(64) DEFAULT NULL,
  `PAYLOAD_HASH` char(64) NOT NULL,
  `STATE` enum('reserved','processing','unresolved','committed','done','failed') NOT NULL DEFAULT 'reserved',
  `GENERATION` int NOT NULL DEFAULT 1,
  `OWNER_ID` varchar(64) DEFAULT NULL,
  `LEASE_EXPIRES_AT` datetime DEFAULT NULL,
  `CURRENT_ATTEMPT_ID` bigint DEFAULT NULL,
  `RESPONSE_JSON` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `token_cleared_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_user_token` (`user_id`,`IDEMPOTENCY_TOKEN`),
  KEY `idx_state_lease` (`STATE`,`LEASE_EXPIRES_AT`),
  KEY `idx_retention` (`STATE`,`completed_at`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_import_attempts` (
  `ID` bigint NOT NULL AUTO_INCREMENT,
  `operation_id` bigint NOT NULL,
  `attempt_no` int NOT NULL,
  `generation` int NOT NULL,
  `JOB_ID` int NOT NULL,
  `STATE` enum('reserved','processing','committed','failed','aborted') NOT NULL DEFAULT 'reserved',
  `COMMIT_MARKER` datetime DEFAULT NULL,
  `RESPONSE_JSON` text DEFAULT NULL,
  `ERROR_CODE` varchar(64) DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_attempt` (`operation_id`,`attempt_no`),
  KEY `idx_operation` (`operation_id`),
  KEY `idx_job` (`JOB_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `stock_import_jobs`
  ADD COLUMN IF NOT EXISTS `ATTEMPT_ID` bigint DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `GENERATION` int NOT NULL DEFAULT 1,
  MODIFY COLUMN `STATUS` enum('processing','done','failed','unresolved') NOT NULL DEFAULT 'processing';

CREATE INDEX IF NOT EXISTS `idx_attempt` ON `stock_import_jobs` (`ATTEMPT_ID`);
