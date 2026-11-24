-- Per-lot attribution of physical issues, so reversals can restore the exact
-- lots and stock levels from which the goods were taken. Additive on top of 083.

CREATE TABLE IF NOT EXISTS `stock_issue_lines` (
  `ID` bigint NOT NULL AUTO_INCREMENT,
  `ISSUE_ID` bigint NOT NULL,
  `LOT_ID` bigint NOT NULL,
  `STOCK_LEVEL_ID` int NOT NULL,
  `QUANTITY` decimal(20,5) NOT NULL,
  `REVERSED_QUANTITY` decimal(20,5) NOT NULL DEFAULT 0.00000,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_issue_line` (`ISSUE_ID`, `LOT_ID`),
  KEY `idx_issue_line_lot` (`LOT_ID`),
  CONSTRAINT `ck_issue_line_positive` CHECK (`QUANTITY` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
