<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDOException;

final class MessengerLinkRepository
{
    public function forUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, user_id, channel, external_id, chat_id, linked_at, last_sent_at
             FROM user_messenger_links WHERE user_id = ? ORDER BY channel ASC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }

    public function find(int $userId, string $channel): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM user_messenger_links WHERE user_id = ? AND channel = ? LIMIT 1'
        );
        $stmt->execute([$userId, $channel]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findByExternal(string $channel, string $externalId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM user_messenger_links WHERE channel = ? AND external_id = ? LIMIT 1'
        );
        $stmt->execute([$channel, $externalId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** false means the external ID belongs to another user (no silent takeover). */
    public function saveLink(int $userId, string $channel, string $externalId, ?string $chatId): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'UPDATE user_messenger_links SET external_id = ?, chat_id = ?, linked_at = NOW(),
             last_sent_at = NULL WHERE user_id = ? AND channel = ?'
        );
        $stmt->execute([$externalId, $chatId, $userId, $channel]);
        if ($stmt->rowCount() > 0) {
            return true;
        }

        try {
            $insert = $pdo->prepare(
                'INSERT INTO user_messenger_links (user_id, channel, external_id, chat_id, linked_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            $insert->execute([$userId, $channel, $externalId, $chatId]);

            return true;
        } catch (PDOException $e) {
            // Same user created the row concurrently: idempotent success.
            $existing = $this->find($userId, $channel);
            if ($existing !== null && (string) $existing['external_id'] === $externalId) {
                return true;
            }

            return false;
        }
    }

    public function deleteLink(int $userId, string $channel): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM user_messenger_links WHERE user_id = ? AND channel = ?');
        $stmt->execute([$userId, $channel]);

        return $stmt->rowCount() > 0;
    }

    public function saveBindCode(string $hash, int $userId, string $channel, int $ttlSeconds): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO messenger_bind_codes (code_hash, user_id, channel, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), channel = VALUES(channel),
                                    expires_at = VALUES(expires_at), used_at = NULL, attempts = 0'
        );
        $stmt->execute([$hash, $userId, $channel, max(30, $ttlSeconds)]);
    }

    public function findBindCode(string $hash): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM messenger_bind_codes WHERE code_hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function markBindAttempt(string $hash): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE messenger_bind_codes SET attempts = attempts + 1 WHERE code_hash = ?'
        );
        $stmt->execute([$hash]);
    }

    public function useBindCode(string $hash): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE messenger_bind_codes SET used_at = NOW()
             WHERE code_hash = ? AND used_at IS NULL AND attempts <= 5 AND expires_at > NOW()'
        );
        $stmt->execute([$hash]);

        return $stmt->rowCount() > 0;
    }

    /** Return the one-time code to the pool when binding could not be persisted. */
    public function releaseBindCode(string $hash): void
    {
        $stmt = Database::pdo()->prepare('UPDATE messenger_bind_codes SET used_at = NULL WHERE code_hash = ?');
        $stmt->execute([$hash]);
    }

    /** @return true when the event was newly recorded, false on a duplicate. */
    public function recordWebhookEvent(string $provider, string $eventId, string $payloadHash): bool
    {
        $stmt = Database::pdo()->prepare(
            'INSERT IGNORE INTO messenger_webhook_events (provider, event_id, payload_hash)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$provider, $eventId, $payloadHash]);

        return $stmt->rowCount() > 0;
    }

    public function pruneBindCodes(): void
    {
        Database::pdo()->exec('DELETE FROM messenger_bind_codes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
}
