<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Http\HttpException;
use PDOException;

/**
 * Authoritative, domain-scoped idempotency registry for stock lot operations.
 *
 * A claim is inserted in the SAME transaction as the business change. The
 * UNIQUE (OPERATION, SCOPE_TYPE, SCOPE_KEY, IDEMPOTENCY_KEY) index is the
 * arbiter for concurrent duplicates.
 */
final class StockLotIdempotencyRepository
{
    public const OPERATION_ISSUE = 'issue';
    public const OPERATION_REVERSAL = 'reversal';
    public const OPERATION_RECEIPT = 'receipt';

    public const SCOPE_REQUEST = 'request';
    public const SCOPE_ISSUE = 'issue';
    public const SCOPE_LOT = 'lot';

    /**
     * Stable lot identity for a receipt scope.
     *
     * The key is built from the actual stock_lots.ID returned by the same
     * SELECT/INSERT path that uses the column collation (utf8mb4_unicode_ci).
     * PHP string normalization is deliberately NOT used here: it is not
     * equivalent to MariaDB UCA comparison and can both merge distinct lots
     * and split one physical lot into two scopes.
     */
    public static function lotScopeKey(int $levelId, int $lotId): string
    {
        return $levelId . ':' . $lotId;
    }

    /**
     * @return array{status: string, claim_id?: int, result_id?: int|null}
     */
    public function claim(
        string $operation,
        string $scopeType,
        string $scopeKey,
        string $idempotencyKey,
        string $payloadHash
    ): array {
        $pdo = Database::pdo();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO stock_lot_idempotency
                 (OPERATION, SCOPE_TYPE, SCOPE_KEY, IDEMPOTENCY_KEY, PAYLOAD_HASH, RESULT_ID)
                 VALUES (?, ?, ?, ?, ?, NULL)'
            );
            $stmt->execute([$operation, $scopeType, $scopeKey, $idempotencyKey, $payloadHash]);

            return ['status' => 'new', 'claim_id' => (int) $pdo->lastInsertId()];
        } catch (PDOException $e) {
            if (!$this->isScopeKeyConflict($e)) {
                throw $e;
            }

            // A competing transaction may commit just after our duplicate-key
            // error. Re-reading on the same connection keeps this transaction's
            // REPEATABLE READ snapshot and can never see the committed row, so
            // use an independent connection with a short bounded wait instead.
            $row = $this->findCommittedClaim($operation, $scopeType, $scopeKey, $idempotencyKey);
            if ($row === null) {
                throw $e;
            }

            $storedHash = (string) $row['PAYLOAD_HASH'];
            if ($storedHash !== '' && !hash_equals($storedHash, $payloadHash)) {
                throw new HttpException(
                    409,
                    'idempotency_key_reused',
                    'Idempotency key already used with a different payload'
                );
            }

            return [
                'status' => 'applied',
                'result_id' => $row['RESULT_ID'] === null ? null : (int) $row['RESULT_ID'],
            ];
        }
    }

    /**
     * Only a duplicate on the scoped registry index is an idempotency signal.
     * A 23000 from any other UNIQUE index must surface as a DB error instead of
     * being mistaken for a replay of an independent operation.
     */
    private function isScopeKeyConflict(PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            && strpos($e->getMessage(), 'uq_lot_idem_scope') !== false;
    }

    private function findCommittedClaim(
        string $operation,
        string $scopeType,
        string $scopeKey,
        string $idempotencyKey
    ): ?array {
        $fresh = Database::freshPdo();
        $sql = 'SELECT ID, PAYLOAD_HASH, RESULT_ID
                  FROM stock_lot_idempotency
                 WHERE OPERATION = ? AND SCOPE_TYPE = ? AND SCOPE_KEY = ? AND IDEMPOTENCY_KEY = ?
                 LIMIT 1';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $stmt = $fresh->prepare($sql);
            $stmt->execute([$operation, $scopeType, $scopeKey, $idempotencyKey]);
            $row = $stmt->fetch();
            if ($row !== false) {
                return $row;
            }
            usleep(10000);
        }

        return null;
    }

    public function complete(int $claimId, int $resultId): void
    {
        Database::pdo()->prepare('UPDATE stock_lot_idempotency SET RESULT_ID = ? WHERE ID = ?')
            ->execute([$resultId, $claimId]);
    }
}
