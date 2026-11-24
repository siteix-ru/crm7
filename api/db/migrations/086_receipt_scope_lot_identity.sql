-- Migrate historical receipt idempotency scopes from the old
-- STOCK_LEVEL_ID + lower(trim(LOT_CODE)) form to the real lot identity
-- STOCK_LEVEL_ID + stock_lots.ID.
--
-- The new scope is derived from RESULT_ID, which 085 populated with the
-- LOT_ID of the original receipt movement. No LOT_ID is guessed: rows whose
-- referenced lot no longer exists are left untouched. The inequality guard
-- makes the migration safe to re-run.

UPDATE `stock_lot_idempotency` r
  JOIN `stock_lots` l ON l.ID = r.RESULT_ID
   SET r.SCOPE_KEY = CONCAT(CAST(l.STOCK_LEVEL_ID AS CHAR), ':', CAST(l.ID AS CHAR))
 WHERE r.OPERATION = 'receipt'
   AND r.SCOPE_TYPE = 'lot'
   AND r.RESULT_ID IS NOT NULL
   AND r.SCOPE_KEY <> CONCAT(CAST(l.STOCK_LEVEL_ID AS CHAR), ':', CAST(l.ID AS CHAR))
   AND NOT EXISTS (
     SELECT 1
       FROM (
         SELECT ID, OPERATION, SCOPE_TYPE, SCOPE_KEY, IDEMPOTENCY_KEY
           FROM `stock_lot_idempotency`
       ) x
      WHERE x.OPERATION = 'receipt'
        AND x.SCOPE_TYPE = 'lot'
        AND x.SCOPE_KEY = CONCAT(CAST(l.STOCK_LEVEL_ID AS CHAR), ':', CAST(l.ID AS CHAR))
        AND x.IDEMPOTENCY_KEY = r.IDEMPOTENCY_KEY
        AND x.ID <> r.ID
   );
