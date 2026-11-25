<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Attachments\AttachmentService;
use App\Attachments\EmailPhotoResolver;
use App\Attachments\EmailPhotoResolverInterface;
use App\Core\Database;
use App\Mail\MailService;
use App\Notifications\NotificationService;
use App\Repositories\EmailQueueRepository;
use App\Repositories\SettingsRepository;
use App\Requests\RequestService;
use App\Support\Crypto;
use App\Support\SmtpMailer;

/** Disposable-MariaDB regression for request photo attachments in queued emails (3.4.281/281B). */
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

final class CountingPhotoResolver implements EmailPhotoResolverInterface
{
    public int $calls = 0;

    public function __construct(private readonly EmailPhotoResolver $inner)
    {
    }

    public function resolve(int $requestId, array $ids = []): string
    {
        $this->calls++;

        return $this->inner->resolve($requestId, $ids);
    }
}

final class ThrowingPhotoResolver implements EmailPhotoResolverInterface
{
    public function __construct(private readonly string $message)
    {
    }

    public function resolve(int $requestId, array $ids = []): string
    {
        throw new RuntimeException($this->message);
    }
}

// All test files live in a disposable directory outside the repository storage.
$storage = sys_get_temp_dir() . '/crm7-email-photos-' . bin2hex(random_bytes(6));
$photoDir = $storage . '/attachments/2026/11';
if (!mkdir($photoDir, 0775, true) && !is_dir($photoDir)) {
    guardFail('cannot create disposable photo directory');
}
putenv('STORAGE_DIR=' . $storage);

$logFile = $storage . '/photo-errors.log';
ini_set('error_log', $logFile);

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAwAB/AF+gAAAAABJRU5ErkJggg==',
    true
);
if ($png === false) {
    guardFail('cannot decode fixture PNG');
}

$cleanup = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM request_activities WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE \'N281-%\')');
    $pdo->exec('DELETE FROM request_attachments WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE \'N281-%\')');
    $pdo->exec('DELETE FROM notifications WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE \'N281-%\')');
    $pdo->exec('DELETE FROM request_comments WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE \'N281-%\')');
    $pdo->exec('DELETE FROM request_history WHERE request_id IN (SELECT ID FROM requests WHERE number LIKE \'N281-%\')');
    $pdo->exec('DELETE FROM notification_settings WHERE user_id IN (310,311,312)');
    $pdo->exec("DELETE FROM email_queue WHERE to_email IN (
        'n281manager@example.test','n281client@example.test','n281actor@example.test')");
    $pdo->exec('DELETE FROM requests WHERE number LIKE \'N281-%\'');
    $pdo->exec('DELETE FROM users WHERE SID LIKE \'N281-%\' OR ID IN (310,311,312)');
};
$removeStorage = static function () use ($storage): void {
    if (!is_dir($storage)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($storage);
};
$cleanup();

$settings = new SettingsRepository();
$mailOriginal = [];
foreach (['mail.enabled', 'mail.host', 'mail.port', 'mail.username', 'mail.password', 'mail.from'] as $key) {
    $mailOriginal[$key] = $settings->get($key);
}
$secret = 'crm7-photo-test-secret-' . bin2hex(random_bytes(6));
$settings->set('mail.enabled', '1');
$settings->set('mail.host', 'smtp.n281.test');
$settings->set('mail.port', '465');
$settings->set('mail.username', 'n281@example.test');
$settings->set('mail.password', Crypto::encrypt($secret));
$settings->set('mail.from', 'crm@n281.test');

$pdo->exec("INSERT INTO users (ID,SID,LOGIN,PASSWORD,LEVEL,FULL_NAME,EMAIL,ACTIVE,STATUS,reg_state) VALUES
    (310,'N281-ACTOR','n281actor','x',90,'N281 Actor','n281actor@example.test','Y','Y','active'),
    (311,'N281-MANAGER','n281manager','x',10,'N281 Manager','n281manager@example.test','Y','Y','active'),
    (312,'N281-CLIENT','n281client','x',5,'N281 Client','n281client@example.test','Y','Y','active')");

$newRequest = static function (string $number, string $status = 'new') use ($pdo): int {
    $pdo->exec("INSERT INTO requests (number,subject,client_id,manager_id,status_id,version)
                VALUES ('$number','N281 request',312,311,'$status',1)");

    return (int) $pdo->query("SELECT ID FROM requests WHERE number='$number'")->fetchColumn();
};
$requestRow = static function (int $id) use ($pdo): array {
    $row = $pdo->query("SELECT * FROM requests WHERE ID=$id")->fetch();

    return is_array($row) ? $row : [];
};

/** Insert an attachment row plus its file, with controllable DB metadata. */
$addAttachment = static function (
    int $requestId,
    string $mime,
    string $bytes,
    ?int $declaredSize = null,
    bool $writeFile = true,
    string $fileName = 'photo.png',
    bool $isInternal = false
) use ($pdo, $photoDir): int {
    $relative = 'attachments/2026/11/' . bin2hex(random_bytes(16)) . '.png';
    if ($writeFile) {
        file_put_contents($photoDir . '/' . basename($relative), $bytes);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO request_attachments
            (request_id, user_id, file_name, storage_path, mime, size, is_internal, `comment`)
         VALUES (?, 310, ?, ?, ?, ?, ?, NULL)'
    );
    $stmt->execute([
        $requestId,
        $fileName,
        $relative,
        $mime,
        $declaredSize ?? strlen($bytes),
        $isInternal ? 1 : 0,
    ]);

    return (int) $pdo->lastInsertId();
};

$payloadsFor = static function (string $to, string $subjectLike) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT ID AS id, attachments_json FROM email_queue
         WHERE to_email=? AND subject LIKE ? ORDER BY ID ASC'
    );
    $stmt->execute([$to, $subjectLike]);

    return $stmt->fetchAll() ?: [];
};
$decode = static function (?string $json): array {
    if ($json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
};
$countNotifications = static function (int $userId, int $requestId, string $type) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND request_id=? AND type=?');
    $stmt->execute([$userId, $requestId, $type]);

    return (int) $stmt->fetchColumn();
};

$service = new RequestService();
$actor = ['ID' => 310, 'SID' => 'N281-ACTOR', 'LEVEL' => 90, 'LOGIN' => 'n281actor'];
$capabilities = ['notifications.use', 'requests.view.all', 'requests.transition', 'stocks.edit'];
$comment = static function (int $requestId, string $body, bool $internal = false) use ($service, $actor, $capabilities): void {
    $service->addComment($actor, $capabilities, $requestId, ['body' => $body, 'is_internal' => $internal]);
};

// --- D3: comments and status changes never attach photos automatically -------

$requestComment = $newRequest('N281-COMMENT');
$addAttachment($requestComment, 'image/png', $png, null, true, 'auto.png');
$comment($requestComment, 'Внешний комментарий');
$commentPayloads = $payloadsFor('n281manager@example.test', '%Комментарий%');
check(count($commentPayloads) === 1, 'external comment queues one email', $failures);
check(count($decode($commentPayloads[0]['attachments_json'] ?? null)) === 0,
    'external comment has no automatic attachment', $failures);

$comment($requestComment, 'Внутренний комментарий', true);
check(count($payloadsFor('n281client@example.test', '%Комментарий%')) === 1,
    'internal comment does not email the client', $failures);
check($countNotifications(312, $requestComment, 'request.comment') === 1,
    'internal comment does not notify the client', $failures);

$requestStatus = $newRequest('N281-STATUS');
$addAttachment($requestStatus, 'image/png', $png, null, true, 'status.png');
$service->transition($actor, $capabilities, $requestStatus, ['to_status' => 'accepted', 'version' => 1]);
$statusPayloads = $payloadsFor('n281manager@example.test', '%Статус%');
check(count($statusPayloads) === 1, 'status change queues one email', $failures);
check(count($decode($statusPayloads[0]['attachments_json'] ?? null)) === 0,
    'status change has no automatic attachment', $failures);

// --- D1: dedicated photo event with the specific ID ---------------------------

$requestPhoto = $newRequest('N281-PHOTO');
$olderId = $addAttachment($requestPhoto, 'image/png', $png, null, true, 'older.png');
$newerId = $addAttachment($requestPhoto, 'image/png', $png, null, true, 'newer.png');
$attachments = new AttachmentService();
$attachments->notifyPhotoAdded($requestRow($requestPhoto), 310, $newerId, 'newer.png', false, 'Описание фото');

$managerPhoto = $payloadsFor('n281manager@example.test', '%Фото%');
$clientPhoto = $payloadsFor('n281client@example.test', '%Фото%');
check(count($managerPhoto) === 1 && count($clientPhoto) === 1,
    'public photo event queues an email for manager and client', $failures);
check(count($payloadsFor('n281actor@example.test', '%Фото%')) === 0,
    'actor is excluded from photo emails', $failures);
$onlyPhoto = $decode($managerPhoto[0]['attachments_json'] ?? null);
check(count($onlyPhoto) === 1 && ($onlyPhoto[0]['file_name'] ?? '') === 'newer.png',
    'photo event attaches only the specified photo, not all request photos', $failures);
check($decode($clientPhoto[0]['attachments_json'] ?? null) === $onlyPhoto,
    'both recipients get the same specific photo payload', $failures);
check($countNotifications(311, $requestPhoto, 'request.photo') === 1
    && $countNotifications(312, $requestPhoto, 'request.photo') === 1
    && $countNotifications(310, $requestPhoto, 'request.photo') === 0,
    'photo event notifies manager and client but not the actor', $failures);
check($countNotifications(311, $requestPhoto, 'request.activity') === 0,
    'photo event does not create a duplicate activity notification', $failures);
check(strpos($managerPhoto[0]['attachments_json'] ?? '', $storage) === false,
    'photo payload contains no internal storage path', $failures);
check(!array_key_exists('storage_path', $onlyPhoto[0] ?? []),
    'photo payload contains no storage_path field', $failures);
check(strpos($managerPhoto[0]['attachments_json'] ?? '', $secret) === false,
    'photo payload contains no SMTP secret', $failures);

// Per-user settings still apply to the new event.
$pdo->exec("INSERT INTO notification_settings (user_id,event_code,in_app,email) VALUES
    (312,'request.photo',0,0)
    ON DUPLICATE KEY UPDATE in_app=VALUES(in_app), email=VALUES(email)");
$attachments->notifyPhotoAdded($requestRow($requestPhoto), 310, $newerId, 'newer.png', false, 'Второе фото');
check(count($payloadsFor('n281client@example.test', '%Фото%')) === 1,
    'disabled email setting suppresses the client photo email', $failures);
check(count($payloadsFor('n281manager@example.test', '%Фото%')) === 2,
    'manager still receives photo emails', $failures);

// Internal files are never emailed, even if someone calls the helper directly.
$requestInternal = $newRequest('N281-INTERNAL');
$internalId = $addAttachment($requestInternal, 'image/png', $png, null, true, 'secret.png', true);
$attachments->notifyPhotoAdded($requestRow($requestInternal), 310, $internalId, 'secret.png', true, 'Внутреннее фото');
check(count($payloadsFor('n281client@example.test', '%Фото%')) === 1,
    'internal photo is not emailed to the client', $failures);
check($countNotifications(312, $requestInternal, 'request.photo') === 0,
    'internal photo creates no client notification', $failures);

// --- D2: MIME filter in SQL before LIMIT --------------------------------------

$requestMimeOrder = $newRequest('N281-MIME-ORDER');
for ($i = 0; $i < 5; $i++) {
    $addAttachment($requestMimeOrder, 'application/pdf', $png, null, true, 'doc-' . $i . '.pdf');
}
$validId = $addAttachment($requestMimeOrder, 'image/png', $png, null, true, 'valid.png');
$mimeOrdered = $decode((new EmailPhotoResolver())->resolve($requestMimeOrder));
check(count($mimeOrdered) === 1 && ($mimeOrdered[0]['file_name'] ?? '') === 'valid.png',
    'non-image files do not displace a valid photo before LIMIT', $failures);

// --- D4: one payload per event, and no resolver call when mail is disabled ----

$pdo->exec("DELETE FROM notification_settings WHERE user_id=312 AND event_code='request.photo'");
$counter = new CountingPhotoResolver(new EmailPhotoResolver());
$countedAttachments = new AttachmentService(notifications: new NotificationService(photos: $counter));
$requestCounted = $newRequest('N281-COUNTED');
$countedId = $addAttachment($requestCounted, 'image/png', $png, null, true, 'counted.png');
$countedAttachments->notifyPhotoAdded($requestRow($requestCounted), 310, $countedId, 'counted.png', false, null);
check($counter->calls === 1, 'resolver runs once per event for all recipients', $failures);
$countedManager = $payloadsFor('n281manager@example.test', '%Фото%');
$countedClient = $payloadsFor('n281client@example.test', '%Фото%');
check($countedManager !== [] && $countedClient !== []
    && end($countedManager)['attachments_json'] === end($countedClient)['attachments_json'],
    'all recipients reuse the identical prepared payload', $failures);

$settings->set('mail.enabled', '0');
$beforeDisabled = $counter->calls;
$requestDisabled = $newRequest('N281-DISABLED');
$disabledId = $addAttachment($requestDisabled, 'image/png', $png, null, true, 'disabled.png');
$countedAttachments->notifyPhotoAdded($requestRow($requestDisabled), 310, $disabledId, 'disabled.png', false, null);
check($counter->calls === $beforeDisabled, 'resolver is not called when mail is disabled', $failures);
$settings->set('mail.enabled', '1');

// --- Safety: bad files are skipped, logs and payloads stay clean -------------

$requestBad = $newRequest('N281-BAD-PHOTOS');
$goodId = $addAttachment($requestBad, 'image/png', $png, null, true, 'good.png');
$addAttachment($requestBad, 'image/png', $png, null, false, 'deleted.png');
$addAttachment($requestBad, 'application/pdf', $png, null, true, 'wrong-mime.png');
$addAttachment($requestBad, 'image/png', $png, AttachmentService::MAX_SIZE + 1, true, 'huge.png');
$badPayload = (new EmailPhotoResolver())->resolve($requestBad, [
    $goodId,
    $goodId,
    $goodId + 1,
    $goodId + 2,
    $goodId + 3,
]);
$bad = $decode($badPayload);
check(count($bad) === 1 && ($bad[0]['file_name'] ?? '') === 'good.png',
    'only the valid photo survives bad-file filtering', $failures);
$log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
check(strpos($log, 'missing_file') !== false && strpos($log, 'oversize') !== false,
    'skipped photos emit generic reason codes', $failures);
check(strpos($log, $storage) === false && strpos($log, $secret) === false
    && strpos($log, '.env') === false, 'logs contain no paths or secrets', $failures);
check(strpos($badPayload, $secret) === false, 'queue payload contains no secret', $failures);

// Cross-request IDs are rejected by ownership checks.
$otherRequest = $newRequest('N281-OTHER');
$foreignId = $addAttachment($otherRequest, 'image/png', $png, null, true, 'foreign.png');
check((new EmailPhotoResolver())->resolve($requestBad, [$foreignId]) === '',
    'a photo ID from another request is not attached', $failures);
check((new EmailPhotoResolver())->resolve($requestBad, [0, -1, 999999999]) === '',
    'empty and invalid IDs are handled safely', $failures);

// --- Queue: retry reuses the payload; legacy rows stay usable ----------------

$repo = new EmailQueueRepository();
$queueId = (int) $pdo->query(
    "SELECT ID FROM email_queue WHERE to_email='n281manager@example.test' AND subject LIKE '%Фото%' ORDER BY ID DESC LIMIT 1"
)->fetchColumn();
check($queueId > 0, 'photo queue row found', $failures);
$pdo->exec("DELETE FROM email_queue WHERE ID <> $queueId");
$pdo->exec("UPDATE email_queue SET status='pending', claim_token=NULL, claimed_at=NULL WHERE ID=$queueId");
$first = $repo->claim(1);
$payloadFirst = (string) ($first[0]['attachments_json'] ?? '');
$pdo->exec("UPDATE email_queue SET status='pending', claim_token=NULL, claimed_at=NULL WHERE ID=$queueId");
$second = $repo->claim(1);
$payloadSecond = (string) ($second[0]['attachments_json'] ?? '');
check($payloadFirst !== '' && $payloadFirst === $payloadSecond,
    'queue retry reuses an identical attachment payload', $failures);
check(count($decode($payloadSecond)) === 1, 'queue retry does not duplicate attachments', $failures);

$pdo->exec("INSERT INTO email_queue (to_email,subject,body) VALUES ('legacy@example.test','legacy','body')");
$legacyRow = $repo->claim(50);
$legacyFound = false;
foreach ($legacyRow as $item) {
    if (($item['to_email'] ?? '') === 'legacy@example.test') {
        $legacyFound = ($item['attachments_json'] ?? null) === null;
    }
}
check($legacyFound, 'old queue rows without attachments_json are claimed with NULL', $failures);
$decodeLegacy = (new ReflectionMethod(MailService::class, 'decodeAttachments'))->invoke(new MailService(), null);
check($decodeLegacy === [], 'NULL payload decodes to no attachments', $failures);
check((new ReflectionMethod(MailService::class, 'decodeAttachments'))->invoke(new MailService(), '') === [],
    'empty payload decodes to no attachments', $failures);
check((new ReflectionMethod(MailService::class, 'decodeAttachments'))->invoke(new MailService(), '{bad json') === [],
    'corrupt payload decodes to no attachments', $failures);

// --- Post-commit notification failure isolation ------------------------------

$leakMarker = 'crm7-leak-marker-' . bin2hex(random_bytes(6));
$leakResolver = new ThrowingPhotoResolver(
    $leakMarker . ' path=' . $storage . ' content=' . base64_encode($png)
);
$isolated = new AttachmentService(notifications: new NotificationService(photos: $leakResolver));
$requestCommitted = $newRequest('N281-COMMITTED');
$committedId = $addAttachment($requestCommitted, 'image/png', $png, null, true, 'committed.png');
$attachmentsBefore = (int) $pdo->query('SELECT COUNT(*) FROM request_attachments')->fetchColumn();
$queueBefore = (int) $pdo->query('SELECT COUNT(*) FROM email_queue')->fetchColumn();
$exceptionEscaped = false;
try {
    $isolated->notifyPhotoAdded($requestRow($requestCommitted), 310, $committedId, 'committed.png', false, 'тело');
} catch (Throwable $e) {
    $exceptionEscaped = true;
}
check(!$exceptionEscaped, 'notification exception does not escape after a successful upload', $failures);
$failureLog = is_file($logFile) ? (string) file_get_contents($logFile) : '';
check(strpos($failureLog, 'photo notification failed after upload') !== false,
    'post-commit notification failure logs a fixed safe code', $failures);
check(strpos($failureLog, $leakMarker) === false && strpos($failureLog, $storage) === false
    && strpos($failureLog, base64_encode($png)) === false && strpos($failureLog, $secret) === false,
    'notification error log contains no path, file content or secret', $failures);
check((int) $pdo->query('SELECT COUNT(*) FROM request_attachments')->fetchColumn() === $attachmentsBefore,
    'failed notification does not duplicate the upload', $failures);
check((int) $pdo->query('SELECT COUNT(*) FROM email_queue')->fetchColumn() === $queueBefore,
    'failed notification does not enqueue a duplicate email', $failures);

// --- SMTP: multiple attachments, sanitized names and legacy compatibility ----

$mailer = new SmtpMailer([]);
$legacy = (new ReflectionMethod($mailer, 'normalizeAttachments'))->invoke($mailer, [
    'file_name' => 'legacy.csv', 'content' => 'x', 'mime' => 'text/csv',
]);
check(count($legacy) === 1, 'legacy single-attachment call stays compatible', $failures);
$list = (new ReflectionMethod($mailer, 'normalizeAttachments'))->invoke($mailer, [
    ['file_name' => 'a.png', 'content' => 'a', 'mime' => 'image/png'],
    ['file_name' => 'b.png', 'content' => 'b', 'mime' => 'image/png'],
]);
check(count($list) === 2, 'mailer accepts a list of attachments', $failures);
$body = (new ReflectionMethod($mailer, 'attachmentBody'))->invoke(
    $mailer,
    'Текст письма',
    [
        ['file_name' => "evil\"\r\nBcc: leak@example.test.png", 'content' => $png, 'mime' => 'image/png'],
        ['file_name' => 'second.png', 'content' => $png, 'mime' => 'image/png'],
    ],
    'test-boundary'
);
check(substr_count($body, 'Content-Disposition: attachment') === 2,
    'mailer emits one MIME part per attachment', $failures);
check(strpos($body, 'Bcc:') === false && strpos($body, "\r\nBcc") === false,
    'attachment filenames cannot inject mail headers', $failures);
check(strpos(str_replace("\r\n", '', $body), base64_encode($png)) !== false,
    'attachment bytes survive MIME encoding', $failures);
check(strpos($body, $storage) === false && strpos($body, $secret) === false,
    'MIME body contains no paths or secrets', $failures);

foreach ($mailOriginal as $key => $value) {
    if ($value === null) {
        $stmt = $pdo->prepare('DELETE FROM settings WHERE `key`=?');
        $stmt->execute([$key]);
    } else {
        $settings->set($key, $value);
    }
}
ini_restore('error_log');
putenv('STORAGE_DIR');
$cleanup();
$removeStorage();

if ($failures !== []) {
    fwrite(STDERR, sprintf("\nНеудавшихся проверок: %d (%s)\n", count($failures), implode('; ', $failures)));
    exit(1);
}
echo "email-photo-attachments: OK\n";
