<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Decimal;

/**
 * Read side of the stock lot lifecycle.
 *
 * Quantities are cast to DECIMAL(20,5) by the database and then normalized
 * with Decimal::parse so the API always returns canonical strings, never
 * PHP floats. Every query is a prepared statement on Database::pdo().
 */
final class StockLotRepository
{
    /**
     * @param array<string, mixed> $filters item_id, warehouse_id, lot_code,
     *                                      expires_before, expires_after, status
     * @param string[]|null $stockSids null = no warehouse restriction
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function list(array $filters, ?array $stockSids, int $page, int $perPage): array
    {
        $today = (string) ($filters['today'] ?? date('Y-m-d'));
        $params = [];
        $where = $this->where($filters, $stockSids, $today, $params);

        $countStmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
               FROM stock_lots l
               JOIN stock_levels sl ON sl.ID = l.STOCK_LEVEL_ID
               LEFT JOIN nomenclature n ON n.SID = sl.NAME_SID
               JOIN stocks s ON s.SID = sl.STOCK_SID
              WHERE ' . $where
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $limit = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $limit);

        $stmt = Database::pdo()->prepare(
            'SELECT l.ID AS id, l.STOCK_LEVEL_ID AS stock_level_id,
                    COALESCE(n.NAME, sl.NAME) AS item_name,
                    s.ID AS warehouse_id, s.NAME AS warehouse_name,
                    l.LOT_CODE AS lot_code, l.RECEIVED_AT AS received_at, l.EXPIRES_AT AS expires_at,
                    CAST(l.QUANTITY_AVAILABLE AS DECIMAL(20,5)) AS quantity_available,
                    CAST(l.QUANTITY_RESERVED AS DECIMAL(20,5)) AS quantity_reserved
               FROM stock_lots l
               JOIN stock_levels sl ON sl.ID = l.STOCK_LEVEL_ID
               LEFT JOIN nomenclature n ON n.SID = sl.NAME_SID
               JOIN stocks s ON s.SID = sl.STOCK_SID
              WHERE ' . $where . '
              ORDER BY l.ID ASC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $available = Decimal::parse((string) $row['quantity_available']);
            $reserved = Decimal::parse((string) $row['quantity_reserved']);
            $expiresAt = $row['expires_at'] === null ? null : (string) $row['expires_at'];

            $items[] = [
                'id' => (int) $row['id'],
                'stock_level_id' => (int) $row['stock_level_id'],
                'item_name' => (string) ($row['item_name'] ?? ''),
                'warehouse_id' => (int) $row['warehouse_id'],
                'warehouse_name' => (string) $row['warehouse_name'],
                'lot_code' => (string) $row['lot_code'],
                'received_at' => $row['received_at'] === null ? null : (string) $row['received_at'],
                'expires_at' => $expiresAt,
                'quantity_available' => $available,
                'quantity_reserved' => $reserved,
                'quantity_total' => Decimal::add($available, $reserved),
                'status' => $this->status($expiresAt, $available, $reserved, $today),
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /** Same DB collation predicate as StockLotService::ensureLot. */
    public function findLotId(int $levelId, string $lotCode): ?int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID FROM stock_lots WHERE STOCK_LEVEL_ID = ? AND LOT_CODE = ? ORDER BY ID ASC LIMIT 1'
        );
        $stmt->execute([$levelId, $lotCode]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * Distinct levels of a request that have lot reservations.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requestLevels(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.STOCK_LEVEL_ID AS stock_level_id,
                    COALESCE(MAX(n.NAME), MAX(sl.NAME)) AS item_name,
                    MAX(s.NAME) AS warehouse_name,
                    CAST(COALESCE(SUM(r.QUANTITY), 0) AS DECIMAL(20,5)) AS reserved,
                    CAST(COALESCE((
                        SELECT SUM(x.QUANTITY_AVAILABLE) FROM stock_lots x
                         WHERE x.STOCK_LEVEL_ID = l.STOCK_LEVEL_ID
                    ), 0) AS DECIMAL(20,5)) AS available
               FROM stock_lot_reservations r
               JOIN stock_lots l ON l.ID = r.LOT_ID
               JOIN stock_reservations p ON p.ID = r.RESERVATION_ID
               JOIN stock_levels sl ON sl.ID = l.STOCK_LEVEL_ID
               LEFT JOIN nomenclature n ON n.SID = sl.NAME_SID
               JOIN stocks s ON s.SID = sl.STOCK_SID
              WHERE p.request_id = ?
              GROUP BY l.STOCK_LEVEL_ID
              ORDER BY l.STOCK_LEVEL_ID ASC'
        );
        $stmt->execute([$requestId]);

        $rows = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $rows[] = [
                'stock_level_id' => (int) $row['stock_level_id'],
                'item_name' => (string) ($row['item_name'] ?? ''),
                'warehouse_name' => (string) $row['warehouse_name'],
                'reserved' => Decimal::parse((string) $row['reserved']),
                'available' => Decimal::parse((string) $row['available']),
            ];
        }

        return $rows;
    }

    /**
     * Per-lot reservations of a request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requestReservations(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.ID AS lot_id, l.LOT_CODE AS lot_code, l.STOCK_LEVEL_ID AS stock_level_id,
                    CAST(r.QUANTITY AS DECIMAL(20,5)) AS quantity,
                    l.EXPIRES_AT AS expires_at, l.RECEIVED_AT AS received_at
               FROM stock_lot_reservations r
               JOIN stock_lots l ON l.ID = r.LOT_ID
               JOIN stock_reservations p ON p.ID = r.RESERVATION_ID
              WHERE p.request_id = ?
              ORDER BY l.ID ASC'
        );
        $stmt->execute([$requestId]);

        $rows = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $rows[] = [
                'lot_id' => (int) $row['lot_id'],
                'lot_code' => (string) $row['lot_code'],
                'stock_level_id' => (int) $row['stock_level_id'],
                'quantity' => Decimal::parse((string) $row['quantity']),
                'expires_at' => $row['expires_at'] === null ? null : (string) $row['expires_at'],
                'received_at' => $row['received_at'] === null ? null : (string) $row['received_at'],
            ];
        }

        return $rows;
    }

    /**
     * Last 50 issue operations of a request, remaining quantity in PHP.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requestIssues(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, STOCK_LEVEL_ID AS stock_level_id, LOT_ID AS lot_id,
                    CAST(QUANTITY AS DECIMAL(20,5)) AS quantity,
                    CAST(REVERSED_QUANTITY AS DECIMAL(20,5)) AS reversed_quantity,
                    CREATED_AT AS created_at, IDEMPOTENCY_KEY AS idempotency_key
               FROM stock_issue_operations
              WHERE REQUEST_ID = ?
              ORDER BY ID DESC LIMIT 50'
        );
        $stmt->execute([$requestId]);

        $rows = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $quantity = Decimal::parse((string) $row['quantity']);
            $reversed = Decimal::parse((string) $row['reversed_quantity']);

            $rows[] = [
                'id' => (int) $row['id'],
                'stock_level_id' => (int) $row['stock_level_id'],
                'lot_id' => (int) $row['lot_id'],
                'quantity' => $quantity,
                'reversed_quantity' => $reversed,
                'remaining_quantity' => Decimal::sub($quantity, $reversed),
                'created_at' => (string) $row['created_at'],
                'idempotency_key' => $row['idempotency_key'] === null ? null : (string) $row['idempotency_key'],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $filters
     * @param string[]|null $stockSids
     * @param array<int, mixed> $params
     */
    private function where(array $filters, ?array $stockSids, string $today, array &$params): string
    {
        $where = ['1=1'];

        $itemId = (int) ($filters['item_id'] ?? 0);
        if ($itemId > 0) {
            $where[] = 'l.STOCK_LEVEL_ID = ?';
            $params[] = $itemId;
        }

        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $where[] = 's.ID = ?';
            $params[] = $warehouseId;
        }

        $lotCode = trim((string) ($filters['lot_code'] ?? ''));
        if ($lotCode !== '') {
            $where[] = 'LOWER(l.LOT_CODE) LIKE ?';
            $params[] = '%' . mb_strtolower($lotCode) . '%';
        }

        $before = trim((string) ($filters['expires_before'] ?? ''));
        if ($before !== '') {
            $where[] = 'l.EXPIRES_AT < ?';
            $params[] = $before;
        }

        $after = trim((string) ($filters['expires_after'] ?? ''));
        if ($after !== '') {
            $where[] = 'l.EXPIRES_AT > ?';
            $params[] = $after;
        }

        if (is_array($stockSids)) {
            if ($stockSids === []) {
                $where[] = '1=0';
            } else {
                $where[] = 's.SID IN (' . implode(',', array_fill(0, count($stockSids), '?')) . ')';
                foreach ($stockSids as $sid) {
                    $params[] = (string) $sid;
                }
            }
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            // Same derivation as status() below; the API derives the returned
            // status in PHP, this expression only narrows the SQL result set
            // before pagination.
            $where[] = "(CASE
                WHEN l.EXPIRES_AT IS NOT NULL AND l.EXPIRES_AT < ? AND l.QUANTITY_AVAILABLE > 0 THEN 'expired'
                WHEN l.QUANTITY_AVAILABLE = 0 AND l.QUANTITY_RESERVED = 0 THEN 'empty'
                WHEN l.QUANTITY_RESERVED > 0 AND l.QUANTITY_AVAILABLE = 0 THEN 'reserved'
                ELSE 'active' END) = ?";
            $params[] = $today;
            $params[] = $status;
        }

        return implode(' AND ', $where);
    }

    private function status(?string $expiresAt, string $available, string $reserved, string $today): string
    {
        if ($expiresAt !== null && $expiresAt < $today && Decimal::compare($available, '0.00000') > 0) {
            return 'expired';
        }
        if (Decimal::compare($available, '0.00000') === 0 && Decimal::compare($reserved, '0.00000') === 0) {
            return 'empty';
        }
        if (Decimal::compare($reserved, '0.00000') > 0 && Decimal::compare($available, '0.00000') === 0) {
            return 'reserved';
        }

        return 'active';
    }
}
