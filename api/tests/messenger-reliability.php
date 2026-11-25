<?php

declare(strict_types=1);

require_once __DIR__ . '/support/mock-http.php';

use App\Controllers\MessengerWebhookController;
use App\Core\Database;
use App\Http\Request;
use App\Messaging\HttpResponse;
use App\Messaging\ChannelRegistry;
use App\Messaging\MaxProvider;
use App\Messaging\MessengerLinkService;
use App\Messaging\MessengerQueue;
use App\Messaging\MessengerQueueService;
use App\Messaging\MessengerRateLimiter;
use App\Messaging\MessengerSettingsService;
use App\Messaging\MessengerWebhookService;
use App\Messaging\NotificationMessage;
use App\Messaging\TelegramProvider;
use App\Notifications\NotificationService;
use App\Repositories\EmailQueueRepository;
use App\Repositories\MessengerLinkRepository;
use App\Repositories\MessengerSettingsRepository;
use App\Requests\RequestService;

/** Disposable-MariaDB regression for Stage 3.4.283 reliability fixes. */
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

final class ThrowingQueue implements MessengerQueue
{
    public function enqueue(int $userId, string $channel, NotificationMessage $message): bool
    {
        throw new RuntimeException('secret-marker-' . $message->body);
    }

    public function processQueue(int $limit = 20): array
    {
        return [];
    }
}

final class SlowFailingMaxTransport implements App\Messaging\HttpTransport
{
    public function __construct(private readonly MockHttpTransport $inner)
    {
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 10
    ): HttpResponse {
        if (str_contains($url, 'platform-api2.max.ru')) {
            usleep(150000);
            $this->inner->push(500, ['ok' => false]);
        }

        return $this->inner->request($method, $url, $headers, $body, $timeoutSeconds);
    }
}

$cleanup = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM audit_log WHERE entity=\'messenger\'');
    $pdo->exec('DELETE FROM messenger_webhook_events');
    $pdo->exec('DELETE FROM messenger_bind_codes');
    $pdo->exec('DELETE FROM notifications WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE \'N283-%\')');
    $pdo->exec('DELETE FROM user_messenger_links WHERE user_id IN (340,341,342,343)');
    $pdo->exec('DELETE FROM email_queue WHERE to_email IN (\'9301\',\'9302\',\'9303\',\'9304\')');
    $pdo->exec("UPDATE messenger_channels SET enabled=0, token_encrypted=NULL,
        webhook_secret_encrypted=NULL, bot_id=NULL, bot_name=NULL,
        last_check_status=NULL, last_check_error=NULL, last_check_at=NULL WHERE channel IN ('max','telegram')");
    $pdo->exec('DELETE FROM notification_settings WHERE user_id IN (340,341,342,343)');
    $pdo->exec('DELETE FROM requests WHERE number LIKE \'N283-%\'');
    $pdo->exec('DELETE FROM users WHERE ID IN (340,341,342,343)');
};
$cleanup();

$storage = sys_get_temp_dir() . '/crm7-messenger-rel-' . bin2hex(random_bytes(6));
if (!mkdir($storage, 0775, true) && !is_dir($storage)) {
    guardFail('cannot create temp dir');
}
$logFile = $storage . '/errors.log';
ini_set('error_log', $logFile);

$pdo->exec("INSERT INTO users (ID,SID,LOGIN,PASSWORD,LEVEL,FULL_NAME,EMAIL,ACTIVE,STATUS,reg_state) VALUES
    (340,'N283-A','n283a','x',50,'N283 A','n283a@example.test','Y','Y','active'),
    (341,'N283-B','n283b','x',50,'N283 B','n283b@example.test','Y','Y','active'),
    (342,'N283-C','n283c','x',50,'N283 C','n283c@example.test','Y','Y','active'),
    (343,'N283-D','n283d','x',50,'N283 D','n283d@example.test','Y','Y','active')");
$pdo->exec("INSERT INTO requests (number,subject,client_id,manager_id,status_id,version)
            VALUES ('N283-REQ','N283 request',342,341,'new',1)");
$requestId = (int) $pdo->query("SELECT ID FROM requests WHERE number='N283-REQ'")->fetchColumn();

$links = new MessengerLinkRepository();
$links->saveLink(340, 'max', '9301', '9301');
$links->saveLink(340, 'telegram', '9302', '9302');
$links->saveLink(341, 'telegram', '9303', '9303');
$pdo->exec("UPDATE messenger_channels SET enabled=1 WHERE channel IN ('max','telegram')");
$pdo->exec("INSERT INTO notification_settings (user_id,event_code,in_app,email,max,telegram) VALUES
    (340,'request.comment',1,0,1,1)
    ON DUPLICATE KEY UPDATE in_app=VALUES(in_app), email=VALUES(email), max=VALUES(max), telegram=VALUES(telegram)");

$queueRepo = new EmailQueueRepository();
$maxHttp = new MockHttpTransport();
$tgHttp = new MockHttpTransport();
$registry = new ChannelRegistry([
    new MaxProvider(new MessengerSettingsRepository(), $maxHttp),
    new TelegramProvider(new MessengerSettingsRepository(), $tgHttp),
]);
$settings = new MessengerSettingsService(new MessengerSettingsRepository(), $registry);
$settings->save('max', true, 'fake-max-token-for-tests');
$settings->save('telegram', true, 'fake-telegram-token-for-tests');
$queue = new MessengerQueueService($queueRepo, $links, new MessengerSettingsRepository(), $registry, new MessengerRateLimiter($links));

// --- F1: slow/failing MAX does not block Telegram in the same worker run -----

$slowHttp = new SlowFailingMaxTransport($maxHttp);
$slowRegistry = new ChannelRegistry([
    new MaxProvider(new MessengerSettingsRepository(), $slowHttp),
    new TelegramProvider(new MessengerSettingsRepository(), $tgHttp),
]);
$slowQueue = new MessengerQueueService($queueRepo, $links, new MessengerSettingsRepository(), $slowRegistry, new MessengerRateLimiter($links));
$slowQueue->enqueue(340, 'max', new NotificationMessage(340, 'request.comment', 'MAX 1', '', '', 'f1-max-1'));
$slowQueue->enqueue(340, 'max', new NotificationMessage(340, 'request.comment', 'MAX 2', '', '', 'f1-max-2'));
$slowQueue->enqueue(340, 'telegram', new NotificationMessage(340, 'request.comment', 'TG', '', '', 'f1-tg'));
$tgHttp->push(200, ['ok' => true, 'result' => ['message_id' => 10]]);
$result = $slowQueue->processQueue(10);
check($result['sent'] === 1 && $result['failed'] === 2,
    'Telegram is delivered even while MAX is slow and failing', $failures);
check(count($maxHttp->requests) === 2 && count($tgHttp->requests) === 1,
    'both channels are attempted in one worker run', $failures);

// --- F2: retry_after planning -----------------------------------------------

$pdo->exec('DELETE FROM email_queue');
$queue->enqueue(340, 'telegram', new NotificationMessage(340, 'request.comment', 'TG 429', '', '', 'f2-tg'));
$tgHttp->push(429, ['ok' => false, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 120]]);
$queue->processQueue(10);
$row = $pdo->query("SELECT * FROM email_queue WHERE channel='telegram'")->fetch();
check(($row['status'] ?? '') === 'pending' && (int) ($row['attempts'] ?? 0) === 1
    && ($row['last_error'] ?? '') === 'rate_limited',
    'Telegram 429 marks a retryable pending row', $failures);
$delay = strtotime((string) ($row['next_attempt_at'] ?? '')) - time();
check($delay >= 115 && $delay <= 121, 'Telegram retry_after=120 is scheduled', $failures);

$requestsBefore = count($tgHttp->requests);
$tgHttp->push(200, ['ok' => true, 'result' => ['message_id' => 1]]);
check($queue->processQueue(10)['sent'] === 0, 'worker does not retry before next_attempt_at', $failures);
check(count($tgHttp->requests) === $requestsBefore, 'no API call is made before the scheduled time', $failures);
$pdo->exec("UPDATE email_queue SET next_attempt_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE channel='telegram'");
check($queue->processQueue(10)['sent'] === 1, 'row becomes claimable after the scheduled time', $failures);

$pdo->exec('DELETE FROM email_queue');
$queue->enqueue(340, 'max', new NotificationMessage(340, 'request.comment', 'MAX 429 header', '', '', 'f2-max-h'));
$maxHttp->push(429, [], null, ['Retry-After: 30']);
$queue->processQueue(10);
$row = $pdo->query("SELECT * FROM email_queue WHERE channel='max'")->fetch();
$delay = strtotime((string) ($row['next_attempt_at'] ?? '')) - time();
check($delay >= 25 && $delay <= 31, 'MAX Retry-After header is honoured', $failures);

$pdo->exec('DELETE FROM email_queue');
$queue->enqueue(340, 'max', new NotificationMessage(340, 'request.comment', 'MAX 429 unknown', '', '', 'f2-max-u'));
$maxHttp->push(429, []);
$queue->processQueue(10);
$row = $pdo->query("SELECT * FROM email_queue WHERE channel='max'")->fetch();
$delay = strtotime((string) ($row['next_attempt_at'] ?? '')) - time();
check($delay >= 4 && $delay <= 6, 'unknown MAX 429 format uses a safe fallback delay', $failures);

$pdo->exec('DELETE FROM email_queue');
$queue->enqueue(340, 'max', new NotificationMessage(340, 'request.comment', 'MAX exhaust', '', '', 'f2-exhaust'));
for ($i = 0; $i < 3; $i++) {
    $maxHttp->push(429, []);
    $queue->processQueue(10);
    $pdo->exec("UPDATE email_queue SET next_attempt_at = NULL WHERE channel='max' AND status='pending'");
}
$row = $pdo->query("SELECT * FROM email_queue WHERE channel='max'")->fetch();
// Existing queue policy marks the row failed when attempts+1 >= 3.
check(($row['status'] ?? '') === 'failed' && (int) ($row['attempts'] ?? 0) >= 2,
    'exhausted attempts move the row to failed', $failures);
$maxHttp->push(200, ['message' => ['mid' => 'late']]);
check($queue->processQueue(10)['sent'] === 0, 'failed row is never retried by later cron runs', $failures);

// --- F3: real enqueue errors are not swallowed -------------------------------

$pdo->exec('DELETE FROM email_queue');
$duplicate = new NotificationMessage(340, 'request.comment', 'Dup', '', '', 'f3-dup');
check($queue->enqueue(340, 'telegram', $duplicate) === true, 'first enqueue succeeds', $failures);
check($queue->enqueue(340, 'telegram', $duplicate) === false, 'duplicate is an idempotent no-op', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM email_queue WHERE channel='telegram'")->fetchColumn() === 1,
    'duplicate creates no extra queue row', $failures);

$thrown = null;
// Force a deterministic database error on a disposable table.
$pdo->exec('RENAME TABLE email_queue TO email_queue_reliability_bak');
try {
    try {
        $queue->enqueue(340, 'telegram', new NotificationMessage(340, 'request.comment', 'PDO', '', '', 'f3-pdo'));
    } catch (Throwable $e) {
        $thrown = $e;
    }
} finally {
    $pdo->exec('RENAME TABLE email_queue_reliability_bak TO email_queue');
}
check($thrown instanceof PDOException, 'real PDO error propagates from enqueue', $failures);
check($thrown === null || (int) ($thrown->errorInfo[1] ?? 0) !== 1062,
    'PDO error is not misreported as a duplicate', $failures);

// Caller distinguishes duplicate/failure: notification still completes, safe log.
$pdo->exec("INSERT INTO notification_settings (user_id,event_code,in_app,email,max,telegram) VALUES
    (342,'request.comment',1,0,1,1)
    ON DUPLICATE KEY UPDATE in_app=VALUES(in_app), email=VALUES(email), max=VALUES(max), telegram=VALUES(telegram)");
$fake = new ThrowingQueue();
$callerService = new RequestService(notifications: new NotificationService(messengers: $fake));
$secretBody = 'secret-marker-caller';
$callerService->addComment(['ID' => 341, 'LEVEL' => 50], ['notifications.use', 'requests.view.all'], $requestId, [
    'body' => $secretBody,
    'is_internal' => false,
]);
$log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
check(strpos($log, 'messenger enqueue failed') !== false, 'caller logs a fixed failure code', $failures);
check(strpos($log, $secretBody) === false && strpos($log, 'secret-marker') === false,
    'caller log contains no message text or secret', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE request_id=$requestId AND type='request.comment'")->fetchColumn() >= 1,
    'notification is not lost when messenger enqueue fails', $failures);

// --- F4: token removal revokes webhook access for both channels --------------

$settings = new MessengerSettingsService(new MessengerSettingsRepository(), $registry);
$maxSecret = $settings->ensureWebhookSecret('max');
$tgSecret = $settings->ensureWebhookSecret('telegram');
$settingsRepo = new MessengerSettingsRepository();
$settingsRepo->deleteToken('max');
$settingsRepo->deleteToken('telegram');
check($settingsRepo->webhookSecret('max') === null && $settingsRepo->webhookSecret('telegram') === null,
    'token deletion clears webhook secrets', $failures);
$webhookController = new MessengerWebhookController($settingsRepo, new MessengerWebhookService($bindings = new MessengerLinkService($links), $links));
$maxUpdate = ['update_type' => 'bot_started', 'chat_id' => 9301, 'user' => ['user_id' => 9301, 'is_bot' => false], 'payload' => 'ANY'];
$tgUpdate = ['update_id' => 99001, 'message' => ['text' => '/start ANY', 'from' => ['id' => 9302, 'is_bot' => false], 'chat' => ['id' => 9302]]];
$maxDenied = $webhookController->max(new Request('POST', '/webhooks/max', [], $maxUpdate, ['x-max-bot-api-secret' => (string) $maxSecret], '127.0.0.1'));
$tgDenied = $webhookController->telegram(new Request('POST', '/webhooks/telegram', [], $tgUpdate, ['x-telegram-bot-api-secret-token' => (string) $tgSecret], '127.0.0.1'));
check($maxDenied->status === 403 && $tgDenied->status === 403,
    'old webhook secrets are rejected after token removal (MAX and Telegram)', $failures);

// --- F5: conflicting external ID is a visible failure ------------------------

$pdo->exec("UPDATE messenger_channels SET enabled=1 WHERE channel='telegram'");
$bindings = new MessengerLinkService($links);
$codeB = (string) ($bindings->startBind(341, 'telegram')['code'] ?? '');
$conflictUpdate = [
    'update_id' => 99002,
    'message' => [
        'text' => '/start ' . $codeB,
        'from' => ['id' => 9302, 'is_bot' => false],
        'chat' => ['id' => 9302],
    ],
];
$webhooks = new MessengerWebhookService($bindings, $links);
check($webhooks->handleTelegram($conflictUpdate) === false,
    'binding to another user\'s external ID fails explicitly', $failures);
$codeHash = hash('sha256', $codeB);
$codeRow = $pdo->query("SELECT * FROM messenger_bind_codes WHERE code_hash='$codeHash'")->fetch();
check(($codeRow['used_at'] ?? null) === null, 'bind code is not consumed on conflict', $failures);
$ownerLink = $links->find(340, 'telegram');
check($ownerLink !== null && (string) $ownerLink['external_id'] === '9302',
    'existing foreign link is unchanged', $failures);

$codeFree = (string) ($bindings->startBind(341, 'telegram')['code'] ?? '');
$freeUpdate = [
    'update_id' => 99003,
    'message' => [
        'text' => '/start ' . $codeFree,
        'from' => ['id' => 9304, 'is_bot' => false],
        'chat' => ['id' => 9304],
    ],
];
check($webhooks->handleTelegram($freeUpdate) === true, 'retry with a free external ID succeeds', $failures);
$newLink = $links->find(341, 'telegram');
check($newLink !== null && (string) $newLink['external_id'] === '9304', 'new link belongs to the correct user', $failures);

$codeC = (string) ($bindings->startBind(342, 'telegram')['code'] ?? '');
$takeoverUpdate = [
    'update_id' => 99004,
    'message' => [
        'text' => '/start ' . $codeC,
        'from' => ['id' => 9304, 'is_bot' => false],
        'chat' => ['id' => 9304],
    ],
];
check($webhooks->handleTelegram($takeoverUpdate) === false, 'concurrent takeover of a foreign link is rejected', $failures);

ini_restore('error_log');
if (is_dir($storage)) {
    @unlink($logFile);
    @rmdir($storage);
}
$cleanup();

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "messenger-reliability: OK\n";
