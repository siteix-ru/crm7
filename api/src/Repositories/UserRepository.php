<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRepository
{
    public function findByLogin(string $login): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE LOGIN = ? LIMIT 1');
        $stmt->execute([$login]);

        return $stmt->fetch() ?: null;
    }

    public function findAnyByLogin(string $login): ?array
    {
        return $this->findByLogin($login);
    }

    public function findAnyByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE EMAIL = ? LIMIT 1');
        $stmt->execute([$email]);

        return $stmt->fetch() ?: null;
    }

    public function findAnyByPhone(string $phone): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE PHONE = ? LIMIT 1');
        $stmt->execute([$phone]);

        return $stmt->fetch() ?: null;
    }

    public function findActiveClientByInn(string $inn): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT ID, FULL_NAME, PHONE, EMAIL FROM users
             WHERE INN = ? AND LEVEL = 5 AND reg_state IN ('active', 'pending')
             LIMIT 1"
        );
        $stmt->execute([$inn]);

        return $stmt->fetch() ?: null;
    }

    public function createActiveClient(array $data): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "INSERT INTO users (ACTIVE, LOGIN, PASSWORD, LEVEL, FULL_NAME,
 STATUS, COMPANY, INN, DOLGNOST, CITY, ADDRESS, PHONE, EMAIL, reg_state)
             VALUES ('Y', ?, ?, 5, ?, 'Y', ?, ?, ?, ?, ?, ?, ?, 'active')"
        );

        $stmt->execute([
            $data['login'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['name'],
            ($data['company'] ?? '') !== '' ? $data['company'] : null,
            ($data['inn'] ?? '') !== '' ? $data['inn'] : null,
            ($data['position'] ?? '') !== '' ? $data['position'] : null,
            ($data['city'] ?? '') !== '' ? $data['city'] : null,
            ($data['address'] ?? '') !== '' ? $data['address'] : null,
            ($data['phone'] ?? '') !== '' ? $data['phone'] : null,
            ($data['email'] ?? '') !== '' ? $data['email'] : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function createPendingClient(array $data): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "INSERT INTO users (ACTIVE, LOGIN, PASSWORD, LEVEL, FULL_NAME,
 STATUS, COMPANY, INN, CITY, ADDRESS, PHONE, EMAIL, reg_state)
             VALUES ('N', ?, ?, 5, ?, 'Y', ?, ?, ?, ?, ?, ?, 'pending')"
        );

        $stmt->execute([
            $data['login'],
            $data['password'],
            $data['name'],
            $data['company'] !== '' ? $data['company'] : null,
            ($data['inn'] ?? '') !== '' ? $data['inn'] : null,
            ($data['city'] ?? '') !== '' ? $data['city'] : null,
            ($data['address'] ?? '') !== '' ? $data['address'] : null,
            $data['phone'] !== '' ? $data['phone'] : null,
            $data['email'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function listPendingClients(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.ID AS id, u.FULL_NAME AS name, u.EMAIL AS email, u.PHONE AS phone,
                    u.COMPANY AS company, u.INN AS inn, u.DOLGNOST AS position,
                    u.CITY AS city, u.ADDRESS AS address, u.TIME_ADD AS created_at,
                    h.created_at AS registered_at
             FROM users u
             LEFT JOIN user_history h ON h.user_id = u.ID AND h.event = ?
             WHERE u.LEVEL = 5 AND u.reg_state = ?
             ORDER BY u.ID ASC
             LIMIT 200'
        );
        $stmt->execute(['registered', 'pending']);

        return $stmt->fetchAll() ?: [];
    }

    public function markClientState(int $id, string $state): void
    {
        if ($state === 'active') {
            $stmt = Database::pdo()->prepare(
                "UPDATE users SET reg_state = 'active', ACTIVE = 'Y', STATUS = 'Y', TIME_ACTIVE = NOW() WHERE ID = ?"
            );
        } else {
            $stmt = Database::pdo()->prepare(
                "UPDATE users SET reg_state = 'rejected', ACTIVE = 'N', STATUS = 'Y' WHERE ID = ?"
            );
        }

        $stmt->execute([$id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET ACTIVE = ? WHERE ID = ?');
        $stmt->execute([$active ? 'Y' : 'N', $id]);
    }

    public function updateContacts(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET FULL_NAME = ?, COMPANY = ?, INN = ?, DOLGNOST = ?, CITY = ?, ADDRESS = ?, PHONE = ?, EMAIL = ? WHERE ID = ?'
        );
        $stmt->execute([
            $data['name'],
            $data['company'] !== '' ? $data['company'] : null,
            $data['inn'] !== '' ? $data['inn'] : null,
            $data['position'] !== '' ? $data['position'] : null,
            ($data['city'] ?? '') !== '' ? $data['city'] : null,
            ($data['address'] ?? '') !== '' ? $data['address'] : null,
            $data['phone'] !== '' ? $data['phone'] : null,
            $data['email'] !== '' ? $data['email'] : null,
            $id,
        ]);
    }

    public function updateLogin(int $id, string $login): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET LOGIN = ? WHERE ID = ?');
        $stmt->execute([$login, $id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function findBySid(string $sid): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE SID = ? LIMIT 1');
        $stmt->execute([$sid]);

        return $stmt->fetch() ?: null;
    }

    public function countActiveAdmins(): int
    {
        $stmt = Database::pdo()->query(
            "SELECT COUNT(*) FROM users
             WHERE LEVEL >= 50
               AND ACTIVE = 'Y'
               AND STATUS = 'Y'
               AND (reg_state IS NULL OR reg_state = 'active')"
        );

        return (int) $stmt->fetchColumn();
    }

    public function countActiveSuperAdmins(): int
    {
        $stmt = Database::pdo()->query(
            "SELECT COUNT(*) FROM users
             WHERE LEVEL >= 90
               AND ACTIVE = 'Y'
               AND STATUS = 'Y'
               AND (reg_state IS NULL OR reg_state = 'active')"
        );

        return (int) $stmt->fetchColumn();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM users WHERE EMAIL = ? AND ACTIVE = 'Y' LIMIT 1"
        );
        $stmt->execute([$email]);

        return $stmt->fetch() ?: null;
    }

    public function updatePassword(int $id, string $hash): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET PASSWORD = ? WHERE ID = ?');
        $stmt->execute([$hash, $id]);
    }

    public function touchActivity(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET TIME_ACTIVE = NOW() WHERE ID = ?');
        $stmt->execute([$id]);
    }

    public function setConsent(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET CONSENT_AT = NOW() WHERE ID = ?');
        $stmt->execute([$id]);
    }

    public function updateName(int $id, string $name): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET FULL_NAME = ? WHERE ID = ?');
        $stmt->execute([$name, $id]);
    }

    public function updateProfile(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users
             SET FULL_NAME = ?, DOLGNOST = ?, DEPARTMENT = ?, CITY = ?, OFFICE = ?,
                 COMPANY = ?, INN = ?, ADDRESS = ?, PHONE = ?, EMAIL = ?
             WHERE ID = ?'
        );
        $nullable = static fn (string $value): ?string => $value !== '' ? $value : null;
        $stmt->execute([
            $data['name'],
            $nullable((string) $data['position']),
            $nullable((string) $data['department']),
            $nullable((string) $data['city']),
            $nullable((string) $data['office']),
            $nullable((string) $data['company']),
            $nullable((string) $data['inn']),
            $nullable((string) $data['address']),
            $nullable((string) $data['phone']),
            $nullable((string) $data['email']),
            $id,
        ]);
    }

    public function updateAvatar(int $id, ?string $path): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET AVATAR_PATH = ? WHERE ID = ?');
        $stmt->execute([$path, $id]);
    }

    public function listStaffFacets(): array
    {
        $rows = Database::pdo()->query(
            'SELECT CITY AS city, DEPARTMENT AS department, OFFICE AS office, DOLGNOST AS position
             FROM users
             WHERE LEVEL >= 10'
        )->fetchAll() ?: [];
        $facets = [
            'cities' => [],
            'departments' => [],
            'offices' => [],
            'positions' => [],
        ];

        foreach ($rows as $row) {
            foreach (['city' => 'cities', 'department' => 'departments', 'office' => 'offices', 'position' => 'positions'] as $source => $target) {
                $value = trim((string) ($row[$source] ?? ''));

                if ($value !== '') {
                    $facets[$target][$value] = true;
                }
            }
        }

        foreach ($facets as $key => $values) {
            $list = array_keys($values);
            natcasesort($list);
            $facets[$key] = array_values($list);
        }

        return $facets;
    }

    public function listManagers(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT ID AS id, SID AS sid, LOGIN AS login, FULL_NAME AS name, LEVEL AS level,
                    EMAIL AS email, PHONE AS phone
             FROM users
             WHERE ACTIVE = 'Y' AND STATUS = 'Y' AND LEVEL IN (10, 50, 90)
             ORDER BY FULL_NAME ASC
             LIMIT 500"
        );

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Пользователи выбранных ролей для выдачи доступа к складу.
     * Поле has_access = уже есть доступ к складу.
     *
     * @param array<int, int> $levels
     * @return array<int, array<string, mixed>>
     */
    public function warehouseCandidates(string $stockSid, array $levels, string $query, string $sort, int $limit, int $offset): array
    {
        [$where, $params] = $this->warehouseCandidatesWhere($levels, $query);

        if ($where === null) {
            return [];
        }

        $order = match ($sort) {
            'name_desc' => 'u.FULL_NAME DESC',
            'login_asc' => 'u.LOGIN ASC',
            'login_desc' => 'u.LOGIN DESC',
            'level_desc' => 'u.LEVEL DESC, u.FULL_NAME ASC',
            'level_asc' => 'u.LEVEL ASC, u.FULL_NAME ASC',
            'access_desc' => 'has_access DESC, u.FULL_NAME ASC',
            'access_asc' => 'has_access ASC, u.FULL_NAME ASC',
            default => 'u.FULL_NAME ASC',
        };

        $sql = "SELECT u.ID AS id, u.SID AS sid, u.LOGIN AS login, u.FULL_NAME AS name, u.LEVEL AS level, u.EMAIL AS email,
                       EXISTS (SELECT 1 FROM user_level_stock uls WHERE uls.USER_SID = u.SID AND uls.STOCK_SID = ?) AS has_access
                FROM users u
                WHERE {$where}
                ORDER BY {$order}
                LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(array_merge([$stockSid], $params));

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<int, int> $levels
     */
    public function countWarehouseCandidates(array $levels, string $query): int
    {
        [$where, $params] = $this->warehouseCandidatesWhere($levels, $query);

        if ($where === null) {
            return 0;
        }

        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM users u WHERE {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<int, int> $levels
     * @return array{0: ?string, 1: array<int, mixed>}
     */
    private function warehouseCandidatesWhere(array $levels, string $query): array
    {
        $levels = array_values(array_unique(array_filter(
            array_map('intval', $levels),
            static fn (int $level): bool => $level > 0
        )));

        if ($levels === []) {
            return [null, []];
        }

        $placeholders = implode(',', array_fill(0, count($levels), '?'));
        $params = $levels;
        $where = "u.ACTIVE = 'Y' AND u.STATUS = 'Y' AND u.LEVEL IN ({$placeholders})";

        if ($query !== '') {
            $where .= ' AND (u.FULL_NAME LIKE ? OR u.LOGIN LIKE ? OR u.EMAIL LIKE ?)';
            $like = '%' . $query . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [$where, $params];
    }

    public function listClientFacets(): array
    {
        $rows = Database::pdo()->query(
            "SELECT DISTINCT CITY AS city
             FROM users
             WHERE LEVEL = 5 AND CITY IS NOT NULL AND CITY <> ''"
        )->fetchAll() ?: [];
        $cities = [];

        foreach ($rows as $row) {
            $city = trim((string) ($row['city'] ?? ''));

            if ($city !== '') {
                $cities[$city] = true;
            }
        }

        $list = array_keys($cities);
        natcasesort($list);

        return ['cities' => array_values($list)];
    }

    public function listClients(string $query, int $limit = 50, int $offset = 0, array $filters = []): array
    {
        [$whereSql, $params] = $this->clientsWhere($query, $filters);

        $order = implode(', ', $this->clientOrderTerms((string) ($filters['sort'] ?? 'name_asc')));
        $limitSql = $limit > 0
            ? ' LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset)
            : '';

        $sql = 'SELECT u.ID AS id, u.SID AS sid, u.LOGIN AS login, u.FULL_NAME AS name,
                        u.EMAIL AS email, u.PHONE AS phone, u.COMPANY AS company, u.INN AS inn,
                        u.DOLGNOST AS position, u.CITY AS city, u.ADDRESS AS address,
                        u.LEVEL AS level, u.ACTIVE AS active, u.reg_state AS reg_state,
                        u.TIME_ADD AS registered_at,
                        cm.manager_id AS manager_id, mu.FULL_NAME AS manager_name,
                        (SELECT COUNT(*) FROM requests r WHERE r.client_id = u.ID) AS requests_total
                 FROM users u
                 LEFT JOIN client_managers cm ON cm.client_id = u.ID
                 LEFT JOIN users mu ON mu.ID = cm.manager_id
                 WHERE ' . $whereSql . '
                 ORDER BY ' . $order . $limitSql;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function countClients(string $query, array $filters = []): int
    {
        [$whereSql, $params] = $this->clientsWhere($query, $filters);

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM users u
             LEFT JOIN client_managers cm ON cm.client_id = u.ID
             WHERE ' . $whereSql
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, string>
     */
    private function clientOrderTerms(string $sort): array
    {
        $map = [
            'name_asc' => 'u.FULL_NAME ASC',
            'name_desc' => 'u.FULL_NAME DESC',
            'created_desc' => 'u.TIME_ADD DESC',
            'created_asc' => 'u.TIME_ADD ASC',
            'requests_desc' => 'requests_total DESC',
            'requests_asc' => 'requests_total ASC',
        ];

        $terms = [];
        $hasName = false;

        foreach (explode(',', $sort) as $criterion) {
            $criterion = trim($criterion);
            $term = $map[$criterion] ?? null;

            if ($term === null || in_array($term, $terms, true)) {
                continue;
            }

            $terms[] = $term;

            if (str_starts_with($term, 'u.FULL_NAME')) {
                $hasName = true;
            }
        }

        if (!$hasName) {
            $terms[] = 'u.FULL_NAME ASC';
        }

        $terms[] = 'u.ID ASC';

        return $terms;
    }

    private function clientsWhere(string $query, array $filters = []): array
    {
        $where = ['u.LEVEL = 5'];
        $params = [];

        $states = array_values(array_filter((array) ($filters['state'] ?? [])));
        $conditions = [];

        foreach ($states as $state) {
            $condition = match ($state) {
                'active' => "u.reg_state = 'active' AND u.ACTIVE = 'Y'",
                'pending' => "u.reg_state = 'pending'",
                'blocked' => "u.reg_state = 'active' AND u.ACTIVE = 'N'",
                default => null
            };

            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        if ($conditions === []) {
            $where[] = "u.reg_state IN ('active', 'pending')";
        } else {
            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }

        if (($filters['from'] ?? '') !== '') {
            $where[] = 'u.TIME_ADD >= ?';
            $params[] = (string) $filters['from'] . ' 00:00:00';
        }

        if (($filters['to'] ?? '') !== '') {
            $where[] = 'u.TIME_ADD <= ?';
            $params[] = (string) $filters['to'] . ' 23:59:59';
        }

        $manager = (string) ($filters['manager'] ?? '');

        if ($manager === 'mine') {
            $where[] = 'cm.manager_id = ?';
            $params[] = (int) ($filters['manager_id'] ?? 0);
        } elseif ($manager === 'none') {
            $where[] = 'cm.client_id IS NULL';
        }

        $managerIds = [];
        $includeNone = false;

        foreach ((array) ($filters['managers'] ?? []) as $value) {
            $value = (string) $value;

            if ($value === 'none') {
                $includeNone = true;
            } elseif (ctype_digit($value) && (int) $value > 0) {
                $managerIds[] = (int) $value;
            }
        }

        if ($managerIds !== [] || $includeNone) {
            $managerParts = [];

            if ($managerIds !== []) {
                $managerParts[] = 'cm.manager_id IN (' . implode(',', array_fill(0, count($managerIds), '?')) . ')';
                array_push($params, ...$managerIds);
            }

            if ($includeNone) {
                $managerParts[] = 'cm.client_id IS NULL';
            }

            $where[] = '(' . implode(' OR ', $managerParts) . ')';
        }

        $cities = array_values(array_filter(
            array_map(static fn ($value): string => trim((string) $value), (array) ($filters['cities'] ?? [])),
            static fn (string $value): bool => $value !== ''
        ));

        if ($cities !== []) {
            $where[] = 'u.CITY IN (' . implode(',', array_fill(0, count($cities), '?')) . ')';
            array_push($params, ...$cities);
        }

        if ($query !== '') {
            $where[] = '(u.FULL_NAME LIKE ? OR u.EMAIL LIKE ? OR u.PHONE LIKE ? OR u.COMPANY LIKE ?
                OR u.LOGIN LIKE ? OR u.INN LIKE ? OR u.CITY LIKE ? OR u.ADDRESS LIKE ?)';
            $like = '%' . $query . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    public function capabilitiesForLevel(int $level): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT capability_code FROM level_capabilities WHERE level = ? ORDER BY capability_code'
        );
        $stmt->execute([$level]);

        return array_map('strval', array_column($stmt->fetchAll(), 'capability_code'));
    }

    public function toProfile(array $row): array
    {
        return [
            'id' => (int) $row['ID'],
            'sid' => (string) $row['SID'],
            'login' => (string) $row['LOGIN'],
            'name' => (string) ($row['FULL_NAME'] ?? ''),
            'level' => (int) $row['LEVEL'],
            'email' => (string) ($row['EMAIL'] ?? ''),
            'phone' => (string) ($row['PHONE'] ?? ''),
            'company' => (string) ($row['COMPANY'] ?? ''),
            'position' => (string) ($row['DOLGNOST'] ?? ''),
            'department' => (string) ($row['DEPARTMENT'] ?? ''),
            'city' => (string) ($row['CITY'] ?? ''),
            'office' => (string) ($row['OFFICE'] ?? ''),
            'inn' => (string) ($row['INN'] ?? ''),
            'address' => (string) ($row['ADDRESS'] ?? ''),
        ];
    }
}
