<?php

declare(strict_types=1);

namespace App\Users;

use App\Audit\AuditService;
use App\Core\Database;
use App\Http\HttpException;
use App\Mail\MailService;
use App\Prices\PriceService;
use App\Repositories\PriceRepository;
use App\Repositories\StocksRepository;
use App\Repositories\TokenRepository;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use App\Support\Validator;
use Throwable;

final class UserAdminService
{
    private const LEVELS = [5, 10, 50, 90];

    private const ADMIN_CORE_CAPABILITIES = ['users.manage', 'roles.manage', 'settings.manage'];

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository(),
        private readonly TokenRepository $tokens = new TokenRepository(),
        private readonly AuditService $audit = new AuditService(),
        private readonly PriceRepository $prices = new PriceRepository(),
        private readonly PriceService $priceService = new PriceService(),
        private readonly StocksRepository $stocks = new StocksRepository(),
        private readonly MailService $mail = new MailService()
    ) {
    }

    public function list(array $filters, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = '(u.FULL_NAME LIKE ? OR u.LOGIN LIKE ? OR u.EMAIL LIKE ? OR u.PHONE LIKE ?
                OR u.COMPANY LIKE ? OR u.INN LIKE ? OR u.DEPARTMENT LIKE ? OR u.CITY LIKE ?
                OR u.OFFICE LIKE ? OR w.NAME LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        if (($filters['scope'] ?? '') === 'staff') {
            $where[] = 'u.LEVEL >= 10';
        }

        $levels = is_array($filters['levels'] ?? null)
            ? array_values(array_unique(array_filter(
                array_map('intval', $filters['levels']),
                static fn (int $level): bool => in_array($level, [10, 50, 90], true)
            )))
            : [];

        if ($levels !== []) {
            $where[] = 'u.LEVEL IN (' . implode(', ', array_fill(0, count($levels), '?')) . ')';
            array_push($params, ...$levels);
        } elseif (!empty($filters['level'])) {
            $where[] = 'u.LEVEL = ?';
            $params[] = (int) $filters['level'];
        }

        $states = is_array($filters['states'] ?? null)
            ? array_values(array_unique(array_filter(
                $filters['states'],
                static fn ($state): bool => in_array($state, ['active', 'blocked', 'pending'], true)
            )))
            : [];

        if ($states !== []) {
            $parts = [];

            foreach ($states as $state) {
                if ($state === 'blocked') {
                    $parts[] = "u.ACTIVE = 'N'";
                } elseif ($state === 'active') {
                    $parts[] = "(u.ACTIVE = 'Y' AND u.reg_state = 'active')";
                } elseif ($state === 'pending') {
                    $parts[] = "u.reg_state = 'pending'";
                }
            }

            if ($parts !== []) {
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        } elseif (!empty($filters['state'])) {
            if ($filters['state'] === 'blocked') {
                $where[] = "u.ACTIVE = 'N'";
            } elseif ($filters['state'] === 'active') {
                $where[] = "u.ACTIVE = 'Y' AND u.reg_state = 'active'";
            } elseif ($filters['state'] === 'pending') {
                $where[] = "u.reg_state = 'pending'";
            }
        }

        $facets = [
            'cities' => ['singular' => 'city', 'column' => 'u.CITY'],
            'departments' => ['singular' => 'department', 'column' => 'u.DEPARTMENT'],
            'offices' => ['singular' => 'office', 'column' => 'u.OFFICE'],
            'positions' => ['singular' => 'position', 'column' => 'u.DOLGNOST'],
        ];

        foreach ($facets as $key => $config) {
            $values = is_array($filters[$key] ?? null)
                ? array_values(array_unique(array_filter(
                    array_map(static fn ($value): string => trim((string) $value), $filters[$key]),
                    static fn (string $value): bool => $value !== ''
                )))
                : [];

            if ($values !== []) {
                $where[] = $config['column'] . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
                array_push($params, ...$values);
                continue;
            }

            $value = trim((string) ($filters[$config['singular']] ?? ''));

            if ($value !== '') {
                $where[] = $config['column'] . ' LIKE ?';
                $params[] = '%' . $value . '%';
            }
        }

        $whereSql = implode(' AND ', $where);
        $offset = max(0, ($page - 1) * $perPage);

        $stmt = Database::pdo()->prepare(
            'SELECT u.ID AS id, u.LOGIN AS login, u.FULL_NAME AS name, u.EMAIL AS email, u.PHONE AS phone,
                    u.COMPANY AS company, u.INN AS inn, u.DOLGNOST AS position, u.LEVEL AS level,
                    u.DEPARTMENT AS department, u.CITY AS city, u.OFFICE AS office,
                    u.WAREHOUSE_ID AS warehouse_id, w.NAME AS warehouse_name,
                    u.ACTIVE AS active, u.reg_state, u.TIME_ADD AS created_at, u.TIME_ACTIVE AS last_seen_at
             FROM users u
             LEFT JOIN stocks w ON w.ID = u.WAREHOUSE_ID
             WHERE ' . $whereSql . '
             ORDER BY ' . $this->sortSql((string) ($filters['sort'] ?? '')) . ($perPage > 0 ? '
             LIMIT ' . max(1, min(100, $perPage)) . ' OFFSET ' . $offset : '')
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
        $assignedMap = $this->prices->assignedTypesForUsers(array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows
        ));

        $items = array_map(static function (array $row) use ($assignedMap): array {
            $id = (int) $row['id'];

            return [
                'id' => $id,
                'login' => (string) ($row['login'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'company' => (string) ($row['company'] ?? ''),
                'inn' => (string) ($row['inn'] ?? ''),
                'position' => (string) ($row['position'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'office' => (string) ($row['office'] ?? ''),
                'warehouse_id' => $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : null,
                'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
                'level' => (int) $row['level'],
                'active' => ($row['active'] ?? 'N') === 'Y',
                'reg_state' => (string) ($row['reg_state'] ?? 'active'),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'last_seen_at' => $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null,
                'price_types' => $assignedMap[$id] ?? [],
            ];
        }, $rows);

        $countStmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM users u LEFT JOIN stocks w ON w.ID = u.WAREHOUSE_ID WHERE ' . $whereSql
        );
        $countStmt->execute($params);

        return ['items' => $items, 'total' => (int) $countStmt->fetchColumn(), 'page' => $page, 'per_page' => $perPage];
    }

    public function create(array $actor, array $capabilities, array $input): array
    {
        $this->requireManage($capabilities);

        $name = trim((string) ($input['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        $company = trim((string) ($input['company'] ?? ''));
        $inn = preg_replace('/\s+/', '', (string) ($input['inn'] ?? '')) ?? '';
        $position = trim((string) ($input['position'] ?? ''));
        $department = mb_substr(trim((string) ($input['department'] ?? '')), 0, 255);
        $city = mb_substr(trim((string) ($input['city'] ?? '')), 0, 128);
        $office = mb_substr(trim((string) ($input['office'] ?? '')), 0, 128);
        $warehouseId = (int) ($input['warehouse_id'] ?? 0);
        $warehouseId = $warehouseId > 0 ? $warehouseId : null;
        $login = trim((string) ($input['login'] ?? ''));
        $level = (int) ($input['level'] ?? 5);
        $priceTypeIds = $this->normalizePriceTypeIds($input['price_type_ids'] ?? null);

        if (mb_strlen($name) < 3) {
            throw new HttpException(422, 'validation_error', 'Укажите ФИО');
        }

        if (!Validator::email($email)) {
            throw new HttpException(422, 'validation_error', 'Укажите корректный e-mail');
        }

        if (!in_array($level, self::LEVELS, true)) {
            throw new HttpException(422, 'validation_error', 'Неизвестная роль');
        }

        if ($level >= 50 && !in_array('roles.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Назначать роли может только технический администратор');
        }

        if ($inn !== '' && preg_match('/^(\d{10}|\d{12})$/', $inn) !== 1) {
            throw new HttpException(422, 'validation_error', 'ИНН должен содержать 10 или 12 цифр');
        }

        if ($warehouseId !== null && $this->stocks->findWarehouseById($warehouseId) === null) {
            throw new HttpException(422, 'validation_error', 'Выбранный склад не найден');
        }

        if ($this->users->findAnyByEmail($email) !== null) {
            throw new HttpException(422, 'email_taken', 'Пользователь с таким e-mail уже существует');
        }

        if ($login === '') {
            $login = $this->generateLoginFromEmail($email);
        } else {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{2,63}$/', $login) !== 1) {
                throw new HttpException(
                    422,
                    'validation_error',
                    'Логин: 3–64 символа, латиница, цифры, точка, дефис, подчёркивание или @'
                );
            }

            if ($this->users->findAnyByLogin($login) !== null) {
                throw new HttpException(422, 'login_taken', 'Такой логин уже занят');
            }
        }

        if ($phone !== '' && $this->users->findAnyByPhone($phone) !== null) {
            throw new HttpException(422, 'phone_taken', 'Пользователь с таким телефоном уже существует');
        }

        $password = bin2hex(random_bytes(5)) . 'Aa1';

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO users (ACTIVE, LOGIN, PASSWORD, LEVEL, FULL_NAME, STATUS, COMPANY, INN, PHONE, EMAIL,
                DOLGNOST, DEPARTMENT, CITY, OFFICE, WAREHOUSE_ID, reg_state)
             VALUES ('Y', ?, ?, ?, ?, 'Y', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->execute([
            $login,
            password_hash($password, PASSWORD_DEFAULT),
            $level,
            $name,
            $company !== '' ? $company : null,
            $inn !== '' ? $inn : null,
            $phone !== '' ? $phone : null,
            $email,
            $position !== '' ? $position : null,
            $department !== '' ? $department : null,
            $city !== '' ? $city : null,
            $office !== '' ? $office : null,
            $warehouseId,
        ]);

        $userId = (int) $pdo->lastInsertId();

        if ($priceTypeIds !== []) {
            $this->priceService->setAssignedTypes($userId, $priceTypeIds);
        }

        $this->history->add($userId, 'created', (int) $actor['ID'], 'Создан администратором');

        return [
            'id' => $userId,
            'login' => $login,
            'email' => $email,
            'password' => $password,
            'price_types' => $this->priceService->assignedTypesForUser(['ID' => $userId]),
        ];
    }

    public function update(array $actor, array $capabilities, int $userId, array $input): array
    {
        $this->requireManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        $actorLevel = (int) ($actor['LEVEL'] ?? 0);
        $targetLevel = (int) $user['LEVEL'];

        if ($actorLevel < 90 && $targetLevel >= $actorLevel) {
            throw new HttpException(403, 'forbidden', 'Нельзя изменять пользователя с равной или более высокой ролью');
        }

        $name = trim((string) ($input['name'] ?? (string) $user['FULL_NAME']));
        $email = mb_strtolower(trim((string) ($input['email'] ?? (string) ($user['EMAIL'] ?? ''))));
        $phone = trim((string) ($input['phone'] ?? (string) ($user['PHONE'] ?? '')));
        $company = trim((string) ($input['company'] ?? (string) ($user['COMPANY'] ?? '')));
        $inn = preg_replace('/\s+/', '', (string) ($input['inn'] ?? (string) ($user['INN'] ?? ''))) ?? '';
        $position = trim((string) ($input['position'] ?? (string) ($user['DOLGNOST'] ?? '')));
        $department = mb_substr(
            trim((string) ($input['department'] ?? (string) ($user['DEPARTMENT'] ?? ''))),
            0,
            255
        );
        $city = mb_substr(trim((string) ($input['city'] ?? (string) ($user['CITY'] ?? ''))), 0, 128);
        $office = mb_substr(trim((string) ($input['office'] ?? (string) ($user['OFFICE'] ?? ''))), 0, 128);
        $warehouseId = array_key_exists('warehouse_id', $input)
            ? (int) $input['warehouse_id']
            : (int) ($user['WAREHOUSE_ID'] ?? 0);
        $warehouseId = $warehouseId > 0 ? $warehouseId : null;
        $level = isset($input['level']) ? (int) $input['level'] : (int) $user['LEVEL'];
        $hasTypes = array_key_exists('price_type_ids', $input);
        $priceTypeIds = $hasTypes
            ? $this->normalizePriceTypeIds($input['price_type_ids'])
            : $this->prices->assignedTypeIds($userId);

        if (mb_strlen($name) < 3) {
            throw new HttpException(422, 'validation_error', 'Укажите ФИО');
        }

        if ($inn !== '' && preg_match('/^(\d{10}|\d{12})$/', $inn) !== 1) {
            throw new HttpException(422, 'validation_error', 'ИНН должен содержать 10 или 12 цифр');
        }

        if ($email !== '' && !Validator::email($email)) {
            throw new HttpException(422, 'validation_error', 'Некорректный e-mail');
        }

        if ($warehouseId !== null && $this->stocks->findWarehouseById($warehouseId) === null) {
            throw new HttpException(422, 'validation_error', 'Выбранный склад не найден');
        }

        if ($email !== (string) ($user['EMAIL'] ?? '')) {
            $existingEmail = $this->users->findAnyByEmail($email);
            $existingLogin = $this->users->findAnyByLogin($email);

            if (($existingEmail !== null && (int) $existingEmail['ID'] !== $userId)
                || ($existingLogin !== null && (int) $existingLogin['ID'] !== $userId)
            ) {
                throw new HttpException(422, 'email_taken', 'Пользователь с таким e-mail уже существует');
            }
        }

        if ($phone !== '' && $phone !== (string) ($user['PHONE'] ?? '')) {
            $existing = $this->users->findAnyByPhone($phone);

            if ($existing !== null && (int) $existing['ID'] !== $userId) {
                throw new HttpException(422, 'phone_taken', 'Пользователь с таким телефоном уже существует');
            }
        }

        $levelChanged = $level !== (int) $user['LEVEL'];

        if ($levelChanged) {
            if (!in_array('roles.manage', $capabilities, true)) {
                throw new HttpException(403, 'forbidden', 'Менять роль может только технический администратор');
            }

            if ($userId === (int) $actor['ID']) {
                throw new HttpException(422, 'validation_error', 'Нельзя менять свою роль');
            }

            if (!in_array($level, self::LEVELS, true)) {
                throw new HttpException(422, 'validation_error', 'Неизвестная роль');
            }

            if (((int) $user['LEVEL'] === 5) !== ($level === 5)) {
                throw new HttpException(
                    422,
                    'use_role_conversion',
                    'Перевод клиента в сотрудника и обратно выполняется через карточку пользователя'
                );
            }

            if ((int) $user['LEVEL'] >= 90 && $level < 90) {
                $this->assertKeepsSuperAdmin($user, 'понизить');
            }

            if ((int) $user['LEVEL'] >= 50 && $level < 50) {
                $this->assertKeepsAdmin($user, 'понизить');
            }
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE users SET FULL_NAME = ?, EMAIL = ?, PHONE = ?, COMPANY = ?, INN = ?, DOLGNOST = ?,
                DEPARTMENT = ?, CITY = ?, OFFICE = ?, WAREHOUSE_ID = ?, LEVEL = ? WHERE ID = ?'
        );
        $stmt->execute([
            $name,
            $email !== '' ? $email : null,
            $phone !== '' ? $phone : null,
            $company !== '' ? $company : null,
            $inn !== '' ? $inn : null,
            $position !== '' ? $position : null,
            $department !== '' ? $department : null,
            $city !== '' ? $city : null,
            $office !== '' ? $office : null,
            $warehouseId,
            $level,
            $userId,
        ]);

        if ($hasTypes) {
            $this->priceService->setAssignedTypes($userId, $priceTypeIds);
        }

        if ($levelChanged) {
            $this->tokens->revokeAllForUser($userId);
        }

        $this->history->add($userId, 'updated', (int) $actor['ID'], 'Изменён администратором');

        return [
            'id' => $userId,
            'level' => $level,
            'email' => $email,
            'price_types' => $this->priceService->assignedTypesForUser(['ID' => $userId]),
        ];
    }

    public function setPriceTypes(array $actor, array $capabilities, int $userId, array $input): array
    {
        if (!in_array('prices.assign', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для закрепления типов цен');
        }

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        $typeIds = $this->normalizePriceTypeIds($input['price_type_ids'] ?? []);
        $current = $this->priceService->assignedTypesForUser($user);

        if ((int) ($user['LEVEL'] ?? 0) === 5 && count($current) > 0 && count($typeIds) === 0) {
            throw new HttpException(
                422,
                'last_price_type',
                'Нельзя отключить последний тип цены. Сначала включите другой тип.'
            );
        }

        $assigned = $this->priceService->setAssignedTypes($userId, $typeIds);

        $this->history->add($userId, 'price_types', (int) $actor['ID'], 'Обновлены закреплённые типы цен');

        return [
            'id' => $userId,
            'price_types' => $assigned,
        ];
    }

    public function card(array $actor, array $capabilities, int $userId): array
    {
        $this->requireManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null || (int) ($user['LEVEL'] ?? 0) < 10) {
            throw new HttpException(404, 'not_found', 'Сотрудник не найден');
        }

        $targetLevel = (int) $user['LEVEL'];
        $actorLevel = (int) ($actor['LEVEL'] ?? 0);
        $isSelf = $userId === (int) ($actor['ID'] ?? 0);
        $active = ($user['ACTIVE'] ?? 'N') === 'Y';
        $lastAdmin = $targetLevel >= 50 && $active && $this->users->countActiveAdmins() <= 1;
        $lastSuperAdmin = $targetLevel >= 90 && $active && $this->users->countActiveSuperAdmins() <= 1;
        $canManageTarget = $actorLevel >= 90 || $targetLevel < $actorLevel;
        $warehouseId = ($user['WAREHOUSE_ID'] ?? null) !== null ? (int) $user['WAREHOUSE_ID'] : null;
        $warehouse = $warehouseId !== null ? $this->stocks->findWarehouseById($warehouseId) : null;

        return [
            'id' => $userId,
            'sid' => (string) ($user['SID'] ?? ''),
            'login' => (string) ($user['LOGIN'] ?? ''),
            'name' => (string) ($user['FULL_NAME'] ?? ''),
            'email' => (string) ($user['EMAIL'] ?? ''),
            'phone' => (string) ($user['PHONE'] ?? ''),
            'company' => (string) ($user['COMPANY'] ?? ''),
            'inn' => (string) ($user['INN'] ?? ''),
            'position' => (string) ($user['DOLGNOST'] ?? ''),
            'department' => (string) ($user['DEPARTMENT'] ?? ''),
            'city' => (string) ($user['CITY'] ?? ''),
            'office' => (string) ($user['OFFICE'] ?? ''),
            'warehouse_id' => $warehouseId,
            'warehouse_name' => (string) ($warehouse['NAME'] ?? ''),
            'level' => $targetLevel,
            'active' => $active,
            'reg_state' => (string) ($user['reg_state'] ?? 'active'),
            'created_at' => (string) ($user['TIME_ADD'] ?? ''),
            'last_seen_at' => $user['TIME_ACTIVE'] !== null ? (string) $user['TIME_ACTIVE'] : null,
            'has_avatar' => trim((string) ($user['AVATAR_PATH'] ?? '')) !== '',
            'is_self' => $isSelf,
            'is_last_admin' => $lastAdmin,
            'is_last_superadmin' => $lastSuperAdmin,
            'can' => [
                'edit' => !$isSelf && $canManageTarget,
                'change_level' => in_array('roles.manage', $capabilities, true)
                    && !$isSelf
                    && !$lastSuperAdmin,
                'block' => !$isSelf && $canManageTarget && !$lastAdmin && !$lastSuperAdmin,
                'reset_password' => !$isSelf && $canManageTarget,
                'change_login' => !$isSelf && $canManageTarget,
                'revoke_sessions' => in_array($targetLevel, [10, 50], true) && $targetLevel < $actorLevel,
                'convert_to_client' => in_array('roles.manage', $capabilities, true)
                    && $actorLevel >= 90
                    && !$isSelf
                    && !$lastAdmin
                    && !$lastSuperAdmin,
            ],
        ];
    }

    public function history(array $capabilities, int $userId): array
    {
        $this->requireManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null || (int) ($user['LEVEL'] ?? 0) < 10) {
            throw new HttpException(404, 'not_found', 'Сотрудник не найден');
        }

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'event' => (string) $row['event'],
            'comment' => $row['comment'] !== null ? (string) $row['comment'] : '',
            'created_at' => (string) ($row['created_at'] ?? ''),
            'actor' => $row['actor_id'] !== null
                ? [
                    'id' => (int) $row['actor_id'],
                    'name' => (string) ($row['actor_name'] ?? ''),
                    'level' => (int) ($row['actor_level'] ?? 0),
                ]
                : null,
        ], $this->history->listByUser($userId));

        return ['items' => $items];
    }

    public function block(array $actor, array $capabilities, int $userId, bool $block): array
    {
        $this->requireManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        if ($userId === (int) $actor['ID']) {
            throw new HttpException(422, 'validation_error', 'Нельзя заблокировать себя');
        }

        if ((int) $user['LEVEL'] >= (int) $actor['LEVEL'] && (int) $actor['LEVEL'] < 90) {
            throw new HttpException(403, 'forbidden', 'Нельзя блокировать пользователя с равной или большей ролью');
        }

        if ($block) {
            if ((int) $user['LEVEL'] >= 90) {
                $this->assertKeepsSuperAdmin($user, 'заблокировать');
            } else {
                $this->assertKeepsAdmin($user, 'заблокировать');
            }
        }

        $stmt = Database::pdo()->prepare("UPDATE users SET ACTIVE = ? WHERE ID = ?");
        $stmt->execute([$block ? 'N' : 'Y', $userId]);

        if ($block) {
            $this->tokens->revokeAllForUser($userId);
        }

        $this->history->add(
            $userId,
            $block ? 'blocked' : 'unblocked',
            (int) $actor['ID'],
            $block ? 'Доступ заблокирован' : 'Доступ восстановлен'
        );

        return ['id' => $userId, 'active' => !$block];
    }

    public function sendCredentials(
        array $actor,
        array $capabilities,
        int $userId,
        string $login,
        string $password
    ): array {
        $this->requireManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null || (int) ($user['LEVEL'] ?? 0) < 10) {
            throw new HttpException(404, 'not_found', 'Сотрудник не найден');
        }

        if ($userId === (int) $actor['ID']
            || ((int) $user['LEVEL'] >= (int) $actor['LEVEL'] && (int) $actor['LEVEL'] < 90)
        ) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для отправки данных сотруднику');
        }

        $email = trim((string) ($user['EMAIL'] ?? ''));

        if ($email === '' || !Validator::email($email)) {
            throw new HttpException(422, 'email_missing', 'У сотрудника не указан корректный e-mail');
        }

        if (trim($login) !== (string) ($user['LOGIN'] ?? '')
            || !password_verify($password, (string) ($user['PASSWORD'] ?? ''))
        ) {
            throw new HttpException(422, 'credentials_invalid', 'Логин или пароль не совпадают с учётной записью');
        }

        if (!$this->mail->config()['enabled']) {
            throw new HttpException(422, 'mail_disabled', 'Почта не настроена — отправка недоступна');
        }

        $name = (string) ($user['FULL_NAME'] ?? '');
        $actorName = (string) ($actor['FULL_NAME'] ?? '');

        $this->mail->enqueue(
            $email,
            'Доступ к CRM7',
            "Здравствуйте, $name!\n\n"
            . "Для вас создана учётная запись в CRM7.\n"
            . "Логин: $login\n"
            . "Пароль: $password\n\n"
            . "Автор создания: $actorName.\n"
            . "После входа рекомендуем сменить пароль в профиле."
        );

        $this->history->add($userId, 'credentials_sent', (int) $actor['ID'], "Данные для входа отправлены на $email");

        return ['sent' => true, 'email' => $email];
    }

    public function revokeSessions(array $actor, array $capabilities, int $userId): array
    {
        $this->requireManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new HttpException(404, 'not_found', 'Сотрудник не найден');
        }

        $targetLevel = (int) ($user['LEVEL'] ?? 0);
        $actorLevel = (int) ($actor['LEVEL'] ?? 0);

        if (!in_array($targetLevel, [10, 50], true) || $targetLevel >= $actorLevel) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для завершения сессий этого сотрудника');
        }

        $this->tokens->revokeAllForUser($userId);
        $this->history->add($userId, 'sessions_revoked', (int) $actor['ID'], 'Завершены все сессии');

        return ['id' => $userId, 'revoked' => true];
    }

    public function changeLogin(array $actor, array $capabilities, int $userId, string $login): array
    {
        $this->requireManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new HttpException(404, 'not_found', 'Сотрудник не найден');
        }

        if ($userId === (int) $actor['ID']) {
            throw new HttpException(422, 'validation_error', 'Смените свой логин в профиле');
        }

        if ((int) $user['LEVEL'] >= (int) $actor['LEVEL'] && (int) $actor['LEVEL'] < 90) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для изменения логина этого сотрудника');
        }

        $login = trim($login);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{2,63}$/', $login) !== 1) {
            throw new HttpException(
                422,
                'validation_error',
                'Логин: 3–64 символа, латиница, цифры, точка, дефис, подчёркивание или @'
            );
        }

        $existing = $this->users->findAnyByLogin($login);

        if ($existing !== null && (int) $existing['ID'] !== $userId) {
            throw new HttpException(422, 'login_taken', 'Такой логин уже занят');
        }

        $stmt = Database::pdo()->prepare('UPDATE users SET LOGIN = ? WHERE ID = ?');
        $stmt->execute([$login, $userId]);

        $this->history->add($userId, 'login_changed', (int) $actor['ID'], 'Логин изменён администратором');

        return ['id' => $userId, 'login' => $login];
    }

    public function resetPassword(array $actor, array $capabilities, int $userId): array
    {
        $this->requireManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        if ($userId === (int) $actor['ID']) {
            throw new HttpException(422, 'validation_error', 'Нельзя сбросить пароль себе — используйте смену пароля');
        }

        if ((int) $user['LEVEL'] >= (int) $actor['LEVEL'] && (int) $actor['LEVEL'] < 90) {
            throw new HttpException(403, 'forbidden', 'Нельзя сбросить пароль пользователю с равной или большей ролью');
        }

        $password = bin2hex(random_bytes(5)) . 'Aa1';

        $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
        $this->tokens->revokeAllForUser($userId);
        $this->history->add($userId, 'password_reset', (int) $actor['ID'], 'Пароль сброшен администратором');

        return ['id' => $userId, 'password' => $password];
    }

    public function roles(): array
    {
        $levels = Database::pdo()->query(
            'SELECT l.level, COUNT(lc.capability_code) AS capabilities
             FROM (SELECT DISTINCT level FROM level_capabilities) l
             LEFT JOIN level_capabilities lc ON lc.level = l.level
             GROUP BY l.level
             ORDER BY l.level DESC'
        )->fetchAll() ?: [];

        $titles = [90 => 'Технический администратор', 50 => 'Администратор', 10 => 'Менеджер', 5 => 'Клиент', 1 => 'Гость'];

        $items = [];

        foreach ($levels as $row) {
            $level = (int) $row['level'];
            $stmt = Database::pdo()->prepare(
                'SELECT capability_code FROM level_capabilities WHERE level = ? ORDER BY capability_code'
            );
            $stmt->execute([$level]);

            $items[] = [
                'level' => $level,
                'title' => $titles[$level] ?? ('Уровень ' . $level),
                'capabilities' => array_map('strval', array_column($stmt->fetchAll() ?: [], 'capability_code')),
            ];
        }

        return ['items' => $items, 'catalog' => $this->capabilityCatalog()];
    }

    public function saveRole(array $actor, array $capabilities, int $level, array $input): array
    {
        if (!in_array('roles.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Менять права ролей может только технический администратор');
        }

        $levels = array_map(
            static fn (array $row): int => (int) $row['level'],
            Database::pdo()->query('SELECT DISTINCT level FROM level_capabilities')->fetchAll() ?: []
        );

        if (!in_array($level, $levels, true)) {
            throw new HttpException(404, 'not_found', 'Роль не найдена');
        }

        $requested = $input['capabilities'] ?? [];

        if (!is_array($requested)) {
            throw new HttpException(422, 'validation_error', 'Некорректный список прав');
        }

        $catalog = $this->capabilityCatalog();
        $known = array_column($catalog, 'code');
        $codes = array_values(array_unique(array_map(static fn ($code): string => (string) $code, $requested)));

        foreach ($codes as $code) {
            if (!in_array($code, $known, true)) {
                throw new HttpException(422, 'validation_error', 'Неизвестное право: ' . $code);
            }
        }

        if ($level >= 90) {
            foreach (self::ADMIN_CORE_CAPABILITIES as $core) {
                if (!in_array($core, $codes, true)) {
                    throw new HttpException(
                        422,
                        'admin_core',
                        'У технического администратора нельзя убирать ключевые права: управление пользователями, ролями и настройками'
                    );
                }
            }
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM level_capabilities WHERE level = ?')->execute([$level]);

            $insert = $pdo->prepare('INSERT INTO level_capabilities (level, capability_code) VALUES (?, ?)');

            foreach ($codes as $code) {
                $insert->execute([$level, $code]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }

        $this->audit->log($actor, 'role.update', 'roles', $level, ['capabilities' => $codes], null);

        return $this->roles();
    }

    private function capabilityCatalog(): array
    {
        $rows = Database::pdo()->query('SELECT code, title FROM capabilities ORDER BY title ASC')->fetchAll() ?: [];

        return array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
        ], $rows);
    }

    private function generateLoginFromEmail(string $email): string
    {
        $local = strstr($email, '@', true);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '', $local !== false ? $local : $email) ?? '';
        $base = preg_replace('/^[^A-Za-z0-9]+/', '', $base) ?? '';
        $base = rtrim($base, '._-');

        if (mb_strlen($base) < 3) {
            $base = 'user';
        }

        $base = mb_substr($base, 0, 50);
        $candidate = $base;
        $suffix = 1;

        while ($this->users->findAnyByLogin($candidate) !== null) {
            $candidate = $base . $suffix;
            $suffix++;

            if ($suffix > 9999) {
                return $base . bin2hex(random_bytes(2));
            }
        }

        return $candidate;
    }

    private function requireManage(array $capabilities): void
    {
        if (!in_array('users.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для управления пользователями');
        }
    }

    private function sortSql(string $sort): string
    {
        $allowed = [
            'name_asc' => 'u.FULL_NAME ASC',
            'name_desc' => 'u.FULL_NAME DESC',
            'level_asc' => 'u.LEVEL ASC, u.FULL_NAME ASC',
            'level_desc' => 'u.LEVEL DESC, u.FULL_NAME ASC',
            'created_asc' => 'u.TIME_ADD ASC',
            'created_desc' => 'u.TIME_ADD DESC',
            'last_seen_asc' => 'u.TIME_ACTIVE IS NULL, u.TIME_ACTIVE ASC',
            'last_seen_desc' => 'u.TIME_ACTIVE IS NULL, u.TIME_ACTIVE DESC',
            'department_asc' => 'u.DEPARTMENT ASC, u.FULL_NAME ASC',
            'department_desc' => 'u.DEPARTMENT DESC, u.FULL_NAME ASC',
            'city_asc' => 'u.CITY ASC, u.FULL_NAME ASC',
            'city_desc' => 'u.CITY DESC, u.FULL_NAME ASC',
            'office_asc' => 'u.OFFICE ASC, u.FULL_NAME ASC',
            'office_desc' => 'u.OFFICE DESC, u.FULL_NAME ASC',
            'warehouse_asc' => 'w.NAME ASC, u.FULL_NAME ASC',
            'warehouse_desc' => 'w.NAME DESC, u.FULL_NAME ASC',
        ];
        $parts = [];
        $seen = [];

        foreach (explode(',', $sort) as $criterion) {
            $criterion = trim($criterion);

            if ($criterion === '' || !isset($allowed[$criterion]) || isset($seen[$criterion])) {
                continue;
            }

            $seen[$criterion] = true;
            $parts[] = $allowed[$criterion];
        }

        return $parts !== [] ? implode(', ', $parts) : 'u.LEVEL DESC, u.FULL_NAME ASC';
    }

    private function normalizePriceTypeId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        $id = (int) $value;

        if ($this->prices->findType($id) === null) {
            throw new HttpException(422, 'validation_error', 'Неизвестный тип цен');
        }

        return $id;
    }

    /**
     * @return int[]
     */
    private function normalizePriceTypeIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $id = $this->normalizePriceTypeId($item);

            if ($id !== null && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function assertKeepsAdmin(array $target, string $action): void
    {
        $active = ($target['ACTIVE'] ?? 'N') === 'Y'
            && ($target['STATUS'] ?? 'N') === 'Y'
            && (($target['reg_state'] ?? 'active') === 'active');

        if ((int) $target['LEVEL'] < 50 || !$active) {
            return;
        }

        if ($this->users->countActiveAdmins() <= 1) {
            throw new HttpException(
                422,
                'last_admin',
                'Нельзя ' . $action . ' последнего администратора — в системе не останется ни одного администратора'
            );
        }
    }

    private function assertKeepsSuperAdmin(array $target, string $action): void
    {
        $active = ($target['ACTIVE'] ?? 'N') === 'Y'
            && ($target['STATUS'] ?? 'N') === 'Y'
            && (($target['reg_state'] ?? 'active') === 'active');

        if ((int) $target['LEVEL'] < 90 || !$active) {
            return;
        }

        if ($this->users->countActiveSuperAdmins() <= 1) {
            throw new HttpException(
                422,
                'last_superadmin',
                'Нельзя ' . $action . ' последнего технического администратора — в системе не останется ни одного технического администратора'
            );
        }
    }
}
