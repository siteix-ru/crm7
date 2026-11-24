<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Controllers\StockLotsController;
use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Requests\RequestService;
use App\Stocks\StockLotService;
use App\Stocks\StockReservationService;
use App\Support\Decimal;

/** Disposable-MariaDB regression test for the stock lot lifecycle (3.4.222R). */
function guardFail(string $message): void
{
    fwrite(STDERR, "GUARD FAIL: " . $message . "\n");
    exit(2);
}

$dsn = (string) (getenv('CRM7_TEST_DB_DSN') ?: '');
$dbUser = (string) (getenv('CRM7_TEST_DB_USER') ?: '');
$dbPass = (string) (getenv('CRM7_TEST_DB_PASS') ?: '');
$dbPort = (string) (getenv('CRM7_TEST_DB_PORT') ?: '');
$disposable = (string) (getenv('CRM7_TEST_DB_DISPOSABLE') ?: '');
$sentinel = (string) (getenv('CRM7_TEST_DB_SENTINEL') ?: '');

if ($dsn === '' || $dbUser === '' || $dbPass === '' || $dbPort === '' || $disposable === '' || $sentinel === '') {
    guardFail('missing disposable database environment');
}
if ($disposable !== '1') {
    guardFail('CRM7_TEST_DB_DISPOSABLE must be 1');
}
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sentinel) !== 1) {
    guardFail('CRM7_TEST_DB_SENTINEL must be UUID v4');
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
$forbidden = ['crm', 'crm7', 'crm_local', 'crm_prod', 'crm_production', 'crm_k', 'crm_demo', 'crm_test', 'crm7_test', 'crm7_k', 'crm7_demo'];
if (in_array(strtolower($dbName), $forbidden, true)) {
    guardFail('DB name is forbidden');
}
if (!ctype_digit($dsnPort) || $dsnPort !== $dbPort) {
    guardFail('DSN port mismatch');
}

$pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
try {
    $row = $pdo->query('SELECT marker FROM crm_disposable_sentinel WHERE id = 1')->fetch();
} catch (Throwable $e) {
    guardFail('crm_disposable_sentinel is missing');
}
if ($row === false || !isset($row['marker']) || !hash_equals($sentinel, (string) $row['marker'])) {
    guardFail('sentinel mismatch');
}

(new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $pdo);

$failures = [];
function check(bool $ok, string $name, array &$failures): void
{
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures[] = $name;
    }
}
function expectHttp(callable $fn, int $status, string $code, string $name, array &$failures): void
{
    try {
        $fn();
        check(false, $name . ' (no exception)', $failures);
    } catch (HttpException $e) {
        check($e->status === $status && $e->errorCode === $code, $name, $failures);
    }
}

function startProcess(string $phpCode, string $dsn, string $dbUser, string $dbPass): array
{
    $script = 'require ' . var_export(__DIR__ . '/../src/autoload.php', true) . ';' . PHP_EOL
        . '$__pdo = new PDO(' . var_export($dsn, true) . ', ' . var_export($dbUser, true) . ', '
        . var_export($dbPass, true) . ', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);' . PHP_EOL
        . '(new ReflectionProperty(App\\Core\\Database::class, "pdo"))->setValue(null, $__pdo);' . PHP_EOL
        . $phpCode;
    $cmd = PHP_BINARY . ' -d display_errors=1 -r ' . escapeshellarg($script);
    $pipes = [];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['proc' => null];
    }
    fclose($pipes[0]);

    return ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2]];
}

function finishProcess(array $handle): array
{
    if ($handle['proc'] === null) {
        return ['exit' => -1, 'out' => '', 'err' => 'spawn failed'];
    }
    $out = stream_get_contents($handle['out']);
    $err = stream_get_contents($handle['err']);
    fclose($handle['out']);
    fclose($handle['err']);

    return ['exit' => proc_close($handle['proc']), 'out' => trim($out), 'err' => trim($err)];
}

// ---------------------------------------------------------------------------
// Isolated fixtures. Only disposable-DB tables are modified.
// ---------------------------------------------------------------------------
$pdo->exec('DELETE FROM stock_lot_movements');
$pdo->exec('DELETE FROM stock_issue_lines');
$pdo->exec('DELETE FROM stock_issue_operations');
$pdo->exec('DELETE FROM stock_lot_reservations');
$pdo->exec('DELETE FROM stock_lots');
$pdo->exec('DELETE FROM stock_reservations');
$pdo->exec('DELETE FROM request_items');
$pdo->exec("DELETE FROM requests WHERE number LIKE 'LOT-TEST-%'");
$pdo->exec("DELETE FROM user_level_stock WHERE USER_SID LIKE 'LOT-%'");
$pdo->exec("DELETE FROM stock_levels WHERE SID LIKE 'LOT-L%'");
$pdo->exec("DELETE FROM nomenclature WHERE NAME_1C IN ('LOT-ITEM','LEGACY-ITEM')");
$pdo->exec("DELETE FROM stocks WHERE SID IN ('LOT-WS-A','LOT-WS-B')");
$pdo->exec("INSERT INTO settings (`key`,value) VALUES ('stock.reserve_enabled','1'),('module.warehouses','1') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$pdo->exec("INSERT INTO stocks (SID,NAME,NAME_1C,LEVEL,STATUS,ACTIVE) VALUES ('LOT-WS-A','Lot A','LOT-WS-A','{90}','Y','Y'),('LOT-WS-B','Lot B','LOT-WS-B','{91}','Y','Y')");
$pdo->exec("INSERT INTO nomenclature (SID,NAME,NAME_1C,UNIT,TRACK_LOTS) VALUES ('LOT-ITEM','Lot item','LOT-ITEM','pcs',1),('LEGACY-ITEM','Legacy item','LEGACY-ITEM','pcs',0)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('LOT-L1','LOT-WS-A','Lot item','LOT-ITEM','pcs',100.00000)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('LOT-L2','LOT-WS-B','Lot item','LOT-ITEM','pcs',50.00000)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('LOT-L3','LOT-WS-A','Lot item','LOT-ITEM','pcs',100.00000)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('LOT-L4','LOT-WS-A','Lot item','LOT-ITEM','pcs',100.00000)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('LOT-L5','LOT-WS-A','Lot item','LOT-ITEM','pcs',100.00000)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('LOT-L6','LOT-WS-A','Lot item','LOT-ITEM','pcs',50.00000)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('LOT-L7','LOT-WS-A','Lot item','LOT-ITEM','pcs',25.00000)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('LOT-L8','LOT-WS-A','Legacy item','LEGACY-ITEM','pcs',50.00000)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('LOT-L9','LOT-WS-A','Lot item','LOT-ITEM','pcs',10.00000)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('LOT-L10','LOT-WS-A','Lot item','LOT-ITEM','pcs',0.00000)");
$pdo->exec("INSERT INTO request_statuses (code,title,sort,is_final) VALUES ('new','New',10,0),('closed','Closed',90,1) ON DUPLICATE KEY UPDATE title=VALUES(title)");
$pdo->exec("INSERT IGNORE INTO users (ID,SID,LOGIN,PASSWORD,LEVEL,FULL_NAME,reg_state) VALUES (1,'LOT-CLIENT','lotclient','x',5,'Lot Client','active'),(90,'LOT-USER','lotmanager','x',90,'Lot Manager','active'),(91,'LOT-USER-B','lotmanagerb','x',91,'Lot Manager B','active'),(10,'LOT-LIMITED','lotlimited','x',10,'Limited','active')");
$pdo->exec("INSERT INTO user_level_stock (USER_SID,STOCK_SID,STATUS,ACTIVE) VALUES ('LOT-LIMITED','LOT-WS-A','Y','Y')");

$level = static function (string $sid) use ($pdo): int {
    return (int) $pdo->query("SELECT ID FROM stock_levels WHERE SID='$sid'")->fetchColumn();
};
$lev1 = $level('LOT-L1');
$lev2 = $level('LOT-L2');
$lev3 = $level('LOT-L3');
$lev4 = $level('LOT-L4');
$lev5 = $level('LOT-L5');
$lev6 = $level('LOT-L6');
$lev7 = $level('LOT-L7');
$levLegacy = $level('LOT-L8');
$lev9 = $level('LOT-L9');
$lev10 = $level('LOT-L10');

$newRequest = static function (string $number) use ($pdo): int {
    $pdo->exec("INSERT INTO requests (number,subject,client_id,status_id,version) VALUES ('$number','Lot test',1,'new',1)");

    return (int) $pdo->query("SELECT id FROM requests WHERE number='$number'")->fetchColumn();
};

$lots = new StockLotService();
$reservations = new StockReservationService();
$requestService = new RequestService();
$controller = new StockLotsController();

$user = ['ID' => 90, 'SID' => 'LOT-USER', 'LEVEL' => 90];
$userB = ['ID' => 91, 'SID' => 'LOT-USER-B', 'LEVEL' => 91];
$limited = ['ID' => 10, 'SID' => 'LOT-LIMITED', 'LEVEL' => 10];
$caps = ['stocks.edit', 'requests.view.all', 'requests.transition'];
$capsAll = ['stocks.edit', 'requests.view.all', 'requests.transition', 'stocks.warehouses.manage'];

$sumAvailable = static function (int $id) use ($pdo): string {
    return Decimal::parse((string) $pdo->query("SELECT CAST(COALESCE(SUM(QUANTITY_AVAILABLE),0) AS DECIMAL(20,5)) FROM stock_lots WHERE STOCK_LEVEL_ID=$id")->fetchColumn());
};
$sumReserved = static function (int $id) use ($pdo): string {
    return Decimal::parse((string) $pdo->query("SELECT CAST(COALESCE(SUM(QUANTITY_RESERVED),0) AS DECIMAL(20,5)) FROM stock_lots WHERE STOCK_LEVEL_ID=$id")->fetchColumn());
};
$levelA = static function (int $id) use ($pdo): string {
    return Decimal::parse((string) $pdo->query("SELECT CAST(QUANTITY AS DECIMAL(20,5)) FROM stock_levels WHERE ID=$id")->fetchColumn());
};
$orphans = static function () use ($pdo): int {
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM stock_lot_reservations WHERE RESERVATION_ID NOT IN (SELECT ID FROM stock_reservations)'
    )->fetchColumn();
};
$apply = static function (int $requestId, array $items) use ($reservations, $pdo): void {
    $reservations->lock($requestId);
    $pdo->beginTransaction();
    try {
        $reservations->apply($requestId, $items);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $reservations->unlock($requestId);
    }
};

// --- 0. Conversion / FIFO foundation ---------------------------------------
$lots->convert($lev1, [
    ['lot_code' => 'LOT-A', 'quantity' => '60.00000', 'expires_at' => null],
    ['lot_code' => 'LOT-B', 'quantity' => '40.00000', 'expires_at' => null],
]);
$lots->convert($lev2, [['lot_code' => 'LOT-C', 'quantity' => '50.00000', 'expires_at' => null]]);
$lots->convert($lev3, [['lot_code' => 'LOT-D', 'quantity' => '100.00000', 'expires_at' => null]]);
$lots->convert($lev4, [['lot_code' => 'LOT-E', 'quantity' => '100.00000', 'expires_at' => null]]);
$lots->convert($lev5, [['lot_code' => 'LOT-F', 'quantity' => '100.00000', 'expires_at' => null]]);
$lots->convert($lev6, [
    ['lot_code' => 'LOT-X', 'quantity' => '20.00000', 'expires_at' => null],
    ['lot_code' => 'LOT-Y', 'quantity' => '30.00000', 'expires_at' => null],
]);
check(Decimal::compare($sumAvailable($lev1), '100.00000') === 0, 'conversion preserves A', $failures);
expectHttp(static fn() => $lots->convert($lev1, [['lot_code' => 'X', 'quantity' => '1.00000']]), 422, 'invalid_mode_transition', 'second conversion rejected', $failures);

// --- 1. F-01: removal of a lot position -------------------------------------
$r1 = $newRequest('LOT-TEST-1');
$apply($r1, [['stock_level_id' => $lev1, 'quantity' => 70.0]]);
check(Decimal::compare($sumReserved($lev1), '70.00000') === 0, 'F01 initial reservation', $failures);
check((string) $pdo->query("SELECT QUANTITY_AVAILABLE FROM stock_lots WHERE LOT_CODE='LOT-A'")->fetchColumn() === '0.00000', 'F01 FIFO first lot consumed', $failures);
$apply($r1, []);
check($orphans() === 0, 'F01 no orphan lot reservations after full removal', $failures);
check(Decimal::compare($sumReserved($lev1), '0.00000') === 0, 'F01 lot reserved cleared', $failures);
check(Decimal::compare($levelA($lev1), '100.00000') === 0, 'F01 level A restored', $failures);
check(Decimal::compare($sumAvailable($lev1), '100.00000') === 0, 'F01 A equals sum of lots', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM stock_reservations WHERE request_id=$r1")->fetchColumn() === 0, 'F01 parent reservation removed', $failures);
check(Decimal::compare(Decimal::add($levelA($lev1), Decimal::parse((string) $pdo->query("SELECT CAST(COALESCE(SUM(quantity),0) AS DECIMAL(20,5)) FROM stock_reservations WHERE request_id=$r1")->fetchColumn())), '100.00000') === 0, 'F01 physical_quantity basis', $failures);
$apply($r1, []);
check(Decimal::compare($levelA($lev1), '100.00000') === 0, 'F01 repeated removal is idempotent', $failures);

// F-01: remove one of two lot positions.
$r2 = $newRequest('LOT-TEST-2');
$apply($r2, [
    ['stock_level_id' => $lev1, 'quantity' => 30.0],
    ['stock_level_id' => $lev2, 'quantity' => 20.0],
]);
check(Decimal::compare($sumReserved($lev1), '30.00000') === 0 && Decimal::compare($sumReserved($lev2), '20.00000') === 0, 'F01 two-level reservation', $failures);
$apply($r2, [['stock_level_id' => $lev1, 'quantity' => 30.0]]);
check($orphans() === 0, 'F01 no orphans after partial removal', $failures);
check(Decimal::compare($sumReserved($lev2), '0.00000') === 0 && Decimal::compare($levelA($lev2), '50.00000') === 0, 'F01 removed level released', $failures);
check(Decimal::compare($sumReserved($lev1), '30.00000') === 0 && Decimal::compare($levelA($lev1), '70.00000') === 0, 'F01 kept level intact', $failures);

// F-01: change quantity of a remaining position + reapply.
$apply($r2, [['stock_level_id' => $lev1, 'quantity' => 20.0]]);
check(Decimal::compare($sumReserved($lev1), '20.00000') === 0 && Decimal::compare($levelA($lev1), '80.00000') === 0, 'F01 quantity change', $failures);
$apply($r2, [['stock_level_id' => $lev1, 'quantity' => 20.0]]);
check(Decimal::compare($sumReserved($lev1), '20.00000') === 0 && $orphans() === 0, 'F01 reapply same composition', $failures);

// F-01: rollback of a mid-operation failure restores the original state.
$pdo->beginTransaction();
try {
    $lots->releaseLevel($r2, $lev1);
    throw new RuntimeException('forced failure after release');
} catch (Throwable $e) {
    $pdo->rollBack();
}
check(Decimal::compare($sumReserved($lev1), '20.00000') === 0 && Decimal::compare($levelA($lev1), '80.00000') === 0, 'F01 rollback restores released reservation', $failures);

// --- 2. F-02: all warehouses authorized ------------------------------------
$r3 = $newRequest('LOT-TEST-3');
$apply($r3, [
    ['stock_level_id' => $lev1, 'quantity' => 40.0],
    ['stock_level_id' => $lev2, 'quantity' => 20.0],
]);
$levels = $lots->reservedLevels($r3);
check($levels === [$lev1, $lev2], 'F02 reservedLevels returns all levels', $failures);
try {
    foreach ($levels as $lv) {
        $lots->assertIssueRights($limited, $caps, $r3, $lv);
    }
    check(false, 'F02 limited user denied on second warehouse', $failures);
} catch (HttpException $e) {
    check($e->status === 403, 'F02 limited user denied on second warehouse', $failures);
}
check((int) $pdo->query("SELECT COUNT(*) FROM stock_issue_operations WHERE REQUEST_ID=$r3")->fetchColumn() === 0, 'F02 no issue created on denial', $failures);
check(Decimal::compare($sumReserved($lev1), '60.00000') === 0, 'F02 balances unchanged on denial', $failures);
try {
    foreach ([$lev1, $lev2] as $lv) {
        $lots->assertIssueRights($limited, $capsAll, $r3, $lv);
    }
    check(true, 'F02 warehouse manager access to both levels', $failures);
} catch (HttpException $e) {
    check(false, 'F02 warehouse manager access to both levels', $failures);
}
check($lots->issueContext(999999) === null, 'F02 unknown issue id rejected', $failures);
check($lots->reservedLevels(999999) === [], 'F02 unknown request id has no levels', $failures);
expectHttp(static fn() => $lots->assertIssueRights(['ID' => 1, 'SID' => 'LOT-CLIENT', 'LEVEL' => 5], [], $r3, $lev1), 403, 'forbidden', 'F02 client cannot issue', $failures);
expectHttp(static fn() => $lots->assertIssueRights($user, ['requests.view.all'], $r3, $lev1), 403, 'forbidden', 'F02 no stocks.edit', $failures);
expectHttp(static fn() => $lots->assertIssueRights($limited, $caps, $r3, $lev2), 403, 'forbidden', 'F02 no access to warehouse B', $failures);
check($requestService->canManageRequest(['ID' => 1, 'SID' => 'LOT-CLIENT', 'LEVEL' => 5], [], ['id' => $r3, 'client_id' => 1, 'manager_id' => null]) === false, 'F02 client cannot manage request', $failures);

// --- 3. F-03: real two-process concurrency ---------------------------------
$r4 = $newRequest('LOT-TEST-4');
$apply($r4, [['stock_level_id' => $lev3, 'quantity' => 100.0]]);
$issueCode = '$__r = $lots->issueWithLock(' . $r4 . ", '100.00000', 'race-key'); echo json_encode(\$__r);";
$child1 = startProcess('$lots = new App\\Stocks\\StockLotService();' . $issueCode, $dsn, $dbUser, $dbPass);
$child2 = startProcess('$lots = new App\\Stocks\\StockLotService();' . $issueCode, $dsn, $dbUser, $dbPass);
$c1 = finishProcess($child1);
$c2 = finishProcess($child2);
$applied = 0;
$already = 0;
foreach ([$c1, $c2] as $c) {
    if ($c['exit'] !== 0) {
        echo '     child error: ' . $c['err'] . "\n";
    }
    if (strpos($c['out'], '"already_applied":false') !== false) {
        ++$applied;
    }
    if (strpos($c['out'], '"already_applied":true') !== false) {
        ++$already;
    }
}
check($applied === 1 && $already === 1, 'F03 two processes same key: one issue, one idempotent', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM stock_issue_operations WHERE IDEMPOTENCY_KEY='race-key'")->fetchColumn() === 1, 'F03 single issue operation after race', $failures);
check(Decimal::compare($levelA($lev3), '0.00000') === 0, 'F03 level A after concurrent issue', $failures);

$r5 = $newRequest('LOT-TEST-5');
$apply($r5, [['stock_level_id' => $lev4, 'quantity' => 50.0]]);
$applyCode = '$__res = new App\\Stocks\\StockReservationService();' . PHP_EOL
    . '$__res->lock(' . $r5 . ');' . PHP_EOL
    . '$__pdo->beginTransaction();' . PHP_EOL
    . 'try { $__res->apply(' . $r5 . ", [['stock_level_id' => " . $lev4 . ", 'quantity' => 20.0]]); \$__pdo->commit(); echo 'apply-ok'; }"
    . PHP_EOL
    . 'catch (Throwable $e) { $__pdo->rollBack(); echo "apply-fail:" . $e->getMessage(); }'
    . PHP_EOL
    . 'finally { $__res->unlock(' . $r5 . '); }';
$issueCode5 = '$lots = new App\\Stocks\\StockLotService();'
    . '$__r = $lots->issueWithLock(' . $r5 . ", '20.00000', 'race-issue'); echo json_encode(\$__r);";
$pA = startProcess($applyCode, $dsn, $dbUser, $dbPass);
$pB = startProcess($issueCode5, $dsn, $dbUser, $dbPass);
$rA = finishProcess($pA);
$rB = finishProcess($pB);
check($rA['exit'] === 0 && strpos($rA['out'], 'apply-ok') === 0, 'F03 apply process succeeded', $failures);
check($rB['exit'] === 0 && strpos($rB['out'], '"already_applied":false') !== false, 'F03 issue process succeeded', $failures);
// Both operations are serialized in either order, so the final numbers depend
// on which one acquired the lock first. Only the invariants must hold.
$parentR4 = Decimal::parse((string) $pdo->query("SELECT CAST(COALESCE(SUM(quantity),0) AS DECIMAL(20,5)) FROM stock_reservations WHERE stock_level_id=$lev4")->fetchColumn());
check(Decimal::compare($sumReserved($lev4), $parentR4) === 0, 'F03 R equals parent after apply+issue race', $failures);
check(Decimal::compare($levelA($lev4), $sumAvailable($lev4)) === 0, 'F03 A consistent after apply+issue race', $failures);
$a4 = $levelA($lev4);
$applyFirst = Decimal::compare($a4, '80.00000') === 0 && Decimal::compare($parentR4, '0.00000') === 0;
$issueFirst = Decimal::compare($a4, '60.00000') === 0 && Decimal::compare($parentR4, '20.00000') === 0;
check($applyFirst || $issueFirst, 'F03 final state matches one serialized outcome', $failures);
check($orphans() === 0, 'F03 no orphans after apply+issue race', $failures);

// --- 4. F-04: multi-lot issue attribution and reversal ---------------------
$r6 = $newRequest('LOT-TEST-6');
$apply($r6, [['stock_level_id' => $lev6, 'quantity' => 50.0]]);
$issue6 = $lots->issueWithLock($r6, '50.00000', 'issue-multi');
$issueId6 = (int) $pdo->query("SELECT ID FROM stock_issue_operations WHERE IDEMPOTENCY_KEY='issue-multi'")->fetchColumn();
$lines = $pdo->query("SELECT LOT_ID, QUANTITY FROM stock_issue_lines WHERE ISSUE_ID=$issueId6 ORDER BY ID ASC")->fetchAll();
$lotX = (int) $pdo->query("SELECT ID FROM stock_lots WHERE LOT_CODE='LOT-X'")->fetchColumn();
$lotY = (int) $pdo->query("SELECT ID FROM stock_lots WHERE LOT_CODE='LOT-Y'")->fetchColumn();
check(count($lines) === 2 && (int) $lines[0]['LOT_ID'] === $lotX && (int) $lines[1]['LOT_ID'] === $lotY, 'F04 issue lines keep both lots', $failures);
check(Decimal::compare(Decimal::parse((string) $lines[0]['QUANTITY']), '20.00000') === 0 && Decimal::compare(Decimal::parse((string) $lines[1]['QUANTITY']), '30.00000') === 0, 'F04 issue line quantities', $failures);
$movementsBefore = (int) $pdo->query("SELECT COUNT(*) FROM stock_lot_movements WHERE TYPE='issue' AND REQUEST_ID=$r6")->fetchColumn();
$rev1 = $lots->reversalWithLock($issueId6, '25.00000', 'rev-multi');
$lotXAvail = Decimal::parse((string) $pdo->query("SELECT QUANTITY_AVAILABLE FROM stock_lots WHERE ID=$lotX")->fetchColumn());
$lotYAvail = Decimal::parse((string) $pdo->query("SELECT QUANTITY_AVAILABLE FROM stock_lots WHERE ID=$lotY")->fetchColumn());
check(Decimal::compare($lotXAvail, '20.00000') === 0, 'F04 reversal 25 restores LOT-X fully', $failures);
check(Decimal::compare($lotYAvail, '5.00000') === 0, 'F04 reversal 25 restores LOT-Y remainder', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM stock_lot_movements WHERE TYPE='issue' AND REQUEST_ID=$r6")->fetchColumn() === $movementsBefore, 'F04 original issue movements unchanged', $failures);
$rev2 = $lots->reversalWithLock($issueId6, '25.00000', 'rev-multi-2');
check(Decimal::compare(Decimal::parse((string) $pdo->query("SELECT QUANTITY_AVAILABLE FROM stock_lots WHERE ID=$lotY")->fetchColumn()), '30.00000') === 0, 'F04 final reversal restores LOT-Y', $failures);
check(Decimal::compare($levelA($lev6), '50.00000') === 0, 'F04 level A fully restored', $failures);
check($lots->reversalWithLock($issueId6, '25.00000', 'rev-multi')['already_applied'] === true, 'F04 repeated reversal idempotent', $failures);
expectHttp(static fn() => $lots->reversalWithLock($issueId6, '0.00001'), 422, 'validation_error', 'F04 over-reversal rejected', $failures);
expectHttp(static fn() => $lots->reversalWithLock(999999, '1.00000'), 404, 'not_found', 'F04 reversal without issue rejected', $failures);
check(Decimal::compare(Decimal::parse((string) $pdo->query("SELECT REVERSED_QUANTITY FROM stock_issue_operations WHERE ID=$issueId6")->fetchColumn()), '50.00000') === 0, 'F04 issue record accumulates reversals', $failures);

// F-04: safe handling of a pre-084 issue without lines (isolated level).
$lots->convert($lev9, [['lot_code' => 'LOT-LEGACY-SOURCE', 'quantity' => '10.00000', 'expires_at' => null]]);
$lotLegacy = (int) $pdo->query("SELECT ID FROM stock_lots WHERE LOT_CODE='LOT-LEGACY-SOURCE'")->fetchColumn();
$pdo->exec("INSERT INTO stock_issue_operations (REQUEST_ID,STOCK_LEVEL_ID,LOT_ID,QUANTITY,IDEMPOTENCY_KEY) VALUES ($r6,$lev9,$lotLegacy,1.00000,'legacy-issue')");
$legacyIssueId = (int) $pdo->lastInsertId();
$revLegacy = $lots->reversalWithLock($legacyIssueId, '1.00000', 'rev-legacy');
check($revLegacy['already_applied'] === false, 'F04 legacy issue reversal uses recorded attribution', $failures);

// F-04: an issue spanning two warehouses keeps per-level attribution.
$issueR3 = $lots->issueWithLock($r3, '60.00000', 'issue-two-levels');
$issueId3 = (int) $pdo->query("SELECT ID FROM stock_issue_operations WHERE IDEMPOTENCY_KEY='issue-two-levels'")->fetchColumn();
$levels3 = $lots->issueLevels($issueId3);
check(count($levels3) === 2 && in_array($lev1, $levels3, true) && in_array($lev2, $levels3, true), 'F04 issue covers both stock levels', $failures);
$lines3 = $pdo->query("SELECT STOCK_LEVEL_ID, QUANTITY FROM stock_issue_lines WHERE ISSUE_ID=$issueId3")->fetchAll();
$byLevel = [];
foreach ($lines3 as $ln) {
    $byLevel[(int) $ln['STOCK_LEVEL_ID']] = Decimal::add($byLevel[(int) $ln['STOCK_LEVEL_ID']] ?? '0.00000', (string) $ln['QUANTITY']);
}
check(Decimal::compare($byLevel[$lev1] ?? '0.00000', '40.00000') === 0 && Decimal::compare($byLevel[$lev2] ?? '0.00000', '20.00000') === 0, 'F04 per-level issue quantities', $failures);
$lots->reversalWithLock($issueId3, '60.00000', 'rev-two-levels');
check(Decimal::compare($levelA($lev1), '80.00000') === 0 && Decimal::compare($levelA($lev2), '50.00000') === 0, 'F04 reversal restores both warehouses', $failures);

// --- 5. F-05: API quantity validation --------------------------------------
$quantityMethod = new ReflectionMethod(StockLotsController::class, 'quantity');
$quantityMethod->setAccessible(true);
$invalid = [
    'missing' => null,
    'alpha' => 'abc',
    'array' => [],
    'object' => new stdClass(),
    'zero' => 0,
    'negative' => -1,
    'too-precise' => '1.000001',
    'exponent' => '1e3',
    'nan' => 'NaN',
    'infinity' => 'INF',
];
foreach ($invalid as $name => $value) {
    $body = $value === null ? [] : ['quantity' => $value];
    $req = new Request('POST', '/x', [], $body, [], '127.0.0.1');
    expectHttp(static fn() => $quantityMethod->invoke($controller, $req), 422, 'validation_error', 'F05 invalid quantity: ' . $name, $failures);
}
$reqOk = new Request('POST', '/x', [], ['quantity' => '1.00000'], [], '127.0.0.1');
check($quantityMethod->invoke($controller, $reqOk) === '1.00000', 'F05 valid quantity accepted', $failures);

// N-04: JSON numbers are decoded by PHP as int/float. Check the real decoder.
$jsonCases = [
    'json int' => ['{"quantity":5}', '5.00000', false],
    'json 0.00001' => ['{"quantity":0.00001}', '0.00001', false],
    'json 1.23456' => ['{"quantity":1.23456}', '1.23456', false],
    'json six decimals' => ['{"quantity":1.234567}', null, true],
    'json negative' => ['{"quantity":-1}', null, true],
    'json zero' => ['{"quantity":0}', null, true],
    'json exponent 1e3' => ['{"quantity":1e3}', '1000.00000', false],
    'json exponent 1e20' => ['{"quantity":1e20}', null, true],
    'json boolean' => ['{"quantity":true}', null, true],
    'json null' => ['{"quantity":null}', null, true],
    'json array' => ['{"quantity":[]}', null, true],
    'json object' => ['{"quantity":{}}', null, true],
];
foreach ($jsonCases as $name => [$json, $expected, $shouldFail]) {
    $body = json_decode($json, true);
    $req = new Request('POST', '/x', [], $body, [], '127.0.0.1');
    if ($shouldFail) {
        expectHttp(static fn() => $quantityMethod->invoke($controller, $req), 422, 'validation_error', 'N04 reject ' . $name, $failures);
    } else {
        try {
            $value = $quantityMethod->invoke($controller, $req);
            check($value === $expected, 'N04 accept ' . $name, $failures);
        } catch (Throwable $e) {
            check(false, 'N04 accept ' . $name, $failures);
        }
    }
}

// --- 5b. N-02: repeated receive into an existing lot -----------------------
$lots->receive($lev10, 'LOT-RECV', '10.00000', null, 'recv-first');
$recvLot = (int) $pdo->query("SELECT ID FROM stock_lots WHERE LOT_CODE='LOT-RECV'")->fetchColumn();
check($recvLot > 0 && Decimal::compare($sumAvailable($lev10), '10.00000') === 0, 'N02 first receipt creates lot', $failures);
$recvReq = $newRequest('LOT-TEST-RECV');
$apply($recvReq, [['stock_level_id' => $lev10, 'quantity' => 4.0]]);
check(Decimal::compare($sumReserved($lev10), '4.00000') === 0, 'N02 lot reserved before receipt', $failures);
$lots->receive($lev10, 'LOT-RECV', '5.00000', null, 'recv-second');
check(Decimal::compare($sumAvailable($lev10), '11.00000') === 0, 'N02 second receipt increases available', $failures);
check(Decimal::compare($sumReserved($lev10), '4.00000') === 0, 'N02 reservation preserved by receipt', $failures);
$mov = $pdo->query("SELECT RESERVED_BEFORE, RESERVED_AFTER FROM stock_lot_movements WHERE LOT_ID=$recvLot AND TYPE='receipt' AND IDEMPOTENCY_KEY='recv-second'")->fetch();
check($mov !== false
    && Decimal::compare(Decimal::parse((string) $mov['RESERVED_BEFORE']), '4.00000') === 0
    && Decimal::compare(Decimal::parse((string) $mov['RESERVED_AFTER']), '4.00000') === 0,
    'N02 receipt movement stores actual reserved values', $failures);
$lots->receive($lev10, 'LOT-RECV', '5.00000', null, 'recv-second');
check(Decimal::compare($sumAvailable($lev10), '11.00000') === 0, 'N02 repeated key does not double receipt', $failures);

// --- 6. F-08: safe lot activation ------------------------------------------
$r7 = $newRequest('LOT-TEST-7');
expectHttp(static fn() => $apply($r7, [['stock_level_id' => $lev7, 'quantity' => 1.0]]), 422, 'lot_conversion_required', 'F08 no lots blocks reservation', $failures);
$pdo->exec("UPDATE settings SET value='0' WHERE `key`='stock.reserve_enabled'");
expectHttp(static fn() => $apply($r7, [['stock_level_id' => $lev7, 'quantity' => 1.0]]), 422, 'lot_conversion_required', 'F08 check-only mode cannot bypass', $failures);
$pdo->exec("UPDATE settings SET value='1' WHERE `key`='stock.reserve_enabled'");
$lots->convert($lev7, [['lot_code' => 'LOT-Z', 'quantity' => '25.00000', 'expires_at' => null]]);
$apply($r7, [['stock_level_id' => $lev7, 'quantity' => 5.0]]);
check(Decimal::compare($sumReserved($lev7), '5.00000') === 0, 'F08 conversion enables reservation', $failures);

// --- 7. Legacy TRACK_LOTS=0 -------------------------------------------------
$r8 = $newRequest('LOT-TEST-8');
$apply($r8, [['stock_level_id' => $levLegacy, 'quantity' => 10.0]]);
check(Decimal::compare($levelA($levLegacy), '40.00000') === 0, 'legacy reservation unchanged', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM stock_lots WHERE STOCK_LEVEL_ID=$levLegacy")->fetchColumn() === 0, 'no lots for legacy product', $failures);
$reservations->release($r8);
check(Decimal::compare($levelA($levLegacy), '50.00000') === 0, 'legacy release unchanged', $failures);

// --- 8. Aggregate invariants ------------------------------------------------
foreach ([$lev1, $lev2, $lev3, $lev4, $lev5, $lev6, $lev7, $lev9, $lev10] as $lv) {
    check(Decimal::compare($levelA($lv), $sumAvailable($lv)) === 0, 'A equals sum of lot available level ' . $lv, $failures);
}
check(Decimal::compare($levelA($lev6), '50.00000') === 0, 'final multi-issue level restored', $failures);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d\n", count($failures)));
    exit(1);
}
echo "stock-lot-lifecycle: OK\n";
