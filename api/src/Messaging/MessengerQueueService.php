<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Repositories\EmailQueueRepository;
use App\Repositories\MessengerLinkRepository;
use App\Repositories\MessengerSettingsRepository;
use PDOException;

/** Shared queue worker for MAX and Telegram. Email keeps its own worker. */
final class MessengerQueueService implements MessengerQueue
{
    /** Predictable order: the faster channel is served first. */
    private const CHANNEL_ORDER = ['telegram', 'max'];

    /** A single channel cannot consume the whole cron tick. */
    private const MAX_PER_CHANNEL = 10;

    private const DEFAULT_BACKOFF_SECONDS = 5;
    private const MAX_BACKOFF_SECONDS = 300;

    private readonly ChannelRegistry $registry;

    public function __construct(
        private readonly EmailQueueRepository $queue = new EmailQueueRepository(),
        private readonly MessengerLinkRepository $links = new MessengerLinkRepository(),
        private readonly MessengerSettingsRepository $settings = new MessengerSettingsRepository(),
        ?ChannelRegistry $registry = null,
        private readonly MessengerRateLimiter $limiter = new MessengerRateLimiter()
    ) {
        $this->registry = $registry ?? ChannelRegistry::default();
    }

    /**
     * true  = queued
     * false = duplicate event (idempotent no-op)
     * throws for real database/connection/schema failures.
     */
    public function enqueue(int $userId, string $channel, NotificationMessage $message): bool
    {
        $row = $this->settings->get($channel);
        if ($row === null || !(bool) $row['enabled'] || $this->registry->get($channel) === null) {
            return false;
        }
        $link = $this->links->find($userId, $channel);
        if ($link === null) {
            return false;
        }

        $recipient = $channel === 'max'
            ? (string) ($link['external_id'] ?? '')
            : (string) ($link['chat_id'] ?? $link['external_id'] ?? '');
        if ($recipient === '') {
            return false;
        }

        $context = json_encode([
            'user_id' => $userId,
            'channel' => $channel,
            'event_code' => $message->eventCode,
            'title' => $message->title,
            'body' => $message->body,
            'link' => $message->link,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($context === false) {
            return false;
        }

        $eventKey = $channel . ':' . $userId . ':' . $message->eventKey;

        try {
            $this->queue->add(
                $recipient,
                mb_substr($message->title, 0, 255),
                $message->text(),
                null,
                $channel,
                $eventKey,
                $context
            );
        } catch (PDOException $e) {
            // Only a unique-key violation is a normal duplicate. MySQL/MariaDB
            // reports 1062; SQLSTATE 23000 alone is not enough.
            $driverCode = $e->errorInfo[1] ?? null;
            if ($driverCode === 1062 || str_contains($e->getMessage(), '1062')) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    public function processQueue(int $limit = 20): array
    {
        $released = $this->queue->releaseStale();
        $sent = 0;
        $failed = 0;
        $errors = [];
        $perChannel = max(1, min(self::MAX_PER_CHANNEL, $limit));

        // One channel at a time: a slow/failing MAX cannot delay Telegram.
        foreach (self::CHANNEL_ORDER as $channel) {
            try {
                $items = $this->queue->claimChannels($perChannel, [$channel]);
            } catch (\Throwable $e) {
                $errors[] = ['channel' => $channel, 'error' => 'claim_failed'];
                continue;
            }

            foreach ($items as $item) {
                $id = (int) $item['id'];

                try {
                    $row = $this->settings->get($channel);
                    if ($row === null || !(bool) $row['enabled']) {
                        $this->queue->markFailed($id, 'channel_disabled');
                        $failed++;
                        $errors[] = ['id' => $id, 'error' => 'channel_disabled'];
                        continue;
                    }

                    $provider = $this->registry->get($channel);
                    if ($provider === null) {
                        $this->queue->markFailed($id, 'unknown_channel');
                        $failed++;
                        $errors[] = ['id' => $id, 'error' => 'unknown_channel'];
                        continue;
                    }

                    $context = json_decode((string) ($item['context_json'] ?? ''), true);
                    $context = is_array($context) ? $context : [];
                    $recipient = (string) $item['to_email'];
                    $userId = (int) ($context['user_id'] ?? 0);
                    $message = new NotificationMessage(
                        $userId,
                        (string) ($context['event_code'] ?? ''),
                        (string) ($context['title'] ?? $item['subject']),
                        (string) ($context['body'] ?? ''),
                        (string) ($context['link'] ?? ''),
                        (string) ($item['event_key'] ?? '')
                    );

                    $wait = $this->limiter->waitSeconds($channel, $recipient, $userId > 0 ? $userId : null);
                    if ($wait > 0) {
                        usleep((int) min(1000000, $wait * 1000000));
                    }

                    $result = $provider->send($recipient, $message);
                    if ($result->ok()) {
                        $this->queue->markSent($id);
                        $this->limiter->markSent($channel, $recipient, $userId > 0 ? $userId : null);
                        $sent++;
                        continue;
                    }

                    $code = $result->errorCode ?? 'delivery_failed';
                    $delay = $this->retryDelay($code, $result->retryAfterSeconds, (int) ($item['attempts'] ?? 0));
                    $this->queue->scheduleRetry($id, $code, $delay);
                    $failed++;
                    $errors[] = ['id' => $id, 'error' => $code];
                } catch (\Throwable $e) {
                    // Only a normalized code is stored; exception text is never logged.
                    $this->queue->markFailed((int) $id, 'provider_exception');
                    $failed++;
                    $errors[] = ['id' => $id, 'error' => 'provider_exception'];
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'released' => $released, 'errors' => $errors];
    }

    /** Suggested server delay wins; otherwise controlled exponential backoff. */
    private function retryDelay(string $errorCode, ?int $suggested, int $attempts): int
    {
        if ($suggested !== null && $suggested > 0) {
            return max(1, min(86400, $suggested));
        }

        $backoff = self::DEFAULT_BACKOFF_SECONDS * (2 ** max(0, $attempts));

        return min(self::MAX_BACKOFF_SECONDS, $backoff);
    }
}
