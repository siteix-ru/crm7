<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\SafeConfigFileWriter;
use App\Http\HttpException;

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

function fails(callable $call, string $code, string $name, array &$failures, array $forbidden = []): void
{
    try {
        $call();
        check(false, $name, $failures);

        return;
    } catch (HttpException $exception) {
        $message = $exception->getMessage();

        foreach ($forbidden as $secret) {
            if (str_contains($message, $secret)) {
                check(false, "$name (секрет в сообщении)", $failures);

                return;
            }
        }

        check($exception->errorCode === $code, $name, $failures);
    }
}

function freshDir(): string
{
    $directory = sys_get_temp_dir() . '/crm7-safe-writer-' . bin2hex(random_bytes(6));

    if (!mkdir($directory, 0700, true)) {
        throw new RuntimeException('Не удалось создать тестовый каталог');
    }

    return $directory;
}

function cleanupDir(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
    }

    foreach (glob($directory . '/.*') ?: [] as $file) {
        if (basename($file) !== '.' && basename($file) !== '..') {
            @unlink($file);
        }
    }

    @rmdir($directory);
}

function tempFiles(string $directory): array
{
    return glob($directory . '/.cfg-*') ?: [];
}

final class FaultyWriter extends SafeConfigFileWriter
{
    /** @var array<string, bool> */
    public array $faults = [];

    public bool $busy = false;

    /** @var list<int> */
    public array $chmodModes = [];

    /** @return resource */
    public function lockForTest(string $path)
    {
        return $this->acquireLock($path);
    }

    public function discardForTest(string $path): void
    {
        $this->discard($path);
    }

    /** @return resource */
    protected function acquireLock(string $path)
    {
        if ($this->busy) {
            throw new HttpException(409, 'config_busy', 'Конфигурация уже сохраняется');
        }

        return parent::acquireLock($path);
    }

    protected function createTemp(string $directory): string
    {
        if (!empty($this->faults['temp'])) {
            throw new HttpException(500, 'temp_create_failed', 'Не удалось создать временный файл');
        }

        return parent::createTemp($directory);
    }

    protected function chmod(string $path, int $mode): bool
    {
        $this->chmodModes[] = $mode;

        if (!empty($this->faults['chmod'])) {
            return false;
        }

        return parent::chmod($path, $mode);
    }

    protected function open(string $path)
    {
        if (!empty($this->faults['open'])) {
            throw new HttpException(500, 'config_open_failed', 'Не удалось открыть файл');
        }

        return parent::open($path);
    }

    protected function write($handle, string $chunk)
    {
        if (!empty($this->faults['write'])) {
            return 0;
        }

        return parent::write($handle, $chunk);
    }

    protected function flush($handle): bool
    {
        if (!empty($this->faults['flush'])) {
            return false;
        }

        return parent::flush($handle);
    }

    protected function sync($handle): bool
    {
        if (!empty($this->faults['sync'])) {
            return false;
        }

        return parent::sync($handle);
    }

    /** @return string|false */
    protected function readFile(string $path)
    {
        if (!empty($this->faults['backup_prepare'])) {
            return false;
        }

        return parent::readFile($path);
    }

    protected function rename(string $from, string $to): bool
    {
        if (!empty($this->faults['backup_rename']) && str_ends_with($to, '.bak')) {
            return false;
        }

        if (!empty($this->faults['main_rename']) && !str_ends_with($to, '.bak')) {
            return false;
        }

        return parent::rename($from, $to);
    }

    protected function unlink(string $path): bool
    {
        if (!empty($this->faults['cleanup'])) {
            return false;
        }

        return parent::unlink($path);
    }
}

$writer = new FaultyWriter();

// 1–5: обычные сценарии.
$dir = freshDir();
$path = $dir . '/settings.json';
$secret = 'unit-secret-value';

$writer->save($path, 'v1');

check(file_get_contents($path) === 'v1', 'первый save создал основной файл', $failures);
check(!file_exists($path . '.bak'), 'первый save не создал backup', $failures);

$writer->save($path, 'v2');

check(file_get_contents($path) === 'v2', 'второй save обновил основной файл', $failures);
check(file_get_contents($path . '.bak') === 'v1', 'backup содержит предыдущую версию', $failures);

$writer->save($path, 'v3', 'safe-backup-content');

check(file_get_contents($path . '.bak') === 'safe-backup-content', 'отдельный backup-content используется для .bak', $failures);
check(file_get_contents($path) === 'v3', 'основной файл сохраняет новые contents', $failures);

$writer->save($path, 'v4');

check(file_get_contents($path . '.bak') === 'v3', 'обычный save хранит только предыдущую версию', $failures);
check(
    $writer->chmodModes !== [] && array_unique($writer->chmodModes) === [0600],
    'для всех создаваемых файлов запрошены права 0600',
    $failures
);
check(tempFiles($dir) === [], 'после успешного сохранения temp-файлов нет', $failures);
check(!str_contains((string) file_get_contents($path), $secret), 'тестовый секрет не записан в конфиг', $failures);
cleanupDir($dir);

// 6–16: ошибки.
$faults = [
    'temp' => 'temp_create_failed',
    'chmod' => 'chmod_failed',
    'open' => 'config_open_failed',
    // Основной файл уже есть: запись сначала используется для backup.
    'write' => 'backup_prepare_failed',
    'flush' => 'config_flush_failed',
    'sync' => 'config_sync_failed',
    'backup_prepare' => 'backup_prepare_failed',
    'backup_rename' => 'backup_rename_failed',
    'main_rename' => 'main_rename_failed'
];

foreach ($faults as $fault => $code) {
    $dir = freshDir();
    $path = $dir . '/settings.json';
    $faulty = new FaultyWriter();

    $faulty->save($path, 'v1');

    if (in_array($fault, ['backup_prepare', 'backup_rename', 'main_rename'], true)) {
        $faulty->save($path, 'v2');
    }

    $mainBefore = file_get_contents($path);
    $bakBefore = file_exists($path . '.bak') ? file_get_contents($path . '.bak') : null;
    $faulty->faults[$fault] = true;

    fails(
        fn () => $faulty->save($path, $secret),
        $code,
        "fault $fault → $code",
        $failures,
        [$secret]
    );

    check(file_get_contents($path) === $mainBefore, "fault $fault: основной файл не повреждён", $failures);
    $currentBackup = file_exists($path . '.bak') ? file_get_contents($path . '.bak') : null;
    $backupIntact = $currentBackup === $bakBefore
        || ($fault === 'main_rename' && $currentBackup === $mainBefore);

    check($backupIntact, "fault $fault: backup не повреждён", $failures);
    check(tempFiles($dir) === [], "fault $fault: temp-файлы удалены", $failures);
    cleanupDir($dir);
}

$dir = freshDir();
$path = $dir . '/settings.json';
$faulty = new FaultyWriter();
$faulty->faults['write'] = true;

fails(
    fn () => $faulty->save($path, $secret),
    'config_write_failed',
    'fault write при первом сохранении → config_write_failed',
    $failures,
    [$secret]
);

check(!file_exists($path), 'основной файл не создан при ошибке записи', $failures);
check(tempFiles($dir) === [], 'temp удалён при ошибке записи основного файла', $failures);
cleanupDir($dir);

$dir = freshDir();
$path = $dir . '/settings.json';
$writer->save($path, 'v1');

$faulty = new FaultyWriter();
$faulty->busy = true;

fails(
    fn () => $faulty->save($path, 'v2'),
    'config_busy',
    'конкурентная запись → config_busy',
    $failures,
    ['v2']
);

$writer->save($path, 'v3');

check(file_get_contents($path) === 'v3', 'после освобождения lock save успешен', $failures);
check(file_get_contents($path . '.bak') === 'v1', 'backup после конкурентного теста цел', $failures);
check(tempFiles($dir) === [], 'temp после конкурентного теста отсутствуют', $failures);

$faulty = new FaultyWriter();
$lockHandle = $faulty->lockForTest($path . '.lock');

fails(
    fn () => $writer->save($path, 'v4'),
    'config_busy',
    'второй сохранятор получает config_busy при реальном lock',
    $failures,
    ['v4']
);

@flock($lockHandle, LOCK_UN);
@fclose($lockHandle);

$writer->save($path, 'v5');

check(file_get_contents($path) === 'v5', 'сохранение после реального lock успешно', $failures);
check(file_get_contents($path . '.bak') === 'v3', 'backup после реального lock цел', $failures);

$faulty = new FaultyWriter();
$faulty->faults['cleanup'] = true;
$orphan = $path . '.orphan';
file_put_contents($orphan, 'x');

fails(
    fn () => $faulty->discardForTest($path . '.orphan'),
    'config_cleanup_failed',
    'ошибка cleanup контролируема',
    $failures
);

cleanupDir($dir);

echo $failures === [] ? "\nALL OK\n" : "\nFAILED: " . implode(', ', $failures) . "\n";

exit($failures === [] ? 0 : 1);
