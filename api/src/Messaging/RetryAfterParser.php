<?php

declare(strict_types=1);

namespace App\Messaging;

/** Reads a retry delay from standard HTTP headers or a generic numeric body field. */
final class RetryAfterParser
{
    public static function seconds(HttpResponse $response): ?int
    {
        $header = $response->header('Retry-After');
        if (is_string($header) && $header !== '') {
            if (ctype_digit(trim($header))) {
                return max(1, min(86400, (int) trim($header)));
            }
            $timestamp = strtotime($header);
            if ($timestamp !== false && $timestamp > time()) {
                return max(1, min(86400, $timestamp - time()));
            }
        }

        $data = $response->json();
        if (is_array($data)) {
            $value = $data['parameters']['retry_after'] ?? $data['retry_after'] ?? $data['retryAfter'] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                return max(1, min(86400, (int) $value));
            }
        }

        return null;
    }
}
