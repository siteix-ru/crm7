<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\HttpException;

/**
 * Политика разрешённых хостов и портов подключения к БД.
 *
 * Источник истины — серверная конфигурация, а не frontend.
 * По умолчанию разрешается только текущая нормализованная пара host:port.
 */
final class DatabaseHostPolicy
{
    /** @var callable(string): list<string> */
    private $resolver;

    /** @param (callable(string): list<string>)|null $resolver */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static fn (string $host): array => self::defaultResolve($host);
    }

    public static function normalizeHost(string $host): string
    {
        $host = trim($host);

        if ($host === '' || preg_match('/[\x00-\x20\x7f]/', $host) === 1) {
            throw self::invalidHost();
        }

        foreach (['://', '@', '/', '\\', '?', '#', ',', ';', '=', '%'] as $needle) {
            if (str_contains($host, $needle)) {
                throw self::invalidHost();
            }
        }

        $host = strtolower(rtrim($host, '.'));

        if ($host === '' || strlen($host) > 253) {
            throw self::invalidHost();
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::canonicalIp($host) ?? throw self::invalidHost();
        }

        // Скобочная IPv6-форма и числовые/шестнадцатеричные алиасы IP
        // не должны попадать в DSN и резолвиться в loopback.
        if (str_starts_with($host, '[') || preg_match('/^[0-9.]+$/', $host) === 1
            || preg_match('/^0x[0-9a-f]+$/', $host) === 1) {
            throw self::invalidHost();
        }

        if (preg_match(
            '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/',
            $host
        ) !== 1) {
            throw self::invalidHost();
        }

        return $host;
    }

    public function assertAllowed(string $host, int $port, string $currentHost, int $currentPort): void
    {
        $normalized = self::normalizeHost($host);
        $current = self::normalizeHost($currentHost);

        // Порт — часть разрешённой пары. Проверка выполняется до PDO
        // независимо от пароля и от флага смены хоста.
        if ($port !== $currentPort) {
            throw new HttpException(422, 'port_change_disabled', 'Смена порта базы данных отключена');
        }

        if ($normalized === $current) {
            return;
        }

        self::assertNotMetadata($normalized);

        $hostChange = filter_var(Config::get('DB_ALLOW_HOST_CHANGE', 'false'), FILTER_VALIDATE_BOOL);

        if ($hostChange !== true) {
            throw new HttpException(422, 'host_change_disabled', 'Смена сервера базы данных отключена');
        }

        $allowlist = self::allowlist();

        if (!in_array($normalized, $allowlist, true)) {
            throw new HttpException(422, 'host_not_allowed', 'Сервер базы данных не разрешён');
        }

        $addresses = $this->addresses($normalized);

        if ($addresses === []) {
            throw new HttpException(422, 'host_resolution_failed', 'Не удалось определить адрес сервера базы данных');
        }

        foreach ($addresses as $address) {
            self::assertNotMetadata($address);
        }
    }

    /** @return list<string> */
    private function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        try {
            $records = ($this->resolver)($host);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            if (!is_string($record)) {
                continue;
            }

            $canonical = self::canonicalIp($record);

            if ($canonical === null) {
                continue;
            }

            $addresses[] = $canonical;
        }

        return array_values(array_unique($addresses));
    }

    private static function canonicalIp(string $value): ?string
    {
        $packed = @inet_pton($value);

        if ($packed === false) {
            return null;
        }

        $canonical = @inet_ntop($packed);

        return $canonical === false ? null : strtolower($canonical);
    }

    private static function assertNotMetadata(string $value): void
    {
        if (preg_match('/(^|\.)(metadata|instance-data|metadata\.google\.internal)(\.|$)/i', $value) === 1) {
            throw new HttpException(422, 'host_not_allowed', 'Сервер базы данных не разрешён');
        }

        $canonical = self::canonicalIp($value);

        if ($canonical === null) {
            return;
        }

        if (str_contains($canonical, ':')) {
            $packed = inet_pton($canonical);

            if ($canonical === '::' || $canonical === '::1' || $canonical === 'fd00:ec2::254') {
                throw new HttpException(422, 'host_not_allowed', 'Сервер базы данных не разрешён');
            }

            if ($packed !== false && strlen($packed) === 16) {
                // IPv4-mapped (::ffff:a.b.c.d) и IPv4-compatible (::a.b.c.d)
                // оба содержат IPv4 в последних четырёх байтах. Обычные IPv6
                // с совпадающими последними байтами сюда не попадают.
                $mapped = substr($packed, 0, 10) === str_repeat("\x00", 10)
                    && substr($packed, 10, 2) === "\xff\xff";
                $compatible = substr($packed, 0, 12) === str_repeat("\x00", 12);

                if ($mapped || $compatible) {
                    $embedded = inet_ntop(substr($packed, 12, 4));

                    if (is_string($embedded)) {
                        self::assertBlockedIpv4($embedded);
                    }

                    return;
                }
            }

            if ($packed !== false && strlen($packed) === 16
                && ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80) {
                throw new HttpException(422, 'host_not_allowed', 'Сервер базы данных не разрешён');
            }

            return;
        }

        self::assertBlockedIpv4($canonical);
    }

    private static function assertBlockedIpv4(string $ip): void
    {
        $long = ip2long($ip);

        if ($long === false) {
            throw self::invalidHost();
        }

        if (($long & 0xff000000) === 0
            || ($long & 0xff000000) === ip2long('127.0.0.0')
            || ($long & 0xffff0000) === ip2long('169.254.0.0')
            || $ip === '100.100.100.200') {
            throw new HttpException(422, 'host_not_allowed', 'Сервер базы данных не разрешён');
        }
    }

    /** @return list<string> */
    private static function allowlist(): array
    {
        $raw = (string) Config::get('DB_HOST_ALLOWLIST', '');
        $values = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            try {
                $values[] = self::normalizeHost($entry);
            } catch (HttpException) {
                // Некорректные записи конфигурации игнорируются.
            }
        }

        return array_values(array_unique($values));
    }

    /** @return list<string> */
    private static function defaultResolve(string $host): array
    {
        if (!function_exists('dns_get_record')) {
            return [];
        }

        try {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = (string) $record['ip'];
            }

            if (isset($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function invalidHost(): HttpException
    {
        return new HttpException(422, 'validation_error', 'Укажите корректный сервер базы данных');
    }
}
