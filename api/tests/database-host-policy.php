<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\DatabaseHostPolicy;
use App\Http\HttpException;

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

function rejected(callable $call, string $code): bool
{
    try {
        $call();

        return false;
    } catch (HttpException $exception) {
        return $exception->errorCode === $code;
    }
}

function allowed(
    DatabaseHostPolicy $policy,
    string $host,
    int $port,
    string $currentHost,
    int $currentPort
): bool {
    try {
        $policy->assertAllowed($host, $port, $currentHost, $currentPort);

        return true;
    } catch (HttpException) {
        return false;
    }
}

$resolver = static fn (string $host): array => ['172.18.0.2'];
$policy = new DatabaseHostPolicy($resolver);

putenv('DB_ALLOW_HOST_CHANGE=false');
putenv('DB_HOST_ALLOWLIST=');

check(
    allowed($policy, '127.0.0.1', 3306, '127.0.0.1', 3306),
    'текущая пара 127.0.0.1:3306 разрешена',
    $failures
);

check(
    allowed($policy, 'DB', 3306, 'db', 3306),
    'Docker-хост без учёта регистра разрешён',
    $failures
);

check(
    allowed($policy, 'db.', 3306, 'db', 3306),
    'завершающая точка нормализуется',
    $failures
);

check(
    allowed($policy, '::1', 3306, '::1', 3306),
    'текущий IPv6 разрешён',
    $failures
);

check(
    rejected(
        fn () => $policy->assertAllowed('127.0.0.1', 22, '127.0.0.1', 3306),
        'port_change_disabled'
    ),
    'смена порта при выключенном флаге отклоняется',
    $failures
);

check(
    rejected(
        fn () => $policy->assertAllowed('db', 3306, '127.0.0.1', 3306),
        'host_change_disabled'
    ),
    'смена хоста при выключенном флаге отклоняется',
    $failures
);

putenv('DB_ALLOW_HOST_CHANGE=true');

check(
    rejected(
        fn () => $policy->assertAllowed('127.0.0.1', 22, '127.0.0.1', 3306),
        'port_change_disabled'
    ),
    'смена порта отклоняется и при включённом флаге',
    $failures
);

check(
    rejected(
        fn () => $policy->assertAllowed('db', 3306, '127.0.0.1', 3306),
        'host_not_allowed'
    ),
    'флаг без allowlist не разрешает все адреса',
    $failures
);

putenv('DB_HOST_ALLOWLIST= DB. , 10.20.30.40 ');

check(
    allowed($policy, 'db', 3306, '127.0.0.1', 3306),
    'разрешённый hostname с текущим портом проходит',
    $failures
);

check(
    rejected(
        fn () => $policy->assertAllowed('db', 3307, '127.0.0.1', 3306),
        'port_change_disabled'
    ),
    'разрешённый hostname с другим портом отклоняется',
    $failures
);

check(
    rejected(
        fn () => $policy->assertAllowed('evil.example.com', 3306, '127.0.0.1', 3306),
        'host_not_allowed'
    ),
    'хост вне allowlist отклоняется',
    $failures
);

putenv('DB_HOST_ALLOWLIST=127.0.0.2');

check(
    rejected(
        fn () => $policy->assertAllowed('127.0.0.2', 3306, '127.0.0.1', 3306),
        'host_not_allowed'
    ),
    'loopback IPv4 127.0.0.2 блокируется',
    $failures
);

putenv('DB_HOST_ALLOWLIST=::ffff:169.254.169.254,::ffff:a9fe:a9fe');

foreach (['::ffff:169.254.169.254', '::ffff:a9fe:a9fe'] as $mapped) {
    check(
        rejected(
            fn () => $policy->assertAllowed($mapped, 3306, '127.0.0.1', 3306),
            'host_not_allowed'
        ),
        "IPv4-mapped metadata $mapped блокируется",
        $failures
    );
}

putenv('DB_HOST_ALLOWLIST=0:0:0:0:0:0:0:1,0:0:0:0:0:0:0:0');

foreach (['0:0:0:0:0:0:0:1', '0:0:0:0:0:0:0:0'] as $expanded) {
    check(
        rejected(
            fn () => $policy->assertAllowed($expanded, 3306, '127.0.0.1', 3306),
            'host_not_allowed'
        ),
        "расширенный IPv6 $expanded блокируется",
        $failures
    );
}

putenv('DB_HOST_ALLOWLIST=::a9fe:a9fe,::7f00:1');

foreach (['::a9fe:a9fe', '::7f00:1'] as $compatible) {
    check(
        rejected(
            fn () => $policy->assertAllowed($compatible, 3306, '127.0.0.1', 3306),
            'host_not_allowed'
        ),
        "IPv4-compatible $compatible блокируется",
        $failures
    );
}

putenv('DB_HOST_ALLOWLIST=::,::1');

foreach (['::', '::1'] as $special) {
    check(
        rejected(
            fn () => $policy->assertAllowed($special, 3306, '127.0.0.1', 3306),
            'host_not_allowed'
        ),
        "$special по-прежнему блокируется",
        $failures
    );
}

putenv('DB_HOST_ALLOWLIST=2001:db8::1');
$ipv6Policy = new DatabaseHostPolicy(static fn (string $host): array => ['2001:db8::2']);

check(
    allowed($ipv6Policy, '2001:db8::1', 3306, '127.0.0.1', 3306),
    'обычный разрешённый IPv6 не блокируется ошибочно',
    $failures
);

putenv('DB_HOST_ALLOWLIST=169.254.169.254,metadata.google.internal,100.100.100.200,fd00:ec2::254');

foreach (['169.254.169.254', 'metadata.google.internal', '100.100.100.200', 'fd00:ec2::254'] as $metadata) {
    check(
        rejected(
            fn () => $policy->assertAllowed($metadata, 3306, '127.0.0.1', 3306),
            'host_not_allowed'
        ),
        "metadata $metadata запрещён даже в allowlist",
        $failures
    );
}

$badHosts = [
    '127.1', '2130706433', '0x7f000001', '[::1]', 'http://evil', 'user@host',
    'host/path', 'host?x', 'host#x', 'host with space', 'host;x', 'host=x', 'host%2f',
    "host\nx", 'host\\x'
];

foreach ($badHosts as $host) {
    check(
        rejected(
            fn () => $policy->assertAllowed($host, 3306, '127.0.0.1', 3306),
            'validation_error'
        ),
        "некорректный формат $host отклонён",
        $failures
    );
}

putenv('DB_HOST_ALLOWLIST=db');
$dnsPolicy = new DatabaseHostPolicy(static fn (string $host): array => ['10.0.0.5', '169.254.169.254']);

check(
    rejected(
        fn () => $dnsPolicy->assertAllowed('db', 3306, '127.0.0.1', 3306),
        'host_not_allowed'
    ),
    'смешанный DNS-ответ с запрещённым IP отклоняется',
    $failures
);

$emptyDns = new DatabaseHostPolicy(static fn (string $host): array => []);

check(
    rejected(
        fn () => $emptyDns->assertAllowed('db', 3306, '127.0.0.1', 3306),
        'host_resolution_failed'
    ),
    'пустой DNS-ответ отклоняется',
    $failures
);

$brokenDns = new DatabaseHostPolicy(static function (string $host): array {
    throw new RuntimeException('resolver failure');
});

check(
    rejected(
        fn () => $brokenDns->assertAllowed('db', 3306, '127.0.0.1', 3306),
        'host_resolution_failed'
    ),
    'исключение resolver превращается в host_resolution_failed',
    $failures
);

putenv('DB_ALLOW_HOST_CHANGE');
putenv('DB_HOST_ALLOWLIST');

echo $failures === [] ? "\nALL OK\n" : "\nFAILED: " . implode(', ', $failures) . "\n";

exit($failures === [] ? 0 : 1);
