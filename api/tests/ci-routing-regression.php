<?php

declare(strict_types=1);

/**
 * DB-independent regression guard for CI test routing.
 *
 * Fails when a database-dependent PHP test is executed by the standalone
 * `php` job, or when it is silently dropped from the disposable MariaDB job.
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
$integrationStart = strpos($workflow, '  stock-import-integration:');
$integrationEnd = strpos($workflow, '  images:', $integrationStart);
check($standaloneStart !== false && $standaloneEnd !== false, 'routing: standalone block found', $failures);
check($integrationStart !== false && $integrationEnd !== false, 'routing: integration block found', $failures);
if ($standaloneStart === false || $standaloneEnd === false || $integrationStart === false || $integrationEnd === false) {
    exit(1);
}

$standalone = substr($workflow, $standaloneStart, $standaloneEnd - $standaloneStart);
$integration = substr($workflow, $integrationStart, $integrationEnd - $integrationStart);

$dbDependent = [];
foreach (glob($root . '/api/tests/*.php') ?: [] as $file) {
    if (basename($file) === basename(__FILE__)) {
        continue;
    }
    $source = (string) file_get_contents($file);
    if (strpos($source, 'CRM_STOCK_TEST_DSN') !== false
        || strpos($source, '->cleanup(') !== false
        || strpos($source, '->recover(') !== false
    ) {
        $dbDependent[] = basename($file);
    }
}
sort($dbDependent);
$expected = [
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
check($dbDependent === $expected,
    'routing: DB-dependent test set matches approved list (' . implode(',', $dbDependent) . ')', $failures);

check(strpos($standalone, 'for f in api/tests/*.php') !== false,
    'routing: standalone suite still discovers PHP tests', $failures);

foreach ($expected as $name) {
    check(strpos($standalone, $name) !== false,
        'routing: standalone suite excludes ' . $name, $failures);
    check(strpos($integration, 'php api/tests/' . $name) !== false,
        'routing: integration job executes ' . $name, $failures);
}

check(substr_count($integration, 'CRM_STOCK_TEST_DSN:') >= 3,
    'routing: integration job passes DSN to legacy, cleanup/recovery and two-PDO steps', $failures);
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
