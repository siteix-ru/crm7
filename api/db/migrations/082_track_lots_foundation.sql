-- Optional lot accounting foundation.
-- Additive and backward-compatible:
--   * existing nomenclature rows keep TRACK_LOTS=0 (legacy aggregate mode);
--   * no balances, reservations or historical data are recalculated;
--   * no lot tables are created yet (full lot accounting is a later stage).

ALTER TABLE `nomenclature`
  ADD COLUMN `TRACK_LOTS` TINYINT(1) NOT NULL DEFAULT 0 AFTER `UNIT`;

-- Global default is applied only when a new product is created.
-- INSERT IGNORE preserves an operator-configured value on repeated runs.
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('stock.track_lots_default', '0');
