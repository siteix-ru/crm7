<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Core\Config;
use App\Repositories\MessengerSettingsRepository;
use App\Support\Crypto;

/** Official MAX Bot API v2 provider. No token ever appears in a URL. */
final class MaxProvider implements ChannelProvider
{
    private const CHANNEL = 'max';

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
            return new ChannelStatus(false, errorCode: 'token_missing', message: 'Токен MAX не настроен');
        }

        $response = $this->http->request('GET', $this->baseUrl() . '/me', [
            'Authorization' => $token,
            'Accept' => 'application/json',
        ]);

        if ($response->transportError !== null) {
            return new ChannelStatus(false, errorCode: 'network_error', message: 'Сеть недоступна');
        }
        if ($response->status === 401 || $response->status === 403) {
            return new ChannelStatus(false, errorCode: 'auth_invalid', message: 'Токен MAX недействителен');
        }
        if ($response->status !== 200) {
            return new ChannelStatus(false, errorCode: 'api_error', message: 'Ошибка API MAX');
        }

        $data = $response->json();
        if (!is_array($data)) {
            return new ChannelStatus(false, errorCode: 'bad_response', message: 'Некорректный ответ MAX');
        }

        $botId = isset($data['user_id']) ? (string) $data['user_id'] : null;
        $botName = isset($data['first_name']) ? (string) $data['first_name']
            : (isset($data['name']) ? (string) $data['name'] : null);

        return new ChannelStatus(true, $botId, $botName, null, 'Подключение установлено', gmdate('Y-m-d H:i:s'));
    }

    public function send(string $recipient, NotificationMessage $message): DeliveryResult
    {
        $token = $this->token();
        if ($token === null) {
            return DeliveryResult::failed('token_missing');
        }
        if (preg_match('/^\d{1,20}$/', $recipient) !== 1) {
            return DeliveryResult::failed('invalid_recipient');
        }

        $response = $this->http->request(
            'POST',
            $this->baseUrl() . '/messages?user_id=' . $recipient,
            [
                'Authorization' => $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            json_encode(['text' => $message->text()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        if ($response->transportError !== null) {
            return DeliveryResult::retry('network_error');
        }
        if ($response->status === 429) {
            // MAX documents a 2 msg/sec limit but not the exact 429 payload in
            // this repo's contract. Prefer Retry-After / a generic numeric field;
            // otherwise use a conservative fallback instead of a 1-second spin.
            return DeliveryResult::retry('rate_limited', RetryAfterParser::seconds($response) ?? 5);
        }
        if ($response->status >= 500) {
            return DeliveryResult::retry('api_error', 60);
        }
        if ($response->status === 401 || $response->status === 403) {
            return DeliveryResult::failed('auth_invalid');
        }
        if ($response->status !== 200) {
            return DeliveryResult::failed('api_error');
        }

        $data = $response->json();
        $providerId = is_array($data) && isset($data['message']['mid'])
            ? (string) $data['message']['mid'] : null;

        return DeliveryResult::delivered($providerId);
    }

    /** Register the webhook with MAX; the secret is sent in the body, never logged. */
    public function setWebhook(string $url, string $secret): bool
    {
        $token = $this->token();
        if ($token === null) {
            return false;
        }
        $response = $this->http->request('POST', $this->baseUrl() . '/subscriptions', [
            'Authorization' => $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode([
            'url' => $url,
            'update_types' => ['message_created', 'bot_started'],
            'secret' => $secret,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');

        return $response->transportError === null && $response->status === 200
            && ($response->json()['success'] ?? false) === true;
    }

    private function token(): ?string
    {
        $row = $this->settings->get(self::CHANNEL);
        $encrypted = $row['token_encrypted'] ?? null;

        return is_string($encrypted) && $encrypted !== '' ? Crypto::decrypt($encrypted) : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) (Config::get('MAX_API_BASE', 'https://platform-api2.max.ru') ?: 'https://platform-api2.max.ru'), '/');
    }
}
