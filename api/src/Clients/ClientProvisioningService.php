<?php

declare(strict_types=1);

namespace App\Clients;

use App\Core\Database;
use App\Http\HttpException;
use App\Repositories\ClientManagerRepository;
use App\Repositories\PriceRepository;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use App\Support\Validator;
use PDOException;
use Throwable;

final class ClientProvisioningService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository(),
        private readonly ClientManagerRepository $managers = new ClientManagerRepository(),
        private readonly PriceRepository $prices = new PriceRepository()
    ) {
    }

    /**
     * Создаёт активного клиента (LEVEL=5) по данным менеджера и привязывает его к автору.
     * Вызывается внутри транзакции создания заявки.
     */
    public function create(array $input, array $actor): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $inn = preg_replace('/\s+/', '', (string) ($input['inn'] ?? '')) ?? '';
        $city = mb_substr(trim((string) ($input['city'] ?? '')), 0, 128);
        $address = mb_substr(trim((string) ($input['address'] ?? '')), 0, 255);
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));

        if (mb_strlen($name) < 2) {
            throw new HttpException(422, 'validation_error', 'Укажите название клиента (минимум 2 символа)');
        }

        if (mb_strlen($name) > 255) {
            throw new HttpException(422, 'validation_error', 'Название клиента слишком длинное');
        }

        if ($inn !== '' && preg_match('/^(\d{10}|\d{12})$/', $inn) !== 1) {
            throw new HttpException(422, 'validation_error', 'ИНН должен содержать 10 или 12 цифр');
        }

        $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($phone !== '' && (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15)) {
            throw new HttpException(422, 'validation_error', 'Телефон должен содержать от 10 до 15 цифр');
        }

        if ($email !== '' && !Validator::email($email)) {
            throw new HttpException(422, 'validation_error', 'Неверный формат e-mail');
        }

        $existing = $inn !== '' ? $this->users->findActiveClientByInn($inn) : null;

        if ($existing !== null) {
            throw new HttpException(
                422,
                'client_exists',
                'Клиент с таким ИНН уже есть в системе: ' . (string) ($existing['FULL_NAME'] ?? '')
            );
        }

        if ($phone !== '' && $this->users->findAnyByPhone($phone) !== null) {
            throw new HttpException(422, 'phone_taken', 'Клиент с таким телефоном уже есть в системе');
        }

        if ($email !== ''
            && ($this->users->findAnyByEmail($email) !== null || $this->users->findAnyByLogin($email) !== null)
        ) {
            throw new HttpException(422, 'email_taken', 'Клиент с таким e-mail уже есть в системе');
        }

        $login = $this->uniqueLogin($email !== '' ? $email : $this->phoneLogin($phoneDigits));
        $password = bin2hex(random_bytes(5)) . 'Aa1';

        try {
            $clientId = $this->users->createActiveClient([
                'login' => $login,
                'password' => $password,
                'name' => $name,
                'inn' => $inn,
                'city' => $city,
                'address' => $address,
                'phone' => $phone,
                'email' => $email,
            ]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? '') === '23000') {
                throw new HttpException(
                    422,
                    'client_exists',
                    'Клиент с такими контактами уже есть в системе'
                );
            }

            throw $exception;
        }

        $actorId = (int) ($actor['ID'] ?? 0);

        $this->history->add(
            $clientId,
            'created',
            $actorId > 0 ? $actorId : null,
            'Создан менеджером при оформлении заявки'
        );

        if ($actorId > 0) {
            $this->managers->assign($clientId, $actorId, $actorId);
        }

        return [
            'id' => $clientId,
            'name' => $name,
            'login' => $login,
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
        ];
    }

    /**
     * Создаёт активного клиента вручную из раздела «Клиенты».
     * Менеджером по умолчанию становится автор, при наличии права можно назначить другого.
     */
    public function createManual(array $input, array $actor, array $capabilities): array
    {
        $actorId = (int) ($actor['ID'] ?? 0);

        if ((int) ($actor['LEVEL'] ?? 0) < 10) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для создания клиента');
        }

        $name = trim((string) ($input['name'] ?? ''));
        $company = mb_substr(trim((string) ($input['company'] ?? '')), 0, 255);
        $inn = preg_replace('/\s+/', '', (string) ($input['inn'] ?? '')) ?? '';
        $position = mb_substr(trim((string) ($input['position'] ?? '')), 0, 255);
        $city = mb_substr(trim((string) ($input['city'] ?? '')), 0, 128);
        $address = mb_substr(trim((string) ($input['address'] ?? '')), 0, 255);
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $login = trim((string) ($input['login'] ?? ''));

        if (mb_strlen($name) < 2) {
            throw new HttpException(422, 'validation_error', 'Укажите имя клиента (минимум 2 символа)');
        }

        if (mb_strlen($name) > 255) {
            throw new HttpException(422, 'validation_error', 'Имя клиента слишком длинное');
        }

        if ($inn !== '' && preg_match('/^(\d{10}|\d{12})$/', $inn) !== 1) {
            throw new HttpException(422, 'validation_error', 'ИНН должен содержать 10 или 12 цифр');
        }

        $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($phone !== '' && (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15)) {
            throw new HttpException(422, 'validation_error', 'Телефон должен содержать от 10 до 15 цифр');
        }

        if ($email !== '' && !Validator::email($email)) {
            throw new HttpException(422, 'validation_error', 'Некорректный e-mail');
        }

        if ($login !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{2,63}$/', $login) !== 1) {
            throw new HttpException(
                422,
                'validation_error',
                'Логин: 3–64 символа, латиница, цифры, точка, дефис, подчёркивание или @'
            );
        }

        if ($inn !== '' && $this->users->findActiveClientByInn($inn) !== null) {
            throw new HttpException(422, 'client_exists', 'Клиент с таким ИНН уже существует');
        }

        if ($phone !== '' && $this->users->findAnyByPhone($phone) !== null) {
            throw new HttpException(422, 'phone_taken', 'Клиент с таким телефоном уже существует');
        }

        if ($email !== ''
            && ($this->users->findAnyByEmail($email) !== null || $this->users->findAnyByLogin($email) !== null)
        ) {
            throw new HttpException(422, 'email_taken', 'Клиент с таким e-mail уже существует');
        }

        if ($login !== '' && $this->users->findAnyByLogin($login) !== null) {
            throw new HttpException(422, 'login_taken', 'Такой логин уже занят');
        }

        if ($login === '') {
            $login = $this->uniqueLogin($email !== '' ? $email : $this->phoneLogin($phoneDigits));
        }

        $password = (string) ($input['password'] ?? '');
        $generated = $password === '';

        if ($generated) {
            $password = bin2hex(random_bytes(5)) . 'Aa1';
        } else {
            $error = Validator::password($password);

            if ($error !== null) {
                throw new HttpException(422, 'weak_password', $error);
            }
        }

        $managerId = (int) ($input['manager_id'] ?? 0);

        if ($managerId <= 0) {
            $managerId = $actorId;
        }

        if ($managerId !== $actorId && !in_array('clients.assign', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для назначения менеджера');
        }

        $manager = $managerId > 0 ? $this->users->findById($managerId) : null;

        if ($manager === null
            || ($manager['ACTIVE'] ?? 'N') !== 'Y'
            || ($manager['STATUS'] ?? 'N') !== 'Y'
            || !in_array((int) ($manager['LEVEL'] ?? 0), [10, 50, 90], true)
        ) {
            throw new HttpException(422, 'manager_invalid', 'Выбранный менеджер недоступен');
        }

        $priceTypeIds = [];
        $rawPriceTypes = $input['price_type_ids'] ?? [];

        if (is_array($rawPriceTypes)) {
            foreach ($rawPriceTypes as $value) {
                $typeId = (int) $value;

                if ($typeId > 0
                    && !in_array($typeId, $priceTypeIds, true)
                    && $this->prices->findType($typeId) !== null
                ) {
                    $priceTypeIds[] = $typeId;
                }
            }
        }

        if ($priceTypeIds !== [] && !in_array('prices.assign', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для назначения типов цен');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $clientId = $this->users->createActiveClient([
                'login' => $login,
                'password' => $password,
                'name' => $name,
                'company' => $company,
                'inn' => $inn,
                'position' => $position,
                'city' => $city,
                'address' => $address,
                'phone' => $phone,
                'email' => $email,
            ]);

            $this->history->add($clientId, 'created', $actorId, 'Создан вручную из раздела «Клиенты»');
            $this->managers->assign($clientId, $managerId, $actorId);

            if ($priceTypeIds !== []) {
                $this->prices->replaceAssignedTypesWithin($clientId, $priceTypeIds);
            }

            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (($exception->errorInfo[0] ?? '') === '23000') {
                throw new HttpException(422, 'client_exists', 'Клиент с такими данными уже существует');
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        return [
            'id' => $clientId,
            'name' => $name,
            'login' => $login,
            'email' => $email,
            'phone' => $phone,
            'password' => $generated ? $password : null,
            'password_generated' => $generated,
            'manager' => [
                'id' => $managerId,
                'name' => (string) ($manager['FULL_NAME'] ?? ''),
            ],
            'price_types' => $this->prices->assignedTypes($clientId),
        ];
    }

    private function phoneLogin(string $digits): string
    {
        return 'client-' . ($digits !== '' ? $digits : bin2hex(random_bytes(3)));
    }

    private function uniqueLogin(string $base): string
    {
        $login = mb_substr($base, 0, 200);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($this->users->findAnyByLogin($login) === null) {
                return $login;
            }

            $login = mb_substr($base, 0, 190) . '-' . bin2hex(random_bytes(3));
        }

        return 'client-' . bin2hex(random_bytes(8));
    }
}
