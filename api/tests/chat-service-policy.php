<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Chat\ChatService;
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

function service(): ChatService
{
    return (new ReflectionClass(ChatService::class))->newInstanceWithoutConstructor();
}

/** @return mixed */
function invoke(ChatService $service, string $method, array $args)
{
    $reflection = new ReflectionMethod(ChatService::class, $method);

    return $reflection->invokeArgs($service, $args);
}

function allows(ChatService $service, array $user, array $capabilities, array $thread): bool
{
    try {
        invoke($service, 'assertCanView', [$user, $capabilities, $thread]);

        return true;
    } catch (HttpException) {
        return false;
    }
}

$service = service();
$staff = ['ID' => 42, 'LEVEL' => 10];
$staffWithoutViewAll = [];
$staffWithViewAll = ['clients.view.all'];
$clientThread = ['ID' => 7, 'client_id' => 999, 'manager_id' => 1, 'peer_id' => null];

check(
    allows($service, $staff, $staffWithoutViewAll, $clientThread),
    'сотрудник без clients.view.all читает чат чужого клиента',
    $failures
);
check(
    allows($service, $staff, $staffWithViewAll, $clientThread),
    'сотрудник с clients.view.all читает чат чужого клиента',
    $failures
);
check(
    invoke($service, 'canPostForThread', [$staff, $staffWithoutViewAll, $clientThread]) === true,
    'сотрудник без chat-capability отвечает в чате чужого клиента',
    $failures
);
check(
    invoke($service, 'canPostForThread', [$staff, $staffWithViewAll, $clientThread]) === true,
    'сотрудник с clients.view.all отвечает в чате чужого клиента',
    $failures
);

$client = ['ID' => 999, 'LEVEL' => 5];
check(
    allows($service, $client, [], $clientThread),
    'клиент читает только свой чат',
    $failures
);
check(
    !allows($service, ['ID' => 1000, 'LEVEL' => 5], [], $clientThread),
    'клиент не читает чужой чат',
    $failures
);
check(
    invoke($service, 'canPostForThread', [$client, [], $clientThread]) === true,
    'клиент отвечает в своём чате',
    $failures
);
check(
    invoke($service, 'canPostForThread', [['ID' => 1000, 'LEVEL' => 5], [], $clientThread]) === false,
    'клиент не отвечает в чужом чате',
    $failures
);

// Неавторизованные пользователи не достигают сервиса: доступ проверяется
// в ApiController::context() до вызова чатных методов. Здесь дополнительно
// проверяем, что политика не меняет ограничения для клиентов уровня 5.

echo $failures === [] ? "\nALL OK\n" : "\nFAILED: " . implode(', ', $failures) . "\n";
exit($failures === [] ? 0 : 1);
