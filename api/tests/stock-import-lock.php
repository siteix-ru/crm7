<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Http\HttpException;
use App\Stocks\StocksService;

$failures = [];
function check(bool $ok, string $name, array &$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) { $failures[] = $name; }
}
function setStatic(string $class, string $prop, mixed $value): void {
    $p = new ReflectionProperty($class, $prop);
    $p->setValue(null, $value);
}
function expectHttp(callable $fn, int $status, string $code, string $name, array &$failures): void {
    try { $fn(); check(false, $name . ' (no exception)', $failures); }
    catch (HttpException $e) {
        check($e->status === $status && $e->errorCode === $code,
            $name . ' (got ' . $e->status . '/' . $e->errorCode . ')', $failures);
    }
}

final class StubStatement extends PDOStatement {
    public array $args = [];
    public function __construct(
        public readonly string $sql,
        private StubPdo $owner,
        private ?Throwable $error = null
    ) {}
    public function execute(?array $params = null): bool {
        if ($this->error) { throw $this->error; }
        $this->args = $params ?? [];
        $this->owner->executed[] = ['sql' => $this->sql, 'args' => $this->args];
        return true;
    }
    public function rowCount(): int { return 1; }
    public function fetchColumn(int $c = 0): mixed {
        if (str_contains($this->sql, 'GET_LOCK')) { return $this->owner->lockResult; }
        if (str_contains($this->sql, 'RELEASE_LOCK')) { return $this->owner->releaseResult; }
        if (str_contains($this->sql, 'FROM settings')) {
            $key = (string) ($this->args[0] ?? '');
            return str_starts_with($key, 'prices') ? '0' : '1';
        }
        return null;
    }
    public function fetch(int $m = PDO::FETCH_DEFAULT, int $o = PDO::FETCH_ORI_NEXT, int $off = 0): mixed {
        if (str_contains($this->sql, 'FROM stock_import_operations')) {
            return [
                'ID'=>1,'user_id'=>1,'IDEMPOTENCY_TOKEN'=>'t','PAYLOAD_HASH'=>'h','STATE'=>'reserved',
                'GENERATION'=>1,'OWNER_ID'=>null,'LEASE_EXPIRES_AT'=>null,'CURRENT_ATTEMPT_ID'=>1,
                'RESPONSE_JSON'=>null,'completed_at'=>null,'failed_at'=>null,'token_cleared_at'=>null,
            ];
        }
        if (str_contains($this->sql, 'FROM stock_import_attempts')) {
            return [
                'ID'=>1,'operation_id'=>1,'attempt_no'=>1,'generation'=>1,'JOB_ID'=>1,
                'STATE'=>'reserved','COMMIT_MARKER'=>null,'RESPONSE_JSON'=>null,'ERROR_CODE'=>null,
            ];
        }
        if (str_contains($this->sql, 'FROM stocks') || str_contains($this->sql, 'FROM nomenclature')) {
            return ['ID'=>1,'SID'=>'s1','NAME'=>'WH1','NAME_1C'=>'WH1','UNIT'=>'pcs'];
        }
        return false;
    }
    public function fetchAll(int $m = PDO::FETCH_DEFAULT, mixed ...$a): array {
        if (str_contains($this->sql, 'GROUP BY')) { return [['SID'=>'s1','NAME'=>'WH1']]; }
        return [];
    }
}

final class StubPdo extends PDO {
    public array $calls = [];
    public array $executed = [];
    public mixed $lockResult = '1';
    public mixed $releaseResult = '1';
    public bool $inTx = false;
    public function __construct() {}
    public function prepare(string $q, array $o = []): PDOStatement|false {
        $this->calls[] = ['prepare', $q];
        return new StubStatement($q, $this);
    }
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
        $this->calls[] = ['query', $q];
        return new StubStatement($q, $this);
    }
    public function exec(string $q): int|false { return 1; }
    public function beginTransaction(): bool { $this->inTx = true; return true; }
    public function commit(): bool { $this->inTx = false; return true; }
    public function rollBack(): bool { $this->inTx = false; return true; }
    public function inTransaction(): bool { return $this->inTx; }
    public function lastInsertId(?string $n = null): string|false { return '1'; }
}

function fixture(): array {
    $path = sys_get_temp_dir() . '/crm_i65_' . bin2hex(random_bytes(5)) . '.csv';
    file_put_contents($path, "WH1;IT1;pcs;11\n");
    return ['error'=>UPLOAD_ERR_OK,'size'=>filesize($path),'name'=>'f.csv','tmp_name'=>$path];
}
function makePdo(mixed $lock = '1', mixed $release = '1'): StubPdo {
    $p = new StubPdo();
    $p->lockResult = $lock;
    $p->releaseResult = $release;
    setStatic(Database::class, 'pdo', $p);
    setStatic(Config::class, 'values', ['LOG_DIR'=>sys_get_temp_dir(),'CRM_STOCK_IMPORT_LOCK_TIMEOUT'=>'30']);
    setStatic(Config::class, 'loaded', true);
    return $p;
}

/** @return list<array{sql: string, args: array}> */
function releaseCalls(StubPdo $pdo): array {
    return array_values(array_filter(
        $pdo->executed,
        static fn (array $call): bool => str_contains($call['sql'], 'RELEASE_LOCK')
    ));
}

/** Перенаправляет error_log во временный файл на время вызова и возвращает его содержимое. */
function captureErrorLog(callable $fn): string {
    $path = sys_get_temp_dir() . '/crm_i68_log_' . bin2hex(random_bytes(5)) . '.log';
    $previous = ini_get('error_log');
    ini_set('error_log', $path);
    try {
        $fn();
    } finally {
        ini_set('error_log', $previous === false ? '' : $previous);
    }
    $content = is_file($path) ? (string) file_get_contents($path) : '';
    @unlink($path);

    return $content;
}

/** Проверяет, что RELEASE_LOCK вызван ровно один раз с ожидаемым именем лока. */
function checkReleaseOnce(StubPdo $pdo, string $name, array &$failures): void {
    $calls = releaseCalls($pdo);
    check(count($calls) === 1, $name . ': ровно один вызов (actual=' . count($calls) . ')', $failures);
    check(($calls[0]['sql'] ?? null) === 'SELECT RELEASE_LOCK(?)', $name . ': SQL', $failures);
    check(($calls[0]['args'] ?? null) === ['crm7_stocks_import:v1'], $name . ': аргумент — имя лока', $failures);
}

$user = ['ID'=>1,'LEVEL'=>90];
$caps = ['stocks.import'];

$p = makePdo('0');
expectHttp(fn() => (new StocksService())->import($user,$caps,fixture(),'2026-01-01'), 423, 'import_busy', 'GET_LOCK=0 → 423', $failures);
checkReleaseOnce($p, 'RELEASE_LOCK в finally при 423', $failures);

$p = makePdo(null);
expectHttp(fn() => (new StocksService())->import($user,$caps,fixture(),'2026-01-01'), 503, 'import_unavailable', 'GET_LOCK=NULL → 503', $failures);
checkReleaseOnce($p, 'RELEASE_LOCK в finally при 503', $failures);

$p = makePdo();
$res = (new StocksService())->import($user,$caps,fixture(),'2026-01-01');
check($res['status'] === 'done', 'успешный импорт → done', $failures);
checkReleaseOnce($p, 'RELEASE_LOCK при успешном импорте', $failures);

$p = makePdo('1', null);
$res = null;
$releaseLog = captureErrorLog(function () use (&$res, $user, $caps): void {
    $res = (new StocksService())->import($user, $caps, fixture(), '2026-01-01');
});
check($res['status'] === 'done', 'RELEASE_LOCK error не портит успех', $failures);
checkReleaseOnce($p, 'RELEASE_LOCK при ошибке release', $failures);
check(str_contains($releaseLog, 'RELEASE_LOCK did not release'), 'ошибка RELEASE_LOCK залогирована', $failures);

$p = makePdo();
expectHttp(fn() => (new StocksService())->import($user,$caps,fixture(),'2026-01-01',[],'bad-key'), 422, 'invalid_idempotency_key', 'невалидный UUID → 422', $failures);

$p = makePdo();
$res = (new StocksService())->import($user,$caps,fixture(),'2026-01-01',[],'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
check($res['status'] === 'done', 'валидный UUID → успех', $failures);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d\n", count($failures)));
    exit(1);
}
echo "\nВсе unit-тесты idempotency пройдены\n";
