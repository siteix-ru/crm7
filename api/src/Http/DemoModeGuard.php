<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Config;

/**
 * Централизованная защита инфраструктурных операций в демо-режиме.
 *
 * Источник истины — серверная конфигурация (DEMO_MODE), а не флаг frontend.
 * Проверка выполняется до maintenance-обработки и до dispatch роутера.
 */
final class DemoModeGuard
{
    /** @var list<array{0: string, 1: string}> */
    private const FORBIDDEN = [
        // Подключение к БД и раскрытие параметров подключения.
        ['GET', '/admin/settings/database'],
        ['POST', '/admin/settings/database'],
        ['POST', '/admin/settings/database/test'],
        // Очередь обновлений (в том числе во время maintenance).
        ['POST', '/admin/update/apply'],
        // Перезапись level_capabilities через реестр модулей и роли.
        ['POST', '/admin/modules'],
        ['POST', '/roles/{level}'],
    ];

    public static function enforce(Request $request): void
    {
        if (!self::isForbidden($request)) {
            return;
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => 'demo_mode']], JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function isForbidden(Request $request): bool
    {
        if (!Config::demoMode()) {
            return false;
        }

        foreach (self::FORBIDDEN as [$method, $pattern]) {
            if ($method === $request->method && self::matches($pattern, $request->path)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts = explode('/', trim($path, '/'));

        if (count($patternParts) !== count($pathParts)) {
            return false;
        }

        foreach ($patternParts as $index => $part) {
            if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                if ($pathParts[$index] === '') {
                    return false;
                }

                continue;
            }

            if ($part !== $pathParts[$index]) {
                return false;
            }
        }

        return true;
    }
}
