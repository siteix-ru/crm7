<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ItemGroupRepository
{
    public function all(bool $onlyEnabled = false): array
    {
        $where = $onlyEnabled ? 'WHERE g.ENABLED = 1' : '';
        $rows = Database::pdo()->query(
            "SELECT g.ID AS id, g.TITLE AS title, g.DESCRIPTION AS description, g.ENABLED AS enabled,
                    g.SORT AS sort,
                    (SELECT COUNT(*) FROM nomenclature n WHERE n.group_id = g.ID) AS positions_count
             FROM item_groups g
             {$where}
             ORDER BY g.SORT ASC, g.ID ASC"
        )->fetchAll() ?: [];

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'enabled' => (bool) $row['enabled'],
            'sort' => (int) $row['sort'],
            'positions_count' => (int) $row['positions_count'],
        ], $rows);
    }

    /**
     * Позиции (номенклатура), назначенные группе.
     */
    public function items(int $id): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT SID AS sid, NAME AS name, UNIT AS unit, TYPE AS type, ARTICLE AS article, ACTIVE AS active
             FROM nomenclature
             WHERE group_id = ?
             ORDER BY NAME ASC'
        );
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(static fn (array $row): array => [
            'sid' => (string) $row['sid'],
            'name' => (string) $row['name'],
            'unit' => (string) ($row['unit'] ?? ''),
            'type' => (string) ($row['type'] ?? 'product'),
            'article' => (string) ($row['article'] ?? ''),
            'active' => (string) ($row['active'] ?? 'Y') === 'Y',
        ], $rows);
    }

    /**
     * Есть ли позиции без группы (номенклатура без группы, присутствующая на складах).
     */
    public function hasUngrouped(): bool
    {
        $value = Database::pdo()->query(
            "SELECT EXISTS(
                SELECT 1 FROM nomenclature n
                WHERE n.group_id IS NULL
                  AND EXISTS (SELECT 1 FROM stock_levels l WHERE l.NAME_SID = n.SID)
            )"
        )->fetchColumn();

        return (int) $value === 1;
    }

    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, TITLE AS title, DESCRIPTION AS description, ENABLED AS enabled, SORT AS sort
             FROM item_groups WHERE ID = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'enabled' => (bool) $row['enabled'],
            'sort' => (int) $row['sort'],
        ];
    }

    public function titleTaken(string $title, ?int $excludeId = null): bool
    {
        $sql = 'SELECT ID FROM item_groups WHERE TITLE = ?';
        $params = [$title];

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND ID <> ?';
            $params[] = $excludeId;
        }

        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    public function nextSort(): int
    {
        $value = Database::pdo()->query('SELECT COALESCE(MAX(SORT), 0) + 10 FROM item_groups')->fetchColumn();

        return (int) $value;
    }

    public function create(string $title, string $description, bool $enabled, int $sort): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO item_groups (TITLE, DESCRIPTION, ENABLED, SORT) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$title, $description, $enabled ? 1 : 0, $sort]);

        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, string $title, string $description, bool $enabled): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE item_groups SET TITLE = ?, DESCRIPTION = ?, ENABLED = ? WHERE ID = ?'
        );
        $stmt->execute([$title, $description, $enabled ? 1 : 0, $id]);
    }

    public function countItems(int $id): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM nomenclature WHERE group_id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM item_groups WHERE ID = ?')->execute([$id]);
    }

    public function clearAssignments(int $id): void
    {
        Database::pdo()->prepare('UPDATE nomenclature SET group_id = NULL WHERE group_id = ?')->execute([$id]);
    }
}
