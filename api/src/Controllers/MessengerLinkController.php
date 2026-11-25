<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Messaging\MessengerLinkService;

final class MessengerLinkController extends ApiController
{
    public function __construct(private readonly MessengerLinkService $links = new MessengerLinkService())
    {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        $user = $this->requireUser($request);

        return Response::ok($this->links->status((int) $user['ID']));
    }

    public function bindStart(Request $request, array $params): Response
    {
        $user = $this->requireUser($request);
        $channel = $this->channel($params);
        $result = $this->links->startBind((int) $user['ID'], $channel);

        return Response::ok($result ?? ['code' => '', 'expires_in' => 0]);
    }

    public function unlink(Request $request, array $params): Response
    {
        $user = $this->requireUser($request);
        $channel = $this->channel($params);

        return Response::ok(['unlinked' => $this->links->unlink((int) $user['ID'], $channel)]);
    }

    private function requireUser(Request $request): array
    {
        [$user, $capabilities] = $this->context($request);
        if (!in_array('notifications.use', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
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
