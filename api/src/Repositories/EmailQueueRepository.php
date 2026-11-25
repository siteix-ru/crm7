<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class EmailQueueRepository
{
    public function add(
        string $toEmail,
        string $subject,
        string $body,
        ?string $attachmentsJson = null,
        string $channel = 'email',
        ?string $eventKey = null,
        ?string $contextJson = null,
        ?string $nextAttemptAt = null
    ): int {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO email_queue
                (to_email, subject, body, attachments_json, channel, event_key, context_json, next_attempt_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$toEmail, $subject, $body, $attachmentsJson, $channel, $eventKey, $contextJson, $nextAttemptAt]);

        return (int) $pdo->lastInsertId();
    }

    public function claim(int $limit, string $channel = 'email'): array
    {
        return $this->claimChannels($limit, [$channel]);
    }

    /** Shared claim path for messenger channels; email keeps its single-channel call. */
    public function claimChannels(int $limit, array $channels): array
    {
        $channels = array_values(array_unique(array_filter($channels, 'is_string')));
        if ($channels === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($channels), '?'));
        $token = bin2hex(random_bytes(8));

        $stmt = Database::pdo()->prepare(
            'UPDATE email_queue
             SET status = \'processing\', claimed_at = NOW(), claim_token = ?
             WHERE status = \'pending\' AND attempts < 3 AND channel IN (' . $placeholders . ')
               AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
             ORDER BY created_at ASC, ID ASC
             LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute(array_merge([$token], $channels));

        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, to_email, subject, body, attempts, attachments_json, channel, event_key, context_json
             FROM email_queue
             WHERE status = \'processing\' AND claim_token = ?'
        );
        $stmt->execute([$token]);

        return $stmt->fetchAll() ?: [];
    }

    public function releaseStale(int $minutes = 10): int
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE email_queue SET status = \'pending\', claimed_at = NULL, claim_token = NULL
             WHERE status = \'processing\' AND claimed_at IS NOT NULL
               AND claimed_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $stmt->execute([max(1, $minutes)]);

        return $stmt->rowCount();
    }

    public function markSent(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE email_queue SET status = \'sent\', sent_at = NOW(), attempts = attempts + 1,
             last_error = NULL, next_attempt_at = NULL, claimed_at = NULL, claim_token = NULL
             WHERE ID = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * Messenger retry path: keep the existing 3-attempt limit but defer the next
     * claim until next_attempt_at. Error text is only a normalized code.
     */
    public function scheduleRetry(int $id, string $errorCode, int $delaySeconds): void
    {
        $delay = max(1, min(86400, $delaySeconds));
        $stmt = Database::pdo()->prepare(
            'UPDATE email_queue
             SET attempts = attempts + 1, last_error = ?,
                 status = IF(attempts + 1 >= 3, \'failed\', \'pending\'),
                 next_attempt_at = IF(attempts + 1 >= 3, NULL, DATE_ADD(NOW(), INTERVAL ? SECOND)),
                 claimed_at = NULL, claim_token = NULL
             WHERE ID = ?'
        );
        $stmt->execute([mb_substr($errorCode, 0, 64), $delay, $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE email_queue
             SET attempts = attempts + 1, last_error = ?, status = IF(attempts + 1 >= 3, \'failed\', \'pending\'), claimed_at = NULL, claim_token = NULL
             WHERE ID = ?'
        );
        $stmt->execute([mb_substr($error, 0, 500), $id]);
    }

    public function clearAll(): int
    {
        return (int) Database::pdo()->exec('DELETE FROM email_queue');
    }

    public function recent(int $limit): array
    {
        $sql = 'SELECT ID AS id, to_email, subject, status, attempts, last_error, created_at, sent_at
                FROM email_queue
                ORDER BY ID DESC
                LIMIT ' . max(1, min(100, $limit));

        return Database::pdo()->query($sql)->fetchAll() ?: [];
    }
}
