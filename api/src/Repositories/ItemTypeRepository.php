<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ItemTypeRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return Database::pdo()->query(
            'SELECT t.ID AS id, t.CODE AS code, t.TITLE AS title, t.DESCRIPTION AS description,
                    t.ENABLED AS enabled, t.SORT AS sort,
                    (SELECT COUNT(*) FROM nomenclature n WHERE n.TYPE = t.CODE) AS positions_count
             FROM item_types t
             ORDER BY t.SORT ASC, t.ID ASC'
        )->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT t.ID AS id, t.CODE AS code, t.TITLE AS title, t.DESCRIPTION AS description,
                    t.ENABLED AS enabled, t.SORT AS sort,
                    (SELECT COUNT(*) FROM nomenclature n WHERE n.TYPE = t.CODE) AS positions_count
             FROM item_types t
             WHERE t.ID = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, CODE AS code, TITLE AS title, DESCRIPTION AS description,
                    ENABLED AS enabled, SORT AS sort
             FROM item_types
             WHERE CODE = ?
             LIMIT 1'
        );
        $stmt->execute([$code]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @return array<int, string>
     */
    public function enabledCodes(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['code'],
            Database::pdo()->query(
                'SELECT CODE AS code FROM item_types WHERE ENABLED = 1 ORDER BY SORT ASC, ID ASC'
            )->fetchAll() ?: []
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function enabled(): array
    {
        return array_map(
            static fn (array $row): array => [
                'code' => (string) $row['code'],
                'title' => (string) $row['title'],
            ],
            Database::pdo()->query(
                'SELECT CODE AS code, TITLE AS title
                 FROM item_types
                 WHERE ENABLED = 1
                 ORDER BY SORT ASC, ID ASC'
            )->fetchAll() ?: []
        );
    }

    public function create(string $code, string $title, string $description, bool $enabled, int $sort): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO item_types (CODE, TITLE, DESCRIPTION, ENABLED, SORT)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $code,
            $title,
            $description !== '' ? $description : null,
            $enabled ? 1 : 0,
            $sort,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, string $title, string $description, bool $enabled): void
    {
        Database::pdo()->prepare(
            'UPDATE item_types SET TITLE = ?, DESCRIPTION = ?, ENABLED = ? WHERE ID = ?'
        )->execute([
            $title,
            $description !== '' ? $description : null,
            $enabled ? 1 : 0,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM item_types WHERE ID = ?')->execute([$id]);
    }

    public function nextSort(): int
    {
        $value = Database::pdo()->query('SELECT COALESCE(MAX(SORT), 0) + 10 FROM item_types')
            ->fetchColumn();

        return (int) $value;
    }

    public function countAll(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM item_types')->fetchColumn();
    }

    public function countEnabled(?int $excludeId = null): int
    {
        if ($excludeId === null) {
            return (int) Database::pdo()->query('SELECT COUNT(*) FROM item_types WHERE ENABLED = 1')
                ->fetchColumn();
        }

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM item_types WHERE ENABLED = 1 AND ID <> ?'
        );
        $stmt->execute([$excludeId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(string $code): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT SID AS sid, NAME AS name, UNIT AS unit, TYPE AS type, ARTICLE AS article, ACTIVE AS active
             FROM nomenclature
             WHERE TYPE = ?
             ORDER BY NAME ASC'
        );
        $stmt->execute([$code]);

        return array_map(static fn (array $row): array => [
            'sid' => (string) $row['sid'],
            'name' => (string) $row['name'],
            'unit' => (string) ($row['unit'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'article' => (string) ($row['article'] ?? ''),
            'active' => (string) ($row['active'] ?? 'Y') === 'Y',
        ], $stmt->fetchAll() ?: []);
    }

    public function countNomenclature(string $code): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM nomenclature WHERE TYPE = ?');
        $stmt->execute([$code]);

        return (int) $stmt->fetchColumn();
    }
}
