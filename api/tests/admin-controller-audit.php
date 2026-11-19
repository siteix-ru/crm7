<?php

declare(strict_types=1);

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

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');

function methodSource(string $source, string $name): string
{
    $position = strpos($source, 'function ' . $name . '(');

    if ($position === false) {
        return '';
    }

    return substr($source, $position, 1200);
}

$saveMethod = methodSource($source, 'saveDatabaseSettings');
$requireMethod = methodSource($source, 'requireTechAdmin');

check($saveMethod !== '', 'найден метод saveDatabaseSettings', $failures);
check(
    str_contains($saveMethod, '[$user] = $this->requireTechAdmin($request);'),
    'результат requireTechAdmin распаковывается в пару',
    $failures
);
check(
    !str_contains($saveMethod, '$user = $this->requireTechAdmin($request);'),
    'старая ошибочная распаковка отсутствует',
    $failures
);
check(
    str_contains($saveMethod, "DatabaseSettings::save(\$request->bodyAll(), (string) (\$user['LOGIN'] ?? ''))"),
    'в DatabaseSettings::save передаётся LOGIN реального пользователя',
    $failures
);
check(
    str_contains($saveMethod, "\$this->audit(\$request, \$user, 'settings.database'"),
    'в аудит передаётся реальный пользователь',
    $failures
);
check(
    str_contains($requireMethod, "return [\$user, \$capabilities];"),
    'контракт requireTechAdmin — пара user + capabilities',
    $failures
);
check(
    str_contains($requireMethod, "in_array('roles.manage', \$capabilities, true)"),
    'проверка roles.manage сохранена',
    $failures
);

echo $failures === [] ? "\nALL OK\n" : "\nFAILED: " . implode(', ', $failures) . "\n";

exit($failures === [] ? 0 : 1);
