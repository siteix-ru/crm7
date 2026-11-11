<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RequestRepository
{
    private const DETAIL_SELECT = 'SELECT r.*, s.title AS status_title, s.color AS status_color,
               s.is_final AS status_is_final, s.sort AS status_sort,
               CASE WHEN r.due_at IS NOT NULL AND r.due_at < NOW() AND s.is_final = 0 THEN 1 ELSE 0 END AS is_overdue,
               c.FULL_NAME AS client_name, c.EMAIL AS client_email, c.PHONE AS client_phone, c.INN AS client_inn,
               m.FULL_NAME AS manager_name,
               (SELECT COUNT(*) FROM request_items ri WHERE ri.request_id = r.ID) AS items_count
        FROM requests r
        INNER JOIN request_statuses s ON s.code = r.status_id
        INNER JOIN users c ON c.ID = r.client_id
        LEFT JOIN users m ON m.ID = r.manager_id';

    public function create(array $data): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO requests (subject, body, client_id, manager_id, status_id, priority, price_type_id, source, due_at, substitution_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?)'
        );

        $stmt->execute([
            $data['subject'],
            $data['body'],
            $data['client_id'],
            $data['manager_id'],
            $data['status_id'],
            $data['priority'],
            $data['price_type_id'] ?? null,
            $data['source'],
            $data['sla_hours'],
            $data['substitution_id'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function setNumber(int $id): string
    {
        $pdo = Database::pdo();

        $pdo->prepare(
            "UPDATE requests SET number = CONCAT('REQ-', YEAR(created_at), '-', LPAD(ID, 6, '0')) WHERE ID = ?"
        )->execute([$id]);

        $stmt = $pdo->prepare('SELECT number FROM requests WHERE ID = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM requests WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function isFinalRequest(int $id): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.is_final FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE r.ID = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM requests WHERE ID = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function detail(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(self::DETAIL_SELECT . ' WHERE r.ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function applyTransition(int $id, string $status, array $timestampOps): void
    {
        $allowed = ['first_response_at', 'resolved_at', 'closed_at'];

        $sets = ['status_id = ?', 'version = version + 1', 'updated_at = NOW()'];
        $params = [$status];

        foreach ($timestampOps as $column => $op) {
            if (in_array($column, $allowed, true)) {
                $sets[] = $column . ' = ' . ($op === 'set' ? 'NOW()' : 'NULL');
            }
        }

        $params[] = $id;

        $stmt = Database::pdo()->prepare('UPDATE requests SET ' . implode(', ', $sets) . ' WHERE ID = ?');
        $stmt->execute($params);
    }

    public function assignManager(int $id, ?int $managerId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE requests SET manager_id = ?, version = version + 1, updated_at = NOW() WHERE ID = ?'
        );
        $stmt->execute([$managerId, $id]);
    }

    public function listForClient(int $clientId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        $stmt = Database::pdo()->prepare(
            self::DETAIL_SELECT . ' WHERE r.client_id = ? ORDER BY r.ID DESC LIMIT ' . $limit
        );
        $stmt->execute([$clientId]);

        return $stmt->fetchAll() ?: [];
    }

    public function listForClientAll(int $clientId): array
    {
        $stmt = Database::pdo()->prepare(
            self::DETAIL_SELECT . ' WHERE r.client_id = ? ORDER BY r.ID DESC'
        );
        $stmt->execute([$clientId]);

        return $stmt->fetchAll() ?: [];
    }

    public function findBySubstitution(int $substitutionId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM requests WHERE substitution_id = ? ORDER BY ID ASC');
        $stmt->execute([$substitutionId]);

        return $stmt->fetchAll() ?: [];
    }

    public function openByManager(int $managerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.* FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE r.manager_id = ? AND s.is_final = 0 AND r.substitution_id IS NULL
             ORDER BY r.ID ASC'
        );
        $stmt->execute([$managerId]);

        return $stmt->fetchAll() ?: [];
    }

    public function openByClient(int $clientId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.* FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE r.client_id = ? AND s.is_final = 0
             ORDER BY r.ID ASC'
        );
        $stmt->execute([$clientId]);

        return $stmt->fetchAll() ?: [];
    }

    public function applySubstitution(int $id, ?int $managerId, ?int $substitutionId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE requests SET manager_id = ?, substitution_id = ?, version = version + 1, updated_at = NOW() WHERE ID = ?'
        );
        $stmt->execute([$managerId, $substitutionId, $id]);
    }

    public function list(array $filters, array $scope, int $page, int $perPage): array
    {
        [$where, $params] = $this->scopeConditions($scope);

        $statusCodes = is_array($filters['statuses'] ?? null)
            ? array_values(array_filter(
                array_map(static fn ($value): string => trim((string) $value), $filters['statuses']),
                static fn (string $code): bool => $code !== ''
            ))
            : [];

        if ($statusCodes !== []) {
            $placeholders = implode(',', array_fill(0, count($statusCodes), '?'));
            $where[] = 'r.status_id IN (' . $placeholders . ')';

            foreach ($statusCodes as $code) {
                $params[] = $code;
            }
        } elseif (!empty($filters['status'])) {
            $where[] = 'r.status_id = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['manager_id'])) {
            $where[] = 'r.manager_id = ?';
            $params[] = (int) $filters['manager_id'];
        }

        if (!empty($filters['client_id'])) {
            $where[] = 'r.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }

        $state = (string) ($filters['state'] ?? '');

        if ($state === 'open') {
            $where[] = 's.is_final = 0';
        } elseif ($state === 'closed') {
            $where[] = 's.is_final = 1';
        } elseif ($state === 'overdue') {
            $where[] = 's.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW()';
        }

        $states = is_array($filters['states'] ?? null)
            ? array_values(array_intersect(
                array_map(static fn ($value): string => (string) $value, $filters['states']),
                ['open', 'closed', 'overdue']
            ))
            : [];

        if ($states !== []) {
            $stateConditions = [];

            foreach ($states as $stateValue) {
                if ($stateValue === 'open') {
                    $stateConditions[] = 's.is_final = 0';
                } elseif ($stateValue === 'closed') {
                    $stateConditions[] = 's.is_final = 1';
                } elseif ($stateValue === 'overdue') {
                    $stateConditions[] = '(s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW())';
                }
            }

            if ($stateConditions !== []) {
                $where[] = '(' . implode(' OR ', $stateConditions) . ')';
            }
        }

        $overdueValues = is_array($filters['overdue'] ?? null)
            ? array_values(array_intersect(
                array_map(static fn ($value): string => (string) $value, $filters['overdue']),
                ['yes', 'no']
            ))
            : [];
        $overdueConditions = [];

        foreach ($overdueValues as $overdueValue) {
            if ($overdueValue === 'yes') {
                $overdueConditions[] = '(s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW())';
            } elseif ($overdueValue === 'no') {
                $overdueConditions[] = '(s.is_final = 1 OR r.due_at IS NULL OR r.due_at >= NOW())';
            }
        }

        if ($overdueConditions !== []) {
            $where[] = '(' . implode(' OR ', $overdueConditions) . ')';
        }

        $warehouseIds = is_array($filters['warehouse_ids'] ?? null)
            ? array_values(array_filter(array_map('intval', $filters['warehouse_ids']), static fn (int $id): bool => $id > 0))
            : [];

        if ($warehouseIds !== []) {
            $placeholders = implode(',', array_fill(0, count($warehouseIds), '?'));
            $where[] = 'EXISTS (SELECT 1 FROM request_items ri WHERE ri.request_id = r.ID AND ri.warehouse_id IN (' . $placeholders . '))';

            foreach ($warehouseIds as $warehouseId) {
                $params[] = $warehouseId;
            }
        } elseif (!empty($filters['warehouse_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM request_items ri WHERE ri.request_id = r.ID AND ri.warehouse_id = ?)';
            $params[] = (int) $filters['warehouse_id'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'r.created_at >= ?';
            $params[] = (string) $filters['from'] . ' 00:00:00';
        }

        if (!empty($filters['to'])) {
            $where[] = 'r.created_at <= ?';
            $params[] = (string) $filters['to'] . ' 23:59:59';
        }

        if (!empty($filters['q'])) {
            $where[] = '(r.subject LIKE ? OR r.body LIKE ? OR r.number LIKE ? OR c.FULL_NAME LIKE ? OR c.PHONE LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $managerScopes = is_array($filters['manager_scopes'] ?? null) ? $filters['manager_scopes'] : [];
        $managerScopes = array_values(array_intersect(
            array_map(static fn ($value): string => (string) $value, $managerScopes),
            ['none', 'mine', 'others']
        ));
        $scopeUserId = (int) ($filters['manager_scope_user'] ?? 0);

        if ($managerScopes === []) {
            $managerScope = (string) ($filters['manager_scope'] ?? '');

            if ($managerScope !== '') {
                $managerScopes = [$managerScope];
            }
        }

        if ($scopeUserId > 0 && $managerScopes !== []) {
            $scopeConditions = [];

            foreach ($managerScopes as $managerScope) {
                if ($managerScope === 'none') {
                    $scopeConditions[] = '(r.manager_id IS NULL AND s.is_final = 0)';
                } elseif ($managerScope === 'mine') {
                    $scopeConditions[] = 'r.manager_id = ?';
                    $params[] = $scopeUserId;
                } elseif ($managerScope === 'others') {
                    $scopeConditions[] = '(r.manager_id IS NOT NULL AND r.manager_id <> ?)';
                    $params[] = $scopeUserId;
                }
            }

            if ($scopeConditions !== []) {
                $where[] = '(' . implode(' OR ', $scopeConditions) . ')';
            }
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             INNER JOIN users c ON c.ID = r.client_id' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = $perPage > 0 ? max(0, ($page - 1) * $perPage) : 0;
        $limitSql = $perPage > 0 ? ' LIMIT ' . $perPage . ' OFFSET ' . $offset : '';

        $sql = self::DETAIL_SELECT . $whereSql . $this->sortSql((string) ($filters['sort'] ?? '')) . $limitSql;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll() ?: [], 'total' => $total];
    }

    public function activeManagerForClient(int $clientId): ?int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT r.manager_id
             FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE r.client_id = ? AND s.is_final = 0 AND r.manager_id IS NOT NULL
             ORDER BY r.ID DESC
             LIMIT 1"
        );
        $stmt->execute([$clientId]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (int) $value : null;
    }

    public function clientInterests(
        int $clientId,
        string $query = '',
        string $sort = 'qty_desc',
        int $limit = 20,
        int $offset = 0,
        array $filters = []
    ): array {
        $limit = $limit <= 0 ? 0 : max(1, min(100, $limit));
        [$where, $params] = $this->interestsWhere($clientId, $query, $filters);

        $orderParts = [];

        foreach (explode(',', $sort) as $term) {
            $clause = match (trim($term)) {
                'qty_asc' => 'total_qty ASC',
                'qty_desc' => 'total_qty DESC',
                'orders_asc' => 'orders ASC',
                'orders_desc' => 'orders DESC',
                'last_asc' => 'last_at ASC',
                'last_desc' => 'last_at DESC',
                'name_asc' => 'ri.name ASC',
                'name_desc' => 'ri.name DESC',
                'warehouse_asc' => 'warehouse_name ASC',
                'warehouse_desc' => 'warehouse_name DESC',
                default => '',
            };

            if ($clause !== '') {
                $orderParts[] = $clause;
            }
        }

        if ($orderParts === []) {
            $orderParts[] = 'total_qty DESC';
        }

        $order = implode(', ', $orderParts) . ', ri.name ASC';

        $stmt = Database::pdo()->prepare(
            "SELECT ri.name, ri.unit,
                    MAX(ri.description) AS description,
                    COUNT(*) AS orders,
                    SUM(ri.quantity) AS total_qty,
                    MAX(r.created_at) AS last_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(ri.warehouse_name ORDER BY ri.quantity DESC), ',', 1) AS warehouse_name
             FROM request_items ri
             INNER JOIN requests r ON r.ID = ri.request_id
             WHERE " . $where . "
             GROUP BY ri.name, ri.unit
             ORDER BY " . $order . "
             " . ($limit > 0 ? 'LIMIT ' . $limit . ' OFFSET ' . max(0, $offset) : '')
        );
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function countClientInterests(int $clientId, string $query = '', array $filters = []): int
    {
        [$where, $params] = $this->interestsWhere($clientId, $query, $filters);

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM (
                SELECT 1
                FROM request_items ri
                INNER JOIN requests r ON r.ID = ri.request_id
                WHERE ' . $where . '
                GROUP BY ri.name, ri.unit
             ) t'
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function interestsWhere(int $clientId, string $query, array $filters = []): array
    {
        $where = "r.client_id = ? AND r.status_id <> 'canceled'";
        $params = [$clientId];

        if (trim($query) !== '') {
            $where .= ' AND ri.name LIKE ?';
            $params[] = '%' . trim($query) . '%';
        }

        $warehouses = array_values(array_filter(
            array_map(static fn ($value): string => trim((string) $value), (array) ($filters['warehouses'] ?? [])),
            static fn (string $value): bool => $value !== ''
        ));

        if ($warehouses !== []) {
            $where .= ' AND ri.warehouse_name IN (' . implode(',', array_fill(0, count($warehouses), '?')) . ')';
            foreach ($warehouses as $warehouse) {
                $params[] = $warehouse;
            }
        }

        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));

        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $where .= ' AND r.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }

        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $where .= ' AND r.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        return [$where, $params];
    }

    public function clientInterestWarehouses(int $clientId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT DISTINCT ri.warehouse_name
             FROM request_items ri
             INNER JOIN requests r ON r.ID = ri.request_id
             WHERE r.client_id = ? AND r.status_id <> 'canceled' AND ri.warehouse_name <> ''
             ORDER BY ri.warehouse_name"
        );
        $stmt->execute([$clientId]);

        return array_values(array_filter(
            array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)),
            static fn (string $value): bool => $value !== ''
        ));
    }

    public function statsForClient(int $clientId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN s.is_final = 0 THEN 1 ELSE 0 END), 0) AS open_now,
                COALESCE(SUM(CASE WHEN s.is_final = 1 THEN 1 ELSE 0 END), 0) AS closed_now,
                COALESCE(SUM(CASE WHEN s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW() THEN 1 ELSE 0 END), 0) AS overdue
             FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE r.client_id = ?'
        );
        $stmt->execute([$clientId]);
        $row = $stmt->fetch() ?: [];

        $byStatusStmt = Database::pdo()->prepare(
            'SELECT r.status_id, COUNT(*) AS cnt
             FROM requests r
             WHERE r.client_id = ?
             GROUP BY r.status_id'
        );
        $byStatusStmt->execute([$clientId]);

        $byStatus = [];

        foreach ($byStatusStmt->fetchAll() ?: [] as $statusRow) {
            $byStatus[(string) $statusRow['status_id']] = (int) $statusRow['cnt'];
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'open' => (int) ($row['open_now'] ?? 0),
            'closed' => (int) ($row['closed_now'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
            'by_status' => $byStatus,
        ];
    }

    public function warehousesForScope(array $scope): array
    {
        [$where, $params] = $this->scopeConditions($scope);

        $conditions = $where;
        $conditions[] = 'ri.warehouse_id IS NOT NULL';
        $conditions[] = "ri.warehouse_name IS NOT NULL AND ri.warehouse_name <> ''";

        $stmt = Database::pdo()->prepare(
            'SELECT ri.warehouse_id, MAX(ri.warehouse_name) AS warehouse_name,
                    COUNT(DISTINCT CASE WHEN s.is_final = 0 THEN ri.request_id END) AS open_count
             FROM request_items ri
             INNER JOIN requests r ON r.ID = ri.request_id
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY ri.warehouse_id
             ORDER BY warehouse_name ASC
             LIMIT 100'
        );
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function createItems(int $requestId, array $items): void
    {
        if ($items === []) {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO request_items (request_id, warehouse_id, warehouse_name, stock_level_id, name, unit, description, quantity, price, price_type_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($items as $item) {
            $stmt->execute([
                $requestId,
                $item['warehouse_id'] ?? null,
                $item['warehouse_name'] ?? null,
                $item['stock_level_id'] ?? null,
                $item['name'],
                $item['unit'] ?? null,
                ($item['description'] ?? '') !== '' ? $item['description'] : null,
                $item['quantity'],
                $item['price'] ?? null,
                $item['price_type_id'] ?? null,
            ]);
        }
    }

    public function updateDetails(int $id, string $subject, string $body): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE requests SET subject = ?, body = ?, version = version + 1, updated_at = NOW() WHERE ID = ?'
        );
        $stmt->execute([$subject, $body, $id]);
    }

    public function updatePriority(int $id, int $priority): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE requests SET priority = ?, version = version + 1, updated_at = NOW() WHERE ID = ?'
        );
        $stmt->execute([$priority, $id]);
    }

    public function updateDueAt(int $id, ?string $dueAt): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE requests SET due_at = ?, version = version + 1, updated_at = NOW() WHERE ID = ?'
        );
        $stmt->execute([$dueAt, $id]);
    }

    public function replaceItems(int $requestId, array $items): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM request_items WHERE request_id = ?');
        $stmt->execute([$requestId]);

        $this->createItems($requestId, $items);

        $stmt = Database::pdo()->prepare('UPDATE requests SET version = version + 1, updated_at = NOW() WHERE ID = ?');
        $stmt->execute([$requestId]);
    }

    public function itemsForRequests(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));

        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, request_id, warehouse_id, warehouse_name, stock_level_id, name, unit, description, quantity, price, price_type_id
             FROM request_items
             WHERE request_id IN (' . $placeholders . ')
             ORDER BY ID ASC'
        );
        $stmt->execute(array_map('intval', $requestIds));

        $result = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) $row['request_id']][] = [
                'id' => (int) $row['id'],
                'warehouse_id' => $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : null,
                'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
                'stock_level_id' => $row['stock_level_id'] !== null ? (int) $row['stock_level_id'] : null,
                'name' => (string) $row['name'],
                'unit' => (string) ($row['unit'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'quantity' => (float) $row['quantity'],
                'price' => $row['price'] !== null ? (float) $row['price'] : null,
                'price_type_id' => $row['price_type_id'] !== null ? (int) $row['price_type_id'] : null,
            ];
        }

        return $result;
    }

    private function sortSql(string $sort): string
    {
        $parts = [];

        foreach (explode(',', $sort) as $code) {
            $part = match (trim($code)) {
                'created_asc' => 'r.created_at ASC',
                'created_desc' => 'r.created_at DESC',
                'number_asc' => 'r.ID ASC',
                'number_desc' => 'r.ID DESC',
                'subject_asc' => 'r.subject ASC',
                'subject_desc' => 'r.subject DESC',
                'client_asc' => 'c.FULL_NAME ASC',
                'client_desc' => 'c.FULL_NAME DESC',
                'manager_asc' => '(m.FULL_NAME IS NULL) ASC, m.FULL_NAME ASC',
                'manager_desc' => '(m.FULL_NAME IS NULL) ASC, m.FULL_NAME DESC',
                'positions_asc' => 'items_count ASC',
                'positions_desc' => 'items_count DESC',
                'due_asc' => '(r.due_at IS NULL) ASC, r.due_at ASC',
                'due_desc' => '(r.due_at IS NULL) ASC, r.due_at DESC',
                'priority_asc' => 'r.priority ASC',
                'priority_desc' => 'r.priority DESC',
                'status', 'status_asc' => 's.sort ASC',
                'status_desc' => 's.sort DESC',
                default => null,
            };

            if ($part !== null && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        if ($parts === []) {
            $parts[] = 'r.created_at DESC';
        }

        if (!in_array('r.ID DESC', $parts, true) && !in_array('r.ID ASC', $parts, true)) {
            $parts[] = 'r.ID DESC';
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function scopeConditions(array $scope): array
    {
        if (!isset($scope['participant_id'])) {
            return [[], []];
        }

        if (!empty($scope['client_only'])) {
            return [['r.client_id = ?'], [$scope['participant_id']]];
        }

        $condition = '(r.client_id = ? OR r.manager_id = ?';
        $params = [$scope['participant_id'], $scope['participant_id']];

        if (!empty($scope['include_unassigned'])) {
            $condition .= ' OR r.manager_id IS NULL';
        }

        if (!empty($scope['substituted_ids'])) {
            $placeholders = implode(',', array_fill(0, count($scope['substituted_ids']), '?'));
            $condition .= ' OR r.manager_id IN (' . $placeholders . ')';

            foreach ($scope['substituted_ids'] as $substitutedId) {
                $params[] = (int) $substitutedId;
            }
        }

        return [[$condition . ')'], $params];
    }

    public function summary(array $scope): array
    {
        $where = [];
        $params = [];

        if (isset($scope['participant_id'])) {
            if (!empty($scope['client_only'])) {
                $where[] = 'r.client_id = ?';
                $params[] = $scope['participant_id'];
            } else {
                $condition = '(r.client_id = ? OR r.manager_id = ?';

                if (!empty($scope['include_unassigned'])) {
                    $condition .= ' OR r.manager_id IS NULL';
                }

                if (!empty($scope['substituted_ids'])) {
                    $placeholders = implode(',', array_fill(0, count($scope['substituted_ids']), '?'));
                    $condition .= ' OR r.manager_id IN (' . $placeholders . ')';
                }

                $where[] = $condition . ')';
                $params[] = $scope['participant_id'];
                $params[] = $scope['participant_id'];

                foreach ($scope['substituted_ids'] ?? [] as $substitutedId) {
                    $params[] = (int) $substitutedId;
                }
            }
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $scopeAnd = $where !== [] ? ' AND ' . implode(' AND ', $where) : '';

        $stmt = Database::pdo()->prepare(
            'SELECT r.status_id, COUNT(*) AS cnt FROM requests r' . $whereSql . ' GROUP BY r.status_id'
        );
        $stmt->execute($params);

        $byStatus = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $byStatus[(string) $row['status_id']] = (int) $row['cnt'];
        }

        $overdueStmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW()' . $scopeAnd
        );
        $overdueStmt->execute($params);
        $overdue = (int) $overdueStmt->fetchColumn();

        $total = array_sum($byStatus);

        $finalStmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE s.is_final = 1' . $scopeAnd
        );
        $finalStmt->execute($params);
        $closed = (int) $finalStmt->fetchColumn();

        return [
            'by_status' => $byStatus,
            'total' => $total,
            'open' => $total - $closed,
            'closed' => $closed,
            'overdue' => $overdue,
        ];
    }
}
