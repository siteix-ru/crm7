<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Repositories\MessengerSettingsRepository;
use App\Support\Crypto;

/** Telegram Bot API provider. No real network calls in tests: inject a mock transport. */
final class TelegramProvider implements ChannelProvider
{
    private const CHANNEL = 'telegram';
    private const API_BASE = 'https://api.telegram.org';

    public function __construct(
        private readonly MessengerSettingsRepository $settings = new MessengerSettingsRepository(),
        private readonly HttpTransport $http = new NativeHttpTransport()
    ) {
    }

    public function code(): string
    {
        return self::CHANNEL;
    }

    public function isConfigured(): bool
    {
        return $this->token() !== null;
    }

    public function checkConnection(): ChannelStatus
    {
        $token = $this->token();
        if ($token === null) {
            return new ChannelStatus(false, errorCode: 'token_missing', message: 'Токен Telegram не настроен');
        }

        $response = $this->http->request('GET', $this->endpoint($token, 'getMe'), [
            'Accept' => 'application/json',
        ]);

        if ($response->transportError !== null) {
            return new ChannelStatus(false, errorCode: 'network_error', message: 'Сеть недоступна');
        }
        $data = $response->json();
        if ($response->status !== 200 || !is_array($data) || empty($data['ok'])) {
            return new ChannelStatus(false, errorCode: $this->errorCode($response, $data), message: 'Ошибка API Telegram');
        }
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];

        return new ChannelStatus(
            true,
            isset($result['id']) ? (string) $result['id'] : null,
            isset($result['username']) ? '@' . (string) $result['username'] : null,
            null,
            'Подключение установлено',
            gmdate('Y-m-d H:i:s')
        );
    }

    public function send(string $recipient, NotificationMessage $message): DeliveryResult
    {
        $token = $this->token();
        if ($token === null) {
            return DeliveryResult::failed('token_missing');
        }
        if (preg_match('/^-?\d{1,20}$/', $recipient) !== 1) {
            return DeliveryResult::failed('invalid_recipient');
        }

        $response = $this->http->request(
            'POST',
            $this->endpoint($token, 'sendMessage'),
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode([
                'chat_id' => $recipient,
                'text' => $message->text(),
                'disable_web_page_preview' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        if ($response->transportError !== null) {
            return DeliveryResult::retry('network_error');
        }
        if ($response->status === 429) {
            // Bot API documents parameters.retry_after; Retry-After is also honoured.
            return DeliveryResult::retry('rate_limited', RetryAfterParser::seconds($response));
        }
        if ($response->status >= 500) {
            return DeliveryResult::retry('api_error', 60);
        }
        $data = $response->json();
        if ($response->status !== 200 || !is_array($data) || empty($data['ok'])) {
            return DeliveryResult::failed($this->errorCode($response, $data));
        }
        $providerId = is_array($data['result'] ?? null) && isset($data['result']['message_id'])
            ? (string) $data['result']['message_id'] : null;

        return DeliveryResult::delivered($providerId);
    }

    public function setWebhook(string $url, string $secret): bool
    {
        $token = $this->token();
        if ($token === null) {
            return false;
        }
        $response = $this->http->request('POST', $this->endpoint($token, 'setWebhook'), [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode([
            'url' => $url,
            'secret_token' => $secret,
            'drop_pending_updates' => true,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
        $data = $response->json();

        return $response->transportError === null && $response->status === 200
            && is_array($data) && !empty($data['ok']);
    }

    private function token(): ?string
    {
        $row = $this->settings->get(self::CHANNEL);
        $encrypted = $row['token_encrypted'] ?? null;

        return is_string($encrypted) && $encrypted !== '' ? Crypto::decrypt($encrypted) : null;
    }

    /** The token is part of the Bot API path, never a query parameter. */
    private function endpoint(string $token, string $method): string
    {
        return self::API_BASE . '/bot' . $token . '/' . $method;
    }

    private function errorCode(HttpResponse $response, ?array $data): string
    {
        $description = is_array($data) ? strtolower((string) ($data['description'] ?? '')) : '';

        return match (true) {
            $response->status === 401 || str_contains($description, 'unauthorized') => 'auth_invalid',
            str_contains($description, 'bot was blocked') => 'recipient_blocked',
            str_contains($description, 'chat not found') => 'recipient_invalid',
            default => 'api_error',
        };
    }
}
