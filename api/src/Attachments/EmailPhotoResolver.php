<?php

declare(strict_types=1);

namespace App\Attachments;

use App\Repositories\AttachmentRepository;
use App\Support\Storage;

/**
 * Resolves request-owned photos into a queue-safe attachment payload.
 *
 * The email queue stores the resolved payload (base64) at enqueue time, so a
 * later retry never re-reads files or resolves a different set of photos.
 * Only integer IDs and a request ID are trusted; no path or URL from input is
 * ever used. Bad files are skipped with a generic log code, never failing the
 * user action and never exposing internal paths.
 */
final class EmailPhotoResolver implements EmailPhotoResolverInterface
{
    /** Public MIME whitelist shared with StocksService::PHOTO_MIMES. */
    private const PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_PHOTOS = 5;

    /** Keeps base64 payload comfortably inside the queue's MEDIUMTEXT column. */
    private const MAX_TOTAL_BYTES = 8388608; // 8 MiB

    public function __construct(
        private readonly AttachmentRepository $attachments = new AttachmentRepository()
    ) {
    }

    /**
     * @param int[] $ids Optional explicit attachment IDs; empty means "all public photos of the request".
     * @return string JSON payload for the mail queue, or '' when nothing is safe to attach.
     */
    public function resolve(int $requestId, array $ids = []): string
    {
        if ($requestId <= 0) {
            return '';
        }

        $rows = $this->attachments->photosForEmail($requestId, $ids, self::MAX_PHOTOS);
        if ($rows === []) {
            return '';
        }

        $payload = [];
        $total = 0;

        foreach ($rows as $row) {
            if (count($payload) >= self::MAX_PHOTOS || $total >= self::MAX_TOTAL_BYTES) {
                break;
            }

            $resolved = $this->resolveOne($row, $requestId, $total);
            if ($resolved === null) {
                continue;
            }

            $payload[] = $resolved['attachment'];
            $total += $resolved['bytes'];
        }

        if ($payload === []) {
            return '';
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** @return array{attachment: array{file_name:string,mime:string,content:string},bytes:int}|null */
    private function resolveOne(array $row, int $requestId, int $total): ?array
    {
        if ((int) ($row['id'] ?? 0) <= 0 || (int) ($row['request_id'] ?? 0) !== $requestId) {
            return null;
        }
        if ((int) ($row['is_internal'] ?? 0) !== 0) {
            return null; // internal files never leave the team by email
        }

        $mime = strtolower(trim((string) ($row['mime'] ?? '')));
        if (!in_array($mime, self::PHOTO_MIMES, true)) {
            $this->skip('invalid_mime');
            return null;
        }

        $size = (int) ($row['size'] ?? 0);
        if ($size <= 0 || $size > AttachmentService::MAX_SIZE
            || $total + $size > self::MAX_TOTAL_BYTES) {
            $this->skip('oversize');
            return null;
        }

        $relative = (string) ($row['storage_path'] ?? '');
        if (preg_match('#^attachments/\d{4}/\d{2}/[0-9a-f]{32}\.[a-z0-9]{1,10}$#', $relative) !== 1) {
            $this->skip('unsafe_path');
            return null;
        }

        $base = realpath(Storage::baseDir());
        $absolute = realpath(Storage::absolute($relative));
        if ($base === false || $absolute === false
            || !str_starts_with($absolute, $base . DIRECTORY_SEPARATOR)
            || !is_file($absolute)) {
            $this->skip('missing_file');
            return null;
        }

        $actual = filesize($absolute);
        if ($actual === false || $actual !== $size || $actual > self::MAX_TOTAL_BYTES) {
            $this->skip('size_mismatch');
            return null;
        }

        $content = file_get_contents($absolute);
        if ($content === false || strlen($content) !== $size) {
            $this->skip('read_failed');
            return null;
        }

        // Defence in depth: confirm the bytes really are an allowed image type.
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!is_string($detected) || !in_array(strtolower($detected), self::PHOTO_MIMES, true)) {
            $this->skip('mime_mismatch');
            return null;
        }

        $name = basename(str_replace('\\', '/', (string) ($row['file_name'] ?? '')));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'photo-' . (int) $row['id'] . '.' . self::extensionFor($mime);
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        return [
            'attachment' => [
                'file_name' => $name,
                'mime' => $mime,
                'content' => base64_encode($content),
            ],
            'bytes' => $size,
        ];
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    /** Generic log code only — never a storage path or other internal detail. */
    private function skip(string $reason): void
    {
        error_log('CRM7: email photo attachment skipped (' . $reason . ')');
    }
}
