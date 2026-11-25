<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Repositories\MessengerSettingsRepository;
use App\Support\Crypto;

final class MessengerSettingsService
{
    public function __construct(
        private readonly MessengerSettingsRepository $settings = new MessengerSettingsRepository(),
        private readonly ChannelRegistry $registry = new ChannelRegistry([])
    ) {
    }

    /** Admin-safe view: no token, no secret, only masked presence. */
    public function list(): array
    {
        $items = [];
        foreach ($this->settings->all() as $row) {
            $channel = (string) $row['channel'];
            $hasToken = is_string($row['token_encrypted'] ?? null) && $row['token_encrypted'] !== '';
            $items[] = [
                'channel' => $channel,
                'enabled' => (bool) $row['enabled'],
                'has_token' => $hasToken,
                'token_masked' => $hasToken ? '••••••••' : '',
                'bot_id' => $row['bot_id'] ?? null,
                'bot_name' => $row['bot_name'] ?? null,
                'last_check_status' => $row['last_check_status'] ?? null,
                'last_check_error' => $row['last_check_error'] ?? null,
                'last_check_at' => $row['last_check_at'] ?? null,
                'has_webhook_secret' => is_string($row['webhook_secret_encrypted'] ?? null)
                    && $row['webhook_secret_encrypted'] !== '',
            ];
        }

        return ['items' => $items];
    }

    public function save(string $channel, bool $enabled, ?string $token): void
    {
        $row = $this->settings->get($channel);
        if ($row === null) {
            return;
        }

        // An empty replacement field never clears an existing token.
        $encrypted = null;
        if (is_string($token) && trim($token) !== '') {
            $encrypted = Crypto::encrypt(trim($token));
        }

        $secret = null;
        if (($row['webhook_secret_encrypted'] ?? null) === null) {
            $secret = Crypto::encrypt($this->newWebhookSecret());
        }

        $this->settings->save($channel, $enabled, $encrypted, $secret);
    }

    public function deleteToken(string $channel): void
    {
        $this->settings->deleteToken($channel);
    }

    public function check(string $channel): ?ChannelStatus
    {
        $provider = $this->registry->get($channel);
        if ($provider === null) {
            return null;
        }

        $status = $provider->checkConnection();
        $this->settings->recordCheck(
            $channel,
            $status->connected,
            $status->errorCode,
            $status->botId,
            $status->botName
        );

        return $status;
    }

    /** Plain secret is returned once, when the webhook subscription is created. */
    public function ensureWebhookSecret(string $channel): ?string
    {
        $row = $this->settings->get($channel);
        if ($row === null) {
            return null;
        }
        $encrypted = $row['webhook_secret_encrypted'] ?? null;
        if (is_string($encrypted) && $encrypted !== '') {
            return Crypto::decrypt($encrypted);
        }

        $plain = $this->newWebhookSecret();
        $this->settings->save($channel, (bool) $row['enabled'], null, Crypto::encrypt($plain));

        return $plain;
    }

    private function newWebhookSecret(): string
    {
        return bin2hex(random_bytes(24));
    }
}
