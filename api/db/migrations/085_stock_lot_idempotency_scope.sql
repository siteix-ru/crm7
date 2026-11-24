-- Scoped idempotency registry for stock lot operations.
-- Additive to 083/084: historical rows are preserved and backfilled with an
-- empty payload hash (legacy replay stays idempotent without a new effect).

CREATE TABLE IF NOT EXISTS `stock_lot_idempotency` (
  `ID` bigint NOT NULL AUTO_INCREMENT,
  `OPERATION` enum('issue','reversal','receipt') NOT NULL,
  `SCOPE_TYPE` enum('request','issue','lot') NOT NULL,
  `SCOPE_KEY` varchar(255) NOT NULL,
  `IDEMPOTENCY_KEY` varchar(128) NOT NULL,
  `PAYLOAD_HASH` char(64) NOT NULL DEFAULT '',
  `RESULT_ID` bigint DEFAULT NULL,
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_lot_idem_scope` (`OPERATION`, `SCOPE_TYPE`, `SCOPE_KEY`, `IDEMPOTENCY_KEY`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill issues: REQUEST_ID is the domain scope; payload cannot be
-- reconstructed reliably, so PAYLOAD_HASH stays empty (legacy semantics).
INSERT INTO `stock_lot_idempotency`
  (`OPERATION`, `SCOPE_TYPE`, `SCOPE_KEY`, `IDEMPOTENCY_KEY`, `PAYLOAD_HASH`, `RESULT_ID`)
SELECT 'issue', 'request', CAST(o.REQUEST_ID AS CHAR), o.IDEMPOTENCY_KEY, '', o.ID
  FROM `stock_issue_operations` o
 WHERE o.IDEMPOTENCY_KEY IS NOT NULL
   AND o.IDEMPOTENCY_KEY <> ''
   AND NOT EXISTS (
     SELECT 1 FROM `stock_lot_idempotency` r
      WHERE r.OPERATION = 'issue'
        AND r.SCOPE_TYPE = 'request'
        AND r.SCOPE_KEY = CAST(o.REQUEST_ID AS CHAR)
        AND r.IDEMPOTENCY_KEY = o.IDEMPOTENCY_KEY
   );

-- Backfill receipts: the lot code is taken from the joined lot because the
-- movement table stores only LOT_ID. SCOPE_KEY matches the runtime
-- normalization: STOCK_LEVEL_ID + lower(trim(LOT_CODE)) (DB collation is ci).
INSERT INTO `stock_lot_idempotency`
  (`OPERATION`, `SCOPE_TYPE`, `SCOPE_KEY`, `IDEMPOTENCY_KEY`, `PAYLOAD_HASH`, `RESULT_ID`)
SELECT 'receipt', 'lot',
       CONCAT(CAST(m.STOCK_LEVEL_ID AS CHAR), ':', LOWER(TRIM(l.LOT_CODE))),
       m.IDEMPOTENCY_KEY, '', m.LOT_ID
  FROM `stock_lot_movements` m
  JOIN `stock_lots` l ON l.ID = m.LOT_ID
 WHERE m.TYPE = 'receipt'
   AND m.REVERSES_ID IS NULL
   AND m.IDEMPOTENCY_KEY IS NOT NULL
   AND m.IDEMPOTENCY_KEY <> ''
   AND NOT EXISTS (
     SELECT 1 FROM `stock_lot_idempotency` r
      WHERE r.OPERATION = 'receipt'
        AND r.SCOPE_TYPE = 'lot'
        AND r.SCOPE_KEY = CONCAT(CAST(m.STOCK_LEVEL_ID AS CHAR), ':', LOWER(TRIM(l.LOT_CODE)))
        AND r.IDEMPOTENCY_KEY = m.IDEMPOTENCY_KEY
   );

-- Backfill reversals: REVERSES_ID is the ID of the original
-- stock_issue_operations row (confirmed in StockLotService::reversal()).
-- The historical key is stored as 'rev:' + client key; only the suffix is the
-- real idempotency key. Rows without a matching issue are left untouched.
INSERT INTO `stock_lot_idempotency`
  (`OPERATION`, `SCOPE_TYPE`, `SCOPE_KEY`, `IDEMPOTENCY_KEY`, `PAYLOAD_HASH`, `RESULT_ID`)
SELECT 'reversal', 'issue', CAST(m.REVERSES_ID AS CHAR),
       SUBSTRING(m.IDEMPOTENCY_KEY, 5), '', m.REVERSES_ID
  FROM `stock_lot_movements` m
  JOIN `stock_issue_operations` o ON o.ID = m.REVERSES_ID
 WHERE m.TYPE = 'reversal'
   AND m.REVERSES_ID IS NOT NULL
   AND m.IDEMPOTENCY_KEY LIKE 'rev:%'
   AND NOT EXISTS (
     SELECT 1 FROM `stock_lot_idempotency` r
      WHERE r.OPERATION = 'reversal'
        AND r.SCOPE_TYPE = 'issue'
        AND r.SCOPE_KEY = CAST(m.REVERSES_ID AS CHAR)
        AND r.IDEMPOTENCY_KEY = SUBSTRING(m.IDEMPOTENCY_KEY, 5)
   );

-- Replace the global issue key UNIQUE with a request-scoped one. Dynamic DDL
-- keeps the migration re-runnable on MariaDB/MySQL variants.
SET @crm7_sql = (
  SELECT IF(COUNT(*) > 0,
    'ALTER TABLE `stock_issue_operations` DROP INDEX `uq_issue_idempotency`',
    'SELECT 1')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'stock_issue_operations'
   AND INDEX_NAME = 'uq_issue_idempotency'
);
PREPARE crm7_stmt FROM @crm7_sql;
EXECUTE crm7_stmt;
DEALLOCATE PREPARE crm7_stmt;

SET @crm7_sql = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `stock_issue_operations` ADD UNIQUE KEY `uq_issue_scope_key` (`REQUEST_ID`, `IDEMPOTENCY_KEY`)',
    'SELECT 1')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'stock_issue_operations'
   AND INDEX_NAME = 'uq_issue_scope_key'
);
PREPARE crm7_stmt FROM @crm7_sql;
EXECUTE crm7_stmt;
DEALLOCATE PREPARE crm7_stmt;

-- The movement key is no longer authoritative; the scoped registry is.
SET @crm7_sql = (
  SELECT IF(COUNT(*) > 0,
    'ALTER TABLE `stock_lot_movements` DROP INDEX `uq_movement_idempotency`',
    'SELECT 1')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'stock_lot_movements'
   AND INDEX_NAME = 'uq_movement_idempotency'
);
PREPARE crm7_stmt FROM @crm7_sql;
EXECUTE crm7_stmt;
DEALLOCATE PREPARE crm7_stmt;
