<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Http\HttpException;
use App\Repositories\SettingsRepository;
use App\Repositories\StockReservationRepository;
use App\Stocks\StockReservationService;

/**
 * Two independent PDO connections are used by worker child processes. The parent
 * coordinates them with file barriers, so both transactions overlap.
 *
 * Environment: CRM_STOCK_TEST_DSN / _USER / _PASS / _PORT / _SENTINEL / _DISPOSABLE.
 */

function guardFail(string $message): void
{
    fwrite(STDERR, "GUARD FAIL: " . $message . "\n");
    exit(2);
}

function env(string $key): string
{
    return (string) (getenv($key) ?: '');
}

$dsn = env('CRM_STOCK_TEST_DSN');
$user = env('CRM_STOCK_TEST_USER');
$password = env('CRM_STOCK_TEST_PASS');
$port = env('CRM_STOCK_TEST_PORT');
$sentinel = env('CRM_STOCK_TEST_SENTINEL');
$disposable = env('CRM_STOCK_TEST_DISPOSABLE');

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
$denylist = ['crm_i47_test', 'crm_i66_audit', 'crm_i69_test', 'crm_i69_fresh',
    'crm_i71x_test', 'crm_i72x_test', 'crm_local', 'crm', 'crm7', 'crm_prod', 'crm_production', 'crm_staging', 'crm_dev'];
if (in_array(strtolower($dbName), $denylist, true)) {
    guardFail('DB name is in denylist');
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

function waitForSignal(string $dir, string $name, float $timeoutSec = 30): bool
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

// ------------------------------------------------------------------ worker mode
$role = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--role=') === 0) {
        $role = substr($arg, 7);
    }
}

if ($role === 'worker') {
    $opt = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
            $opt[$m[1]] = $m[2];
        }
    }
    $dir = (string) ($opt['dir'] ?? '');
    $slot = (string) ($opt['slot'] ?? '');
    $requestId = (int) ($opt['request'] ?? 0);
    $levelId = (int) ($opt['level'] ?? 0);
    $qty = (float) ($opt['qty'] ?? 0);

    $pdo = connect();
    $service = new StockReservationService();
    $pdo->beginTransaction();
    writeSignal($dir, 'ready_' . $slot, ['pid' => getmypid(), 'conn' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()]);
    if (!waitForSignal($dir, 'go', 30)) {
        $pdo->rollBack();
        writeSignal($dir, 'done_' . $slot, ['outcome' => 'error', 'code' => 'go_timeout']);
        exit(0);
    }

    $service->lock($requestId);
    $seen = (new StockReservationRepository())->forRequestFresh($requestId);
    try {
        $service->apply($requestId, [['stock_level_id' => $levelId, 'quantity' => $qty]]);
        $pdo->commit();
        writeSignal($dir, 'done_' . $slot, ['outcome' => 'success', 'seen' => $seen]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $outcome = ['outcome' => 'error', 'class' => get_class($e), 'message' => $e->getMessage()];
        if ($e instanceof HttpException) {
            $outcome['status'] = $e->status;
            $outcome['code'] = $e->errorCode;
        }
        writeSignal($dir, 'done_' . $slot, $outcome);
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
    'disposable sentinel verified', $failures);

$levelIds = [];
$requestIds = [];
function nextIds(PDO $pdo, array &$levelIds, array &$requestIds): array
{
    $levelId = 1000000 + random_int(1, 900000);
    $requestId = 2000000 + random_int(1, 900000);
    $stmt = $pdo->prepare('INSERT INTO stock_levels (ID, NAME, QUANTITY, ACTIVE) VALUES (?, ?, ?, \'Y\')');
    $stmt->execute([$levelId, 'rt02-' . bin2hex(random_bytes(6)), 0]);
    $levelIds[] = $levelId;
    $requestIds[] = $requestId;

    return [$levelId, $requestId];
}

function setQuantity(PDO $pdo, int $levelId, float $quantity): void
{
    $pdo->prepare('UPDATE stock_levels SET QUANTITY = ? WHERE ID = ?')->execute([number_format($quantity, 5, '.', ''), $levelId]);
}

function quantity(PDO $pdo, int $levelId): float
{
    return (float) $pdo->query('SELECT QUANTITY FROM stock_levels WHERE ID = ' . $levelId)->fetchColumn();
}

function reserved(PDO $pdo, int $requestId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM stock_reservations WHERE request_id = ?');
    $stmt->execute([$requestId]);

    return (float) $stmt->fetchColumn();
}

function reservationRows(PDO $pdo, int $requestId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM stock_reservations WHERE request_id = ?');
    $stmt->execute([$requestId]);

    return (int) $stmt->fetchColumn();
}

function addDummyReservation(PDO $pdo, int $requestId): void
{
    // Marker row used to identify the request without interfering with the test level.
    $stmt = $pdo->prepare('INSERT INTO stock_reservations (request_id, stock_level_id, quantity) VALUES (?, ?, 0)');
    $stmt->execute([$requestId, 999999999]);
}

function reservationRowExists(PDO $pdo, int $requestId, int $levelId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM stock_reservations WHERE request_id = ? AND stock_level_id = ? LIMIT 1');
    $stmt->execute([$requestId, $levelId]);

    return $stmt->fetchColumn() !== false;
}

function runConcurrent(array $specs): array
{
    global $dsn, $user, $password, $failures;
    $dir = sys_get_temp_dir() . '/crm_i110_' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $pipes = [];
    foreach ($specs as $slot => $spec) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . ' --role=worker --dir=' . escapeshellarg($dir) . ' --slot=' . escapeshellarg((string) $slot)
            . ' --request=' . (int) $spec['request'] . ' --level=' . (int) $spec['level']
            . ' --qty=' . number_format($spec['qty'], 5, '.', '');
        proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$slot]);
    }
    foreach (array_keys($specs) as $slot) {
        check(waitForSignal($dir, 'ready_' . $slot), 'worker ' . $slot . ' entered transaction');
    }
    writeSignal($dir, 'go', ['at' => microtime(true)]);
    $out = [];
    foreach (array_keys($specs) as $slot) {
        check(waitForSignal($dir, 'done_' . $slot), 'worker ' . $slot . ' finished');
        $out[$slot] = readSignal($dir, 'done_' . $slot);
        echo 'OUT ' . $slot . '=' . json_encode($out[$slot], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);

    return $out;
}

function insufficient(array $result): bool
{
    return ($result['outcome'] ?? '') === 'error' && ($result['code'] ?? '') === 'insufficient_stock'
        && ($result['status'] ?? 0) === 422;
}

// Scenario 1: quantity 5, two concurrent reservations of 4.
[$level1, $reqA1] = nextIds($pdo, $levelIds, $requestIds);
$reqB1 = $requestIds[] = 2000000 + random_int(1, 900000);
setQuantity($pdo, $level1, 5);
addDummyReservation($pdo, $reqA1);
addDummyReservation($pdo, $reqB1);
$out = runConcurrent([
    'A' => ['request' => $reqA1, 'level' => $level1, 'qty' => 4],
    'B' => ['request' => $reqB1, 'level' => $level1, 'qty' => 4],
]);
$out1 = $out;
$successes = count(array_filter($out, static fn (array $r): bool => ($r['outcome'] ?? '') === 'success'));
check($successes === 1, 'S1 exactly one reservation succeeded');
check(count(array_filter($out, 'insufficient')) === 1, 'S1 loser got insufficient_stock/422');
check(count(array_filter($out, static fn (array $r): bool => ($r['class'] ?? '') === 'PDOException')) === 0,
    'S1 conflict is not a raw PDOException');
check(abs(quantity($pdo, $level1) - 1.0) < 0.00001, 'S1 final quantity is 1');

// Scenario 2: quantity 5, concurrent 5 and 1.
[$level2, $reqA2] = nextIds($pdo, $levelIds, $requestIds);
$reqB2 = $requestIds[] = 2000000 + random_int(1, 900000);
setQuantity($pdo, $level2, 5);
addDummyReservation($pdo, $reqA2);
addDummyReservation($pdo, $reqB2);
$out = runConcurrent([
    'A' => ['request' => $reqA2, 'level' => $level2, 'qty' => 5],
    'B' => ['request' => $reqB2, 'level' => $level2, 'qty' => 1],
]);
check(count(array_filter($out, static fn (array $r): bool => ($r['outcome'] ?? '') === 'success')) === 1,
    'S2 exactly one reservation succeeded');
check(quantity($pdo, $level2) >= 0, 'S2 final quantity is non-negative');

// Scenario 3: concurrent change of an existing reservation (delta, not full amount).
[$level3, $req3] = nextIds($pdo, $levelIds, $requestIds);
setQuantity($pdo, $level3, 5);
$pdo->prepare('INSERT INTO stock_reservations (request_id, stock_level_id, quantity) VALUES (?, ?, 1)')
    ->execute([$req3, $level3]);
$out = runConcurrent([
    'A' => ['request' => $req3, 'level' => $level3, 'qty' => 2],
    'B' => ['request' => $req3, 'level' => $level3, 'qty' => 3],
]);
check(count(array_filter($out, static fn (array $r): bool => ($r['outcome'] ?? '') === 'success')) === 2,
    'S3 both delta changes succeeded');
$s3Reserved = reserved($pdo, $req3);
$s3Quantity = quantity($pdo, $level3);
echo 'S3 reserved=' . $s3Reserved . ' quantity=' . $s3Quantity . "\n";
// Either delta change may run last; the invariant is total stock (available + reserved) = 6.
check(in_array((int) round($s3Reserved), [2, 3], true), 'S3 final reservation is 2 or 3');
check(abs(($s3Reserved + $s3Quantity) - 6.0) < 0.00001,
    'S3 quantity reflects delta only (total stock preserved)');

// Scenario 4: decrease and cancel restore exactly the reserved amount.
[$level4, $req4] = nextIds($pdo, $levelIds, $requestIds);
setQuantity($pdo, $level4, 2);
$pdo->prepare('INSERT INTO stock_reservations (request_id, stock_level_id, quantity) VALUES (?, ?, 3)')
    ->execute([$req4, $level4]);
(new StockReservationService())->apply($req4, [['stock_level_id' => $level4, 'quantity' => 1]]);
check(abs(quantity($pdo, $level4) - 4.0) < 0.00001, 'S4 decrease restored 2 units');
check(abs(reserved($pdo, $req4) - 1.0) < 0.00001, 'S4 reservation is 1');
(new StockReservationService())->apply($req4, []);
check(abs(quantity($pdo, $level4) - 5.0) < 0.00001, 'S4 cancel restored remaining unit');
check(reservationRows($pdo, $req4) === 0, 'S4 reservation row removed');

// Scenario 5a: reserve_enabled=0 keeps check-only behaviour.
[$level5, $req5] = nextIds($pdo, $levelIds, $requestIds);
setQuantity($pdo, $level5, 5);
$settings->set('stock.reserve_enabled', '0');
$settings->set('stock.allow_zero', '0');
$thrown = null;
try {
    (new StockReservationService())->apply($req5, [['stock_level_id' => $level5, 'quantity' => 6]]);
} catch (Throwable $e) {
    $thrown = $e;
}
check($thrown instanceof HttpException && $thrown->errorCode === 'insufficient_stock' && $thrown->status === 422,
    'S5a reserve_enabled=0 still rejects insufficient stock');
check(abs(quantity($pdo, $level5) - 5.0) < 0.00001 && reservationRows($pdo, $req5) === 0,
    'S5a reserve_enabled=0 changed nothing');

// Scenario 5b: allow_zero=1 preserves the explicit legacy bypass.
$settings->set('stock.reserve_enabled', '1');
$settings->set('stock.allow_zero', '1');
[$level6, $req6] = nextIds($pdo, $levelIds, $requestIds);
setQuantity($pdo, $level6, 0);
(new StockReservationService())->apply($req6, [['stock_level_id' => $level6, 'quantity' => 3]]);
check(abs(quantity($pdo, $level6) + 3.0) < 0.00001, 'S5b allow_zero keeps negative-balance bypass');
check(abs(reserved($pdo, $req6) - 3.0) < 0.00001, 'S5b allow_zero persisted reservation');
$settings->set('stock.allow_zero', '0');

// Scenario 6: failed transaction leaves no partial reservation.
$loserSlot = array_keys(array_filter($out1, static fn (array $r): bool => ($r['outcome'] ?? '') !== 'success'))[0] ?? 'A';
$loserRequest = $loserSlot === 'A' ? $reqA1 : $reqB1;
check(!reservationRowExists($pdo, $loserRequest, $level1), 'S6 loser left no partial reservation for the test level');

// Cleanup fixtures and restore settings.
foreach ($requestIds as $requestId) {
    (new StockReservationRepository())->deleteForRequest($requestId);
}
$placeholders = implode(',', array_fill(0, count($levelIds), '?'));
$stmt = $pdo->prepare('DELETE FROM stock_levels WHERE ID IN (' . $placeholders . ')');
$stmt->execute($levelIds);
$settings->set('stock.reserve_enabled', $originalReserve);
$settings->set('stock.allow_zero', $originalAllowZero);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "\nALL OK\n";
