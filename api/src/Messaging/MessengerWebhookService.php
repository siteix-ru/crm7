<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Repositories\MessengerLinkRepository;

/** Parses verified platform events into one-time account bindings. */
final class MessengerWebhookService
{
    public function __construct(
        private readonly MessengerLinkService $bindings = new MessengerLinkService(),
        private readonly MessengerLinkRepository $links = new MessengerLinkRepository()
    ) {
    }

    public function handleMax(array $update): bool
    {
        $type = (string) ($update['update_type'] ?? '');
        $user = is_array($update['user'] ?? null) ? $update['user'] : [];
        if (!empty($user['is_bot'])) {
            return false;
        }
        $externalId = $this->digits($user['user_id'] ?? null);
        if ($externalId === null) {
            return false;
        }
        $chatId = $this->digits($update['chat_id'] ?? ($update['message']['recipient']['chat_id'] ?? null));

        $code = '';
        if ($type === 'bot_started') {
            $code = (string) ($update['payload'] ?? '');
        } elseif ($type === 'message_created') {
            $text = (string) ($update['message']['body']['text']
                ?? $update['message']['text'] ?? '');
            $code = $this->extractCode($text);
        }
        if ($code === '') {
            return false;
        }

        $eventId = $this->eventId('max', $update, [
            $type,
            $externalId,
            $code,
            (string) ($update['timestamp'] ?? ''),
        ]);
        if (!$this->links->recordWebhookEvent('max', $eventId, $eventId)) {
            return false;
        }

        return $this->bindings->consumeBindCode($code, 'max', $externalId, $chatId);
    }

    public function handleTelegram(array $update): bool
    {
        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        if (!empty($from['is_bot'])) {
            return false;
        }
        $externalId = $this->digits($from['id'] ?? null);
        if ($externalId === null) {
            return false;
        }
        $chatId = $this->digits($message['chat']['id'] ?? null);
        $code = $this->extractCode((string) ($message['text'] ?? ''));
        if ($code === '') {
            return false;
        }

        $eventId = $this->eventId('telegram', $update, [
            (string) ($update['update_id'] ?? ''),
            $externalId,
            $code,
        ]);
        if (!$this->links->recordWebhookEvent('telegram', $eventId, $eventId)) {
            return false;
        }

        return $this->bindings->consumeBindCode($code, 'telegram', $externalId, $chatId);
    }

    private function extractCode(string $text): string
    {
        if (preg_match('#^/(?:start|bind)\s+([A-Za-z0-9_-]{4,32})#', trim($text), $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    private function digits(mixed $value): ?string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^\d{1,20}$/', $value) === 1 ? $value : null;
    }

    private function eventId(string $provider, array $update, array $parts): string
    {
        if ($provider === 'telegram' && isset($update['update_id'])) {
            return 'tg-' . (string) $update['update_id'];
        }

        return hash('sha256', implode('|', $parts));
    }
}
