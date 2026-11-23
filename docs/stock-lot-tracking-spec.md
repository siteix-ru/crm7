# CRM7 — Stock Lot Tracking Specification (v1.1, reconciled with master 3e914f0)

Status labels:

- `[USER-APPROVED]` — business decision approved by the user (preserved from v1.0).
- `[CODE-CONFIRMED]` — verified against current `origin/master` `3e914f0…` (merge of PR #14).
- `[IMPLEMENTED 3.4.221]` — implemented in the foundation stage.
- `[PROPOSED]` — technical proposal, **not** an approved business decision.
- `[NEEDS USER DECISION]` — requires an explicit user decision; do not assume it.

---

## 1. Baseline and reconciliation

- Baseline v1.0 was written against `master` at PR #10 (`94b016b…`). Current baseline is `3e914f0…`. `[CODE-CONFIRMED]`
- Changes since v1.0 that matter here:
  - PR #13 added Dockerless deployment and **native backup/restore** (`deploy/native_backup_restore.py`); lot tables are not covered by it yet. `[CODE-CONFIRMED]`
  - PR #14 fixed PHP 8.4 `fgetcsv()` deprecation in `StocksService::readCsv()` by passing `'"', '\\'` explicitly. The CSV parser must remain unchanged by lot work. `[CODE-CONFIRMED]`
  - Atomic reservation and ABS accounting (`physical_quantity`, shared topology sentinel, `Decimal`) remain in place. `[CODE-CONFIRMED]`

## 2. Purpose, scope, non-scope

Purpose: introduce optional per-product lot accounting while keeping the PR #10 aggregate contract unchanged for products without lot tracking. `[USER-APPROVED]`

Long-term scope: `TRACK_LOTS` flag, lot balances, immutable movement ledger, lot-level reservations, FIFO, manual correction, import/export, API/UI warnings, mode transitions, backup/restore, `issue`/`reversal`. `[USER-APPROVED]`

Non-scope (all stages): FEFO automation, new access capabilities, automatic backfill of existing balances, changes to the `quantity`/`physical_quantity` API contract. `[USER-APPROVED]`

**Stage 3.4.221 scope** `[USER-APPROVED via Stage plan]`:
- migration adding `nomenclature.TRACK_LOTS TINYINT(1) NOT NULL DEFAULT 0`;
- `stock.track_lots_default` setting for **new products only**;
- typed accessor in `SettingsRepository`;
- `ModuleService::SETTINGS` registration and existing rights/validation;
- apply default on product creation via `StocksRepository::upsertNomenclature()`;
- documentation of the future lot contract;
- tests proving legacy compatibility and mode isolation.

**Not in Stage 3.4.221** (explicitly deferred): FIFO allocation, physical issue/reversal, per-lot write-off, lot import/export, expiry management, lot editing UI, conversion of existing balances into lots, new lot tables. `[USER-APPROVED via Stage plan]`

## 3. Preserved business rules

- `P = A + R`, `stock_levels.QUANTITY = A`, API `quantity` = A, `physical_quantity` = P. `[USER-APPROVED]` `[CODE-CONFIRMED]`
- `TRACK_LOTS=false` keeps aggregate behavior unchanged. `[USER-APPROVED]`
- All existing products receive `TRACK_LOTS=0`; no backfill; balances and reservations are untouched. `[USER-APPROVED]`
- Global default applies only when creating a new product; changing it never changes existing products. `[USER-APPROVED]`
- No new capability is introduced; changing the setting uses existing `roles.manage` enforcement in `ModuleService::save()`. `[USER-APPROVED]` `[CODE-CONFIRMED]`
- Client-owner cancel path never grants issue/reversal rights. `[USER-APPROVED]`

## 4. Current master code map (verified)

| Concern | File / symbol |
|---|---|
| Settings registry | `api/src/Modules/ModuleService.php:28-49` (`SETTINGS`) |
| Settings save / rights | `ModuleService::save()` → `requireRolesManage()` (`:74-76`) |
| Bool coercion / validation | `ModuleService::readModuleSettings()` (`:299-330`), `moduleSettingsPayload()` (`:273-297`) |
| Generic settings storage | `api/src/Repositories/SettingsRepository.php:11-34` |
| Typed stock accessors | `SettingsRepository::stockReserveEnabled()`, `allowZeroStock()` |
| Product creation | `api/src/Repositories/StocksRepository.php:611` `upsertNomenclature()` |
| Available quantity | `api/src/Stocks/StocksService.php:1548` `resolveAvailable()` |
| Reservation apply/release | `api/src/Stocks/StockReservationService.php:41,96` |
| Reservation sums | `api/src/Repositories/StockReservationRepository.php:161,173` |
| Warehouse topology | `StocksService::deleteWarehouse()` `:1836`, `collapseWarehouses()` `:2171` |
| Request rights | `api/src/Requests/RequestService.php:1844` `canManage()` |
| CSV import | `StocksService::readCsv()` `:3256-3288` (PHP 8.4-safe as of PR #14) |
| Latest migration before this stage | `api/db/migrations/081_stock_import_idempotency.sql` |
| Settings table | `api/db/migrations/008_mail.sql:1-6` |

## 5. Implemented foundation (3.4.221)

- Migration `082_track_lots_foundation.sql` adds the column with default `0` and inserts `stock.track_lots_default='0'` with `INSERT IGNORE` (safe re-run). `[IMPLEMENTED 3.4.221]`
- `SettingsRepository::trackLotsDefault(): bool` — strict `=== '1'`, so null/invalid values are false. `[IMPLEMENTED 3.4.221]`
- `ModuleService::SETTINGS['requests']['stock.track_lots_default']` — bool type, existing validation and `roles.manage` gate reused. `[IMPLEMENTED 3.4.221]`
- `StocksRepository::upsertNomenclature()` passes the default in the `INSERT IGNORE`; existing rows keep their flag. `[IMPLEMENTED 3.4.221]`
- No lot tables, no changes to aggregate stock arithmetic, no API field removals. `[IMPLEMENTED 3.4.221]`

## 6. Legacy compatibility contract

At `TRACK_LOTS=0`, the following must remain byte-for-byte equivalent in behavior and API shape: product CRUD, stock levels, receipts, manual ABS corrections, write-offs, reservations/release, transfers, warehouse delete/collapse, requests/documents, reports, CSV import/export. `[USER-APPROVED]`

Checks added in 3.4.221: migration defaults, existing rows = 0, balances/reservations unchanged, setting validation, default applies only to new products, mode isolation. `[IMPLEMENTED 3.4.221]`

## 7. Projected lot contract (NOT implemented)

Tables and fields below remain **design projections** and must not be created before a separate approved stage: `stock_lots`, `stock_lot_movements` (append-only ledger), `stock_reservation_lots`, `request_issues`. `[PROPOSED]`

Invariants for a future lot mode: `A = Σ lot.available`, `R = Σ lot.reserved`, `P = Σ(available+reserved)`, non-negative balances, no zero-balance lot creation, immutable ledger with reversal rows, idempotency keys for issue/reversal. `[USER-APPROVED for the future mode]`

Lock order for future lot operations: `request GET_LOCK → BEGIN → sentinel → stock_levels FOR UPDATE → stock_lots FOR UPDATE (ID asc) → reservation/ledger/issue writes → COMMIT`. The shared sentinel alone does not protect lot rows. `[PROPOSED]`

## 8. Backup/restore interaction

- Native backup/restore from PR #13 snapshots SQL, secrets and optional `api/storage`; it has no knowledge of lot tables, which do not exist yet. `[CODE-CONFIRMED]`
- Adding `TRACK_LOTS` does not change backup content semantics: it is a column of an existing table and is included automatically in SQL dumps. `[CODE-CONFIRMED]`
- Future lot tables require extending snapshot/restore and invariant assertions before enabling lot mode. `[PROPOSED]`

## 9. Known defect carried forward

Current `closed` status does not release unused reservations and has no issue action. This was already identified in v1.0 §10/§22. `[CODE-CONFIRMED from v1.0; not re-fixed here]`

It is **not** changed in Stage 3.4.221 and is proposed as the scope of Stage 3.4.222 together with issue/reversal. `[PROPOSED]`

## 10. Open decisions `[NEEDS USER DECISION]`

1. Confirm that v1.0 approved business rules remain valid against master `3e914f0…`.
2. Decide whether `issue`/`reversal` and the `closed` reservation fix belong to the same next stage or separate stages.
3. Decide when to extend native backup/restore for future lot tables.
4. Decide whether `track_lots` should be exposed in `GET /stocks` / ItemCard API responses (not done here to avoid contract changes).

## 11. Next stages

- **3.4.222** — issue/reversal + request lifecycle fix (XL).
- **3.4.223** — lot tables, domain layer, FIFO reservation and conversion (XL).
- **3.4.224** — import/export, API and UI (L).
- **3.4.225** — backup/restore extension and full integration matrix (L).
