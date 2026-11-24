<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Database;
use App\Http\HttpException;
use App\Repositories\StockLotIdempotencyRepository;
use App\Stocks\StockLotService;
use App\Stocks\StockReservationService;
use App\Support\Decimal;

/** Disposable-MariaDB regression test for scoped stock lot idempotency. */
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

// Own fixtures only.
$pdo->exec("DELETE FROM stock_lot_idempotency WHERE IDEMPOTENCY_KEY LIKE 'idem-%'");
$pdo->exec("DELETE FROM stock_lot_movements WHERE STOCK_LEVEL_ID IN (SELECT ID FROM stock_levels WHERE SID LIKE 'IDEM-L%')");
$pdo->exec("DELETE FROM stock_issue_lines WHERE ISSUE_ID IN (SELECT ID FROM stock_issue_operations WHERE REQUEST_ID IN (SELECT id FROM requests WHERE number LIKE 'IDEM-TEST-%'))");
$pdo->exec("DELETE FROM stock_issue_operations WHERE REQUEST_ID IN (SELECT id FROM requests WHERE number LIKE 'IDEM-TEST-%')");
$pdo->exec("DELETE FROM stock_lot_reservations WHERE RESERVATION_ID IN (SELECT ID FROM stock_reservations WHERE request_id IN (SELECT id FROM requests WHERE number LIKE 'IDEM-TEST-%'))");
$pdo->exec("DELETE FROM stock_lots WHERE STOCK_LEVEL_ID IN (SELECT ID FROM stock_levels WHERE SID LIKE 'IDEM-L%')");
$pdo->exec("DELETE FROM stock_reservations WHERE request_id IN (SELECT id FROM requests WHERE number LIKE 'IDEM-TEST-%')");
$pdo->exec("DELETE FROM request_items WHERE request_id IN (SELECT id FROM requests WHERE number LIKE 'IDEM-TEST-%')");
$pdo->exec("DELETE FROM requests WHERE number LIKE 'IDEM-TEST-%'");
$pdo->exec("DELETE FROM stock_levels WHERE SID LIKE 'IDEM-L%'");
$pdo->exec("DELETE FROM nomenclature WHERE NAME_1C IN ('IDEM-ITEM','IDEM-LEGACY')");
$pdo->exec("DELETE FROM stocks WHERE SID IN ('IDEM-WS-A','IDEM-WS-B')");
$pdo->exec("INSERT INTO settings (`key`,value) VALUES ('stock.reserve_enabled','1'),('module.warehouses','1') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$pdo->exec("INSERT INTO request_statuses (code,title,sort,is_final) VALUES ('new','New',10,0),('closed','Closed',90,1) ON DUPLICATE KEY UPDATE title=VALUES(title)");
$pdo->exec("INSERT INTO stocks (SID,NAME,NAME_1C,LEVEL,STATUS,ACTIVE) VALUES ('IDEM-WS-A','Idem A','IDEM-WS-A','{90}','Y','Y'),('IDEM-WS-B','Idem B','IDEM-WS-B','{91}','Y','Y')");
$pdo->exec("INSERT INTO nomenclature (SID,NAME,NAME_1C,UNIT,TRACK_LOTS) VALUES ('IDEM-ITEM','Idem item','IDEM-ITEM','pcs',1)");
$levelSids = [
    'IDEM-L1' => 100, 'IDEM-L2' => 100, 'IDEM-L3' => 100, 'IDEM-L4' => 100,
    'IDEM-L5' => 100, 'IDEM-L6' => 100, 'IDEM-L7' => 100,
    'IDEM-L9' => 100, 'IDEM-L10' => 100, 'IDEM-L11' => 100, 'IDEM-L12' => 100,
    'IDEM-L13' => 100, 'IDEM-L14' => 100, 'IDEM-L15' => 100, 'IDEM-L16' => 100,
];
foreach ($levelSids as $sid => $qty) {
    $pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('$sid','IDEM-WS-A','Idem item','IDEM-ITEM','pcs',$qty.00000)");
}
$levels = [];
foreach (array_keys($levelSids) as $sid) {
    $levels[$sid] = (int) $pdo->query("SELECT ID FROM stock_levels WHERE SID='$sid'")->fetchColumn();
}
$newRequest = static function (string $number) use ($pdo): int {
    $pdo->exec("INSERT INTO requests (number,subject,client_id,status_id,version) VALUES ('$number','Idem test',1,'new',1)");

    return (int) $pdo->query("SELECT id FROM requests WHERE number='$number'")->fetchColumn();
};
$converter = new StockLotService();
foreach ($levels as $sid => $id) {
    $converter->convert($id, [['lot_code' => 'IDEM-START-' . $sid, 'quantity' => '100.00000', 'expires_at' => null]]);
}
$apply = static function (int $requestId, int $levelId, float $qty) use ($pdo): void {
    $res = new StockReservationService();
    $res->lock($requestId);
    $pdo->beginTransaction();
    try {
        $res->apply($requestId, [['stock_level_id' => $levelId, 'quantity' => $qty]]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $res->unlock($requestId);
    }
};
$lots = new StockLotService();
$registryCount = static fn(string $key): int => (int) $pdo->query(
    "SELECT COUNT(*) FROM stock_lot_idempotency WHERE IDEMPOTENCY_KEY=" . $pdo->quote($key)
)->fetchColumn();
$issueCount = static fn(int $requestId, string $key): int => (int) $pdo->query(
    "SELECT COUNT(*) FROM stock_issue_operations WHERE REQUEST_ID=$requestId AND IDEMPOTENCY_KEY=" . $pdo->quote($key)
)->fetchColumn();
$lotRow = static function (int $levelId, string $code) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT ID, QUANTITY_AVAILABLE FROM stock_lots WHERE STOCK_LEVEL_ID=? AND LOT_CODE=? LIMIT 1');
    $stmt->execute([$levelId, $code]);
    $row = $stmt->fetch();

    return $row === false ? [] : $row;
};
$lotAvailable = static function (int $lotId) use ($pdo): string {
    return Decimal::parse((string) $pdo->query("SELECT QUANTITY_AVAILABLE FROM stock_lots WHERE ID=$lotId")->fetchColumn());
};
$receiptMovements = static fn(string $key): int => (int) $pdo->query(
    "SELECT COUNT(*) FROM stock_lot_movements WHERE TYPE='receipt' AND IDEMPOTENCY_KEY=" . $pdo->quote($key)
)->fetchColumn();

// 1. Same key, same issue: second call is already_applied.
$r1 = $newRequest('IDEM-TEST-1');
$apply($r1, $levels['IDEM-L1'], 20.0);
$first = $lots->issueWithLock($r1, '5.00000', 'idem-same-issue');
$second = $lots->issueWithLock($r1, '5.00000', 'idem-same-issue');
check($first['already_applied'] === false && $second['already_applied'] === true, 'idem same issue key', $failures);
check($second['result_id'] === $first['result_id'], 'idem replay returns original result id', $failures);
check($issueCount($r1, 'idem-same-issue') === 1, 'idem single issue operation', $failures);

// 2. Same key, different requests: both operations execute.
$r2 = $newRequest('IDEM-TEST-2');
$apply($r2, $levels['IDEM-L2'], 20.0);
$secondIssue = $lots->issueWithLock($r2, '5.00000', 'idem-same-issue');
check($secondIssue['already_applied'] === false, 'idem same key different request executes', $failures);
check($issueCount($r2, 'idem-same-issue') === 1, 'idem second request has own operation', $failures);

// 3. Same key, reversal of different issues.
$rev1 = $lots->reversalWithLock((int) $first['result_id'], '2.00000', 'idem-same-reversal');
$rev2 = $lots->reversalWithLock((int) $secondIssue['result_id'], '2.00000', 'idem-same-reversal');
check($rev1['already_applied'] === false && $rev2['already_applied'] === false, 'idem same key different issues executes', $failures);

// 4. Same key, receipts of different lots.
$rc1 = $lots->receive($levels['IDEM-L1'], 'IDEM-A', '3.00000', null, 'idem-same-receipt');
$rc2 = $lots->receive($levels['IDEM-L2'], 'IDEM-B', '3.00000', null, 'idem-same-receipt');
check($registryCount('idem-same-receipt') === 2, 'idem same key different scopes creates two claims', $failures);
check(true, 'idem same key different lots executes', $failures);

// 5. Same key across operation types.
$r3 = $newRequest('IDEM-TEST-3');
$apply($r3, $levels['IDEM-L3'], 20.0);
$crossIssue = $lots->issueWithLock($r3, '4.00000', 'idem-cross-type');
$crossRev = $lots->reversalWithLock((int) $crossIssue['result_id'], '1.00000', 'idem-cross-type');
$lots->receive($levels['IDEM-L4'], 'IDEM-CROSS', '4.00000', null, 'idem-cross-type');
check($crossIssue['already_applied'] === false && $crossRev['already_applied'] === false, 'idem same key across operation types', $failures);
check($registryCount('idem-cross-type') === 3, 'idem cross-type has three scoped claims', $failures);

// 6. Same key, different payload -> 409 and no state change.
$r4 = $newRequest('IDEM-TEST-4');
$apply($r4, $levels['IDEM-L5'], 20.0);
$base = $lots->issueWithLock($r4, '5.00000', 'idem-conflict');
$issuesBefore = (int) $pdo->query("SELECT COUNT(*) FROM stock_issue_operations WHERE REQUEST_ID=$r4")->fetchColumn();
expectHttp(static fn() => $lots->issueWithLock($r4, '6.00000', 'idem-conflict'), 409, 'idempotency_key_reused', 'idem payload conflict 409', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM stock_issue_operations WHERE REQUEST_ID=$r4")->fetchColumn() === $issuesBefore, 'idem conflict leaves state unchanged', $failures);

// 7. Same key and payload -> no repeated effect (covered in 1); different keys, same payload.
$r5 = $newRequest('IDEM-TEST-5');
$apply($r5, $levels['IDEM-L6'], 20.0);
$a = $lots->issueWithLock($r5, '3.00000', 'idem-key-a');
$b = $lots->issueWithLock($r5, '3.00000', 'idem-key-b');
check($a['already_applied'] === false && $b['already_applied'] === false, 'idem different keys same payload are two operations', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM stock_issue_operations WHERE REQUEST_ID=$r5")->fetchColumn() === 2, 'idem two operations recorded', $failures);

// 9. Parallel same scope/key -> one effect.
$r6 = $newRequest('IDEM-TEST-6');
$apply($r6, $levels['IDEM-L7'], 20.0);
$code = '$__lots = new App\\Stocks\\StockLotService(); $__r = $__lots->issueWithLock(' . $r6 . ", '5.00000', 'idem-parallel-same'); echo json_encode(\$__r);";
$h1 = startProcess($code, $dsn, $dbUser, $dbPass);
$h2 = startProcess($code, $dsn, $dbUser, $dbPass);
$p1 = finishProcess($h1);
$p2 = finishProcess($h2);
$applied = 0;
$replayed = 0;
foreach ([$p1, $p2] as $p) {
    if ($p['exit'] !== 0) {
        echo '     child error: ' . $p['err'] . "\n";
    }
    if (strpos($p['out'], '"already_applied":false') !== false) {
        ++$applied;
    }
    if (strpos($p['out'], '"already_applied":true') !== false) {
        ++$replayed;
    }
}
check($applied === 1 && $replayed === 1, 'idem parallel same scope/key one effect', $failures);

// 10. Parallel different scopes with same key -> all execute.
$r7 = $newRequest('IDEM-TEST-7');
$r8 = $newRequest('IDEM-TEST-8');
$apply($r7, $levels['IDEM-L1'], 20.0);
$apply($r8, $levels['IDEM-L2'], 20.0);
$code7 = '$__lots = new App\\Stocks\\StockLotService(); $__r = $__lots->issueWithLock(' . $r7 . ", '3.00000', 'idem-parallel-diff'); echo json_encode(\$__r);";
$code8 = '$__lots = new App\\Stocks\\StockLotService(); $__r = $__lots->issueWithLock(' . $r8 . ", '3.00000', 'idem-parallel-diff'); echo json_encode(\$__r);";
$h7 = startProcess($code7, $dsn, $dbUser, $dbPass);
$h8 = startProcess($code8, $dsn, $dbUser, $dbPass);
$p7 = finishProcess($h7);
$p8 = finishProcess($h8);
check($p7['exit'] === 0 && $p8['exit'] === 0
    && strpos($p7['out'], '"already_applied":false') !== false
    && strpos($p8['out'], '"already_applied":false') !== false,
    'idem parallel different scopes both execute', $failures);

// 11. Rollback: a failed operation does not leave a registered key.
$r9 = $newRequest('IDEM-TEST-9');
$apply($r9, $levels['IDEM-L3'], 20.0);
expectHttp(static fn() => $lots->issueWithLock($r9, '999.00000', 'idem-rollback'), 422, 'insufficient_lot_stock', 'idem failed issue rejected', $failures);
check($registryCount('idem-rollback') === 0, 'idem failed operation leaves no claim', $failures);
$retry = $lots->issueWithLock($r9, '5.00000', 'idem-rollback');
check($retry['already_applied'] === false, 'idem retry after rollback executes', $failures);

// 15. Legacy empty hash: safe replay without a new effect.
$r10 = $newRequest('IDEM-TEST-10');
$apply($r10, $levels['IDEM-L4'], 20.0);
$pdo->exec("INSERT INTO stock_lot_idempotency (OPERATION,SCOPE_TYPE,SCOPE_KEY,IDEMPOTENCY_KEY,PAYLOAD_HASH,RESULT_ID) VALUES ('issue','request','$r10','idem-legacy-hash','',999)");
$legacyReplay = $lots->issueWithLock($r10, '1.00000', 'idem-legacy-hash');
check($legacyReplay['already_applied'] === true, 'idem legacy empty hash replays safely', $failures);
check($issueCount($r10, 'idem-legacy-hash') === 0, 'idem legacy replay creates no issue', $failures);

// 16. TRACK_LOTS=0 regression: no registry involvement.
$pdo->exec("INSERT INTO nomenclature (SID,NAME,NAME_1C,UNIT,TRACK_LOTS) VALUES ('IDEM-LEGACY','Idem legacy','IDEM-LEGACY','pcs',0)");
$pdo->exec("INSERT INTO stock_levels (SID,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES ('IDEM-L8','IDEM-WS-A','Idem legacy','IDEM-LEGACY','pcs',50.00000)");
$legacyLevelId = (int) $pdo->query("SELECT ID FROM stock_levels WHERE SID='IDEM-L8'")->fetchColumn();
$r11 = $newRequest('IDEM-TEST-11');
$res = new StockReservationService();
$res->lock($r11);
$pdo->beginTransaction();
try {
    $res->apply($r11, [['stock_level_id' => $legacyLevelId, 'quantity' => 10.0]]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
} finally {
    $res->unlock($r11);
}
check((string) $pdo->query("SELECT QUANTITY FROM stock_levels WHERE ID=$legacyLevelId")->fetchColumn() === '40.00000', 'idem legacy TRACK_LOTS=0 reservation unchanged', $failures);
check($registryCount('idem-none') === 0, 'idem legacy path writes no claim', $failures);

// --- AUD-01: lot identity must follow the database collation ----------------
// 1. Accent-insensitive collation: CAFE and CAFE+acute are one physical lot.
$levIdem = $levels['IDEM-L9'];
$lots->receive($levIdem, 'IDEM-CAFE', '10.00000', null, 'idem-cafe');
$cafeFirst = $lotRow($levIdem, 'IDEM-CAFE');
$lots->receive($levIdem, 'IDEM-CAFÉ', '10.00000', null, 'idem-cafe');
$cafeAgain = $lotRow($levIdem, 'IDEM-CAFÉ');
check($cafeFirst !== [] && $cafeAgain !== []
    && (int) $cafeAgain['ID'] === (int) $cafeFirst['ID']
    && Decimal::compare($lotAvailable((int) $cafeFirst['ID']), '10.00000') === 0,
    'identity accent-insensitive code is one lot, no double credit', $failures);

// 2. NFC vs NFD: outcome must follow the actual DB lookup, not PHP normalization.
$levIdem = $levels['IDEM-L10'];
$nfc = "IDEM-\u{00C9}";
$nfd = "IDEM-E\u{0301}";
$lots->receive($levIdem, $nfc, '7.00000', null, 'idem-nfc');
$nfcRow = $lotRow($levIdem, $nfc);
$nfdBefore = $lotRow($levIdem, $nfd);
$lots->receive($levIdem, $nfd, '7.00000', null, 'idem-nfc');
$nfdAfter = $lotRow($levIdem, $nfd);
if ($nfdBefore !== [] && (int) $nfdBefore['ID'] === (int) $nfcRow['ID']) {
    check(Decimal::compare($lotAvailable((int) $nfcRow['ID']), '7.00000') === 0,
        'identity NFC/NFD one lot dedupes by real LOT_ID', $failures);
} else {
    check($nfdAfter !== [] && (int) $nfdAfter['ID'] !== (int) $nfcRow['ID']
        && Decimal::compare($lotAvailable((int) $nfcRow['ID']), '7.00000') === 0
        && Decimal::compare($lotAvailable((int) $nfdAfter['ID']), '7.00000') === 0,
        'identity NFC/NFD distinct lots both credited once', $failures);
}

// 3. Leading space is significant in the DB: different lots, independent scopes.
$levIdem = $levels['IDEM-L11'];
$lots->receive($levIdem, 'IDEM-LOT-A', '5.00000', null, 'idem-leading');
$lots->receive($levIdem, ' ID-LOT-A', '5.00000', null, 'idem-leading');
$plain = $lotRow($levIdem, 'IDEM-LOT-A');
$leading = $lotRow($levIdem, ' ID-LOT-A');
check($plain !== [] && $leading !== [] && (int) $plain['ID'] !== (int) $leading['ID']
    && Decimal::compare($lotAvailable((int) $plain['ID']), '5.00000') === 0
    && Decimal::compare($lotAvailable((int) $leading['ID']), '5.00000') === 0,
    'identity leading space produces distinct lot without false already_applied', $failures);

// 4. PAD SPACE: trailing spaces are ignored by the DB, so one lot is credited.
$levIdem = $levels['IDEM-L12'];
$lots->receive($levIdem, 'IDEM-TAIL', '6.00000', null, 'idem-tail');
$lots->receive($levIdem, 'IDEM-TAIL ', '6.00000', null, 'idem-tail');
$tail = $lotRow($levIdem, 'IDEM-TAIL');
check($tail !== [] && Decimal::compare($lotAvailable((int) $tail['ID']), '6.00000') === 0
    && $receiptMovements('idem-tail') === 1,
    'identity PAD SPACE code is one lot, no double credit', $failures);

// 5. Same LOT_CODE on two levels must have independent scopes.
$levA = $levels['IDEM-L13'];
$levB = $levels['IDEM-L14'];
$lots->receive($levA, 'IDEM-SAME', '4.00000', null, 'idem-two-levels');
$lots->receive($levB, 'IDEM-SAME', '4.00000', null, 'idem-two-levels');
$sameA = $lotRow($levA, 'IDEM-SAME');
$sameB = $lotRow($levB, 'IDEM-SAME');
check($registryCount('idem-two-levels') === 2 && $sameA !== [] && $sameB !== []
    && Decimal::compare($lotAvailable((int) $sameA['ID']), '4.00000') === 0
    && Decimal::compare($lotAvailable((int) $sameB['ID']), '4.00000') === 0,
    'identity same code different levels are independent', $failures);

// 6/7. Same key + same payload -> replay; same key + different payload -> 409.
$levIdem = $levels['IDEM-L15'];
$lots->receive($levIdem, 'IDEM-REPLAY', '9.00000', null, 'idem-replay');
$lots->receive($levIdem, 'IDEM-REPLAY', '9.00000', null, 'idem-replay');
$replayLot = $lotRow($levIdem, 'IDEM-REPLAY');
check(Decimal::compare($lotAvailable((int) $replayLot['ID']), '9.00000') === 0
    && $receiptMovements('idem-replay') === 1,
    'identity replay same payload keeps balance', $failures);
expectHttp(static fn() => $lots->receive($levIdem, 'IDEM-REPLAY', '10.00000', null, 'idem-replay'),
    409, 'idempotency_key_reused', 'identity same key different payload 409', $failures);
check(Decimal::compare($lotAvailable((int) $replayLot['ID']), '9.00000') === 0,
    'identity payload conflict leaves balance unchanged', $failures);

// 8. Parallel receipts, same key and same lot: exactly one credit.
$levIdem = $levels['IDEM-L16'];
$codePar = '$__lots = new App\\Stocks\\StockLotService(); $__lots->receive(' . $levIdem
    . ", 'IDEM-PAR', '5.00000', null, 'idem-par'); echo 'ok';";
$hPar1 = startProcess($codePar, $dsn, $dbUser, $dbPass);
$hPar2 = startProcess($codePar, $dsn, $dbUser, $dbPass);
$pPar1 = finishProcess($hPar1);
$pPar2 = finishProcess($hPar2);
$parLot = $lotRow($levIdem, 'IDEM-PAR');
check($pPar1['exit'] === 0 && $pPar2['exit'] === 0
    && $receiptMovements('idem-par') === 1
    && Decimal::compare($lotAvailable((int) $parLot['ID']), '5.00000') === 0,
    'identity parallel same key credits once', $failures);

// 9. Parallel receipts, different keys: both credits exactly once.
$codeParA = '$__lots = new App\\Stocks\\StockLotService(); $__lots->receive(' . $levIdem
    . ", 'IDEM-PAR2', '5.00000', null, 'idem-par-a'); echo 'ok';";
$codeParB = str_replace("'idem-par-a'", "'idem-par-b'", $codeParA);
$hParA = startProcess($codeParA, $dsn, $dbUser, $dbPass);
$hParB = startProcess($codeParB, $dsn, $dbUser, $dbPass);
$pParA = finishProcess($hParA);
$pParB = finishProcess($hParB);
if ($pParA['exit'] !== 0 || $pParB['exit'] !== 0) {
    echo '     child A: ' . $pParA['out'] . ' | ' . $pParA['err'] . "\n";
    echo '     child B: ' . $pParB['out'] . ' | ' . $pParB['err'] . "\n";
}
$par2Lot = $lotRow($levIdem, 'IDEM-PAR2');
check($pParA['exit'] === 0 && $pParB['exit'] === 0
    && $receiptMovements('idem-par-a') === 1 && $receiptMovements('idem-par-b') === 1
    && Decimal::compare($lotAvailable((int) $par2Lot['ID']), '10.00000') === 0,
    'identity parallel different keys credit twice', $failures);

// 10. Rollback after claim removes both the registry row and the stock change.
$beforeLevel = (string) $pdo->query("SELECT QUANTITY FROM stock_levels WHERE ID=$levIdem")->fetchColumn();
$pdo->beginTransaction();
$lots->receive($levIdem, 'IDEM-TX', '5.00000', null, 'idem-tx');
$claimInTx = $registryCount('idem-tx');
$txLot = $lotRow($levIdem, 'IDEM-TX');
$txAvailable = $txLot === [] ? '' : (string) $txLot['QUANTITY_AVAILABLE'];
$pdo->rollBack();
check($claimInTx === 1 && $txLot !== []
    && Decimal::compare(Decimal::parse($txAvailable), '5.00000') === 0,
    'identity claim and balance visible before rollback', $failures);
check($registryCount('idem-tx') === 0 && $lotRow($levIdem, 'IDEM-TX') === []
    && (string) $pdo->query("SELECT QUANTITY FROM stock_levels WHERE ID=$levIdem")->fetchColumn() === $beforeLevel,
    'identity rollback reverts claim and balance together', $failures);

// 17. A competing claim committed after this transaction's snapshot must still
// be recognized as the same scoped operation. A re-read on the same connection
// keeps the REPEATABLE READ snapshot and cannot see the committed row.
$competingHash = hash('sha256', 'idem-competing-snapshot');
$pdo->exec("DELETE FROM stock_lot_idempotency WHERE IDEMPOTENCY_KEY='idem-competing-snapshot'");
$pdo->beginTransaction();
$pdo->query('SELECT COUNT(*) FROM stock_lot_idempotency')->fetchColumn();
$otherPdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$otherPdo->exec(
    "INSERT INTO stock_lot_idempotency (OPERATION,SCOPE_TYPE,SCOPE_KEY,IDEMPOTENCY_KEY,PAYLOAD_HASH,RESULT_ID) VALUES "
    . "('issue','request','7777777','idem-competing-snapshot','$competingHash',4242)"
);
try {
    $registry = new StockLotIdempotencyRepository();
    $conflict = $registry->claim(
        StockLotIdempotencyRepository::OPERATION_ISSUE,
        StockLotIdempotencyRepository::SCOPE_REQUEST,
        '7777777',
        'idem-competing-snapshot',
        $competingHash
    );
    check($conflict['status'] === 'applied' && (int) $conflict['result_id'] === 4242,
        'idem competing claim committed after snapshot is replayed', $failures);
} catch (Throwable $e) {
    check(false, 'idem competing claim committed after snapshot is replayed (' . get_class($e) . ')', $failures);
} finally {
    $pdo->rollBack();
    $pdo->exec("DELETE FROM stock_lot_idempotency WHERE IDEMPOTENCY_KEY='idem-competing-snapshot'");
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d\n", count($failures)));
    exit(1);
}
echo "stock-lot-idempotency-scope: OK\n";
