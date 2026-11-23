<?php

declare(strict_types=1);

/**
 * Self-provisioning HTTP/API regression for requests.transition staff enforcement.
 *
 * Every fixture lives in a per-run random namespace. The test creates and removes
 * all of its own data inside an explicitly provisioned disposable MariaDB.
 *
 * Required env:
 *   CRM_STOCK_TEST_DSN / _USER / _PASS / _PORT / _SENTINEL / _DISPOSABLE
 *   CRM_STOCK_TEST_BASE              loopback API base URL
 *   CRM_STOCK_TEST_LOGIN_PASSWORD    password for the self-provisioned test users
 *
 * Missing/invalid env is a hard failure (exit 2), never a silent SKIP.
 * --guard-selftest and --static-selftest are read-only self checks.
 */

$failures = [];

function check(bool $condition, string $name, array &$failures): void
{
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures[] = $name;
    }
}

function guardFail(string $reason): void
{
    fwrite(STDERR, 'GUARD FAIL: ' . $reason . "\n");
    exit(2);
}

/** The CI disposable contract allows only these two exact base URL forms. */
function apiBaseError(string $base): ?string
{
    if (preg_match('#^http://127\.0\.0\.1:8080(?:/api/v2)?$#', $base) !== 1) {
        return 'API base должен быть http://127.0.0.1:8080 или http://127.0.0.1:8080/api/v2';
    }

    return null;
}

function normalizeApiBase(string $base): string
{
    return str_ends_with($base, '/api/v2') ? $base : $base . '/api/v2';
}

/** @return string|null error description, or null when the target is an allowed disposable DB */
function dbTargetError(array $env): ?string
{
    $required = [
        'CRM_STOCK_TEST_DSN', 'CRM_STOCK_TEST_USER', 'CRM_STOCK_TEST_PASS',
        'CRM_STOCK_TEST_PORT', 'CRM_STOCK_TEST_SENTINEL', 'CRM_STOCK_TEST_DISPOSABLE',
        'CRM_STOCK_TEST_BASE', 'CRM_STOCK_TEST_LOGIN_PASSWORD',
    ];
    foreach ($required as $key) {
        if (trim((string) ($env[$key] ?? '')) === '') {
            return 'не задан ' . $key;
        }
    }
    if ($env['CRM_STOCK_TEST_DISPOSABLE'] !== '1') {
        return 'CRM_STOCK_TEST_DISPOSABLE должен быть 1';
    }
    $dsn = (string) $env['CRM_STOCK_TEST_DSN'];
    if (!str_starts_with(strtolower($dsn), 'mysql:')) {
        return 'ожидается mysql: DSN';
    }
    $parts = [];
    foreach (explode(';', substr($dsn, strpos($dsn, ':') + 1)) as $segment) {
        $pair = explode('=', $segment, 2);
        if (count($pair) === 2) {
            $parts[strtolower(trim($pair[0]))] = trim($pair[1]);
        }
    }
    $host = strtolower($parts['host'] ?? '');
    $dbName = strtolower($parts['dbname'] ?? '');
    $port = (string) ($parts['port'] ?? '');
    if (!in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
        return 'DB host не loopback: ' . $host;
    }
    if (preg_match('/^crm_[a-z0-9]{1,20}_test$/', $dbName) !== 1) {
        return 'DB name не disposable: ' . $dbName;
    }
    $denylist = ['crm_local', 'crm7_itn', 'crm', 'crm7', 'crm_prod', 'crm_production',
        'crm_staging', 'crm_dev', 'crm_i71x_test', 'crm_i72x_test'];
    if (in_array($dbName, $denylist, true)) {
        return 'БД в denylist: ' . $dbName;
    }
    if (!ctype_digit($port) || $port !== (string) $env['CRM_STOCK_TEST_PORT']) {
        return 'порт DSN и CRM_STOCK_TEST_PORT не совпадают';
    }
    $baseError = apiBaseError((string) $env['CRM_STOCK_TEST_BASE']);
    if ($baseError !== null) {
        return $baseError;
    }
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $env['CRM_STOCK_TEST_SENTINEL']) !== 1) {
        return 'sentinel должен быть UUID v4';
    }

    return null;
}

$keys = [
    'CRM_STOCK_TEST_DSN', 'CRM_STOCK_TEST_USER', 'CRM_STOCK_TEST_PASS',
    'CRM_STOCK_TEST_PORT', 'CRM_STOCK_TEST_SENTINEL', 'CRM_STOCK_TEST_DISPOSABLE',
    'CRM_STOCK_TEST_BASE', 'CRM_STOCK_TEST_LOGIN_PASSWORD',
];
$env = [];
foreach ($keys as $key) {
    $raw = getenv($key);
    $env[$key] = $raw === false ? '' : (string) $raw;
}

if (in_array('--guard-selftest', $argv, true)) {
    echo "=== GUARD SELFTEST ===\n";
    $valid = [
        'CRM_STOCK_TEST_DSN' => 'mysql:host=127.0.0.1;port=3306;dbname=crm_cistock_test;charset=utf8mb4',
        'CRM_STOCK_TEST_USER' => 'root', 'CRM_STOCK_TEST_PASS' => 'x',
        'CRM_STOCK_TEST_PORT' => '3306',
        'CRM_STOCK_TEST_SENTINEL' => '123e4567-e89b-42d3-a456-426614174000',
        'CRM_STOCK_TEST_DISPOSABLE' => '1',
        'CRM_STOCK_TEST_BASE' => 'http://127.0.0.1:8080',
        'CRM_STOCK_TEST_LOGIN_PASSWORD' => 'x',
    ];
    check(dbTargetError($valid) === null, 'positive: crm_cistock_test accepted', $failures);
    $local = $valid;
    $local['CRM_STOCK_TEST_DSN'] = 'mysql:host=127.0.0.1;port=18720;dbname=crm_i139_test;charset=utf8mb4';
    $local['CRM_STOCK_TEST_PORT'] = '18720';
    check(dbTargetError($local) === null, 'positive: local disposable DB accepted', $failures);
    $normalized = $valid;
    $normalized['CRM_STOCK_TEST_BASE'] = 'http://127.0.0.1:8080/api/v2';
    check(dbTargetError($normalized) === null, 'positive: /api/v2 base accepted', $failures);
    check(normalizeApiBase('http://127.0.0.1:8080') === 'http://127.0.0.1:8080/api/v2',
        'root base normalizes exactly once', $failures);
    check(normalizeApiBase('http://127.0.0.1:8080/api/v2') === 'http://127.0.0.1:8080/api/v2',
        'normalized base is not doubled', $failures);
    foreach ([
        'http://127.0.0.1:3306' => 'forbidden port',
        'http://127.0.0.1:8081' => 'forbidden port',
        'http://localhost:8080' => 'non-loopback host',
        'http://192.168.1.1:8080' => 'external host',
        'https://127.0.0.1:8080' => 'https',
        'http://127.0.0.1:8080/api/v3' => 'api/v3',
        'http://127.0.0.1:8080/other' => 'arbitrary path',
        'http://127.0.0.1:8080?x=1' => 'query',
        'http://127.0.0.1:8080#fragment' => 'fragment',
        'http://127.0.0.1:abc' => 'malformed port',
        'not-a-url' => 'malformed url',
        'http://127.0.0.1:8080/api/v2/' => 'trailing slash',
    ] as $badBase => $reason) {
        $case = $valid;
        $case['CRM_STOCK_TEST_BASE'] = $badBase;
        check(dbTargetError($case) !== null, 'negative: ' . $reason . ' rejected', $failures);
    }
    foreach (['crm_local', 'crm7_itn', 'crm_prod', 'crm_staging', 'crm', 'crm_badname'] as $badName) {
        $case = $valid;
        $case['CRM_STOCK_TEST_DSN'] = 'mysql:host=127.0.0.1;port=3306;dbname=' . $badName . ';charset=utf8mb4';
        check(dbTargetError($case) !== null, 'negative: ' . $badName . ' rejected', $failures);
    }
    $case = $valid;
    $case['CRM_STOCK_TEST_LOGIN_PASSWORD'] = '';
    check(dbTargetError($case) !== null, 'negative: missing login password rejected', $failures);
    $case = $valid;
    $case['CRM_STOCK_TEST_DISPOSABLE'] = '0';
    check(dbTargetError($case) !== null, 'negative: non-disposable flag rejected', $failures);
    $case = $valid;
    $case['CRM_STOCK_TEST_DSN'] = 'mysql:host=db.example.com;port=3306;dbname=crm_i139_test;charset=utf8mb4';
    check(dbTargetError($case) !== null, 'negative: non-loopback host rejected', $failures);
    $case = $valid;
    $case['CRM_STOCK_TEST_PORT'] = '3307';
    check(dbTargetError($case) !== null, 'negative: port mismatch rejected', $failures);
    if ($failures !== []) {
        fwrite(STDERR, "\nGUARD SELFTEST FAILED: " . implode('; ', $failures) . "\n");
        exit(1);
    }
    echo "GUARD SELFTEST OK\n";
    exit(0);
}

if (in_array('--static-selftest', $argv, true)) {
    echo "=== STATIC SELFTEST (source contract only, not runtime proof) ===\n";
    $source = (string) file_get_contents(__FILE__);
    check(str_contains($source, 'random_bytes('), 'per-run random namespace generated', $failures);
    $fixedStock = 't141' . '-ws';
    $fixedNumber = 'T141' . '-%';
    check(!str_contains($source, "'" . $fixedStock . "'") && !str_contains($source, "'" . $fixedNumber . "'"),
        'fixed fixture namespace removed', $failures);
    check(str_contains($source, 'DELETE FROM notifications'), 'notifications cleanup present', $failures);
    check(substr_count($source, 'finally {') >= 2, ' provisioning and capability cleanup use finally', $failures);
    check(str_contains($source, '$cleanupErrors[]'), 'cleanup errors collected', $failures);
    check(str_contains($source, 'INSERT IGNORE INTO level_capabilities'),
        'capability restoration present', $failures);
    $countPos = strpos($source, '$count = static function');
    $tryPos = strpos($source, '$primaryError = null;' . "\n" . 'try {');
    check($countPos !== false && $tryPos !== false && $countPos < $tryPos,
        '$count declared before main try (cleanup verification safe on early failure)', $failures);
    if ($failures !== []) {
        fwrite(STDERR, "\nSTATIC SELFTEST FAILED: " . implode('; ', $failures) . "\n");
        exit(1);
    }
    echo "STATIC SELFTEST OK\n";
    exit(0);
}

$guardError = dbTargetError($env);
if ($guardError !== null) {
    guardFail($guardError);
}

$pdo = new PDO($env['CRM_STOCK_TEST_DSN'], $env['CRM_STOCK_TEST_USER'], $env['CRM_STOCK_TEST_PASS'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$markerStmt = $pdo->prepare('SELECT marker FROM crm_disposable_sentinel WHERE id = 1');
$markerStmt->execute();
if (!hash_equals($env['CRM_STOCK_TEST_SENTINEL'], (string) $markerStmt->fetchColumn())) {
    guardFail('sentinel marker не совпадает');
}

$base = normalizeApiBase($env['CRM_STOCK_TEST_BASE']);
$password = $env['CRM_STOCK_TEST_LOGIN_PASSWORD'];
$ns = 't141' . bin2hex(random_bytes(4));

$capCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM level_capabilities WHERE level = ? AND capability_code = ?'
);
$capCheck->execute([10, 'requests.transition']);
$level10CapOriginal = (int) $capCheck->fetchColumn() > 0;
$cleanupErrors = [];
$count = static function (string $sql, array $params = []) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
};

$cleanup = function () use ($pdo, $ns, &$level10CapOriginal, &$cleanupErrors): void {
    try {
        $pdo->prepare('DELETE FROM user_tokens WHERE user_id IN (SELECT ID FROM users WHERE LOGIN LIKE ?)')
            ->execute([$ns . '%']);
        $pdo->prepare('DELETE FROM notifications WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE ?)'
            . ' OR user_id IN (SELECT ID FROM users WHERE LOGIN LIKE ?)')
            ->execute([$ns . '-%', $ns . '%']);
        $pdo->prepare('DELETE FROM audit_log WHERE entity = ? AND entity_id IN (SELECT ID FROM requests WHERE number LIKE ?)')
            ->execute(['request', $ns . '-%']);
        $pdo->prepare('DELETE FROM request_history WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE ?)')
            ->execute([$ns . '-%']);
        $pdo->prepare('DELETE FROM stock_reservations WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE ?)')
            ->execute([$ns . '-%']);
        $pdo->prepare('DELETE FROM requests WHERE number LIKE ?')->execute([$ns . '-%']);
        $pdo->prepare('DELETE FROM stock_levels WHERE STOCK_SID = ? OR NAME_SID LIKE ?')
            ->execute([$ns . '-ws', $ns . '-%']);
        $pdo->prepare('DELETE FROM stocks WHERE SID = ?')->execute([$ns . '-ws']);
        $pdo->prepare('DELETE FROM users WHERE LOGIN LIKE ?')->execute([$ns . '%']);
    } catch (Throwable $exception) {
        $cleanupErrors[] = 'cleanup: ' . $exception->getMessage();
    }
    try {
        if ($level10CapOriginal) {
            $pdo->prepare('INSERT IGNORE INTO level_capabilities (level, capability_code) VALUES (?, ?)')
                ->execute([10, 'requests.transition']);
        } else {
            $pdo->prepare('DELETE FROM level_capabilities WHERE level = ? AND capability_code = ?')
                ->execute([10, 'requests.transition']);
        }
    } catch (Throwable $exception) {
        $cleanupErrors[] = 'capability: ' . $exception->getMessage();
    }
};

/** @return array{0:int,1:array<string,mixed>} */
function httpJson(string $method, string $url, ?array $body, ?string $token): array
{
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
        'ignore_errors' => true,
        'timeout' => 20,
    ]]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match) === 1) {
            $status = (int) $match[1];
        }
    }
    $decoded = json_decode($raw === false ? '' : $raw, true);

    return [$status, is_array($decoded) ? $decoded : []];
}

function login(string $base, string $login, string $password): string
{
    [$status, $json] = httpJson('POST', $base . '/auth/login', [
        'login' => $login, 'password' => $password,
    ], null);
    if ($status !== 200 || !isset($json['data']['token'])) {
        throw new RuntimeException('login failed for ' . $login . ': HTTP ' . $status);
    }

    return (string) $json['data']['token'];
}

function requestState(PDO $pdo, string $number): array
{
    $stmt = $pdo->prepare('SELECT ID, status_id, version FROM requests WHERE number = ? LIMIT 1');
    $stmt->execute([$number]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('fixture request missing: ' . $number);
    }
    $id = (int) $row['ID'];
    $res = $pdo->prepare('SELECT COUNT(*) FROM stock_reservations WHERE request_id = ?');
    $res->execute([$id]);

    return [
        'id' => $id,
        'status' => (string) $row['status_id'],
        'version' => (int) $row['version'],
        'reservations' => (int) $res->fetchColumn(),
    ];
}

function transition(string $base, string $token, int $id, string $target, int $version): array
{
    return httpJson('POST', $base . '/requests/' . $id . '/transition', [
        'to_status' => $target,
        'comment' => 'api regression',
        'version' => $version,
    ], $token);
}

echo "=== SELF-PROVISIONING (namespace " . $ns . ") ===\n";

$primaryError = null;
try {
    $pdo->prepare('INSERT IGNORE INTO level_capabilities (level, capability_code) VALUES (?, ?)')
        ->execute([10, 'requests.transition']);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('password_hash failed');
    }
    $logins = [
        'admin' => $ns . '_admin', 'admin50' => $ns . '_admin50',
        'manager' => $ns . '_manager', 'nocap' => $ns . '_nocap',
        'client' => $ns . '_client', 'client2' => $ns . '_client2',
        'guest' => $ns . '_guest',
    ];
    $levels = [
        'admin' => 90, 'admin50' => 50, 'manager' => 10, 'nocap' => 10,
        'client' => 5, 'client2' => 5, 'guest' => 1,
    ];
    $insertUser = $pdo->prepare(
        'INSERT INTO users (LOGIN, PASSWORD, LEVEL, FULL_NAME, STATUS, ACTIVE, EMAIL) VALUES (?, ?, ?, ?, \'Y\', \'Y\', ?)'
    );
    foreach ($logins as $key => $login) {
        $insertUser->execute([$login, $hash, $levels[$key], $ns . ' ' . $key, $login . '@test.local']);
    }
    $userIds = [];
    foreach ($logins as $key => $login) {
        $stmt = $pdo->prepare('SELECT ID FROM users WHERE LOGIN = ? LIMIT 1');
        $stmt->execute([$login]);
        $userIds[$key] = (int) $stmt->fetchColumn();
    }

    $pdo->prepare('INSERT INTO stocks (SID, NAME, NAME_1C, STATUS, LEVEL) VALUES (?, ?, ?, \'Y\', ?)')
        ->execute([$ns . '-ws', $ns . ' warehouse', $ns . ' warehouse', '{90},{50},{10},{5},{1},']);
    $pdo->prepare('INSERT INTO stock_levels (STOCK_SID, NAME, NAME_SID, UNIT, QUANTITY) VALUES (?, ?, ?, ?, ?)')
        ->execute([$ns . '-ws', $ns . ' item', $ns . '-item', 'pcs', 10.00000]);
    $levelStmt = $pdo->prepare('SELECT ID FROM stock_levels WHERE STOCK_SID = ? LIMIT 1');
    $levelStmt->execute([$ns . '-ws']);
    $levelId = (int) $levelStmt->fetchColumn();

    $numbers = [
        'S90' => $ns . '-S90', 'S50' => $ns . '-S50', 'S10' => $ns . '-S10',
        'S10NC' => $ns . '-S10NC', 'OWNER' => $ns . '-OWNER', 'OWNER2' => $ns . '-OWNER2',
        'NONOWNER' => $ns . '-NONOWNER', 'GUEST' => $ns . '-GUEST', 'INVALID' => $ns . '-INVALID',
    ];
    $insertRequest = $pdo->prepare(
        'INSERT INTO requests (number, subject, body, client_id, manager_id, status_id, priority, version) VALUES (?, ?, ?, ?, ?, \'new\', 2, 1)'
    );
    $fixtures = [
        'S90' => [$userIds['client'], $userIds['manager']],
        'S50' => [$userIds['client'], $userIds['manager']],
        'S10' => [$userIds['client'], $userIds['manager']],
        'S10NC' => [$userIds['client'], $userIds['nocap']],
        'OWNER' => [$userIds['client'], null],
        'OWNER2' => [$userIds['client'], null],
        'NONOWNER' => [$userIds['client2'], null],
        'GUEST' => [$userIds['client2'], null],
        'INVALID' => [$userIds['client'], $userIds['manager']],
    ];
    foreach ($fixtures as $key => [$owner, $manager]) {
        $insertRequest->execute([$numbers[$key], $ns . ' subject', $ns . ' body', $owner, $manager]);
    }
    $insertReservation = $pdo->prepare(
        'INSERT INTO stock_reservations (request_id, stock_level_id, quantity) VALUES (?, ?, ?)'
    );
    foreach (['OWNER' => 2.0, 'OWNER2' => 3.0] as $key => $qty) {
        $insertReservation->execute([requestState($pdo, $numbers[$key])['id'], $levelId, $qty]);
    }

    check($count('SELECT COUNT(*) FROM users WHERE LOGIN LIKE ?', [$ns . '%']) === 7,
        'self-provisioning created 7 test users', $failures);
    check($count('SELECT COUNT(*) FROM requests WHERE number LIKE ?', [$ns . '-%']) === 9,
        'self-provisioning created 9 test requests', $failures);
    check($count('SELECT COUNT(*) FROM stock_reservations WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE ?)',
        [$ns . '-%']) === 2, 'self-provisioning created 2 test reservations', $failures);

    $tokens = [];
    foreach ($logins as $key => $login) {
        $tokens[$key] = login($base, $login, $password);
    }

    echo "=== STAFF WITH CAPABILITY ===\n";
    foreach ([['admin', 'LEVEL90', 'S90'], ['admin50', 'LEVEL50', 'S50'], ['manager', 'LEVEL10', 'S10']]
             as [$key, $role, $numberKey]) {
        $before = requestState($pdo, $numbers[$numberKey]);
        [$status] = transition($base, $tokens[$key], $before['id'], 'in_progress', $before['version']);
        $after = requestState($pdo, $numbers[$numberKey]);
        check($status === 200 && $after['status'] === 'in_progress' && $after['version'] === $before['version'] + 1,
            $role . ' with capability: HTTP 200 and status/version updated (' . $numberKey . ')', $failures);
    }

    echo "=== STAFF WITHOUT CAPABILITY ===\n";
    $pdo->prepare('DELETE FROM level_capabilities WHERE level = ? AND capability_code = ?')
        ->execute([10, 'requests.transition']);
    try {
        $before = requestState($pdo, $numbers['S10NC']);
        [$status, $json] = transition($base, $tokens['nocap'], $before['id'], 'in_progress', $before['version']);
        $after = requestState($pdo, $numbers['S10NC']);
        check($status === 403 && ($json['error']['code'] ?? '') === 'forbidden',
            'LEVEL10 without capability: direct POST returns 403', $failures);
        check($after['status'] === $before['status'] && $after['version'] === $before['version']
            && $after['reservations'] === $before['reservations'],
            '403 left status, version and reservations unchanged', $failures);
    } finally {
        $pdo->prepare('INSERT IGNORE INTO level_capabilities (level, capability_code) VALUES (?, ?)')
            ->execute([10, 'requests.transition']);
    }

    echo "=== CLIENT OWNER / NON-OWNER / GUEST ===\n";
    $ownerBefore = requestState($pdo, $numbers['OWNER']);
    check($ownerBefore['reservations'] === 1, 'fixture: owner request has an active reservation', $failures);
    [$status] = transition($base, $tokens['client'], $ownerBefore['id'], 'canceled', $ownerBefore['version']);
    $ownerAfter = requestState($pdo, $numbers['OWNER']);
    check($status === 200 && $ownerAfter['status'] === 'canceled' && $ownerAfter['reservations'] === 0,
        'LEVEL5 owner: cancel without capability succeeds and releases reservation', $failures);
    [$repeatStatus] = transition($base, $tokens['client'], $ownerAfter['id'], 'canceled', $ownerAfter['version']);
    $ownerRepeat = requestState($pdo, $numbers['OWNER']);
    check($repeatStatus === 403 && $ownerRepeat['reservations'] === 0,
        'repeat cancel returns 403 (final status) and does not release twice', $failures);

    foreach (['resolved', 'closed'] as $target) {
        $before = requestState($pdo, $numbers['OWNER2']);
        [$status] = transition($base, $tokens['client'], $before['id'], $target, $before['version']);
        $after = requestState($pdo, $numbers['OWNER2']);
        check($status === 403 && $after['status'] === $before['status'] && $after['version'] === $before['version'],
            'LEVEL5 owner cannot ' . $target . ' (403, state unchanged)', $failures);
    }

    $nonOwnerBefore = requestState($pdo, $numbers['OWNER']);
    [$status] = transition($base, $tokens['client2'], $nonOwnerBefore['id'], 'canceled', $nonOwnerBefore['version']);
    check($status === 403, 'LEVEL5 non-owner cannot cancel someone else\'s request (403)', $failures);

    $guestBefore = requestState($pdo, $numbers['GUEST']);
    [$status] = transition($base, $tokens['guest'], $guestBefore['id'], 'canceled', $guestBefore['version']);
    check($status === 403, 'LEVEL1 guest cannot transition (403)', $failures);

    echo "=== PRESERVED BEHAVIOUR ===\n";
    $invalidBefore = requestState($pdo, $numbers['INVALID']);
    [$status, $json] = transition($base, $tokens['manager'], $invalidBefore['id'], 'resolved', $invalidBefore['version']);
    check($status === 422 && ($json['error']['code'] ?? '') === 'invalid_transition',
        'invalid transition still returns 422 invalid_transition', $failures);

    $sameBefore = requestState($pdo, $numbers['S90']);
    [$status] = transition($base, $tokens['admin'], $sameBefore['id'], 'in_progress', $sameBefore['version']);
    check($status === 422, 'repeated same-status transition still returns 422', $failures);

    $staleBefore = requestState($pdo, $numbers['S50']);
    [$status, $json] = transition($base, $tokens['admin50'], $staleBefore['id'], 'waiting', 999999);
    check($status === 409 && ($json['error']['code'] ?? '') === 'version_conflict',
        'stale version still returns 409 version_conflict', $failures);
} catch (Throwable $exception) {
    $primaryError = $exception;
} finally {
    $cleanup();
}

if ($primaryError !== null) {
    fwrite(STDERR, 'TEST ERROR: ' . $primaryError->getMessage() . "\n");
}
foreach ($cleanupErrors as $error) {
    fwrite(STDERR, 'CLEANUP ERROR: ' . $error . "\n");
}

echo "=== CLEANUP VERIFICATION ===\n";
check($count('SELECT COUNT(*) FROM users WHERE LOGIN LIKE ?', [$ns . '%']) === 0,
    'cleanup removed test users', $failures);
check($count('SELECT COUNT(*) FROM requests WHERE number LIKE ?', [$ns . '-%']) === 0,
    'cleanup removed test requests', $failures);
check($count('SELECT COUNT(*) FROM stock_levels WHERE STOCK_SID = ?', [$ns . '-ws']) === 0,
    'cleanup removed test stock level', $failures);
$capAfter = $pdo->prepare('SELECT COUNT(*) FROM level_capabilities WHERE level = ? AND capability_code = ?');
$capAfter->execute([10, 'requests.transition']);
check(((int) $capAfter->fetchColumn() > 0) === $level10CapOriginal,
    'level 10 capability restored to original state', $failures);
check($cleanupErrors === [], 'cleanup completed without errors', $failures);

if ($primaryError !== null || $failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "\nALL OK\n";
