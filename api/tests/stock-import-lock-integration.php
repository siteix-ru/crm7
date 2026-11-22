<?php

declare(strict_types=1);

/**
 * Integration-тест сериализации импорта остатков и контракта HTTP 423.
 *
 * Тест подключается ТОЛЬКО к явно помеченному disposable-стенду:
 * loopback host, database в формате crm_<name>_test и флаг
 * CRM_STOCK_TEST_DISPOSABLE=1. Любая неоднозначная конфигурация → SKIP
 * до создания PDO-соединения и до любых SQL/HTTP-запросов.
 *
 * С Stage 3.4.65 reservation (job/operation/attempt) создаётся ДО GET_LOCK.
 * При отказе 423 она терминализируется в failed через abandonReservation(),
 * а повтор с тем же Idempotency-Key проходит по пути retry() и создаёт
 * новую generation. Это и проверяют assertions ниже.
 */

function skip(string $reason): void
{
    echo 'SKIP: ' . $reason . "\n";
    exit(0);
}

function dsnPart(string $dsn, string $key): string
{
    foreach (explode(';', $dsn) as $segment) {
        [$name, $value] = array_pad(explode('=', $segment, 2), 2, '');

        if (strtolower(trim($name)) === $key) {
            return trim($value);
        }
    }

    return '';
}

$dsn = (string) (getenv('CRM_STOCK_TEST_DSN') ?: '');
$dbUser = (string) (getenv('CRM_STOCK_TEST_USER') ?: '');
$dbPassword = (string) (getenv('CRM_STOCK_TEST_PASS') ?: '');
$base = rtrim((string) (getenv('CRM_STOCK_TEST_BASE') ?: ''), '/');
$token = (string) (getenv('CRM_STOCK_TEST_TOKEN') ?: '');
$disposable = (string) (getenv('CRM_STOCK_TEST_DISPOSABLE') ?: '');

foreach (['CRM_STOCK_TEST_DSN', 'CRM_STOCK_TEST_USER', 'CRM_STOCK_TEST_PASS',
          'CRM_STOCK_TEST_BASE', 'CRM_STOCK_TEST_TOKEN', 'CRM_STOCK_TEST_DISPOSABLE'] as $key) {
    if ((string) (getenv($key) ?: '') === '') {
        skip('не задан ' . $key . ' — подключение не выполняется');
    }
}

if ($disposable !== '1') {
    skip('CRM_STOCK_TEST_DISPOSABLE должен быть 1 — подключение не выполняется');
}

if (!str_starts_with(strtolower($dsn), 'mysql:')) {
    skip('ожидается mysql DSN — подключение не выполняется');
}

$dsnBody = substr($dsn, strpos($dsn, ':') + 1);

$loopback = ['localhost', '127.0.0.1', '::1', '[::1]'];
$dbHost = strtolower(dsnPart($dsnBody, 'host'));
$dbName = dsnPart($dsnBody, 'dbname');
$baseHost = strtolower((string) parse_url($base, PHP_URL_HOST));

if (!in_array($dbHost, $loopback, true)) {
    skip('DB host не loopback (' . $dbHost . ') — подключение не выполняется');
}

if (preg_match('/^crm_[a-z0-9]{1,20}_test$/', $dbName) !== 1) {
    skip('DB name не соответствует disposable-конвенции crm_<name>_test (' . $dbName . ') — подключение не выполняется');
}

if (!in_array($baseHost, $loopback, true)) {
    skip('API base host не loopback (' . $baseHost . ') — подключение не выполняется');
}

require __DIR__ . '/../src/autoload.php';

$failures = [];

function check(bool $condition, string $name, array &$failures): void
{
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";

    if (!$condition) {
        $failures[] = $name;
    }
}

function api(string $base, string $token, string $path, string $csvPath, ?string $key = null): array
{
    $boundary = '----crm' . bin2hex(random_bytes(8));
    $data = (string) file_get_contents($csvPath);
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"fixture.csv\"\r\n"
        . "Content-Type: text/csv\r\n\r\n" . $data
        . "\r\n--{$boundary}\r\nContent-Disposition: form-data; name=\"actual_date\"\r\n\r\n2026-01-01\r\n";
    $body .= "--{$boundary}--\r\n";

    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: multipart/form-data; boundary=' . $boundary,
    ];
    if ($key !== null) {
        $headers[] = 'Idempotency-Key: ' . $key;
    }

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 130,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, $raw];
}

function rows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function levels(PDO $pdo): array
{
    return rows($pdo, 'SELECT STOCK, NAME, QUANTITY, ID FROM stock_levels ORDER BY STOCK, NAME, ID');
}

function backup(PDO $pdo): array
{
    // Общая нормализация порядка с levelsProjection(): STOCK → NAME → QUANTITY.
    return levelsProjection(
        rows($pdo, 'SELECT STOCK, NAME, QUANTITY FROM stock_levels_backup ORDER BY STOCK, NAME')
    );
}

function levelsProjection(array $rows): array
{
    $projected = array_map(static fn (array $row): array => [
        'STOCK' => $row['STOCK'] ?? '',
        'NAME' => $row['NAME'] ?? '',
        'QUANTITY' => $row['QUANTITY'] ?? '',
    ], $rows);
    usort($projected, static function (array $a, array $b): int {
        return strcmp((string) $a['STOCK'], (string) $b['STOCK'])
            ?: strcmp((string) $a['NAME'], (string) $b['NAME'])
            ?: strcmp((string) $a['QUANTITY'], (string) $b['QUANTITY']);
    });

    return $projected;
}

function jobs(PDO $pdo): array
{
    return rows($pdo, 'SELECT ID, STATUS FROM stock_import_jobs ORDER BY ID');
}

function maxId(PDO $pdo, string $table): int
{
    $allowed = ['stock_import_operations', 'stock_import_attempts', 'stock_import_jobs'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('unexpected table: ' . $table);
    }

    return (int) $pdo->query('SELECT COALESCE(MAX(ID), 0) FROM ' . $table)->fetchColumn();
}

/** Только строки текущего тестового запроса — без зависимости от фиксированных AUTO_INCREMENT ID. */
function freshRows(PDO $pdo, string $table, int $afterId, string $columns): array
{
    $allowed = [
        'stock_import_operations' => 'ID, user_id, IDEMPOTENCY_TOKEN, STATE, GENERATION, CURRENT_ATTEMPT_ID, completed_at, failed_at',
        'stock_import_attempts' => 'ID, operation_id, attempt_no, generation, JOB_ID, STATE, COMMIT_MARKER, ERROR_CODE',
        'stock_import_jobs' => 'ID, STATUS, ERRORS, ATTEMPT_ID, GENERATION',
    ];
    if (!isset($allowed[$table]) || $allowed[$table] !== $columns) {
        throw new InvalidArgumentException('unexpected projection: ' . $table);
    }

    return rows($pdo, 'SELECT ' . $columns . ' FROM ' . $table . ' WHERE ID > ' . $afterId . ' ORDER BY ID');
}

function usedLock(PDO $pdo): mixed
{
    $rows = $pdo->query("SELECT IS_USED_LOCK('crm7_stocks_import:v1')")->fetchAll(PDO::FETCH_NUM);

    return $rows[0][0] ?? null;
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

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

$fixture = sys_get_temp_dir() . '/crm_lock_fixture.csv';
file_put_contents($fixture, "WH1;IT1;pcs;11\nWH1;IT_UNIQUE;pcs;7\n");

$pdo = new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$levelsBefore = levels($pdo);
$backupBefore = backup($pdo);
$jobsBefore = jobs($pdo);
$mappingsBefore = rows($pdo, 'SELECT source_name, price_type_id FROM price_import_mappings ORDER BY source_name');
$settingsBefore = rows($pdo, 'SELECT `key`, value FROM settings ORDER BY `key`');

$opIdBefore = maxId($pdo, 'stock_import_operations');
$attemptIdBefore = maxId($pdo, 'stock_import_attempts');
$jobIdBefore = maxId($pdo, 'stock_import_jobs');

$key = uuid();

// Внешнее соединение удерживает лок — первый импорт с этим ключом получит 423.
$pdo->query("SELECT GET_LOCK('crm7_stocks_import:v1', 5)")->fetchAll(PDO::FETCH_NUM);

try {
    [$status, $raw] = api($base, $token, '/api/v2/stocks/import', $fixture, $key);
    check($status === 423, 'lock занят → HTTP 423', $failures);
    check(str_contains($raw, 'import_busy'), 'error code import_busy', $failures);

    $decoded = json_decode($raw, true);
    check(is_array($decoded) && ($decoded['ok'] ?? null) === false,
        'HTTP 423 не превращается в успешный ответ', $failures);

    $newOps = freshRows($pdo, 'stock_import_operations', $opIdBefore,
        'ID, user_id, IDEMPOTENCY_TOKEN, STATE, GENERATION, CURRENT_ATTEMPT_ID, completed_at, failed_at');
    $newAttempts = freshRows($pdo, 'stock_import_attempts', $attemptIdBefore,
        'ID, operation_id, attempt_no, generation, JOB_ID, STATE, COMMIT_MARKER, ERROR_CODE');
    $newJobs = freshRows($pdo, 'stock_import_jobs', $jobIdBefore,
        'ID, STATUS, ERRORS, ATTEMPT_ID, GENERATION');

    check(count($newOps) === 1, 'после отказа создана ровно одна operation', $failures);
    check(count($newAttempts) === 1, 'после отказа создан ровно один attempt', $failures);
    check(count($newJobs) === 1, 'после отказа создан ровно один job', $failures);

    $op = $newOps[0] ?? [];
    $attempt = $newAttempts[0] ?? [];
    $job = $newJobs[0] ?? [];

    check(($op['IDEMPOTENCY_TOKEN'] ?? null) === $key, 'operation привязана к ключу текущего запроса', $failures);
    check(($op['STATE'] ?? '') === 'failed', 'operation имеет STATE=failed', $failures);
    check(array_key_exists('failed_at', $op) && $op['failed_at'] !== null, 'operation.failed_at заполнен', $failures);
    check((int) ($op['GENERATION'] ?? 0) === 1, 'operation GENERATION=1', $failures);

    check(($job['STATUS'] ?? '') === 'failed', 'job имеет STATUS=failed', $failures);
    check(str_contains((string) ($job['ERRORS'] ?? ''), 'Импорт не начат'),
        'job содержит сообщение об отказе до начала импорта', $failures);
    check((int) ($job['GENERATION'] ?? 0) === 1, 'job GENERATION=1', $failures);

    check(($attempt['STATE'] ?? '') === 'failed', 'attempt имеет STATE=failed', $failures);
    check(($attempt['ERROR_CODE'] ?? '') === 'import_busy', 'attempt.ERROR_CODE=import_busy', $failures);
    check(array_key_exists('COMMIT_MARKER', $attempt) && $attempt['COMMIT_MARKER'] === null,
        'attempt.COMMIT_MARKER IS NULL', $failures);
    check((int) ($attempt['generation'] ?? 0) === 1, 'attempt generation=1', $failures);

    check((int) ($job['ATTEMPT_ID'] ?? 0) === (int) ($attempt['ID'] ?? -1),
        'job.ATTEMPT_ID соответствует attempt.ID', $failures);
    check((int) ($op['CURRENT_ATTEMPT_ID'] ?? 0) === (int) ($attempt['ID'] ?? -1),
        'operation.CURRENT_ATTEMPT_ID соответствует attempt.ID', $failures);
    check((int) ($attempt['JOB_ID'] ?? 0) === (int) ($job['ID'] ?? -1),
        'attempt.JOB_ID соответствует job.ID', $failures);
    check((int) ($attempt['operation_id'] ?? 0) === (int) ($op['ID'] ?? -1),
        'attempt.operation_id соответствует operation.ID', $failures);

    // Побочные эффекты остаются запрещёнными даже при создании failed job.
    check(levels($pdo) === $levelsBefore, 'после отказа stock_levels не изменены', $failures);
    check(backup($pdo) === $backupBefore, 'после отказа backup не изменён', $failures);
    check(rows($pdo, 'SELECT source_name, price_type_id FROM price_import_mappings ORDER BY source_name') === $mappingsBefore,
        'после отказа price_import_mappings не изменены', $failures);
    check(rows($pdo, 'SELECT `key`, value FROM settings ORDER BY `key`') === $settingsBefore,
        'после отказа settings не изменены', $failures);

    $held = usedLock($pdo);
    check($held !== null && $held !== false, 'лок удерживается внешним соединением', $failures);
} finally {
    // Гарантированно освобождаем лок, удерживаемый этим тестом.
    $pdo->query("SELECT RELEASE_LOCK('crm7_stocks_import:v1')");
}

$heldAfterRelease = usedLock($pdo);
check($heldAfterRelease === null || $heldAfterRelease === false,
    'лок освобождён перед повторным POST', $failures);

// Повтор с тем же ключом и тем же payload — retry-путь Stage 3.4.65.
$preImportLevels = levelsProjection(levels($pdo));
[$status, $raw] = api($base, $token, '/api/v2/stocks/import', $fixture, $key);
check($status === 201, 'повтор с тем же ключом → 201 через retry', $failures);
$retryJson = json_decode($raw, true);
check(is_array($retryJson['data'] ?? null) && ($retryJson['data']['status'] ?? '') === 'done',
    'ответ retry содержит status=done', $failures);
check((int) ($retryJson['data']['job_id'] ?? 0) > 0, 'job_id в ответе retry положителен', $failures);

$retryOps = freshRows($pdo, 'stock_import_operations', $opIdBefore,
    'ID, user_id, IDEMPOTENCY_TOKEN, STATE, GENERATION, CURRENT_ATTEMPT_ID, completed_at, failed_at');
check(count($retryOps) === 1, 'retry не создал вторую operation', $failures);
check((int) ($retryOps[0]['GENERATION'] ?? 0) === 2, 'после retry GENERATION=2', $failures);
check(($retryOps[0]['STATE'] ?? '') === 'committed', 'operation после retry committed', $failures);

$allAttempts = freshRows($pdo, 'stock_import_attempts', $attemptIdBefore,
    'ID, operation_id, attempt_no, generation, JOB_ID, STATE, COMMIT_MARKER, ERROR_CODE');
$allJobs = freshRows($pdo, 'stock_import_jobs', $jobIdBefore,
    'ID, STATUS, ERRORS, ATTEMPT_ID, GENERATION');
check(count($allAttempts) === 2, 'в истории два attempt', $failures);
check(count($allJobs) === 2, 'в истории два job', $failures);

$oldAttempt = null;
$currentAttempt = null;
foreach ($allAttempts as $candidate) {
    if ((int) ($candidate['generation'] ?? 0) === 1) {
        $oldAttempt = $candidate;
    }
    if ((int) ($candidate['generation'] ?? 0) === 2) {
        $currentAttempt = $candidate;
    }
}
check($oldAttempt !== null
    && ($oldAttempt['STATE'] ?? '') === 'aborted'
    && ($oldAttempt['ERROR_CODE'] ?? '') === 'import_busy'
    && (int) ($oldAttempt['generation'] ?? 0) === 1
    && array_key_exists('COMMIT_MARKER', $oldAttempt) && $oldAttempt['COMMIT_MARKER'] === null,
    'предыдущая попытка aborted с import_busy осталась в истории', $failures);
check($currentAttempt !== null && ($currentAttempt['STATE'] ?? '') === 'committed'
    && array_key_exists('COMMIT_MARKER', $currentAttempt) && $currentAttempt['COMMIT_MARKER'] !== null,
    'новая попытка committed с marker', $failures);
check((int) ($retryOps[0]['CURRENT_ATTEMPT_ID'] ?? 0) === (int) ($currentAttempt['ID'] ?? -1),
    'operation указывает на attempt gen2', $failures);

$oldJob = null;
$currentJob = null;
foreach ($allJobs as $candidate) {
    if ((int) ($candidate['GENERATION'] ?? 0) === 1) {
        $oldJob = $candidate;
    }
    if ((int) ($candidate['GENERATION'] ?? 0) === 2) {
        $currentJob = $candidate;
    }
}
check($oldJob !== null && ($oldJob['STATUS'] ?? '') === 'failed', 'job gen1 остался failed', $failures);
check($currentJob !== null && ($currentJob['STATUS'] ?? '') === 'done', 'job gen2 завершён done', $failures);
check((int) ($currentJob['ATTEMPT_ID'] ?? 0) === (int) ($currentAttempt['ID'] ?? -1),
    'job gen2 связан с attempt gen2', $failures);

// Stock/mapping/backup применены ровно один раз, replay не дублирует.
$finalLevels = levels($pdo);
check(levelValue($finalLevels, 'WH1', 'IT1') === '11.00000', 'WH1/IT1 = 11', $failures);
check(levelValue($finalLevels, 'WH1', 'IT_UNIQUE') === '7.00000', 'WH1/IT_UNIQUE = 7', $failures);
check(levelValue($finalLevels, 'WH1', 'IT1') !== 'DUPLICATE', 'нет дублей по WH1/IT1', $failures);
check(backup($pdo) === $preImportLevels, 'backup соответствует остаткам до импорта', $failures);
check(rows($pdo, 'SELECT source_name, price_type_id FROM price_import_mappings ORDER BY source_name') === $mappingsBefore,
    'mapping не применён повторно', $failures);
check(array_filter(jobs($pdo), static fn (array $j): bool => ($j['STATUS'] ?? '') === 'processing') === [],
    'нет stuck processing jobs', $failures);

$held = usedLock($pdo);
check($held === null || $held === false, 'IS_USED_LOCK = NULL после завершения', $failures);

if (is_file($fixture)) {
    unlink($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d\n", count($failures)));
    exit(1);
}

echo "\nВсе integration-проверки пройдены\n";
