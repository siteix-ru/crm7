<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Database;
use App\Repositories\SettingsRepository;
use App\Requests\RequestService;
use App\Support\Crypto;

/** Disposable-MariaDB regression for request.comment notifications (3.4.280). */
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
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sentinel) !== 1) {
    guardFail('CRM7_TEST_DB_SENTINEL must be UUID v4');
}
if (strpos($dsn, 'mysql:') !== 0) {
    guardFail('DSN must be a mysql DSN');
}
$parts = [];
foreach (explode(';', substr($dsn, strlen('mysql:'))) as $item) {
    $pair = explode('=', $item, 2);
    if (count($pair) === 2) {
        $parts[$pair[0]] = $pair[1];
    }
}
$host = strtolower((string) ($parts['host'] ?? ''));
$dbName = (string) ($parts['dbname'] ?? '');
$dsnPort = (string) ($parts['port'] ?? '');
if (!in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
    guardFail('DB host must be loopback');
}
if (preg_match('/^crm_[a-z0-9]{1,20}_test$/', $dbName) !== 1) {
    guardFail('DB name is not disposable');
}
$forbidden = ['crm', 'crm7', 'crm_local', 'crm_prod', 'crm_production', 'crm_k', 'crm_demo',
    'crm_test', 'crm7_test', 'crm7_k', 'crm7_demo'];
if (in_array(strtolower($dbName), $forbidden, true)) {
    guardFail('DB name is forbidden');
}
if (!ctype_digit($dsnPort) || $dsnPort !== $dbPort) {
    guardFail('DSN port mismatch');
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
    $pdo->exec('DELETE FROM notifications WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE \'N280-%\')');
    $pdo->exec('DELETE FROM request_comments WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE \'N280-%\')');
    $pdo->exec('DELETE FROM notification_settings WHERE user_id IN (300,301,302,303)');
    $pdo->exec("DELETE FROM email_queue WHERE to_email IN (
        'n280actor@example.test','n280manager@example.test',
        'n280client@example.test','n280client2@example.test')");
    $pdo->exec('DELETE FROM requests WHERE number LIKE \'N280-%\'');
    $pdo->exec('DELETE FROM users WHERE SID LIKE \'N280-%\' OR ID IN (300,301,302,303)');
    $pdo->exec('DELETE FROM request_statuses WHERE code LIKE \'n280%\'');
};
$cleanup();

$settings = new SettingsRepository();
$mailOriginal = [
    'mail.enabled' => $settings->get('mail.enabled'),
    'mail.host' => $settings->get('mail.host'),
    'mail.port' => $settings->get('mail.port'),
    'mail.username' => $settings->get('mail.username'),
    'mail.password' => $settings->get('mail.password'),
    'mail.from' => $settings->get('mail.from'),
];
// Queue-only mail configuration: processQueue() is never called, so no real
// SMTP connection is made.
$settings->set('mail.enabled', '1');
$settings->set('mail.host', 'smtp.n280.test');
$settings->set('mail.port', '465');
$settings->set('mail.username', 'n280@example.test');
$settings->set('mail.password', Crypto::encrypt('not-a-real-secret'));
$settings->set('mail.from', 'crm@n280.test');

$pdo->exec("INSERT INTO request_statuses (code,title,sort,is_final) VALUES ('n280new','N280 New',10,0)");
$pdo->exec("INSERT INTO users (ID,SID,LOGIN,PASSWORD,LEVEL,FULL_NAME,EMAIL,ACTIVE,STATUS,reg_state) VALUES
    (300,'N280-ACTOR','n280actor','x',90,'N280 Actor','n280actor@example.test','Y','Y','active'),
    (301,'N280-MANAGER','n280manager','x',10,'N280 Manager','n280manager@example.test','Y','Y','active'),
    (302,'N280-CLIENT','n280client','x',5,'N280 Client','n280client@example.test','Y','Y','active'),
    (303,'N280-CLIENT2','n280client2','x',5,'N280 Client 2','n280client2@example.test','Y','Y','active')");

$newRequest = static function (string $number, int $clientId, ?int $managerId) use ($pdo): int {
    $manager = $managerId === null ? 'NULL' : (string) $managerId;
    $pdo->exec("INSERT INTO requests (number,subject,client_id,manager_id,status_id,version)
                VALUES ('$number','N280 request',$clientId,$manager,'n280new',1)");

    return (int) $pdo->query("SELECT ID FROM requests WHERE number='$number'")->fetchColumn();
};
$requestId = $newRequest('N280-REQ-1', 302, 301);
$requestIdNoManager = $newRequest('N280-REQ-2', 303, null);

$countNotifications = static function (int $userId, int $requestId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND request_id=? AND type=\'request.comment\'');

    return (int) (($stmt->execute([$userId, $requestId])) ? $stmt->fetchColumn() : -1);
};
$countQueued = static function (string $to): int {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM email_queue WHERE to_email=? AND subject LIKE \'%Комментарий%\'');
    $stmt->execute([$to]);

    return (int) $stmt->fetchColumn();
};

$service = new RequestService();
$actor = ['ID' => 300, 'SID' => 'N280-ACTOR', 'LEVEL' => 90, 'LOGIN' => 'n280actor'];
$capabilities = ['notifications.use', 'requests.view.all', 'requests.transition', 'stocks.edit'];

// 1. External comment: manager and client are notified; the actor is not.
$service->addComment($actor, $capabilities, $requestId, [
    'body' => 'Внешний комментарий для клиента',
    'is_internal' => false,
]);
check($countNotifications(301, $requestId) === 1, 'external comment notifies manager', $failures);
check($countNotifications(302, $requestId) === 1, 'external comment notifies client', $failures);
check($countNotifications(300, $requestId) === 0, 'actor is not notified', $failures);
check($countQueued('n280manager@example.test') === 1, 'external comment queues email for manager', $failures);
check($countQueued('n280client@example.test') === 1, 'external comment queues email for client', $failures);
check($countQueued('n280actor@example.test') === 0, 'actor receives no email', $failures);

// 2. Internal comment: the client is not notified, the manager still is.
$service->addComment($actor, $capabilities, $requestId, [
    'body' => 'Внутренний комментарий команды',
    'is_internal' => true,
]);
check($countNotifications(301, $requestId) === 2, 'internal comment notifies manager', $failures);
check($countNotifications(302, $requestId) === 1, 'internal comment does not notify client', $failures);
check($countQueued('n280client@example.test') === 1, 'internal comment does not queue client email', $failures);
check($countQueued('n280manager@example.test') === 2, 'internal comment queues manager email', $failures);

// 3. Disabled settings suppress the channel for that recipient only.
$pdo->exec("INSERT INTO notification_settings (user_id,event_code,in_app,email) VALUES
    (302,'request.comment',0,0)
    ON DUPLICATE KEY UPDATE in_app=VALUES(in_app), email=VALUES(email)");
$service->addComment($actor, $capabilities, $requestId, [
    'body' => 'Комментарий при выключенных настройках клиента',
    'is_internal' => false,
]);
check($countNotifications(302, $requestId) === 1, 'disabled in_app suppresses client notification', $failures);
check($countQueued('n280client@example.test') === 1, 'disabled email suppresses client email', $failures);
check($countNotifications(301, $requestId) === 3, 'manager still notified', $failures);

// 4. Request without a manager still notifies an external client.
$service->addComment($actor, $capabilities, $requestIdNoManager, [
    'body' => 'Комментарий без менеджера',
    'is_internal' => false,
]);
check($countNotifications(303, $requestIdNoManager) === 1, 'client notified without manager', $failures);

foreach ($mailOriginal as $key => $value) {
    if ($value === null) {
        $stmt = $pdo->prepare('DELETE FROM settings WHERE `key`=?');
        $stmt->execute([$key]);
    } else {
        $settings->set($key, $value);
    }
}
$cleanup();

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "request-comment-notification: OK\n";
