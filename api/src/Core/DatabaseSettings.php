<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\HttpException;
use App\Support\DatabaseConfigCrypto;
use PDO;
use PDOException;
use RuntimeException;

final class DatabaseSettings
{
    private const FILE_NAME = 'database.json';
    private const ENCRYPTED_PREFIX = 'dbcfg:';

    /** @var string|null Только для изолированных standalone-тестов. */
    private static ?string $pathOverride = null;

    public static function setPathOverride(?string $path): void
    {
        self::$pathOverride = $path;
    }

    public static function current(): array
    {
        $file = self::readConfig();

        if ($file !== null) {
            return [
                'host' => (string) $file['host'],
                'port' => (int) $file['port'],
                'database' => (string) $file['database'],
                'user' => (string) $file['user'],
                'password' => self::decodePassword((string) ($file['password'] ?? '')),
                'source' => 'file',
            ];
        }

        return [
            'host' => (string) Config::get('DB_HOST', '127.0.0.1'),
            'port' => (int) Config::get('DB_PORT', '3306'),
            'database' => (string) Config::get('DB_NAME', 'crm_local'),
            'user' => (string) Config::get('DB_USER', 'root'),
            'password' => (string) Config::get('DB_PASS', ''),
            'source' => 'env',
        ];
    }

    public static function publicPayload(): array
    {
        $current = self::current();

        return [
            'host' => $current['host'],
            'port' => $current['port'],
            'database' => $current['database'],
            'user' => $current['user'],
            'has_password' => $current['password'] !== '',
            'source' => $current['source'],
            'file' => self::path(),
        ];
    }

    /** @param (callable(string, string, string, array): \PDO)|null $pdoFactory */
    public static function test(
        array $input,
        ?DatabaseHostPolicy $policy = null,
        ?callable $pdoFactory = null
    ): array {
        $settings = self::normalize($input, $policy);
        $pdo = self::connect($settings, $pdoFactory);

        return [
            'ok' => true,
            'server' => (string) $pdo->query('SELECT VERSION()')->fetchColumn(),
        ];
    }

    /**
     * @param (callable(string, string, string, array): \PDO)|null $pdoFactory
     */
    public static function save(
        array $input,
        string $actorLogin = '',
        ?DatabaseHostPolicy $policy = null,
        ?callable $pdoFactory = null,
        ?SafeConfigFileWriter $writer = null
    ): array {
        $settings = self::normalize($input, $policy);

        $pdo = self::connect($settings, $pdoFactory);

        self::assertHasAdmin($pdo, $settings['database']);

        $warnings = self::collectWarnings($pdo, $actorLogin);
        $path = self::path();
        $previous = self::readConfig();
        $backupContents = $previous === null ? null : self::backupConfig($previous);
        $contents = self::encodeConfig($settings, self::encryptPassword($settings['password']));

        ($writer ?? new SafeConfigFileWriter())->save($path, $contents, $backupContents);

        return self::publicPayload() + ['warnings' => $warnings];
    }

    public static function path(): string
    {
        return self::$pathOverride ?? dirname(__DIR__, 2) . '/config/' . self::FILE_NAME;
    }

    public static function normalize(array $input, ?DatabaseHostPolicy $policy = null): array
    {
        $policy ??= new DatabaseHostPolicy();
        $current = self::current();
        $host = DatabaseHostPolicy::normalizeHost((string) ($input['host'] ?? ''));
        $rawPort = $input['port'] ?? null;

        if (is_int($rawPort)) {
            $port = $rawPort;
        } elseif (is_string($rawPort) && preg_match('/^[1-9][0-9]*$/', $rawPort) === 1) {
            $port = (int) $rawPort;
        } else {
            throw new HttpException(422, 'validation_error', 'Укажите корректный порт (1–65535)');
        }

        $database = trim((string) ($input['database'] ?? ''));
        $user = trim((string) ($input['user'] ?? ''));
        $password = array_key_exists('password', $input) ? (string) $input['password'] : '';

        if ($port < 1 || $port > 65535) {
            throw new HttpException(422, 'validation_error', 'Укажите корректный порт (1–65535)');
        }

        if ($database === '' || preg_match('/^[A-Za-z0-9_$-]+$/', $database) !== 1) {
            throw new HttpException(422, 'validation_error', 'Укажите корректное имя базы данных');
        }

        if ($user === '') {
            throw new HttpException(422, 'validation_error', 'Укажите пользователя базы данных');
        }

        $policy->assertAllowed(
            $host,
            $port,
            (string) $current['host'],
            (int) $current['port']
        );

        $sameTarget = DatabaseHostPolicy::normalizeHost((string) $current['host']) === $host
            && (int) $current['port'] === $port;

        if ($password === '') {
            if (!$sameTarget) {
                throw new HttpException(
                    422,
                    'password_required',
                    'Укажите пароль для нового сервера или порта базы данных'
                );
            }

            $password = (string) $current['password'];
        }

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'user' => $user,
            'password' => $password,
        ];
    }

    /** @param (callable(string, string, string, array): \PDO)|null $factory */
    private static function connect(array $settings, ?callable $factory = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $settings['host'],
            $settings['port'],
            $settings['database']
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ];

        try {
            if ($factory !== null) {
                return $factory($dsn, $settings['user'], $settings['password'], $options);
            }

            return new PDO($dsn, $settings['user'], $settings['password'], $options);
        } catch (PDOException $exception) {
            Logger::error('Database connection failed', [
                'host' => $settings['host'],
                'port' => $settings['port'],
                'code' => $exception->getCode(),
            ]);

            throw new HttpException(422, 'db_connection_failed', 'Не удалось подключиться к базе данных');
        }
    }

    private static function assertHasAdmin(PDO $pdo, string $database): void
    {
        $tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();

        if ($tables === []) {
            throw new HttpException(
                422,
                'no_schema',
                'В целевой базе нет таблиц CRM — сначала примените миграции (php api/bin/migrate.php)'
            );
        }

        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM users
             WHERE LEVEL >= 50
               AND ACTIVE = 'Y'
               AND STATUS = 'Y'
               AND (reg_state IS NULL OR reg_state = 'active')"
        )->fetchColumn();

        if ($count < 1) {
            throw new HttpException(
                422,
                'no_admin',
                'В целевой базе нет активного администратора — после переключения вход будет невозможен'
            );
        }
    }

    private static function collectWarnings(PDO $pdo, string $actorLogin): array
    {
        if ($actorLogin === '') {
            return [];
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM users
             WHERE LOGIN = ?
               AND LEVEL >= 50
               AND ACTIVE = 'Y'
               AND STATUS = 'Y'
               AND (reg_state IS NULL OR reg_state = 'active')"
        );
        $stmt->execute([$actorLogin]);

        if ((int) $stmt->fetchColumn() === 0) {
            return [
                'Ваш логин «' . $actorLogin . '» не найден среди администраторов новой базы — после переключения войти сможет только администратор новой базы',
            ];
        }

        return [];
    }

    /** @return array<string, mixed>|null */
    private static function readConfig(): ?array
    {
        $path = self::path();

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new HttpException(500, 'config_read_failed', 'Не удалось прочитать конфигурацию подключения к БД');
        }

        $data = json_decode($raw, true);

        if (!is_array($data)
            || !isset($data['host'], $data['port'], $data['database'], $data['user'])
            || !is_string($data['host']) || $data['host'] === ''
            || !is_numeric($data['port']) || (int) $data['port'] < 1 || (int) $data['port'] > 65535
            || !is_string($data['database']) || $data['database'] === ''
            || !is_string($data['user']) || $data['user'] === '') {
            throw new HttpException(500, 'config_invalid', 'Конфигурация подключения к БД некорректна');
        }

        return $data;
    }

    private static function decodePassword(string $stored): string
    {
        if (DatabaseConfigCrypto::isEncrypted($stored)) {
            try {
                return DatabaseConfigCrypto::decrypt($stored);
            } catch (RuntimeException) {
                throw new HttpException(500, 'config_invalid', 'Конфигурация подключения к БД некорректна');
            }
        }

        if (str_starts_with($stored, self::ENCRYPTED_PREFIX)) {
            throw new HttpException(500, 'config_invalid', 'Конфигурация подключения к БД некорректна');
        }

        return $stored;
    }

    private static function encryptPassword(string $password): string
    {
        if (DatabaseConfigCrypto::isEncrypted($password)) {
            throw new HttpException(422, 'validation_error', 'Пароль уже зашифрован');
        }

        if ((string) Config::get('DB_CONFIG_KEY', '') === '') {
            throw new HttpException(500, 'db_config_key_missing', 'Ключ шифрования конфигурации БД не настроен');
        }

        try {
            return DatabaseConfigCrypto::encrypt($password);
        } catch (RuntimeException) {
            throw new HttpException(500, 'config_encrypt_failed', 'Не удалось зашифровать пароль конфигурации');
        }
    }

    /** @param array<string, mixed> $values */
    private static function encodeConfig(array $values, string $password): string
    {
        $json = json_encode([
            'host' => (string) $values['host'],
            'port' => (int) $values['port'],
            'database' => (string) $values['database'],
            'user' => (string) $values['user'],
            'password' => $password,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new HttpException(500, 'config_encoding_failed', 'Не удалось подготовить конфигурацию');
        }

        return $json;
    }

    /** @param array<string, mixed> $previous */
    private static function backupConfig(array $previous): string
    {
        $stored = (string) ($previous['password'] ?? '');

        if ($stored === '') {
            return self::encodeConfig($previous, '');
        }

        if (DatabaseConfigCrypto::isEncrypted($stored)) {
            return self::encodeConfig($previous, $stored);
        }

        if (str_starts_with($stored, self::ENCRYPTED_PREFIX)) {
            throw new HttpException(500, 'config_invalid', 'Конфигурация подключения к БД некорректна');
        }

        return self::encodeConfig($previous, self::encryptPassword($stored));
    }
}
