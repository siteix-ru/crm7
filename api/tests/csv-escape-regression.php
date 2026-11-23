<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Stocks\StocksService;

/**
 * Narrow guard for CSV parsing semantics of StocksService::readCsv().
 *
 * PHP 8.4 deprecates calling fgetcsv() without the $escape argument. The fix
 * passes the historical default explicitly; this test asserts that the parsed
 * rows are byte-for-byte identical to the previous behaviour, not merely that
 * no deprecation is emitted.
 */

$deprecations = [];
set_error_handler(static function (int $errno, string $message) use (&$deprecations): bool {
    if ($errno === E_DEPRECATED && stripos($message, 'fgetcsv') !== false) {
        $deprecations[] = $message;
    }
    return false;
});

$failures = [];
function check(bool $ok, string $name, array &$failures): void
{
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures[] = $name;
    }
}

$service = (new ReflectionClass(StocksService::class))->newInstanceWithoutConstructor();
$readCsv = new ReflectionMethod(StocksService::class, 'readCsv');
$readCsv->setAccessible(true);

$cases = [
    'plain semicolon fields' => [
        "WH1;IT1;pcs;11\n",
        [['WH1', 'IT1', 'pcs', '11']],
    ],
    'delimiter inside quoted field' => [
        "\"WH;1\";IT1;pcs;11\n",
        [['WH;1', 'IT1', 'pcs', '11']],
    ],
    'doubled quotes inside field' => [
        "\"WH\"\"1\";IT1;pcs;11\n",
        [['WH"1', 'IT1', 'pcs', '11']],
    ],
    'backslash before quote is preserved (historical escape semantics)' => [
        '"WH\\"1";IT1;pcs;11' . "\n",
        [['WH\\"1', 'IT1', 'pcs', '11']],
    ],
    'multiple rows' => [
        "a;b\n1;2\n",
        [['a', 'b'], ['1', '2']],
    ],
    'comma delimiter autodetection' => [
        "WH1,IT1,pcs,11\n",
        [['WH1', 'IT1', 'pcs', '11']],
    ],
    'BOM stripped from first cell' => [
        "\xEF\xBB\xBFWH1;IT1;pcs;11\n",
        [['WH1', 'IT1', 'pcs', '11']],
    ],
];

try {
    foreach ($cases as $name => [$csv, $expected]) {
        // Use the exact path returned by tempnam(): appending '.csv' would
        // leave the original tempnam() file behind after the test.
        $path = tempnam(sys_get_temp_dir(), 'crm_csv_');
        if ($path === false) {
            check(false, $name . ' (tempnam failed)', $failures);
            continue;
        }
        try {
            file_put_contents($path, $csv);
            $actual = $readCsv->invoke($service, $path);
            check($actual === $expected, $name, $failures);
            if ($actual !== $expected) {
                fwrite(STDERR, '  expected=' . json_encode($expected) . ' actual=' . json_encode($actual) . "\n");
            }
        } catch (Throwable $e) {
            check(false, $name . ' (' . $e::class . ': ' . $e->getMessage() . ')', $failures);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
} finally {
    restore_error_handler();
}

check($deprecations === [], 'no fgetcsv deprecation', $failures);
if ($deprecations !== []) {
    fwrite(STDERR, '  deprecations=' . implode(' | ', $deprecations) . "\n");
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d\n", count($failures)));
    exit(1);
}
echo "csv-escape-regression: OK\n";
