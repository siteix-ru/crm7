<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class UpdateController extends ApiController
{
    private const FEED_URL = 'https://update.crm7.ru/v1/releases.json';

    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';

    public function check(Request $request): Response
    {
        $this->requireTechAdmin($request);
        $current = $this->currentVersion($request);
        $feed = $this->fetchFeed();
        $chain = array_map(
            fn (array $release): array => $this->releasePayload($release),
            $this->releaseChain($feed, $current)
        );
        $release = $chain[0] ?? null;
        $latest = $chain !== [] ? $chain[count($chain) - 1] : null;

        return Response::ok([
            'configured' => $this->updateDir() !== null,
            'current_version' => $current,
            'latest_version' => $latest !== null ? (string) ($latest['version'] ?? '') : null,
            'update_available' => $release !== null,
            'release' => $release,
            'chain' => $chain,
            'feed_generated_at' => isset($feed['generated_at']) ? (string) $feed['generated_at'] : null,
        ]);
    }

    public function status(Request $request): Response
    {
        $this->requireTechAdmin($request);
        $dir = $this->updateDir();

        return Response::ok([
            'configured' => $dir !== null,
            'current_version' => $this->currentVersion($request),
            'status' => $dir === null ? ['state' => 'not_configured'] : $this->readStatus($dir),
        ]);
    }

    public function apply(Request $request): Response
    {
        [$user] = $this->requireTechAdmin($request);
        $dir = $this->updateDir();

        if ($dir === null) {
            throw new HttpException(503, 'update_not_configured', 'Автообновление не настроено на этом стенде');
        }

        $lock = fopen($dir . '/apply.lock', 'c');

        if ($lock === false) {
            throw new HttpException(500, 'update_lock_failed', 'Не удалось заблокировать запуск обновления');
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new HttpException(409, 'update_running', 'Обновление уже запускается');
            }

            $status = $this->readStatus($dir);

            if (in_array((string) ($status['state'] ?? ''), ['queued', 'running'], true) && !$this->isStale($status)) {
                throw new HttpException(409, 'update_running', 'Обновление уже выполняется');
            }

            $current = $this->currentVersion($request);
            $feed = $this->fetchFeed();
            $chain = $this->releaseChain($feed, $current);

            if ($current === null) {
                throw new HttpException(409, 'current_version_unknown', 'Не удалось определить текущую версию');
            }

            if ($chain === []) {
                throw new HttpException(409, 'no_updates', 'Обновлений нет');
            }

            $requestId = bin2hex(random_bytes(16));
            $target = (string) ($chain[count($chain) - 1]['version'] ?? '');
            $payload = [
                'request_id' => $requestId,
                'requested_at' => date('c'),
                'current_version' => $current,
                'target_version' => $target,
                'requested_by' => [
                    'id' => (int) ($user['ID'] ?? 0),
                    'login' => (string) ($user['LOGIN'] ?? ''),
                ],
            ];

            $this->writeJson($dir . '/status.json', [
                'state' => 'queued',
                'exit_code' => 0,
                'updated_at' => date('c'),
                ...$payload,
            ]);
            $this->writeJson($dir . '/request.json', $payload);
            $this->audit($request, $user, 'system.update.request', 'system', null, [
                'target_version' => $target,
                'request_id' => $requestId,
            ]);

            return Response::ok([
                'accepted' => true,
                'request_id' => $requestId,
                'target_version' => $target,
                'status' => $this->readStatus($dir),
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function requireTechAdmin(Request $request): array
    {
        [$user, $capabilities] = $this->context($request);

        if (!in_array('roles.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Доступ только техническому администратору');
        }

        return [$user, $capabilities];
    }

    private function currentVersion(Request $request): ?string
    {
        $value = (string) Config::get('CRM7_VERSION', '');

        if (!preg_match(self::VERSION_PATTERN, $value)) {
            $value = $request->queryParam('current');
        }

        if (!preg_match(self::VERSION_PATTERN, $value)) {
            $value = $request->str('current');
        }

        return preg_match(self::VERSION_PATTERN, $value) ? $value : null;
    }

    private function updateDir(): ?string
    {
        $dir = rtrim((string) Config::get('CRM7_UPDATE_DIR', ''), '/');

        if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
            return null;
        }

        return $dir;
    }

    private function fetchFeed(): array
    {
        $url = (string) Config::get('CRM7_UPDATE_FEED', self::FEED_URL);
        $handle = curl_init($url);

        if ($handle === false) {
            throw new HttpException(502, 'feed_unavailable', 'Не удалось проверить обновления');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'crm7-api/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($raw) || $status !== 200) {
            throw new HttpException(
                502,
                'feed_unavailable',
                $error !== '' ? 'Не удалось проверить обновления: ' . $error : 'Не удалось проверить обновления'
            );
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data['releases'] ?? null)) {
            throw new HttpException(502, 'feed_invalid', 'Некорректный фид обновлений');
        }

        return $data;
    }

    private function latestRelease(array $feed): ?array
    {
        $latest = null;

        foreach ($feed['releases'] as $release) {
            if (!is_array($release)) {
                continue;
            }

            $version = (string) ($release['version'] ?? '');

            if (!preg_match(self::VERSION_PATTERN, $version)) {
                continue;
            }

            if ($latest === null || version_compare($version, (string) ($latest['version'] ?? ''), '>')) {
                $latest = $release;
            }
        }

        return $latest;
    }

    private function nextRelease(array $feed, ?string $current): ?array
    {
        if ($current === null) {
            return $this->latestRelease($feed);
        }

        $next = null;

        foreach ($feed['releases'] as $release) {
            if (!is_array($release)) {
                continue;
            }

            $version = (string) ($release['version'] ?? '');

            if (!preg_match(self::VERSION_PATTERN, $version) || version_compare($version, $current, '<=')) {
                continue;
            }

            $minFrom = trim((string) ($release['min_from'] ?? ''));

            if ($minFrom !== '') {
                if (!preg_match(self::VERSION_PATTERN, $minFrom) || version_compare($current, $minFrom, '<')) {
                    continue;
                }
            }

            if ($next === null || version_compare($version, (string) ($next['version'] ?? ''), '<')) {
                $next = $release;
            }
        }

        return $next;
    }

    private function releaseChain(array $feed, ?string $current): array
    {
        if ($current === null) {
            $latest = $this->latestRelease($feed);

            return $latest === null ? [] : [$latest];
        }

        $chain = [];
        $cursor = $current;

        while (true) {
            $next = $this->nextRelease($feed, $cursor);

            if ($next === null) {
                break;
            }

            $chain[] = $next;
            $cursor = (string) ($next['version'] ?? '');

            if (count($chain) >= 50) {
                break;
            }
        }

        return $chain;
    }

    private function releasePayload(array $release): array
    {
        return [
            'version' => (string) ($release['version'] ?? ''),
            'released_at' => (string) ($release['released_at'] ?? ''),
            'commit' => (string) ($release['commit'] ?? ''),
            'min_from' => (string) ($release['min_from'] ?? ''),
            'notes' => (string) ($release['notes'] ?? ''),
            'images' => is_array($release['images'] ?? null) ? $release['images'] : [],
        ];
    }

    private function readStatus(string $dir): array
    {
        $path = $dir . '/status.json';

        if (!is_file($path)) {
            return ['state' => 'idle', 'updated_at' => null];
        }

        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($data)) {
            return ['state' => 'idle', 'updated_at' => null];
        }

        if ($this->isStale($data)) {
            $state = (string) ($data['state'] ?? '');
            $data['state'] = 'stalled';
            $data['message'] = $state === 'queued'
                ? 'Служба обновления не отвечает'
                : 'Обновление не завершилось за отведённое время';
        }

        return $data;
    }

    private function isStale(array $status): bool
    {
        $state = (string) ($status['state'] ?? '');

        if (!in_array($state, ['queued', 'running'], true)) {
            return false;
        }

        $updated = (string) ($status['updated_at'] ?? '');
        $timestamp = $updated !== '' ? strtotime($updated) : false;

        if ($timestamp === false) {
            return false;
        }

        $limit = $state === 'queued' ? 90 : 1860;

        return time() - $timestamp > $limit;
    }

    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new HttpException(500, 'update_write_failed', 'Не удалось подготовить запрос обновления');
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, $json . "\n") === false) {
            throw new HttpException(500, 'update_write_failed', 'Не удалось записать запрос обновления');
        }

        @chmod($temporary, 0664);

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new HttpException(500, 'update_write_failed', 'Не удалось записать запрос обновления');
        }
    }
}
