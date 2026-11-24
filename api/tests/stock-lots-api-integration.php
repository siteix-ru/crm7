<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Controllers\StockLotsController;
use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Repositories\TokenRepository;
use App\Stocks\StockLotService;
use App\Stocks\StockReservationService;
use App\Support\Decimal;

/**
 * Disposable-MariaDB integration test for the stock lot read/receive/convert
 * HTTP layer. Controller methods are called directly with crafted Request
 * objects (no HTTP server); authentication uses real tokens from the test DB.
 */
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

// ---------------------------------------------------------------------------
// Isolated fixtures.
// ---------------------------------------------------------------------------
$pdo->exec('DELETE FROM stock_lot_movements');
$pdo->exec('DELETE FROM stock_issue_lines');
$pdo->exec('DELETE FROM stock_issue_operations');
$pdo->exec('DELETE FROM stock_lot_reservations');
$pdo->exec('DELETE FROM stock_lot_idempotency');
$pdo->exec('DELETE FROM stock_lots');
$pdo->exec('DELETE FROM stock_reservations');
$pdo->exec('DELETE FROM request_items');
$pdo->exec("DELETE FROM requests WHERE number LIKE 'LAPI-%'");
$pdo->exec('DELETE FROM user_tokens WHERE user_id IN (190,191,192,193)');
$pdo->exec("DELETE FROM user_level_stock WHERE USER_SID LIKE 'LAPI-%'");
$pdo->exec('DELETE FROM level_capabilities WHERE level IN (90,91,5,92)');
$pdo->exec('DELETE FROM users WHERE SID LIKE \'LAPI-%\' OR ID IN (190,191,192,193)');
$pdo->exec("DELETE FROM stock_levels WHERE SID LIKE 'LAPI-%'");
$pdo->exec("DELETE FROM nomenclature WHERE NAME_1C LIKE 'LAPI-%'");
$pdo->exec("DELETE FROM stocks WHERE SID LIKE 'LAPI-%'");

$pdo->exec("INSERT INTO settings (`key`,value) VALUES ('stock.reserve_enabled','1'),('module.warehouses','1') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$pdo->exec("INSERT INTO stocks (SID,NAME,NAME_1C,LEVEL,STATUS,ACTIVE) VALUES ('LAPI-WS-A','Lot A','LAPI-WS-A','{90}','Y','Y'),('LAPI-WS-B','Lot B','LAPI-WS-B','{91}','Y','Y')");
$pdo->exec("INSERT INTO nomenclature (SID,NAME,NAME_1C,UNIT,TRACK_LOTS) VALUES ('LAPI-ITEM','Lot item','LAPI-ITEM','pcs',1),('LAPI-LEGACY','Legacy item','LAPI-LEGACY','pcs',0)");
foreach ([
    ['LAPI-L1', 100.0], ['LAPI-L2', 50.0], ['LAPI-L3', 10.0],
    ['LAPI-L4', 0.0], ['LAPI-L5', 20.0], ['LAPI-L6', 30.0],
] as [$sid, $qty]) {
    $pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('$sid','" . (str_ends_with($sid, 'L2') ? 'LAPI-WS-B' : 'LAPI-WS-A') . "','Lot item','LAPI-ITEM','pcs',$qty)");
}
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('LAPI-LEG','LAPI-WS-A','Legacy item','LAPI-LEGACY','pcs',50.0)");
$pdo->exec("INSERT INTO request_statuses (code,title,sort,is_final) VALUES ('new','New',10,0) ON DUPLICATE KEY UPDATE title=VALUES(title)");

// Deterministic capabilities: A manages requests but only sees warehouse A;
// B has stocks.edit but no request management and only sees warehouse B.
$pdo->exec("INSERT INTO level_capabilities (level,capability_code) VALUES (90,'stocks.edit'),(90,'requests.view.all'),(91,'stocks.edit'),(5,'requests.view.own'),(92,'stocks.view')");
$pdo->exec("INSERT INTO users (ID,SID,LOGIN,PASSWORD,LEVEL,FULL_NAME,ACTIVE,STATUS,reg_state) VALUES
    (190,'LAPI-USER-A','lapiusera','x',90,'Lot API A','Y','Y','active'),
    (191,'LAPI-USER-B','lapiuserb','x',91,'Lot API B','Y','Y','active'),
    (192,'LAPI-CLIENT','lapiclient','x',5,'Lot API client','Y','Y','active'),
    (193,'LAPI-VIEWER','lapiviewer','x',92,'Lot API viewer','Y','Y','active')");

$tokenFor = static function (int $userId) use ($pdo): string {
    $token = bin2hex(random_bytes(32));
    (new TokenRepository())->create($userId, hash('sha256', $token), '127.0.0.1', 'test-agent', 1440);

    return $token;
};
$tokenA = $tokenFor(190);
$tokenB = $tokenFor(191);
$tokenClient = $tokenFor(192);
$tokenViewer = $tokenFor(193);
$pdo->exec("INSERT INTO user_level_stock (USER_SID,STOCK_SID,STATUS,ACTIVE) VALUES ('LAPI-VIEWER','LAPI-WS-A','Y','Y')");
$request = static function (string $method, string $path, array $query, array $body, string $token) {
    return new Request($method, $path, $query, $body, ['authorization' => 'Bearer ' . $token], '127.0.0.1');
};
$data = static function ($response): array {
    return (array) $response->payload['data'];
};

$levelId = static function (string $sid) use ($pdo): int {
    return (int) $pdo->query("SELECT ID FROM stock_levels WHERE SID='$sid'")->fetchColumn();
};
$warehouseId = static function (string $sid) use ($pdo): int {
    return (int) $pdo->query("SELECT ID FROM stocks WHERE SID='$sid'")->fetchColumn();
};
$levelA = static function (int $id) use ($pdo): string {
    return Decimal::parse((string) $pdo->query("SELECT CAST(QUANTITY AS DECIMAL(20,5)) FROM stock_levels WHERE ID=$id")->fetchColumn());
};
$sumAvailable = static function (int $id) use ($pdo): string {
    return Decimal::parse((string) $pdo->query("SELECT CAST(COALESCE(SUM(QUANTITY_AVAILABLE),0) AS DECIMAL(20,5)) FROM stock_lots WHERE STOCK_LEVEL_ID=$id")->fetchColumn());
};

$lev1 = $levelId('LAPI-L1');
$lev2 = $levelId('LAPI-L2');
$lev3 = $levelId('LAPI-L3');
$lev4 = $levelId('LAPI-L4');
$lev5 = $levelId('LAPI-L5');
$lev6 = $levelId('LAPI-L6');
$levLegacy = $levelId('LAPI-LEG');
$wsA = $warehouseId('LAPI-WS-A');
$wsB = $warehouseId('LAPI-WS-B');

$lots = new StockLotService();
$reservations = new StockReservationService();
$controller = new StockLotsController();

$apply = static function (int $rid, array $items) use ($reservations, $pdo): void {
    $reservations->lock($rid);
    $pdo->beginTransaction();
    try {
        $reservations->apply($rid, $items);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $reservations->unlock($rid);
    }
};
$newRequest = static function (string $number, ?int $managerId) use ($pdo): int {
    $manager = $managerId === null ? 'NULL' : (string) $managerId;
    $pdo->exec("INSERT INTO requests (number,subject,client_id,status_id,version,manager_id) VALUES ('$number','Lot API test',192,'new',1,$manager)");

    return (int) $pdo->query("SELECT id FROM requests WHERE number='$number'")->fetchColumn();
};

// --- 1. Foundation ----------------------------------------------------------
$lots->convert($lev1, [
    ['lot_code' => 'LAPI-ALPHA', 'quantity' => '60.00000', 'expires_at' => null],
    ['lot_code' => 'LAPI-BETA', 'quantity' => '40.00000', 'expires_at' => '2020-01-01'],
]);
$lots->convert($lev2, [['lot_code' => 'LAPI-B-LOT', 'quantity' => '50.00000', 'expires_at' => null]]);

// --- 2. GET /stocks/lots: filters, canonical values, warehouse isolation ----
$res = $controller->lots($request('GET', '/stocks/lots', ['item_id' => (string) $lev1], [], $tokenA), []);
$list = $data($res);
check($res->status === 200 && $list['total'] === 2 && count($list['items']) === 2, 'list: item_id filter', $failures);
check(array_keys($list['items'][0]) === [
    'id', 'stock_level_id', 'item_name', 'warehouse_id', 'warehouse_name', 'lot_code',
    'received_at', 'expires_at', 'quantity_available', 'quantity_reserved', 'quantity_total', 'status',
], 'list: exact item shape', $failures);
check($list['items'][0]['quantity_available'] === '60.00000'
    && $list['items'][0]['quantity_total'] === '60.00000'
    && $list['items'][0]['warehouse_name'] === 'Lot A', 'list: canonical quantities and joins', $failures);

$res = $controller->lots($request('GET', '/stocks/lots', [
    'item_id' => (string) $lev1, 'lot_code' => 'alpha',
], [], $tokenA), []);
$list = $data($res);
check($list['total'] === 1 && $list['items'][0]['lot_code'] === 'LAPI-ALPHA', 'list: partial case-insensitive lot_code', $failures);

$res = $controller->lots($request('GET', '/stocks/lots', ['status' => 'expired'], [], $tokenA), []);
$list = $data($res);
check($list['total'] === 1 && $list['items'][0]['lot_code'] === 'LAPI-BETA'
    && $list['items'][0]['status'] === 'expired', 'list: expired status', $failures);

$res = $controller->lots($request('GET', '/stocks/lots', ['expires_before' => '2020-06-01'], [], $tokenA), []);
check($data($res)['total'] === 1, 'list: expires_before', $failures);
$res = $controller->lots($request('GET', '/stocks/lots', ['expires_after' => '2021-01-01'], [], $tokenA), []);
check($data($res)['total'] === 0, 'list: expires_after', $failures);

$res = $controller->lots($request('GET', '/stocks/lots', ['warehouse_id' => (string) $wsA], [], $tokenA), []);
check($data($res)['total'] === 2, 'list: warehouse_id filter', $failures);
expectHttp(static fn () => $controller->lots($request('GET', '/stocks/lots', [
    'warehouse_id' => (string) $wsB,
], [], $tokenA), []), 403, 'forbidden', 'list: explicit forbidden warehouse denied', $failures);

$res = $controller->lots($request('GET', '/stocks/lots', ['per_page' => '1'], [], $tokenA), []);
$list = $data($res);
check($list['total'] === 2 && count($list['items']) === 1
    && $list['page'] === 1 && $list['per_page'] === 1, 'list: pagination', $failures);
$res = $controller->lots($request('GET', '/stocks/lots', ['per_page' => '500'], [], $tokenA), []);
check($data($res)['per_page'] === 200, 'list: per_page capped at 200', $failures);

$res = $controller->lots($request('GET', '/stocks/lots', [], [], $tokenB), []);
$list = $data($res);
check($list['total'] === 1 && $list['items'][0]['warehouse_name'] === 'Lot B', 'list: user sees only accessible warehouses', $failures);
expectHttp(static fn () => $controller->lots($request('GET', '/stocks/lots', [], [], $tokenClient), []),
    403, 'forbidden', 'list: no stock view capability denied', $failures);

// A read-only stock viewer can list lots, but only for accessible warehouses
// and never mutate them.
$res = $controller->lots($request('GET', '/stocks/lots', [], [], $tokenViewer), []);
$viewerList = $data($res);
check($res->status === 200 && $viewerList['total'] === 2
    && $viewerList['items'][0]['warehouse_name'] === 'Lot A',
    'list: stocks.view can read accessible lots', $failures);
expectHttp(static fn () => $controller->lots($request('GET', '/stocks/lots', [
    'warehouse_id' => (string) $wsB,
], [], $tokenViewer), []), 403, 'forbidden', 'list: viewer cannot filter other warehouse', $failures);
expectHttp(static fn () => $controller->receive($request('POST', '/stocks/lots/receive', [], [
    'stock_level_id' => $lev3, 'lot_code' => 'LAPI-VIEW-LOT', 'quantity' => '1.00000',
], $tokenViewer), []), 403, 'forbidden', 'receive: stocks.view cannot write', $failures);

// --- 3. POST /stocks/lots/receive ------------------------------------------
// Into an existing lot.
$res = $controller->receive($request('POST', '/stocks/lots/receive', [], [
    'stock_level_id' => $lev3, 'lot_code' => 'LAPI-RECV-BASE', 'quantity' => '10.00000',
], $tokenA), []);
$recv = $data($res);
check($res->status === 200 && $recv['already_applied'] === false
    && $recv['level_id'] === $lev3 && $recv['quantity'] === '10.00000'
    && is_int($recv['lot_id']), 'receive: into existing lot', $failures);
$res = $controller->receive($request('POST', '/stocks/lots/receive', [], [
    'stock_level_id' => $lev3, 'lot_code' => 'LAPI-RECV-BASE', 'quantity' => 5,
], $tokenA), []);
check($res->status === 200 && Decimal::compare($sumAvailable($lev3), '15.00000') === 0,
    'receive: second receipt increases lot', $failures);

// Creating a new lot.
$res = $controller->receive($request('POST', '/stocks/lots/receive', [], [
    'stock_level_id' => $lev4, 'lot_code' => 'LAPI-NEW-LOT', 'quantity' => '7.00000',
    'idempotency_key' => 'lapi-recv-idem',
], $tokenA), []);
$created = $data($res);
check($res->status === 200 && is_int($created['lot_id']) && $created['already_applied'] === false,
    'receive: creates new lot', $failures);
check(Decimal::compare($sumAvailable($lev4), '7.00000') === 0, 'receive: new lot balance', $failures);

// Same key replay, then a different payload with the same key.
$res = $controller->receive($request('POST', '/stocks/lots/receive', [], [
    'stock_level_id' => $lev4, 'lot_code' => 'LAPI-NEW-LOT', 'quantity' => '7.00000',
    'idempotency_key' => 'lapi-recv-idem',
], $tokenA), []);
$replay = $data($res);
check($res->status === 200 && $replay['already_applied'] === true
    && is_int($replay['lot_id']) && Decimal::compare($sumAvailable($lev4), '7.00000') === 0,
    'receive: same key replay is idempotent', $failures);
expectHttp(static fn () => $controller->receive($request('POST', '/stocks/lots/receive', [], [
    'stock_level_id' => $lev4, 'lot_code' => 'LAPI-NEW-LOT', 'quantity' => '9.00000',
    'idempotency_key' => 'lapi-recv-idem',
], $tokenA), []), 409, 'idempotency_key_reused', 'receive: same key different payload -> 409', $failures);
expectHttp(static fn () => $controller->receive($request('POST', '/stocks/lots/receive', [], [
    'stock_level_id' => $lev3, 'lot_code' => '   ', 'quantity' => '1.00000',
], $tokenA), []), 422, 'validation_error', 'receive: blank lot_code rejected', $failures);
expectHttp(static fn () => $controller->receive($request('POST', '/stocks/lots/receive', [], [
    'stock_level_id' => $lev3, 'lot_code' => 'LAPI-RECV-BASE', 'quantity' => '1.00000',
], $tokenClient), []), 403, 'forbidden', 'receive: no stocks.edit denied', $failures);

// --- 4. POST /stocks/lots/convert ------------------------------------------
$res = $controller->convert($request('POST', '/stocks/lots/convert', [], [
    'stock_level_id' => $lev5,
    'lots' => [
        ['lot_code' => 'LAPI-C1', 'quantity' => '12.00000', 'expires_at' => '2030-01-01'],
        ['lot_code' => 'LAPI-C2', 'quantity' => 8],
    ],
], $tokenA), []);
$converted = $data($res);
check($res->status === 200 && $converted['stock_level_id'] === $lev5
    && $converted['quantity'] === '20.00000' && count($converted['lots']) === 2
    && $converted['lots'][1]['quantity'] === '8.00000', 'convert: correct sum', $failures);
check(Decimal::compare($sumAvailable($lev5), '20.00000') === 0, 'convert: lots created', $failures);

expectHttp(static fn () => $controller->convert($request('POST', '/stocks/lots/convert', [], [
    'stock_level_id' => $lev6,
    'lots' => [['lot_code' => 'LAPI-BAD', 'quantity' => '25.00000']],
], $tokenA), []), 422, 'lot_distribution_mismatch', 'convert: wrong sum -> 422', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM stock_lots WHERE STOCK_LEVEL_ID=$lev6")->fetchColumn() === 0,
    'convert: wrong sum rolls back', $failures);

expectHttp(static fn () => $controller->convert($request('POST', '/stocks/lots/convert', [], [
    'stock_level_id' => $lev5,
    'lots' => [['lot_code' => 'LAPI-AGAIN', 'quantity' => '20.00000']],
], $tokenA), []), 422, 'invalid_mode_transition', 'convert: second convert -> 422', $failures);
expectHttp(static fn () => $controller->convert($request('POST', '/stocks/lots/convert', [], [
    'stock_level_id' => $lev6, 'lots' => [],
], $tokenA), []), 422, 'validation_error', 'convert: empty lots rejected', $failures);
expectHttp(static fn () => $controller->convert($request('POST', '/stocks/lots/convert', [], [
    'stock_level_id' => $lev2,
    'lots' => [['lot_code' => 'LAPI-FORBIDDEN', 'quantity' => '1.00000']],
], $tokenA), []), 403, 'forbidden', 'convert: forbidden warehouse denied', $failures);
expectHttp(static fn () => $controller->convert($request('POST', '/stocks/lots/convert', [], [
    'stock_level_id' => $lev5,
    'lots' => [['lot_code' => 'LAPI-NO-RIGHTS', 'quantity' => '1.00000']],
], $tokenClient), []), 403, 'forbidden', 'convert: no stocks.edit denied', $failures);

// --- 5. GET /requests/{id}/lots --------------------------------------------
$r1 = $newRequest('LAPI-REQ-1', 190);
$apply($r1, [['stock_level_id' => $lev1, 'quantity' => 20.0]]);
$issue = $lots->issueWithLock($r1, '4.00000', 'lapi-issue-1');
$issueId = (int) $issue['result_id'];
$lots->reversalWithLock($issueId, '1.00000', 'lapi-reversal-1');

$res = $controller->requestLots($request('GET', '/requests/' . $r1 . '/lots', [], [], $tokenA), ['id' => (string) $r1]);
$detail = $data($res);
check($res->status === 200 && $detail['request_id'] === $r1, 'request lots: response envelope', $failures);
check(count($detail['levels']) === 1 && $detail['levels'][0]['stock_level_id'] === $lev1
    && $detail['levels'][0]['warehouse_name'] === 'Lot A'
    && Decimal::compare($detail['levels'][0]['reserved'], '16.00000') === 0
    && Decimal::compare($detail['levels'][0]['available'], '81.00000') === 0,
    'request lots: levels with reserved/available', $failures);
check(count($detail['reservations']) === 1 && $detail['reservations'][0]['lot_code'] === 'LAPI-ALPHA'
    && Decimal::compare($detail['reservations'][0]['quantity'], '16.00000') === 0,
    'request lots: per-lot reservations', $failures);
check(count($detail['issues']) === 1 && $detail['issues'][0]['id'] === $issueId
    && $detail['issues'][0]['idempotency_key'] === 'lapi-issue-1'
    && $detail['issues'][0]['remaining_quantity'] === '3.00000',
    'request lots: issue history with remaining quantity', $failures);

// Manager B cannot manage the request and has no access to its warehouse.
expectHttp(static fn () => $controller->requestLots(
    $request('GET', '/requests/' . $r1 . '/lots', [], [], $tokenB), ['id' => (string) $r1]
), 403, 'forbidden', 'request lots: denial on reserved warehouse', $failures);

// Warehouse worker fallback: no request rights, but stocks.edit + level access.
$r2 = $newRequest('LAPI-REQ-2', null);
$apply($r2, [['stock_level_id' => $lev2, 'quantity' => '5.00000']]);
$res = $controller->requestLots($request('GET', '/requests/' . $r2 . '/lots', [], [], $tokenB), ['id' => (string) $r2]);
$detail2 = $data($res);
check($res->status === 200 && count($detail2['levels']) === 1
    && $detail2['levels'][0]['stock_level_id'] === $lev2
    && count($detail2['reservations']) === 1,
    'request lots: warehouse worker fallback', $failures);
expectHttp(static fn () => $controller->requestLots($request('GET', '/requests/999999/lots', [], [], $tokenA), ['id' => '999999']),
    404, 'not_found', 'request lots: missing request -> 404', $failures);
expectHttp(static fn () => $controller->requestLots($request('GET', '/requests/' . $r2 . '/lots', [], [], $tokenClient), ['id' => (string) $r2]),
    403, 'forbidden', 'request lots: no stocks.edit denied', $failures);

// --- 6. TRACK_LOTS=0 product is unaffected ---------------------------------
expectHttp(static fn () => $controller->receive($request('POST', '/stocks/lots/receive', [], [
    'stock_level_id' => $levLegacy, 'lot_code' => 'LAPI-LEGACY-LOT', 'quantity' => '1.00000',
], $tokenA), []), 422, 'invalid_mode_transition', 'legacy: receive rejected', $failures);
expectHttp(static fn () => $controller->convert($request('POST', '/stocks/lots/convert', [], [
    'stock_level_id' => $levLegacy, 'lots' => [['lot_code' => 'LAPI-LEGACY-LOT', 'quantity' => '50.00000']],
], $tokenA), []), 422, 'invalid_mode_transition', 'legacy: convert rejected', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM stock_lots WHERE STOCK_LEVEL_ID=$levLegacy")->fetchColumn() === 0,
    'legacy: no lots created', $failures);
check(Decimal::compare($levelA($levLegacy), '50.00000') === 0, 'legacy: balance unchanged', $failures);
$res = $controller->lots($request('GET', '/stocks/lots', ['item_id' => (string) $levLegacy], [], $tokenA), []);
check($data($res)['total'] === 0, 'legacy: absent from lot list', $failures);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d\n", count($failures)));
    exit(1);
}
echo "stock-lots-api-integration: OK\n";
