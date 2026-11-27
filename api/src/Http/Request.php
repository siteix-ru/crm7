<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Config;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        public readonly string $ip
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

        $headers = [];

        if (function_exists('getallheaders')) {
            $raw = getallheaders();

            if (is_array($raw)) {
                $headers = array_change_key_case($raw, CASE_LOWER);
            }
        }

        if ($headers === []) {
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$name] = (string) $value;
                }
            }
        }

        $body = [];

        $rawBody = file_get_contents('php://input') ?: '';

        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        if ($body === [] && $_POST !== []) {
            $body = $_POST;
        }

        return new self($method, $path, $_GET, $body, $headers, self::clientIp($headers, $_SERVER));
    }

    /**
     * Безопасный IP клиента: forwarded-заголовки учитываются только тогда,
     * когда непосредственный источник запроса входит в TRUSTED_PROXIES.
     * По умолчанию доверенных proxy нет — используется REMOTE_ADDR.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed> $server
     */
    public static function clientIp(array $headers, array $server): string
    {
        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));

        if ($remote === '' || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }

        $trusted = self::trustedProxies();

        if ($trusted === [] || !self::isTrusted($remote, $trusted)) {
            return $remote;
        }

        $forwarded = trim((string) (
            $headers['x-forwarded-for'] ?? $server['HTTP_X_FORWARDED_FOR'] ?? ''
        ));

        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));

            // Идём справа налево: правые адреса добавлены доверенными proxy.
            // Первый недоверенный адрес — реальный клиент.
            for ($index = count($parts) - 1; $index >= 0; $index -= 1) {
                $candidate = $parts[$index];

                if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                    continue;
                }

                if (!self::isTrusted($candidate, $trusted)) {
                    return $candidate;
                }
            }
        }

        $real = trim((string) ($headers['x-real-ip'] ?? $server['HTTP_X_REAL_IP'] ?? ''));

        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }

        return $remote;
    }

    /** @return list<string> */
    private static function trustedProxies(): array
    {
        $raw = trim((string) Config::get('TRUSTED_PROXIES', ''));

        if ($raw === '') {
            return [];
        }

        $result = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);

            if ($entry !== '') {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $trusted
     */
    private static function isTrusted(string $ip, array $trusted): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        foreach ($trusted as $entry) {
            if (str_contains($entry, '/')) {
                if (self::inCidr($packed, $entry)) {
                    return true;
                }

                continue;
            }

            $expected = @inet_pton($entry);

            if ($expected !== false && $expected === $packed) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, '');
        $networkPacked = @inet_pton($network);

        if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = strlen($packed) * 8;
        $prefixLength = filter_var($prefix, FILTER_VALIDATE_INT);

        if ($prefixLength === false || $prefixLength < 0 || $prefixLength > $bits) {
            return false;
        }

        $wholeBytes = intdiv((int) $prefixLength, 8);
        $remainingBits = (int) $prefixLength % 8;

        if ($wholeBytes > 0
            && substr($packed, 0, $wholeBytes) !== substr($networkPacked, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits);

        return (ord($packed[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
    }

    public function withPath(string $path): self
    {
        return new self($this->method, $path, $this->query, $this->body, $this->headers, $this->ip);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function queryParam(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function queryAll(): array
    {
        return $this->query;
    }

    public function bodyAll(): array
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        $key = strtolower(str_replace('_', '-', $name));

        return isset($this->headers[$key]) ? (string) $this->headers[$key] : null;
    }

    public function bearerToken(): ?string
    {
        $header = (string) ($this->headers['authorization'] ?? '');

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    public function userAgent(): string
    {
        return (string) ($this->headers['user-agent'] ?? '');
    }
}
