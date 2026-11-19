<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use RuntimeException;

/**
 * Шифрование пароля из конфигурации БД.
 *
 * Формат: dbcfg:v1:<base64(IV || TAG || CIPHER)>
 * Ключ: отдельный DB_CONFIG_KEY, domain-separated и сжатый до 32 байт.
 */
final class DatabaseConfigCrypto
{
    private const PREFIX = 'dbcfg:v1:';
    private const AAD = 'dbcfg:v1';
    private const KEY_DOMAIN = 'db-config|';

    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public static function encrypt(string $plaintext): string
    {
        if (self::isEncrypted($plaintext)) {
            throw new RuntimeException('Значение уже зашифровано');
        }

        $key = self::key();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD,
            self::TAG_LENGTH
        );

        if ($cipher === false) {
            throw new RuntimeException('Не удалось зашифровать пароль базы данных');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encrypted): string
    {
        if (!self::isEncrypted($encrypted)) {
            throw new RuntimeException('Неизвестный формат зашифрованного пароля базы данных');
        }

        $raw = base64_decode(substr($encrypted, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('Повреждённый зашифрованный пароль базы данных');
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $cipher = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        if (strlen($iv) !== self::IV_LENGTH || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Повреждённый зашифрованный пароль базы данных');
        }

        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD
        );

        if ($plain === false) {
            throw new RuntimeException('Не удалось расшифровать пароль базы данных');
        }

        return $plain;
    }

    private static function key(): string
    {
        $secret = (string) Config::get('DB_CONFIG_KEY', '');

        if ($secret === '') {
            throw new RuntimeException('DB_CONFIG_KEY не настроен');
        }

        return hash('sha256', self::KEY_DOMAIN . $secret, true);
    }
}
