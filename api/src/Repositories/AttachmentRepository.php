<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AttachmentRepository
{
    public function add(
        int $requestId,
        int $userId,
        string $fileName,
        string $storagePath,
        string $mime,
        int $size,
        bool $isInternal = false,
        ?string $comment = null
    ): int {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO request_attachments
                (request_id, user_id, file_name, storage_path, mime, size, is_internal, `comment`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $requestId,
            $userId,
            $fileName,
            $storagePath,
            $mime,
            $size,
            $isInternal ? 1 : 0,
            $comment !== '' ? $comment : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM request_attachments WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function listByRequest(int $requestId, bool $includeInternal = true): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.ID AS id, a.user_id, a.file_name, a.mime, a.size, a.is_internal, a.`comment`,
                    a.created_at, u.FULL_NAME AS user_name
             FROM request_attachments a
             INNER JOIN users u ON u.ID = a.user_id
             WHERE a.request_id = ? AND (a.is_internal = 0 OR ? = 1)
             ORDER BY a.created_at ASC, a.ID ASC'
        );
        $stmt->execute([$requestId, $includeInternal ? 1 : 0]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Public image attachments of a request, optionally restricted to explicit IDs.
     * Only IDs and request ownership are trusted; paths are never accepted from input.
     */
    public function photosForEmail(int $requestId, array $ids = [], int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        // Filter before LIMIT so non-image files cannot displace valid photos.
        // The resolver re-checks MIME and the actual bytes; this is only the
        // selection step.
        $where = "request_id = ? AND is_internal = 0
                  AND mime IN ('image/jpeg', 'image/png', 'image/webp')";
        $params = [$requestId];

        if ($ids !== []) {
            $ints = [];
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ints[$id] = $id;
                }
            }
            if ($ints === []) {
                return [];
            }
            $ints = array_values($ints);
            $where .= ' AND ID IN (' . implode(',', array_fill(0, count($ints), '?')) . ')';
            $params = array_merge($params, $ints);
        }

        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, request_id, file_name, storage_path, mime, size, is_internal
             FROM request_attachments
             WHERE ' . $where . '
             ORDER BY ID DESC LIMIT ' . $limit
        );
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM request_attachments WHERE ID = ?');
        $stmt->execute([$id]);
    }
}
