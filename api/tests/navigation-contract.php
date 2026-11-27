<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Controllers\AppSettingsController;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use App\Navigation\NavigationRegistry;
use App\Support\Modules;

/** Phase 0 contract tests for docs/navigation-2.0.md §18–19. */
$failures = [];

function check(bool $condition, string $name, array &$failures): void
{
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures[] = $name;
    }
}

/**
 * Read-only policy fixtures from migration SQL. No database connection and
 * no writes: the test derives expected capability grants from declared rules.
 *
 * @return array{grants: array<int, array<int, string>>, codes: array<int, string>}
 */
function policyFixtures(): array
{
    $sql = '';
    foreach (glob(__DIR__ . '/../db/migrations/*.sql') ?: [] as $file) {
        $sql .= (string) file_get_contents($file) . "\n";
    }

    $grants = [];
    preg_match_all(
        '/INSERT\s+(?:IGNORE\s+)?INTO\s+`?level_capabilities`?\s*\([^)]*\)\s*VALUES\s*(.*?);/is',
        $sql,
        $statements
    );
    foreach ($statements[1] ?? [] as $values) {
        preg_match_all('/\(\s*(\d+)\s*,\s*\'([^\']+)\'\s*\)/', $values, $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $grants[(int) $pair[1]][] = (string) $pair[2];
        }
    }

    $codes = [];
    preg_match_all(
        '/INSERT\s+(?:IGNORE\s+)?INTO\s+`?capabilities`?\s*\([^)]*\)\s*VALUES\s*(.*?);/is',
        $sql,
        $capStatements
    );
    foreach ($capStatements[1] ?? [] as $values) {
        preg_match_all('/\(\s*\'([^\']+)\'\s*,/', $values, $found);
        $codes = array_merge($codes, $found[1] ?? []);
    }

    return [
        'grants' => array_map(static fn (array $row): array => array_values(array_unique($row)), $grants),
        'codes' => array_values(array_unique($codes)),
    ];
}

$fixtures = policyFixtures();

// 1. Registry capabilities are derived, not declared independently.
$definitions = NavigationRegistry::definition();
$derived = true;
foreach ($definitions as $node) {
    $moduleCode = (string) ($node['module'] ?? '');
    if ($moduleCode === '') {
        continue;
    }
    if (array_key_exists('capabilities', $node)) {
        $derived = false;
        continue;
    }
    $module = Modules::byCode()[$moduleCode] ?? null;
    if ($module === null) {
        $derived = false;
        continue;
    }
    $groupCode = (string) ($node['moduleGroup'] ?? '');
    if ($groupCode !== '') {
        $expected = null;
        foreach (Modules::accessGroups($module) as $group) {
            if ((string) ($group['code'] ?? '') === $groupCode) {
                $expected = array_values(array_map('strval', (array) ($group['access'] ?? [])));
            }
        }
    } else {
        $expected = array_values(array_map('strval', (array) ($module['access'] ?? [])));
    }
    if ($expected === null || NavigationRegistry::capabilitiesFor($node) !== $expected) {
        $derived = false;
    }
}
check($derived, 'module-backed NavNode capabilities are derived from Modules::access', $failures);

// 2. System node codes are existing policy codes, not invented capabilities.
$unknown = array_diff(NavigationRegistry::policyCodes(), $fixtures['codes']);
check($unknown === [], 'system nodes use only existing capability codes', $failures);

// 3. Role matrix from declared level_capabilities (read-only fixtures).
$registry = new NavigationRegistry();
$allEnabled = static fn (string $code): bool => true;
$matrix = [];
foreach ([90, 50, 10, 5, 1] as $level) {
    $nodes = $registry->visible($fixtures['grants'][$level] ?? [], $allEnabled);
    $matrix[$level] = array_column($nodes, 'id');

    $groups = [];
    foreach ($nodes as $node) {
        $groups[(string) $node['group']][] = $node['id'];
    }
    check($groups !== [] && min(array_map('count', $groups)) > 0, "level $level has no empty nav groups", $failures);
    check(count($matrix[$level]) === count(array_unique($matrix[$level])), "level $level has no duplicate nav nodes", $failures);
}

check(in_array('stock-lots', $matrix[90], true), 'tech admin sees lots when module enabled', $failures);
check(in_array('settings', $matrix[50], true) && in_array('admin-audit', $matrix[50], true), 'admin sees settings and audit', $failures);
check(in_array('my-reports', $matrix[10], true) && !in_array('reports', $matrix[10], true), 'manager sees own reports only', $failures);
check(!in_array('settings', $matrix[10], true) && !in_array('admin-audit', $matrix[10], true), 'manager has no system nav', $failures);
check(in_array('requests', $matrix[5], true) && !in_array('clients', $matrix[5], true), 'client sees requests but not clients', $failures);
check(!in_array('stock-lots', $matrix[5], true), 'client does not see lots', $failures);

// 4. Disabled module hides node and its metadata.
$lotsDisabled = static fn (string $code): bool => $code !== 'stock_lots';
$withoutLots = $registry->visible($fixtures['grants'][90] ?? [], $lotsDisabled);
check(!in_array('stock-lots', array_column($withoutLots, 'id'), true), 'disabled lots module hides nav node', $failures);
check(!str_contains((string) json_encode($withoutLots, JSON_UNESCAPED_UNICODE), 'Партии'), 'disabled module metadata is not leaked', $failures);

// 5. No user data crosses between capability sets.
$managerJson = (string) json_encode($registry->visible($fixtures['grants'][10] ?? [], $allEnabled), JSON_UNESCAPED_UNICODE);
$clientJson = (string) json_encode($registry->visible($fixtures['grants'][5] ?? [], $allEnabled), JSON_UNESCAPED_UNICODE);
check($managerJson !== $clientJson && !str_contains($managerJson . $clientJson, '@'), 'visibleNav carries no user identity data', $failures);

// 6. Unauthenticated request is still rejected by backend authorization.
$router = new Router();
$router->get('/settings/app', [new AppSettingsController(), 'index']);
$unauthenticated = new Request('GET', '/settings/app', [], [], [], '127.0.0.1');
$rejected = false;
try {
    $router->dispatch($unauthenticated);
} catch (HttpException $e) {
    $rejected = $e->status === 401 && $e->errorCode === 'unauthorized';
}
check($rejected, 'unauthenticated /settings/app returns 401 and no nav', $failures);

// 7. Existing API guards remain in the entry point.
$indexSource = (string) file_get_contents(__DIR__ . '/../public/index.php');
check(str_contains($indexSource, 'Modules::guards('), 'module API guards remain active', $failures);
$controllerSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/ApiController.php');
check(str_contains($controllerSource, '$this->auth->authenticate'), 'backend authentication remains active', $failures);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}

echo "navigation-contract: OK\n";
