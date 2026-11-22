<?php

declare(strict_types=1);

/**
 * Постоянные регрессионные тесты идемпотентности импорта остатков (Stage 3.4.65).
 *
 * Тест подключается ТОЛЬКО к явно помеченному disposable-стенду. required env:
 *   CRM_STOCK_TEST_DSN      mysql DSN строго с host/port/dbname/charset
 *   CRM_STOCK_TEST_USER / _PASS
 *   CRM_STOCK_TEST_BASE     loopback base URL API
 *   CRM_STOCK_TEST_TOKEN    bearer-токен пользователя 1
 *   CRM_STOCK_TEST_TOKEN2   bearer-токен пользователя 2 (сценарий C)
 *   CRM_STOCK_TEST_PORT    ожидаемый порт БД (должен совпадать с DSN и @@port)
 *   CRM_STOCK_TEST_SENTINEL уникальный marker, созданный provisioning'ом БД
 *   CRM_STOCK_TEST_DISPOSABLE=1
 *
 * Provisioning disposable БД ОБЯЗАН до запуска теста создать в этой БД таблицу:
 *   CREATE TABLE crm_disposable_sentinel (
 *     id INT PRIMARY KEY,
 *     marker VARCHAR(64) NOT NULL
 *   );
 *   INSERT INTO crm_disposable_sentinel (id, marker) VALUES (1, '<уникальный UUID>');
 * и передать тот же UUID в CRM_STOCK_TEST_SENTINEL. Тест НИКОГДА не создаёт
 * sentinel сам — отсутствие или расхождение marker → GUARD FAIL до любых DELETE.
 *
 * Любая неоднозначная конфигурация → SKIP (не задано) или GUARD FAIL (exit 2,
 * значение задано, но некорректно) ДО создания PDO и до любых SQL/HTTP-запросов.
 */

function skip(string $reason): void
{
    echo 'SKIP: ' . $reason . "\n";
    exit(0);
}

/** Некорректная, но заданная конфигурация: fail closed, never touch DB. */
function guardFail(string $reason): void
{
    fwrite(STDERR, 'GUARD FAIL: ' . $reason . "\n");
    exit(2);
}

/**
 * Разбор DSN с отбрасыванием невалидных/дублирующихся ключей.
 *
 * @return array{host: string, port: string, dbname: string, keys: list<string>}
 */
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

    return [
        'host' => strtolower($parsed['host']),
        'port' => $parsed['port'],
        'dbname' => $parsed['dbname'],
        'keys' => array_keys($parsed),
    ];
}

$env = [];
foreach (['CRM_STOCK_TEST_DSN', 'CRM_STOCK_TEST_USER', 'CRM_STOCK_TEST_PASS',
          'CRM_STOCK_TEST_BASE', 'CRM_STOCK_TEST_TOKEN', 'CRM_STOCK_TEST_TOKEN2',
          'CRM_STOCK_TEST_PORT', 'CRM_STOCK_TEST_SENTINEL', 'CRM_STOCK_TEST_DISPOSABLE'] as $key) {
    $raw = getenv($key);
    $env[$key] = $raw === false ? '' : (string) $raw;
}

// Обязательные для запуска; TOKEN2 — опционален (сценарий C).
foreach (['CRM_STOCK_TEST_DSN', 'CRM_STOCK_TEST_USER', 'CRM_STOCK_TEST_PASS',
          'CRM_STOCK_TEST_BASE', 'CRM_STOCK_TEST_TOKEN',
          'CRM_STOCK_TEST_PORT', 'CRM_STOCK_TEST_SENTINEL', 'CRM_STOCK_TEST_DISPOSABLE'] as $key) {
    if ($env[$key] === '') {
        skip('не задан ' . $key . ' — подключение не выполняется');
    }
}

if ($env['CRM_STOCK_TEST_DISPOSABLE'] !== '1') {
    guardFail('CRM_STOCK_TEST_DISPOSABLE должен быть 1');
}

$dsn = $env['CRM_STOCK_TEST_DSN'];
$dbUser = $env['CRM_STOCK_TEST_USER'];
$dbPassword = $env['CRM_STOCK_TEST_PASS'];
$base = rtrim($env['CRM_STOCK_TEST_BASE'], '/');
$token = $env['CRM_STOCK_TEST_TOKEN'];
$token2 = $env['CRM_STOCK_TEST_TOKEN2'];
$sentinel = $env['CRM_STOCK_TEST_SENTINEL'];
$expectedPort = $env['CRM_STOCK_TEST_PORT'];

$parts = parseDsn($dsn);
$dbHost = $parts['host'];
$dbName = $parts['dbname'];

$loopback = ['localhost', '127.0.0.1', '::1', '[::1]'];
if (!in_array($dbHost, $loopback, true)) {
    guardFail('DB host не loopback (' . $dbHost . ')');
}

if (preg_match('/^crm_[a-z0-9]{1,20}_test$/', $dbName) !== 1) {
    guardFail('DB name не соответствует disposable-конвенции (' . $dbName . ')');
}

// Явный denylist известных БД, которые НЕ должны использоваться этим тестом.
$denylist = [
    'crm_i47_test', 'crm_i66_audit', 'crm_i69_test', 'crm_i69_fresh',
    'crm_local', 'crm', 'crm7', 'crm_prod', 'crm_production', 'crm_staging', 'crm_dev',
];
if (in_array(strtolower($dbName), $denylist, true)) {
    guardFail('БД в denylist: ' . $dbName);
}

if (!ctype_digit($parts['port']) || (int) $parts['port'] < 1 || (int) $parts['port'] > 65535) {
    guardFail('некорректный порт в DSN: ' . $parts['port']);
}
if (!ctype_digit($expectedPort) || (int) $expectedPort !== (int) $parts['port']) {
    guardFail('порт DSN (' . $parts['port'] . ') != CRM_STOCK_TEST_PORT (' . $expectedPort . ')');
}

$baseHost = strtolower((string) parse_url($base, PHP_URL_HOST));
if (!in_array($baseHost, $loopback, true)) {
    guardFail('API base host не loopback (' . $baseHost . ')');
}

if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sentinel) !== 1) {
    guardFail('CRM_STOCK_TEST_SENTINEL должен быть UUID v4');
}

require __DIR__ . '/../src/autoload.php';

use App\Core\Database;
use App\Repositories\StockImportIdempotencyRepository;

$failures = [];
$skipped = [];

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

function noteSkipped(string $name, string $reason, array &$skipped): void
{
    echo 'SKIP ' . $name . ': ' . $reason . "\n";
    $skipped[] = $name;
}

/** Точка подклющения только после всех статических guard-проверок. */
$pdo = new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Верификация ФАКТИЧЕСКИ подключённой БД и sentinel — до любых изменений данных.
$actualDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($actualDb !== $dbName) {
    guardFail('фактическая БД (' . $actualDb . ') != DSN dbname (' . $dbName . ')');
}
// @@port отражает порт внутри MariaDB и при Docker port-mapping не совпадает с
// хост-портом из DSN, поэтому он не используется как идентификатор. Фактическая
// идентичность подтверждается DATABASE() и sentinel (см. ниже), а порт DSN —
// сравнением с CRM_STOCK_TEST_PORT на этапе статического guard.
$actualPort = (string) $pdo->query('SELECT @@port')->fetchColumn();

$sentinelRow = $pdo->query('SELECT marker FROM crm_disposable_sentinel WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
if ($sentinelRow === false || !isset($sentinelRow['marker'])) {
    guardFail('sentinel-таблица отсутствует или пуста — БД не disposable');
}
if (!hash_equals($sentinel, (string) $sentinelRow['marker'])) {
    guardFail('sentinel marker не совпадает — БД не disposable');
}

// Репозиторий использует тот же PDO (singleton), что и этот тест.
$dbPdoProperty = new ReflectionProperty(Database::class, 'pdo');
$dbPdoProperty->setValue(null, $pdo);
$idem = new StockImportIdempotencyRepository();

echo "TARGET DSN=$dsn DB=$actualDb PORT=$actualPort HOST=$dbHost BASE=$base SENTINEL=verified\n";
echo 'TOKEN2=' . ($token2 !== '' ? 'provided' : 'absent') . "\n";

/** Один HTTP-POST импорта. Возвращает [status, decoded JSON]. */
function postImport(string $base, string $token, string $csv, ?string $key, ?string $mapping = null): array
{
    $boundary = '----crm' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"fixture.csv\"\r\n"
        . "Content-Type: text/csv\r\n\r\n" . $csv
        . "\r\n--{$boundary}\r\nContent-Disposition: form-data; name=\"actual_date\"\r\n\r\n2026-01-01\r\n";
    if ($mapping !== null) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"mapping\"\r\n\r\n"
            . $mapping . "\r\n";
    }
    $body .= "--{$boundary}--\r\n";

    return httpPost($base . '/api/v2/stocks/import', $token, $body, $boundary, $key);
}

function httpPost(string $url, string $token, string $body, string $boundary, ?string $key = null): array
{
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: multipart/form-data; boundary=' . $boundary,
    ];
    if ($key !== null) {
        $headers[] = 'Idempotency-Key: ' . $key;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 130,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (string) curl_error($ch);
    $errno = (int) curl_errno($ch);

    return [$status, decode($raw), $error, $errno];
}

function decode(string $raw): array
{
    $json = json_decode($raw, true);

    return is_array($json) ? $json : ['raw' => substr($raw, 0, 300)];
}

/** @return array{0: array, 1: array} */
function postImportParallel(string $base, string $token, string $csv, string $key): array
{
    $mh = curl_multi_init();
    $requests = [];

    for ($i = 0; $i < 2; $i++) {
        $boundary = '----crm' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"fixture.csv\"\r\n"
            . "Content-Type: text/csv\r\n\r\n" . $csv
            . "\r\n--{$boundary}\r\nContent-Disposition: form-data; name=\"actual_date\"\r\n\r\n2026-01-01\r\n"
            . "--{$boundary}--\r\n";

        $ch = curl_init($base . '/api/v2/stocks/import');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Idempotency-Key: ' . $key,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 130,
        ]);
        curl_multi_add_handle($mh, $ch);
        $requests[] = $ch;
    }

    $multiStatus = CURLM_OK;
    do {
        $multiStatus = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $multiStatus === CURLM_OK);

    $result = [];
    foreach ($requests as $ch) {
        $raw = (string) curl_multi_getcontent($ch);
        $result[] = [
            (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            decode($raw),
            (string) curl_error($ch),
            (int) curl_errno($ch),
        ];
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    if ($multiStatus !== CURLM_OK) {
        $failures[] = 'D: curl_multi_exec status ' . $multiStatus;
    }

    return $result;
}

function rows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function levels(PDO $pdo): array
{
    return rows($pdo, 'SELECT STOCK, NAME, QUANTITY, ID FROM stock_levels ORDER BY STOCK, NAME, ID');
}

function levelsProjection(array $rows): array
{
    $projected = array_map(static fn (array $row): array => [
        'STOCK' => (string) ($row['STOCK'] ?? ''),
        'NAME' => (string) ($row['NAME'] ?? ''),
        'QUANTITY' => (string) ($row['QUANTITY'] ?? ''),
    ], $rows);
    usort($projected, static function (array $a, array $b): int {
        return strcmp($a['STOCK'], $b['STOCK']) ?: strcmp($a['NAME'], $b['NAME'])
            ?: strcmp($a['QUANTITY'], $b['QUANTITY']);
    });

    return $projected;
}

function backup(PDO $pdo): array
{
    return levelsProjection(rows($pdo, 'SELECT STOCK, NAME, QUANTITY FROM stock_levels_backup ORDER BY STOCK, NAME'));
}

function ops(PDO $pdo): array
{
    return rows($pdo, 'SELECT ID, user_id, IDEMPOTENCY_TOKEN, STATE, GENERATION, CURRENT_ATTEMPT_ID, completed_at, failed_at, token_cleared_at
                         FROM stock_import_operations ORDER BY ID');
}

function attempts(PDO $pdo): array
{
    return rows($pdo, 'SELECT ID, operation_id, attempt_no, generation, JOB_ID, STATE, COMMIT_MARKER, RESPONSE_JSON
                         FROM stock_import_attempts ORDER BY ID');
}

function jobs(PDO $pdo): array
{
    return rows($pdo, 'SELECT ID, STATUS, ATTEMPT_ID, GENERATION FROM stock_import_jobs ORDER BY ID');
}

function mappings(PDO $pdo): array
{
    return rows($pdo, 'SELECT source_name, price_type_id FROM price_import_mappings ORDER BY source_name');
}

function levelValue(array $rows, string $stock, string $name): ?string
{
    $found = null;
    foreach ($rows as $row) {
        if (($row['STOCK'] ?? '') === $stock && ($row['NAME'] ?? '') === $name) {
            if ($found !== null) {
                return 'DUPLICATE';
            }
            $found = (string) $row['QUANTITY'];
        }
    }

    return $found;
}

/** Валидация формы ответа импорта. */
function validImportData(array $json): bool
{
    if (($json['ok'] ?? null) !== true || !is_array($json['data'] ?? null)) {
        return false;
    }
    $data = $json['data'];

    return ($data['status'] ?? null) === 'done'
        && is_int($data['job_id'] ?? null) && (int) $data['job_id'] > 0
        && is_int($data['rows_imported'] ?? null) && (int) $data['rows_imported'] >= 0;
}

/** Сброс тестовых данных ТОЛЬКО после sentinel/identity-проверки, в транзакции. */
function resetFixtures(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        foreach ([
            'stock_import_attempts', 'stock_import_operations', 'stock_import_jobs',
            'price_import_mappings', 'nomenclature_prices', 'stock_levels_backup',
            'stock_levels', 'last_stock_update', 'nomenclature', 'stocks',
        ] as $table) {
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
        $pdo->exec("INSERT INTO settings (`key`,value) VALUES
            ('module.warehouses','1'),('prices.levels','90')
            ON DUPLICATE KEY UPDATE value=VALUES(value)");

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
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

$csvA = "WH1;IT1;pcs;11\n";
$csvB = "WH1;IT1;pcs;77\n";
$mapping = '{"FOO":1}';

// ---------------------------------------------------------------- A
scenario('A: same key + same payload → replay');
resetFixtures($pdo);
$beforeA = levelsProjection(levels($pdo));
$keyA = uuid();
[$statusA1, $jsonA1] = postImport($base, $token, $csvA, $keyA, $mapping);
[$statusA2, $jsonA2] = postImport($base, $token, $csvA, $keyA, $mapping);
check($statusA1 === 201, 'A: первый POST → 201 (actual=' . $statusA1 . ')', $failures);
check($statusA2 === 200, 'A: повтор → 200 (actual=' . $statusA2 . ')', $failures);
check(validImportData($jsonA1), 'A: корректная форма первого ответа', $failures);
check(validImportData($jsonA2), 'A: корректная форма ответа replay', $failures);
check(($jsonA2['data']['replayed'] ?? false) === true, 'A: повтор помечен replayed', $failures);
$dataA1 = is_array($jsonA1['data'] ?? null) ? $jsonA1['data'] : [];
$dataA2 = is_array($jsonA2['data'] ?? null) ? $jsonA2['data'] : [];
check(is_int($dataA1['job_id'] ?? null) && $dataA1['job_id'] > 0, 'A: job_id первого ответа положителен', $failures);
check(($dataA2['job_id'] ?? null) === ($dataA1['job_id'] ?? null), 'A: replay вернул тот же job_id', $failures);
check(($dataA1['rows_imported'] ?? null) === 1, 'A: первый ответ rows_imported = 1', $failures);
check(($dataA2['rows_imported'] ?? null) === 1, 'A: replay rows_imported = 1', $failures);
$opsA = ops($pdo);
check(count($opsA) === 1, 'A: одна operation', $failures);
check((int) ($dataA2['operation_id'] ?? 0) === (int) ($opsA[0]['ID'] ?? 0), 'A: replay operation_id = исходной', $failures);
check(count(attempts($pdo)) === 1, 'A: один attempt', $failures);
check(count(jobs($pdo)) === 1, 'A: один job', $failures);
check(($opsA[0]['STATE'] ?? '') === 'committed', 'A: operation committed', $failures);
check(levelValue(levels($pdo), 'WH1', 'IT1') === '11.00000', 'A: stock применён один раз', $failures);
check(levelValue(levels($pdo), 'WH1', 'IT1') !== 'DUPLICATE', 'A: нет дублей stock', $failures);
check(backup($pdo) === $beforeA, 'A: backup не дублирован', $failures);
check(mappings($pdo) === [['source_name' => 'foo', 'price_type_id' => 1]], 'A: mapping сохранён один раз', $failures);

// ---------------------------------------------------------------- B
scenario('B: same key + different payload → conflict');
resetFixtures($pdo);
$beforeB = levelsProjection(levels($pdo));
$keyB = uuid();
[$statusB1, $jsonB1] = postImport($base, $token, $csvA, $keyB);
[$statusB2, $jsonB2] = postImport($base, $token, $csvB, $keyB);
check($csvA !== $csvB, 'B: payload действительно различается', $failures);
check($statusB1 === 201 && validImportData($jsonB1), 'B: первый POST → 201', $failures);
check($statusB2 === 409, 'B: конфликт payload → 409 (actual=' . $statusB2 . ')', $failures);
check(($jsonB2['error']['code'] ?? null) === 'idempotency_conflict', 'B: код idempotency_conflict', $failures);
check(($jsonB2['ok'] ?? null) === false && !isset($jsonB2['data']), 'B: конфликт не содержит успешный ответ', $failures);
check(count(ops($pdo)) === 1, 'B: не создана вторая operation', $failures);
check(count(attempts($pdo)) === 1, 'B: не создан второй attempt', $failures);
check(count(jobs($pdo)) === 1, 'B: не создан второй job', $failures);
check(($opsB = ops($pdo)) && ($opsB[0]['GENERATION'] ?? null) === 1, 'B: исходная operation не перезаписана', $failures);
check(levelValue(levels($pdo), 'WH1', 'IT1') === '11.00000', 'B: stock не применён повторно', $failures);
check(backup($pdo) === $beforeB, 'B: backup без повторных записей', $failures);

// ---------------------------------------------------------------- C
scenario('C: user isolation');
if ($token2 === '') {
    noteSkipped('C', 'не задан CRM_STOCK_TEST_TOKEN2 — покрытие неполное', $skipped);
} else {
    resetFixtures($pdo);
    $keyC = uuid();
    [$statusC1, $jsonC1] = postImport($base, $token, $csvA, $keyC);
    [$statusC2, $jsonC2] = postImport($base, $token2, $csvB, $keyC);
    [$statusC1r, $jsonC1r] = postImport($base, $token, $csvA, $keyC);
    [$statusC2r, $jsonC2r] = postImport($base, $token2, $csvB, $keyC);
    check($statusC1 === 201 && validImportData($jsonC1), 'C: пользователь 1 → 201', $failures);
    check($statusC2 === 201 && validImportData($jsonC2), 'C: пользователь 2 → 201', $failures);
    check($statusC1r === 200 && $statusC2r === 200, 'C: оба replay → 200', $failures);
    check(validImportData($jsonC1r) && validImportData($jsonC2r), 'C: оба replay-ответа корректны', $failures);
    $opId1 = (int) ($jsonC1r['data']['operation_id'] ?? 0);
    $opId2 = (int) ($jsonC2r['data']['operation_id'] ?? 0);
    check($opId1 > 0 && $opId2 > 0 && $opId1 !== $opId2, 'C: replay-ответы дают разные operation_id', $failures);
    $opsC = ops($pdo);
    check(count($opsC) === 2, 'C: две операции', $failures);
    check(count(array_unique(array_column($opsC, 'user_id'))) === 2, 'C: разные user_id', $failures);
    check(count(array_filter($opsC, static fn (array $o): bool => (int) $o['ID'] === $opId1)) === 1,
        'C: replay 1 вернул операцию пользователя 1', $failures);
    check(count(array_filter($opsC, static fn (array $o): bool => (int) $o['ID'] === $opId2)) === 1,
        'C: replay 2 вернул операцию пользователя 2', $failures);
    $jobsC = jobs($pdo);
    check(count($jobsC) === 2 && count(array_unique(array_column($jobsC, 'ATTEMPT_ID'))) === 2,
        'C: два разных attempt/job', $failures);
    check(($jsonC1r['data']['rows_imported'] ?? null) === 1 && ($jsonC2r['data']['rows_imported'] ?? null) === 1,
        'C: rows_imported=1 у обоих replay', $failures);
    check(levelValue(levels($pdo), 'WH1', 'IT1') === '77.00000', 'C: итоговый stock от пользователя 2', $failures);
}

// ---------------------------------------------------------------- D
scenario('D: parallel same key (real HTTP clients)');
resetFixtures($pdo);
$beforeD = levelsProjection(levels($pdo));
$keyD = uuid();
$resultsD = postImportParallel($base, $token, $csvA, $keyD);
$codesD = array_column($resultsD, 0);
foreach ($resultsD as $idx => $response) {
    check($response[3] === 0 && $response[2] === '', 'D: клиент ' . ($idx + 1) . ' без curl-ошибки', $failures);
    check($response[0] > 0, 'D: клиент ' . ($idx + 1) . ' получил HTTP статус', $failures);
}
check(in_array(201, $codesD, true), 'D: один клиент создал операцию (201)', $failures);
check(count(array_filter($codesD, static fn ($s): bool => $s === 201)) === 1, 'D: ровно один 201', $failures);
foreach ($resultsD as $idx => [$code, $json]) {
    check(in_array($code, [200, 201, 409], true), 'D: клиент ' . ($idx + 1) . ' код ' . $code, $failures);
    if ($code === 201) {
        check(validImportData($json), 'D: winner — корректный ответ done', $failures);
        check((int) ($json['data']['job_id'] ?? 0) > 0, 'D: winner — положительный job_id', $failures);
        check(($json['data']['rows_imported'] ?? null) === 1, 'D: winner — rows_imported=1', $failures);
    } elseif ($code === 200) {
        check(($json['data']['replayed'] ?? false) === true, 'D: replay-ответ помечен replayed', $failures);
    } elseif ($code === 409) {
        check(in_array($json['error']['code'] ?? null, ['import_in_progress', 'result_unavailable'], true),
            'D: loser — ожидаемый код конфликта', $failures);
        check(!isset($json['data']), 'D: loser — нет успешного payload', $failures);
    }
}
check(count(ops($pdo)) === 1, 'D: одна operation (actual=' . count(ops($pdo)) . ')', $failures);
check(count(attempts($pdo)) === 1, 'D: один attempt', $failures);
check(count(jobs($pdo)) === 1, 'D: один job', $failures);
check(levelValue(levels($pdo), 'WH1', 'IT1') === '11.00000', 'D: stock применён один раз', $failures);
check(levelValue(levels($pdo), 'WH1', 'IT1') !== 'DUPLICATE', 'D: нет дублей stock', $failures);
check(backup($pdo) === $beforeD, 'D: backup один', $failures);
echo 'Parallel codes: ' . implode(',', $codesD) . "\n";

// ---------------------------------------------------------------- E
scenario('E: legacy request without key');
resetFixtures($pdo);
[$statusE1, $jsonE1] = postImport($base, $token, $csvA, null);
[$statusE2, $jsonE2] = postImport($base, $token, $csvA, null);
check($statusE1 === 201 && validImportData($jsonE1), 'E: первый legacy → 201', $failures);
check($statusE2 === 201 && validImportData($jsonE2), 'E: второй legacy → 201', $failures);
check(($jsonE1['data']['replayed'] ?? false) !== true, 'E: первый не replay', $failures);
check(($jsonE2['data']['replayed'] ?? false) !== true, 'E: второй не replay', $failures);
check(count(ops($pdo)) === 2, 'E: две отдельные операции', $failures);
check(count(attempts($pdo)) === 2 && count(jobs($pdo)) === 2, 'E: два attempt/job', $failures);
check(levelValue(levels($pdo), 'WH1', 'IT1') === '11.00000', 'E: stock применён', $failures);
check(levelValue(levels($pdo), 'WH1', 'IT1') !== 'DUPLICATE', 'E: нет дублей stock', $failures);

// ---------------------------------------------------------------- F
scenario('F: integrity summary');
$jobsF = jobs($pdo);
check(array_filter($jobsF, static fn (array $j): bool => ($j['STATUS'] ?? '') === 'processing') === [],
    'F: нет stuck processing jobs', $failures);
check(count(array_filter($jobsF, static fn (array $j): bool => ($j['STATUS'] ?? '') === 'done')) === 2,
    'F: оба legacy job завершены done', $failures);
check(count(levels($pdo)) === 5, 'F: ровно 5 stock_levels', $failures);
check(count(backup($pdo)) === 5, 'F: ровно 5 backup-строк', $failures);

// ---------------------------------------------------------------- G: cleanup
scenario('G: cleanup — terminal-only, history preserved, token reuse');
resetFixtures($pdo);
$beforeG = levelsProjection(levels($pdo));
$keyG = uuid();
[$statusG, $jsonG] = postImport($base, $token, $csvA, $keyG);
check($statusG === 201 && validImportData($jsonG), 'G: импорт → 201', $failures);
$opG = ops($pdo);
check(count($opG) === 1 && ($opG[0]['IDEMPOTENCY_TOKEN'] ?? null) === $keyG, 'G: токен виден до cleanup', $failures);
$opGId = (int) ($opG[0]['ID'] ?? 0);

// Нетерминальная запись не должна очищаться.
$pdo->exec("INSERT INTO stock_import_operations
    (user_id, IDEMPOTENCY_TOKEN, PAYLOAD_HASH, STATE, GENERATION, completed_at)
    VALUES (1, '" . uuid() . "', 'x', 'processing', 1, DATE_SUB(NOW(), INTERVAL 30 HOUR))");
$nonTerminalToken = (string) $pdo->query("SELECT IDEMPOTENCY_TOKEN FROM stock_import_operations WHERE STATE='processing' LIMIT 1")->fetchColumn();

// Имитация истечения TTL только для терминальной операции.
$pdo->exec('UPDATE stock_import_operations SET completed_at=DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE ID=' . $opGId);
$levelsBeforeCleanup = levels($pdo);
$attemptsBeforeCleanup = attempts($pdo);
$jobsBeforeCleanup = jobs($pdo);
$cleared = $idem->cleanup(100, 24);
check($cleared === 1, 'G: cleanup очистил ровно 1 токен (actual=' . $cleared . ')', $failures);
$opGAfter = ops($pdo);
check(count($opGAfter) === 2, 'G: история сохранена (2 строки)', $failures);
$rowG = null;
foreach ($opGAfter as $row) {
    if ((int) $row['ID'] === $opGId) {
        $rowG = $row;
    }
}
check($rowG !== null && array_key_exists('IDEMPOTENCY_TOKEN', $rowG) && $rowG['IDEMPOTENCY_TOKEN'] === null,
    'G: токен обнулён', $failures);
check($rowG !== null && ($rowG['token_cleared_at'] ?? null) !== null, 'G: token_cleared_at проставлен', $failures);
check($rowG !== null && ($rowG['STATE'] ?? '') === 'committed', 'G: состояние не изменено', $failures);
check((string) $pdo->query('SELECT IDEMPOTENCY_TOKEN FROM stock_import_operations WHERE ID=' . $opGId)->fetchColumn() === '',
    'G: SQL подтверждает token=NULL', $failures);
check((string) $pdo->query("SELECT COUNT(*) FROM stock_import_operations WHERE IDEMPOTENCY_TOKEN = '" . $nonTerminalToken . "'")->fetchColumn() === '1',
    'G: нетерминальная запись не очищена', $failures);
check(levels($pdo) === $levelsBeforeCleanup, 'G: stock не изменён cleanup', $failures);
check(attempts($pdo) === $attemptsBeforeCleanup, 'G: attempt не изменён cleanup', $failures);
check(jobs($pdo) === $jobsBeforeCleanup, 'G: job не изменён cleanup', $failures);
check(backup($pdo) === $beforeG, 'G: backup не изменён cleanup', $failures);
[$statusGReuse, $jsonGReuse] = postImport($base, $token, $csvA, $keyG);
check($statusGReuse === 201 && validImportData($jsonGReuse), 'G: повтор после cleanup → 201 (новая операция)', $failures);
check(count(ops($pdo)) === 3, 'G: создана новая операция после cleanup', $failures);

// ---------------------------------------------------------------- H: recovery
scenario('H: recovery — marker / no marker / forceFail / retry');
// H1: marker present → committed, replay безопасен
resetFixtures($pdo);
$beforeH1 = levelsProjection(levels($pdo));
$keyH1 = uuid();
[$sH1, $jH1] = postImport($base, $token, $csvA, $keyH1);
check($sH1 === 201, 'H1: импорт → 201', $failures);
$opH1 = (int) $pdo->query('SELECT MAX(ID) FROM stock_import_operations')->fetchColumn();
$pdo->exec("UPDATE stock_import_operations SET STATE='processing', completed_at=NULL WHERE ID=" . $opH1);
$pdo->exec("UPDATE stock_import_attempts SET STATE='processing' WHERE operation_id=" . $opH1);
$pdo->exec("UPDATE stock_import_jobs SET STATUS='processing' WHERE ID IN (SELECT JOB_ID FROM stock_import_attempts WHERE operation_id=" . $opH1 . ')');
$recH1 = $idem->recover($opH1);
check(($recH1['action'] ?? '') === 'committed', 'H1: recovery с marker → committed', $failures);
check(($recH1['state'] ?? '') === 'done', 'H1: состояние done', $failures);
$levelsH1BeforeRecover = levels($pdo);
[$sH1r, $jH1r] = postImport($base, $token, $csvA, $keyH1);
check($sH1r === 200 && ($jH1r['data']['replayed'] ?? false) === true, 'H1: replay после recovery → 200', $failures);
check(levels($pdo) === $levelsH1BeforeRecover, 'H1: recovery не изменил stock', $failures);
check(backup($pdo) === $beforeH1, 'H1: recovery не применил stock повторно', $failures);

// H2: no marker → unresolved, stock не меняется
resetFixtures($pdo);
$beforeH2 = levels($pdo);
$keyH2 = uuid();
[$sH2] = postImport($base, $token, $csvA, $keyH2);
check($sH2 === 201, 'H2: импорт → 201', $failures);
$opH2 = (int) $pdo->query('SELECT MAX(ID) FROM stock_import_operations')->fetchColumn();
$pdo->exec("UPDATE stock_import_operations SET STATE='processing', completed_at=NULL WHERE ID=" . $opH2);
$pdo->exec("UPDATE stock_import_attempts SET STATE='processing', COMMIT_MARKER=NULL, RESPONSE_JSON=NULL, ERROR_CODE=NULL WHERE operation_id=" . $opH2);
$pdo->exec("UPDATE stock_import_jobs SET STATUS='processing' WHERE ID IN (SELECT JOB_ID FROM stock_import_attempts WHERE operation_id=" . $opH2 . ')');
$levelsH2BeforeRecover = levels($pdo);
$recH2 = $idem->recover($opH2);
check(($recH2['action'] ?? '') === 'unresolved', 'H2: recovery без marker → unresolved', $failures);
check(($recH2['state'] ?? '') === 'unresolved', 'H2: состояние unresolved', $failures);
check(levels($pdo) === $levelsH2BeforeRecover, 'H2: recovery не изменил stock', $failures);
$attemptH2 = attempts($pdo);
check(count($attemptH2) === 1 && ($attemptH2[0]['STATE'] ?? '') === 'aborted', 'H2: attempt aborted', $failures);
$jobH2 = jobs($pdo);
check(count($jobH2) === 1 && ($jobH2[0]['STATUS'] ?? '') === 'unresolved', 'H2: job unresolved', $failures);
$recH2Again = $idem->recover($opH2);
check(($recH2Again['action'] ?? '') === 'noop', 'H2: повторный recovery → noop', $failures);
[$sH2r, $jH2r] = postImport($base, $token, $csvA, $keyH2);
check($sH2r === 409 && ($jH2r['error']['code'] ?? null) === 'result_unavailable', 'H2: replay до forceFail → 409', $failures);

// H3: forceFail → retry с новой generation
check($idem->forceFail($opH2) === true, 'H3: forceFail → true', $failures);
check(($opsH3 = ops($pdo)) && ($opsH3[0]['STATE'] ?? '') === 'failed', 'H3: состояние failed', $failures);
$beforeH3Retry = levelsProjection(levels($pdo));
[$sH3, $jH3] = postImport($base, $token, $csvA, $keyH2);
check($sH3 === 201 && validImportData($jH3), 'H3: retry после forceFail → 201', $failures);
$attemptsH3 = attempts($pdo);
check(count($attemptsH3) === 2, 'H3: два attempt', $failures);
check(($attemptsH3[0]['STATE'] ?? '') === 'aborted', 'H3: старый attempt aborted', $failures);
check(($attemptsH3[1]['STATE'] ?? '') === 'committed' && ($attemptsH3[1]['COMMIT_MARKER'] ?? null) !== null,
    'H3: новый attempt committed с marker', $failures);
$opsH3After = ops($pdo);
check(count($opsH3After) === 1 && (int) ($opsH3After[0]['GENERATION'] ?? 0) === 2, 'H3: generation = 2', $failures);
check(levelValue(levels($pdo), 'WH1', 'IT1') === '11.00000', 'H3: stock применён один раз', $failures);
check(levelValue(levels($pdo), 'WH1', 'IT1') !== 'DUPLICATE', 'H3: нет дублей stock', $failures);
check(backup($pdo) === $beforeH3Retry, 'H3: backup соответствует состоянию перед retry', $failures);
check(count(backup($pdo)) === 5, 'H3: backup без дублей', $failures);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d\n", count($failures)));
    exit(1);
}
if ($skipped !== []) {
    fwrite(STDERR, sprintf("\nВНИМАНИЕ: покрытие неполное, пропущено сценариев: %d (%s)\n",
        count($skipped), implode(', ', $skipped)));
    exit(3);
}
echo "\nВсе idempotency integration-проверки пройдены (skipped: 0)\n";
