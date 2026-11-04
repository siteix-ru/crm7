<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FileResponse;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use App\Support\Storage;
use App\Support\Validator;
use App\Users\AvatarService;

final class ProfileController extends ApiController
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository(),
        private readonly AvatarService $avatars = new AvatarService()
    ) {
        parent::__construct();
    }

    public function update(Request $request): Response
    {
        [$user] = $this->context($request);
        $userId = (int) $user['ID'];
        $target = $this->users->findById($userId);

        if ($target === null) {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        $isStaff = (int) ($target['LEVEL'] ?? 0) >= 10;
        $input = $request->bodyAll();
        $text = static function (string $key, string $fallback) use ($input): string {
            if (!array_key_exists($key, $input)) {
                return $fallback;
            }

            $value = $input[$key];

            return is_scalar($value) ? trim((string) $value) : $fallback;
        };

        $name = $text('name', (string) ($target['FULL_NAME'] ?? ''));
        $position = mb_substr($text('position', (string) ($target['DOLGNOST'] ?? '')), 0, 255);
        $phone = mb_substr($text('phone', (string) ($target['PHONE'] ?? '')), 0, 255);
        $email = mb_strtolower(mb_substr($text('email', (string) ($target['EMAIL'] ?? '')), 0, 255));
        $city = mb_substr($text('city', (string) ($target['CITY'] ?? '')), 0, 128);
        $department = $isStaff
            ? mb_substr($text('department', (string) ($target['DEPARTMENT'] ?? '')), 0, 255)
            : (string) ($target['DEPARTMENT'] ?? '');
        $office = $isStaff
            ? mb_substr($text('office', (string) ($target['OFFICE'] ?? '')), 0, 128)
            : (string) ($target['OFFICE'] ?? '');
        $company = $isStaff
            ? (string) ($target['COMPANY'] ?? '')
            : mb_substr($text('company', (string) ($target['COMPANY'] ?? '')), 0, 255);
        $address = $isStaff
            ? (string) ($target['ADDRESS'] ?? '')
            : mb_substr($text('address', (string) ($target['ADDRESS'] ?? '')), 0, 255);
        $innRaw = $isStaff
            ? (string) ($target['INN'] ?? '')
            : $text('inn', (string) ($target['INN'] ?? ''));
        $inn = preg_replace('/\s+/', '', $innRaw) ?? '';

        if (mb_strlen($name) < 3) {
            throw new HttpException(422, 'validation_error', 'Укажите имя (минимум 3 символа)');
        }

        if ($email !== '' && !Validator::email($email)) {
            throw new HttpException(422, 'validation_error', 'Некорректный e-mail');
        }

        if ($phone !== '' && mb_strlen($phone) < 5) {
            throw new HttpException(422, 'validation_error', 'Некорректный телефон');
        }

        if (!$isStaff && $inn !== '' && preg_match('/^\d{10}(\d{2})?$/', $inn) !== 1) {
            throw new HttpException(422, 'validation_error', 'ИНН должен содержать 10 или 12 цифр');
        }

        if ($email !== '') {
            $existing = $this->users->findAnyByEmail($email);

            if ($existing !== null && (int) $existing['ID'] !== $userId) {
                throw new HttpException(422, 'email_taken', 'Пользователь с таким e-mail уже зарегистрирован');
            }
        }

        if ($phone !== '') {
            $existing = $this->users->findAnyByPhone($phone);

            if ($existing !== null && (int) $existing['ID'] !== $userId) {
                throw new HttpException(422, 'phone_taken', 'Пользователь с таким телефоном уже зарегистрирован');
            }
        }

        $before = [
            'Имя' => (string) ($target['FULL_NAME'] ?? ''),
            'Должность' => (string) ($target['DOLGNOST'] ?? ''),
            'Отдел' => (string) ($target['DEPARTMENT'] ?? ''),
            'Город' => (string) ($target['CITY'] ?? ''),
            'Офис' => (string) ($target['OFFICE'] ?? ''),
            'Компания' => (string) ($target['COMPANY'] ?? ''),
            'ИНН' => (string) ($target['INN'] ?? ''),
            'Адрес' => (string) ($target['ADDRESS'] ?? ''),
            'Телефон' => (string) ($target['PHONE'] ?? ''),
            'E-mail' => (string) ($target['EMAIL'] ?? ''),
        ];
        $after = [
            'Имя' => mb_substr($name, 0, 255),
            'Должность' => $position,
            'Отдел' => $department,
            'Город' => $city,
            'Офис' => $office,
            'Компания' => $company,
            'ИНН' => $inn,
            'Адрес' => $address,
            'Телефон' => $phone,
            'E-mail' => $email,
        ];

        $this->users->updateProfile($userId, [
            'name' => $after['Имя'],
            'position' => $after['Должность'],
            'department' => $after['Отдел'],
            'city' => $after['Город'],
            'office' => $after['Офис'],
            'company' => $after['Компания'],
            'inn' => $after['ИНН'],
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
            $userId,
            'profile_updated',
            $userId,
            $changes !== [] ? implode('; ', $changes) : 'Изменений нет'
        );
        $this->audit($request, $user, 'profile.update', 'user', $userId, []);

        return Response::ok([
            'id' => $userId,
            'name' => $after['Имя'],
            'position' => $after['Должность'],
            'department' => $after['Отдел'],
            'city' => $after['Город'],
            'office' => $after['Офис'],
            'company' => $after['Компания'],
            'inn' => $after['ИНН'],
            'address' => $after['Адрес'],
            'phone' => $after['Телефон'],
            'email' => $after['E-mail'],
        ]);
    }

    public function uploadAvatar(Request $request): Response
    {
        [$user] = $this->context($request);

        return Response::ok($this->avatars->store($user, $_FILES['file'] ?? []));
    }

    public function deleteAvatar(Request $request): Response
    {
        [$user] = $this->context($request);

        return Response::ok($this->avatars->remove($user));
    }

    public function avatar(Request $request, array $params): FileResponse
    {
        [$viewer] = $this->context($request);

        $targetId = (int) ($params['id'] ?? 0);
        $target = $this->users->findById($targetId);

        if ($target === null || ($target['AVATAR_PATH'] ?? null) === null) {
            throw new HttpException(404, 'not_found', 'Фото не найдено');
        }

        if ((int) $target['LEVEL'] === 5 && (int) $viewer['LEVEL'] < 10 && (int) $viewer['ID'] !== $targetId) {
            throw new HttpException(403, 'forbidden', 'Нет доступа к фото профиля');
        }

        return new FileResponse(
            Storage::absolute((string) $target['AVATAR_PATH']),
            'avatar-' . $targetId . '.' . pathinfo((string) $target['AVATAR_PATH'], PATHINFO_EXTENSION),
            (string) ($this->detectMime((string) $target['AVATAR_PATH']))
        );
    }

    public function show(Request $request, array $params): Response
    {
        [$viewer] = $this->context($request);

        $target = $this->users->findById((int) ($params['id'] ?? 0));

        if ($target === null || ($target['ACTIVE'] ?? 'N') !== 'Y') {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        $targetLevel = (int) $target['LEVEL'];
        $viewerLevel = (int) $viewer['LEVEL'];

        if ($targetLevel === 5 && $viewerLevel < 10) {
            throw new HttpException(403, 'forbidden', 'Нет доступа к профилю');
        }

        return Response::ok([
            'id' => (int) $target['ID'],
            'login' => (string) ($target['LOGIN'] ?? ''),
            'name' => (string) ($target['FULL_NAME'] ?? ''),
            'level' => $targetLevel,
            'is_staff' => $targetLevel >= 10,
            'position' => (string) ($target['DOLGNOST'] ?? ''),
            'company' => (string) ($target['COMPANY'] ?? ''),
            'email' => (string) ($target['EMAIL'] ?? ''),
            'phone' => (string) ($target['PHONE'] ?? ''),
            'has_avatar' => ($target['AVATAR_PATH'] ?? null) !== null,
        ]);
    }

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $absolute = Storage::absolute($path);

        return is_file($absolute) ? (string) ($finfo->file($absolute) ?: 'application/octet-stream') : 'application/octet-stream';
    }
}
