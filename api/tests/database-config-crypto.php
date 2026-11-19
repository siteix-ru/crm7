<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Support\DatabaseConfigCrypto;

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

/** @param list<string> $forbidden */
function fails(callable $call, string $name, array &$failures, array $forbidden = []): void
{
    try {
        $call();
        check(false, $name, $failures);

        return;
    } catch (Throwable $exception) {
        $message = $exception->getMessage();

        foreach ($forbidden as $secret) {
            if (str_contains($message, $secret)) {
                check(false, "$name (секрет в сообщении)", $failures);

                return;
            }
        }

        check(true, $name, $failures);
    }
}

$key = 'unit-db-config-key-1';
$otherKey = 'unit-db-config-key-2';
$secret = 'unit-db-password';

putenv('DB_CONFIG_KEY=' . $key);
putenv('APP_KEY=unit-app-key-should-not-be-used');

$encrypted = DatabaseConfigCrypto::encrypt($secret);

check(DatabaseConfigCrypto::decrypt($encrypted) === $secret, 'round-trip', $failures);
check(DatabaseConfigCrypto::encrypt($secret) !== $encrypted, 'разные IV дают разные конверты', $failures);
check(DatabaseConfigCrypto::isEncrypted($encrypted), 'зашифрованное значение распознаётся', $failures);
check(!DatabaseConfigCrypto::isEncrypted($secret), 'legacy plaintext распознаётся как незашифрованный', $failures);

fails(
    fn () => DatabaseConfigCrypto::encrypt($encrypted),
    'двойное шифрование отклоняется',
    $failures,
    [$secret, $encrypted]
);

putenv('DB_CONFIG_KEY=' . $otherKey);

fails(
    fn () => DatabaseConfigCrypto::decrypt($encrypted),
    'неверный ключ отклоняет расшифровку',
    $failures,
    [$key, $otherKey, $secret, $encrypted]
);

putenv('DB_CONFIG_KEY=' . $key);

$payload = substr($encrypted, strlen('dbcfg:v1:'));
$raw = base64_decode($payload, true);
$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
$broken = 'dbcfg:v1:' . base64_encode($raw);

fails(
    fn () => DatabaseConfigCrypto::decrypt($broken),
    'повреждённый ciphertext отклоняется',
    $failures,
    [$secret, $broken]
);

fails(
    fn () => DatabaseConfigCrypto::decrypt('dbcfg:v1:!!!'),
    'некорректный base64 отклоняется',
    $failures,
    [$secret]
);

fails(
    fn () => DatabaseConfigCrypto::decrypt('dbcfg:v1:' . base64_encode('short')),
    'недостаточная длина payload отклоняется',
    $failures,
    [$secret]
);

fails(
    fn () => DatabaseConfigCrypto::decrypt('dbcfg:v2:' . $payload),
    'неизвестная версия формата не трактуется как plaintext',
    $failures,
    [$secret]
);

putenv('DB_CONFIG_KEY');

fails(
    fn () => DatabaseConfigCrypto::encrypt($secret),
    'шифрование без DB_CONFIG_KEY завершается ошибкой',
    $failures,
    [$secret, 'unit-app-key-should-not-be-used']
);

fails(
    fn () => DatabaseConfigCrypto::decrypt($encrypted),
    'расшифровка без DB_CONFIG_KEY завершается ошибкой',
    $failures,
    [$secret, $encrypted, 'unit-app-key-should-not-be-used']
);

putenv('DB_CONFIG_KEY');
putenv('APP_KEY');

echo $failures === [] ? "\nALL OK\n" : "\nFAILED: " . implode(', ', $failures) . "\n";

exit($failures === [] ? 0 : 1);
