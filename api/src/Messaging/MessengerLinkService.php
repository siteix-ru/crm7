<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Repositories\MessengerLinkRepository;

final class MessengerLinkService
{
    private const TTL_SECONDS = 300;
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly MessengerLinkRepository $links = new MessengerLinkRepository())
    {
    }

    public function status(int $userId): array
    {
        $byChannel = [];
        foreach ($this->links->forUser($userId) as $row) {
            $byChannel[(string) $row['channel']] = $row;
        }

        $items = [];
        foreach (['max', 'telegram'] as $channel) {
            $link = $byChannel[$channel] ?? null;
            $items[] = [
                'channel' => $channel,
                'connected' => $link !== null,
                'masked_id' => $link !== null ? $this->mask((string) $link['external_id']) : '',
                'linked_at' => $link['linked_at'] ?? null,
            ];
        }

        return ['items' => $items];
    }

    /** The plain code is shown to the owner only; the DB stores its hash. */
    public function startBind(int $userId, string $channel): ?array
    {
        if (!in_array($channel, ['max', 'telegram'], true)) {
            return null;
        }
        $this->links->pruneBindCodes();

        $code = strtoupper(rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '='));
        $this->links->saveBindCode(hash('sha256', $code), $userId, $channel, self::TTL_SECONDS);

        return ['code' => $code, 'expires_in' => self::TTL_SECONDS];
    }

    public function consumeBindCode(string $plainCode, string $channel, string $externalId, ?string $chatId): bool
    {
        if (!in_array($channel, ['max', 'telegram'], true)) {
            return false;
        }
        $code = strtoupper(trim($plainCode));
        if ($code === '' || strlen($code) > 32 || preg_match('/^[A-Z0-9_-]+$/', $code) !== 1) {
            return false;
        }
        $hash = hash('sha256', $code);
        $row = $this->links->findBindCode($hash);
        if ($row === null
            || (string) $row['channel'] !== $channel
            || $row['used_at'] !== null
            || (int) $row['attempts'] >= self::MAX_ATTEMPTS
            || strtotime((string) $row['expires_at']) <= time()) {
            return false;
        }

        $ownerId = (int) $row['user_id'];
        $existing = $this->links->findByExternal($channel, $externalId);
        if ($existing !== null && (int) $existing['user_id'] !== $ownerId) {
            // Never consume the code on a conflict with somebody else's link.
            return false;
        }

        $this->links->markBindAttempt($hash);
        if (!$this->links->useBindCode($hash)) {
            return false;
        }

        if (!$this->links->saveLink($ownerId, $channel, $externalId, $chatId)) {
            $this->links->releaseBindCode($hash);

            return false;
        }

        return true;
    }

    public function unlink(int $userId, string $channel): bool
    {
        return in_array($channel, ['max', 'telegram'], true) && $this->links->deleteLink($userId, $channel);
    }

    private function mask(string $id): string
    {
        $tail = substr($id, -4);

        return str_repeat('•', max(2, min(8, strlen($id) - strlen($tail)))) . $tail;
    }
}
