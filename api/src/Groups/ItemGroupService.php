<?php

declare(strict_types=1);

namespace App\Groups;

use App\Http\HttpException;
use App\Repositories\ItemGroupRepository;
use App\Repositories\SettingsRepository;

final class ItemGroupService
{
    public function __construct(
        private readonly ItemGroupRepository $groups = new ItemGroupRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository()
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->groupsEnabled();
    }

    public function enabledForUser(?array $user): bool
    {
        return $user !== null && $this->settings->groupsEnabledForLevel((int) ($user['LEVEL'] ?? 0));
    }

    public function list(bool $onlyEnabled = false): array
    {
        return $this->groups->all($onlyEnabled);
    }

    public function hasUngrouped(): bool
    {
        return $this->groups->hasUngrouped();
    }

    public function find(int $id): ?array
    {
        return $this->groups->find($id);
    }

    public function items(int $id): array
    {
        if ($this->groups->find($id) === null) {
            throw new HttpException(404, 'not_found', 'Группа не найдена');
        }

        return $this->groups->items($id);
    }

    public function create(string $title, string $description = '', bool $enabled = true): array
    {
        $title = $this->normalizeTitle($title);
        $description = $this->normalizeDescription($description);

        if ($this->groups->titleTaken($title)) {
            throw new HttpException(422, 'duplicate_group', 'Группа с таким названием уже есть');
        }

        $id = $this->groups->create($title, $description, $enabled, $this->groups->nextSort());

        return $this->groups->find($id) ?? [];
    }

    public function update(int $id, string $title, string $description = '', bool $enabled = true): array
    {
        if ($this->groups->find($id) === null) {
            throw new HttpException(404, 'not_found', 'Группа не найдена');
        }

        $title = $this->normalizeTitle($title);
        $description = $this->normalizeDescription($description);

        if ($this->groups->titleTaken($title, $id)) {
            throw new HttpException(422, 'duplicate_group', 'Группа с таким названием уже есть');
        }

        $this->groups->update($id, $title, $description, $enabled);

        return $this->groups->find($id) ?? [];
    }

    public function delete(int $id): void
    {
        if ($this->groups->find($id) === null) {
            throw new HttpException(404, 'not_found', 'Группа не найдена');
        }

        $positions = $this->groups->countItems($id);

        if ($positions > 0) {
            throw new HttpException(
                422,
                'group_not_empty',
                "В группе есть позиции номенклатуры ($positions). Сначала уберите их из группы."
            );
        }

        $this->groups->delete($id);
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            throw new HttpException(422, 'validation_error', 'Укажите название группы');
        }

        if (mb_strlen($title) > 255) {
            throw new HttpException(422, 'validation_error', 'Название слишком длинное (до 255 символов)');
        }

        return $title;
    }

    private function normalizeDescription(string $description): string
    {
        $description = trim($description);

        if (mb_strlen($description) > 1000) {
            throw new HttpException(422, 'validation_error', 'Описание слишком длинное (до 1000 символов)');
        }

        return $description;
    }
}
