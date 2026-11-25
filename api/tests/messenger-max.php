<?php

declare(strict_types=1);

require_once __DIR__ . '/support/mock-http.php';

use App\Auth\AuthService;
use App\Controllers\AdminMessengerController;
use App\Controllers\MessengerWebhookController;
use App\Core\Database;
use App\Http\Request;
use App\Messaging\ChannelRegistry;
use App\Messaging\MaxProvider;
use App\Messaging\MessengerQueueService;
use App\Messaging\MessengerRateLimiter;
use App\Messaging\MessengerSettingsService;
use App\Messaging\MessengerWebhookService;
use App\Messaging\NotificationMessage;
use App\Repositories\MessengerLinkRepository;
use App\Repositories\MessengerSettingsRepository;
use App\Support\Crypto;

/** Disposable-MariaDB regression for MAX channel (Stage 3.4.282). */
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
    $row = $pdo->query('SELECT marker FROM crm_disposable_sentinel WHERE id = 1')->fetch();
} catch (Throwable $e) {
    guardFail('crm_disposable_sentinel is missing');
}
if ($row === false || !isset($row['marker']) || !hash_equals($sentinel, (string) $row['marker'])) {
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

$cleanup = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM audit_log WHERE entity=\'messenger\'');
    $pdo->exec('DELETE FROM messenger_webhook_events');
    $pdo->exec('DELETE FROM messenger_bind_codes');
    $pdo->exec('DELETE FROM user_messenger_links WHERE user_id IN (320,321,322)');
    $pdo->exec('DELETE FROM email_queue WHERE to_email IN (\'9001\',\'9002\',\'9003\')');
    $pdo->exec("UPDATE messenger_channels SET enabled=0, token_encrypted=NULL,
        webhook_secret_encrypted=NULL, bot_id=NULL, bot_name=NULL,
        last_check_status=NULL, last_check_error=NULL, last_check_at=NULL WHERE channel IN ('max','telegram')");
    $pdo->exec('DELETE FROM notification_settings WHERE user_id IN (320,321,322)');
    $pdo->exec('DELETE FROM user_tokens WHERE user_id IN (320,321,322)');
    $pdo->exec('DELETE FROM users WHERE ID IN (320,321,322)');
};
$cleanup();

// The disposable DB is shared by the whole CI job. Only *new* email rows can
// be attributed to MAX processing; earlier tests may leave their own rows.
$emailQueueBaseline = (int) $pdo->query("SELECT COUNT(*) FROM email_queue WHERE channel='email'")->fetchColumn();

// Earlier tests in the same CI job reset level_capabilities for levels 90/91/5
// and keep only their own grants. Restore exactly the grants this test needs,
// using the same idempotent statements as migration 089.
$capabilitySnapshot = $pdo->query('SELECT level, capability_code FROM level_capabilities')->fetchAll();
$pdo->exec("INSERT IGNORE INTO capabilities (code,title) VALUES ('messengers.manage','Мессенджеры: настройка')");
$pdo->exec("INSERT IGNORE INTO level_capabilities (level,capability_code) VALUES
    (90,'messengers.manage'), (90,'notifications.use'),
    (50,'messengers.manage'), (50,'notifications.use'),
    (10,'notifications.use')");
// The negative test must keep a user without the administrative capability.
$pdo->exec("DELETE FROM level_capabilities WHERE level=10 AND capability_code='messengers.manage'");

$passwordHash = password_hash('Secret123', PASSWORD_DEFAULT);
$pdo->exec("INSERT INTO users (ID,SID,LOGIN,PASSWORD,LEVEL,FULL_NAME,EMAIL,ACTIVE,STATUS,reg_state) VALUES
    (320,'N282-ADMIN','n282admin','$passwordHash',90,'N282 Admin','n282admin@example.test','Y','Y','active'),
    (321,'N282-MANAGER','n282manager','$passwordHash',10,'N282 Manager','n282manager@example.test','Y','Y','active'),
    (322,'N282-USER','n282user','$passwordHash',50,'N282 User','n282user@example.test','Y','Y','active')");

$auth = new AuthService();
$adminToken = $auth->login('n282admin', 'Secret123', '127.0.0.1', 'test')['token'];
$managerToken = $auth->login('n282manager', 'Secret123', '127.0.0.1', 'test')['token'];
$request = static function (string $method, array $body = [], string $token = '', array $headers = []) use ($adminToken): Request {
    $all = ['authorization' => 'Bearer ' . ($token !== '' ? $token : $adminToken)];
    foreach ($headers as $name => $value) {
        $all[strtolower($name)] = $value;
    }

    return new Request($method, '/admin/messengers', [], $body, $all, '127.0.0.1');
};

$http = new MockHttpTransport();
$max = new MaxProvider(new MessengerSettingsRepository(), $http);
$registry = new ChannelRegistry([$max]);
$settings = new MessengerSettingsService(new MessengerSettingsRepository(), $registry);
$controller = new AdminMessengerController($settings, $registry);
$links = new MessengerLinkRepository();

// 1. Token is encrypted at rest and never returned by the API.
$secretToken = 'max-token-' . bin2hex(random_bytes(6));
$response = $controller->save($request('PUT', ['enabled' => true, 'token' => $secretToken]), 'max');
check($response->status === 200, 'token save succeeds', $failures);
$raw = (string) $pdo->query("SELECT token_encrypted FROM messenger_channels WHERE channel='max'")->fetchColumn();
check($raw !== '' && $raw !== $secretToken && Crypto::decrypt($raw) === $secretToken,
    'token is stored encrypted, never plaintext', $failures);
$listing = json_encode($controller->index($request('GET'))->payload, JSON_UNESCAPED_UNICODE);
check(strpos((string) $listing, $secretToken) === false && strpos((string) $listing, 'token_encrypted') === false,
    'admin API does not expose the token', $failures);

// 2. Empty replacement field keeps the token; explicit replace changes it.
$controller->save($request('PUT', ['enabled' => true, 'token' => '']), 'max');
check(Crypto::decrypt((string) $pdo->query("SELECT token_encrypted FROM messenger_channels WHERE channel='max'")->fetchColumn()) === $secretToken,
    'empty token field does not erase the stored token', $failures);
$newToken = 'max-token-replaced-' . bin2hex(random_bytes(6));
$controller->save($request('PUT', ['enabled' => true, 'token' => $newToken]), 'max');
check(Crypto::decrypt((string) $pdo->query("SELECT token_encrypted FROM messenger_channels WHERE channel='max'")->fetchColumn()) === $newToken,
    'token replacement works', $failures);

// 3. Permissions.
$denied = false;
try {
    $controller->save($request('PUT', ['enabled' => true, 'token' => 'x'], $managerToken), 'max');
} catch (App\Http\HttpException $e) {
    $denied = $e->status === 403;
}
check($denied, 'only administrators can change messenger settings', $failures);

// 4. Real GET /me shape against a mock transport, with token in the header only.
$http->push(200, ['user_id' => 777, 'first_name' => 'CRM7 Bot', 'is_bot' => true]);
$status = $settings->check('max');
check($status !== null && $status->connected && $status->botName === 'CRM7 Bot' && $status->botId === '777',
    'MAX checkConnection reads bot info', $failures);
$meRequest = $http->requests[count($http->requests) - 1];
check($meRequest['method'] === 'GET' && str_ends_with($meRequest['url'], '/me')
    && ($meRequest['headers']['Authorization'] ?? '') === $newToken
    && strpos($meRequest['url'], $newToken) === false,
    'token travels in Authorization header, never in URL', $failures);
$audit = (string) ($pdo->query("SELECT data_json FROM audit_log WHERE entity='messenger' ORDER BY ID DESC LIMIT 1")->fetchColumn() ?: '');
check(strpos($audit, $newToken) === false, 'audit log contains no token', $failures);

// 5. Binding via verified webhook event with a one-time code.
$bind = (new App\Messaging\MessengerLinkService($links))->startBind(322, 'max');
$code = (string) ($bind['code'] ?? '');
check($code !== '', 'bind code issued', $failures);
$hashed = (string) $pdo->query('SELECT code_hash FROM messenger_bind_codes ORDER BY created_at DESC LIMIT 1')->fetchColumn();
check($hashed !== '' && $hashed !== $code, 'only code hash is stored', $failures);

$webhooks = new MessengerWebhookService(new App\Messaging\MessengerLinkService($links), $links);
$update = [
    'update_type' => 'bot_started',
    'timestamp' => 1700000000000,
    'chat_id' => 9001,
    'user' => ['user_id' => 9001, 'first_name' => 'Tester', 'is_bot' => false],
    'payload' => $code,
];
$webhooks->handleMax($update);
$webhooks->handleMax($update);
$link = $links->find(322, 'max');
check($link !== null && (string) $link['external_id'] === '9001', 'MAX account bound once', $failures);
$events = (int) $pdo->query("SELECT COUNT(*) FROM messenger_webhook_events WHERE provider='max'")->fetchColumn();
check($events === 1, 'duplicate webhook event is ignored', $failures);

// 6. Queue delivery, retry and idempotency.
$pdo->exec("INSERT INTO notification_settings (user_id,event_code,in_app,email,max,telegram) VALUES
    (322,'request.comment',1,0,1,0)
    ON DUPLICATE KEY UPDATE in_app=VALUES(in_app), email=VALUES(email), max=VALUES(max), telegram=VALUES(telegram)");
$queue = new MessengerQueueService(new App\Repositories\EmailQueueRepository(), $links, new MessengerSettingsRepository(), $registry, new MessengerRateLimiter($links));
$message = new NotificationMessage(322, 'request.comment', 'Комментарий', 'Текст', 'https://crm.test/#/requests/1', 'evt-1');
check($queue->enqueue(322, 'max', $message) === true, 'public notification queued for MAX', $failures);
check($queue->enqueue(322, 'max', $message) === false, 'same event is not queued twice', $failures);

$http->push(429, [], null, ['Retry-After: 1']);
$first = $queue->processQueue(10);
check($first['failed'] === 1, 'temporary MAX error is marked retryable', $failures);
// Simulate elapse of the planned delay without sleeping in the test.
$pdo->exec("UPDATE email_queue SET next_attempt_at = NULL WHERE channel='max' AND status='pending'");
$http->push(200, ['message' => ['mid' => 'mid-1']]);
$second = $queue->processQueue(10);
check($second['sent'] === 1, 'retry after temporary error delivers the message', $failures);
$sendRequest = $http->requests[count($http->requests) - 1];
check(strpos($sendRequest['url'], 'user_id=9001') !== false
    && strpos($sendRequest['url'], $newToken) === false
    && strpos((string) $sendRequest['body'], 'Текст') !== false,
    'MAX message uses linked ID and keeps token out of URL', $failures);

// 7. Rate limiting and disabled channel.
$limiter = new MessengerRateLimiter($links);
$limiter->markSent('max', '9001', 322);
check($limiter->waitSeconds('max', '9001', 322) > 0, 'MAX per-dialog rate limit is enforced', $failures);
$pdo->exec("UPDATE messenger_channels SET enabled=0 WHERE channel='max'");
check($queue->enqueue(322, 'max', new NotificationMessage(322, 'request.comment', 'Другой', '', '', 'evt-2')) === false,
    'disabled channel receives no messages', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM email_queue WHERE channel='email'")->fetchColumn()
    === $emailQueueBaseline,
    'MAX failures create no new email deliveries', $failures);

// 8. Token deletion.
$pdo->exec("UPDATE messenger_channels SET enabled=1 WHERE channel='max'");
$removed = $controller->deleteToken($request('DELETE', ['confirm' => true]), 'max');
check($removed->status === 200, 'token deletion succeeds', $failures);
check($pdo->query("SELECT token_encrypted FROM messenger_channels WHERE channel='max'")->fetchColumn() === null,
    'deleted token is removed from storage', $failures);

// 9. Webhook authenticity.
$webhookController = new MessengerWebhookController(new MessengerSettingsRepository(), $webhooks);
$bad = $webhookController->max(new Request('POST', '/webhooks/max', [], $update, [], '127.0.0.1'));
check($bad->status === 403, 'webhook without secret is rejected', $failures);

// Leave capability grants exactly as they were before this test.
$pdo->exec('DELETE FROM level_capabilities');
$restoreCapability = $pdo->prepare('INSERT IGNORE INTO level_capabilities (level,capability_code) VALUES (?, ?)');
foreach ($capabilitySnapshot as $capability) {
    $restoreCapability->execute([
        (int) $capability['level'],
        (string) $capability['capability_code'],
    ]);
}
$cleanup();

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "messenger-max: OK\n";
