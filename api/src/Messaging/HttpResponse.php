<?php

declare(strict_types=1);

namespace App\Messaging;

/** Minimal normalized HTTP response used by messenger providers. */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly ?string $transportError = null
    ) {
    }

    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Read a response header without ever exposing the request URL. */
    public function header(string $name): ?string
    {
        $target = strtolower($name);
        foreach ($this->headers as $line) {
            if (preg_match('/^([^:]+):\s*(.*)$/', (string) $line, $matches) === 1
                && strtolower(trim($matches[1])) === $target) {
                return trim($matches[2]);
            }
        }

        return null;
    }
}
