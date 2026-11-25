<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Repositories\MessengerLinkRepository;

/** MAX allows at most two messages per second into one dialog. */
final class MessengerRateLimiter
{
    private const MIN_INTERVAL_MS = 550;

    /** @var array<string,float> */
    private array $memory = [];

    public function __construct(private readonly MessengerLinkRepository $links = new MessengerLinkRepository())
    {
    }

    /** Returns the number of seconds the caller must wait before sending. */
    public function waitSeconds(string $channel, string $recipient, ?int $userId = null): float
    {
        $now = microtime(true);
        $key = $channel . ':' . $recipient;
        $last = $this->memory[$key] ?? null;

        if ($last === null && $userId !== null) {
            $link = $this->links->find($userId, $channel);
            $stored = $link['last_sent_at'] ?? null;
            if (is_string($stored) && $stored !== '') {
                $last = strtotime($stored) ?: null;
            }
        }

        if ($last === null) {
            return 0.0;
        }

        $wait = self::MIN_INTERVAL_MS / 1000 - ($now - $last);

        return $wait > 0 ? $wait : 0.0;
    }

    public function markSent(string $channel, string $recipient, ?int $userId = null): void
    {
        $this->memory[$channel . ':' . $recipient] = microtime(true);

        if ($userId !== null) {
            $stmt = \App\Core\Database::pdo()->prepare(
                'UPDATE user_messenger_links SET last_sent_at = NOW() WHERE user_id = ? AND channel = ?'
            );
            $stmt->execute([$userId, $channel]);
        }
    }
}
