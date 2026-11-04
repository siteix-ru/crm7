<?php

declare(strict_types=1);

namespace App\Clients;

use App\Core\Config;
use App\Core\Database;
use App\Http\HttpException;
use App\Mail\MailService;
use App\Notifications\NotificationService;
use App\Prices\PriceService;
use App\Support\Validator;
use App\Repositories\RequestRepository;
use App\Repositories\ResetRepository;
use App\Repositories\TokenRepository;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use App\Repositories\ViewLogRepository;
use App\Users\AvatarService;

final class ClientService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository(),
        private readonly RequestRepository $requests = new RequestRepository(),
        private readonly ClientManagerService $managers = new ClientManagerService(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly ViewLogRepository $views = new ViewLogRepository(),
        private readonly PriceService $prices = new PriceService(),
        private readonly TokenRepository $tokens = new TokenRepository(),
        private readonly ResetRepository $resets = new ResetRepository(),
        private readonly MailService $mail = new MailService(),
        private readonly AvatarService $avatars = new AvatarService()
    ) {
    }

    public function pending(array $capabilities): array
    {
        $this->requireConfirm($capabilities);

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'company' => (string) ($row['company'] ?? ''),
            'inn' => (string) ($row['inn'] ?? ''),
            'position' => (string) ($row['position'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'registered_at' => (string) ($row['registered_at'] ?? $row['created_at'] ?? ''),
        ], $this->users->listPendingClients());

        return ['items' => $items];
    }

    public function activate(array $actor, array $capabilities, int $clientId, string $comment): array
    {
        $this->requireConfirm($capabilities);
        $client = $this->assertPendingClient($clientId);

        $this->users->markClientState($clientId, 'active');
        $this->history->add($clientId, 'activated', (int) $actor['ID'], $comment);

        $this->notifications->notify(
            $clientId,
            'user.approved',
            'Регистрация подтверждена',
            'Добро пожаловать! Теперь вы можете войти в личный кабинет и создавать заявки.'
        );

        return [
            'id' => $clientId,
            'name' => (string) ($client['FULL_NAME'] ?? ''),
            'state' => 'active',
        ];
    }

    public function reject(array $actor, array $capabilities, int $clientId, string $comment): array
    {
        $this->requireConfirm($capabilities);
        $client = $this->assertPendingClient($clientId);

        if (trim($comment) === '') {
            throw new HttpException(422, 'validation_error', 'Укажите причину отказа');
        }

        $this->users->markClientState($clientId, 'rejected');
        $this->history->add($clientId, 'rejected', (int) $actor['ID'], $comment);

        $this->notifications->notify(
            $clientId,
            'user.rejected',
            'Регистрация отклонена',
            'Причина: ' . trim($comment)
        );

        return [
            'id' => $clientId,
            'name' => (string) ($client['FULL_NAME'] ?? ''),
            'state' => 'rejected',
        ];
    }

    public function setBlocked(array $actor, array $capabilities, int $clientId, bool $blocked, string $comment): array
    {
        $client = $this->assertClient($clientId);
        $this->assertCanManageAccount($actor, $capabilities, $clientId);

        if (($client['reg_state'] ?? 'active') !== 'active') {
            throw new HttpException(422, 'not_active', 'Регистрация клиента ещё не подтверждена');
        }

        $isActive = ($client['ACTIVE'] ?? 'N') === 'Y';

        if ($blocked && !$isActive) {
            throw new HttpException(422, 'already_blocked', 'Клиент уже заблокирован');
        }

        if (!$blocked && $isActive) {
            throw new HttpException(422, 'not_blocked', 'Клиент не заблокирован');
        }

        $this->users->setActive($clientId, !$blocked);
        $this->history->add(
            $clientId,
            $blocked ? 'blocked' : 'unblocked',
            (int) $actor['ID'],
            $comment !== '' ? $comment : ($blocked ? 'Доступ заблокирован' : 'Доступ восстановлен')
        );

        [$mailSent, $email] = $this->notifyAccessChange($actor, $client, $blocked, $comment);

        return [
            'id' => $clientId,
            'active' => !$blocked,
            'email_sent' => $mailSent,
            'email' => $email,
        ];
    }

    public function uploadAvatar(array $actor, array $capabilities, int $clientId, array $file): array
    {
        $client = $this->assertClient($clientId);
        $this->assertCanManageAccount($actor, $capabilities, $clientId);

        return $this->avatars->store($client, $file, $actor, 'client');
    }

    public function deleteAvatar(array $actor, array $capabilities, int $clientId): array
    {
        $client = $this->assertClient($clientId);
        $this->assertCanManageAccount($actor, $capabilities, $clientId);

        return $this->avatars->remove($client, $actor, 'client');
    }

    public function updateContacts(array $actor, array $capabilities, int $clientId, array $input): array
    {
        $client = $this->assertClient($clientId);

        if (($client['reg_state'] ?? 'active') === 'pending') {
            $this->requireConfirm($capabilities);
        } else {
            $this->assertCanManageAccount($actor, $capabilities, $clientId);
        }

        $name = trim((string) ($input['name'] ?? ''));
        $company = trim((string) ($input['company'] ?? ''));
        $inn = preg_replace('/\s+/', '', (string) ($input['inn'] ?? '')) ?? '';
        $position = trim((string) ($input['position'] ?? ''));
        $city = mb_substr(trim((string) ($input['city'] ?? '')), 0, 128);
        $address = mb_substr(trim((string) ($input['address'] ?? '')), 0, 255);
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));

        if (mb_strlen($name) < 2) {
            throw new HttpException(422, 'validation_error', 'Укажите имя клиента');
        }

        if ($email !== '' && !Validator::email($email)) {
            throw new HttpException(422, 'validation_error', 'Некорректный e-mail');
        }

        if ($inn !== '' && !preg_match('/^\d{10}(\d{2})?$/', $inn)) {
            throw new HttpException(422, 'validation_error', 'ИНН — 10 или 12 цифр');
        }

        if ($email !== '') {
            $existing = $this->users->findAnyByEmail($email);

            if ($existing !== null && (int) $existing['ID'] !== $clientId) {
                throw new HttpException(422, 'email_taken', 'Пользователь с таким e-mail уже зарегистрирован');
            }
        }

        if ($phone !== '') {
            $existing = $this->users->findAnyByPhone($phone);

            if ($existing !== null && (int) $existing['ID'] !== $clientId) {
                throw new HttpException(422, 'phone_taken', 'Пользователь с таким телефоном уже зарегистрирован');
            }
        }

        $before = [
            'Имя' => (string) ($client['FULL_NAME'] ?? ''),
            'Компания' => (string) ($client['COMPANY'] ?? ''),
            'ИНН' => (string) ($client['INN'] ?? ''),
            'Должность' => (string) ($client['DOLGNOST'] ?? ''),
            'Город' => (string) ($client['CITY'] ?? ''),
            'Адрес' => (string) ($client['ADDRESS'] ?? ''),
            'Телефон' => (string) ($client['PHONE'] ?? ''),
            'E-mail' => (string) ($client['EMAIL'] ?? ''),
        ];
        $after = [
            'Имя' => mb_substr($name, 0, 255),
            'Компания' => mb_substr($company, 0, 255),
            'ИНН' => mb_substr($inn, 0, 12),
            'Должность' => mb_substr($position, 0, 255),
            'Город' => $city,
            'Адрес' => $address,
            'Телефон' => mb_substr($phone, 0, 255),
            'E-mail' => mb_substr($email, 0, 255),
        ];

        $this->users->updateContacts($clientId, [
            'name' => $after['Имя'],
            'company' => $after['Компания'],
            'inn' => $after['ИНН'],
            'position' => $after['Должность'],
            'city' => $after['Город'],
            'address' => $after['Адрес'],
            'phone' => $after['Телефон'],
            'email' => $after['E-mail'],
        ]);

        $changes = [];

        foreach ($before as $label => $oldValue) {
            $newValue = $after[$label];

            if ($oldValue !== $newValue) {
                $changes[] = $label . ': «' . ($oldValue !== '' ? $oldValue : '—') . '» → «' . ($newValue !== '' ? $newValue : '—') . '»';
            }
        }

        $this->history->add(
            $clientId,
            'updated',
            (int) $actor['ID'],
            $changes !== [] ? implode('; ', $changes) : 'Изменений нет'
        );

        return ['id' => $clientId];
    }

    public function changeLogin(array $actor, array $capabilities, int $clientId, string $login): array
    {
        $client = $this->assertClient($clientId);
        $this->assertCanManageAccount($actor, $capabilities, $clientId);

        if (Config::demoMode()) {
            throw new HttpException(403, 'demo_mode', 'В демо-режиме изменение логина недоступно');
        }

        $login = trim($login);

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{2,63}$/', $login)) {
            throw new HttpException(
                422,
                'validation_error',
                'Логин — 3–64 символа: латиница, цифры, точка, дефис, подчёркивание или @'
            );
        }

        $existing = $this->users->findAnyByLogin($login);

        if ($existing !== null && (int) $existing['ID'] !== $clientId) {
            throw new HttpException(422, 'login_taken', 'Такой логин уже занят');
        }

        $previous = (string) ($client['LOGIN'] ?? '');
        $changed = false;

        if (strcasecmp($previous, $login) !== 0) {
            $changed = true;
            $pdo = Database::pdo();
            $pdo->beginTransaction();

            try {
                $this->users->updateLogin($clientId, $login);
                $this->tokens->revokeAllForUser($clientId);
                $this->history->add(
                    $clientId,
                    'login_changed',
                    (int) $actor['ID'],
                    'Логин: «' . $previous . '» → «' . $login . '»'
                );
                $pdo->commit();
            } catch (\Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }
        }

        return ['id' => $clientId, 'login' => $login, 'changed' => $changed];
    }

    public function card(array $actor, array $capabilities, int $clientId): array
    {
        $client = $this->users->findById($clientId);

        if ($client === null || (int) $client['LEVEL'] !== 5) {
            throw new HttpException(404, 'not_found', 'Клиент не найден');
        }

        $this->assertCanView($actor, $capabilities, $clientId);

        if ((int) $actor['LEVEL'] >= 10
            && !$this->views->recentExists('user', $clientId, (int) $actor['ID'], 'view', 15)
        ) {
            $this->views->add('user', $clientId, (int) $actor['ID'], 'view');
        }

        $views = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'user' => [
                'id' => (int) $row['user_id'],
                'name' => (string) $row['user_name'],
                'level' => (int) $row['user_level'],
            ],
            'created_at' => (string) $row['created_at'],
        ], $this->views->listForEntity('user', $clientId, 50));

        $history = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'event' => (string) $row['event'],
            'comment' => (string) ($row['comment'] ?? ''),
            'actor' => $row['actor_id'] !== null
                ? [
                    'id' => (int) $row['actor_id'],
                    'name' => (string) ($row['actor_name'] ?? ''),
                    'level' => (int) ($row['actor_level'] ?? 0),
                ]
                : null,
            'created_at' => (string) $row['created_at'],
        ], $this->history->listByUser($clientId));

        $requestRows = $this->requests->listForClient($clientId, 200);

        $requests = array_map(static fn (array $row): array => [
            'id' => (int) $row['ID'],
            'number' => (string) $row['number'],
            'subject' => (string) $row['subject'],
            'status' => [
                'code' => (string) $row['status_id'],
                'title' => (string) $row['status_title'],
                'color' => (string) $row['status_color'],
                'is_final' => (bool) $row['status_is_final'],
            ],
            'manager' => $row['manager_id'] !== null
                ? ['id' => (int) $row['manager_id'], 'name' => (string) ($row['manager_name'] ?? '')]
                : null,
            'created_at' => (string) $row['created_at'],
            'due_at' => $row['due_at'] !== null ? (string) $row['due_at'] : null,
            'is_overdue' => (bool) $row['is_overdue'],
        ], $requestRows);

        return [
            'client' => [
                'id' => $clientId,
                'login' => (string) ($client['LOGIN'] ?? ''),
                'name' => (string) ($client['FULL_NAME'] ?? ''),
                'email' => (string) ($client['EMAIL'] ?? ''),
                'phone' => (string) ($client['PHONE'] ?? ''),
                'company' => (string) ($client['COMPANY'] ?? ''),
                'inn' => (string) ($client['INN'] ?? ''),
                'position' => (string) ($client['DOLGNOST'] ?? ''),
                'city' => (string) ($client['CITY'] ?? ''),
                'address' => (string) ($client['ADDRESS'] ?? ''),
                'active' => ($client['ACTIVE'] ?? 'N') === 'Y',
                'reg_state' => (string) ($client['reg_state'] ?? 'active'),
                'registered_at' => (string) ($client['TIME_ADD'] ?? ''),
                'last_seen_at' => $client['TIME_ACTIVE'] !== null ? (string) $client['TIME_ACTIVE'] : null,
                'price_types' => $this->prices->assignedTypesForUser($client),
            ],
            'manager' => $this->managerInfo($clientId),
            'transfer' => $this->transferInfo($actor, $capabilities, $clientId),
            'can' => $this->permissions($actor, $capabilities, $clientId, $client),
            'stats' => $this->requests->statsForClient($clientId),
            'interests' => array_map(static fn (array $row): array => [
                'name' => (string) $row['name'],
                'unit' => (string) ($row['unit'] ?? ''),
                'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
                'orders' => (int) $row['orders'],
                'total_qty' => (float) $row['total_qty'],
                'last_at' => (string) $row['last_at'],
            ], $this->requests->clientInterests($clientId, '', 'qty_desc', 20, 0)),
            'history' => $history,
            'views' => $views,
            'requests' => $requests,
        ];
    }

    public function history(array $actor, array $capabilities, int $clientId): array
    {
        $this->assertClient($clientId);
        $this->assertCanView($actor, $capabilities, $clientId);

        $views = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'user' => [
                'id' => (int) $row['user_id'],
                'name' => (string) $row['user_name'],
                'level' => (int) $row['user_level'],
            ],
            'created_at' => (string) $row['created_at'],
        ], $this->views->listForEntityAll('user', $clientId));

        $history = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'event' => (string) $row['event'],
            'comment' => (string) ($row['comment'] ?? ''),
            'actor' => $row['actor_id'] !== null
                ? [
                    'id' => (int) $row['actor_id'],
                    'name' => (string) ($row['actor_name'] ?? ''),
                    'level' => (int) ($row['actor_level'] ?? 0),
                ]
                : null,
            'created_at' => (string) $row['created_at'],
        ], $this->history->listByUser($clientId));

        $requests = array_map(static fn (array $row): array => [
            'id' => (int) $row['ID'],
            'number' => (string) $row['number'],
            'subject' => (string) $row['subject'],
            'status' => [
                'code' => (string) $row['status_id'],
                'title' => (string) $row['status_title'],
                'color' => (string) $row['status_color'],
                'is_final' => (bool) $row['status_is_final'],
            ],
            'manager' => $row['manager_id'] !== null
                ? ['id' => (int) $row['manager_id'], 'name' => (string) ($row['manager_name'] ?? '')]
                : null,
            'created_at' => (string) $row['created_at'],
            'due_at' => $row['due_at'] !== null ? (string) $row['due_at'] : null,
            'is_overdue' => (bool) $row['is_overdue'],
        ], $this->requests->listForClientAll($clientId));

        return [
            'history' => $history,
            'views' => $views,
            'requests' => $requests,
        ];
    }

    public function interests(array $actor, array $capabilities, int $clientId, array $filters): array
    {
        $client = $this->users->findById($clientId);

        if ($client === null || (int) $client['LEVEL'] !== 5) {
            throw new HttpException(404, 'not_found', 'Клиент не найден');
        }

        $this->assertCanView($actor, $capabilities, $clientId);

        $query = trim((string) ($filters['q'] ?? ''));
        $sort = (string) ($filters['sort'] ?? 'qty_desc');
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = $perPage <= 0 ? 0 : max(10, min(100, $perPage));
        $warehousesRaw = $filters['warehouses'] ?? [];
        $warehousesRaw = is_array($warehousesRaw) ? $warehousesRaw : explode(',', (string) $warehousesRaw);
        $warehouses = array_values(array_filter(
            array_map(static fn ($value): string => trim((string) $value), $warehousesRaw),
            static fn (string $value): bool => $value !== ''
        ));
        $listFilters = [
            'warehouses' => $warehouses,
            'from' => $this->dateFilterValue((string) ($filters['from'] ?? '')),
            'to' => $this->dateFilterValue((string) ($filters['to'] ?? '')),
        ];

        $items = array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'unit' => (string) ($row['unit'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
            'orders' => (int) $row['orders'],
            'total_qty' => (float) $row['total_qty'],
            'last_at' => (string) $row['last_at'],
        ], $this->requests->clientInterests($clientId, $query, $sort, $perPage, ($page - 1) * $perPage, $listFilters));

        return [
            'items' => $items,
            'total' => $this->requests->countClientInterests($clientId, $query, $listFilters),
            'warehouses' => $this->requests->clientInterestWarehouses($clientId),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function dateFilterValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new HttpException(422, 'validation_error', 'Некорректная дата периода');
        }

        return $value;
    }

    private function assertPendingClient(int $clientId): array
    {
        $client = $this->assertClient($clientId);

        if (($client['reg_state'] ?? 'active') !== 'pending') {
            throw new HttpException(422, 'not_pending', 'Заявка на регистрацию уже обработана');
        }

        return $client;
    }

    private function managerInfo(int $clientId): ?array
    {
        $row = $this->managers->managerFor($clientId);

        if ($row === null) {
            return null;
        }

        $temporary = $this->managers->activeTemporary($clientId);

        return [
            'id' => (int) $row['manager_id'],
            'name' => (string) $row['manager_name'],
            'level' => (int) ($row['manager_level'] ?? 0),
            'assigned_at' => (string) $row['assigned_at'],
            'assigned_by' => $row['assigned_by'] !== null
                ? ['id' => (int) $row['assigned_by'], 'name' => (string) ($row['assigned_by_name'] ?? '')]
                : null,
            'temporary_until' => $temporary !== null ? (string) $temporary['date_to'] : null,
        ];
    }

    private function transferInfo(array $actor, array $capabilities, int $clientId): ?array
    {
        $transfer = $this->managers->currentForClient($clientId);

        if ($transfer === null) {
            return null;
        }

        $isPending = $transfer['status'] === 'pending';
        $isScheduled = $transfer['status'] === 'scheduled';

        $transfer['incoming'] = $isPending
            && $transfer['to'] !== null
            && (int) $transfer['to']['id'] === (int) $actor['ID'];
        $transfer['can_accept'] = $transfer['incoming'];
        $transfer['can_cancel'] = ($isPending || $isScheduled)
            && ((int) $transfer['created_by']['id'] === (int) $actor['ID']
                || in_array('clients.assign', $capabilities, true));

        return $transfer;
    }

    /**
     * Сброс пароля клиента из карточки: новый пароль возвращается один раз.
     */
    public function resetPassword(array $actor, array $capabilities, int $clientId): array
    {
        $client = $this->assertClient($clientId);
        $this->assertCanManageAccount($actor, $capabilities, $clientId);

        if (Config::demoMode()) {
            throw new HttpException(403, 'demo_mode', 'Действие недоступно в демо-режиме');
        }

        if ((string) ($client['reg_state'] ?? 'active') !== 'active') {
            throw new HttpException(422, 'not_activated', 'Сначала подтвердите регистрацию клиента');
        }

        $password = bin2hex(random_bytes(5)) . 'Aa1';
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $this->users->updatePassword($clientId, password_hash($password, PASSWORD_DEFAULT));
            $this->tokens->revokeAllForUser($clientId);
            $this->resets->invalidateForUser($clientId);
            $this->history->add($clientId, 'password_reset', (int) $actor['ID'], 'Пароль сброшен из карточки клиента');
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        [$mailSent, $email] = $this->notifyPasswordReset($actor, $client, $password);

        return [
            'id' => $clientId,
            'login' => (string) ($client['LOGIN'] ?? ''),
            'password' => $password,
            'channel' => 'show',
            'email_sent' => $mailSent,
            'email' => $email,
        ];
    }

    public function revokeSessions(array $actor, array $capabilities, int $clientId): array
    {
        $client = $this->assertClient($clientId);
        $this->assertCanManageAccount($actor, $capabilities, $clientId);

        if (Config::demoMode()) {
            throw new HttpException(403, 'demo_mode', 'Действие недоступно в демо-режиме');
        }

        if ((string) ($client['reg_state'] ?? 'active') !== 'active') {
            throw new HttpException(422, 'not_activated', 'Сначала подтвердите регистрацию клиента');
        }

        $this->tokens->revokeAllForUser($clientId);
        $this->history->add(
            $clientId,
            'sessions_revoked',
            (int) $actor['ID'],
            'Завершены все сессии клиента'
        );

        return ['id' => $clientId, 'revoked' => true];
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function notifyPasswordReset(array $actor, array $client, string $password): array
    {
        $email = trim((string) ($client['EMAIL'] ?? ''));

        if ($email === '' || !Validator::email($email)) {
            return [false, null];
        }

        $mailConfig = $this->mail->config();

        if (!$mailConfig['enabled']) {
            return [false, $email];
        }

        $name = (string) ($client['FULL_NAME'] ?? '');
        $actorName = (string) ($actor['FULL_NAME'] ?? '');

        $this->mail->enqueue(
            $email,
            'Пароль был сброшен',
            "Здравствуйте, $name!\n\n"
            . "Ваш пароль был сброшен: $actorName.\n"
            . "Новый пароль: $password\n\n"
            . "После входа рекомендуем сменить пароль в профиле."
        );

        return [true, $email];
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function notifyAccessChange(array $actor, array $client, bool $blocked, string $comment): array
    {
        $email = trim((string) ($client['EMAIL'] ?? ''));

        if ($email === '' || !Validator::email($email)) {
            return [false, null];
        }

        $mailConfig = $this->mail->config();

        if (!$mailConfig['enabled']) {
            return [false, $email];
        }

        $name = (string) ($client['FULL_NAME'] ?? '');
        $actorName = (string) ($actor['FULL_NAME'] ?? '');
        $reason = $blocked && $comment !== '' ? "\nПричина: $comment\n" : '';
        $state = $blocked ? 'заблокирована' : 'активирована';

        $this->mail->enqueue(
            $email,
            $blocked ? 'Учётная запись заблокирована' : 'Учётная запись активирована',
            "Здравствуйте, $name!\n\n"
            . "Ваша учётная запись $state.\n"
            . $reason
            . "\nДействие выполнил(а): $actorName.\n\n"
            . "Если это ошибка — свяжитесь с вашим менеджером."
        );

        return [true, $email];
    }

    /**
     * Отправка клиенту ссылки на смену пароля (одноразовый токен, 60 минут).
     */
    public function sendPasswordResetLink(array $actor, array $capabilities, int $clientId): array
    {
        $client = $this->assertClient($clientId);
        $this->assertCanManageAccount($actor, $capabilities, $clientId);

        if (Config::demoMode()) {
            throw new HttpException(403, 'demo_mode', 'Действие недоступно в демо-режиме');
        }

        $email = trim((string) ($client['EMAIL'] ?? ''));

        if ($email === '' || !Validator::email($email)) {
            throw new HttpException(422, 'no_email', 'У клиента не указан e-mail');
        }

        $state = (string) ($client['reg_state'] ?? 'active');

        if ($state !== 'active' || ($client['ACTIVE'] ?? 'N') !== 'Y') {
            throw new HttpException(422, 'not_activated', 'Учётная запись клиента ещё не активирована');
        }

        $mailConfig = $this->mail->config();

        if (!$mailConfig['enabled']) {
            throw new HttpException(422, 'mail_disabled', 'Почта не настроена — используйте сброс с показом пароля');
        }

        $token = bin2hex(random_bytes(32));

        $this->resets->invalidateForUser($clientId);
        $this->resets->create($clientId, hash('sha256', $token), 60);

        $appUrl = rtrim((string) Config::get('APP_URL', 'http://localhost:5173'), '/');
        $link = $appUrl . '/#/reset?token=' . $token;

        $this->mail->enqueue(
            $email,
            'Восстановление пароля',
            "Здравствуйте!\n\nСсылка для установки нового пароля (действует 60 минут):\n$link\n\n"
            . "Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо."
        );

        return [
            'id' => $clientId,
            'email' => $email,
            'channel' => 'email',
        ];
    }

    private function canManageAccount(array $actor, array $capabilities, int $clientId): bool
    {
        if ((int) $actor['LEVEL'] < 10) {
            return false;
        }

        return $this->managers->canManage($actor, $capabilities, $clientId)
            || in_array('clients.assign', $capabilities, true);
    }

    private function assertCanManageAccount(array $actor, array $capabilities, int $clientId): void
    {
        if (!$this->canManageAccount($actor, $capabilities, $clientId)) {
            throw new HttpException(403, 'forbidden', 'Нет доступа к управлению учётной записью');
        }
    }

    private function permissions(array $actor, array $capabilities, int $clientId, array $client): array
    {
        $managerRow = $this->managers->managerFor($clientId);
        $transferRow = $this->managers->currentForClient($clientId);
        $isStaff = (int) $actor['LEVEL'] >= 10;
        $canAssign = in_array('clients.assign', $capabilities, true);
        $canManage = $this->managers->canManage($actor, $capabilities, $clientId);

        return [
            'manage' => $canManage,
            'access' => $this->canManageAccount($actor, $capabilities, $clientId),
            'reset_password' => $this->canManageAccount($actor, $capabilities, $clientId)
                && !Config::demoMode(),
            'revoke_sessions' => $this->canManageAccount($actor, $capabilities, $clientId)
                && !Config::demoMode()
                && (($client['reg_state'] ?? 'active') === 'active'),
            'assign' => $canAssign,
            'claim' => $isStaff
                && $managerRow === null
                && ($client['ACTIVE'] ?? 'N') === 'Y',
            'accept' => $isStaff
                && $transferRow !== null
                && $transferRow['status'] === 'pending'
                && $transferRow['to'] !== null
                && (int) $transferRow['to']['id'] === (int) $actor['ID'],
            'cancel' => $isStaff
                && $transferRow !== null
                && in_array($transferRow['status'], ['pending', 'scheduled'], true)
                && ((int) $transferRow['created_by']['id'] === (int) $actor['ID'] || $canAssign),
            'convert_to_staff' => in_array('roles.manage', $capabilities, true)
                && (int) $actor['LEVEL'] >= 90
                && !Config::demoMode()
                && ($client['ACTIVE'] ?? 'N') === 'Y'
                && ($client['reg_state'] ?? 'active') === 'active',
        ];
    }

    private function assertClient(int $clientId): array
    {
        $client = $this->users->findById($clientId);

        if ($client === null || (int) $client['LEVEL'] !== 5) {
            throw new HttpException(404, 'not_found', 'Клиент не найден');
        }

        return $client;
    }

    private function assertCanView(array $actor, array $capabilities, int $clientId): void
    {
        if ((int) $actor['LEVEL'] >= 10) {
            return;
        }

        if ($clientId === (int) $actor['ID']) {
            return;
        }

        throw new HttpException(403, 'forbidden', 'Нет доступа к клиенту');
    }

    private function requireConfirm(array $capabilities): void
    {
        if (!in_array('clients.confirm', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для подтверждения регистрации');
        }
    }
}
