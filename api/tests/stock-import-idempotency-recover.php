<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
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

function guardFail(string $message): void
{
    fwrite(STDERR, "GUARD FAIL: " . $message . "\n");
    exit(2);
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

$dsn = (string) (getenv('CRM_STOCK_TEST_DSN') ?: '');
$user = (string) (getenv('CRM_STOCK_TEST_USER') ?: '');
$password = (string) (getenv('CRM_STOCK_TEST_PASS') ?: '');
$port = (string) (getenv('CRM_STOCK_TEST_PORT') ?: '');
$sentinel = (string) (getenv('CRM_STOCK_TEST_SENTINEL') ?: '');
$disposable = (string) (getenv('CRM_STOCK_TEST_DISPOSABLE') ?: '');

if ($dsn === '' || $user === '' || $password === '' || $port === '' || $sentinel === '') {
    guardFail('recover: missing disposable database environment');
}
if ($disposable !== '1') {
    guardFail('recover: CRM_STOCK_TEST_DISPOSABLE must be 1');
}
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sentinel) !== 1) {
    guardFail('recover: CRM_STOCK_TEST_SENTINEL must be UUID v4');
}
if (strpos($dsn, 'mysql:') !== 0) {
    guardFail('recover: DSN must be a mysql DSN');
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
$charset = (string) ($parts['charset'] ?? '');

if (!in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
    guardFail('recover: DB host must be loopback');
}
if (preg_match('/^crm_[a-z0-9]{1,20}_test$/', $dbName) !== 1) {
    guardFail('recover: DB name is not disposable');
}
$denylist = ['crm_i47_test', 'crm_i66_audit', 'crm_i69_test', 'crm_i69_fresh',
    'crm_i71x_test', 'crm_i72x_test', 'crm_local', 'crm', 'crm7', 'crm_prod', 'crm_production', 'crm_staging', 'crm_dev'];
if (in_array(strtolower($dbName), $denylist, true)) {
    guardFail('recover: DB name is in denylist');
}
if (!ctype_digit($dsnPort) || $dsnPort !== $port || $charset !== 'utf8mb4') {
    guardFail('recover: DSN port/charset mismatch');
}

putenv('DB_HOST=' . $host);
putenv('DB_PORT=' . $dsnPort);
putenv('DB_NAME=' . $dbName);
putenv('DB_USER=' . $user);
putenv('DB_PASS=' . $password);
Config::load(__DIR__ . '/../config/.env');

$pdo = Database::pdo();
check((string) $pdo->query('SELECT DATABASE()')->fetchColumn() === $dbName,
    'recover: actual database matches disposable DSN', $failures);
$row = $pdo->query('SELECT marker FROM crm_disposable_sentinel WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
check($row !== false && isset($row['marker']) && hash_equals($sentinel, (string) $row['marker']),
    'recover: disposable sentinel verified', $failures);

$action = (string) ($argv[1] ?? 'recover');
$operationId = (int) ($argv[2] ?? 0);
$repository = new StockImportIdempotencyRepository();

if ($operationId <= 0) {
    // Self-contained check: create a fresh reserved operation, then recover it.
    $reservation = $repository->reserve(1, uuid(), hash('sha256', 'recover-self-test'), 'recover.csv', '2026-01-01');
    $operationId = (int) ($reservation['operation']['ID'] ?? 0);
    check($operationId > 0, 'recover: self-test reservation created', $failures);
}

try {
    $result = $action === 'forcefail'
        ? ['ok' => $repository->forceFail($operationId)]
        : $repository->recover($operationId);
    if ($action === 'forcefail') {
        check(($result['ok'] ?? false) === true, 'recover: forceFail succeeded', $failures);
    } else {
        check(is_array($result) && isset($result['action']) && (string) $result['action'] !== '',
            'recover: recover returned an action', $failures);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $exception) {
    check(false, 'recover: no exception (' . get_class($exception) . ')', $failures);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "ALL OK\n";
