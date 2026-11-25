<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/autoload.php';

use App\Messaging\HttpResponse;
use App\Messaging\HttpTransport;

/** Test-only HTTP transport: no network, records every request. */
final class MockHttpTransport implements HttpTransport
{
    public array $requests = [];
    private array $queue = [];

    public function push(
        int $status,
        array|string|null $body = null,
        ?string $transportError = null,
        array $headers = []
    ): void {
        $payload = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : (string) $body;
        $this->queue[] = new HttpResponse($status, $payload, $headers, $transportError);
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 10
    ): HttpResponse {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        return array_shift($this->queue) ?? new HttpResponse(500, '{"ok":false}');
    }
}
