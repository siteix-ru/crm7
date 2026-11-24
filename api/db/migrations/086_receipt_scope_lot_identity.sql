-- Migrate historical receipt idempotency scopes from the old
-- STOCK_LEVEL_ID + lower(trim(LOT_CODE)) form to the real lot identity
-- STOCK_LEVEL_ID + stock_lots.ID.
--
-- The new scope is derived from RESULT_ID, which 085 populated with the
-- LOT_ID of the original receipt movement. No LOT_ID is guessed: rows whose
-- referenced lot no longer exists are left untouched. The inequality guard
-- makes the migration safe to re-run.
--
-- String comparisons/concatenations explicitly COLLATE to the registry column
-- collation (utf8mb4_unicode_ci), which differs from the application
-- connection collation on some supported MariaDB versions.

UPDATE `stock_lot_idempotency` r
  JOIN `stock_lots` l ON l.ID = r.RESULT_ID
   SET r.SCOPE_KEY = CONCAT(CAST(l.STOCK_LEVEL_ID AS CHAR) COLLATE utf8mb4_unicode_ci,
                            ':' COLLATE utf8mb4_unicode_ci,
                            CAST(l.ID AS CHAR) COLLATE utf8mb4_unicode_ci)
 WHERE r.OPERATION = 'receipt' COLLATE utf8mb4_unicode_ci
   AND r.SCOPE_TYPE = 'lot' COLLATE utf8mb4_unicode_ci
   AND r.RESULT_ID IS NOT NULL
   AND r.SCOPE_KEY <> CONCAT(CAST(l.STOCK_LEVEL_ID AS CHAR) COLLATE utf8mb4_unicode_ci,
                             ':' COLLATE utf8mb4_unicode_ci,
                             CAST(l.ID AS CHAR) COLLATE utf8mb4_unicode_ci)
                        COLLATE utf8mb4_unicode_ci
   AND NOT EXISTS (
     SELECT 1
       FROM (
         SELECT ID, OPERATION, SCOPE_TYPE, SCOPE_KEY, IDEMPOTENCY_KEY
           FROM `stock_lot_idempotency`
       ) x
      WHERE x.OPERATION = 'receipt' COLLATE utf8mb4_unicode_ci
        AND x.SCOPE_TYPE = 'lot' COLLATE utf8mb4_unicode_ci
        AND x.SCOPE_KEY = CONCAT(CAST(l.STOCK_LEVEL_ID AS CHAR) COLLATE utf8mb4_unicode_ci,
                                 ':' COLLATE utf8mb4_unicode_ci,
                                 CAST(l.ID AS CHAR) COLLATE utf8mb4_unicode_ci)
                            COLLATE utf8mb4_unicode_ci
        AND x.IDEMPOTENCY_KEY = r.IDEMPOTENCY_KEY
        AND x.ID <> r.ID
   );
