<?php

declare(strict_types=1);

namespace App\Messaging;

use RuntimeException;

/** HTTPS-only transport with a fixed timeout and no redirect following. */
final class NativeHttpTransport implements HttpTransport
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 10
    ): HttpResponse {
        if (!str_starts_with($url, 'https://')) {
            return new HttpResponse(0, '', [], 'transport_insecure_url');
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'Content-Length: ' . strlen($body ?? '');
        $lines[] = 'Connection: close';

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'timeout' => max(1, $timeoutSeconds),
                'follow_location' => 0,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseHeaders = [];
        set_error_handler(static function () use (&$responseHeaders): bool {
            return true;
        });
        try {
            $raw = file_get_contents($url, false, $context);
            $responseHeaders = $http_response_header ?? [];
        } catch (\Throwable $e) {
            $raw = false;
        } finally {
            restore_error_handler();
        }

        if ($raw === false) {
            return new HttpResponse(0, '', [], 'transport_error');
        }

        $status = 0;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return new HttpResponse($status, $raw, $responseHeaders);
    }
}
