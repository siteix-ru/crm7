<?php

declare(strict_types=1);

namespace App\Users;

use App\Http\HttpException;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use App\Support\Storage;

final class AvatarService
{
    public const MAX_AVATAR_SIZE = 5242880;

    private const AVATAR_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository()
    ) {
    }

    /**
     * Загружает или заменяет аватар пользователя.
     *
     * @param array<string, mixed> $target строка users
     * @param array<string, mixed> $file элемент $_FILES['file']
     * @param array<string, mixed>|null $actor кто выполняет действие (для истории)
     */
    public function store(array $target, array $file, ?array $actor = null, string $origin = 'profile'): array
    {
        $this->assertValidFile($file);

        $stored = Storage::save($file);
        $previous = (string) ($target['AVATAR_PATH'] ?? '');

        $this->users->updateAvatar((int) $target['ID'], $stored['relative']);

        if ($previous !== '' && $previous !== $stored['relative']) {
            try {
                Storage::delete($previous);
            } catch (\Throwable) {
                // старый файл можно удалить позже
            }
        }

        $this->history->add(
            (int) $target['ID'],
            'avatar_updated',
            $actor !== null ? (int) $actor['ID'] : (int) $target['ID'],
            $this->label($origin) . ($previous !== '' ? ': заменено' : ': добавлено')
        );

        return ['has_avatar' => true];
    }

    /**
     * Удаляет аватар пользователя.
     *
     * @param array<string, mixed> $target строка users
     * @param array<string, mixed>|null $actor кто выполняет действие (для истории)
     */
    public function remove(array $target, ?array $actor = null, string $origin = 'profile'): array
    {
        $previous = (string) ($target['AVATAR_PATH'] ?? '');

        if ($previous === '') {
            return ['has_avatar' => false];
        }

        $this->users->updateAvatar((int) $target['ID'], null);

        try {
            Storage::delete($previous);
        } catch (\Throwable) {
            // файл можно удалить позже
        }

        $this->history->add(
            (int) $target['ID'],
            'avatar_removed',
            $actor !== null ? (int) $actor['ID'] : (int) $target['ID'],
            $this->label($origin) . ': удалено'
        );

        return ['has_avatar' => false];
    }

    private function label(string $origin): string
    {
        return $origin === 'client' ? 'Фото клиента' : 'Фото профиля';
    }

    /**
     * @param array<string, mixed> $file
     */
    private function assertValidFile(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpException(422, 'too_large', 'Файл превышает лимит сервера загрузки');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'upload_failed', 'Файл не загружен');
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            throw new HttpException(422, 'upload_failed', 'Пустой файл');
        }

        if ($size > self::MAX_AVATAR_SIZE) {
            throw new HttpException(422, 'too_large', 'Фото больше 5 МБ');
        }

        $originalName = (string) ($file['name'] ?? 'avatar');
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, self::AVATAR_EXTENSIONS, true)) {
            throw new HttpException(422, 'bad_type', 'Допустимые форматы фото: jpg, png, webp');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file((string) ($file['tmp_name'] ?? '')) ?: '');

        if (!str_starts_with($mime, 'image/')) {
            throw new HttpException(422, 'bad_type', 'Файл не является изображением');
        }
    }
}
