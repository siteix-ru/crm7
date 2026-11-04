<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RequestDraftRepository
{
    public function listForUser(int $userId, ?int $clientId = null): array
    {
        $sql = 'SELECT ID AS id, client_id, subject, body, priority, items, created_at, updated_at
                FROM request_drafts
                WHERE user_id = ?';
        $params = [$userId];

        if ($clientId !== null && $clientId > 0) {
            $sql .= ' AND client_id = ?';
            $params[] = $clientId;
        }

        $sql .= ' ORDER BY updated_at DESC, ID DESC LIMIT 100';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function listForClient(int $clientId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, user_id, client_id, subject, body, priority, items, created_at, updated_at
             FROM request_drafts
             WHERE client_id = ? OR (client_id IS NULL AND user_id = ?)
             ORDER BY updated_at DESC, ID DESC
             LIMIT 100'
        );
        $stmt->execute([$clientId, $clientId]);

        return $stmt->fetchAll() ?: [];
    }

    public function findAccessible(int $id, int $actorId, int $actorLevel): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT d.ID AS id, d.user_id, d.client_id, d.subject, d.body, d.priority, d.price_type_id,
                    d.items, d.created_at, d.updated_at, u.LEVEL AS owner_level
             FROM request_drafts d
             INNER JOIN users u ON u.ID = d.user_id
             WHERE d.ID = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        if ((int) $row['user_id'] === $actorId) {
            return $row;
        }

        if ($actorLevel < 10) {
            return null;
        }

        $clientId = $row['client_id'] !== null ? (int) $row['client_id'] : 0;
        $ownerIsClient = (int) ($row['owner_level'] ?? 0) === 5;

        if ($clientId > 0 || $ownerIsClient) {
            return $row;
        }

        return null;
    }

    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, user_id, client_id, subject, body, priority, price_type_id, items, created_at, updated_at
             FROM request_drafts
             WHERE ID = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(int $userId, array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO request_drafts (user_id, client_id, subject, body, priority, price_type_id, items)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['client_id'],
            $data['subject'],
            $data['body'],
            $data['priority'],
            $data['price_type_id'] ?? null,
            $data['items'],
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE request_drafts
             SET client_id = ?, subject = ?, body = ?, priority = ?, price_type_id = ?, items = ?, updated_at = NOW()
             WHERE ID = ?'
        );
        $stmt->execute([
            $data['client_id'],
            $data['subject'],
            $data['body'],
            $data['priority'],
            $data['price_type_id'] ?? null,
            $data['items'],
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM request_drafts WHERE ID = ?');
        $stmt->execute([$id]);
    }
}
