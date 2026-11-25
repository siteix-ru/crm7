<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Messaging\ChannelRegistry;
use App\Messaging\MessengerSettingsService;

final class AdminMessengerController extends ApiController
{
    public function __construct(
        private readonly MessengerSettingsService $settings = new MessengerSettingsService(),
        private readonly ChannelRegistry $registry = new ChannelRegistry([])
    ) {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        $this->requireManage($request);

        return Response::ok($this->settings->list());
    }

    public function save(Request $request, array $params): Response
    {
        $user = $this->requireManage($request);
        $channel = $this->channel($params);

        $enabled = filter_var($request->input('enabled', false), FILTER_VALIDATE_BOOL);
        $token = $request->input('token', null);
        if ($token !== null && !is_string($token)) {
            throw new HttpException(422, 'validation_error', 'Некорректный токен');
        }

        $this->settings->save($channel, $enabled, $token);
        $this->audit($request, $user, 'settings.messenger.changed', 'messenger', $channel, [
            'enabled' => $enabled,
            'token_changed' => is_string($token) && trim($token) !== '',
        ]);

        return Response::ok($this->settings->list());
    }

    public function check(Request $request, array $params): Response
    {
        $user = $this->requireManage($request);
        $channel = $this->channel($params);

        $status = $this->settings->check($channel);
        $this->audit($request, $user, 'settings.messenger.checked', 'messenger', $channel, [
            'result' => $status?->connected ? 'ok' : 'error',
            'error_code' => $status?->errorCode,
        ]);

        return Response::ok($status?->toArray() ?? ['connected' => false, 'error_code' => 'unknown_channel']);
    }

    public function deleteToken(Request $request, array $params): Response
    {
        $user = $this->requireManage($request);
        $channel = $this->channel($params);
        $confirm = filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOL);
        if (!$confirm) {
            throw new HttpException(422, 'confirmation_required', 'Требуется подтверждение удаления');
        }

        $this->settings->deleteToken($channel);
        $this->audit($request, $user, 'settings.messenger.token_removed', 'messenger', $channel);

        return Response::ok($this->settings->list());
    }

    public function setupWebhook(Request $request, array $params): Response
    {
        $user = $this->requireManage($request);
        $channel = $this->channel($params);

        $url = trim((string) $request->input('url', ''));
        if (!str_starts_with($url, 'https://')) {
            throw new HttpException(422, 'validation_error', 'Webhook-URL должен использовать HTTPS');
        }

        $secret = $this->settings->ensureWebhookSecret($channel);
        $provider = $this->registry->get($channel);
        $ok = $secret !== null && $provider !== null
            && method_exists($provider, 'setWebhook')
            && $provider->setWebhook($url, $secret);

        $this->audit($request, $user, 'settings.messenger.webhook', 'messenger', $channel, [
            'result' => $ok ? 'ok' : 'error',
        ]);

        return Response::ok(['registered' => $ok]);
    }

    private function requireManage(Request $request): array
    {
        [$user, $capabilities] = $this->context($request);
        if (!in_array('messengers.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для настройки мессенджеров');
        }

        return $user;
    }

    /** Router passes route parameters as an array; validate before use. */
    private function channel(array $params): string
    {
        $channel = $params['channel'] ?? null;
        if (!is_string($channel) || !in_array($channel, ['max', 'telegram'], true)) {
            throw new HttpException(404, 'not_found', 'Неизвестный канал');
        }

        return $channel;
    }
}
