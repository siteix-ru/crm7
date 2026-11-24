<?php

declare(strict_types=1);

/**
 * DB-independent regression guard for CI test routing.
 *
 * Fails when a database-dependent PHP test is executed by the standalone
 * `php` job, or when it is silently dropped from the disposable MariaDB jobs.
 */

$root = dirname(__DIR__, 2);
$workflow = file_get_contents($root . '/.github/workflows/ci.yml');
if ($workflow === false) {
    fwrite(STDERR, "FAIL cannot read .github/workflows/ci.yml\n");
    exit(1);
}

$failures = [];
function check(bool $condition, string $name, array &$failures): void
{
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures[] = $name;
    }
}

$standaloneStart = strpos($workflow, '      - name: PHP standalone tests');
$standaloneEnd = strpos($workflow, '  python-deploy-tests:', $standaloneStart);
$foundationStart = strpos($workflow, '  track-lots-foundation:');
$lotStart = strpos($workflow, '  lot-lifecycle-db:');
$integrationStart = strpos($workflow, '  stock-import-integration:');
$integrationEnd = strpos($workflow, '  images:', $integrationStart);
check($standaloneStart !== false && $standaloneEnd !== false, 'routing: standalone block found', $failures);
check($foundationStart !== false && $lotStart !== false, 'routing: DB job blocks found', $failures);
check($integrationStart !== false && $integrationEnd !== false, 'routing: integration block found', $failures);
if ($standaloneStart === false || $standaloneEnd === false
    || $foundationStart === false || $lotStart === false
    || $integrationStart === false || $integrationEnd === false
    || $lotStart <= $foundationStart || $integrationStart <= $lotStart
) {
    exit(1);
}

$standalone = substr($workflow, $standaloneStart, $standaloneEnd - $standaloneStart);
$foundation = substr($workflow, $foundationStart, $lotStart - $foundationStart);
$lot = substr($workflow, $lotStart, $integrationStart - $lotStart);
$integration = substr($workflow, $integrationStart, $integrationEnd - $integrationStart);

$dbDependent = [];
foreach (glob($root . '/api/tests/*.php') ?: [] as $file) {
    if (basename($file) === basename(__FILE__)) {
        continue;
    }
    $source = (string) file_get_contents($file);
    if (strpos($source, 'CRM_STOCK_TEST_DSN') !== false
        || strpos($source, 'CRM7_TEST_DB_DSN') !== false
        || strpos($source, '->cleanup(') !== false
        || strpos($source, '->recover(') !== false
    ) {
        $dbDependent[] = basename($file);
    }
}
sort($dbDependent);

$expectedStock = [
    'request-transition-api-integration.php',
    'stock-abs-accounting-integration.php',
    'stock-import-fencing-integration.php',
    'stock-import-idempotency-cleanup.php',
    'stock-import-idempotency-integration.php',
    'stock-import-idempotency-recover.php',
    'stock-import-lock-integration.php',
    'stock-import-two-pdo-integration.php',
    'stock-reservation-concurrency-integration.php',
    'stock-warehouse-collapse-integration.php',
];
$expectedFoundation = ['track-lots-foundation.php'];
$expectedLot = [
    'stock-lot-idempotency-scope.php',
    'stock-lot-lifecycle.php',
    'stock-lots-api-integration.php',
];
$expected = array_merge($expectedStock, $expectedFoundation, $expectedLot);
sort($expected);
check($dbDependent === $expected,
    'routing: DB-dependent test set matches approved list (' . implode(',', $dbDependent) . ')', $failures);

check(strpos($standalone, 'for f in api/tests/*.php') !== false,
    'routing: standalone suite still discovers PHP tests', $failures);

foreach ($expected as $name) {
    check(strpos($standalone, $name) !== false,
        'routing: standalone suite excludes ' . $name, $failures);
}
foreach ($expectedStock as $name) {
    check(strpos($integration, 'api/tests/' . $name) !== false,
        'routing: stock-import-integration executes ' . $name, $failures);
}
foreach ($expectedFoundation as $name) {
    check(strpos($foundation, 'api/tests/' . $name) !== false,
        'routing: track-lots-foundation executes ' . $name, $failures);
}
foreach ($expectedLot as $name) {
    check(strpos($lot, 'api/tests/' . $name) !== false,
        'routing: lot-lifecycle-db executes ' . $name, $failures);
}

$lotEnv = [
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS',
    'CRM7_TEST_DB_DSN', 'CRM7_TEST_DB_USER', 'CRM7_TEST_DB_PASS',
    'CRM7_TEST_DB_PORT', 'CRM7_TEST_DB_DISPOSABLE', 'CRM7_TEST_DB_SENTINEL',
];
foreach ($lotEnv as $name) {
    check(strpos($lot, $name) !== false, 'routing: lot DB job provides ' . $name, $failures);
}

foreach (['083_stock_lot_lifecycle.sql', '084_stock_issue_lines.sql',
          '085_stock_lot_idempotency_scope.sql', '086_receipt_scope_lot_identity.sql'] as $migration) {
    check(file_exists($root . '/api/db/migrations/' . $migration),
        'routing: migration ' . $migration . ' present', $failures);
}
check(strpos($lot, 'php api/bin/migrate.php') !== false,
    'routing: lot DB job applies migrations via the standard runner', $failures);
check(strpos($lot, 'concurrency:') !== false && strpos($lot, 'cancel-in-progress: false') !== false,
    'routing: lot DB job has non-cancelling concurrency isolation', $failures);
check(strpos($lot, 'services:') !== false && strpos($lot, 'mariadb:11') !== false,
    'routing: lot DB job uses its own MariaDB 11 service', $failures);
check(substr_count($workflow, 'image: mariadb:11') >= 5 && substr_count($workflow, 'image: mariadb:10.11') === 1,
    'routing: all migration-applying jobs use MariaDB 11', $failures);
check(strpos($lot, 'crm_lotlifecycle_test') !== false,
    'routing: lot DB job uses a dedicated disposable database', $failures);
check(strpos($lot, 'if: always()') !== false && strpos($lot, 'DROP DATABASE IF EXISTS crm_lotlifecycle_test') !== false,
    'routing: lot DB job cleans up on every outcome', $failures);
check(strpos($lot, 'needs: [php]') !== false,
    'routing: lot DB job preserves mandatory php dependency', $failures);
check(substr_count($lot, 'continue-on-error: true') === 1,
    'routing: lot DB job allows continue-on-error only for cleanup', $failures);
check(strpos($lot, '|| true') === false,
    'routing: no masked pipeline errors', $failures);

check(strpos($integration, 'stock-import-integration:') !== false
    || strpos($workflow, '  stock-import-integration:') !== false,
    'routing: mandatory job still present', $failures);
check(strpos($integration, 'needs: [php]') !== false, 'routing: needs [php] preserved', $failures);
check(strpos($integration, 'timeout-minutes: 30') !== false, 'routing: timeout preserved', $failures);
check(strpos($integration, 'cancel-in-progress: false') !== false, 'routing: concurrency preserved', $failures);
check(strpos($integration, 'continue-on-error') === false, 'routing: no continue-on-error', $failures);
check(strpos($integration, '|| true') === false, 'routing: no masked pipeline errors', $failures);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "ALL OK\n";
