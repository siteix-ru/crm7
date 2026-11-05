<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RequestActivityRepository
{
    public function add(
        int $requestId,
        int $userId,
        string $typeCode,
        string $title,
        ?string $body,
        ?string $createdAt = null,
        ?bool $isInternal = null,
        ?int $attachmentId = null
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO request_activities
                (request_id, user_id, type_code, title, body, created_at, is_internal, attachment_id)
             VALUES (?, ?, ?, ?, ?, COALESCE(?, NOW()), ?, ?)'
        );
        $stmt->execute([
            $requestId,
            $userId,
            $typeCode,
            $title,
            $body,
            $createdAt,
            $isInternal === null ? null : ($isInternal ? 1 : 0),
            $attachmentId,
        ]);
    }

    public function clearAttachment(int $attachmentId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE request_activities SET attachment_id = NULL WHERE attachment_id = ?'
        );
        $stmt->execute([$attachmentId]);
    }

    public function listByRequest(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.ID AS id, a.user_id, a.type_code, a.title, a.body, a.is_internal, a.attachment_id,
                    a.created_at,
                    u.FULL_NAME AS user_name, u.LEVEL AS user_level, u.DOLGNOST AS user_position,
                    t.audience AS audience,
                    f.file_name AS attachment_name, f.size AS attachment_size, f.mime AS attachment_mime
             FROM request_activities a
             INNER JOIN users u ON u.ID = a.user_id
             LEFT JOIN activity_types t ON t.code = a.type_code
             LEFT JOIN request_attachments f ON f.ID = a.attachment_id
             WHERE a.request_id = ?
             ORDER BY a.created_at ASC, a.ID ASC'
        );
        $stmt->execute([$requestId]);

        return $stmt->fetchAll() ?: [];
    }
}
