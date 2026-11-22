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
$dbUser = (string) (getenv('CRM_STOCK_TEST_USER') ?: '');
$dbPass = (string) (getenv('CRM_STOCK_TEST_PASS') ?: '');
$port = (string) (getenv('CRM_STOCK_TEST_PORT') ?: '');
$sentinel = (string) (getenv('CRM_STOCK_TEST_SENTINEL') ?: '');
$disposable = (string) (getenv('CRM_STOCK_TEST_DISPOSABLE') ?: '');

if ($dsn === '' || $dbUser === '' || $dbPass === '' || $port === '' || $sentinel === '') {
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

if (!in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
    guardFail('DB host must be loopback');
}
if (preg_match('/^crm_[a-z0-9]{1,20}_test$/', $dbName) !== 1) {
    guardFail('DB name is not disposable');
}
if (!ctype_digit($dsnPort) || $dsnPort !== $port) {
    guardFail('DSN port mismatch');
}

putenv('DB_HOST=' . $host);
putenv('DB_PORT=' . $dsnPort);
putenv('DB_NAME=' . $dbName);
putenv('DB_USER=' . $dbUser);
putenv('DB_PASS=' . $dbPass);
Config::load(__DIR__ . '/../config/.env');

function connect(): PDO
{
    global $dsn, $dbUser, $dbPass;
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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

function waitForSignal(string $dir, string $name, float $timeout = 20): bool
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $dir . '/' . $name);
        if (is_file($dir . '/' . $name)) {
            return true;
        }
        usleep(20000);
    }

    return false;
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function available(PDO $pdo, int $levelId): string
{
    return (string) $pdo->query('SELECT CAST(QUANTITY AS DECIMAL(20,5)) FROM stock_levels WHERE ID = ' . $levelId)->fetchColumn();
}

function reserved(PDO $pdo, int $levelId): string
{
    return (string) $pdo->query(
        'SELECT CAST(COALESCE(SUM(quantity),0) AS DECIMAL(20,5)) FROM stock_reservations WHERE stock_level_id = ' . $levelId
    )->fetchColumn();
}

function add(string $a, string $b): string
{
    return bcadd($a, $b, 5);
}

function sub(string $a, string $b): string
{
    return bcsub($a, $b, 5);
}

function orphans(PDO $pdo): int
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM stock_reservations r LEFT JOIN stock_levels l ON l.ID = r.stock_level_id WHERE l.ID IS NULL'
    )->fetchColumn();
}

function reserve(int $levelId, int $requestId, float $qty, PDO $pdo): StockReservationService
{
    $service = new StockReservationService();
    $service->lock($requestId);
    try {
        $service->apply($requestId, [['stock_level_id' => $levelId, 'quantity' => $qty]]);
    } finally {
        $service->unlock($requestId);
    }

    return $service;
}

$role = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--role=') === 0) {
        $role = substr($arg, 7);
    }
}

if ($role === 'hold_abs') {
    $opt = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
            $opt[$m[1]] = $m[2];
        }
    }
    $dir = (string) ($opt['dir'] ?? '');
    $levelId = (int) ($opt['level'] ?? 0);
    $pdo = connect();
    $pdo->beginTransaction();
    $repo = new StockReservationRepository();
    $repo->lockTopologyShared();
    writeSignal($dir, 'abs_holding', ['pid' => getmypid()]);
    if (!waitForSignal($dir, 'go', 20)) {
        $pdo->rollBack();
        exit(0);
    }
    $repo->setLevelAvailable($levelId, '7.00000');
    $pdo->commit();
    writeSignal($dir, 'abs_done', ['outcome' => 'success']);
    exit(0);
}

if ($role === 'hold_reserve') {
    $opt = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
            $opt[$m[1]] = $m[2];
        }
    }
    $dir = (string) ($opt['dir'] ?? '');
    $levelId = (int) ($opt['level'] ?? 0);
    $requestId = (int) ($opt['request'] ?? 0);
    $pdo = connect();
    $pdo->beginTransaction();
    $service = new StockReservationService();
    $service->lock($requestId);
    try {
        $service->apply($requestId, [['stock_level_id' => $levelId, 'quantity' => 2]]);
        writeSignal($dir, 'reserve_holding', ['pid' => getmypid()]);
        if (!waitForSignal($dir, 'go', 20)) {
            throw new RuntimeException('go timeout');
        }
        $pdo->commit();
        writeSignal($dir, 'reserve_done', ['outcome' => 'success']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        writeSignal($dir, 'reserve_done', ['outcome' => 'error', 'message' => $e->getMessage()]);
    } finally {
        $service->unlock($requestId);
    }
    exit(0);
}

if ($role === 'hold_collapse') {
    $opt = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
            $opt[$m[1]] = $m[2];
        }
    }
    $dir = (string) ($opt['dir'] ?? '');
    $pdo = connect();
    $pdo->beginTransaction();
    $repo = new StockReservationRepository();
    $repo->lockTopologyExclusive();
    writeSignal($dir, 'collapse_holding', ['pid' => getmypid()]);
    if (!waitForSignal($dir, 'go', 20)) {
        $pdo->rollBack();
        exit(0);
    }
    $pdo->commit();
    writeSignal($dir, 'collapse_done', ['outcome' => 'success']);
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
$origReserve = $settings->get('stock.reserve_enabled');
$origZero = $settings->get('stock.allow_zero');
$settings->set('stock.reserve_enabled', '1');
$settings->set('stock.allow_zero', '0');

$pdo = connect();
$row = $pdo->query('SELECT marker FROM crm_disposable_sentinel WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
check($row !== false && isset($row['marker']) && hash_equals($sentinel, (string) $row['marker']), 'sentinel verified');

$user = ['ID' => 1, 'SID' => 'abs-test', 'LEVEL' => 90];
$caps = ['stocks.view', 'stocks.edit', 'stocks.import', 'stocks.export', 'stocks.manage',
    'stocks.warehouses.view_all', 'stocks.warehouses.manage', 'stocks.warehouses.create'];
$suffix = bin2hex(random_bytes(4));
$stockSid = 'abs-wh-' . $suffix;
$nameSid = null;
$levelIds = [];
$requestIds = [];

$pdo->prepare("INSERT INTO stocks (NAME, NAME_1C, SID, ACTIVE, STATUS, LEVEL) VALUES (?, ?, ?, 'Y', 'Y', '{90},{50},{10},{5},{1},')")
    ->execute(['ABS склад ' . $suffix, 'ABS склад ' . $suffix, $stockSid]);
$warehouseId = (int) $pdo->lastInsertId();

$service = new StocksService();
$created = $service->createItem($user, $caps, $warehouseId, [
    'name' => 'ABS товар ' . $suffix, 'unit' => 'шт', 'quantity' => '5.00000',
]);
$item1 = $created['item'];
$level1 = (int) $item1['id'];
$levelIds[] = $level1;
$nameSid = (string) $pdo->query('SELECT NAME_SID FROM stock_levels WHERE ID = ' . $level1)->fetchColumn();
check(available($pdo, $level1) === '5.00000', 'S1 legacy create: available=5');

$request1 = 6000000 + random_int(1, 900000);
$requestIds[] = $request1;
reserve($level1, $request1, 4, $pdo);
check(available($pdo, $level1) === '1.00000' && reserved($pdo, $level1) === '4.00000', 'S2 reserve: A=1 R=4');
check(add(available($pdo, $level1), reserved($pdo, $level1)) === '5.00000', 'S2 invariant P=5');

$service->updateItem($user, $caps, $level1, ['quantity' => '7.00000']);
check(available($pdo, $level1) === '7.00000' && reserved($pdo, $level1) === '4.00000', 'S3 legacy update keeps R');
check(add(available($pdo, $level1), reserved($pdo, $level1)) === '11.00000', 'S3 invariant P=11');

$service->updateItem($user, $caps, $level1, ['physical_quantity' => '10.00000']);
check(available($pdo, $level1) === '6.00000' && reserved($pdo, $level1) === '4.00000', 'S4 physical=10 R=4 -> A=6');
check(add(available($pdo, $level1), reserved($pdo, $level1)) === '10.00000', 'S4 invariant P=10');

$before = available($pdo, $level1);
try {
    $service->updateItem($user, $caps, $level1, ['physical_quantity' => '1.00000']);
    $rejected = false;
} catch (HttpException $e) {
    $rejected = true;
}
check($rejected && available($pdo, $level1) === $before, 'S5 physical<R rejected without changes');

try {
    $service->updateItem($user, $caps, $level1, ['quantity' => '8.00000', 'physical_quantity' => '99.00000']);
    $bad = false;
} catch (HttpException $e) {
    $bad = true;
}
check($bad, 'S6 inconsistent both fields rejected');
$service->updateItem($user, $caps, $level1, ['quantity' => '6.00000', 'physical_quantity' => '10.00000']);
check(available($pdo, $level1) === '6.00000', 'S6 consistent both fields accepted');

$settings->set('stock.allow_zero', '1');
$service->updateItem($user, $caps, $level1, ['quantity' => '-1.00000']);
check(available($pdo, $level1) === '-1.00000', 'S7 allow_zero negative available allowed');
$settings->set('stock.allow_zero', '0');
try {
    $service->updateItem($user, $caps, $level1, ['quantity' => '-1.00000']);
    $negativeRejected = false;
} catch (HttpException $e) {
    $negativeRejected = true;
}
check($negativeRejected, 'S7 negative rejected when allow_zero=0');

// F1 regression: PATCH without a balance field must keep the current balance.
$created2 = $service->createItem($user, $caps, $warehouseId, [
    'name' => 'ABS PATCH ' . $suffix, 'unit' => 'шт', 'quantity' => '7.25000',
]);
$level2 = (int) $created2['item']['id'];
$nameSid2 = (string) $pdo->query('SELECT NAME_SID FROM stock_levels WHERE ID = ' . $level2)->fetchColumn();
check(available($pdo, $level2) === '7.25000' && reserved($pdo, $level2) === '0.00000', 'F1a setup A=7.25 R=0');
$service->updateItem($user, $caps, $level2, ['name' => 'ABS PATCH ren ' . $suffix]);
check(available($pdo, $level2) === '7.25000' && reserved($pdo, $level2) === '0.00000', 'F1a PATCH name only keeps A=7.25 R=0');

$request2 = 6100000 + random_int(1, 900000);
$requestIds[] = $request2;
reserve($level2, $request2, 4, $pdo);
$service->updateItem($user, $caps, $level2, ['quantity' => '6.00000']);
check(available($pdo, $level2) === '6.00000' && reserved($pdo, $level2) === '4.00000', 'F1b setup A=6 R=4 P=10');
$service->updateItem($user, $caps, $level2, ['description' => 'PATCH только описание']);
check(available($pdo, $level2) === '6.00000' && reserved($pdo, $level2) === '4.00000'
    && add(available($pdo, $level2), reserved($pdo, $level2)) === '10.00000', 'F1b PATCH description keeps A/R/P');

$service->updateItem($user, $caps, $level2, ['quantity' => '8.00000']);
check(available($pdo, $level2) === '8.00000' && reserved($pdo, $level2) === '4.00000', 'F1c explicit legacy quantity still wins');
$service->updateItem($user, $caps, $level2, ['physical_quantity' => '11.00000']);
check(available($pdo, $level2) === '7.00000' && reserved($pdo, $level2) === '4.00000', 'F1d physical_quantity still computes A=P-R');
$service->updateItem($user, $caps, $level2, ['quantity' => '7.00000', 'physical_quantity' => '11.00000']);
check(available($pdo, $level2) === '7.00000', 'F1e consistent both fields accepted');
try {
    $service->updateItem($user, $caps, $level2, ['quantity' => '7.00000', 'physical_quantity' => '99.00000']);
    $bothBad = false;
} catch (HttpException $e) {
    $bothBad = true;
}
check($bothBad && available($pdo, $level2) === '7.00000', 'F1e inconsistent both fields rejected');

// Import: legacy CSV with active reservation.
$csv = 'C:\Temp\opencode\abs-' . $suffix . '.csv';
file_put_contents($csv, "Склад;Товар;Ед.;Остаток\nABS склад $suffix;ABS товар $suffix;шт;6\n");
$importFile = ['error' => UPLOAD_ERR_OK, 'size' => filesize($csv), 'name' => 'abs.csv', 'tmp_name' => $csv];
$result = $service->import($user, $caps, $importFile, '2026-11-22', [], uuid());
check(($result['status'] ?? '') === 'done', 'S8 legacy import completed');
echo 'S8 A=' . available($pdo, $level1) . ' R=' . reserved($pdo, $level1) . ' status=' . ($result['status'] ?? '') . "\n";
check(available($pdo, $level1) === '6.00000' && reserved($pdo, $level1) === '4.00000', 'S8 legacy import keeps reservation');
check((float) $pdo->query('SELECT QUANTITY FROM stock_levels_backup WHERE ID = ' . $level1)->fetchColumn() !== 0.0,
    'S8 backup stored available');

// Import with PHYSICAL_QUANTITY column.
$csv2 = 'C:\Temp\opencode\abs2-' . $suffix . '.csv';
file_put_contents($csv2, "Склад;Товар;Ед.;Физический остаток\nABS склад $suffix;ABS товар $suffix;шт;12\n");
$importFile2 = ['error' => UPLOAD_ERR_OK, 'size' => filesize($csv2), 'name' => 'abs2.csv', 'tmp_name' => $csv2];
echo 'S9 before A=' . available($pdo, $level1) . ' R=' . reserved($pdo, $level1) . "\n";
$result2 = $service->import($user, $caps, $importFile2, '2026-11-22', [], uuid());
check(($result2['status'] ?? '') === 'done', 'S9 physical import completed');
echo 'S9 A=' . available($pdo, $level1) . ' R=' . reserved($pdo, $level1) . ' status=' . ($result2['status'] ?? '') . "\n";
check(available($pdo, $level1) === '8.00000' && reserved($pdo, $level1) === '4.00000', 'S9 physical=12 R=4 -> A=8');

$csvBad = 'C:\Temp\opencode\absbad-' . $suffix . '.csv';
file_put_contents($csvBad, "Склад;Товар;Ед.;Остаток;Физический остаток\nABS склад $suffix;ABS товар $suffix;шт;6;1\n");
$importBad = ['error' => UPLOAD_ERR_OK, 'size' => filesize($csvBad), 'name' => 'absbad.csv', 'tmp_name' => $csvBad];
$beforeBad = available($pdo, $level1);
try {
    $service->import($user, $caps, $importBad, '2026-11-22', [], uuid());
    $badImport = false;
} catch (Throwable $e) {
    $badImport = true;
}
check($badImport, 'S10 import error surfaced');
check(available($pdo, $level1) === $beforeBad, 'S10 import error rolled back available');
@unlink($csv); @unlink($csv2); @unlink($csvBad);

// Concurrency: ABS shared lock + reservation shared lock are compatible.
$dir = sys_get_temp_dir() . '/crm_i119_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
    . ' --role=hold_reserve --dir=' . escapeshellarg($dir) . ' --level=' . $level1 . ' --request=' . ($requestIds[] = 7000000 + random_int(1, 900000));
$pipes = [];
proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
check(waitForSignal($dir, 'reserve_holding', 20), 'S11 worker holds shared lock via reserve');
writeSignal($dir, 'go', ['at' => microtime(true)]);
check(waitForSignal($dir, 'reserve_done', 20), 'S11 worker finished');
$done = readSignal($dir, 'reserve_done');
echo 'S11 worker=' . json_encode($done, JSON_UNESCAPED_UNICODE) . "\n";
check(($done['outcome'] ?? '') === 'success', 'S11 concurrent reserve succeeded');
$beforeConcurrent = available($pdo, $level1);
$concurrentTarget = add($beforeConcurrent, '2.00000');
$pdo->beginTransaction();
try {
    $repoAbs = new StockReservationRepository();
    $repoAbs->lockTopologyShared();
    if (!$repoAbs->setLevelAvailable($level1, $concurrentTarget)) {
        throw new RuntimeException('level missing');
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
check(orphans($pdo) === 0, 'S11 no orphans');
$rAfter = reserved($pdo, $level1);
$pBefore11 = add($beforeConcurrent, $rAfter);
$pAfter11 = add(available($pdo, $level1), $rAfter);
check($pAfter11 === add($pBefore11, '2.00000'), 'S11 invariant P=A+R grew exactly by ABS delta');
foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
rmdir($dir);

// Concurrency: createItem shared lock vs collapse exclusive lock (scenario 14).
$dir14 = sys_get_temp_dir() . '/crm_i119c_' . bin2hex(random_bytes(6));
mkdir($dir14, 0700, true);
$cmd14 = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
    . ' --role=hold_collapse --dir=' . escapeshellarg($dir14);
$pipes14 = [];
proc_open($cmd14, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes14);
check(waitForSignal($dir14, 'collapse_holding', 20), 'S14 worker holds exclusive topology lock');
$createdName = 'ABS col ' . $suffix;
$createdDuringLock = true;
try {
    $service->createItem($user, $caps, $warehouseId, [
        'name' => $createdName, 'unit' => 'шт', 'quantity' => '1.00000',
    ]);
} catch (Throwable $e) {
    $createdDuringLock = false;
}
writeSignal($dir14, 'go', ['at' => microtime(true)]);
check(waitForSignal($dir14, 'collapse_done', 20), 'S14 worker finished');
check(!$createdDuringLock, 'S14 createItem blocked by exclusive topology lock');
$repoStocks = new \App\Repositories\StocksRepository();
check($repoStocks->nomenclatureByName($createdName) === null, 'S14 no nomenclature created under exclusive lock');
check(orphans($pdo) === 0, 'S14 no orphans');
foreach (glob($dir14 . '/*') ?: [] as $file) { unlink($file); }
rmdir($dir14);

// F2 regression: legacy quantity cells with >5 decimals must round, not fail.
$importCell = static function (string $header, string $cell) use ($service, $user, $caps, $suffix): array {
    $path = 'C:\Temp\opencode\absdec-' . bin2hex(random_bytes(5)) . '.csv';
    file_put_contents($path, $header . "\nABS склад " . $suffix . ';ABS товар ' . $suffix . ';шт;' . $cell . "\n");
    $file = ['error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'name' => 'dec.csv', 'tmp_name' => $path];
    try {
        return $service->import($user, $caps, $file, '2026-11-22', [], uuid());
    } finally {
        @unlink($path);
    }
};

$r = $importCell('Склад;Товар;Ед.;Остаток', '1.234567');
check(($r['status'] ?? '') === 'done' && available($pdo, $level1) === '1.23457',
    'F2a legacy 1.234567 rounds to 1.23457');
$r = $importCell('Склад;Товар;Ед.;Остаток', '0.000001');
check(($r['status'] ?? '') === 'done' && available($pdo, $level1) === '0.00000',
    'F2b legacy 0.000001 rounds to 0.00000');
$r = $importCell('Склад;Товар;Ед.;Остаток', '1.23456');
check(($r['status'] ?? '') === 'done' && available($pdo, $level1) === '1.23456',
    'F2c legacy 1.23456 preserved');

$r = $importCell('Склад;Товар;Ед.;Остаток', '5');
check(($r['status'] ?? '') === 'done' && available($pdo, $level1) === '5.00000', 'F2d setup A=5');
try {
    $importCell('Склад;Товар;Ед.;Остаток', '-1');
    $negImport = false;
} catch (HttpException $e) {
    $negImport = $e->errorCode === 'empty_file';
}
check($negImport && available($pdo, $level1) === '5.00000', 'F2d legacy negative stays rejected, A unchanged');

$rNow = reserved($pdo, $level1);
$expectedPhysical = sub('14.00000', $rNow);
$r = $importCell('Склад;Товар;Ед.;PHYSICAL_QUANTITY', '14');
check(($r['status'] ?? '') === 'done' && available($pdo, $level1) === $expectedPhysical,
    'F2e PHYSICAL_QUANTITY header maps to physical (A=14-R)');
$r = $importCell('Склад;Товар;Ед.;Остаток физический', '14');
check(($r['status'] ?? '') === 'done' && available($pdo, $level1) === $expectedPhysical,
    'F2f "Остаток физический" header maps to physical');

$validPhysical = add('9.00000', reserved($pdo, $level1));
$badCsv2 = 'C:\Temp\opencode\absbad2-' . $suffix . '.csv';
file_put_contents(
    $badCsv2,
    "Склад;Товар;Ед.;Остаток;Физический остаток\n"
    . 'ABS склад ' . $suffix . ';ABS товар ' . $suffix . ';шт;9;' . $validPhysical . "\n"
    . 'ABS склад ' . $suffix . ';ABS товар ' . $suffix . ";шт;6;1\n"
);
$badFile2 = ['error' => UPLOAD_ERR_OK, 'size' => filesize($badCsv2), 'name' => 'bad2.csv', 'tmp_name' => $badCsv2];
$r = $importCell('Склад;Товар;Ед.;Остаток', '5');
check(($r['status'] ?? '') === 'done' && available($pdo, $level1) === '5.00000', 'F2g setup A=5');
$beforeBad2 = available($pdo, $level1);
try {
    $service->import($user, $caps, $badFile2, '2026-11-22', [], uuid());
    $bad2 = false;
} catch (Throwable $e) {
    $bad2 = true;
}
check($bad2 && available($pdo, $level1) === $beforeBad2, 'F2h bad row rolls back whole import');
@unlink($badCsv2);

check(orphans($pdo) === 0, 'S15 final orphan count zero');

// Restore settings and cleanup fixtures.
$settings->set('stock.reserve_enabled', $origReserve);
$settings->set('stock.allow_zero', $origZero);
foreach ($requestIds as $rid) {
    $pdo->prepare('DELETE FROM stock_reservations WHERE request_id = ?')->execute([$rid]);
}
if ($nameSid !== null) {
    $pdo->prepare('DELETE FROM stock_levels WHERE NAME_SID = ?')->execute([$nameSid]);
    $pdo->prepare('DELETE FROM nomenclature WHERE SID = ?')->execute([$nameSid]);
}
if (isset($nameSid2)) {
    $pdo->prepare('DELETE FROM stock_levels WHERE NAME_SID = ?')->execute([$nameSid2]);
    $pdo->prepare('DELETE FROM nomenclature WHERE SID = ?')->execute([$nameSid2]);
}
$pdo->prepare('DELETE FROM stocks WHERE SID = ?')->execute([$stockSid]);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "\nALL OK\n";
