<?php

declare(strict_types=1);

namespace App\Stocks;

use App\Core\Database;
use App\Http\HttpException;
use App\Support\Decimal;

/**
 * Server-side lot lifecycle for products with nomenclature.TRACK_LOTS=1.
 *
 * Invariants (kept in sync on every operation):
 *   A = SUM(stock_lots.QUANTITY_AVAILABLE) = stock_levels.QUANTITY
 *   R = SUM(stock_lots.QUANTITY_RESERVED)
 *   P = A + R
 * Legacy products (TRACK_LOTS=0) never reach these paths.
 *
 * Locking contract (F-03): every public entry point that mutates balances uses
 * StockRequestLock (one name per request, 30s timeout) and SELECT ... FOR UPDATE.
 * The advisory lock is never a replacement for row locks.
 */
final class StockLotService
{
    public function isLotLevel(int $levelId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM stock_levels l
              JOIN nomenclature n ON n.SID = l.NAME_SID
             WHERE l.ID = ? AND n.TRACK_LOTS = 1 LIMIT 1'
        );
        $stmt->execute([$levelId]);

        return $stmt->fetchColumn() !== false;
    }

    public function hasLots(int $levelId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM stock_lots WHERE STOCK_LEVEL_ID = ? LIMIT 1');
        $stmt->execute([$levelId]);

        return $stmt->fetchColumn() !== false;
    }

    public function hasLotReservations(int $requestId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM stock_lot_reservations r
              JOIN stock_reservations p ON p.ID = r.RESERVATION_ID
             WHERE p.request_id = ? LIMIT 1'
        );
        $stmt->execute([$requestId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * All stock levels actually involved in a request's lot reservations.
     * Used by the API to authorize EVERY warehouse before an issue (F-02).
     *
     * @return array<int, int>
     */
    public function reservedLevels(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT DISTINCT l.STOCK_LEVEL_ID
               FROM stock_lot_reservations r
               JOIN stock_lots l ON l.ID = r.LOT_ID
               JOIN stock_reservations p ON p.ID = r.RESERVATION_ID
              WHERE p.request_id = ?
              ORDER BY l.STOCK_LEVEL_ID ASC'
        );
        $stmt->execute([$requestId]);

        return array_map('intval', array_column($stmt->fetchAll() ?: [], 'STOCK_LEVEL_ID'));
    }

    /**
     * All stock levels of a recorded issue (F-02 for reversals).
     * Old 083-only issues fall back to the level stored on the operation.
     *
     * @return array<int, int>
     */
    public function issueLevels(int $issueId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT DISTINCT STOCK_LEVEL_ID FROM stock_issue_lines WHERE ISSUE_ID = ? ORDER BY STOCK_LEVEL_ID ASC'
        );
        $stmt->execute([$issueId]);
        $levels = array_map('intval', array_column($stmt->fetchAll() ?: [], 'STOCK_LEVEL_ID'));
        if ($levels !== []) {
            return $levels;
        }

        $stmt = Database::pdo()->prepare('SELECT STOCK_LEVEL_ID FROM stock_issue_operations WHERE ID = ? LIMIT 1');
        $stmt->execute([$issueId]);
        $value = $stmt->fetchColumn();

        return $value === false ? [] : [(int) $value];
    }

    public function lockRequest(int $requestId): void
    {
        StockRequestLock::lock($requestId);
    }

    public function unlockRequest(int $requestId): void
    {
        StockRequestLock::unlock($requestId);
    }

    /** Check-only availability validation for lot levels (reservation disabled). */
    public function assertAvailable(int $levelId, string $want): void
    {
        $this->assertLotsInitialized($levelId);
        $stmt = Database::pdo()->prepare(
            'SELECT CAST(COALESCE(SUM(QUANTITY_AVAILABLE), 0) AS DECIMAL(20,5)) FROM stock_lots WHERE STOCK_LEVEL_ID = ?'
        );
        $stmt->execute([$levelId]);
        $available = Decimal::parse((string) $stmt->fetchColumn());
        if (Decimal::compare(Decimal::parse($want), $available) > 0) {
            throw new HttpException(422, 'insufficient_lot_stock', 'Недостаточно остатка партий');
        }
    }

    /** F-08: a lot product without initialized lots cannot be traded at all. */
    private function assertLotsInitialized(int $levelId): void
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM stock_lots WHERE STOCK_LEVEL_ID = ? LIMIT 1');
        $stmt->execute([$levelId]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }
        throw new HttpException(
            422,
            'lot_conversion_required',
            'Для товара включён учёт партий, но партии не созданы: выполните конверсию остатка'
        );
    }

    /** Initial distribution of an existing available balance into lots. */
    public function convert(int $levelId, array $lots): void
    {
        if (!$this->isLotLevel($levelId)) {
            throw new HttpException(422, 'invalid_mode_transition', 'Lot mode is not enabled');
        }

        $pdo = Database::pdo();
        $lock = $pdo->prepare('SELECT QUANTITY FROM stock_levels WHERE ID = ? FOR UPDATE');
        $lock->execute([$levelId]);
        if ($lock->fetchColumn() === false) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }
        if ($this->hasLots($levelId)) {
            throw new HttpException(422, 'invalid_mode_transition', 'Lots already exist');
        }

        $available = $this->levelAvailable($levelId);
        $sum = '0.00000';
        foreach ($lots as $lot) {
            $sum = Decimal::add($sum, Decimal::parse((string) ($lot['quantity'] ?? '0')));
        }
        if (Decimal::compare($sum, $available) !== 0) {
            throw new HttpException(422, 'lot_distribution_mismatch', 'Lot distribution must equal available balance');
        }

        foreach ($lots as $lot) {
            $this->createLot(
                $levelId,
                (string) ($lot['lot_code'] ?? ''),
                Decimal::parse((string) $lot['quantity']),
                $this->nullableDate($lot['expires_at'] ?? null),
                'conversion',
                null
            );
        }
    }

    /** Receipt increases both a lot and the aggregate available balance. */
    public function receive(
        int $levelId,
        string $lotCode,
        string $quantity,
        ?string $expiresAt = null,
        ?string $idempotencyKey = null
    ): void {
        if ($idempotencyKey !== null) {
            $existing = Database::pdo()->prepare(
                'SELECT ID FROM stock_lot_movements WHERE IDEMPOTENCY_KEY = ? LIMIT 1'
            );
            $existing->execute([$idempotencyKey]);
            if ($existing->fetchColumn() !== false) {
                return;
            }
        }

        if (!$this->isLotLevel($levelId)) {
            throw new HttpException(422, 'invalid_mode_transition', 'Lot mode is not enabled');
        }
        $qty = Decimal::parse($quantity);
        if (Decimal::compare($qty, '0.00000') <= 0) {
            throw new HttpException(422, 'validation_error', 'Receipt quantity must be positive');
        }

        $stmt = Database::pdo()->prepare(
            'SELECT ID, QUANTITY_AVAILABLE, QUANTITY_RESERVED
               FROM stock_lots WHERE STOCK_LEVEL_ID = ? AND LOT_CODE = ? FOR UPDATE'
        );
        $stmt->execute([$levelId, $lotCode]);
        $row = $stmt->fetch();

        if ($row === false) {
            $this->createLot($levelId, $lotCode, $qty, $this->nullableDate($expiresAt), 'receipt', null, $idempotencyKey);
        } else {
            $before = Decimal::parse((string) $row['QUANTITY_AVAILABLE']);
            $after = Decimal::add($before, $qty);
            // A receipt does not change the reservation: record its actual value
            // before and after instead of passing null to non-nullable parameters.
            $reserved = Decimal::parse((string) $row['QUANTITY_RESERVED']);
            $this->updateLot((int) $row['ID'], $after, null);
            $this->movement((int) $row['ID'], $levelId, 'receipt', $qty, $before, $after,
                $reserved, $reserved, null, $idempotencyKey);
        }

        $this->changeLevel($levelId, $qty);
    }

    /**
     * F-01: release the allocations of a single lot level before its parent
     * reservation is removed. Safe to call repeatedly (idempotent).
     */
    public function releaseLevel(int $requestId, int $levelId): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID FROM stock_reservations WHERE request_id = ? AND stock_level_id = ? FOR UPDATE'
        );
        $stmt->execute([$requestId, $levelId]);
        $parentId = $stmt->fetchColumn();
        if ($parentId !== false) {
            $this->releaseParent((int) $parentId);
        }
    }

    /**
     * FIFO allocation for a request. Parent reservations must already exist.
     * @param array<int, string> $desired levelId => canonical quantity
     */
    public function reserve(int $requestId, array $desired): bool
    {
        $pdo = Database::pdo();

        foreach ($desired as $levelId => $quantity) {
            $levelId = (int) $levelId;
            if (!$this->isLotLevel($levelId)) {
                continue;
            }

            $want = Decimal::parse((string) $quantity);
            if (Decimal::compare($want, '0.00000') <= 0) {
                continue;
            }
            $this->assertLotsInitialized($levelId);

            // Release previous allocation for this parent first (idempotent re-apply).
            $parent = $pdo->prepare('SELECT ID FROM stock_reservations WHERE request_id = ? AND stock_level_id = ? FOR UPDATE');
            $parent->execute([$requestId, $levelId]);
            $parentId = $parent->fetchColumn();
            if ($parentId === false) {
                return false;
            }
            $this->releaseParent((int) $parentId);

            $this->assertAvailable($levelId, $want);

            $lots = $pdo->prepare(
                'SELECT ID, QUANTITY_AVAILABLE, QUANTITY_RESERVED FROM stock_lots
                  WHERE STOCK_LEVEL_ID = ? AND QUANTITY_AVAILABLE > 0
                  ORDER BY RECEIVED_AT ASC, ID ASC FOR UPDATE'
            );
            $lots->execute([$levelId]);

            $remaining = $want;
            foreach ($lots->fetchAll() ?: [] as $lot) {
                if (Decimal::compare($remaining, '0.00000') <= 0) {
                    break;
                }
                $available = Decimal::parse((string) $lot['QUANTITY_AVAILABLE']);
                $take = Decimal::compare($available, $remaining) <= 0 ? $available : $remaining;
                $remaining = Decimal::sub($remaining, $take);

                $beforeAvailable = $available;
                $afterAvailable = Decimal::sub($available, $take);
                $beforeReserved = Decimal::parse((string) $lot['QUANTITY_RESERVED']);
                $afterReserved = Decimal::add($beforeReserved, $take);
                $this->updateLot((int) $lot['ID'], $afterAvailable, $afterReserved);

                $pdo->prepare(
                    'INSERT INTO stock_lot_reservations (RESERVATION_ID, LOT_ID, QUANTITY) VALUES (?, ?, ?)'
                )->execute([(int) $parentId, (int) $lot['ID'], $take]);

                $this->movement((int) $lot['ID'], $levelId, 'reservation', $take,
                    $beforeAvailable, $afterAvailable, $beforeReserved, $afterReserved, $requestId);
            }

            if (Decimal::compare($remaining, '0.00000') > 0) {
                throw new HttpException(422, 'insufficient_lot_stock', 'Недостаточно остатка партий');
            }

            $this->changeLevel($levelId, Decimal::sub('0.00000', $want));
        }

        return true;
    }

    /** Releases all lot allocations of a request. */
    public function release(int $requestId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT p.ID AS parent_id, p.stock_level_id AS level_id
               FROM stock_reservations p
              WHERE p.request_id = ?'
        );
        $stmt->execute([$requestId]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $this->releaseParent((int) $row['parent_id']);
        }
    }

    private function releaseParent(int $parentId): void
    {
        $pdo = Database::pdo();
        $rows = $pdo->prepare(
            'SELECT r.ID, r.LOT_ID, r.QUANTITY, l.STOCK_LEVEL_ID,
                    l.QUANTITY_AVAILABLE, l.QUANTITY_RESERVED
               FROM stock_lot_reservations r
               JOIN stock_lots l ON l.ID = r.LOT_ID
              WHERE r.RESERVATION_ID = ?
              ORDER BY r.ID ASC FOR UPDATE'
        );
        $rows->execute([$parentId]);
        $levelId = 0;
        $released = '0.00000';
        foreach ($rows->fetchAll() ?: [] as $row) {
            $lotId = (int) $row['LOT_ID'];
            $levelId = (int) $row['STOCK_LEVEL_ID'];
            $qty = Decimal::parse((string) $row['QUANTITY']);
            $released = Decimal::add($released, $qty);
            $beforeAvailable = Decimal::parse((string) $row['QUANTITY_AVAILABLE']);
            $beforeReserved = Decimal::parse((string) $row['QUANTITY_RESERVED']);
            $afterAvailable = Decimal::add($beforeAvailable, $qty);
            $afterReserved = Decimal::sub($beforeReserved, $qty);
            $this->updateLot($lotId, $afterAvailable, $afterReserved);
            $pdo->prepare('DELETE FROM stock_lot_reservations WHERE ID = ?')->execute([(int) $row['ID']]);
            $this->movement($lotId, $levelId, 'release', $qty,
                $beforeAvailable, $afterAvailable, $beforeReserved, $afterReserved, null);
        }
        if ($levelId > 0 && Decimal::compare($released, '0.00000') !== 0) {
            $this->changeLevel($levelId, $released);
        }
    }

    /** Context of an issue operation, used by the API for rights checks. */
    public function issueContext(int $issueId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT REQUEST_ID, STOCK_LEVEL_ID, LOT_ID, QUANTITY, REVERSED_QUANTITY
               FROM stock_issue_operations WHERE ID = ? LIMIT 1'
        );
        $stmt->execute([$issueId]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'request_id' => (int) $row['REQUEST_ID'],
            'level_id' => (int) $row['STOCK_LEVEL_ID'],
            'lot_id' => (int) $row['LOT_ID'],
            'quantity' => (string) $row['QUANTITY'],
            'reversed_quantity' => (string) $row['REVERSED_QUANTITY'],
        ];
    }

    /**
     * API entry point: unified lock + transaction + optional authorization
     * callback (executed under the lock and before any balance is changed).
     */
    public function issueWithLock(int $requestId, string $quantity, ?string $idempotencyKey = null, ?callable $authorize = null): array
    {
        StockRequestLock::lock($requestId);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($authorize !== null) {
                $authorize();
            }
            $result = $this->issue($requestId, $quantity, $idempotencyKey);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            StockRequestLock::unlock($requestId);
        }
    }

    /**
     * Physical issue from a request's reserved lots. Caller must hold the
     * request lock and a transaction (use issueWithLock from the API).
     */
    public function issue(int $requestId, string $quantity, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey !== null) {
            $existing = Database::pdo()->prepare(
                'SELECT ID FROM stock_issue_operations WHERE IDEMPOTENCY_KEY = ? LIMIT 1'
            );
            $existing->execute([$idempotencyKey]);
            if ($existing->fetchColumn() !== false) {
                return ['already_applied' => true];
            }
        }

        $this->assertRequestOpen($requestId);
        $qty = Decimal::parse($quantity);
        if (Decimal::compare($qty, '0.00000') <= 0) {
            throw new HttpException(422, 'validation_error', 'Issue quantity must be positive');
        }

        $pdo = Database::pdo();
        $rows = $pdo->prepare(
            'SELECT r.ID AS reservation_row, r.RESERVATION_ID, r.LOT_ID, r.QUANTITY,
                    l.STOCK_LEVEL_ID, l.QUANTITY_AVAILABLE, l.QUANTITY_RESERVED,
                    p.request_id, p.quantity AS parent_quantity
               FROM stock_lot_reservations r
               JOIN stock_lots l ON l.ID = r.LOT_ID
               JOIN stock_reservations p ON p.ID = r.RESERVATION_ID
              WHERE p.request_id = ?
              ORDER BY l.RECEIVED_AT ASC, l.ID ASC FOR UPDATE'
        );
        $rows->execute([$requestId]);
        $all = $rows->fetchAll() ?: [];
        if ($all === []) {
            throw new HttpException(422, 'insufficient_lot_stock', 'No lot reservation for request');
        }

        $reservedTotal = '0.00000';
        foreach ($all as $row) {
            $reservedTotal = Decimal::add($reservedTotal, Decimal::parse((string) $row['QUANTITY']));
        }
        if (Decimal::compare($qty, $reservedTotal) > 0) {
            throw new HttpException(422, 'insufficient_lot_stock', 'Issue exceeds reserved lot quantity');
        }

        $remaining = $qty;
        $firstLotId = 0;
        $firstLevelId = 0;
        $lines = [];
        foreach ($all as $row) {
            if (Decimal::compare($remaining, '0.00000') <= 0) {
                break;
            }
            $reserved = Decimal::parse((string) $row['QUANTITY']);
            $take = Decimal::compare($reserved, $remaining) <= 0 ? $reserved : $remaining;
            $remaining = Decimal::sub($remaining, $take);

            $lotId = (int) $row['LOT_ID'];
            $levelId = (int) $row['STOCK_LEVEL_ID'];
            $beforeReserved = Decimal::parse((string) $row['QUANTITY_RESERVED']);
            $afterReserved = Decimal::sub($beforeReserved, $take);
            $beforeAvailable = Decimal::parse((string) $row['QUANTITY_AVAILABLE']);
            $this->updateLot($lotId, $beforeAvailable, $afterReserved);

            $left = Decimal::sub($reserved, $take);
            if (Decimal::compare($left, '0.00000') === 0) {
                $pdo->prepare('DELETE FROM stock_lot_reservations WHERE ID = ?')->execute([(int) $row['reservation_row']]);
            } else {
                $pdo->prepare('UPDATE stock_lot_reservations SET QUANTITY = ? WHERE ID = ?')
                    ->execute([$left, (int) $row['reservation_row']]);
            }

            $parentStmt = $pdo->prepare('SELECT quantity FROM stock_reservations WHERE ID = ? FOR UPDATE');
            $parentStmt->execute([(int) $row['RESERVATION_ID']]);
            $parentCurrent = Decimal::parse((string) $parentStmt->fetchColumn());
            $parentLeft = Decimal::sub($parentCurrent, $take);
            $pdo->prepare('UPDATE stock_reservations SET quantity = ? WHERE ID = ?')
                ->execute([$parentLeft, (int) $row['RESERVATION_ID']]);
            if (Decimal::compare($parentLeft, '0.00000') === 0) {
                $pdo->prepare('DELETE FROM stock_reservations WHERE ID = ?')->execute([(int) $row['RESERVATION_ID']]);
            }

            if ($lines === []) {
                $firstLotId = $lotId;
                $firstLevelId = $levelId;
            }
            $lines[] = ['lot_id' => $lotId, 'level_id' => $levelId, 'quantity' => $take];

            $this->movement($lotId, $levelId, 'issue', $take,
                $beforeAvailable, $beforeAvailable, $beforeReserved, $afterReserved, $requestId);
        }

        $pdo->prepare(
            'INSERT INTO stock_issue_operations (REQUEST_ID, STOCK_LEVEL_ID, LOT_ID, QUANTITY, IDEMPOTENCY_KEY)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$requestId, $firstLevelId, $firstLotId, $qty, $idempotencyKey]);
        $issueId = (int) $pdo->lastInsertId();

        // F-04: immutable per-lot attribution of this issue.
        $lineStmt = $pdo->prepare(
            'INSERT INTO stock_issue_lines (ISSUE_ID, LOT_ID, STOCK_LEVEL_ID, QUANTITY, REVERSED_QUANTITY)
             VALUES (?, ?, ?, ?, 0.00000)'
        );
        foreach ($lines as $line) {
            $lineStmt->execute([$issueId, $line['lot_id'], $line['level_id'], $line['quantity']]);
        }

        return ['already_applied' => false, 'operations' => [$issueId]];
    }

    /** API entry point for reversals (same lock/transaction contract). */
    public function reversalWithLock(int $issueId, string $quantity, ?string $idempotencyKey = null, ?callable $authorize = null): array
    {
        $context = $this->issueContext($issueId);
        if ($context === null) {
            throw new HttpException(404, 'not_found', 'Issue operation not found');
        }
        StockRequestLock::lock($context['request_id']);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($authorize !== null) {
                $authorize();
            }
            $result = $this->reversal($issueId, $quantity, $idempotencyKey);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            StockRequestLock::unlock($context['request_id']);
        }
    }

    /**
     * Compensating reversal for an existing issue. Caller must hold the lock
     * and a transaction. Partial reversals are allocated in the original issue
     * order: stock_issue_lines.ID ASC (the FIFO order of lots at issue time).
     */
    public function reversal(int $issueId, string $quantity, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey !== null) {
            $existing = Database::pdo()->prepare(
                'SELECT ID FROM stock_lot_movements WHERE IDEMPOTENCY_KEY = ? LIMIT 1'
            );
            $existing->execute(['rev:' . $idempotencyKey]);
            if ($existing->fetchColumn() !== false) {
                return ['already_applied' => true];
            }
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT ID, LOT_ID, STOCK_LEVEL_ID, QUANTITY, REVERSED_QUANTITY
               FROM stock_issue_operations WHERE ID = ? FOR UPDATE'
        );
        $stmt->execute([$issueId]);
        $issue = $stmt->fetch();
        if ($issue === false) {
            throw new HttpException(404, 'not_found', 'Issue operation not found');
        }

        $qty = Decimal::parse($quantity);
        if (Decimal::compare($qty, '0.00000') <= 0) {
            throw new HttpException(422, 'validation_error', 'Reversal quantity must be positive');
        }
        $remaining = Decimal::sub(
            Decimal::parse((string) $issue['QUANTITY']),
            Decimal::parse((string) $issue['REVERSED_QUANTITY'])
        );
        if (Decimal::compare($qty, $remaining) > 0) {
            throw new HttpException(422, 'validation_error', 'Reversal exceeds non-reversed quantity');
        }

        $lineStmt = $pdo->prepare(
            'SELECT ID, LOT_ID, STOCK_LEVEL_ID, QUANTITY, REVERSED_QUANTITY
               FROM stock_issue_lines WHERE ISSUE_ID = ? ORDER BY ID ASC FOR UPDATE'
        );
        $lineStmt->execute([$issueId]);
        $lines = $lineStmt->fetchAll() ?: [];
        if ($lines === []) {
            // Issues recorded by 083 before stock_issue_lines existed: use only
            // the attribution that was actually recorded, never invent lots.
            $lines = [[
                'ID' => null,
                'LOT_ID' => $issue['LOT_ID'],
                'STOCK_LEVEL_ID' => $issue['STOCK_LEVEL_ID'],
                'QUANTITY' => $issue['QUANTITY'],
                'REVERSED_QUANTITY' => $issue['REVERSED_QUANTITY'],
            ]];
        }

        $todo = $qty;
        $firstLine = true;
        foreach ($lines as $line) {
            if (Decimal::compare($todo, '0.00000') <= 0) {
                break;
            }
            $lineLeft = Decimal::sub(
                Decimal::parse((string) $line['QUANTITY']),
                Decimal::parse((string) $line['REVERSED_QUANTITY'])
            );
            if (Decimal::compare($lineLeft, '0.00000') <= 0) {
                continue;
            }
            $take = Decimal::compare($lineLeft, $todo) <= 0 ? $lineLeft : $todo;

            $lotId = (int) $line['LOT_ID'];
            $levelId = (int) $line['STOCK_LEVEL_ID'];
            $lotStmt = $pdo->prepare(
                'SELECT QUANTITY_AVAILABLE, QUANTITY_RESERVED FROM stock_lots WHERE ID = ? FOR UPDATE'
            );
            $lotStmt->execute([$lotId]);
            $lot = $lotStmt->fetch();
            if ($lot === false) {
                throw new HttpException(404, 'not_found', 'Lot not found');
            }

            $before = Decimal::parse((string) $lot['QUANTITY_AVAILABLE']);
            $after = Decimal::add($before, $take);
            $this->updateLot($lotId, $after, Decimal::parse((string) $lot['QUANTITY_RESERVED']));

            if ($line['ID'] !== null) {
                $pdo->prepare('UPDATE stock_issue_lines SET REVERSED_QUANTITY = REVERSED_QUANTITY + ? WHERE ID = ?')
                    ->execute([$take, (int) $line['ID']]);
            }

            $this->movement($lotId, $levelId, 'reversal', $take,
                $before, $after, $before, $before, null,
                $firstLine && $idempotencyKey !== null ? 'rev:' . $idempotencyKey : null,
                (int) $issue['ID']);

            $this->changeLevel($levelId, $take);
            $todo = Decimal::sub($todo, $take);
            $firstLine = false;
        }

        if (Decimal::compare($todo, '0.00000') !== 0) {
            throw new HttpException(422, 'validation_error', 'Reversal distribution mismatch');
        }

        $pdo->prepare('UPDATE stock_issue_operations SET REVERSED_QUANTITY = REVERSED_QUANTITY + ? WHERE ID = ?')
            ->execute([$qty, $issueId]);

        return ['already_applied' => false];
    }

    /** Rights required for physical stock operations (server-side only). */
    public function assertIssueRights(array $user, array $capabilities, int $requestId, int $levelId): void
    {
        if (!in_array('stocks.edit', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав склада');
        }

        $stmt = Database::pdo()->prepare(
            'SELECT s.SID AS warehouse_sid, s.LEVEL AS warehouse_level
               FROM stock_levels l JOIN stocks s ON s.SID = l.STOCK_SID WHERE l.ID = ? LIMIT 1'
        );
        $stmt->execute([$levelId]);
        $warehouse = $stmt->fetch();
        if ($warehouse === false) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        $level = (int) $user['LEVEL'];
        $byLevel = str_contains((string) $warehouse['warehouse_level'], '{' . $level . '}');
        $byAccess = false;
        if (!$byLevel) {
            $access = Database::pdo()->prepare(
                'SELECT 1 FROM user_level_stock WHERE USER_SID = ? AND STOCK_SID = ? AND STATUS = ? AND ACTIVE = ? LIMIT 1'
            );
            $access->execute([(string) $user['SID'], (string) $warehouse['warehouse_sid'], 'Y', 'Y']);
            $byAccess = $access->fetchColumn() !== false;
        }
        if (!$byLevel && !$byAccess && !in_array('stocks.warehouses.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Нет доступа к складу');
        }

        // Request management is checked by RequestService::canManageRequest in the controller.
    }

    private function assertRequestOpen(int $requestId): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.code FROM requests r JOIN request_statuses s ON s.code = r.status_id WHERE r.id = ? LIMIT 1'
        );
        $stmt->execute([$requestId]);
        $code = $stmt->fetchColumn();
        if ($code === false || in_array((string) $code, ['closed', 'canceled'], true)) {
            throw new HttpException(409, 'issue_after_closed', 'Списание закрытой заявки запрещено');
        }
    }

    private function levelAvailable(int $levelId): string
    {
        $stmt = Database::pdo()->prepare('SELECT CAST(QUANTITY AS DECIMAL(20,5)) FROM stock_levels WHERE ID = ? LIMIT 1');
        $stmt->execute([$levelId]);
        $value = $stmt->fetchColumn();

        return Decimal::parse($value === false ? '0' : (string) $value);
    }

    private function changeLevel(int $levelId, string $delta): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE stock_levels SET QUANTITY = QUANTITY + ?, ACTUAL_DATE = CURDATE() WHERE ID = ? AND QUANTITY + ? >= 0'
        );
        $stmt->execute([$delta, $levelId, $delta]);
        if ($stmt->rowCount() === 0 && Decimal::compare($delta, '0.00000') !== 0) {
            throw new HttpException(422, 'insufficient_lot_stock', 'Недостаточно агрегированного остатка');
        }
    }

    private function createLot(
        int $levelId,
        string $code,
        string $qty,
        ?string $expiresAt,
        string $type,
        ?int $requestId,
        ?string $idempotencyKey = null
    ): void {
        if (trim($code) === '') {
            throw new HttpException(422, 'lot_code_required', 'LOT_CODE обязателен');
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO stock_lots (STOCK_LEVEL_ID, NAME_SID, LOT_CODE, QUANTITY_AVAILABLE, QUANTITY_RESERVED, RECEIVED_AT, EXPIRES_AT)
             SELECT l.ID, l.NAME_SID, ?, ?, 0.00000, NOW(), ? FROM stock_levels l WHERE l.ID = ?'
        );
        $stmt->execute([$code, $qty, $expiresAt, $levelId]);
        $lotId = (int) $pdo->lastInsertId();
        $this->movement($lotId, $levelId, $type, $qty, '0.00000', $qty, '0.00000', '0.00000', $requestId, $idempotencyKey);
    }

    private function updateLot(int $lotId, string $available, ?string $reserved): void
    {
        if ($reserved === null) {
            Database::pdo()->prepare('UPDATE stock_lots SET QUANTITY_AVAILABLE = ? WHERE ID = ?')
                ->execute([$available, $lotId]);
        } else {
            Database::pdo()->prepare(
                'UPDATE stock_lots SET QUANTITY_AVAILABLE = ?, QUANTITY_RESERVED = ? WHERE ID = ?'
            )->execute([$available, $reserved, $lotId]);
        }
    }

    private function movement(
        int $lotId,
        int $levelId,
        string $type,
        string $qty,
        string $availableBefore,
        string $availableAfter,
        string $reservedBefore,
        string $reservedAfter,
        ?int $requestId,
        ?string $idempotencyKey = null,
        ?int $reversesId = null
    ): void {
        Database::pdo()->prepare(
            'INSERT INTO stock_lot_movements
             (LOT_ID, STOCK_LEVEL_ID, TYPE, QUANTITY, AVAILABLE_BEFORE, AVAILABLE_AFTER,
              RESERVED_BEFORE, RESERVED_AFTER, REQUEST_ID, IDEMPOTENCY_KEY, REVERSES_ID)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $lotId, $levelId, $type, $qty, $availableBefore, $availableAfter,
            $reservedBefore, $reservedAfter, $requestId, $idempotencyKey, $reversesId,
        ]);
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = (string) $value;

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : null;
    }
}
