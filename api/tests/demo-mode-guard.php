<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Http\DemoModeGuard;
use App\Http\Request;

$failures = [];

function check(bool $condition, string $name, array &$failures): void
{
    if ($condition) {
        echo "ok   $name\n";

        return;
    }

    echo "FAIL $name\n";
    $failures[] = $name;
}

function req(string $method, string $path): Request
{
    return new Request($method, $path, [], [], [], '127.0.0.1');
}

putenv('DEMO_MODE=true');

$forbidden = [
    ['POST', '/admin/settings/database'],
    ['POST', '/admin/settings/database/test'],
    ['GET', '/admin/settings/database'],
    ['POST', '/admin/update/apply'],
    ['POST', '/admin/modules'],
    ['POST', '/roles/10'],
    ['POST', '/roles/5'],
];

foreach ($forbidden as [$method, $path]) {
    check(DemoModeGuard::isForbidden(req($method, $path)), "запрещено $method $path", $failures);
}

$allowed = [
    ['GET', '/admin/update/check'],
    ['GET', '/admin/update/status'],
    ['GET', '/admin/settings/email'],
    ['POST', '/requests'],
    ['PATCH', '/clients/5'],
    ['POST', '/chat/threads'],
    ['POST', '/stocks/import'],
    ['POST', '/roles'],
    ['POST', '/roles/10/extra'],
    ['POST', '/admin/modules/extra'],
    ['POST', '/admin/settings/database/extra'],
    ['GET', '/admin/settings/database/test'],
    ['PUT', '/admin/settings/database'],
    ['POST', '/admin/settings/system'],
];

foreach ($allowed as [$method, $path]) {
    check(!DemoModeGuard::isForbidden(req($method, $path)), "разрешено $method $path", $failures);
}

// В index.php префикс API_BASE_PATH снимается до вызова guard, поэтому
// здесь проверяется именно ненормализованный путь, а не поведение запроса.
check(
    !DemoModeGuard::isForbidden(req('POST', '/api/v2/roles/10')),
    'ненормализованный путь /api/v2/roles/10 не совпадает с шаблоном',
    $failures
);

putenv('DEMO_MODE=false');

foreach ($forbidden as [$method, $path]) {
    check(!DemoModeGuard::isForbidden(req($method, $path)), "guard выключен: $method $path", $failures);
}

putenv('DEMO_MODE');

$entry = (string) file_get_contents(__DIR__ . '/../public/index.php');
$guardFound = preg_match('/DemoModeGuard\s*::\s*enforce\s*\(/', $entry, $guardMatch, PREG_OFFSET_CAPTURE);
$maintenanceFound = preg_match('/\$maintenance\s*=/', $entry, $maintenanceMatch, PREG_OFFSET_CAPTURE);

check(
    $guardFound === 1
        && $maintenanceFound === 1
        && $guardMatch[0][1] < $maintenanceMatch[0][1],
    'guard подключён до maintenance-проверки',
    $failures
);

echo $failures === [] ? "\nALL OK\n" : "\nFAILED: " . implode(', ', $failures) . "\n";

exit($failures === [] ? 0 : 1);
