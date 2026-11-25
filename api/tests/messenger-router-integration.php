<?php

declare(strict_types=1);

require_once __DIR__ . '/support/mock-http.php';

use App\Controllers\AdminMessengerController;
use App\Controllers\MessengerLinkController;
use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Messaging\ChannelRegistry;
use App\Messaging\MaxProvider;
use App\Messaging\MessengerLinkService;
use App\Messaging\MessengerSettingsService;
use App\Messaging\TelegramProvider;
use App\Repositories\MessengerSettingsRepository;

/** Router-level regression for messenger {channel} routes (Stage 3.4.292). */
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
if (strpos($dsn, 'mysql:') !== 0) {
    guardFail('DSN must be a mysql DSN');
}
$pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
try {
    $markerRow = $pdo->query('SELECT marker FROM crm_disposable_sentinel WHERE id = 1')->fetch();
} catch (Throwable $e) {
    guardFail('crm_disposable_sentinel is missing');
}
if ($markerRow === false || !isset($markerRow['marker']) || !hash_equals($sentinel, (string) $markerRow['marker'])) {
    guardFail('sentinel mismatch');
}
(new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $pdo);

$failures = [];
function check(bool $ok, string $name, array &$failures): void
{
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures[] = $name;
    }
}

$channelSnapshot = $pdo->query('SELECT * FROM messenger_channels ORDER BY channel')->fetchAll();
$adminId = 0;
$managerId = 0;
$clientId = 0;
$adminToken = 'r292-admin-token';
$managerToken = 'r292-manager-token';
$clientToken = 'r292-client-token';
$fakeSecret = 'r292-fake-token-' . bin2hex(random_bytes(6));
$logFile = sys_get_temp_dir() . '/crm7-r292-' . bin2hex(random_bytes(5)) . '.log';
ini_set('error_log', $logFile);

$cleanup = static function () use ($pdo, &$adminId, &$managerId, &$clientId, $channelSnapshot, $logFile): void {
    $ids = array_filter([$adminId, $managerId, $clientId]);
    if ($ids !== []) {
        $in = implode(',', $ids);
        $pdo->exec("DELETE FROM user_tokens WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM messenger_bind_codes WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM users WHERE ID IN ($in)");
    }
    $pdo->exec('DELETE FROM messenger_webhook_events WHERE provider IN (\'max\',\'telegram\')');
    foreach ($channelSnapshot as $row) {
        $stmt = $pdo->prepare(
            'UPDATE messenger_channels SET enabled=?, token_encrypted=?, webhook_secret_encrypted=?,
             bot_id=?, bot_name=?, last_check_at=?, last_check_status=?, last_check_error=? WHERE channel=?'
        );
        $stmt->execute([
            $row['enabled'], $row['token_encrypted'], $row['webhook_secret_encrypted'],
            $row['bot_id'], $row['bot_name'], $row['last_check_at'],
            $row['last_check_status'], $row['last_check_error'], $row['channel'],
        ]);
    }
    if (is_file($logFile)) {
        @unlink($logFile);
    }
};
$cleanup();

$hash = password_hash('Secret123', PASSWORD_DEFAULT);
$pdo->exec("INSERT INTO users (ID,SID,LOGIN,PASSWORD,LEVEL,FULL_NAME,EMAIL,ACTIVE,STATUS,reg_state) VALUES
    (NULL,'R292-A','r292admin','$hash',90,'R292 Admin','r292admin@example.test','Y','Y','active'),
    (NULL,'R292-M','r292manager','$hash',10,'R292 Manager','r292manager@example.test','Y','Y','active'),
    (NULL,'R292-C','r292client','$hash',5,'R292 Client','r292client@example.test','Y','Y','active')");
$adminId = (int) $pdo->query("SELECT ID FROM users WHERE LOGIN='r292admin'")->fetchColumn();
$managerId = (int) $pdo->query("SELECT ID FROM users WHERE LOGIN='r292manager'")->fetchColumn();
$clientId = (int) $pdo->query("SELECT ID FROM users WHERE LOGIN='r292client'")->fetchColumn();
foreach ([[$adminId, $adminToken], [$managerId, $managerToken], [$clientId, $clientToken]] as [$uid, $token]) {
    $stmt = $pdo->prepare(
        'INSERT INTO user_tokens (user_id,token_hash,ip,user_agent,expires_at)
         VALUES (?, SHA2(?,256), \'127.0.0.1\', \'r292\', DATE_ADD(NOW(), INTERVAL 1 HOUR))'
    );
    $stmt->execute([$uid, $token]);
}

$http = new MockHttpTransport();
$registry = new ChannelRegistry([
    new MaxProvider(new MessengerSettingsRepository(), $http),
    new TelegramProvider(new MessengerSettingsRepository(), $http),
]);
$settingsRepo = new MessengerSettingsRepository();
$settingsService = new MessengerSettingsService($settingsRepo, $registry);
$adminController = new AdminMessengerController($settingsService, $registry);
$linkController = new MessengerLinkController(new MessengerLinkService());

// Same route registrations as api/public/index.php for the messenger surface.
$router = new Router();
$router->get('/admin/messengers', [$adminController, 'index']);
$router->put('/admin/messengers/{channel}', [$adminController, 'save']);
$router->post('/admin/messengers/{channel}/check', [$adminController, 'check']);
$router->post('/admin/messengers/{channel}/webhook', [$adminController, 'setupWebhook']);
$router->delete('/admin/messengers/{channel}/token', [$adminController, 'deleteToken']);
$router->post('/messengers/{channel}/bind', [$linkController, 'bindStart']);
$router->delete('/messengers/{channel}/link', [$linkController, 'unlink']);

function req(string $method, string $path, string $token = '', array $body = []): Request
{
    $headers = $token !== '' ? ['authorization' => 'Bearer ' . $token] : [];

    return new Request($method, $path, [], $body, $headers, '127.0.0.1');
}

function dispatchSafely(Router $router, Request $request): ?Response
{
    try {
        $result = $router->dispatch($request);
    } catch (HttpException $e) {
        return new Response($e->status, ['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
    } catch (Throwable $e) {
        return new Response(500, ['ok' => false, 'error' => ['code' => 'unexpected', 'message' => get_class($e)]]);
    }

    return $result instanceof Response ? $result : null;
}

// Valid channels through the real Router.
$save = dispatchSafely($router, req('PUT', '/admin/messengers/max', $adminToken, ['enabled' => true, 'token' => $fakeSecret]));
check($save !== null && $save->status === 200, 'PUT /admin/messengers/max returns 200 via Router', $failures);
$checkMax = dispatchSafely($router, req('POST', '/admin/messengers/max/check', $adminToken));
check($checkMax !== null && $checkMax->status === 200,
    'POST /admin/messengers/max/check returns 200 via Router', $failures);
$checkTg = dispatchSafely($router, req('POST', '/admin/messengers/telegram/check', $adminToken));
check($checkTg !== null && $checkTg->status === 200,
    'POST /admin/messengers/telegram/check returns 200 via Router', $failures);
$bind = dispatchSafely($router, req('POST', '/messengers/max/bind', $clientToken));
check($bind !== null && $bind->status === 200, 'POST /messengers/max/bind returns 200 via Router', $failures);
$unlink = dispatchSafely($router, req('DELETE', '/messengers/telegram/link', $clientToken));
check($unlink !== null && $unlink->status === 200, 'DELETE /messengers/telegram/link returns 200 via Router', $failures);
$delete = dispatchSafely($router, req('DELETE', '/admin/messengers/max/token', $adminToken, ['confirm' => true]));
check($delete !== null && $delete->status === 200,
    'DELETE /admin/messengers/max/token returns 200 via Router', $failures);

// Unsupported / missing / malformed channel values.
foreach ([
    ['PUT', '/admin/messengers/vkontakte', $adminToken],
    ['POST', '/admin/messengers/vkontakte/check', $adminToken],
    ['DELETE', '/admin/messengers/vkontakte/token', $adminToken],
    ['POST', '/messengers/vkontakte/bind', $clientToken],
    ['DELETE', '/messengers/vkontakte/link', $clientToken],
] as [$method, $path, $token]) {
    $r = dispatchSafely($router, req($method, $path, $token, ['confirm' => true]));
    check($r !== null && $r->status === 404 && ($r->payload['error']['code'] ?? '') === 'not_found',
        "$method $path rejects unsupported channel with 404", $failures);
}
$missing = dispatchSafely($router, req('PUT', '/admin/messengers/', $adminToken));
check($missing === null || $missing->status === 404,
    'missing route parameter never causes HTTP 500', $failures);
$malformed = dispatchSafely($router, req('PUT', '/admin/messengers/array', $adminToken));
check($malformed !== null && $malformed->status === 404,
    'malformed channel parameter is rejected', $failures);
// Direct guard for non-string parameter (Router paths can only be strings).
try {
    $adminController->check(req('POST', '/admin/messengers/max/check', $adminToken), ['channel' => ['max']]);
    check(false, 'array channel parameter throws HttpException', $failures);
} catch (HttpException $e) {
    check($e->status === 404, 'array channel parameter throws HttpException 404', $failures);
}

// Authorization and capabilities still enforced through the Router.
$noAuth = dispatchSafely($router, req('POST', '/admin/messengers/max/check'));
check($noAuth !== null && $noAuth->status === 401, 'no auth is rejected', $failures);
$lowCap = dispatchSafely($router, req('POST', '/admin/messengers/max/check', $managerToken));
check($lowCap !== null && $lowCap->status === 403, 'insufficient capabilities are rejected', $failures);
$clientLow = dispatchSafely($router, req('POST', '/messengers/max/bind', $managerToken));
check($clientLow !== null && $clientLow->status === 200,
    'link routes remain authorized by notifications.use', $failures);

// Mock transport error path: no real network call.
$http->push(500, ['ok' => false]);
$hook = dispatchSafely($router, req(
    'POST',
    '/admin/messengers/max/webhook',
    $adminToken,
    ['url' => 'https://example.test/webhooks/max']
));
check($hook !== null && $hook->status === 200 && ($hook->payload['data']['registered'] ?? null) === false,
    'mock transport failure yields registered=false, not 500', $failures);
check(count($http->requests) === 1, 'only mock transport handled the webhook request', $failures);

// No token leakage in API responses or logs.
$json = json_encode($save->payload, JSON_UNESCAPED_UNICODE);
check(strpos((string) $json, $fakeSecret) === false, 'token never returned in API response', $failures);
$log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
check(strpos($log, $fakeSecret) === false && strpos($log, $adminToken) === false,
    'token never written to logs', $failures);

// Source-level evidence that production routes match the tested registrations.
$indexSource = (string) file_get_contents(__DIR__ . '/../public/index.php');
foreach ([
    "/admin/messengers/{channel}", "/admin/messengers/{channel}/check",
    "/admin/messengers/{channel}/webhook", "/admin/messengers/{channel}/token",
    "/messengers/{channel}/bind", "/messengers/{channel}/link",
] as $route) {
    check(str_contains($indexSource, $route), "production registers $route", $failures);
}

ini_restore('error_log');
$cleanup();

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "messenger-router-integration: OK\n";
