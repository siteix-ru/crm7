<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Http\HttpException;
use App\Repositories\SettingsRepository;
use App\Repositories\StockReservationRepository;
use App\Stocks\StockReservationService;
use App\Stocks\StocksService;

function guardFail(string $message): void
{
    fwrite(STDERR, "GUARD FAIL: " . $message . "\n");
    exit(2);
}

$dsn = (string) (getenv('CRM_STOCK_TEST_DSN') ?: '');
$user = (string) (getenv('CRM_STOCK_TEST_USER') ?: '');
$password = (string) (getenv('CRM_STOCK_TEST_PASS') ?: '');
$port = (string) (getenv('CRM_STOCK_TEST_PORT') ?: '');
$sentinel = (string) (getenv('CRM_STOCK_TEST_SENTINEL') ?: '');
$disposable = (string) (getenv('CRM_STOCK_TEST_DISPOSABLE') ?: '');

if ($dsn === '' || $user === '' || $password === '' || $port === '' || $sentinel === '') {
    guardFail('missing disposable database environment');
}
if ($disposable !== '1') {
    guardFail('CRM_STOCK_TEST_DISPOSABLE must be 1');
}
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sentinel) !== 1) {
    guardFail('CRM_STOCK_TEST_SENTINEL must be UUID v4');
}
if (strpos($dsn, 'mysql:') !== 0) {
    guardFail('DSN must be a mysql DSN');
}

$parts = [];
foreach (explode(';', substr($dsn, strlen('mysql:'))) as $item) {
    $pair = explode('=', $item, 2);
    if (count($pair) === 2) {
        $parts[$pair[0]] = $pair[1];
    }
}
$host = strtolower((string) ($parts['host'] ?? ''));
$dbName = (string) ($parts['dbname'] ?? '');
$dsnPort = (string) ($parts['port'] ?? '');
$charset = (string) ($parts['charset'] ?? '');

if (!in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
    guardFail('DB host must be loopback');
}
if (preg_match('/^crm_[a-z0-9]{1,20}_test$/', $dbName) !== 1) {
    guardFail('DB name is not disposable');
}
if (!ctype_digit($dsnPort) || $dsnPort !== $port || $charset !== 'utf8mb4') {
    guardFail('DSN port/charset mismatch');
}

putenv('DB_HOST=' . $host);
putenv('DB_PORT=' . $dsnPort);
putenv('DB_NAME=' . $dbName);
putenv('DB_USER=' . $user);
putenv('DB_PASS=' . $password);
Config::load(__DIR__ . '/../config/.env');

function connect(): PDO
{
    global $dsn, $user, $password;
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
    $prop = new ReflectionProperty(Database::class, 'pdo');
    $prop->setValue(null, $pdo);

    return $pdo;
}

function writeSignal(string $dir, string $name, array $data): void
{
    file_put_contents($dir . '/' . $name, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function readSignal(string $dir, string $name): array
{
    $path = $dir . '/' . $name;
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

function waitForSignal(string $dir, string $name, float $timeoutSec = 20): bool
{
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $dir . '/' . $name);
        if (is_file($dir . '/' . $name)) {
            return true;
        }
        usleep(20000);
    }

    return false;
}

function cleanupFixtures(PDO $pdo, array $sids, array $nameSids, array $requestIds): void
{
    foreach ($requestIds as $requestId) {
        $pdo->prepare('DELETE FROM stock_reservations WHERE request_id = ?')->execute([$requestId]);
        $pdo->prepare('DELETE FROM request_items WHERE request_id = ?')->execute([$requestId]);
    }
    if ($sids !== []) {
        $placeholders = implode(',', array_fill(0, count($sids), '?'));
        $pdo->prepare('DELETE FROM stock_levels WHERE STOCK_SID IN (' . $placeholders . ')')->execute($sids);
        $pdo->prepare('DELETE FROM stocks WHERE SID IN (' . $placeholders . ')')->execute($sids);
    }
    if ($nameSids !== []) {
        $placeholders = implode(',', array_fill(0, count($nameSids), '?'));
        $pdo->prepare('DELETE FROM nomenclature WHERE SID IN (' . $placeholders . ')')->execute($nameSids);
    }
}

function counts(PDO $pdo): array
{
    return [
        'stocks' => (int) $pdo->query('SELECT COUNT(*) FROM stocks')->fetchColumn(),
        'levels' => (int) $pdo->query('SELECT COUNT(*) FROM stock_levels')->fetchColumn(),
        'reservations' => (int) $pdo->query('SELECT COUNT(*) FROM stock_reservations')->fetchColumn(),
    ];
}

function orphans(PDO $pdo): int
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM stock_reservations r LEFT JOIN stock_levels l ON l.ID = r.stock_level_id WHERE l.ID IS NULL'
    )->fetchColumn();
}

function levelQuantity(PDO $pdo, int $levelId): float
{
    return (float) $pdo->query('SELECT QUANTITY FROM stock_levels WHERE ID = ' . $levelId)->fetchColumn();
}

// ------------------------------------------------------------------ worker mode
$role = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--role=') === 0) {
        $role = substr($arg, 7);
    }
}

if ($role === 'reverse_reserve') {
    $opt = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
            $opt[$m[1]] = $m[2];
        }
    }
    $dir = (string) ($opt['dir'] ?? '');
    $levelId = (int) ($opt['level'] ?? 0);
    $requestId = (int) ($opt['request'] ?? 0);
    $qty = (float) ($opt['qty'] ?? 0);

    $pdo = connect();
    $pdo->beginTransaction();
    $service = new StockReservationService();
    $service->lock($requestId);
    try {
        writeSignal($dir, 'reverse_started', ['pid' => getmypid(), 'level' => $levelId]);
        // Blocks on shared topology lock held exclusively by connection A.
        $service->apply($requestId, [['stock_level_id' => $levelId, 'quantity' => $qty]]);
        $pdo->commit();
        writeSignal($dir, 'done_reverse', ['outcome' => 'success', 'level' => $levelId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        writeSignal($dir, 'done_reverse', [
            'outcome' => 'error',
            'code' => $e instanceof HttpException ? $e->errorCode : get_class($e),
            'level' => $levelId,
            'message' => $e->getMessage(),
        ]);
    } finally {
        $service->unlock($requestId);
    }
    exit(0);
}

if ($role === 'reserve') {
    $opt = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
            $opt[$m[1]] = $m[2];
        }
    }
    $dir = (string) ($opt['dir'] ?? '');
    $levelId = (int) ($opt['level'] ?? 0);
    $requestId = (int) ($opt['request'] ?? 0);
    $qty = (float) ($opt['qty'] ?? 0);

    $pdo = connect();
    $pdo->beginTransaction();
    $service = new StockReservationService();
    $service->lock($requestId);
    try {
        $service->apply($requestId, [['stock_level_id' => $levelId, 'quantity' => $qty]]);
        writeSignal($dir, 'reserved', ['pid' => getmypid()]);
        if (!waitForSignal($dir, 'go_commit', 20)) {
            throw new RuntimeException('go_commit timeout');
        }
        $pdo->commit();
        writeSignal($dir, 'done_reserve', ['outcome' => 'success']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        writeSignal($dir, 'done_reserve', ['outcome' => 'error', 'message' => $e->getMessage()]);
    } finally {
        $service->unlock($requestId);
    }
    exit(0);
}

// ------------------------------------------------------------------ coordinator
$failures = [];
function check(bool $condition, string $name): void
{
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures[] = $name;
    }
}

$settings = new SettingsRepository();
$originalReserve = $settings->get('stock.reserve_enabled');
$originalAllowZero = $settings->get('stock.allow_zero');
$settings->set('stock.reserve_enabled', '1');
$settings->set('stock.allow_zero', '0');

$pdo = connect();
$row = $pdo->query('SELECT marker FROM crm_disposable_sentinel WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
check($row !== false && isset($row['marker']) && hash_equals($sentinel, (string) $row['marker']),
    'disposable sentinel verified');

$user = ['ID' => 1, 'SID' => 'collapse-test', 'LEVEL' => 90];
$suffix = bin2hex(random_bytes(4));
$sids = [];
$nameSids = [];
$requestIds = [];
$collapsed = false;

function makeFixture(PDO $pdo, string $suffix, array &$sids, array &$nameSids): int
{
    $stockSid = 'collapse-wh-' . $suffix;
    $nameSid = 'collapse-nm-' . $suffix;
    $pdo->prepare("INSERT INTO stocks (NAME, NAME_1C, SID, ACTIVE, STATUS, LEVEL) VALUES (?, ?, ?, 'Y', 'Y', '{90},{50},{10},{5},{1},')")
        ->execute(['Склад ' . $suffix, 'C-' . $suffix, $stockSid]);
    $pdo->prepare("INSERT INTO nomenclature (NAME, NAME_1C, SID, UNIT, ACTIVE) VALUES (?, ?, ?, 'шт', 'Y')")
        ->execute(['Товар ' . $suffix, 'T-' . $suffix, $nameSid]);
    $pdo->prepare("INSERT INTO stock_levels (STOCK_SID, NAME_SID, NAME, UNIT, QUANTITY, ACTIVE) VALUES (?, ?, ?, 'шт', 5, 'Y')")
        ->execute([$stockSid, $nameSid, 'Товар ' . $suffix]);
    $sids[] = $stockSid;
    $nameSids[] = $nameSid;

    return (int) $pdo->lastInsertId();
}

// Scenario 1: R=0 -> collapse succeeds and preserves aggregate.
$level1 = makeFixture($pdo, $suffix . 'a', $sids, $nameSids);
$service = new StocksService();
$service->collapseWarehouses($user);
$collapsed = true;
check((int) $pdo->query('SELECT COUNT(*) FROM stocks')->fetchColumn() === 1, 'S1 single warehouse after collapse');
check((float) $pdo->query('SELECT QUANTITY FROM stock_levels WHERE NAME_SID = \'' . $nameSids[0] . '\'')->fetchColumn() === 5.0,
    'S1 quantity preserved');

// Scenario 2: R>0 -> 423, no data changes, no backup.
$suffix2 = $suffix . 'b';
$level2 = makeFixture($pdo, $suffix2, $sids, $nameSids);
$request2 = 3000000 + random_int(1, 900000);
$requestIds[] = $request2;
$resService = new StockReservationService();
$resService->lock($request2);
try {
    $resService->apply($request2, [['stock_level_id' => $level2, 'quantity' => 2]]);
} finally {
    $resService->unlock($request2);
}
check(levelQuantity($pdo, $level2) === 3.0, 'S2 reservation reduced available to 3');
$before = counts($pdo);
$backupBefore = (int) $pdo->query('SELECT COUNT(*) FROM stock_levels_backup')->fetchColumn();
$rejected = null;
try {
    (new StocksService())->collapseWarehouses($user);
} catch (HttpException $e) {
    $rejected = $e;
}
check($rejected instanceof HttpException && $rejected->status === 423 && $rejected->errorCode === 'warehouse_has_reservations',
    'S2 collapse rejected with 423 warehouse_has_reservations');
check(counts($pdo) === $before, 'S2 no data changes on rejection');
check((int) $pdo->query('SELECT COUNT(*) FROM stock_levels_backup')->fetchColumn() === $backupBefore,
    'S2 no backup created on rejection');

// Scenario 3: after rejection release restores the balance.
$resService->lock($request2);
try {
    $resService->release($request2);
} finally {
    $resService->unlock($request2);
}
check(levelQuantity($pdo, $level2) === 5.0, 'S3 release restored available to 5');
check((int) $pdo->query('SELECT COUNT(*) FROM stock_reservations WHERE request_id = ' . $request2)->fetchColumn() === 0,
    'S3 reservation removed');

// Scenario 4: concurrent reserve holds shared lock; collapse must not create orphans.
$suffix4 = $suffix . 'd';
$level4 = makeFixture($pdo, $suffix4, $sids, $nameSids);
$request4 = 4000000 + random_int(1, 900000);
$requestIds[] = $request4;
$dir = sys_get_temp_dir() . '/crm_i116_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
    . ' --role=reserve --dir=' . escapeshellarg($dir)
    . ' --level=' . $level4 . ' --request=' . $request4 . ' --qty=2';
$pipes = [];
proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
check(waitForSignal($dir, 'reserved', 20), 'S4 worker reserved and holds shared topology lock');
$busy = null;
try {
    (new StocksService())->collapseWarehouses($user);
} catch (HttpException $e) {
    $busy = $e;
}
check($busy instanceof HttpException && $busy->status === 423 && $busy->errorCode === 'inventory_busy',
    'S4 collapse returned 423 inventory_busy while reservation in flight');
writeSignal($dir, 'go_commit', ['at' => microtime(true)]);
check(waitForSignal($dir, 'done_reserve', 20), 'S4 worker finished');
$done = readSignal($dir, 'done_reserve');
check(($done['outcome'] ?? '') === 'success', 'S4 reservation committed');
check(orphans($pdo) === 0, 'S4 no orphan reservations');
check((int) $pdo->query('SELECT COUNT(*) FROM stock_reservations WHERE request_id = ' . $request4)->fetchColumn() === 1,
    'S4 concurrent reservation survived');
foreach (glob($dir . '/*') ?: [] as $file) {
    unlink($file);
}
rmdir($dir);

// Scenario 5: lock released after 423 rejection (another collapse can run after reservations are cleared).
$resService->lock($request4);
try {
    $resService->release($request4);
} finally {
    $resService->unlock($request4);
}
$afterRelease = null;
try {
    (new StocksService())->collapseWarehouses($user);
} catch (HttpException $e) {
    $afterRelease = $e;
}
check($afterRelease === null, 'S5 collapse succeeds after lock released and R=0');
check(orphans($pdo) === 0, 'S5 no orphans after successful collapse');

// Scenario 6: reverse lock order — connection A takes exclusive lock first,
// connection B starts apply() and blocks on the shared lock.
$suffix6 = $suffix . 'r';
$oldLevelId = makeFixture($pdo, $suffix6, $sids, $nameSids);
$level6NameSid = end($nameSids);
$request6 = 5000000 + random_int(1, 900000);
$requestIds[] = $request6;
$dir6 = sys_get_temp_dir() . '/crm_i116r_' . bin2hex(random_bytes(6));
mkdir($dir6, 0700, true);

$pdo->beginTransaction();
(new StockReservationRepository())->lockTopologyExclusive();
writeSignal($dir6, 'exclusive_ready', ['pid' => getmypid()]);
$cmd6 = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
    . ' --role=reverse_reserve --dir=' . escapeshellarg($dir6)
    . ' --level=' . $oldLevelId . ' --request=' . $request6 . ' --qty=2';
$pipes6 = [];
proc_open($cmd6, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes6);
check(waitForSignal($dir6, 'reverse_started', 20), 'S6 connection B started apply while A holds exclusive lock');

// Manual collapse on connection A inside the same transaction/lock.
$singleSid6 = StocksService::SINGLE_WAREHOUSE_SID;
$singleName6 = $settings->singleWarehouseName();
$pdo->exec('DELETE FROM stock_levels');
$pdo->exec('DELETE FROM nomenclature_prices');
$pdo->exec('DELETE FROM user_level_stock');
$pdo->exec('DELETE FROM stocks');
$pdo->prepare(
    "INSERT INTO stocks (NAME, NAME_1C, SID, SORT, LEVEL, ACTIVE, STATUS) VALUES (?, ?, ?, 0, ?, 'Y', 'Y')"
)->execute([$singleName6, $singleSid6, $singleSid6, '{90},{50},{10},{5},{1},']);
$pdo->prepare(
    "INSERT INTO stock_levels (STOCK_SID, NAME_SID, NAME, UNIT, QUANTITY, ACTIVE)
     VALUES (?, ?, 'Товар reverse', 'шт', 5, 'Y')"
)->execute([$singleSid6, $level6NameSid]);
$pdo->commit();
writeSignal($dir6, 'collapse_done', ['at' => microtime(true)]);

check(waitForSignal($dir6, 'done_reverse', 20), 'S6 connection B finished after collapse commit');
$res6 = readSignal($dir6, 'done_reverse');
echo 'S6 result=' . json_encode($res6, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
$newLevelId6 = (int) $pdo->query(
    "SELECT ID FROM stock_levels WHERE NAME_SID = '" . $level6NameSid . "' LIMIT 1"
)->fetchColumn();
check($newLevelId6 > 0 && $newLevelId6 !== $oldLevelId, 'S6 collapse created a new level ID');
check(($res6['level'] ?? 0) === $oldLevelId, 'S6 connection B used the pre-collapse level ID');
check(($res6['outcome'] ?? '') === 'error' && ($res6['code'] ?? '') === 'insufficient_stock',
    'S6 B failed explicitly with insufficient_stock (no silent success)');
check((int) $pdo->query('SELECT COUNT(*) FROM stock_reservations WHERE request_id = ' . $request6)->fetchColumn() === 0,
    'S6 no reservation row for failed B');
check(orphans($pdo) === 0, 'S6 no orphan reservations');
$available6 = levelQuantity($pdo, $newLevelId6);
$reserved6 = (float) $pdo->query('SELECT COALESCE(SUM(quantity),0) FROM stock_reservations')->fetchColumn();
check(abs(($available6 + $reserved6) - 5.0) < 0.00001, 'S6 available + reserved = physical (5)');
foreach (glob($dir6 . '/*') ?: [] as $file) {
    unlink($file);
}
rmdir($dir6);

// Cleanup.
if (!$collapsed) {
    try {
        (new StocksService())->collapseWarehouses($user);
    } catch (Throwable) {
    }
}
cleanupFixtures($pdo, $sids, $nameSids, $requestIds);
$settings->set('stock.reserve_enabled', $originalReserve);
$settings->set('stock.allow_zero', $originalAllowZero);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "\nALL OK\n";
