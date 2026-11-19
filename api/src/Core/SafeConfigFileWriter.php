<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\HttpException;
use Throwable;

/**
 * Атомарная запись конфигурационного файла с одним backup и блокировкой.
 *
 * Не знает о формате файла и не шифрует содержимое — только безопасная
 * запись, резервная копия и обработка ошибок.
 */
class SafeConfigFileWriter
{
    protected const MODE = 0600;

    public function save(string $path, string $contents, ?string $backupContents = null): void
    {
        $directory = dirname($path);
        $lock = $this->acquireLock($path . '.lock');

        try {
            if ($this->fileExists($path)) {
                $source = $backupContents ?? $this->readFile($path);

                if (!is_string($source)) {
                    throw new HttpException(500, 'backup_prepare_failed', 'Не удалось подготовить резервную копию конфигурации');
                }

                $backup = $this->writeTemp(
                    $directory,
                    $source,
                    'backup_prepare_failed'
                );

                if (!$this->rename($backup, $path . '.bak')) {
                    $this->discard($backup);

                    throw new HttpException(500, 'backup_rename_failed', 'Не удалось подготовить резервную копию конфигурации');
                }
            }

            $temporary = $this->writeTemp($directory, $contents, 'config_write_failed');

            if (!$this->rename($temporary, $path)) {
                $this->discard($temporary);

                throw new HttpException(500, 'main_rename_failed', 'Не удалось сохранить конфигурацию');
            }
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return resource */
    protected function acquireLock(string $path)
    {
        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new HttpException(500, 'config_lock_failed', 'Не удалось заблокировать конфигурацию');
        }

        if (!@chmod($path, self::MODE)) {
            fclose($handle);

            throw new HttpException(500, 'config_lock_failed', 'Не удалось заблокировать конфигурацию');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new HttpException(409, 'config_busy', 'Конфигурация уже сохраняется');
        }

        return $handle;
    }

    /** @param resource $handle */
    protected function releaseLock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    protected function fileExists(string $path): bool
    {
        return is_file($path);
    }

    /** @return string|false */
    protected function readFile(string $path)
    {
        return @file_get_contents($path);
    }

    protected function createTemp(string $directory): string
    {
        $temporary = @tempnam($directory, '.cfg-');

        if ($temporary === false || $temporary === '') {
            throw new HttpException(500, 'temp_create_failed', 'Не удалось создать временный файл конфигурации');
        }

        if (!$this->chmod($temporary, self::MODE)) {
            @unlink($temporary);

            throw new HttpException(500, 'chmod_failed', 'Не удалось установить права конфигурации');
        }

        return $temporary;
    }

    protected function chmod(string $path, int $mode): bool
    {
        return @chmod($path, $mode);
    }

    protected function writeTemp(string $directory, $contents, string $errorCode): string
    {
        if (!is_string($contents)) {
            throw new HttpException(500, $errorCode, 'Не удалось подготовить конфигурацию');
        }

        $temporary = $this->createTemp($directory);

        try {
            $handle = $this->open($temporary);
            $written = 0;
            $length = strlen($contents);

            while ($written < $length) {
                $chunk = $this->write($handle, substr($contents, $written));

                if ($chunk === false || $chunk <= 0) {
                    throw new HttpException(500, $errorCode, 'Не удалось записать конфигурацию');
                }

                $written += $chunk;
            }

            if (!$this->flush($handle)) {
                throw new HttpException(500, 'config_flush_failed', 'Не удалось записать конфигурацию');
            }

            if (!$this->sync($handle)) {
                throw new HttpException(500, 'config_sync_failed', 'Не удалось записать конфигурацию');
            }

            if (!$this->close($handle)) {
                throw new HttpException(500, 'config_close_failed', 'Не удалось записать конфигурацию');
            }

            return $temporary;
        } catch (Throwable $exception) {
            $this->discard($temporary, $exception);

            throw $exception;
        }
    }

    /** @return resource */
    protected function open(string $path)
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new HttpException(500, 'config_open_failed', 'Не удалось открыть конфигурацию');
        }

        return $handle;
    }

    /** @param resource $handle */
    protected function write($handle, string $chunk)
    {
        return @fwrite($handle, $chunk);
    }

    /** @param resource $handle */
    protected function flush($handle): bool
    {
        return @fflush($handle);
    }

    /** @param resource $handle */
    protected function sync($handle): bool
    {
        if (!function_exists('fsync')) {
            return true;
        }

        return @fsync($handle);
    }

    /** @param resource $handle */
    protected function close($handle): bool
    {
        return @fclose($handle);
    }

    protected function rename(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    protected function unlink(string $path): bool
    {
        return @unlink($path);
    }

    protected function discard(string $path, ?Throwable $primary = null): void
    {
        if (!$this->fileExists($path)) {
            return;
        }

        if ($this->unlink($path) || $primary !== null) {
            return;
        }

        throw new HttpException(500, 'config_cleanup_failed', 'Не удалось удалить временный файл конфигурации');
    }
}
