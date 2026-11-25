<?php

declare(strict_types=1);

require_once __DIR__ . '/support/mock-http.php';

use App\Controllers\MessengerWebhookController;
use App\Core\Database;
use App\Http\Request;
use App\Messaging\ChannelRegistry;
use App\Messaging\MessengerLinkService;
use App\Messaging\MessengerQueueService;
use App\Messaging\MessengerSettingsService;
use App\Messaging\MessengerWebhookService;
use App\Messaging\NotificationMessage;
use App\Messaging\TelegramProvider;
use App\Repositories\MessengerLinkRepository;
use App\Repositories\MessengerSettingsRepository;
use App\Support\Crypto;

/** Disposable-MariaDB regression for Telegram provider via mock HTTP only. */
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
    $pdo->exec('DELETE FROM messenger_webhook_events');
    $pdo->exec('DELETE FROM messenger_bind_codes');
    $pdo->exec('DELETE FROM user_messenger_links WHERE user_id IN (330,331)');
    $pdo->exec("UPDATE messenger_channels SET enabled=0, token_encrypted=NULL,
        webhook_secret_encrypted=NULL, bot_id=NULL, bot_name=NULL,
        last_check_status=NULL, last_check_error=NULL, last_check_at=NULL WHERE channel='telegram'");
    $pdo->exec('DELETE FROM notification_settings WHERE user_id IN (330,331)');
    $pdo->exec('DELETE FROM email_queue WHERE to_email IN (\'9101\',\'9102\')');
    $pdo->exec('DELETE FROM users WHERE ID IN (330,331)');
};
$cleanup();
$pdo->exec("INSERT INTO users (ID,SID,LOGIN,PASSWORD,LEVEL,FULL_NAME,EMAIL,ACTIVE,STATUS,reg_state) VALUES
    (330,'N282-TGUSER','n282tguser','x',50,'N282 TG User','n282tguser@example.test','Y','Y','active'),
    (331,'N282-TGUSER2','n282tguser2','x',50,'N282 TG User 2','n282tguser2@example.test','Y','Y','active')");

$token = 'telegram-token-' . bin2hex(random_bytes(6));
$http = new MockHttpTransport();
$provider = new TelegramProvider(new MessengerSettingsRepository(), $http);
$registry = new ChannelRegistry([$provider]);
$settings = new MessengerSettingsService(new MessengerSettingsRepository(), $registry);
$settings->save('telegram', true, $token);

// 1. getMe uses the Bot API path and returns normalized status.
$http->push(200, ['ok' => true, 'result' => ['id' => 4242, 'username' => 'crm7_bot', 'is_bot' => true]]);
$status = $settings->check('telegram');
check($status !== null && $status->connected && $status->botId === '4242' && $status->botName === '@crm7_bot',
    'getMe returns normalized bot status', $failures);
$me = end($http->requests);
check(strpos($me['url'], '/bot' . $token . '/getMe') !== false && strpos($me['url'], '?') === false,
    'token is in path, not query string', $failures);

// 2. sendMessage payload.
$message = new NotificationMessage(330, 'request.comment', 'Комментарий', 'Текст', '', 'evt-tg-1');
$http->push(200, ['ok' => true, 'result' => ['message_id' => 55]]);
$result = $provider->send('9101', $message);
check($result->ok() && $result->providerId === '55', 'sendMessage result is normalized', $failures);
$sent = end($http->requests);
$payload = json_decode((string) $sent['body'], true);
check($sent['method'] === 'POST' && str_ends_with($sent['url'], '/sendMessage')
    && ($payload['chat_id'] ?? '') === '9101' && strpos((string) ($payload['text'] ?? ''), 'Текст') !== false,
    'sendMessage sends chat_id and text', $failures);

// 3. API and network errors.
$http->push(401, ['ok' => false, 'description' => 'Unauthorized']);
check($provider->send('9101', $message)->errorCode === 'auth_invalid',
    'Unauthorized maps to auth_invalid', $failures);
$http->push(0, '', 'transport_error');
check($provider->send('9101', $message)->errorCode === 'network_error',
    'network failure maps to retryable network_error', $failures);
$http->push(429, ['ok' => false, 'parameters' => ['retry_after' => 7]]);
$rate = $provider->send('9101', $message);
check($rate->errorCode === 'rate_limited' && $rate->retryAfterSeconds === 7,
    '429 exposes retry_after', $failures);

// 4. setWebhook carries the secret token.
$http->push(200, ['ok' => true, 'result' => true]);
check($provider->setWebhook('https://crm.test/webhooks/telegram', 'tg-secret-123') === true,
    'setWebhook succeeds with mock response', $failures);
$hook = end($http->requests);
$hookBody = json_decode((string) $hook['body'], true);
check(str_ends_with($hook['url'], '/setWebhook') && ($hookBody['secret_token'] ?? '') === 'tg-secret-123'
    && strpos($hook['url'], $token) !== false,
    'setWebhook sends secret_token in body', $failures);

// 5. Webhook /start binding, duplicate protection and secret check.
$links = new MessengerLinkRepository();
$bindings = new MessengerLinkService($links);
$bind = $bindings->startBind(330, 'telegram');
$code = (string) ($bind['code'] ?? '');
$update = [
    'update_id' => 99123,
    'message' => [
        'text' => '/start ' . $code,
        'from' => ['id' => 9101, 'is_bot' => false],
        'chat' => ['id' => 9101],
    ],
];
$webhooks = new MessengerWebhookService($bindings, $links);
$webhooks->handleTelegram($update);
$webhooks->handleTelegram($update);
$link = $links->find(330, 'telegram');
check($link !== null && (string) $link['external_id'] === '9101', 'Telegram account bound via /start', $failures);
check((int) $pdo->query("SELECT COUNT(*) FROM messenger_webhook_events WHERE provider='telegram'")->fetchColumn() === 1,
    'duplicate update_id is ignored', $failures);

$controller = new MessengerWebhookController(new MessengerSettingsRepository(), $webhooks);
$rejected = $controller->telegram(new Request('POST', '/webhooks/telegram', [], $update, [], '127.0.0.1'));
check($rejected->status === 403, 'Telegram webhook requires secret header', $failures);

// 6. Queue retry / idempotency with mock transport.
$pdo->exec("INSERT INTO notification_settings (user_id,event_code,in_app,email,max,telegram) VALUES
    (330,'request.comment',1,0,0,1)
    ON DUPLICATE KEY UPDATE in_app=VALUES(in_app), email=VALUES(email), max=VALUES(max), telegram=VALUES(telegram)");
$queue = new MessengerQueueService(new App\Repositories\EmailQueueRepository(), $links, new MessengerSettingsRepository(), $registry);
check($queue->enqueue(330, 'telegram', $message) === true, 'notification queued for Telegram', $failures);
check($queue->enqueue(330, 'telegram', $message) === false, 'Telegram event is not queued twice', $failures);
$http->push(0, '', 'transport_error');
check($queue->processQueue(10)['failed'] === 1, 'temporary Telegram error is retryable', $failures);
$pdo->exec("UPDATE email_queue SET next_attempt_at = NULL WHERE channel='telegram' AND status='pending'");
$http->push(200, ['ok' => true, 'result' => ['message_id' => 56]]);
check($queue->processQueue(10)['sent'] === 1, 'Telegram retry delivers', $failures);

// 7. No token in logs or payloads.
$logPayload = json_encode([$status->toArray(), $result, $rate], JSON_UNESCAPED_UNICODE);
check(strpos((string) $logPayload, $token) === false, 'normalized results contain no token', $failures);
check(Crypto::decrypt((string) $pdo->query("SELECT token_encrypted FROM messenger_channels WHERE channel='telegram'")->fetchColumn()) === $token,
    'Telegram token remains encrypted', $failures);

$cleanup();

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "telegram-provider-mock: OK\n";
