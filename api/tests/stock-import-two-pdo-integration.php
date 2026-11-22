<?php

declare(strict_types=1);

/**
 * Живые two-PDO тесты сериализации транзакции импорта остатков (Stage 3.4.74).
 *
 * Два независимых PHP-процесса (A и B) с собственными PDO работают с одной
 * disposable MariaDB. Синхронизация — через файловые signal-файлы в уникальной
 * временной директории, НЕ через sleep как единственное средство.
 *
 * Guard — точная копия Stage 3.4.71, без ослаблений.
 */

function skip(string $reason): void
{
    echo 'SKIP: ' . $reason . "\n";
    exit(0);
}

function guardFail(string $reason): void
{
    fwrite(STDERR, 'GUARD FAIL: ' . $reason . "\n");
    exit(2);
}

function parseDsn(string $dsn): array
{
    if (!str_starts_with(strtolower($dsn), 'mysql:')) {
        guardFail('ожидается mysql: DSN');
    }
    $body = substr($dsn, strpos($dsn, ':') + 1);
    $allowed = ['host', 'port', 'dbname', 'charset'];
    $parsed = [];
    foreach (explode(';', $body) as $segment) {
        if (trim($segment) === '') {
            continue;
        }
        if (!str_contains($segment, '=')) {
            guardFail('DSN-сегмент без значения');
        }
        [$name, $value] = explode('=', $segment, 2);
        $name = strtolower(trim($name));
        if (!in_array($name, $allowed, true)) {
            guardFail('неподдерживаемый DSN-ключ: ' . $name);
        }
        if (array_key_exists($name, $parsed)) {
            guardFail('дублирующийся DSN-ключ: ' . $name);
        }
        $parsed[$name] = trim($value);
    }
    foreach (['host', 'port', 'dbname'] as $required) {
        if (!isset($parsed[$required]) || $parsed[$required] === '') {
            guardFail('в DSN отсутствует ' . $required);
        }
    }

    return ['host' => strtolower($parsed['host']), 'port' => $parsed['port'], 'dbname' => $parsed['dbname']];
}

$env = [];
foreach (['CRM_STOCK_TEST_DSN', 'CRM_STOCK_TEST_USER', 'CRM_STOCK_TEST_PASS',
          'CRM_STOCK_TEST_BASE', 'CRM_STOCK_TEST_TOKEN', 'CRM_STOCK_TEST_TOKEN2',
          'CRM_STOCK_TEST_PORT', 'CRM_STOCK_TEST_SENTINEL', 'CRM_STOCK_TEST_DISPOSABLE'] as $key) {
    $raw = getenv($key);
    $env[$key] = $raw === false ? '' : (string) $raw;
}
foreach (['CRM_STOCK_TEST_DSN', 'CRM_STOCK_TEST_USER', 'CRM_STOCK_TEST_PASS',
          'CRM_STOCK_TEST_BASE', 'CRM_STOCK_TEST_TOKEN', 'CRM_STOCK_TEST_PORT',
          'CRM_STOCK_TEST_SENTINEL', 'CRM_STOCK_TEST_DISPOSABLE'] as $key) {
    if ($env[$key] === '') {
        skip('не задан ' . $key . ' — подключение не выполняется');
    }
}
if ($env['CRM_STOCK_TEST_DISPOSABLE'] !== '1') {
    guardFail('CRM_STOCK_TEST_DISPOSABLE должен быть 1');
}
$parts = parseDsn($env['CRM_STOCK_TEST_DSN']);
$loopback = ['localhost', '127.0.0.1', '::1', '[::1]'];
if (!in_array($parts['host'], $loopback, true)) {
    guardFail('DB host не loopback');
}
if (preg_match('/^crm_[a-z0-9]{1,20}_test$/', $parts['dbname']) !== 1) {
    guardFail('DB name не disposable');
}
$denylist = ['crm_i47_test', 'crm_i66_audit', 'crm_i69_test', 'crm_i69_fresh',
    'crm_i71x_test', 'crm_i72x_test', 'crm_local', 'crm', 'crm7', 'crm_prod', 'crm_production', 'crm_staging', 'crm_dev'];
if (in_array(strtolower($parts['dbname']), $denylist, true)) {
    guardFail('БД в denylist: ' . $parts['dbname']);
}
if (!ctype_digit($parts['port']) || (int) $parts['port'] < 1 || (int) $parts['port'] > 65535
    || !ctype_digit($env['CRM_STOCK_TEST_PORT']) || (int) $env['CRM_STOCK_TEST_PORT'] !== (int) $parts['port']) {
    guardFail('порт DSN некорректный или не совпадает с CRM_STOCK_TEST_PORT');
}
if (!in_array(strtolower((string) parse_url(rtrim($env['CRM_STOCK_TEST_BASE'], '/'), PHP_URL_HOST)), $loopback, true)) {
    guardFail('API base host не loopback');
}
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $env['CRM_STOCK_TEST_SENTINEL'])) {
    guardFail('CRM_STOCK_TEST_SENTINEL должен быть UUID v4');
}

require __DIR__ . '/../src/autoload.php';

use App\Core\Database;
use App\Repositories\PriceRepository;
use App\Repositories\StockImportIdempotencyRepository;
use App\Repositories\StocksRepository;

$failures = [];

function check(bool $condition, string $name, array &$failures): void
{
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures[] = $name;
    }
}

function connect(): PDO
{
    global $env;
    $pdo = new PDO($env['CRM_STOCK_TEST_DSN'], $env['CRM_STOCK_TEST_USER'], $env['CRM_STOCK_TEST_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== parseDsn($env['CRM_STOCK_TEST_DSN'])['dbname']) {
        guardFail('фактическая БД != DSN dbname');
    }
    $row = $pdo->query('SELECT marker FROM crm_disposable_sentinel WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if ($row === false || !isset($row['marker']) || !hash_equals($env['CRM_STOCK_TEST_SENTINEL'], (string) $row['marker'])) {
        guardFail('sentinel отсутствует или не совпадает');
    }
    $prop = new ReflectionProperty(Database::class, 'pdo');
    $prop->setValue(null, $pdo);

    return $pdo;
}

function signalPath(string $dir, string $name): string
{
    return $dir . DIRECTORY_SEPARATOR . $name;
}

function writeSignal(string $dir, string $name, array $data): void
{
    file_put_contents(signalPath($dir, $name), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function readSignal(string $dir, string $name): array
{
    $path = signalPath($dir, $name);
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

/** Ожидание FILE BARRIER — sleep используется только как шаг polling, не как синхронизация. */
function waitForSignal(string $dir, string $name, float $timeoutSec): bool
{
    $deadline = microtime(true) + $timeoutSec;
    $path = signalPath($dir, $name);
    while (microtime(true) < $deadline) {
        clearstatcache(true, $path);
        if (is_file($path)) {
            return true;
        }
        usleep(20000);
    }

    return false;
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function rows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function one(PDO $pdo, string $sql): array
{
    return rows($pdo, $sql)[0] ?? [];
}

function snapshot(PDO $pdo): array
{
    return [
        'ops' => rows($pdo, 'SELECT ID,STATE,GENERATION,CURRENT_ATTEMPT_ID FROM stock_import_operations ORDER BY ID'),
        'attempts' => rows($pdo, 'SELECT ID,operation_id,generation,JOB_ID,STATE,COMMIT_MARKER IS NOT NULL AS marked FROM stock_import_attempts ORDER BY ID'),
        'jobs' => rows($pdo, 'SELECT ID,STATUS,ATTEMPT_ID,GENERATION FROM stock_import_jobs ORDER BY ID'),
        'levels' => rows($pdo, 'SELECT STOCK,NAME,QUANTITY FROM stock_levels ORDER BY STOCK,NAME'),
        'backup' => rows($pdo, 'SELECT STOCK,NAME,QUANTITY FROM stock_levels_backup ORDER BY STOCK,NAME'),
        'mapping' => rows($pdo, 'SELECT source_name,price_type_id FROM price_import_mappings ORDER BY source_name'),
    ];
}

function resetFixtures(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        foreach (['stock_import_attempts', 'stock_import_operations', 'stock_import_jobs',
                  'price_import_mappings', 'nomenclature_prices', 'stock_levels_backup',
                  'stock_levels', 'last_stock_update', 'nomenclature', 'stocks'] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
        $pdo->exec("INSERT INTO stocks (ID,ACTIVE,STATUS,SID,NAME,NAME_1C,LEVEL,IN_REPORTS,SORT) VALUES
            (1,'Y','Y','s1','WH1','WH1','{10},{50},{90},',1,1),(2,'Y','Y','s2','WH2','WH2','{10},{50},{90},',1,2)");
        $pdo->exec("INSERT INTO nomenclature (ID,ACTIVE,SID,NAME,NAME_1C,TYPE,UNIT) VALUES
            (1,'Y','n1','IT1','IT1','product','pcs')");
        $pdo->exec("INSERT INTO stock_levels (ID,ACTIVE,SID,ACTUAL_DATE,STOCK,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES
            (1,'Y','l1','2026-01-01','WH1','s1','IT1','n1','pcs',10),(2,'Y','l2','2026-01-01','WH2','s2','IT2','n2','pcs',20)");
        $pdo->exec("INSERT INTO settings (`key`,value) VALUES ('module.warehouses','1'),('prices.levels','90')
                    ON DUPLICATE KEY UPDATE value=VALUES(value)");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function lockEvidence(PDO $observer): array
{
    $out = [
        'available' => false,
        'waits' => [],
        'trx' => [],
        'trx_available' => false,
        'processlist' => [],
        'processlist_available' => false,
        'correlations' => [],
        'at' => microtime(true),
    ];
    try {
        $waits = rows($observer, 'SELECT requesting_trx_id, blocking_trx_id FROM information_schema.INNODB_LOCK_WAITS LIMIT 10');
        $out['available'] = true;
        $out['waits'] = $waits;
    } catch (Throwable $e) {
        $out['error'] = substr($e->getMessage(), 0, 120);
    }
    try {
        $out['trx'] = rows($observer,
            'SELECT trx_id, trx_mysql_thread_id, trx_state FROM information_schema.INNODB_TRX LIMIT 100');
        $out['trx_available'] = true;
    } catch (Throwable $e) {
        $out['trx_error'] = substr($e->getMessage(), 0, 120);
    }
    try {
        // Исключаем собственное соединение observer, чтобы его SQL не выглядел как ожидающий FOR UPDATE.
        $out['processlist'] = rows($observer,
            "SELECT ID,USER,STATE,LEFT(IFNULL(INFO,''),512) AS info FROM information_schema.PROCESSLIST
              WHERE (INFO LIKE '%FOR UPDATE%' OR STATE LIKE '%lock%')
                AND ID <> CONNECTION_ID() LIMIT 10");
        $out['processlist_available'] = true;
    } catch (Throwable $e) {
        $out['processlist_error'] = substr($e->getMessage(), 0, 120);
    }

    // Связываем trx ID с connection ID через INNODB_TRX.trx_mysql_thread_id.
    $trxById = [];
    foreach ($out['trx'] as $trx) {
        $trxById[(string) ($trx['trx_id'] ?? '')] = $trx;
    }
    foreach ($out['waits'] as $wait) {
        $req = $trxById[(string) ($wait['requesting_trx_id'] ?? '')] ?? [];
        $block = $trxById[(string) ($wait['blocking_trx_id'] ?? '')] ?? [];
        $out['correlations'][] = [
            'requesting_trx_id' => (string) ($wait['requesting_trx_id'] ?? ''),
            'blocking_trx_id' => (string) ($wait['blocking_trx_id'] ?? ''),
            'requesting_conn' => (int) ($req['trx_mysql_thread_id'] ?? 0),
            'blocking_conn' => (int) ($block['trx_mysql_thread_id'] ?? 0),
            'requesting_state' => (string) ($req['trx_state'] ?? ''),
            'blocking_state' => (string) ($block['trx_state'] ?? ''),
        ];
    }

    return $out;
}

// ------------------------------------------------------------------ worker modes
$role = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--role=')) {
        $role = substr($arg, 7);
    }
}

if ($role === 'A' || $role === 'B') {
    $opt = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
            $opt[$m[1]] = $m[2];
        }
    }
    $dir = (string) ($opt['dir'] ?? '');
    $opId = (int) ($opt['op'] ?? 0);
    $attemptId = (int) ($opt['attempt'] ?? 0);
    $userId = (int) ($opt['user'] ?? 1);
    $token = (string) ($opt['token'] ?? '');
    $hash = (string) ($opt['hash'] ?? '');
    $mode = (string) ($opt['mode'] ?? 'commit');
    $pdo = connect();
    $repo = new StockImportIdempotencyRepository();

    if ($role === 'A') {
        $owner = 'worker-a-' . getmypid();
        $pdo->beginTransaction();
        $state = $repo->lockForWorker($pdo, $opId, $attemptId, 1, $owner);
        if ($state === null) {
            writeSignal($dir, 'A_DONE', ['error' => 'lockForWorker returned null']);
            exit(1);
        }
        if ($repo->activate($pdo, $opId, $attemptId, 1, $owner, 60) !== 1) {
            writeSignal($dir, 'A_DONE', ['error' => 'activate returned 0']);
            exit(1);
        }
        // Реальные production-мутации внутри транзакции A.
        $stocks = new StocksRepository();
        $stocks->snapshotLevels();
        $stocks->updateLevelFromImport(1, 'two-pdo-' . getmypid(), '2026-01-01', '11.00000');
        $prices = new PriceRepository();
        $prices->saveImportMapping('twopdo', 1);
        writeSignal($dir, 'A_READY', [
            'pid' => getmypid(), 'conn' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
            'time' => microtime(true), 'state' => 'after_activate',
        ]);
        if (!waitForSignal($dir, 'GO', 30)) {
            writeSignal($dir, 'A_DONE', ['error' => 'GO timeout']);
            exit(1);
        }
        if ($mode === 'rollback') {
            $pdo->rollBack();
            $outcome = 'rolled_back';
        } else {
            // Полный production-путь: marker/response фиксируются до COMMIT.
            $repo->markCommitted($pdo, $opId, $attemptId, 1, '{"status":"done"}');
            $pdo->commit();
            $outcome = 'committed';
        }
        writeSignal($dir, 'A_DONE', [
            'pid' => getmypid(), 'conn' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
            'time' => microtime(true), 'outcome' => $outcome,
        ]);
        exit(0);
    }

    // Worker B
    if (!waitForSignal($dir, 'A_READY', 30)) {
        writeSignal($dir, 'B_DONE', ['error' => 'A_READY timeout']);
        exit(1);
    }
    // Connection ID B фиксируется ДО блокирующего SELECT ... FOR UPDATE внутри retry().
    writeSignal($dir, 'B_START', [
        'pid' => getmypid(),
        'conn' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
        'time' => microtime(true),
    ]);
    $start = microtime(true);
    $result = $repo->retry($userId, $token, $hash, 'f.csv', '2026-01-01');
    $end = microtime(true);
    writeSignal($dir, 'B_DONE', [
        'pid' => getmypid(), 'conn' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
        'start' => $start, 'end' => $end, 'duration' => round($end - $start, 3),
        'result' => $result === null ? null : [
            'generation' => (int) ($result['operation']['GENERATION'] ?? 0),
            'attempt_id' => (int) ($result['attempt']['ID'] ?? 0),
        ],
    ]);
    exit(0);
}

// ------------------------------------------------------------------ coordinator
echo "TARGET DB={$parts['dbname']} HOST={$parts['host']} PORT={$parts['port']}\n";

$pdo = connect();
echo 'DB VERSION=' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";

function runRace(PDO $coordinator, string $mode, array &$failures): array
{
    $dir = sys_get_temp_dir() . '/crm_i74_' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    resetFixtures($coordinator);

    $uid = 1;
    $token = uuid();
    $hash = hash('sha256', 'two-pdo-' . $mode);
    $repo = new StockImportIdempotencyRepository();
    $reservation = $repo->reserve($uid, $token, $hash, 'f.csv', '2026-01-01');
    $opId = (int) $reservation['operation']['ID'];
    $attemptId = (int) $reservation['attempt']['ID'];

    $common = '--dir=' . escapeshellarg($dir) . ' --op=' . $opId . ' --attempt=' . $attemptId
        . ' --user=' . $uid . ' --token=' . escapeshellarg($token) . ' --hash=' . escapeshellarg($hash);
    $cmdA = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . " --role=A --mode={$mode} " . $common;
    $cmdB = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --role=B ' . $common;

    $pipes = [];
    $procA = proc_open($cmdA, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesA);
    $procB = null;
    $bReady = waitForSignal($dir, 'A_READY', 20);
    check($bReady, "$mode: worker A достиг after_activate", $failures);
    if ($bReady) {
        $procB = proc_open($cmdB, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesB);
    }

    // Наблюдатель фиксирует lock evidence, пока B ждёт.
    $evidence = [];
    $deadline = microtime(true) + 12;
    while (microtime(true) < $deadline) {
        if (is_file(signalPath($dir, 'B_DONE'))) {
            break;
        }
        $snap = lockEvidence($coordinator);
        if (($snap['available'] ?? false) && $snap['waits'] !== []) {
            $evidence[] = $snap;
        }
        usleep(120000);
    }

    writeSignal($dir, 'GO', ['at' => microtime(true)]);
    $aDone = waitForSignal($dir, 'A_DONE', 20);
    $bDone = waitForSignal($dir, 'B_DONE', 20);
    check($aDone, "$mode: worker A завершён", $failures);
    check($bDone, "$mode: worker B завершён", $failures);

    $a = readSignal($dir, 'A_DONE');
    $b = readSignal($dir, 'B_DONE');
    $aReady = readSignal($dir, 'A_READY');

    if (is_resource($procA)) {
        proc_close($procA);
    }
    if (is_resource($procB)) {
        proc_close($procB);
    }

    return [
        'dir' => $dir, 'op_id' => $opId, 'attempt_id' => $attemptId,
        'token' => $token, 'hash' => $hash,
        'a' => $a, 'b' => $b, 'a_ready' => $aReady,
        'b_start' => readSignal($dir, 'B_START'),
        'evidence_count' => count($evidence),
        'evidence_all' => $evidence,
        'evidence_last' => $evidence[count($evidence) - 1] ?? [],
    ];
}

// ---------------------------------------------------------------- Race 2: COMMIT
echo "\n=== Race 2: COMMIT A ===\n";
$r = runRace($pdo, 'commit', $failures);
$lockWait = (float) ($r['b']['duration'] ?? 0);
check(($r['a']['outcome'] ?? '') === 'committed', 'commit: A зафиксировал транзакцию', $failures);
check($lockWait >= 0.2, 'commit: B реально ожидал (длительность ' . $lockWait . 's)', $failures);
check(array_key_exists('result', $r['b']) && $r['b']['result'] === null,
    'commit: retry вернул null для committed operation', $failures);
check($r['evidence_count'] > 0, 'commit: собрано lock evidence (snapshots=' . $r['evidence_count'] . ')', $failures);
$opAfter = one($pdo, 'SELECT STATE,GENERATION,CURRENT_ATTEMPT_ID FROM stock_import_operations WHERE ID=' . $r['op_id']);
check(($opAfter['STATE'] ?? '') === 'committed' && (int) ($opAfter['GENERATION'] ?? 0) === 1,
    'commit: operation осталась committed gen1', $failures);
check(count(rows($pdo, 'SELECT ID FROM stock_import_attempts WHERE operation_id=' . $r['op_id'])) === 1,
    'commit: новый attempt не создан', $failures);
check((float) one($pdo, "SELECT QUANTITY FROM stock_levels WHERE ID=1")['QUANTITY'] === 11.0,
    'commit: stock применён один раз', $failures);
check(count(rows($pdo, 'SELECT source_name FROM price_import_mappings')) === 1,
    'commit: mapping записан один раз', $failures);
echo 'A pid/conn=' . ($r['a_ready']['pid'] ?? '?') . '/' . ($r['a_ready']['conn'] ?? '?')
    . ' B pid/conn=' . ($r['b']['pid'] ?? '?') . '/' . ($r['b']['conn'] ?? '?') . "\n";
echo 'LOCK EVIDENCE (last snapshot)=' . json_encode($r['evidence_last'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

// ---------------------------------------------------------------- Race 3: recover/retry after COMMIT, before finishJob
echo "\n=== Race 3: recover/retry после COMMIT до finishJob ===\n";
$repo = new StockImportIdempotencyRepository();
$rec = $repo->recover($r['op_id']);
check(($rec['action'] ?? '') === 'noop',
    'race3: committed operation → recovery noop (гонки нет)', $failures);
// Поздний finishJob старого worker обновляет ТОЛЬКО свой job gen1 — это легитимно.
// Новой generation не существует: committed operation не может быть retry.
$job1Race3 = (int) one($pdo, 'SELECT JOB_ID FROM stock_import_attempts WHERE ID=' . $r['attempt_id'])['JOB_ID'];
$lateFinish = $repo->finishJob($job1Race3, $r['attempt_id'], 1, 'done', 1, 1, 0, []);
check($lateFinish === 1, 'race3: поздний finishJob обновил только свой job', $failures);
check((one($pdo, 'SELECT STATUS FROM stock_import_jobs WHERE ID=' . $job1Race3)['STATUS'] ?? '') === 'done',
    'race3: job остался согласованным done', $failures);
check(count(rows($pdo, 'SELECT ID FROM stock_import_jobs')) === 1
    && count(rows($pdo, 'SELECT ID FROM stock_import_attempts')) === 1,
    'race3: новые attempt/job не созданы', $failures);
check((one($pdo, 'SELECT STATE FROM stock_import_operations WHERE ID=' . $r['op_id'])['STATE'] ?? '') === 'committed',
    'race3: operation осталась committed', $failures);

// ---------------------------------------------------------------- Race 2b + D: ROLLBACK
echo "\n=== Race 2b/D: ROLLBACK A, ordering ===\n";
$r2 = runRace($pdo, 'rollback', $failures);
check(($r2['a']['outcome'] ?? '') === 'rolled_back', 'rollback: A откатил транзакцию', $failures);
$wait2 = (float) ($r2['b']['duration'] ?? 0);
check($wait2 >= 0.2, 'rollback: B реально ожидал (длительность ' . $wait2 . 's)', $failures);

// Обязательное DB-level доказательство: ALL условия должны выполняться В ОДНОМ snapshot.
$observerOk = ($r2['evidence_last']['available'] ?? false) === true
    && ($r2['evidence_last']['trx_available'] ?? false) === true
    && ($r2['evidence_last']['processlist_available'] ?? false) === true;
check($observerOk, 'rollback: observer доступен (LOCK_WAITS/TRX/PROCESSLIST)', $failures);
check($r2['evidence_count'] > 0, 'rollback: собрано lock evidence (snapshots=' . $r2['evidence_count'] . ')', $failures);

$aConn = (int) ($r2['a_ready']['conn'] ?? 0);
$bConn = (int) ($r2['b_start']['conn'] ?? 0);
check($aConn > 0 && $bConn > 0 && $aConn !== $bConn, 'rollback: разные CONNECTION_ID A/B', $failures);

$matchedSnapshot = null;
$rejections = [];
$releaseTime = (float) ($r2['a']['time'] ?? 0);
foreach ($r2['evidence_all'] as $snap) {
    if (($snap['available'] ?? false) !== true) {
        $rejections[] = 'observer_unavailable';
        continue;
    }
    if (($snap['waits'] ?? []) === []) {
        $rejections[] = 'no_waits';
        continue;
    }
    $correlationOk = false;
    $corrReq = 0;
    $corrBlock = 0;
    $corrState = 'other';
    foreach ($snap['correlations'] ?? [] as $corr) {
        $corrReq = (int) ($corr['requesting_conn'] ?? 0);
        $corrBlock = (int) ($corr['blocking_conn'] ?? 0);
        $rawState = (string) ($corr['requesting_state'] ?? '');
        $corrState = in_array($rawState, ['LOCK WAIT', 'RUNNING', 'WAITING'], true) ? $rawState : 'other';
        if ($corrReq === $bConn && $corrBlock === $aConn && $corrState === 'LOCK WAIT') {
            $correlationOk = true;
            break;
        }
    }
    if (!$correlationOk) {
        $rejections[] = 'correlation_mismatch(req=' . $corrReq . ',block=' . $corrBlock . ',state=' . $corrState . ')';
        continue;
    }
    $processOk = false;
    $procFound = false;
    $procHasSql = false;
    foreach ($snap['processlist'] ?? [] as $proc) {
        if ((int) ($proc['ID'] ?? 0) !== $bConn) {
            continue;
        }
        $procFound = true;
        if (str_contains((string) ($proc['info'] ?? ''), 'FOR UPDATE')) {
            $procHasSql = true;
            $processOk = true;
            break;
        }
    }
    if (!$processOk) {
        $rejections[] = 'processlist_mismatch(row=' . ($procFound ? 'yes' : 'no')
            . ',for_update=' . ($procHasSql ? 'yes' : 'no') . ')';
        continue;
    }
    if ($releaseTime <= 0 || (float) ($snap['at'] ?? 0) >= $releaseTime) {
        $rejections[] = 'snapshot_too_late';
        continue;
    }
    $matchedSnapshot = $snap;
    break;
}
check($matchedSnapshot !== null,
    'rollback: единый snapshot подтверждает, что B ждёт блокировку A', $failures);
if ($matchedSnapshot === null) {
    $counts = array_count_values($rejections);
    ksort($counts);
    echo 'ROLLBACK REJECTIONS=' . json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
echo 'ROLLBACK LOCK EVIDENCE (matched)='
    . json_encode($matchedSnapshot ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

$opAfterRb = one($pdo, 'SELECT STATE,GENERATION FROM stock_import_operations WHERE ID=' . $r2['op_id']);
echo 'B retry result after rollback=' . json_encode($r2['b']['result'] ?? null)
    . ' op=' . json_encode($opAfterRb) . "\n";
// D: точный порядок rollback → markFailedAfterRollback → retry (уже после снятия блокировки).
$repo->markFailedAfterRollback($r2['op_id'], $r2['attempt_id'], 1, 'injected_rollback');
$stateAfterMark = one($pdo, 'SELECT STATE,GENERATION FROM stock_import_operations WHERE ID=' . $r2['op_id']);
check(($stateAfterMark['STATE'] ?? '') === 'failed', 'D: markFailedAfterRollback → failed', $failures);
$repo2 = new StockImportIdempotencyRepository();
$retried = $repo2->retry(1, (string) ($r2['token'] ?? ''), (string) ($r2['hash'] ?? ''), 'f.csv', '2026-01-01');
echo 'D: retry after markFailed=' . json_encode($retried === null ? null : [
    'generation' => $retried['operation']['GENERATION'] ?? null,
]) . "\n";

// ---------------------------------------------------------------- F: control
echo "\n=== F: current worker control ===\n";
resetFixtures($pdo);
$boundary = '----crm' . bin2hex(random_bytes(8));
$body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"f.csv\"\r\n"
    . "Content-Type: text/csv\r\n\r\nWH1;IT1;pcs;11\n"
    . "\r\n--{$boundary}\r\nContent-Disposition: form-data; name=\"actual_date\"\r\n\r\n2026-01-01\r\n--{$boundary}--\r\n";
$ch = curl_init(rtrim($env['CRM_STOCK_TEST_BASE'], '/') . '/api/v2/stocks/import');
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 130,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $env['CRM_STOCK_TEST_TOKEN'],
        'Content-Type: multipart/form-data; boundary=' . $boundary,
        'Idempotency-Key: ' . uuid(),
    ],
]);
curl_exec($ch);
$statusF = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
check($statusF === 201, 'F: успешный POST → 201', $failures);
check(count(rows($pdo, 'SELECT ID FROM stock_import_operations')) === 1, 'F: одна operation', $failures);
check((float) one($pdo, "SELECT QUANTITY FROM stock_levels WHERE ID=1")['QUANTITY'] === 11.0, 'F: stock применён', $failures);
check(count(rows($pdo, 'SELECT ID FROM stock_levels_backup')) === 2, 'F: backup создан', $failures);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "\nВсе two-PDO проверки пройдены\n";
