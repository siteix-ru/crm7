<?php

declare(strict_types=1);

/**
 * Постоянные тесты generation fencing для Stock Import Idempotency (Stage 3.4.65).
 *
 * Проверяют на реальной MariaDB, что worker старой generation не может повредить
 * результат актуальной попытки. Используется та же disposable-защита, что и в
 * stock-import-idempotency-integration.php (Stage 3.4.71): внешний sentinel,
 * строгий DSN whitelist, denylist, SELECT DATABASE() и проверка marker.
 *
 * Требуемые env — идентичны stock-import-idempotency-integration.php.
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
            guardFail('DSN-сегмент без значения: ' . $segment);
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

$dsn = $env['CRM_STOCK_TEST_DSN'];
$base = rtrim($env['CRM_STOCK_TEST_BASE'], '/');
$token = $env['CRM_STOCK_TEST_TOKEN'];
$sentinel = $env['CRM_STOCK_TEST_SENTINEL'];
$parts = parseDsn($dsn);
$loopback = ['localhost', '127.0.0.1', '::1', '[::1]'];
if (!in_array($parts['host'], $loopback, true)) {
    guardFail('DB host не loopback');
}
if (preg_match('/^crm_[a-z0-9]{1,20}_test$/', $parts['dbname']) !== 1) {
    guardFail('DB name не disposable');
}
$denylist = ['crm_i47_test', 'crm_i66_audit', 'crm_i69_test', 'crm_i69_fresh',
    'crm_i71x_test', 'crm_local', 'crm', 'crm7', 'crm_prod', 'crm_production', 'crm_staging', 'crm_dev'];
if (in_array(strtolower($parts['dbname']), $denylist, true)) {
    guardFail('БД в denylist: ' . $parts['dbname']);
}
if (!ctype_digit($parts['port']) || (int) $parts['port'] < 1 || (int) $parts['port'] > 65535
    || !ctype_digit($env['CRM_STOCK_TEST_PORT']) || (int) $env['CRM_STOCK_TEST_PORT'] !== (int) $parts['port']) {
    guardFail('порт DSN некорректный или не совпадает с CRM_STOCK_TEST_PORT');
}
if (!in_array(strtolower((string) parse_url($base, PHP_URL_HOST)), $loopback, true)) {
    guardFail('API base host не loopback');
}
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sentinel) !== 1) {
    guardFail('CRM_STOCK_TEST_SENTINEL должен быть UUID v4');
}

require __DIR__ . '/../src/autoload.php';

use App\Core\Database;
use App\Repositories\StockImportIdempotencyRepository;

$failures = [];

function check(bool $condition, string $name, array &$failures): void
{
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures[] = $name;
    }
}

function scenario(string $name): void
{
    echo "\n=== $name ===\n";
}

/** Подключение и верификация sentinel — до любых изменений данных. */
$pdo = new PDO($dsn, $env['CRM_STOCK_TEST_USER'], $env['CRM_STOCK_TEST_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $parts['dbname']) {
    guardFail('фактическая БД != DSN dbname');
}
$sentinelRow = $pdo->query('SELECT marker FROM crm_disposable_sentinel WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
if ($sentinelRow === false || !isset($sentinelRow['marker']) || !hash_equals($sentinel, (string) $sentinelRow['marker'])) {
    guardFail('sentinel отсутствует или не совпадает — БД не disposable');
}

$dbPdoProperty = new ReflectionProperty(Database::class, 'pdo');
$dbPdoProperty->setValue(null, $pdo);
$repo = new StockImportIdempotencyRepository();

echo "TARGET DB={$parts['dbname']} HOST={$parts['host']} SENTINEL=verified\n";

function rows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function one(PDO $pdo, string $sql): array
{
    return rows($pdo, $sql)[0] ?? [];
}

function levels(PDO $pdo): array
{
    return rows($pdo, 'SELECT STOCK, NAME, QUANTITY FROM stock_levels ORDER BY STOCK, NAME');
}

function backup(PDO $pdo): array
{
    return rows($pdo, 'SELECT STOCK, NAME, QUANTITY FROM stock_levels_backup ORDER BY STOCK, NAME');
}

function mappings(PDO $pdo): array
{
    return rows($pdo, 'SELECT source_name, price_type_id FROM price_import_mappings ORDER BY source_name');
}

/** Критическое состояние: только сущности текущей generation + низменные данные. */
function criticalState(PDO $pdo, int $operationId, int $currentAttemptId, int $currentJobId): array
{
    return [
        'operation' => one($pdo, 'SELECT ID,STATE,GENERATION,CURRENT_ATTEMPT_ID,RESPONSE_JSON,completed_at,failed_at
                                    FROM stock_import_operations WHERE ID=' . $operationId),
        'attempt' => one($pdo, 'SELECT ID,operation_id,generation,JOB_ID,STATE,COMMIT_MARKER,RESPONSE_JSON
                                  FROM stock_import_attempts WHERE ID=' . $currentAttemptId),
        'job' => one($pdo, 'SELECT ID,STATUS,ATTEMPT_ID,GENERATION FROM stock_import_jobs WHERE ID=' . $currentJobId),
        'levels' => levels($pdo),
        'backup' => backup($pdo),
        'mapping' => mappings($pdo),
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
            (1,'Y','Y','s1','WH1','WH1','{10},{50},{90},',1,1),
            (2,'Y','Y','s2','WH2','WH2','{10},{50},{90},',1,2),
            (3,'Y','Y','s3','WH3','WH3','{10},{50},{90},',1,3)");
        $pdo->exec("INSERT INTO nomenclature (ID,ACTIVE,SID,NAME,NAME_1C,TYPE,UNIT) VALUES
            (1,'Y','n1','IT1','IT1','product','pcs'),
            (2,'Y','n2','IT2','IT2','product','pcs'),
            (3,'Y','n3','IT3','IT3','product','pcs')");
        $pdo->exec("INSERT INTO stock_levels (ID,ACTIVE,SID,ACTUAL_DATE,STOCK,STOCK_SID,NAME,NAME_SID,UNIT,QUANTITY) VALUES
            (1,'Y','l1','2026-01-01','WH1','s1','IT1','n1','pcs',10),
            (2,'Y','l2','2026-01-01','WH1','s1','IT2','n2','pcs',20),
            (3,'Y','l3','2026-01-01','WH2','s2','IT1','n1','pcs',30),
            (4,'Y','l4','2026-01-01','WH2','s2','IT3','n3','pcs',40),
            (5,'Y','l5','2026-01-01','WH3','s3','IT2','n2','pcs',50)");
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

function postImport(string $base, string $token, string $key): array
{
    $boundary = '----crm' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"fixture.csv\"\r\n"
        . "Content-Type: text/csv\r\n\r\nWH1;IT1;pcs;11\n"
        . "\r\n--{$boundary}\r\nContent-Disposition: form-data; name=\"actual_date\"\r\n\r\n2026-01-01\r\n"
        . "--{$boundary}--\r\n";
    $ch = curl_init($base . '/api/v2/stocks/import');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 130,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Idempotency-Key: ' . $key,
        ],
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [$status, is_array(json_decode($raw, true)) ? json_decode($raw, true) : ['raw' => substr($raw, 0, 200)]];
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

// ------------------------------------------------------------------------------------------------
// Подготовка: generation 1 через реальный POST, затем доказательный rollback (состояние failed),
// затем generation 2 допустимым production-путём retry() внутри того же POST.
// ------------------------------------------------------------------------------------------------
scenario('Setup: generation 1 → failed → retry → generation 2 (production path)');
resetFixtures($pdo);
$key = uuid();
[$s1, $j1] = postImport($base, $token, $key);
check($s1 === 201 && ($j1['data']['status'] ?? '') === 'done', 'setup: POST gen1 → 201/done', $failures);
$opId = (int) $pdo->query('SELECT MAX(ID) FROM stock_import_operations')->fetchColumn();
$oldAttempt = one($pdo, 'SELECT * FROM stock_import_attempts WHERE operation_id=' . $opId . ' ORDER BY ID LIMIT 1');
$oldJobId = (int) ($oldAttempt['JOB_ID'] ?? 0);
$oldAttemptId = (int) ($oldAttempt['ID'] ?? 0);
check($oldAttemptId > 0 && $oldJobId > 0, 'setup: найдены attempt1/job1', $failures);

// Fixture: состояние, которое оставляет markFailedAfterRollback при доказанном rollback.
$pdo->exec("UPDATE stock_import_operations SET STATE='failed', completed_at=NULL, RESPONSE_JSON=NULL WHERE ID=" . $opId);
$pdo->exec("UPDATE stock_import_attempts SET STATE='failed', COMMIT_MARKER=NULL, RESPONSE_JSON=NULL, ERROR_CODE='injected_rollback' WHERE ID=" . $oldAttemptId);
[$s2, $j2] = postImport($base, $token, $key);
check($s2 === 201 && ($j2['data']['status'] ?? '') === 'done', 'setup: retry POST gen2 → 201/done', $failures);
$opNow = one($pdo, 'SELECT * FROM stock_import_operations WHERE ID=' . $opId);
check((int) ($opNow['GENERATION'] ?? 0) === 2, 'setup: GENERATION = 2', $failures);
$currentAttemptId = (int) ($opNow['CURRENT_ATTEMPT_ID'] ?? 0);
$currentJob = one($pdo, 'SELECT * FROM stock_import_jobs WHERE ATTEMPT_ID=' . $currentAttemptId);
$currentJobId = (int) ($currentJob['ID'] ?? 0);
check($currentAttemptId > 0 && $currentAttemptId !== $oldAttemptId && $currentJobId > 0 && $currentJobId !== $oldJobId,
    'setup: новые attempt2/job2 отличны от старых', $failures);
$attempt2 = one($pdo, 'SELECT * FROM stock_import_attempts WHERE ID=' . $currentAttemptId);
check(($attempt2['STATE'] ?? '') === 'committed' && ($attempt2['COMMIT_MARKER'] ?? null) !== null,
    'setup: attempt2 committed с marker', $failures);
check(($currentJob['STATUS'] ?? '') === 'done', 'setup: job2 done', $failures);

// Fixture: привести старые строки в активное состояние, чтобы изолировать generation/attempt
// предикаты (моделируем worker, который не узнал о смене поколения). Текущие сущности не трогаем.
$pdo->exec("UPDATE stock_import_attempts SET STATE='processing' WHERE ID=" . $oldAttemptId);
$pdo->exec("UPDATE stock_import_jobs SET STATUS='processing' WHERE ID=" . $oldJobId);
$baseline = criticalState($pdo, $opId, $currentAttemptId, $currentJobId);

// ------------------------------------------------------------------------------------------------
// A. Stale worker: lockForWorker + activate
// ------------------------------------------------------------------------------------------------
scenario('A: stale lockForWorker / activate');
$staleOwner = 'stale-worker-' . bin2hex(random_bytes(4));
check($repo->lockForWorker($pdo, $opId, $oldAttemptId, 1, $staleOwner) === null,
    'A: lockForWorker(old attempt, gen1) → null', $failures);
check($repo->activate($pdo, $opId, $oldAttemptId, 1, $staleOwner, 30) === 0,
    'A: activate(old attempt, gen1) → 0', $failures);
check(criticalState($pdo, $opId, $currentAttemptId, $currentJobId) === $baseline,
    'A: критическое состояние не изменено', $failures);

// ------------------------------------------------------------------------------------------------
// B. Stale finishJob
// ------------------------------------------------------------------------------------------------
scenario('B: stale finishJob');
$staleJobResult = $repo->finishJob($oldJobId, $oldAttemptId, 1, 'done', 999, 999, 0, ['stale']);
$job1After = one($pdo, 'SELECT STATUS,ROWS_IMPORTED FROM stock_import_jobs WHERE ID=' . $oldJobId);
// job1 – это старый job: его обновление допустимо, но он НЕ должен затрагивать job2.
check($staleJobResult === 1 && ($job1After['STATUS'] ?? '') === 'done', 'B: старый job1 обновлён (допустимо)', $failures);
$fencedJobResult = $repo->finishJob($currentJobId, $oldAttemptId, 1, 'done', 999, 999, 0, ['stale']);
check($fencedJobResult === 0, 'B: stale finishJob не трогает job2 (actual=' . $fencedJobResult . ')', $failures);
check(criticalState($pdo, $opId, $currentAttemptId, $currentJobId) === $baseline,
    'B: operation/attempt2/job2 не изменены', $failures);

// ------------------------------------------------------------------------------------------------
// C. Stale markFailed
// ------------------------------------------------------------------------------------------------
scenario('C: stale markFailed');
$pdo->beginTransaction();
$repo->markFailed($pdo, $opId, $oldAttemptId, 1, 'stale_fail');
$pdo->commit();
check(criticalState($pdo, $opId, $currentAttemptId, $currentJobId) === $baseline,
    'C: operation gen2/attempt2/job2 не переведены в failed', $failures);

// ------------------------------------------------------------------------------------------------
// D. Stale marker write
// ------------------------------------------------------------------------------------------------
scenario('D: stale markCommitted');
// Возвращаем старой attempt активное состояние, чтобы блокирующим был именно
// generation/attempt предикат, а не терминальное состояние attempt.
$pdo->exec("UPDATE stock_import_attempts SET STATE='processing', COMMIT_MARKER=NULL WHERE ID=" . $oldAttemptId);
$pdo->beginTransaction();
$staleJson = json_encode(['status' => 'done', 'job_id' => $oldJobId, 'stale' => true]);
$markResult = $repo->markCommitted($pdo, $opId, $oldAttemptId, 1, (string) $staleJson);
$pdo->rollBack(); // как в StocksService: false → исключение → rollBack
check($markResult === false, 'D: markCommitted(old attempt, gen1) → false', $failures);
$attempt2After = one($pdo, 'SELECT STATE,COMMIT_MARKER,RESPONSE_JSON FROM stock_import_attempts WHERE ID=' . $currentAttemptId);
check(($attempt2After['COMMIT_MARKER'] ?? null) !== null, 'D: marker attempt2 сохранён', $failures);
check(($attempt2After['STATE'] ?? '') === 'committed', 'D: attempt2 остался committed', $failures);
check(($attempt2After['RESPONSE_JSON'] ?? '') !== $staleJson, 'D: RESPONSE_JSON не подменён', $failures);
check(criticalState($pdo, $opId, $currentAttemptId, $currentJobId) === $baseline,
    'D: критическое состояние не изменено', $failures);

// ------------------------------------------------------------------------------------------------
// E. Stale stock/mapping mutation
// ------------------------------------------------------------------------------------------------
scenario('E: stale stock/mapping mutation');
echo "NOT TESTABLE: в production нет публичного seam для вызова мутаций stock/mapping с\n"
    . "произвольными attempt/generation — мутации находятся внутри StocksService::import()\n"
    . "после успешной activate(). Искусственный SQL не используется. Косвенное доказательство:\n"
    . "после всех stale-действий stock/mapping/settings/backup неизменны (assertions A–D).\n";

// ------------------------------------------------------------------------------------------------
// F. Current worker control
// ------------------------------------------------------------------------------------------------
scenario('F: current worker control');
[$sReplay, $jReplay] = postImport($base, $token, $key);
check($sReplay === 200 && ($jReplay['data']['replayed'] ?? false) === true, 'F: replay gen2 → 200', $failures);
check(($jReplay['data']['job_id'] ?? 0) === $currentJobId, 'F: replay вернул job2', $failures);
check((int) ($jReplay['data']['operation_id'] ?? 0) === $opId, 'F: replay вернул operation', $failures);
$opFinal = one($pdo, 'SELECT STATE,GENERATION,RESPONSE_JSON FROM stock_import_operations WHERE ID=' . $opId);
check(($opFinal['STATE'] ?? '') === 'committed' && (int) ($opFinal['GENERATION'] ?? 0) === 2,
    'F: operation remains committed gen2', $failures);
check(is_string($opFinal['RESPONSE_JSON'] ?? null) && str_contains((string) $opFinal['RESPONSE_JSON'], '"status":"done"'),
    'F: ответ аккуратно сохранён', $failures);
check(count(rows($pdo, 'SELECT ID FROM stock_levels')) === 5, 'F: ровно 5 stock_levels', $failures);
check(count(backup($pdo)) === 5, 'F: ровно 5 backup-строк', $failures);
check(criticalState($pdo, $opId, $currentAttemptId, $currentJobId) === $baseline,
    'F: replay не изменил критическое состояние', $failures);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d\n", count($failures)));
    exit(1);
}
echo "\nВсе stale-worker fencing-проверки пройдены (E: NOT TESTABLE, задокументировано)\n";
