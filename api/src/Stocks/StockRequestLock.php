<?php

declare(strict_types=1);

namespace App\Stocks;

use App\Core\Database;
use App\Http\HttpException;

/**
 * Single advisory lock for every operation that changes one request's stock
 * reservations (apply / release / issue / reversal).
 *
 * GET_LOCK is connection-scoped and is NOT a substitute for SELECT ... FOR UPDATE;
 * row locks are still taken by the services. The lock only serializes the logical
 * operation on a request and prevents TOCTOU between reading reservations,
 * checking rights and writing balances.
 */
final class StockRequestLock
{
    private const PREFIX = 'crm7:stock_request:';
    private const TIMEOUT_SECONDS = 30;

    public static function lock(int $requestId): void
    {
        $stmt = Database::pdo()->prepare('SELECT GET_LOCK(?, ' . self::TIMEOUT_SECONDS . ')');
        $stmt->execute([self::PREFIX . $requestId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new HttpException(409, 'stock_operation_conflict', 'Складская операция уже выполняется');
        }
    }

    public static function unlock(int $requestId): void
    {
        Database::pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([self::PREFIX . $requestId]);
    }
}
