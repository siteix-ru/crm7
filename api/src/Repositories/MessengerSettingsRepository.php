<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class MessengerSettingsRepository
{
    public const CHANNELS = ['max', 'telegram'];

    public function all(): array
    {
        return Database::pdo()->query('SELECT * FROM messenger_channels ORDER BY channel ASC')->fetchAll() ?: [];
    }

    public function get(string $channel): ?array
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM messenger_channels WHERE channel = ? LIMIT 1');
        $stmt->execute([$channel]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function setEnabled(string $channel, bool $enabled): void
    {
        $stmt = Database::pdo()->prepare('UPDATE messenger_channels SET enabled = ? WHERE channel = ?');
        $stmt->execute([$enabled ? 1 : 0, $channel]);
    }

    /** Null arguments always mean "keep the existing value". */
    public function save(
        string $channel,
        bool $enabled,
        ?string $tokenEncrypted = null,
        ?string $webhookSecretEncrypted = null
    ): void {
        $current = $this->get($channel);
        if ($current === null) {
            return;
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE messenger_channels SET enabled = ?, token_encrypted = ?, webhook_secret_encrypted = ?
             WHERE channel = ?'
        );
        $stmt->execute([
            $enabled ? 1 : 0,
            $tokenEncrypted ?? ($current['token_encrypted'] ?? null),
            $webhookSecretEncrypted ?? ($current['webhook_secret_encrypted'] ?? null),
            $channel,
        ]);
    }

    public function deleteToken(string $channel): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE messenger_channels SET token_encrypted = NULL, enabled = 0,
             webhook_secret_encrypted = NULL, bot_id = NULL, bot_name = NULL,
             last_check_status = NULL, last_check_error = NULL, last_check_at = NULL WHERE channel = ?'
        );
        $stmt->execute([$channel]);

        // Invalidate replay protection state as well: a future token gets a new
        // webhook secret, so old event IDs must not suppress fresh deliveries.
        $stmt = Database::pdo()->prepare('DELETE FROM messenger_webhook_events WHERE provider = ?');
        $stmt->execute([$channel]);
    }

    public function recordCheck(
        string $channel,
        bool $connected,
        ?string $errorCode,
        ?string $botId = null,
        ?string $botName = null
    ): void {
        $stmt = Database::pdo()->prepare(
            'UPDATE messenger_channels SET last_check_at = NOW(), last_check_status = ?,
             last_check_error = ?, bot_id = ?, bot_name = ? WHERE channel = ?'
        );
        $stmt->execute([
            $connected ? 'ok' : 'error',
            $errorCode,
            $botId,
            $botName,
            $channel,
        ]);
    }

    public function webhookSecret(string $channel): ?string
    {
        $row = $this->get($channel);
        $encrypted = $row['webhook_secret_encrypted'] ?? null;

        return is_string($encrypted) && $encrypted !== '' ? $encrypted : null;
    }
}
