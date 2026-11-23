<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Database;
use App\Http\HttpException;
use App\Modules\ModuleService;
use App\Repositories\SettingsRepository;
use App\Repositories\StocksRepository;

/**
 * Disposable-MariaDB test for Stage 3.4.221.
 *
 * Destructive DDL/DML is allowed ONLY after all guards pass. The guard follows
 * the project convention used by api/tests/stock-abs-accounting-integration.php:
 * loopback host, dedicated disposable DB name, explicit DISPOSABLE flag, UUID v4
 * sentinel and a matching marker stored in crm_disposable_sentinel.
 *
 * Required env:
 *   CRM7_TEST_DB_DSN         mysql:host=127.0.0.1;port=<port>;dbname=crm_<name>_test;charset=utf8mb4
 *   CRM7_TEST_DB_USER
 *   CRM7_TEST_DB_PASS
 *   CRM7_TEST_DB_PORT        must equal the DSN port
 *   CRM7_TEST_DB_DISPOSABLE  must be exactly 1
 *   CRM7_TEST_DB_SENTINEL    UUID v4, must match crm_disposable_sentinel.marker
 *
 * The sentinel row must be provisioned by the disposable-environment setup
 * before this script is run. On any guard failure the script exits with code 2
 * and performs NO DDL/DML.
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
function check(bool $condition, string $name, array &$failures): void
{
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures[] = $name;
    }
}

$pdo->exec('DROP TABLE IF EXISTS stock_reservations');
$pdo->exec('DROP TABLE IF EXISTS stock_levels');
$pdo->exec('DROP TABLE IF EXISTS nomenclature');
$pdo->exec('DROP TABLE IF EXISTS settings');

$pdo->exec('CREATE TABLE settings (
    `key` varchar(100) NOT NULL,
    `value` text,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$pdo->exec('CREATE TABLE nomenclature (
    ID int NOT NULL AUTO_INCREMENT,
    NAME char(255) DEFAULT NULL,
    NAME_1C char(255) DEFAULT NULL,
    UNIT char(255) DEFAULT NULL,
    DESCRIPTION text DEFAULT NULL,
    PRIMARY KEY (ID),
    UNIQUE KEY NAME_1C (NAME_1C),
    UNIQUE KEY NAME (NAME)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$pdo->exec('CREATE TABLE stock_levels (
    ID int NOT NULL AUTO_INCREMENT,
    NAME_SID char(50) DEFAULT NULL,
    QUANTITY decimal(20,5) DEFAULT 0.00000,
    PRIMARY KEY (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$pdo->exec('CREATE TABLE stock_reservations (
    ID int NOT NULL AUTO_INCREMENT,
    quantity decimal(20,5) DEFAULT 0.00000,
    PRIMARY KEY (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$pdo->exec("INSERT INTO nomenclature (NAME, NAME_1C, UNIT, DESCRIPTION) VALUES ('Legacy item', 'LEGACY-1', 'pcs', 'old')");
$pdo->exec("INSERT INTO stock_levels (NAME_SID, QUANTITY) VALUES ('LEGACY-1', 12.34000)");
$pdo->exec("INSERT INTO stock_reservations (quantity) VALUES (3.00000)");

$migration = (string) file_get_contents(__DIR__ . '/../db/migrations/082_track_lots_foundation.sql');
foreach (preg_split('/;\s*[\r\n]+/', $migration) ?: [] as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

// --- Migration assertions -------------------------------------------------
$column = $pdo->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomenclature' AND COLUMN_NAME = 'TRACK_LOTS'")->fetch();
check($column !== false, 'TRACK_LOTS column exists', $failures);
check(($column['COLUMN_TYPE'] ?? '') === 'tinyint(1)', 'TRACK_LOTS type is tinyint(1)', $failures);
check(($column['IS_NULLABLE'] ?? '') === 'NO', 'TRACK_LOTS is NOT NULL', $failures);
check(($column['COLUMN_DEFAULT'] ?? '') === '0', 'TRACK_LOTS default is 0', $failures);
check((string) $pdo->query("SELECT TRACK_LOTS FROM nomenclature WHERE NAME_1C = 'LEGACY-1'")->fetchColumn() === '0',
    'existing product gets TRACK_LOTS=0', $failures);
check((string) $pdo->query('SELECT QUANTITY FROM stock_levels LIMIT 1')->fetchColumn() === '12.34000',
    'stock balance unchanged by migration', $failures);
check((string) $pdo->query('SELECT quantity FROM stock_reservations LIMIT 1')->fetchColumn() === '3.00000',
    'reservation unchanged by migration', $failures);
check((string) $pdo->query("SELECT value FROM settings WHERE `key` = 'stock.track_lots_default'")->fetchColumn() === '0',
    'global default setting inserted as 0', $failures);

// Re-running the INSERT must not overwrite an operator value.
$pdo->exec("UPDATE settings SET value = '1' WHERE `key` = 'stock.track_lots_default'");
$pdo->exec("INSERT IGNORE INTO settings (`key`, value) VALUES ('stock.track_lots_default', '0')");
check((string) $pdo->query("SELECT value FROM settings WHERE `key` = 'stock.track_lots_default'")->fetchColumn() === '1',
    'repeated default insert preserves configured value', $failures);

// --- Settings accessor ----------------------------------------------------
$settings = new SettingsRepository();
check($settings->trackLotsDefault() === true, 'trackLotsDefault() true for 1', $failures);
$settings->set('stock.track_lots_default', 'garbage');
check($settings->trackLotsDefault() === false, 'trackLotsDefault() strict false for invalid value', $failures);
$pdo->exec("DELETE FROM settings WHERE `key` = 'stock.track_lots_default'");
check($settings->trackLotsDefault() === false, 'trackLotsDefault() false when key missing', $failures);

// --- New-product default and isolation ------------------------------------
$settings->set('stock.track_lots_default', '1');
$repo = new StocksRepository();
$new = $repo->upsertNomenclature('New lot item', 'pcs', 'new');
check($new !== null && (string) ($new['TRACK_LOTS'] ?? '') === '1', 'new product receives default=true', $failures);

$legacyAgain = $repo->upsertNomenclature('LEGACY-1', 'pcs', 'updated');
check($legacyAgain !== null && (string) ($legacyAgain['TRACK_LOTS'] ?? '') === '0',
    'existing product keeps TRACK_LOTS=0 on upsert', $failures);
check((string) $pdo->query("SELECT DESCRIPTION FROM nomenclature WHERE NAME_1C = 'LEGACY-1'")->fetchColumn() === 'updated',
    'legacy upsert still updates description', $failures);

$settings->set('stock.track_lots_default', '0');
$new2 = $repo->upsertNomenclature('Another legacy item', 'pcs', null);
check($new2 !== null && (string) ($new2['TRACK_LOTS'] ?? '') === '0', 'new product receives default=false', $failures);
check((string) $pdo->query("SELECT TRACK_LOTS FROM nomenclature WHERE NAME_1C = 'New lot item'")->fetchColumn() === '1',
    'changing global default does not change existing products', $failures);

// --- Settings registry / rights -------------------------------------------
$reflection = new ReflectionClass(ModuleService::class);
$settingsConstant = $reflection->getReflectionConstant('SETTINGS');
$registry = $settingsConstant === false ? [] : (array) $settingsConstant->getValue();
check(isset($registry['requests']['stock.track_lots_default'])
    && ($registry['requests']['stock.track_lots_default']['type'] ?? '') === 'bool',
    'setting registered as bool in ModuleService::SETTINGS', $failures);

$module = new ModuleService();
$readSettings = $reflection->getMethod('readModuleSettings');
try {
    $values = $readSettings->invoke($module, 'requests', ['stock.track_lots_default' => true]);
    check(($values['stock.track_lots_default'] ?? null) === '1', 'bool coercion true -> 1', $failures);
} catch (Throwable $e) {
    check(false, 'bool coercion true -> 1 (' . $e::class . ')', $failures);
}

try {
    $module->save([], [], ['modules' => []]);
    check(false, 'save() requires roles.manage', $failures);
} catch (HttpException $e) {
    check($e->status === 403 && $e->errorCode === 'forbidden', 'save() requires roles.manage (403)', $failures);
}

// --- Strict validation for stock.track_lots_default only -------------------
$strictCases = [
    [true, '1', 'bool true accepted'],
    [false, '0', 'bool false accepted'],
    ['1', '1', 'string 1 accepted'],
    ['0', '0', 'string 0 accepted'],
];
foreach ($strictCases as [$value, $expected, $name]) {
    try {
        $values = $readSettings->invoke($module, 'requests', ['stock.track_lots_default' => $value]);
        check(($values['stock.track_lots_default'] ?? null) === $expected, $name, $failures);
    } catch (Throwable $e) {
        check(false, $name . ' (' . $e::class . ')', $failures);
    }
}

$invalidCases = [
    ['garbage', 'invalid string rejected'],
    ['', 'empty string rejected'],
    [2, 'integer 2 rejected'],
];
foreach ($invalidCases as [$value, $name]) {
    try {
        $readSettings->invoke($module, 'requests', ['stock.track_lots_default' => $value]);
        check(false, $name, $failures);
    } catch (HttpException $e) {
        check($e->status === 422 && $e->errorCode === 'validation_error', $name, $failures);
    } catch (Throwable $e) {
        check(false, $name . ' (unexpected ' . $e::class . ')', $failures);
    }
}

try {
    $values = $readSettings->invoke($module, 'requests', []);
    check(!array_key_exists('stock.track_lots_default', $values), 'missing value is skipped', $failures);
} catch (Throwable $e) {
    check(false, 'missing value is skipped (' . $e::class . ')', $failures);
}

// Other bool settings keep the legacy coercion (no regression).
try {
    $values = $readSettings->invoke($module, 'requests', ['stock.reserve_enabled' => 'garbage']);
    check(($values['stock.reserve_enabled'] ?? null) === '1', 'other bool settings unchanged', $failures);
} catch (Throwable $e) {
    check(false, 'other bool settings unchanged (' . $e::class . ')', $failures);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d\n", count($failures)));
    exit(1);
}
echo "track-lots-foundation: OK\n";
