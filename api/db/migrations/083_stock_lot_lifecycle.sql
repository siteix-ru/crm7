-- Server-side stock lot lifecycle: balances, FIFO reservations, issue/reversal ledger.
-- Legacy products (TRACK_LOTS=0) are untouched; this migration only adds tables.

CREATE TABLE IF NOT EXISTS `stock_lots` (
  `ID` bigint NOT NULL AUTO_INCREMENT,
  `STOCK_LEVEL_ID` int NOT NULL,
  `NAME_SID` char(50) NOT NULL,
  `LOT_CODE` varchar(128) NOT NULL,
  `QUANTITY_AVAILABLE` decimal(20,5) NOT NULL DEFAULT 0.00000,
  `QUANTITY_RESERVED` decimal(20,5) NOT NULL DEFAULT 0.00000,
  `RECEIVED_AT` datetime NOT NULL,
  `EXPIRES_AT` date DEFAULT NULL,
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_level_lot` (`STOCK_LEVEL_ID`, `LOT_CODE`),
  KEY `idx_fifo` (`STOCK_LEVEL_ID`, `RECEIVED_AT`, `ID`),
  CONSTRAINT `ck_lot_nonnegative` CHECK (`QUANTITY_AVAILABLE` >= 0 AND `QUANTITY_RESERVED` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_lot_reservations` (
  `ID` bigint NOT NULL AUTO_INCREMENT,
  `RESERVATION_ID` int NOT NULL,
  `LOT_ID` bigint NOT NULL,
  `QUANTITY` decimal(20,5) NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_reservation_lot` (`RESERVATION_ID`, `LOT_ID`),
  KEY `idx_lot` (`LOT_ID`),
  CONSTRAINT `ck_lot_reservation_positive` CHECK (`QUANTITY` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_issue_operations` (
  `ID` bigint NOT NULL AUTO_INCREMENT,
  `REQUEST_ID` int NOT NULL,
  `STOCK_LEVEL_ID` int NOT NULL,
  `LOT_ID` bigint NOT NULL,
  `QUANTITY` decimal(20,5) NOT NULL,
  `REVERSED_QUANTITY` decimal(20,5) NOT NULL DEFAULT 0.00000,
  `IDEMPOTENCY_KEY` varchar(128) DEFAULT NULL,
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_issue_idempotency` (`IDEMPOTENCY_KEY`),
  KEY `idx_issue_request` (`REQUEST_ID`),
  CONSTRAINT `ck_issue_positive` CHECK (`QUANTITY` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_lot_movements` (
  `ID` bigint NOT NULL AUTO_INCREMENT,
  `LOT_ID` bigint NOT NULL,
  `STOCK_LEVEL_ID` int NOT NULL,
  `TYPE` enum('receipt','conversion','reservation','release','issue','reversal') NOT NULL,
  `QUANTITY` decimal(20,5) NOT NULL,
  `AVAILABLE_BEFORE` decimal(20,5) NOT NULL,
  `AVAILABLE_AFTER` decimal(20,5) NOT NULL,
  `RESERVED_BEFORE` decimal(20,5) NOT NULL,
  `RESERVED_AFTER` decimal(20,5) NOT NULL,
  `REQUEST_ID` int DEFAULT NULL,
  `IDEMPOTENCY_KEY` varchar(160) DEFAULT NULL,
  `REVERSES_ID` bigint DEFAULT NULL,
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_movement_idempotency` (`IDEMPOTENCY_KEY`),
  KEY `idx_movement_lot` (`LOT_ID`, `ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
