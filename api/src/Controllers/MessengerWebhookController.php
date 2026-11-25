<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Messaging\MessengerWebhookService;
use App\Repositories\MessengerSettingsRepository;
use App\Support\Crypto;

/** Public endpoints: authenticity is proved by the platform secret header. */
final class MessengerWebhookController extends ApiController
{
    public function __construct(
        private readonly MessengerSettingsRepository $settings = new MessengerSettingsRepository(),
        private readonly MessengerWebhookService $webhooks = new MessengerWebhookService()
    ) {
        parent::__construct();
    }

    public function max(Request $request): Response
    {
        if (!$this->validSecret('max', $request->header('X-Max-Bot-Api-Secret'))) {
            return Response::error('invalid_signature', 'Недействительная подпись', 403);
        }
        $body = $request->bodyAll();
        if ($body === []) {
            return Response::error('invalid_payload', 'Пустой запрос', 400);
        }
        $bound = $this->webhooks->handleMax($body);

        return Response::ok(['ok' => true, 'bound' => $bound]);
    }

    public function telegram(Request $request): Response
    {
        if (!$this->validSecret('telegram', $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return Response::error('invalid_signature', 'Недействительная подпись', 403);
        }
        $body = $request->bodyAll();
        if ($body === []) {
            return Response::error('invalid_payload', 'Пустой запрос', 400);
        }
        $bound = $this->webhooks->handleTelegram($body);

        return Response::ok(['ok' => true, 'bound' => $bound]);
    }

    private function validSecret(string $channel, ?string $provided): bool
    {
        if (!is_string($provided) || $provided === '') {
            return false;
        }
        $encrypted = $this->settings->webhookSecret($channel);
        if ($encrypted === null) {
            return false;
        }
        $expected = Crypto::decrypt($encrypted);

        return is_string($expected) && $expected !== '' && hash_equals($expected, $provided);
    }
}
