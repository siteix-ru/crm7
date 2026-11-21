<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Http\HttpException;
use PDO;
use PDOException;
use Throwable;

/**
 * Хранилище операций импорта остатков с идемпотентностью по (user_id, token).
 *
 * Все методы используют один статический PDO приложения (App\Core\Database::pdo()),
 * поэтому транзакции, блокировки строк и GET_LOCK работают на одном соединении.
 */
final class StockImportIdempotencyRepository
{
    public const STATE_RESERVED = 'reserved';

    public const STATE_PROCESSING = 'processing';

    public const STATE_UNRESOLVED = 'unresolved';

    public const STATE_COMMITTED = 'committed';

    public const STATE_DONE = 'done';

    public const STATE_FAILED = 'failed';

    public const STATE_ABORTED = 'aborted';

    /** @return array{operation: array, attempt: array, job_id: int, replay: bool} */
    public function reserve(
        int $userId,
        ?string $token,
        string $payloadHash,
        string $fileName,
        string $actualDate,
        bool $legacy = false
    ): array {
        $pdo = Database::pdo();
        $isLegacy = $legacy || $token === null;
        $effectiveToken = $token ?? self::generateToken();

        $pdo->beginTransaction();
        $jobId = 0;

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO stock_import_jobs (FILE_NAME, ACTUAL_DATE, STATUS, user_id, ATTEMPT_ID, GENERATION)
                 VALUES (?, ?, \'processing\', ?, NULL, 1)'
            );
            $stmt->execute([$fileName, $actualDate, $userId]);
            $jobId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO stock_import_operations
                    (user_id, IDEMPOTENCY_TOKEN, PAYLOAD_HASH, STATE, GENERATION)
                 VALUES (?, ?, ?, \'reserved\', 1)'
            );
            $stmt->execute([$userId, $effectiveToken, $payloadHash]);
            $operationId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO stock_import_attempts
                    (operation_id, attempt_no, generation, JOB_ID, STATE)
                 VALUES (?, 1, 1, ?, \'reserved\')'
            );
            $stmt->execute([$operationId, $jobId]);
            $attemptId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'UPDATE stock_import_jobs SET ATTEMPT_ID = ?
                  WHERE ID = ? AND ATTEMPT_ID IS NULL AND GENERATION = 1'
            );
            $stmt->execute([$attemptId, $jobId]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('job link failed');
            }

            $stmt = $pdo->prepare(
                'UPDATE stock_import_operations SET CURRENT_ATTEMPT_ID = ?
                  WHERE ID = ? AND GENERATION = 1 AND CURRENT_ATTEMPT_ID IS NULL'
            );
            $stmt->execute([$attemptId, $operationId]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('operation link failed');
            }

            $pdo->commit();

            return [
                'operation' => ['ID' => $operationId, 'GENERATION' => 1, 'STATE' => self::STATE_RESERVED],
                'attempt' => ['ID' => $attemptId, 'GENERATION' => 1, 'STATE' => self::STATE_RESERVED],
                'job_id' => $jobId,
                'replay' => false,
                'token' => $effectiveToken,
                'legacy' => $isLegacy,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                }
            }

            if ($exception instanceof PDOException && self::isDuplicateKey($exception)) {
                return $this->resolveDuplicate($userId, $effectiveToken, $payloadHash, $isLegacy);
            }

            throw $exception;
        }
    }

    /** @return array{operation: array, attempt: array, job_id: int, replay: bool} */
    private function resolveDuplicate(int $userId, string $token, string $payloadHash, bool $legacy): array
    {
        // После rollback читаем свежим запросом на primary; UNIQUE является арбитром гонки.
        $row = $this->findByToken($userId, $token);
        if ($row === null) {
            throw new HttpException(503, 'import_unavailable', 'Импорт временно недоступен. Повторите попытку позже.');
        }

        if ((string) ($row['operation']['PAYLOAD_HASH'] ?? '') !== $payloadHash) {
            throw new HttpException(409, 'idempotency_conflict', 'Этот ключ уже используется другим импортом.');
        }

        $state = (string) ($row['operation']['STATE'] ?? 'reserved');
        if (in_array($state, [self::STATE_COMMITTED, self::STATE_DONE], true)) {
            $response = $this->decodeResponse(
                $row['attempt']['response'] ?? $row['attempt']['RESPONSE_JSON'] ?? null
            );
            if ($response === null) {
                throw new HttpException(409, 'result_unavailable', 'Результат импорта недоступен.');
            }
            $response['replayed'] = true;
            $response['operation_id'] = (int) $row['operation']['ID'];
            $response['state'] = $state;

            return [
                'operation' => $row['operation'],
                'attempt' => $row['attempt'],
                'job_id' => (int) ($row['attempt']['job_id'] ?? $row['attempt']['JOB_ID'] ?? 0),
                'replay' => true,
                'response' => $response,
                'token' => $token,
                'legacy' => $legacy,
            ];
        }

        if ($state === self::STATE_FAILED) {
            // Повтор тот же токен: новый attempt создаётся отдельной операцией retry().
            return [
                'operation' => $row['operation'],
                'attempt' => $row['attempt'],
                'job_id' => (int) ($row['attempt']['job_id'] ?? 0),
                'replay' => false,
                'retry' => true,
                'token' => $token,
                'legacy' => $legacy,
            ];
        }

        if ($state === self::STATE_UNRESOLVED) {
            throw new HttpException(409, 'result_unavailable', 'Результат предыдущей попытки не определён. Обратитесь к администратору.');
        }

        // reserved / processing — импорт ещё выполняется.
        throw new HttpException(409, 'import_in_progress', 'Импорт уже выполняется.');
    }

    /** @return array{operation: array, attempt: array}|null */
    public function findByToken(int $userId, string $token): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT o.*, a.ID AS a_id, a.operation_id AS a_operation_id, a.attempt_no,
                    a.generation AS a_generation, a.JOB_ID AS a_job_id, a.STATE AS a_state,
                    a.COMMIT_MARKER AS a_commit_marker, a.RESPONSE_JSON AS a_response,
                    a.ERROR_CODE AS a_error, a.started_at AS a_started, a.finished_at AS a_finished
               FROM stock_import_operations o
               LEFT JOIN stock_import_attempts a ON a.ID = o.CURRENT_ATTEMPT_ID
              WHERE o.user_id = ? AND o.IDEMPOTENCY_TOKEN = ? LIMIT 1'
        );
        $stmt->execute([$userId, $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->splitRow($row);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $operationId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT o.*, a.ID AS a_id, a.operation_id AS a_operation_id, a.attempt_no,
                    a.generation AS a_generation, a.JOB_ID AS a_job_id, a.STATE AS a_state,
                    a.COMMIT_MARKER AS a_commit_marker, a.RESPONSE_JSON AS a_response,
                    a.ERROR_CODE AS a_error, a.started_at AS a_started, a.finished_at AS a_finished
               FROM stock_import_operations o
               LEFT JOIN stock_import_attempts a ON a.ID = o.CURRENT_ATTEMPT_ID
              WHERE o.ID = ? LIMIT 1'
        );
        $stmt->execute([$operationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->splitRow($row);
    }

    /**
     * Блокирует operation и текущую attempt, проверяет инварианты и generation.
     * Возвращает null, если fencing не прошёл (0 affected rows / расхождение данных).
     *
     * @return array{operation: array, attempt: array}|null
     */
    public function lockForWorker(PDO $pdo, int $operationId, int $attemptId, int $generation, ?string $owner): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_import_operations WHERE ID = ? FOR UPDATE');
        $stmt->execute([$operationId]);
        $operation = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($operation === false) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT * FROM stock_import_attempts WHERE ID = ? FOR UPDATE');
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($attempt === false) {
            return null;
        }

        if ((int) ($operation['CURRENT_ATTEMPT_ID'] ?? 0) !== $attemptId
            || (int) ($attempt['operation_id'] ?? 0) !== $operationId
            || (int) ($operation['GENERATION'] ?? 0) !== $generation
            || (int) ($attempt['generation'] ?? 0) !== $generation) {
            return null;
        }

        if (!in_array((string) $operation['STATE'], [self::STATE_RESERVED, self::STATE_PROCESSING], true)
            || !in_array((string) $attempt['STATE'], [self::STATE_RESERVED, self::STATE_PROCESSING], true)) {
            return null;
        }

        $currentOwner = trim((string) ($operation['OWNER_ID'] ?? ''));
        if ($owner !== null && $currentOwner !== '' && $currentOwner !== $owner) {
            return null;
        }

        return ['operation' => $operation, 'attempt' => $attempt];
    }

    /** Условная активация: 1 строка при успехе, 0 при устаревшей generation. */
    public function activate(PDO $pdo, int $operationId, int $attemptId, int $generation, string $owner, int $leaseSeconds): int
    {
        $stmt = $pdo->prepare(
            'UPDATE stock_import_operations
                SET STATE = \'processing\', OWNER_ID = ?, LEASE_EXPIRES_AT = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE ID = ? AND GENERATION = ? AND CURRENT_ATTEMPT_ID = ?
                AND STATE IN (\'reserved\',\'processing\')'
        );
        $stmt->execute([$owner, $leaseSeconds, $operationId, $generation, $attemptId]);

        if ($stmt->rowCount() !== 1) {
            return 0;
        }

        $stmt = $pdo->prepare(
            'UPDATE stock_import_attempts SET STATE = \'processing\', started_at = NOW()
              WHERE ID = ? AND operation_id = ? AND generation = ? AND STATE IN (\'reserved\',\'processing\')'
        );
        $stmt->execute([$attemptId, $operationId, $generation]);

        return $stmt->rowCount() === 1 ? 1 : 0;
    }

    /**
     * Фиксирует marker/response/attempt/operation внутри транзакции остатков.
     * Возвращает false при устаревшей generation (0 affected rows).
     */
    public function markCommitted(
        PDO $pdo,
        int $operationId,
        int $attemptId,
        int $generation,
        string $responseJson
    ): bool {
        $stmt = $pdo->prepare(
            'UPDATE stock_import_attempts
                SET STATE = \'committed\', COMMIT_MARKER = NOW(), RESPONSE_JSON = ?, finished_at = NOW()
              WHERE ID = ? AND operation_id = ? AND generation = ? AND STATE = \'processing\'
                AND COMMIT_MARKER IS NULL'
        );
        $stmt->execute([$responseJson, $attemptId, $operationId, $generation]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }

        $stmt = $pdo->prepare(
            'UPDATE stock_import_operations
                SET STATE = \'committed\', RESPONSE_JSON = ?, completed_at = NOW()
              WHERE ID = ? AND GENERATION = ? AND CURRENT_ATTEMPT_ID = ? AND STATE = \'processing\''
        );
        $stmt->execute([$responseJson, $operationId, $generation, $attemptId]);

        return $stmt->rowCount() === 1;
    }

    /** Помечает операцию/попытку как failed после доказанного rollback до COMMIT. */
    public function markFailed(
        PDO $pdo,
        int $operationId,
        int $attemptId,
        int $generation,
        string $errorCode
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE stock_import_attempts
                SET STATE = \'failed\', ERROR_CODE = ?, finished_at = NOW()
              WHERE ID = ? AND operation_id = ? AND generation = ? AND COMMIT_MARKER IS NULL
                AND STATE IN (\'reserved\',\'processing\')'
        );
        $stmt->execute([$errorCode, $attemptId, $operationId, $generation]);

        $stmt = $pdo->prepare(
            'UPDATE stock_import_operations
                SET STATE = \'failed\', failed_at = NOW()
              WHERE ID = ? AND GENERATION = ? AND CURRENT_ATTEMPT_ID = ?
                AND STATE IN (\'reserved\',\'processing\',\'unresolved\')'
        );
        $stmt->execute([$operationId, $generation, $attemptId]);
    }

    /**
     * Отказ до активации (GET_LOCK=0/NULL, устаревший fencing).
     * Терминализирует только что созданную резервацию, чтобы повтор с тем же
     * токеном прошёл через retry() и получил новую generation.
     */
    public function abandonReservation(
        int $operationId,
        int $attemptId,
        int $generation,
        string $errorCode
    ): void {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $this->markFailed($pdo, $operationId, $attemptId, $generation, $errorCode);

            $stmt = $pdo->prepare('SELECT JOB_ID FROM stock_import_attempts WHERE ID = ? AND operation_id = ?');
            $stmt->execute([$attemptId, $operationId]);
            $jobId = (int) $stmt->fetchColumn();

            // markFailed переводит operation в failed — этого достаточно для retry().
            // job обновляем отдельно по его собственному идентификатору.
            if ($jobId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE stock_import_jobs
                        SET STATUS = \'failed\', ERRORS = ?, finished_at = NOW()
                      WHERE ID = ? AND ATTEMPT_ID = ? AND GENERATION = ?
                        AND STATUS IN (\'processing\',\'unresolved\')'
                );
                $stmt->execute(['Импорт не начат.', $jobId, $attemptId, $generation]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                }
            }
            error_log('stocks.import: abandonReservation failed: ' . $exception->getMessage());
        }
    }

    /**
     * Восстановление «зависшей» операции без записи остатков.
     *
     * Блокирует строки в порядке operation → attempt → job, проверяет marker
     * и терминализирует попытку:
     *   - marker есть → транзакция остатков зафиксирована: committed (при
     *     наличии RESPONSE_JSON → done), attempt committed, job done;
     *   - marker нет → откат не доказан (lease не является доказательством):
     *     operation → unresolved, attempt → aborted, job → unresolved.
     * Новые attempt/job создаёт последующий import() через retry(), только
     * после того как оператор подтвердит завершение старой транзакции и
     * вызовет forceFail(). GET_LOCK в recovery не берётся.
     *
     * @return array<string, mixed>|null
     */
    public function recover(int $operationId): ?array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM stock_import_operations WHERE ID = ? FOR UPDATE');
            $stmt->execute([$operationId]);
            $operation = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($operation === false) {
                $pdo->rollBack();

                return null;
            }

            $attemptId = (int) $operation['CURRENT_ATTEMPT_ID'];
            $stmt = $pdo->prepare('SELECT * FROM stock_import_attempts WHERE ID = ? FOR UPDATE');
            $stmt->execute([$attemptId]);
            $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($attempt === false) {
                $pdo->rollBack();

                return null;
            }

            $jobId = (int) $attempt['JOB_ID'];
            $stmt = $pdo->prepare('SELECT * FROM stock_import_jobs WHERE ID = ? FOR UPDATE');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            $generation = (int) $operation['GENERATION'];
            $state = (string) $operation['STATE'];
            $terminal = [self::STATE_COMMITTED, self::STATE_DONE, self::STATE_FAILED, self::STATE_UNRESOLVED];

            if (in_array($state, $terminal, true)) {
                $pdo->rollBack();

                return [
                    'operation_id' => $operationId,
                    'action' => 'noop',
                    'state' => $state,
                ];
            }

            $marker = $attempt['COMMIT_MARKER'] !== null;

            if ($marker) {
                $hasResponse = is_string($attempt['RESPONSE_JSON'] ?? null) && $attempt['RESPONSE_JSON'] !== '';
                $newState = $hasResponse ? self::STATE_DONE : self::STATE_COMMITTED;

                $stmt = $pdo->prepare(
                    'UPDATE stock_import_attempts
                        SET STATE = \'committed\', finished_at = NOW()
                      WHERE ID = ? AND operation_id = ? AND generation = ? AND COMMIT_MARKER IS NOT NULL'
                );
                $stmt->execute([$attemptId, $operationId, $generation]);

                $stmt = $pdo->prepare(
                    'UPDATE stock_import_operations SET STATE = ?, completed_at = NOW()
                      WHERE ID = ? AND GENERATION = ? AND CURRENT_ATTEMPT_ID = ?
                        AND STATE IN (\'reserved\',\'processing\',\'unresolved\')'
                );
                $stmt->execute([$newState, $operationId, $generation, $attemptId]);

                if ($job !== false) {
                    $stmt = $pdo->prepare(
                        'UPDATE stock_import_jobs SET STATUS = \'done\', finished_at = NOW()
                          WHERE ID = ? AND ATTEMPT_ID = ? AND GENERATION = ?
                            AND STATUS IN (\'processing\',\'unresolved\')'
                    );
                    $stmt->execute([$jobId, $attemptId, $generation]);
                }

                $action = 'committed';
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE stock_import_attempts
                        SET STATE = \'aborted\', ERROR_CODE = \'unresolved\', finished_at = NOW()
                      WHERE ID = ? AND operation_id = ? AND generation = ? AND COMMIT_MARKER IS NULL
                        AND STATE IN (\'reserved\',\'processing\')'
                );
                $stmt->execute([$attemptId, $operationId, $generation]);

                $stmt = $pdo->prepare(
                    'UPDATE stock_import_operations SET STATE = \'unresolved\'
                      WHERE ID = ? AND GENERATION = ? AND CURRENT_ATTEMPT_ID = ?
                        AND STATE IN (\'reserved\',\'processing\')'
                );
                $stmt->execute([$operationId, $generation, $attemptId]);

                if ($job !== false) {
                    $stmt = $pdo->prepare(
                        'UPDATE stock_import_jobs SET STATUS = \'unresolved\', finished_at = NOW()
                          WHERE ID = ? AND ATTEMPT_ID = ? AND GENERATION = ?
                            AND STATUS IN (\'processing\',\'unresolved\')'
                    );
                    $stmt->execute([$jobId, $attemptId, $generation]);
                }

                $action = 'unresolved';
            }

            $pdo->commit();

            return [
                'operation_id' => $operationId,
                'action' => $action,
                'state' => $marker ? ($hasResponse ? self::STATE_DONE : self::STATE_COMMITTED) : self::STATE_UNRESOLVED,
                'generation' => $generation,
                'attempt_id' => $attemptId,
                'job_id' => $jobId,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                }
            }
            throw $exception;
        }
    }

    /**
     * Подтверждение администратором, что старая транзакция завершена (откачена).
     * Переводит unresolved → failed, после чего import() с тем же токеном
     * создаст новую generation через retry(). Сторожевые UPDATE гарантируют,
     * что marker всё ещё отсутствует.
     */
    public function forceFail(int $operationId): bool
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM stock_import_operations WHERE ID = ? FOR UPDATE');
            $stmt->execute([$operationId]);
            $operation = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($operation === false || (string) $operation['STATE'] !== self::STATE_UNRESOLVED) {
                $pdo->rollBack();

                return false;
            }

            $attemptId = (int) $operation['CURRENT_ATTEMPT_ID'];
            $generation = (int) $operation['GENERATION'];

            $stmt = $pdo->prepare('SELECT * FROM stock_import_attempts WHERE ID = ? FOR UPDATE');
            $stmt->execute([$attemptId]);
            $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($attempt === false || $attempt['COMMIT_MARKER'] !== null) {
                $pdo->rollBack();

                return false;
            }

            $stmt = $pdo->prepare(
                'UPDATE stock_import_attempts
                    SET STATE = \'failed\', ERROR_CODE = \'force_fail\', finished_at = NOW()
                  WHERE ID = ? AND operation_id = ? AND generation = ? AND COMMIT_MARKER IS NULL'
            );
            $stmt->execute([$attemptId, $operationId, $generation]);

            $stmt = $pdo->prepare(
                'UPDATE stock_import_operations SET STATE = \'failed\', failed_at = NOW()
                  WHERE ID = ? AND GENERATION = ? AND CURRENT_ATTEMPT_ID = ? AND STATE = \'unresolved\''
            );
            $updated = $stmt->execute([$operationId, $generation, $attemptId]) && $stmt->rowCount() === 1;

            $pdo->commit();

            return $updated;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                }
            }
            throw $exception;
        }
    }

    /** Обёртка markFailed в отдельной транзакции (после rollback остатков). */
    public function markFailedAfterRollback(
        int $operationId,
        int $attemptId,
        int $generation,
        string $errorCode
    ): void {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $this->markFailed($pdo, $operationId, $attemptId, $generation, $errorCode);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                }
            }
            error_log('stocks.import: markFailed failed: ' . $exception->getMessage());
        }
    }

    /**
     * Retry после доказанного rollback: новая generation/attempt/job в одной транзакции.
     *
     * @return array{operation: array, attempt: array, job_id: int}|null
     */
    public function retry(int $userId, string $token, string $payloadHash, string $fileName, string $actualDate): ?array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM stock_import_operations
                  WHERE user_id = ? AND IDEMPOTENCY_TOKEN = ? FOR UPDATE'
            );
            $stmt->execute([$userId, $token]);
            $operation = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($operation === false
                || (string) ($operation['PAYLOAD_HASH'] ?? '') !== $payloadHash
                || (string) ($operation['STATE'] ?? '') !== self::STATE_FAILED) {
                $pdo->rollBack();

                return null;
            }

            $oldAttemptId = (int) $operation['CURRENT_ATTEMPT_ID'];
            $generation = (int) $operation['GENERATION'] + 1;

            $stmt = $pdo->prepare(
                'UPDATE stock_import_attempts
                    SET STATE = \'aborted\', finished_at = NOW()
                  WHERE ID = ? AND operation_id = ? AND generation = ? AND COMMIT_MARKER IS NULL'
            );
            $stmt->execute([$oldAttemptId, (int) $operation['ID'], (int) $operation['GENERATION']]);

            $stmt = $pdo->prepare(
                'INSERT INTO stock_import_jobs (FILE_NAME, ACTUAL_DATE, STATUS, user_id, ATTEMPT_ID, GENERATION)
                 VALUES (?, ?, \'processing\', ?, NULL, ?)'
            );
            $stmt->execute([$fileName, $actualDate, $userId, $generation]);
            $jobId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO stock_import_attempts (operation_id, attempt_no, generation, JOB_ID, STATE)
                 VALUES (?, ?, ?, ?, \'reserved\')'
            );
            $stmt->execute([(int) $operation['ID'], $generation, $generation, $jobId]);
            $attemptId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'UPDATE stock_import_jobs SET ATTEMPT_ID = ?
                  WHERE ID = ? AND ATTEMPT_ID IS NULL AND GENERATION = ?'
            );
            $stmt->execute([$attemptId, $jobId, $generation]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('retry job link failed');
            }

            $stmt = $pdo->prepare(
                'UPDATE stock_import_operations
                    SET GENERATION = ?, CURRENT_ATTEMPT_ID = ?, STATE = \'reserved\', failed_at = NULL,
                        OWNER_ID = NULL, LEASE_EXPIRES_AT = NULL
                  WHERE ID = ? AND GENERATION = ? AND STATE = \'failed\''
            );
            $stmt->execute([$generation, $attemptId, (int) $operation['ID'], (int) $operation['GENERATION']]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('retry generation bump failed');
            }

            $pdo->commit();

            return [
                'operation' => ['ID' => (int) $operation['ID'], 'GENERATION' => $generation, 'STATE' => self::STATE_RESERVED],
                'attempt' => ['ID' => $attemptId, 'GENERATION' => $generation, 'STATE' => self::STATE_RESERVED],
                'job_id' => $jobId,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                }
            }
            error_log('stocks.import: retry reservation failed: ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * Ограниченная очистка TTL-токенов только для терминальных операций.
     * Возвращает количество очищенных токенов.
     */
    public function cleanup(int $limit = 100, int $ttlHours = 24): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT o.ID, o.CURRENT_ATTEMPT_ID
               FROM stock_import_operations o
              WHERE o.IDEMPOTENCY_TOKEN IS NOT NULL
                AND o.token_cleared_at IS NULL
                AND o.STATE IN (\'done\',\'committed\',\'failed\')
                AND (
                     ( o.STATE IN (\'done\',\'committed\') AND o.completed_at IS NOT NULL
                       AND o.completed_at < DATE_SUB(NOW(), INTERVAL ? HOUR) )
                     OR ( o.STATE = \'failed\' AND o.failed_at IS NOT NULL
                          AND o.failed_at < DATE_SUB(NOW(), INTERVAL ? HOUR) )
                   )
              ORDER BY o.ID LIMIT ' . max(1, min(1000, $limit))
        );
        $stmt->execute([$ttlHours, $ttlHours]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cleared = 0;
        foreach ($candidates as $candidate) {
            $operationId = (int) $candidate['ID'];
            $attemptId = (int) $candidate['CURRENT_ATTEMPT_ID'];
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT * FROM stock_import_operations WHERE ID = ? FOR UPDATE');
                $stmt->execute([$operationId]);
                $operation = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare('SELECT * FROM stock_import_attempts WHERE ID = ? FOR UPDATE');
                $stmt->execute([$attemptId]);
                $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($operation === false || $attempt === false
                    || (int) ($operation['CURRENT_ATTEMPT_ID'] ?? 0) !== $attemptId
                    || (int) ($attempt['operation_id'] ?? 0) !== $operationId
                    || (int) ($operation['GENERATION'] ?? 0) !== (int) ($attempt['generation'] ?? 0)
                    || !in_array((string) $operation['STATE'], ['done', 'committed', 'failed'], true)) {
                    $pdo->rollBack();
                    continue;
                }

                $valid = false;
                if (in_array((string) $operation['STATE'], ['done', 'committed'], true)) {
                    $valid = $attempt['COMMIT_MARKER'] !== null
                        && is_string($attempt['RESPONSE_JSON'] ?? null)
                        && $attempt['RESPONSE_JSON'] !== ''
                        && json_decode((string) $attempt['RESPONSE_JSON'], true) !== null;
                } else {
                    $valid = $attempt['COMMIT_MARKER'] === null;
                }

                if (!$valid || $operation['IDEMPOTENCY_TOKEN'] === null || $operation['token_cleared_at'] !== null) {
                    $pdo->rollBack();
                    continue;
                }

                $stmt = $pdo->prepare(
                    'UPDATE stock_import_operations
                        SET IDEMPOTENCY_TOKEN = NULL, token_cleared_at = NOW()
                      WHERE ID = ? AND IDEMPOTENCY_TOKEN IS NOT NULL AND token_cleared_at IS NULL
                        AND STATE IN (\'done\',\'committed\',\'failed\')
                        AND CURRENT_ATTEMPT_ID = ?'
                );
                $stmt->execute([$operationId, $attemptId]);
                if ($stmt->rowCount() !== 1) {
                    $pdo->rollBack();
                    continue;
                }
                $pdo->commit();
                $cleared++;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (Throwable) {
                    }
                }
                error_log('stocks.import: cleanup failed: ' . $exception->getMessage());
            }
        }

        return $cleared;
    }

    /** Fenced admin-обновление job (только актуальная generation/attempt). */
    public function finishJob(
        int $jobId,
        int $attemptId,
        int $generation,
        string $status,
        int $total,
        int $imported,
        int $skipped,
        array $errors
    ): int {
        $stmt = Database::pdo()->prepare(
            'UPDATE stock_import_jobs
                SET STATUS = ?, ROWS_TOTAL = ?, ROWS_IMPORTED = ?, ROWS_SKIPPED = ?, ERRORS = ?, finished_at = NOW()
              WHERE ID = ? AND ATTEMPT_ID = ? AND GENERATION = ?
                AND STATUS IN (\'processing\',\'unresolved\')'
        );
        $stmt->execute([
            $status, $total, $imported, $skipped,
            $errors !== [] ? mb_substr(implode("\n", array_slice($errors, 0, 50)), 0, 4000) : null,
            $jobId, $attemptId, $generation,
        ]);

        return $stmt->rowCount();
    }

    /** @return array<string, mixed>|null */
    public function decodeResponse(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function generateToken(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private static function isDuplicateKey(PDOException $exception): bool
    {
        $code = $exception->errorInfo[1] ?? null;

        return $code === 1062 || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }

    /** @return array{operation: array, attempt: array} */
    private function splitRow(array $row): array
    {
        $operation = [];
        $attempt = [];

        foreach ($row as $key => $value) {
            if (str_starts_with((string) $key, 'a_')) {
                $attempt[substr((string) $key, 2)] = $value;
            } else {
                $operation[$key] = $value;
            }
        }

        return ['operation' => $operation, 'attempt' => $attempt];
    }
}
