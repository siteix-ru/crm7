<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Controllers\RequestController;
use App\Controllers\StocksController;
use App\Core\Config;
use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Repositories\SettingsRepository;
use App\Repositories\TokenRepository;
use App\Support\Decimal;

/** Disposable-MariaDB regression test for TRACK_LOTS=0 (legacy) compatibility (3.4.264). */
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

// Database::freshPdo() builds its own connection from DB_* settings; point it
// at the same disposable database (the worker children inherit these values).
putenv('DB_HOST=' . $host);
putenv('DB_PORT=' . $dsnPort);
putenv('DB_NAME=' . $dbName);
putenv('DB_USER=' . $dbUser);
putenv('DB_PASS=' . $dbPass);
Config::load(__DIR__ . '/../config/.env');

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

/** Safe diagnostic payload for a worker failure (never includes tokens). */
function workerErrorDiagnostics(Throwable $e): array
{
    $outcome = ['outcome' => 'error', 'class' => get_class($e), 'message' => $e->getMessage()];
    if ($e instanceof HttpException) {
        $outcome['status'] = $e->status;
        $outcome['code'] = $e->errorCode;
    }
    if ($e instanceof PDOException) {
        $outcome['sqlstate'] = $e->errorInfo[0] ?? null;
        $outcome['driver_code'] = $e->errorInfo[1] ?? null;
        $outcome['driver_message'] = $e->errorInfo[2] ?? null;
    }

    return $outcome;
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

if ($role === 'l264-worker') {
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
    $qty = (string) ($opt['qty'] ?? '');
    $token = (string) ($opt['token'] ?? '');

    $workerPdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $workerPdo);

    $controller = new RequestController();
    writeSignal($dir, 'ready_' . $slot, ['pid' => getmypid(), 'conn' => (int) $workerPdo->query('SELECT CONNECTION_ID()')->fetchColumn()]);
    if (!waitForSignal($dir, 'go', 60)) {
        writeSignal($dir, 'done_' . $slot, ['outcome' => 'error', 'code' => 'go_timeout']);
        exit(0);
    }

    $version = (int) $workerPdo->query('SELECT version FROM requests WHERE ID=' . $requestId)->fetchColumn();
    try {
        $response = $controller->items(new Request(
            'POST',
            '/requests/' . $requestId . '/items',
            [],
            [
                'items' => [[
                    'stock_level_id' => $levelId,
                    'quantity' => $qty,
                    'name' => 'L264 Legacy Item',
                    'unit' => 'pcs',
                ]],
                'version' => $version,
            ],
            ['authorization' => 'Bearer ' . $token],
            '127.0.0.1'
        ), ['id' => (string) $requestId]);
        writeSignal($dir, 'done_' . $slot, [
            'outcome' => 'success',
            'status' => $response->status,
            'response' => $response->payload,
        ]);
    } catch (Throwable $e) {
        writeSignal($dir, 'done_' . $slot, workerErrorDiagnostics($e));
    }
    exit(0);
}

if ($role === 'l264-cancel') {
    $opt = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
            $opt[$m[1]] = $m[2];
        }
    }
    $dir = (string) ($opt['dir'] ?? '');
    $slot = (string) ($opt['slot'] ?? '');
    $requestId = (int) ($opt['request'] ?? 0);
    $token = (string) ($opt['token'] ?? '');

    $workerPdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $workerPdo);

    writeSignal($dir, 'ready_' . $slot, ['pid' => getmypid(), 'conn' => (int) $workerPdo->query('SELECT CONNECTION_ID()')->fetchColumn()]);
    if (!waitForSignal($dir, 'go', 60)) {
        writeSignal($dir, 'done_' . $slot, ['outcome' => 'error', 'code' => 'go_timeout']);
        exit(0);
    }

    $version = (int) $workerPdo->query('SELECT version FROM requests WHERE ID=' . $requestId)->fetchColumn();
    try {
        $response = (new RequestController())->transition(new Request(
            'POST',
            '/requests/' . $requestId . '/transition',
            [],
            ['to_status' => 'canceled', 'version' => $version],
            ['authorization' => 'Bearer ' . $token],
            '127.0.0.1'
        ), ['id' => (string) $requestId]);
        writeSignal($dir, 'done_' . $slot, [
            'outcome' => 'success',
            'status' => $response->status,
            'response' => $response->payload,
        ]);
    } catch (Throwable $e) {
        writeSignal($dir, 'done_' . $slot, workerErrorDiagnostics($e));
    }
    exit(0);
}

if ($role === 'l264-multi') {
    $opt = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
            $opt[$m[1]] = $m[2];
        }
    }
    $dir = (string) ($opt['dir'] ?? '');
    $slot = (string) ($opt['slot'] ?? '');
    $requestId = (int) ($opt['request'] ?? 0);
    $levels = array_map('intval', explode(',', (string) ($opt['levels'] ?? '')));
    $token = (string) ($opt['token'] ?? '');

    $workerPdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $workerPdo);

    writeSignal($dir, 'multi_ready_' . $slot, ['pid' => getmypid(), 'conn' => (int) $workerPdo->query('SELECT CONNECTION_ID()')->fetchColumn()]);
    if (!waitForSignal($dir, 'multi_go', 60)) {
        writeSignal($dir, 'multi_done_' . $slot, ['outcome' => 'error', 'code' => 'go_timeout']);
        exit(0);
    }

    $items = [];
    foreach ($levels as $levelId) {
        $items[] = [
            'stock_level_id' => $levelId,
            'quantity' => '3.00000',
            'name' => 'L264 Legacy Item',
            'unit' => 'pcs',
        ];
    }
    try {
        (new RequestController())->items(new Request(
            'POST',
            '/requests/' . $requestId . '/items',
            [],
            ['items' => $items, 'version' => 1],
            ['authorization' => 'Bearer ' . $token],
            '127.0.0.1'
        ), ['id' => (string) $requestId]);
        writeSignal($dir, 'multi_done_' . $slot, ['outcome' => 'success']);
    } catch (Throwable $e) {
        $outcome = ['outcome' => 'error', 'class' => get_class($e), 'message' => $e->getMessage()];
        if ($e instanceof HttpException) {
            $outcome['status'] = $e->status;
            $outcome['code'] = $e->errorCode;
        }
        writeSignal($dir, 'multi_done_' . $slot, $outcome);
    }
    exit(0);
}

// ------------------------------------------------------------------ coordinator
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

$settings = new SettingsRepository();
$original = [
    'stock.reserve_enabled' => $settings->get('stock.reserve_enabled'),
    'stock.allow_zero' => $settings->get('stock.allow_zero'),
    'module.warehouses' => $settings->get('module.warehouses'),
    'module.stock_edit' => $settings->get('module.stock_edit'),
];
$settings->set('stock.reserve_enabled', '1');
$settings->set('stock.allow_zero', '0');
$settings->set('module.warehouses', '1');
$settings->set('module.stock_edit', '1');

// Every fixture row lives in the L264- namespace; no other test's data is
// deleted or modified. Cleanup is idempotent so reruns are safe.
$cleanupFixtures = static function () use ($pdo): void {
    $pdo->exec("DELETE FROM stock_lot_movements
        WHERE STOCK_LEVEL_ID IN (SELECT ID FROM stock_levels WHERE SID LIKE 'L264-%')
           OR REQUEST_ID IN (SELECT ID FROM requests WHERE number LIKE 'L264-%')");
    $pdo->exec("DELETE FROM stock_issue_lines
        WHERE STOCK_LEVEL_ID IN (SELECT ID FROM stock_levels WHERE SID LIKE 'L264-%')
           OR ISSUE_ID IN (SELECT ID FROM stock_issue_operations
                            WHERE REQUEST_ID IN (SELECT ID FROM requests WHERE number LIKE 'L264-%')
                               OR STOCK_LEVEL_ID IN (SELECT ID FROM stock_levels WHERE SID LIKE 'L264-%'))");
    $pdo->exec("DELETE FROM stock_issue_operations
        WHERE REQUEST_ID IN (SELECT ID FROM requests WHERE number LIKE 'L264-%')
           OR STOCK_LEVEL_ID IN (SELECT ID FROM stock_levels WHERE SID LIKE 'L264-%')");
    $pdo->exec("DELETE FROM stock_lot_reservations
        WHERE LOT_ID IN (SELECT ID FROM stock_lots
                          WHERE STOCK_LEVEL_ID IN (SELECT ID FROM stock_levels WHERE SID LIKE 'L264-%'))
           OR RESERVATION_ID IN (SELECT ID FROM stock_reservations
                                  WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE 'L264-%'))");
    $pdo->exec("DELETE FROM stock_lot_idempotency
        WHERE IDEMPOTENCY_KEY LIKE 'L264-%' OR SCOPE_KEY LIKE 'L264-%'");
    $pdo->exec("DELETE FROM stock_lots
        WHERE STOCK_LEVEL_ID IN (SELECT ID FROM stock_levels WHERE SID LIKE 'L264-%')");
    $pdo->exec("DELETE FROM stock_reservations
        WHERE stock_level_id IN (SELECT ID FROM stock_levels WHERE SID LIKE 'L264-%')
           OR request_id IN (SELECT ID FROM requests WHERE number LIKE 'L264-%')");
    $pdo->exec("DELETE FROM request_items
        WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE 'L264-%')");
    $pdo->exec("DELETE FROM requests WHERE number LIKE 'L264-%'");
    $pdo->exec('DELETE FROM user_tokens WHERE user_id IN (290,291,292,293)');
    $pdo->exec("DELETE FROM user_level_stock WHERE USER_SID LIKE 'L264-%'");
    $pdo->exec("DELETE FROM users WHERE SID LIKE 'L264-%' OR ID IN (290,291,292,293)");
    $pdo->exec("DELETE FROM stock_levels WHERE SID LIKE 'L264-%'");
    $pdo->exec("DELETE FROM nomenclature WHERE SID LIKE 'L264-%'");
    $pdo->exec("DELETE FROM stocks WHERE SID LIKE 'L264-%'");
    $pdo->exec('DELETE FROM level_capabilities WHERE level IN (90,91,5)');
};
// Preserve the baseline capability rows that cleanup removes, so reruns and
// any test following this one see the same permissions as before.
$originalCapabilities = $pdo->query(
    'SELECT level, capability_code FROM level_capabilities WHERE level IN (90,91,5)'
)->fetchAll(PDO::FETCH_ASSOC);
$cleanupFixtures();

$pdo->exec("INSERT INTO stocks (SID,NAME,NAME_1C,LEVEL,STATUS,ACTIVE) VALUES
    ('L264-WS-A','L264 Warehouse A','L264-WS-A','{90}','Y','Y'),
    ('L264-WS-B','L264 Warehouse B','L264-WS-B','{91}','Y','Y')");
$pdo->exec("INSERT INTO nomenclature (SID,NAME,NAME_1C,UNIT,TRACK_LOTS) VALUES
    ('L264-LEGACY','L264 Legacy Item','L264-LEGACY','pcs',0),
    ('L264-LOT','L264 Lot Item','L264-LOT','pcs',1)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES
    ('L264-L','L264-WS-A','L264 Legacy Item','L264-LEGACY','pcs',10.00000),
    ('L264-ACL-L','L264-WS-A','L264 Legacy Item','L264-LEGACY','pcs',7.00000),
    ('L264-CONC','L264-WS-A','L264 Legacy Item','L264-LEGACY','pcs',5.00000),
    ('L264-MA','L264-WS-A','L264 Legacy Item','L264-LEGACY','pcs',10.00000),
    ('L264-MB','L264-WS-A','L264 Legacy Item','L264-LEGACY','pcs',10.00000),
    ('L264-MC','L264-WS-A','L264 Legacy Item','L264-LEGACY','pcs',10.00000),
    ('L264-F','L264-WS-A','L264 Legacy Item','L264-LEGACY','pcs',10.00000),
    ('L264-G','L264-WS-A','L264 Legacy Item','L264-LEGACY','pcs',10.00000),
    ('L264-LOT-L','L264-WS-A','L264 Lot Item','L264-LOT','pcs',10.00000)");
$pdo->exec("INSERT INTO request_statuses (code,title,sort,is_final,is_active) VALUES
    ('new','New',10,0,1),('canceled','Canceled',70,1,1)
    ON DUPLICATE KEY UPDATE title=VALUES(title), is_active=1");
$pdo->exec("INSERT INTO users (ID,SID,LOGIN,PASSWORD,LEVEL,FULL_NAME,ACTIVE,STATUS,reg_state) VALUES
    (290,'L264-USER-A','l264usera','x',90,'L264 User A','Y','Y','active'),
    (291,'L264-USER-B','l264userb','x',90,'L264 User B','Y','Y','active'),
    (292,'L264-ACL','l264acl','x',91,'L264 ACL','Y','Y','active'),
    (293,'L264-CLIENT','l264client','x',5,'L264 Client','Y','Y','active')");
$pdo->exec("INSERT INTO level_capabilities (level,capability_code) VALUES
    (90,'stocks.edit'),(90,'requests.view.all'),(90,'requests.transition'),
    (91,'stocks.edit'),(5,'requests.view.own')");

$tokenFor = static function (int $userId) use ($pdo): string {
    $token = bin2hex(random_bytes(32));
    (new TokenRepository())->create($userId, hash('sha256', $token), '127.0.0.1', 'test-agent', 1440);

    return $token;
};
$tokenA = $tokenFor(290);
$tokenB = $tokenFor(291);
$tokenAcl = $tokenFor(292);

$apiRequest = static function (string $method, string $path, array $query, array $body, string $token) {
    return new Request($method, $path, $query, $body, ['authorization' => 'Bearer ' . $token], '127.0.0.1');
};
$newFixtureRequest = static function (string $number) use ($pdo): int {
    $pdo->exec("INSERT INTO requests (number,subject,client_id,manager_id,status_id,version)
                VALUES ('$number','L264 test',293,290,'new',1)");

    return (int) $pdo->query("SELECT ID FROM requests WHERE number='$number'")->fetchColumn();
};

$levelId = static function (string $sid) use ($pdo): int {
    return (int) $pdo->query("SELECT ID FROM stock_levels WHERE SID='$sid'")->fetchColumn();
};
$levL = $levelId('L264-L');
$levAcl = $levelId('L264-ACL-L');
$levConc = $levelId('L264-CONC');
$levMa = $levelId('L264-MA');
$levMb = $levelId('L264-MB');
$levMc = $levelId('L264-MC');
$levF = $levelId('L264-F');
$levG = $levelId('L264-G');
$levLot = $levelId('L264-LOT-L');
$levelIds = [$levL, $levAcl, $levConc, $levMa, $levMb, $levMc, $levF, $levG, $levLot];

$reqA = $newFixtureRequest('L264-REQ-A');
$reqD = $newFixtureRequest('L264-REQ-LOT');
$reqConcA = $newFixtureRequest('L264-REQ-CONC-A');
$reqConcB = $newFixtureRequest('L264-REQ-CONC-B');
$pdo->exec("UPDATE requests SET manager_id=291 WHERE ID=$reqConcB");
$reqMultiA = $newFixtureRequest('L264-REQ-MULTI-A');
$reqMultiB = $newFixtureRequest('L264-REQ-MULTI-B');
$pdo->exec("UPDATE requests SET manager_id=291 WHERE ID=$reqMultiB");
$reqF1 = $newFixtureRequest('L264-REQ-F1');
$reqF2 = $newFixtureRequest('L264-REQ-F2');
$pdo->exec("UPDATE requests SET manager_id=291 WHERE ID=$reqF2");
$reqG1 = $newFixtureRequest('L264-REQ-G1');
$reqG2 = $newFixtureRequest('L264-REQ-G2');
$pdo->exec("UPDATE requests SET manager_id=291 WHERE ID=$reqG2");
$requestIds = [$reqA, $reqD, $reqConcA, $reqConcB, $reqMultiA, $reqMultiB, $reqF1, $reqF2, $reqG1, $reqG2];

// A = available (stock_levels.QUANTITY), R = SUM(stock_reservations.quantity),
// P = A + R. All comparisons use Decimal, never floats.
$available = static function (int $id) use ($pdo): string {
    return Decimal::parse((string) $pdo->query(
        "SELECT CAST(COALESCE(QUANTITY,0) AS DECIMAL(20,5)) FROM stock_levels WHERE ID=$id"
    )->fetchColumn());
};
$reserved = static function (int $id) use ($pdo): string {
    return Decimal::parse((string) $pdo->query(
        "SELECT CAST(COALESCE(SUM(quantity),0) AS DECIMAL(20,5)) FROM stock_reservations WHERE stock_level_id=$id"
    )->fetchColumn());
};
$physical = static function (int $id) use ($available, $reserved): string {
    return Decimal::add($available($id), $reserved($id));
};

$assertNoLotRows = static function (string $label) use ($pdo, $levelIds, $requestIds, $failures): void {
    $levels = implode(',', array_map('intval', $levelIds));
    $requests = implode(',', array_map('intval', $requestIds));
    $queries = [
        'stock_lots' => "SELECT COUNT(*) FROM stock_lots WHERE STOCK_LEVEL_ID IN ($levels)",
        'stock_lot_reservations' => "SELECT COUNT(*) FROM stock_lot_reservations r
            LEFT JOIN stock_lots l ON l.ID = r.LOT_ID
            LEFT JOIN stock_reservations p ON p.ID = r.RESERVATION_ID
            WHERE l.STOCK_LEVEL_ID IN ($levels) OR p.request_id IN ($requests)",
        'stock_issue_operations' => "SELECT COUNT(*) FROM stock_issue_operations
            WHERE REQUEST_ID IN ($requests) OR STOCK_LEVEL_ID IN ($levels)",
        'stock_issue_lines' => "SELECT COUNT(*) FROM stock_issue_lines
            WHERE STOCK_LEVEL_ID IN ($levels) OR ISSUE_ID IN (
                SELECT ID FROM stock_issue_operations
                WHERE REQUEST_ID IN ($requests) OR STOCK_LEVEL_ID IN ($levels))",
        'stock_lot_movements' => "SELECT COUNT(*) FROM stock_lot_movements
            WHERE STOCK_LEVEL_ID IN ($levels) OR REQUEST_ID IN ($requests)",
    ];
    foreach ($queries as $table => $sql) {
        check((int) $pdo->query($sql)->fetchColumn() === 0, $label . ': no rows in ' . $table, $failures);
    }
    $scopeKeys = array_map('strval', $requestIds);
    $placeholders = implode(',', array_fill(0, count($scopeKeys), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_lot_idempotency
        WHERE IDEMPOTENCY_KEY LIKE 'L264-%' OR SCOPE_KEY LIKE 'L264-%'
           OR SCOPE_KEY IN ($placeholders)");
    $stmt->execute($scopeKeys);
    check((int) $stmt->fetchColumn() === 0, $label . ': no rows in stock_lot_idempotency', $failures);

    check((int) $pdo->query("SELECT COUNT(*) FROM stock_issue_operations WHERE REQUEST_ID IN ($requests)")->fetchColumn() === 0,
        $label . ': no issue operations for test requests', $failures);
    check((int) $pdo->query("SELECT COUNT(*) FROM stock_issue_lines WHERE ISSUE_ID IN (
        SELECT ID FROM stock_issue_operations WHERE REQUEST_ID IN ($requests))")->fetchColumn() === 0,
        $label . ': no issue lines for test requests', $failures);
};

$stocksController = new StocksController();
$requestController = new RequestController();

// --- A. Full legacy cycle: set balance -> reserve -> cancel -------------------
$res = $stocksController->updateItem(
    $apiRequest('PATCH', '/stocks/items/' . $levL, [], ['quantity' => '100.00000'], $tokenA),
    ['itemId' => (string) $levL]
);
check($res->status === 200, 'A: updateItem balance -> 100 (200)', $failures);
check(Decimal::compare($available($levL), '100.00000') === 0
    && Decimal::compare($reserved($levL), '0.00000') === 0
    && Decimal::compare($physical($levL), '100.00000') === 0,
    'A: A=100 R=0 P=100 after updateItem', $failures);

$res = $requestController->items(
    $apiRequest('POST', '/requests/' . $reqA . '/items', [], [
        'items' => [[
            'stock_level_id' => $levL,
            'quantity' => '30',
            'name' => 'L264 Legacy Item',
            'unit' => 'pcs',
        ]],
        'version' => 1,
    ], $tokenA),
    ['id' => (string) $reqA]
);
check($res->status === 200, 'A: reserve 30 via items (200)', $failures);
check(Decimal::compare($available($levL), '70.00000') === 0
    && Decimal::compare($reserved($levL), '30.00000') === 0
    && Decimal::compare($physical($levL), '100.00000') === 0,
    'A: A=70 R=30 P=100 after reserve', $failures);

$version = (int) $pdo->query("SELECT version FROM requests WHERE ID=$reqA")->fetchColumn();
$res = $requestController->transition(
    $apiRequest('POST', '/requests/' . $reqA . '/transition', [], [
        'to_status' => 'canceled',
        'version' => $version,
    ], $tokenA),
    ['id' => (string) $reqA]
);
check($res->status === 200, 'A: cancel via transition (200)', $failures);
check(Decimal::compare($available($levL), '100.00000') === 0
    && Decimal::compare($reserved($levL), '0.00000') === 0
    && Decimal::compare($physical($levL), '100.00000') === 0,
    'A: A=100 R=0 P=100 after canceled', $failures);
$assertNoLotRows('A');

// --- B. Two concurrent legacy reservations, 4 + 4 with only 5 available ------
check(Decimal::compare($available($levConc), '5.00000') === 0,
    'B: insufficient-stock variant starts with A=5', $failures);
$dir = sys_get_temp_dir() . '/crm_l264_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$specs = [
    'A' => ['request' => $reqConcA, 'token' => $tokenA],
    'B' => ['request' => $reqConcB, 'token' => $tokenB],
];
$pipes = [];
foreach ($specs as $slot => $spec) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' --role=l264-worker --dir=' . escapeshellarg($dir)
        . ' --slot=' . escapeshellarg($slot)
        . ' --request=' . (int) $spec['request']
        . ' --level=' . (int) $levConc
        . ' --qty=4.00000 --token=' . escapeshellarg($spec['token']);
    proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$slot]);
}
foreach (array_keys($specs) as $slot) {
    check(waitForSignal($dir, 'ready_' . $slot, 30), 'B: worker ' . $slot . ' ready', $failures);
}
writeSignal($dir, 'go', ['at' => microtime(true)]);
$outcomes = [];
foreach (array_keys($specs) as $slot) {
    check(waitForSignal($dir, 'done_' . $slot, 60), 'B: worker ' . $slot . ' finished', $failures);
    $outcomes[$slot] = readSignal($dir, 'done_' . $slot);
    echo 'OUT ' . $slot . '=' . json_encode($outcomes[$slot], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
foreach ($pipes as $slot => $handles) {
    if (is_resource($handles[1] ?? null)) {
        fclose($handles[1]);
    }
    if (is_resource($handles[2] ?? null)) {
        fclose($handles[2]);
    }
}
foreach (glob($dir . '/*') ?: [] as $file) {
    unlink($file);
}
rmdir($dir);

$successes = count(array_filter($outcomes, static fn (array $r): bool => ($r['outcome'] ?? '') === 'success'));
$insufficient = count(array_filter($outcomes, static fn (array $r): bool =>
    ($r['outcome'] ?? '') === 'error'
    && ($r['status'] ?? 0) === 422
    && ($r['code'] ?? '') === 'insufficient_stock'));
check($successes === 1, 'B: exactly one legacy reservation succeeded', $failures);
check($insufficient === 1, 'B: loser got 422 insufficient_stock', $failures);
check(count(array_filter($outcomes, static fn (array $r): bool => ($r['class'] ?? '') === 'PDOException')) === 0,
    'B: conflict is not a raw PDOException', $failures);
check(Decimal::compare($available($levConc), '1.00000') === 0, 'B: A=1.00000', $failures);
check(Decimal::compare($reserved($levConc), '4.00000') === 0, 'B: R=4.00000', $failures);
check(Decimal::compare($physical($levConc), '5.00000') === 0, 'B: P=5.00000 (total preserved)', $failures);
check(Decimal::compare($available($levConc), '0.00000') >= 0, 'B: no negative available balance', $failures);
$assertNoLotRows('B');
check((int) $pdo->query("SELECT COUNT(*) FROM request_activities WHERE request_id IN ($reqConcA,$reqConcB) AND type_code='items'")->fetchColumn() === 1,
    'B: exactly one item activity (no duplicate side effects)', $failures);

// --- C. Two multi-item requests listing the same levels in reverse order ----
$multiDir = sys_get_temp_dir() . '/crm_l264_multi_' . bin2hex(random_bytes(6));
mkdir($multiDir, 0700, true);
$multiSpecs = [
    'A' => ['request' => $reqMultiA, 'token' => $tokenA, 'levels' => [$levMa, $levMb, $levMc]],
    'B' => ['request' => $reqMultiB, 'token' => $tokenB, 'levels' => [$levMc, $levMb, $levMa]],
];
$multiPipes = [];
foreach ($multiSpecs as $slot => $spec) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' --role=l264-multi --dir=' . escapeshellarg($multiDir)
        . ' --slot=' . escapeshellarg($slot)
        . ' --request=' . (int) $spec['request']
        . ' --levels=' . escapeshellarg(implode(',', $spec['levels']))
        . ' --token=' . escapeshellarg($spec['token']);
    proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $multiPipes[$slot]);
}
foreach (array_keys($multiSpecs) as $slot) {
    check(waitForSignal($multiDir, 'multi_ready_' . $slot, 30), 'C: multi worker ' . $slot . ' ready', $failures);
}
writeSignal($multiDir, 'multi_go', ['at' => microtime(true)]);
$multiOutcomes = [];
foreach (array_keys($multiSpecs) as $slot) {
    check(waitForSignal($multiDir, 'multi_done_' . $slot, 60), 'C: multi worker ' . $slot . ' finished', $failures);
    $multiOutcomes[$slot] = readSignal($multiDir, 'multi_done_' . $slot);
}
foreach ($multiPipes as $handles) {
    if (is_resource($handles[1] ?? null)) {
        fclose($handles[1]);
    }
    if (is_resource($handles[2] ?? null)) {
        fclose($handles[2]);
    }
}
foreach (glob($multiDir . '/*') ?: [] as $file) {
    unlink($file);
}
rmdir($multiDir);
check(count(array_filter($multiOutcomes, static fn (array $r): bool => ($r['outcome'] ?? '') === 'success')) === 2,
    'C: both reversed-order multi-item requests succeeded', $failures);
check(count(array_filter($multiOutcomes, static fn (array $r): bool => ($r['class'] ?? '') === 'PDOException')) === 0,
    'C: no raw PDOException in multi-item requests', $failures);
foreach ([$levMa, $levMb, $levMc] as $levelId) {
    check(Decimal::compare($available($levelId), '4.00000') === 0
        && Decimal::compare($reserved($levelId), '6.00000') === 0
        && Decimal::compare($physical($levelId), '10.00000') === 0,
        'C: A=4 R=6 P=10 for level ' . $levelId, $failures);
}
check((int) $pdo->query('SELECT COUNT(*) FROM stock_levels WHERE ID IN (' . implode(',', [$levMa, $levMb, $levMc]) . ') AND QUANTITY < 0')->fetchColumn() === 0,
    'C: no negative available balance', $failures);
$assertNoLotRows('C');
check((int) $pdo->query("SELECT COUNT(*) FROM request_activities WHERE request_id IN ($reqMultiA,$reqMultiB) AND type_code='items'")->fetchColumn() === 2,
    'C: exactly two item activities (no duplicate side effects)', $failures);

/** Run two real child processes against independent connections with a file barrier. */
$runPair = static function (array $specs) use ($dsn, $dbUser, $dbPass): array {
    $dir = sys_get_temp_dir() . '/crm_l264_pair_' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $pipes = [];
    foreach ($specs as $slot => $spec) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . ' --role=' . escapeshellarg((string) $spec['role'])
            . ' --dir=' . escapeshellarg($dir)
            . ' --slot=' . escapeshellarg((string) $slot)
            . ' --request=' . (int) $spec['request']
            . ' --token=' . escapeshellarg((string) $spec['token']);
        if (isset($spec['level'])) {
            $cmd .= ' --level=' . (int) $spec['level'];
        }
        if (isset($spec['qty'])) {
            $cmd .= ' --qty=' . escapeshellarg((string) $spec['qty']);
        }
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $handles);
        $pipes[$slot] = ['proc' => $process, 'out' => $handles[1] ?? null, 'err' => $handles[2] ?? null];
    }
    foreach (array_keys($specs) as $slot) {
        waitForSignal($dir, 'ready_' . $slot, 30);
    }
    writeSignal($dir, 'go', ['at' => microtime(true)]);
    $outcomes = [];
    foreach (array_keys($specs) as $slot) {
        waitForSignal($dir, 'done_' . $slot, 60);
        $outcomes[$slot] = readSignal($dir, 'done_' . $slot);
    }
    foreach ($pipes as $slot => $handles) {
        $stdout = is_resource($handles['out']) ? (string) stream_get_contents($handles['out']) : '';
        $stderr = is_resource($handles['err']) ? (string) stream_get_contents($handles['err']) : '';
        if (is_resource($handles['out'])) {
            fclose($handles['out']);
        }
        if (is_resource($handles['err'])) {
            fclose($handles['err']);
        }
        $exit = is_resource($handles['proc']) ? proc_close($handles['proc']) : -1;
        $outcomes[$slot]['exit_code'] = $exit;
        $outcomes[$slot]['stdout'] = trim($stdout);
        $outcomes[$slot]['stderr'] = trim($stderr);
        $ready = readSignal($dir, 'ready_' . $slot);
        $outcomes[$slot]['pid'] = $ready['pid'] ?? null;
        $outcomes[$slot]['db_conn'] = $ready['conn'] ?? null;
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);

    return $outcomes;
};

// --- F. Concurrent release (reduce an existing reservation) vs reserve -------
$initF = static fn (int $requestId, int $levelId) => $requestController->items(
    $apiRequest('POST', '/requests/' . $requestId . '/items', [], [
        'items' => [['stock_level_id' => $levelId, 'quantity' => '4.00000', 'name' => 'L264 Legacy Item', 'unit' => 'pcs']],
        'version' => 1,
    ], $tokenA),
    ['id' => (string) $requestId]
);
$initF($reqF1, $levF);
check(Decimal::compare($available($levF), '6.00000') === 0 && Decimal::compare($reserved($levF), '4.00000') === 0,
    'F: initial A=6 R=4', $failures);
$outF = $runPair([
    'A' => ['role' => 'l264-worker', 'request' => $reqF1, 'level' => $levF, 'qty' => '1.00000', 'token' => $tokenA],
    'B' => ['role' => 'l264-worker', 'request' => $reqF2, 'level' => $levF, 'qty' => '4.00000', 'token' => $tokenB],
]);
check(count(array_filter($outF, static fn (array $r): bool => ($r['outcome'] ?? '') === 'success')) === 2,
    'F: both release and reserve succeeded', $failures);
check(count(array_filter($outF, static fn (array $r): bool => ($r['class'] ?? '') === 'PDOException')) === 0,
    'F: no raw PDOException', $failures);
check(Decimal::compare($available($levF), '5.00000') === 0
    && Decimal::compare($reserved($levF), '5.00000') === 0
    && Decimal::compare($physical($levF), '10.00000') === 0,
    'F: A=5 R=5 P=10', $failures);
$assertNoLotRows('F');

// --- G. Concurrent cancel vs reserve -----------------------------------------
$initG = static fn (int $requestId, int $levelId) => $requestController->items(
    $apiRequest('POST', '/requests/' . $requestId . '/items', [], [
        'items' => [['stock_level_id' => $levelId, 'quantity' => '4.00000', 'name' => 'L264 Legacy Item', 'unit' => 'pcs']],
        'version' => 1,
    ], $tokenA),
    ['id' => (string) $requestId]
);
$initG($reqG1, $levG);
check(Decimal::compare($available($levG), '6.00000') === 0 && Decimal::compare($reserved($levG), '4.00000') === 0,
    'G: initial A=6 R=4', $failures);
$outG = $runPair([
    'A' => ['role' => 'l264-cancel', 'request' => $reqG1, 'token' => $tokenA],
    'B' => ['role' => 'l264-worker', 'request' => $reqG2, 'level' => $levG, 'qty' => '4.00000', 'token' => $tokenB],
]);
foreach (['A', 'B'] as $slot) {
    $worker = $outG[$slot] ?? [];
    echo 'DIAG G ' . $slot . ' ' . json_encode([
        'pid' => $worker['pid'] ?? null,
        'db_conn' => $worker['db_conn'] ?? null,
        'exit_code' => $worker['exit_code'] ?? null,
        'outcome' => $worker['outcome'] ?? null,
        'status' => $worker['status'] ?? null,
        'code' => $worker['code'] ?? null,
        'class' => $worker['class'] ?? null,
        'message' => $worker['message'] ?? null,
        'sqlstate' => $worker['sqlstate'] ?? null,
        'driver_code' => $worker['driver_code'] ?? null,
        'response' => $worker['response'] ?? null,
        'stdout' => $worker['stdout'] ?? '',
        'stderr' => $worker['stderr'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
echo 'DIAG G A/R/P ' . json_encode([
    'A' => $available($levG), 'R' => $reserved($levG), 'P' => $physical($levG),
]) . "\n";
echo 'DIAG G status/activity ' . json_encode([
    'canceled_request_status' => $pdo->query("SELECT status_id FROM requests WHERE ID=$reqG1")->fetchColumn(),
    'item_activities' => (int) $pdo->query(
        "SELECT COUNT(*) FROM request_activities WHERE request_id IN ($reqG1,$reqG2) AND type_code='items'"
    )->fetchColumn(),
]) . "\n";
check(count(array_filter($outG, static fn (array $r): bool => ($r['outcome'] ?? '') === 'success')) === 2,
    'G: both cancel and reserve succeeded', $failures);
check(count(array_filter($outG, static fn (array $r): bool => ($r['class'] ?? '') === 'PDOException')) === 0,
    'G: no raw PDOException', $failures);
check(Decimal::compare($available($levG), '6.00000') === 0
    && Decimal::compare($reserved($levG), '4.00000') === 0
    && Decimal::compare($physical($levG), '10.00000') === 0,
    'G: A=6 R=4 P=10', $failures);
$assertNoLotRows('G');

// --- H. ACL: stocks.edit without warehouse A access -> 403 -------------------
$beforeAclAvailable = $available($levAcl);
$beforeAclReserved = $reserved($levAcl);
expectHttp(static fn () => $stocksController->updateItem(
    $apiRequest('PATCH', '/stocks/items/' . $levAcl, [], ['quantity' => '99.00000'], $tokenAcl),
    ['itemId' => (string) $levAcl]
), 403, 'forbidden', 'H: updateItem without warehouse access -> 403', $failures);
check(Decimal::compare($available($levAcl), $beforeAclAvailable) === 0
    && Decimal::compare($reserved($levAcl), $beforeAclReserved) === 0,
    'H: balance and reservation unchanged after 403', $failures);
$assertNoLotRows('H');

// --- D. TRACK_LOTS=1 control: F-08 still applies to the lot mode ------------
$beforeLotAvailable = $available($levLot);
expectHttp(static fn () => $requestController->items(
    $apiRequest('POST', '/requests/' . $reqD . '/items', [], [
        'items' => [[
            'stock_level_id' => $levLot,
            'quantity' => '1',
            'name' => 'L264 Lot Item',
            'unit' => 'pcs',
        ]],
        'version' => 1,
    ], $tokenA),
    ['id' => (string) $reqD]
), 422, 'lot_conversion_required', 'I: lot product without lots requires conversion', $failures);
check(Decimal::compare($available($levLot), $beforeLotAvailable) === 0
    && Decimal::compare($reserved($levLot), '0.00000') === 0,
    'I: lot level balance/reservation unchanged (rollback)', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM stock_lots WHERE STOCK_LEVEL_ID=$levLot")->fetchColumn() === 0,
    'I: no lots created for lot product', $failures);
$assertNoLotRows('I');

$cleanupFixtures();
$pdo->exec('DELETE FROM level_capabilities WHERE level IN (90,91,5)');
$restoreCapability = $pdo->prepare(
    'INSERT IGNORE INTO level_capabilities (level, capability_code) VALUES (?, ?)'
);
foreach ($originalCapabilities as $capability) {
    $restoreCapability->execute([(int) $capability['level'], (string) $capability['capability_code']]);
}
foreach ($original as $key => $value) {
    if ($value !== null) {
        $settings->set($key, $value);
    }
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "stock-legacy-compatibility: OK\n";
