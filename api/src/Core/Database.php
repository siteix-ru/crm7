<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        self::$pdo = self::makePdo();

        return self::$pdo;
    }

    /**
     * Independent autocommit connection. Used when a caller needs to see the
     * latest committed state instead of its transaction's REPEATABLE READ snapshot.
     */
    public static function freshPdo(): PDO
    {
        return self::makePdo();
    }

    private static function makePdo(): PDO
    {
        $settings = DatabaseSettings::current();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $settings['host'],
            $settings['port'],
            $settings['database']
        );

        return new PDO(
            $dsn,
            $settings['user'],
            $settings['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
}
