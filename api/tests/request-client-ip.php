<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Http\Request;

$failures = [];

function check(bool $condition, string $name, array &$failures): void
{
    if ($condition) {
        echo "ok   $name\n";

        return;
    }

    echo "FAIL $name\n";
    $failures[] = $name;
}

function ip(array $headers, array $server): string
{
    return Request::clientIp($headers, $server);
}

putenv('TRUSTED_PROXIES');

// Без доверенных proxy forwarded-заголовки полностью игнорируются.
check(
    ip(['x-forwarded-for' => '203.0.113.9', 'x-real-ip' => '203.0.113.9'],
        ['REMOTE_ADDR' => '198.51.100.7']) === '198.51.100.7',
    'forwarded заголовки игнорируются без доверенного proxy',
    $failures
);

putenv('TRUSTED_PROXIES=10.0.0.5');

check(
    ip(['x-forwarded-for' => '203.0.113.9'],
        ['REMOTE_ADDR' => '10.0.0.5']) === '203.0.113.9',
    'запрос от доверенного proxy: клиент из XFF',
    $failures
);

check(
    ip(['x-forwarded-for' => '203.0.113.9'],
        ['REMOTE_ADDR' => '10.0.0.6']) === '10.0.0.6',
    'неизвестный proxy не принимает XFF',
    $failures
);

check(
    ip(['x-forwarded-for' => '192.0.2.10, 10.0.0.5'],
        ['REMOTE_ADDR' => '10.0.0.5']) === '192.0.2.10',
    'цепочка XFF: берём первого недоверенного клиента',
    $failures
);

check(
    ip(['x-forwarded-for' => '192.0.2.10, 10.0.0.5, 10.0.0.5'],
        ['REMOTE_ADDR' => '10.0.0.5']) === '192.0.2.10',
    'цепочка с несколькими доверенными proxy',
    $failures
);

check(
    ip(['x-forwarded-for' => 'not-an-ip, 10.0.0.5'],
        ['REMOTE_ADDR' => '10.0.0.5']) === '10.0.0.5',
    'некорректный XFF не подменяет IP',
    $failures
);

check(
    ip(['x-forwarded-for' => '', 'x-real-ip' => '203.0.113.9'],
        ['REMOTE_ADDR' => '10.0.0.5']) === '203.0.113.9',
    'X-Real-IP от доверенного proxy',
    $failures
);

check(
    ip(['x-real-ip' => '203.0.113.9'],
        ['REMOTE_ADDR' => '10.0.0.6']) === '10.0.0.6',
    'X-Real-IP от недоверенного источника игнорируется',
    $failures
);

check(
    ip([], []) === '0.0.0.0',
    'отсутствие REMOTE_ADDR',
    $failures
);

check(
    ip([], ['REMOTE_ADDR' => '2001:db8::7']) === '2001:db8::7',
    'IPv6 REMOTE_ADDR',
    $failures
);

putenv('TRUSTED_PROXIES=10.0.0.0/8');

check(
    ip(['x-forwarded-for' => '203.0.113.9'],
        ['REMOTE_ADDR' => '10.12.34.56']) === '203.0.113.9',
    'доверенная сеть CIDR IPv4',
    $failures
);

check(
    ip(['x-forwarded-for' => '203.0.113.9'],
        ['REMOTE_ADDR' => '192.168.1.2']) === '192.168.1.2',
    'private IP не является доверенным автоматически',
    $failures
);

putenv('TRUSTED_PROXIES=2001:db8::/32');

check(
    ip(['x-forwarded-for' => '203.0.113.9'],
        ['REMOTE_ADDR' => '2001:db8::5']) === '203.0.113.9',
    'доверенная сеть CIDR IPv6',
    $failures
);

putenv('TRUSTED_PROXIES');

echo $failures === [] ? "\nALL OK\n" : "\nFAILED: " . implode(', ', $failures) . "\n";
exit($failures === [] ? 0 : 1);
