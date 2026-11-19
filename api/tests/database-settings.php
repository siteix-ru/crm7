<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\DatabaseHostPolicy;
use App\Core\DatabaseSettings;
use App\Core\SafeConfigFileWriter;
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

/** @param list<string> $forbidden */
function fails(callable $call, string $code, string $name, array &$failures, array $forbidden = []): void
{
    try {
        $call();
        check(false, $name, $failures);

        return;
    } catch (HttpException $exception) {
        $message = $exception->getMessage();

        foreach ($forbidden as $secret) {
            if (str_contains($message, $secret)) {
                check(false, "$name (секрет в сообщении)", $failures);

                return;
            }
        }

        check($exception->errorCode === $code, $name, $failures);
    }
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

$logDir = sys_get_temp_dir() . '/crm7-dbsettings-test-' . getmypid();

if (!is_dir($logDir)) {
    mkdir($logDir, 0700, true);
}

@unlink($logDir . '/app.log');

putenv('LOG_DIR=' . $logDir);
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=3306');
putenv('DB_PASS=unit-secret');
putenv('DB_ALLOW_HOST_CHANGE=false');
putenv('DB_HOST_ALLOWLIST=');

$policy = new DatabaseHostPolicy(static fn (string $host): array => ['172.18.0.2']);
$base = ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'crm_local', 'user' => 'crm_app'];

$same = DatabaseSettings::normalize($base, $policy);

check($same['password'] === 'unit-secret', 'тот же хост и порт: пароль повторно используется', $failures);

$nullPassword = DatabaseSettings::normalize($base + ['password' => null], $policy);

check(
    $nullPassword['password'] === 'unit-secret',
    'password: null для текущей пары обрабатывается как пустой',
    $failures
);

check(
    rejected(
        fn () => DatabaseSettings::normalize(
            ['host' => '127.0.0.1', 'port' => 22, 'database' => 'crm_local', 'user' => 'crm_app', 'password' => 'explicit'],
            $policy
        ),
        'port_change_disabled'
    ),
    'смена порта с непустым паролем всё равно отклоняется',
    $failures
);

check(
    rejected(
        fn () => DatabaseSettings::normalize(
            ['host' => '127.0.0.1', 'port' => 22, 'database' => 'crm_local', 'user' => 'crm_app'],
            $policy
        ),
        'port_change_disabled'
    ),
    'смена порта без пароля отклоняется политикой до проверки пароля',
    $failures
);

$invalidPorts = ['3306abc', '3306.9', '', 0, 65536, -1, ' 3306', '03306'];

foreach ($invalidPorts as $invalidPort) {
    $input = ['host' => '127.0.0.1', 'port' => $invalidPort, 'database' => 'crm_local', 'user' => 'crm_app'];

    check(
        rejected(
            fn () => DatabaseSettings::normalize($input, $policy),
            'validation_error'
        ),
        'некорректный порт ' . var_export($invalidPort, true) . ' отклоняется',
        $failures
    );
}

check(
    DatabaseSettings::normalize($base + ['port' => '3306'], $policy)['port'] === 3306,
    'порт "3306" принимается',
    $failures
);

$invalidPdoCalled = false;
$invalidFactory = static function () use (&$invalidPdoCalled): never {
    $invalidPdoCalled = true;

    throw new PDOException('unexpected');
};

try {
    DatabaseSettings::test(
        ['host' => '127.0.0.1', 'port' => '3306abc', 'database' => 'crm_local', 'user' => 'crm_app', 'password' => 'x'],
        $policy,
        $invalidFactory
    );
} catch (HttpException) {
    // Ожидаемый отказ до PDO.
}

check(
    $invalidPdoCalled === false,
    'некорректный порт не достигает PDO',
    $failures
);

putenv('DB_ALLOW_HOST_CHANGE=true');
putenv('DB_HOST_ALLOWLIST=db');

check(
    rejected(
        fn () => DatabaseSettings::normalize(
            ['host' => 'db', 'port' => 22, 'database' => 'crm_local', 'user' => 'crm_app', 'password' => 'explicit'],
            new DatabaseHostPolicy(static fn (string $host): array => ['172.18.0.2'])
        ),
        'port_change_disabled'
    ),
    'разрешённый хост с чужим портом отклоняется',
    $failures
);

check(
    rejected(
        fn () => DatabaseSettings::normalize(
            ['host' => 'db', 'port' => 3306, 'database' => 'crm_local', 'user' => 'crm_app'],
            new DatabaseHostPolicy(static fn (string $host): array => ['172.18.0.2'])
        ),
        'password_required'
    ),
    'новый хост без пароля отклоняется',
    $failures
);

$changed = DatabaseSettings::normalize(
    ['host' => 'db', 'port' => 3306, 'database' => 'crm_local', 'user' => 'crm_app', 'password' => 'explicit'],
    new DatabaseHostPolicy(static fn (string $host): array => ['172.18.0.2'])
);

check($changed['password'] === 'explicit', 'новый хост с явным паролем принят', $failures);

putenv('DB_ALLOW_HOST_CHANGE=false');
putenv('DB_HOST_ALLOWLIST=');

check(
    rejected(
        fn () => DatabaseSettings::normalize(
            ['host' => 'evil.example.com', 'port' => 3306, 'database' => 'crm_local', 'user' => 'crm_app', 'password' => 'x'],
            $policy
        ),
        'host_change_disabled'
    ),
    'запрещённый хост отклоняется политикой',
    $failures
);

$pdoCalled = false;
$guarded = static function () use (&$pdoCalled): never {
    $pdoCalled = true;

    throw new PDOException('SQLSTATE[HY000] [2002] Connection refused (using password: YES) unit-secret');
};

$error = null;

try {
    DatabaseSettings::test(
        ['host' => '127.0.0.1', 'port' => 22, 'database' => 'crm_local', 'user' => 'crm_app', 'password' => 'x'],
        $policy,
        $guarded
    );
} catch (HttpException $exception) {
    $error = $exception;
}

check(
    $error !== null && $error->errorCode === 'port_change_disabled' && $pdoCalled === false,
    'политика отклоняет порт до PDO-подключения',
    $failures
);

$error = null;

try {
    DatabaseSettings::test($base, $policy, $guarded);
} catch (HttpException $exception) {
    $error = $exception;
}

check($error !== null && $error->errorCode === 'db_connection_failed', 'PDOException → стабильный код', $failures);
check(
    $error !== null && !str_contains($error->getMessage(), 'unit-secret')
        && !str_contains($error->getMessage(), 'using password')
        && !str_contains($error->getMessage(), 'SQLSTATE'),
    'клиентское сообщение обезличено',
    $failures
);

check(
    !str_contains(json_encode(DatabaseSettings::publicPayload()), 'unit-secret'),
    'пароль не попадает в API-ответ',
    $failures
);

$log = @file_get_contents($logDir . '/app.log');

check(
    $log !== false && !str_contains($log, 'unit-secret') && !str_contains($log, 'SQLSTATE')
        && str_contains($log, '127.0.0.1') && str_contains($log, '3306'),
    'лог содержит нормализованные host/port, но не секреты',
    $failures
);

// --- c.3.3: интеграция crypto/writer и fail-closed чтения ---
final class FakeStatementForDbSettings extends PDOStatement
{
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [['ID' => 1]];
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return 1;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }
}

final class FakePdoForDbSettings extends PDO
{
    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return new FakeStatementForDbSettings();
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeStatementForDbSettings();
    }
}

final class BusyWriterForDbSettings extends SafeConfigFileWriter
{
    /** @return resource */
    protected function acquireLock(string $path)
    {
        throw new HttpException(409, 'config_busy', 'Конфигурация уже сохраняется');
    }
}

$configDir = sys_get_temp_dir() . '/crm7-dbsettings-c33-' . bin2hex(random_bytes(6));

if (!mkdir($configDir, 0700, true)) {
    throw new RuntimeException('Не удалось создать тестовый каталог конфигурации');
}

$configPath = $configDir . '/database.json';
DatabaseSettings::setPathOverride($configPath);
putenv('DB_CONFIG_KEY=unit-db-config-key-for-settings');
$c33Policy = new DatabaseHostPolicy(static fn (string $host): array => ['172.18.0.2']);
$pdoCalls = 0;
$fakeFactory = static function () use (&$pdoCalls): PDO {
    ++$pdoCalls;

    return new FakePdoForDbSettings();
};
$baseInput = ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'crm_local', 'user' => 'crm_app'];
$secretOne = 'unit-pw-one';

DatabaseSettings::save($baseInput + ['password' => $secretOne], '', $c33Policy, $fakeFactory);
$mainJson = json_decode((string) file_get_contents($configPath), true);
$encryptedOne = (string) $mainJson['password'];

check(str_starts_with($encryptedOne, 'dbcfg:v1:'), 'save пишет зашифрованный пароль', $failures);
check(!str_contains((string) file_get_contents($configPath), $secretOne), 'main JSON не содержит plaintext', $failures);
check(DatabaseSettings::current()['password'] === $secretOne, 'current расшифровывает пароль', $failures);

DatabaseSettings::save($baseInput, '', $c33Policy, $fakeFactory);
$mainJson2 = json_decode((string) file_get_contents($configPath), true);

check(str_starts_with((string) $mainJson2['password'], 'dbcfg:v1:'), 'повторный save сохраняет зашифрованный пароль', $failures);
check(
    App\Support\DatabaseConfigCrypto::decrypt((string) $mainJson2['password']) === $secretOne,
    'нет двойного шифрования',
    $failures
);

$legacy = 'unit-legacy-password';
file_put_contents($configPath, json_encode([
    'host' => '127.0.0.1', 'port' => 3306, 'database' => 'crm_local',
    'user' => 'crm_app', 'password' => $legacy
]));

DatabaseSettings::save($baseInput, '', $c33Policy, $fakeFactory);
$mainRaw = (string) file_get_contents($configPath);
$bakRaw = (string) file_get_contents($configPath . '.bak');
$mainLegacy = json_decode($mainRaw, true);
$bakLegacy = json_decode($bakRaw, true);

check(str_starts_with((string) $mainLegacy['password'], 'dbcfg:v1:'), 'legacy main мигрирует в encrypted', $failures);
check(str_starts_with((string) $bakLegacy['password'], 'dbcfg:v1:'), 'backup при миграции зашифрован', $failures);
check(!str_contains($mainRaw, $legacy) && !str_contains($bakRaw, $legacy), 'plaintext нет ни в main, ни в backup', $failures);

putenv('DB_CONFIG_KEY=unit-wrong-key');

fails(
    fn () => DatabaseSettings::current(),
    'config_invalid',
    'неверный ключ блокирует чтение',
    $failures,
    [$legacy, $secretOne]
);

putenv('DB_CONFIG_KEY=unit-db-config-key-for-settings');

$cases = [
    'dbcfg:v1:!!!' => 'повреждённый ciphertext',
    'dbcfg:v2:abc' => 'неизвестная версия формата',
    '{"host":"x"}' => 'некорректная структура',
    '{bad' => 'invalid JSON'
];

foreach ($cases as $badValue => $description) {
    $raw = is_string($badValue) && str_starts_with($badValue, '{')
        ? $badValue
        : json_encode([
            'host' => '127.0.0.1', 'port' => 3306, 'database' => 'crm_local',
            'user' => 'crm_app', 'password' => $badValue
        ]);
    file_put_contents($configPath, $raw);
    $callsBefore = $pdoCalls;

    fails(
        fn () => DatabaseSettings::current(),
        'config_invalid',
        "$description блокирует чтение без fallback",
        $failures
    );

    check($pdoCalls === $callsBefore, "$description: PDO не вызывается", $failures);
}

@unlink($configPath);
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=3306');
putenv('DB_NAME=crm_env');
putenv('DB_USER=env_user');
putenv('DB_PASS=env-pass');

check(DatabaseSettings::current()['source'] === 'env', 'ENV fallback работает без database.json', $failures);
check(DatabaseSettings::current()['password'] === 'env-pass', 'ENV fallback читает DB_PASS', $failures);

putenv('DB_CONFIG_KEY');
$beforeMissing = file_exists($configPath) ? file_get_contents($configPath) : null;

fails(
    fn () => DatabaseSettings::save($baseInput + ['password' => $secretOne], '', $c33Policy, $fakeFactory),
    'db_config_key_missing',
    'отсутствие ключа блокирует запись',
    $failures,
    [$secretOne]
);

check(
    (file_exists($configPath) ? file_get_contents($configPath) : null) === $beforeMissing,
    'при отсутствии ключа конфиг не изменён',
    $failures
);

putenv('DB_CONFIG_KEY=unit-db-config-key-for-settings');

file_put_contents($configPath, json_encode([
    'host' => '127.0.0.1', 'port' => 3306, 'database' => 'crm_local',
    'user' => 'crm_app', 'password' => App\Support\DatabaseConfigCrypto::encrypt('busy-pw')
]));

$busyWriter = new BusyWriterForDbSettings();
$beforeBusy = (string) file_get_contents($configPath);

fails(
    fn () => DatabaseSettings::save($baseInput, '', $c33Policy, $fakeFactory, $busyWriter),
    'config_busy',
    'config_busy передаётся контроллеру',
    $failures,
    ['busy-pw']
);

check((string) file_get_contents($configPath) === $beforeBusy, 'config_busy не повреждает основной конфиг', $failures);

$payload = DatabaseSettings::publicPayload();
check(!str_contains(json_encode($payload), 'busy-pw'), 'DB_CONFIG_KEY/пароль нет в API payload', $failures);
check(!str_contains(json_encode($payload), 'unit-db-config-key-for-settings'), 'ключ нет в API payload', $failures);

$configLog = @file_get_contents($logDir . '/app.log');
check(
    $configLog === false || (!str_contains($configLog, 'busy-pw') && !str_contains($configLog, 'unit-db-config-key-for-settings')),
    'секреты отсутствуют в логах',
    $failures
);

DatabaseSettings::setPathOverride(null);

foreach (glob($configDir . '/*') ?: [] as $file) {
    @unlink($file);
}

@rmdir($configDir);
putenv('DB_CONFIG_KEY');
putenv('DB_NAME');
putenv('DB_USER');
putenv('DB_PASS');

@unlink($logDir . '/app.log');
@rmdir($logDir);

putenv('LOG_DIR');
putenv('DB_HOST');
putenv('DB_PORT');
putenv('DB_PASS');
putenv('DB_ALLOW_HOST_CHANGE');
putenv('DB_HOST_ALLOWLIST');

echo $failures === [] ? "\nALL OK\n" : "\nFAILED: " . implode(', ', $failures) . "\n";

exit($failures === [] ? 0 : 1);
