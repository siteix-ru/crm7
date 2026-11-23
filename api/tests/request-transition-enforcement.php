<?php

declare(strict_types=1);

/**
 * DB-independent contract regression for the requests.transition enforcement.
 *
 * Verifies that the staff-side capability check exists in RequestService::transition()
 * and that the separate client-owner cancellation path remains intact.
 * Run by the standalone PHP suite (no DB, no HTTP, no Docker).
 */

$failures = [];

function check(bool $condition, string $name, array &$failures): void
{
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures[] = $name;
    }
}

$root = dirname(__DIR__);
$servicePath = $root . '/src/Requests/RequestService.php';
$controllerPath = $root . '/src/Controllers/RequestController.php';
$routesPath = $root . '/public/index.php';

foreach ([$servicePath, $controllerPath, $routesPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, 'FAIL missing source file: ' . $path . "\n");
        exit(1);
    }
}

$service = (string) file_get_contents($servicePath);
$controller = (string) file_get_contents($controllerPath);
$routes = (string) file_get_contents($routesPath);

$transitionStart = strpos($service, 'public function transition(');
$transitionEnd = $transitionStart === false ? false : strpos($service, "\n    public function claim(", $transitionStart);
$transitionBody = $transitionStart === false || $transitionEnd === false
    ? ''
    : substr($service, $transitionStart, $transitionEnd - $transitionStart);

check($transitionBody !== '', 'transition() method source located', $failures);

$managerAssignment = '$isManager = $this->canManage($user, $capabilities, $request);';
$capabilityCheck = "if (\$isManager && !in_array('requests.transition', \$capabilities, true))";
$clientGuard = "if (!\$isManager && \$toStatus !== 'canceled')";
$clientMessage = 'Клиент может только отменить заявку';
$releaseCall = '$this->reservations->release($id);';
$applyTransition = '$this->requests->applyTransition($id, $toStatus, $timestampOps);';

check(str_contains($transitionBody, $managerAssignment), 'staff/client distinction via canManage preserved', $failures);
check(str_contains($transitionBody, $capabilityCheck), 'staff-side requests.transition enforcement present', $failures);
check(
    strpos($transitionBody, $managerAssignment) < strpos($transitionBody, $capabilityCheck),
    'capability check runs after staff/client distinction',
    $failures
);
check(str_contains($transitionBody, $clientGuard), 'client-only cancel guard preserved', $failures);
check(str_contains($transitionBody, $clientMessage), 'client-cancel error message preserved', $failures);
check(str_contains($transitionBody, $applyTransition), 'status write preserved', $failures);
check(str_contains($transitionBody, $releaseCall), 'reservation release on cancel preserved', $failures);

check(
    substr_count($routes, "[\$requests, 'transition']") === 1,
    'exactly one API route maps to transition',
    $failures
);
check(
    str_contains($routes, "/requests/{id}/transition'"),
    'transition route is POST /requests/{id}/transition',
    $failures
);
check(
    !str_contains($controller, "requireCapability('requests.transition')"),
    'enforcement is in service layer, not duplicated in controller',
    $failures
);

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}

echo "\nALL OK\n";
