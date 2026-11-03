<?php

declare(strict_types=1);

namespace App\Stocks;

use App\Http\HttpException;
use App\Repositories\ItemTypeRepository;

/**
 * Справочник типов номенклатуры: список, создание, редактирование, удаление.
 */
final class ItemTypeService
{
    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
        'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
        'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    public function __construct(
        private readonly ItemTypeRepository $types = new ItemTypeRepository()
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function enabledCodes(): array
    {
        return $this->types->enabledCodes();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function enabledList(): array
    {
        return $this->types->enabled();
    }

    public function isKnown(string $code): bool
    {
        return $this->types->findByCode($code) !== null;
    }

    public function defaultCode(): string
    {
        $enabled = $this->enabledCodes();

        return $enabled[0] ?? '';
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    public function list(array $capabilities): array
    {
        $this->requireSettings($capabilities);

        $rows = $this->types->all();
        $total = count($rows);

        return [
            'types' => array_map(
                fn (array $row): array => $this->payloadItem($row, $total),
                $rows
            ),
        ];
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    public function get(array $capabilities, int $id): array
    {
        $this->requireSettings($capabilities);

        $type = $this->types->findById($id);

        if ($type === null) {
            throw new HttpException(404, 'not_found', 'Тип номенклатуры не найден');
        }

        return ['type' => $this->payloadItem($type, $this->types->countAll())];
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    public function items(array $capabilities, int $id): array
    {
        $this->requireSettings($capabilities);

        $type = $this->types->findById($id);

        if ($type === null) {
            throw new HttpException(404, 'not_found', 'Тип номенклатуры не найден');
        }

        return ['items' => $this->types->items((string) $type['code'])];
    }

    /**
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $capabilities, array $input): array
    {
        $this->requireSettings($capabilities);

        $title = $this->validateTitle($input['title'] ?? '');
        $description = $this->validateDescription($input['description'] ?? '');
        $enabled = $this->parseEnabled($input['enabled'] ?? true);
        $code = $this->makeCode($title);

        $id = $this->types->create(
            $code,
            $title,
            $description,
            $enabled,
            $this->types->nextSort()
        );

        return $this->get($capabilities, $id);
    }

    /**
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(array $capabilities, int $id, array $input): array
    {
        $this->requireSettings($capabilities);

        $type = $this->types->findById($id);

        if ($type === null) {
            throw new HttpException(404, 'not_found', 'Тип номенклатуры не найден');
        }

        $title = $this->validateTitle($input['title'] ?? $type['title']);
        $description = $this->validateDescription($input['description'] ?? $type['description']);
        $enabled = array_key_exists('enabled', $input)
            ? $this->parseEnabled($input['enabled'])
            : (bool) $type['enabled'];

        if ((bool) $type['enabled'] && !$enabled && (int) ($type['positions_count'] ?? 0) > 0) {
            throw new HttpException(
                422,
                'type_not_empty',
                'Нельзя выключить тип: в нём есть позиции номенклатуры'
            );
        }

        if ((bool) $type['enabled'] && !$enabled && $this->types->countEnabled($id) === 0) {
            throw new HttpException(
                422,
                'last_enabled_type',
                'Нельзя выключить последний включённый тип номенклатуры'
            );
        }

        $this->types->update($id, $title, $description, $enabled);

        return $this->get($capabilities, $id);
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    public function delete(array $capabilities, int $id): array
    {
        $this->requireSettings($capabilities);

        $type = $this->types->findById($id);

        if ($type === null) {
            throw new HttpException(404, 'not_found', 'Тип номенклатуры не найден');
        }

        if ($this->types->countAll() <= 1) {
            throw new HttpException(
                422,
                'last_type',
                'Нельзя удалить последний тип номенклатуры'
            );
        }

        if ($this->types->countNomenclature((string) $type['code']) > 0) {
            throw new HttpException(
                422,
                'item_type_in_use',
                'Тип используется в номенклатуре. Сначала измените тип у этих позиций.'
            );
        }

        if ((bool) $type['enabled'] && $this->types->countEnabled($id) === 0) {
            throw new HttpException(
                422,
                'last_enabled_type',
                'Нельзя удалить последний включённый тип номенклатуры'
            );
        }

        $this->types->delete($id);

        return ['id' => $id];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function payloadItem(array $row, int $total = 0): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'enabled' => (bool) $row['enabled'],
            'sort' => (int) ($row['sort'] ?? 0),
            'positions_count' => (int) ($row['positions_count'] ?? 0),
            'is_last' => $total <= 1,
        ];
    }

    private function validateTitle(mixed $value): string
    {
        $title = mb_substr(trim((string) $value), 0, 255);

        if ($title === '') {
            throw new HttpException(422, 'validation_error', 'Укажите название типа');
        }

        return $title;
    }

    private function validateDescription(mixed $value): string
    {
        return mb_substr(trim((string) $value), 0, 1000);
    }

    private function parseEnabled(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    private function makeCode(string $title): string
    {
        $normalized = mb_strtolower($title);
        $normalized = strtr($normalized, self::TRANSLIT);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', $normalized), '_');
        $base = $base !== '' ? mb_substr($base, 0, 42) : 'type';
        $code = $base;
        $suffix = 2;

        while ($this->types->findByCode($code) !== null) {
            $code = $base . '_' . $suffix;
            $suffix += 1;
        }

        return $code;
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function requireSettings(array $capabilities): void
    {
        if (!in_array('settings.manage', $capabilities, true)) {
            throw new HttpException(
                403,
                'forbidden',
                'Недостаточно прав для управления типами номенклатуры'
            );
        }
    }
}
