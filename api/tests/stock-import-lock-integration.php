<?php

declare(strict_types=1);

/**
 * Integration-тест сериализации импорта остатков.
 *
 * Тест подключается ТОЛЬКО к явно помеченному disposable-стенду:
 * loopback host, database в формате crm_<name>_test и флаг
 * CRM_STOCK_TEST_DISPOSABLE=1. Любая неоднозначная конфигурация → SKIP
 * до создания PDO-соединения и до любых SQL/HTTP-запросов.
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

function api(string $base, string $token, string $path, string $csvPath): array
{
    $boundary = '----crm' . bin2hex(random_bytes(8));
    $data = (string) file_get_contents($csvPath);
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"fixture.csv\"\r\n"
        . "Content-Type: text/csv\r\n\r\n" . $data
        . "\r\n--{$boundary}\r\nContent-Disposition: form-data; name=\"actual_date\"\r\n\r\n2026-01-01\r\n"
        . "--{$boundary}--\r\n";

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ],
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
    return rows($pdo, 'SELECT STOCK, NAME, QUANTITY FROM stock_levels_backup ORDER BY STOCK, NAME');
}

function jobs(PDO $pdo): array
{
    return rows($pdo, 'SELECT ID, STATUS FROM stock_import_jobs ORDER BY ID');
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

$fixture = sys_get_temp_dir() . '/crm_lock_fixture.csv';
file_put_contents($fixture, "WH1;IT1;pcs;11\nWH1;IT_UNIQUE;pcs;7\n");

$pdo = new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$levelsBefore = levels($pdo);
$backupBefore = backup($pdo);
$jobsBefore = jobs($pdo);

// Внешнее соединение удерживает лок — второй импорт получает 423.
$pdo->exec("SELECT GET_LOCK('crm7_stocks_import:v1', 5)");

[$status, $raw] = api($base, $token, '/api/v2/stocks/import', $fixture);
check($status === 423, 'lock занят → HTTP 423', $failures);
check(str_contains($raw, 'import_busy'), 'error code import_busy', $failures);
check(jobs($pdo) === $jobsBefore, 'после отказа jobs не созданы', $failures);
check(levels($pdo) === $levelsBefore, 'после отказа stock_levels не изменены', $failures);
check(backup($pdo) === $backupBefore, 'после отказа backup не изменён', $failures);

$held = $pdo->query("SELECT IS_USED_LOCK('crm7_stocks_import:v1')")->fetchColumn();
check($held !== null && $held !== false, 'лок удерживается внешним соединением', $failures);

$pdo->exec("SELECT RELEASE_LOCK('crm7_stocks_import:v1')");

$preImportLevels = levels($pdo);
$preImportBackup = backup($pdo);

[$status, $raw] = api($base, $token, '/api/v2/stocks/import', $fixture);
check(in_array($status, [200, 201], true), 'после снятия лока импорт выполняется', $failures);
check(str_contains($raw, '"status":"done"') || str_contains($raw, '"status": "done"'), 'ответ содержит done', $failures);

$finalLevels = levels($pdo);
check(levelValue($finalLevels, 'WH1', 'IT1') === '11.00000', 'WH1/IT1 = 11', $failures);
check(levelValue($finalLevels, 'WH1', 'IT_UNIQUE') === '7.00000', 'WH1/IT_UNIQUE = 7', $failures);
check(levelValue($finalLevels, 'WH1', 'IT1') !== 'DUPLICATE', 'нет дублей по WH1/IT1', $failures);
check(backup($pdo) === $preImportBackup, 'backup соответствует состоянию до импорта', $failures);
check(count(array_filter(jobs($pdo), static fn ($j) => ($j['STATUS'] ?? '') === 'done')) >= 1,
    'есть job со статусом done', $failures);
check(array_filter(jobs($pdo), static fn ($j) => ($j['STATUS'] ?? '') === 'processing') === [],
    'нет stuck processing jobs', $failures);

$held = $pdo->query("SELECT IS_USED_LOCK('crm7_stocks_import:v1')")->fetchColumn();
check($held === null || $held === false, 'IS_USED_LOCK = NULL после завершения', $failures);

@unlink($fixture);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d\n", count($failures)));
    exit(1);
}

echo "\nВсе integration-проверки пройдены\n";
