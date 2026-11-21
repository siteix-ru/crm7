<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Http\HttpException;
use App\Stocks\StocksService;

$failures = [];

function check(bool $condition, string $name, array &$failures): void
{
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";

    if (!$condition) {
        $failures[] = $name;
    }
}

function expectHttp(callable $call, int $status, string $code, string $name, array &$failures): void
{
    try {
        $call();
        check(false, $name, $failures);
    } catch (HttpException $exception) {
        check($exception->status === $status && $exception->errorCode === $code, $name, $failures);
    }
}

function setStaticProperty(string $class, string $name, mixed $value): void
{
    $property = new ReflectionProperty($class, $name);
    $property->setValue(null, $value);
}

function setConfig(array $values): void
{
    setStaticProperty(Config::class, 'values', $values);
    setStaticProperty(Config::class, 'loaded', true);
}

final class FakeStatement extends PDOStatement
{
    public array $executeArgs = [];

    public function __construct(
        public readonly string $query,
        private FakePdo $owner,
        private mixed $result = null,
        private ?Throwable $error = null
    ) {
    }

    public function execute(?array $params = null): bool
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        $this->executeArgs = $params ?? [];

        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->query, 'GET_LOCK')) {
            return $this->owner->lockResult;
        }

        if (str_contains($this->query, 'RELEASE_LOCK')) {
            return $this->owner->releaseResult;
        }

        if (str_contains($this->query, 'FROM settings')) {
            $key = (string) ($this->executeArgs[0] ?? '');

            return str_starts_with($key, 'prices') ? '0' : '1';
        }

        return $this->result;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM stocks') || str_contains($this->query, 'FROM nomenclature')) {
            return ['ID' => 1, 'SID' => 's1', 'NAME' => 'WH1', 'NAME_1C' => 'WH1', 'UNIT' => 'pcs'];
        }

        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if (str_contains($this->query, 'GROUP BY')) {
            return [['SID' => 's1', 'NAME' => 'WH1']];
        }

        return [];
    }
}

final class FakePdo extends PDO
{
    /** @var array<int, FakeStatement> */
    public array $statements = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    public array $calls = [];

    public mixed $lockResult = '1';

    public mixed $releaseResult = '1';

    public bool $acquireThrows = false;

    public bool $releaseThrows = false;

    public bool $commitThrows = false;

    public bool $beginThrows = false;

    public bool $rollbackThrows = false;

    public bool $inTransaction = false;

    public string $throwPrepareContains = '';

    public function __construct()
    {
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->calls[] = ['prepare', $query];

        $forced = $this->throwPrepareContains !== '' && str_contains($query, $this->throwPrepareContains);

        $statement = new FakeStatement(
            $query,
            $this,
            null,
            $forced
                ? new PDOException('forced post-commit failure')
                : ($this->acquireThrows && str_contains($query, 'GET_LOCK') ? new PDOException('acquire failed') : null)
        );
        $this->statements[] = $statement;

        return $statement;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->calls[] = ['query', $query];

        $statement = new FakeStatement($query, $this);
        $this->statements[] = $statement;

        return $statement;
    }

    public function exec(string $statement): int|false
    {
        $this->calls[] = ['exec', $statement];

        return 0;
    }

    public function beginTransaction(): bool
    {
        $this->calls[] = ['beginTransaction', null];

        if ($this->beginThrows) {
            throw new PDOException('begin failed');
        }

        $this->inTransaction = true;

        return true;
    }

    public function commit(): bool
    {
        $this->calls[] = ['commit', null];

        if ($this->commitThrows) {
            throw new PDOException('commit failed');
        }

        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->calls[] = ['rollBack', null];
        $this->inTransaction = false;

        if ($this->rollbackThrows) {
            throw new PDOException('rollback failed');
        }

        return true;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '1';
    }

    public function statementFor(string $needle): ?FakeStatement
    {
        foreach ($this->statements as $statement) {
            if (str_contains($statement->query, $needle)) {
                return $statement;
            }
        }

        return null;
    }
}

function csvFile(): array
{
    $path = sys_get_temp_dir() . '/crm_import_' . bin2hex(random_bytes(6)) . '.csv';
    file_put_contents($path, "WH1;IT1;pcs;11\n");

    return [
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($path),
        'name' => 'fixture.csv',
        'tmp_name' => $path,
    ];
}

function service(): StocksService
{
    return new StocksService();
}

$user = ['ID' => 1, 'LEVEL' => 90];
$caps = ['stocks.import'];

setConfig(['LOG_DIR' => sys_get_temp_dir(), 'CRM_STOCK_IMPORT_LOCK_TIMEOUT' => '30']);

// GET_LOCK = 0 → 423, no job, no transaction
$pdo = new FakePdo();
$pdo->lockResult = '0';
setStaticProperty(Database::class, 'pdo', $pdo);
expectHttp(static fn () => service()->import($user, $caps, csvFile(), '2026-01-01'),
    423, 'import_busy', 'GET_LOCK=0 → 423 import_busy', $failures);
check($pdo->statementFor('stock_import_jobs') === null, 'timeout: job не создаётся', $failures);
check(!in_array(['beginTransaction', null], $pdo->calls, true), 'timeout: транзакция не началась', $failures);
check($pdo->statementFor('RELEASE_LOCK') === null, 'timeout: lock не снимался (не был взят)', $failures);

// GET_LOCK = NULL → 503
$pdo = new FakePdo();
$pdo->lockResult = null;
setStaticProperty(Database::class, 'pdo', $pdo);
expectHttp(static fn () => service()->import($user, $caps, csvFile(), '2026-01-01'),
    503, 'import_unavailable', 'GET_LOCK=NULL → 503', $failures);
check($pdo->statementFor('stock_import_jobs') === null, 'acquisition error: job не создаётся', $failures);

// GET_LOCK exception → 503
$pdo = new FakePdo();
$pdo->acquireThrows = true;
setStaticProperty(Database::class, 'pdo', $pdo);
expectHttp(static fn () => service()->import($user, $caps, csvFile(), '2026-01-01'),
    503, 'import_unavailable', 'PDOException при acquisition → 503', $failures);

// Happy path: order, single release, correct params
$pdo = new FakePdo();
setStaticProperty(Database::class, 'pdo', $pdo);
$result = service()->import($user, $caps, csvFile(), '2026-01-01');
check($result['status'] === 'done', 'успешный импорт → done', $failures);
$names = array_column($pdo->calls, 0);
check(count(array_keys($names, 'prepare', true)) >= 2, 'GET_LOCK и RELEASE_LOCK подготовлены', $failures);
$acquire = $pdo->statementFor('GET_LOCK');
$release = $pdo->statementFor('RELEASE_LOCK');
check($acquire !== null && $acquire->executeArgs === ['crm7_stocks_import:v1', 30],
    'lock name и timeout=30 переданы в GET_LOCK', $failures);
check($release !== null && $release->executeArgs === ['crm7_stocks_import:v1'],
    'тот же lock name передан в RELEASE_LOCK', $failures);
check(array_search('beginTransaction', $names, true) < array_search('commit', $names, true),
    'beginTransaction до commit', $failures);
$commitIndex = array_search('commit', $names, true);
$releaseIndex = null;
foreach ($pdo->calls as $i => $call) {
    if ($call[0] === 'prepare' && str_contains((string) $call[1], 'RELEASE_LOCK')) {
        $releaseIndex = $i;
    }
}
check($releaseIndex !== null && $releaseIndex > $commitIndex, 'RELEASE_LOCK после commit', $failures);
check(count(array_filter($pdo->statements, static fn ($s) => str_contains($s->query, 'RELEASE_LOCK'))) === 1,
    'RELEASE_LOCK вызван ровно один раз', $failures);

// Exception inside transaction: rollback + single release + failed job
$pdo = new FakePdo();
setStaticProperty(Database::class, 'pdo', $pdo);
$pdo->commitThrows = true;
expectHttp(static fn () => service()->import($user, $caps, csvFile(), '2026-01-01'),
    500, 'import_failed', 'PDOException после beginTransaction → 500', $failures);
check(in_array(['rollBack', null], $pdo->calls, true), 'rollback вызван', $failures);
check(count(array_filter($pdo->statements, static fn ($s) => str_contains($s->query, 'RELEASE_LOCK'))) === 1,
    'release вызван один раз после ошибки', $failures);
$job = $pdo->statementFor('UPDATE stock_import_jobs');
check($job !== null && ($job->executeArgs[0] ?? null) === 'failed', 'finishJob(failed) вызван', $failures);

// RELEASE_LOCK failure must not replace successful result
$pdo = new FakePdo();
$pdo->releaseResult = null;
setStaticProperty(Database::class, 'pdo', $pdo);
$result = service()->import($user, $caps, csvFile(), '2026-01-01');
check($result['status'] === 'done', 'ошибка RELEASE_LOCK не портит успех', $failures);

// F-01: post-commit finishJob failure must not become import failure
$pdo = new FakePdo();
$pdo->throwPrepareContains = 'UPDATE stock_import_jobs';
setStaticProperty(Database::class, 'pdo', $pdo);
$result = service()->import($user, $caps, csvFile(), '2026-01-01');
check($result['status'] === 'done', 'finishJob(done) после commit не меняет успешный ответ', $failures);
check(!in_array(['rollBack', null], $pdo->calls, true), 'после commit rollback не вызывается', $failures);
check(count(array_filter($pdo->statements, static fn ($s) => str_contains($s->query, 'RELEASE_LOCK'))) === 1,
    'release вызван один раз при post-commit error', $failures);

// F-01: post-commit audit failure (AuditService уже гасит, проверяем итог)
$pdo = new FakePdo();
$pdo->throwPrepareContains = 'audit_log';
setStaticProperty(Database::class, 'pdo', $pdo);
$result = service()->import($user, $caps, csvFile(), '2026-01-01');
check($result['status'] === 'done', 'audit error после commit не меняет успешный ответ', $failures);

// F-03: beginTransaction failure → rollback не вызывается
$pdo = new FakePdo();
$pdo->beginThrows = true;
setStaticProperty(Database::class, 'pdo', $pdo);
expectHttp(static fn () => service()->import($user, $caps, csvFile(), '2026-01-01'),
    500, 'import_failed', 'beginTransaction failure → 500', $failures);
check(!in_array(['rollBack', null], $pdo->calls, true), 'нет активной транзакции → rollback не вызывается', $failures);

// F-03: rollback failure не маскирует primary exception
$pdo = new FakePdo();
$pdo->commitThrows = true;
$pdo->rollbackThrows = true;
setStaticProperty(Database::class, 'pdo', $pdo);
expectHttp(static fn () => service()->import($user, $caps, csvFile(), '2026-01-01'),
    500, 'import_failed', 'rollback error не маскирует primary', $failures);
check(in_array(['rollBack', null], $pdo->calls, true), 'rollback вызван при активной транзакции', $failures);

// Timeout configuration validation
$method = new ReflectionMethod(StocksService::class, 'importLockTimeout');
foreach ([['abc', 30], ['0', 30], ['61', 30], ['45', 45], [null, 30]] as [$value, $expected]) {
    $cfg = $value === null
        ? ['LOG_DIR' => sys_get_temp_dir()]
        : ['LOG_DIR' => sys_get_temp_dir(), 'CRM_STOCK_IMPORT_LOCK_TIMEOUT' => $value];
    setConfig($cfg);
    check($method->invoke(service()) === $expected,
        'timeout config ' . var_export($value, true) . ' → ' . $expected, $failures);
}

setConfig(['LOG_DIR' => sys_get_temp_dir(), 'CRM_STOCK_IMPORT_LOCK_TIMEOUT' => '30']);

// F-02: integration guard —invalid config должен SKIP до подключения.
function runIntegrationGuard(array $overrides): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/stock-import-lock-integration.php');
    $env = getenv() ?: [];

    foreach ($overrides as $key => $value) {
        $env[$key] = $value;
    }

    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, $env);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

$baseEnv = [
    'CRM_STOCK_TEST_DSN' => 'mysql:host=127.0.0.1;port=1;dbname=crm_guard_test',
    'CRM_STOCK_TEST_USER' => 'u',
    'CRM_STOCK_TEST_PASS' => 'p',
    'CRM_STOCK_TEST_BASE' => 'http://127.0.0.1:1',
    'CRM_STOCK_TEST_TOKEN' => 't',
    'CRM_STOCK_TEST_DISPOSABLE' => '1',
];

[$code, $out] = runIntegrationGuard([]);
check($code === 0 && str_contains($out, 'SKIP'), 'guard: missing config → SKIP', $failures);

$env = $baseEnv;
$env['CRM_STOCK_TEST_DSN'] = 'mysql:host=db.example.com;dbname=crm_guard_test';
[$code, $out] = runIntegrationGuard($env);
check($code === 0 && str_contains($out, 'host не loopback'), 'guard: non-local host → SKIP', $failures);

$env = $baseEnv;
$env['CRM_STOCK_TEST_DSN'] = 'mysql:host=127.0.0.1;dbname=crm_prod';
[$code, $out] = runIntegrationGuard($env);
check($code === 0 && str_contains($out, 'disposable-конвенции'), 'guard: production-like DB name → SKIP', $failures);

$env = $baseEnv;
$env['CRM_STOCK_TEST_DSN'] = 'mysql:host=127.0.0.1;dbname=crm_test';
[$code, $out] = runIntegrationGuard($env);
check($code === 0 && str_contains($out, 'SKIP'), 'guard: ambiguous DB name → SKIP', $failures);

$env = $baseEnv;
$env['CRM_STOCK_TEST_DSN'] = 'mysql:host=127.0.0.1;dbname=crm_guard_test_backup';
[$code, $out] = runIntegrationGuard($env);
check($code === 0 && str_contains($out, 'SKIP'), 'guard: naming convention → SKIP', $failures);

$env = $baseEnv;
$env['CRM_STOCK_TEST_DISPOSABLE'] = '0';
[$code, $out] = runIntegrationGuard($env);
check($code === 0 && str_contains($out, 'CRM_STOCK_TEST_DISPOSABLE'), 'guard: explicit флаг обязателен', $failures);

// Valid disposable config проходит guard (дамп ниже — локальный порт без сервера,
// реальные данные не используются; ожидается отказ PDO, но не SKIP).
$env = $baseEnv;
$env['CRM_STOCK_TEST_BASE'] = 'http://127.0.0.1:1';
[$code, $out] = runIntegrationGuard($env);
check($code !== 0 && !str_contains($out, 'SKIP'), 'guard: valid disposable config проходит проверку', $failures);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d\n", count($failures)));
    exit(1);
}

echo "\nВсе unit-тесты serialization import пройдены\n";
