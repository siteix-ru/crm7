<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class StockReservationRepository
{
    /**
     * @return array<int, float> stock_level_id => quantity
     */
    public function forRequest(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT stock_level_id, quantity FROM stock_reservations WHERE request_id = ?'
        );
        $stmt->execute([$requestId]);

        $result = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) $row['stock_level_id']] = (float) $row['quantity'];
        }

        return $result;
    }

    /**
     * Reads the latest committed reservation map using an independent connection.
     * The caller must hold the request's named lock to avoid concurrent changes.
     *
     * @return array<int, float> stock_level_id => quantity
     */
    public function forRequestFresh(int $requestId): array
    {
        $stmt = Database::freshPdo()->prepare(
            'SELECT stock_level_id, quantity FROM stock_reservations WHERE request_id = ?'
        );
        $stmt->execute([$requestId]);

        $result = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) $row['stock_level_id']] = (float) $row['quantity'];
        }

        return $result;
    }

    /**
     * @param array<int, float> $map
     */
    public function replace(int $requestId, array $map): void
    {
        $pdo = Database::pdo();

        if ($map === []) {
            $this->deleteForRequest($requestId);

            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO stock_reservations (request_id, stock_level_id, quantity)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
        );

        foreach ($map as $levelId => $quantity) {
            $stmt->execute([$requestId, (int) $levelId, $quantity]);
        }

        $placeholders = implode(',', array_fill(0, count($map), '?'));
        $stmt = $pdo->prepare(
            'DELETE FROM stock_reservations
             WHERE request_id = ? AND stock_level_id NOT IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$requestId], array_map('intval', array_keys($map))));
    }

    public function deleteForRequest(int $requestId): void
    {
        Database::pdo()->prepare('DELETE FROM stock_reservations WHERE request_id = ?')->execute([$requestId]);
    }

    public function levelQuantity(int $levelId): float
    {
        $stmt = Database::pdo()->prepare('SELECT QUANTITY FROM stock_levels WHERE ID = ? LIMIT 1');
        $stmt->execute([$levelId]);

        return (float) ($stmt->fetchColumn() ?: 0.0);
    }

    public function levelExists(int $levelId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM stock_levels WHERE ID = ? LIMIT 1');
        $stmt->execute([$levelId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Serializes concurrent modifications of the same request's reservations.
     * GET_LOCK is connection-scoped and does not take InnoDB gap locks, so
     * unrelated requests never deadlock on the reservation index.
     */
    public function lockRequest(int $requestId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT GET_LOCK(?, 30)');
        $stmt->execute(['crm7:stock_reservation:' . $requestId]);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function unlockRequest(int $requestId): void
    {
        $stmt = Database::pdo()->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute(['crm7:stock_reservation:' . $requestId]);
    }

    /**
     * Atomically applies a stock change only if the resulting balance stays >= 0.
     * Returns false when the level is missing or the condition does not hold.
     */
    public function adjustLevelAtomic(int $levelId, float $delta): bool
    {
        if (abs($delta) < 0.00001) {
            return true;
        }

        // QUANTITY is DECIMAL(20,5): bind a canonical decimal string, never a float.
        $change = number_format($delta, 5, '.', '');
        $stmt = Database::pdo()->prepare(
            'UPDATE stock_levels
                SET QUANTITY = QUANTITY + ?
              WHERE ID = ? AND QUANTITY + ? >= 0'
        );
        $stmt->execute([$change, $levelId, $change]);

        return $stmt->rowCount() > 0;
    }

    public function levelLabel(int $levelId): string
    {
        $stmt = Database::pdo()->prepare('SELECT NAME FROM stock_levels WHERE ID = ? LIMIT 1');
        $stmt->execute([$levelId]);

        $name = $stmt->fetchColumn();

        return $name === false ? ('#' . $levelId) : (string) $name;
    }

    public function adjustLevel(int $levelId, float $delta): void
    {
        if (abs($delta) < 0.00001) {
            return;
        }

        $stmt = Database::pdo()->prepare('UPDATE stock_levels SET QUANTITY = QUANTITY + ? WHERE ID = ?');
        $stmt->execute([$delta, $levelId]);
    }
}
