<?php

declare(strict_types=1);

namespace App\Prices;

use App\Http\HttpException;
use App\Repositories\PriceRepository;
use App\Repositories\SettingsRepository;

final class PriceService
{
    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    public function __construct(
        private readonly PriceRepository $prices = new PriceRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository()
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->pricesEnabled();
    }

    public function types(): array
    {
        return $this->prices->types();
    }

    public function findType(int $id): ?array
    {
        return $this->prices->findType($id);
    }

    public function createType(string $title, string $description = '', bool $enabled = true): array
    {
        $title = trim($title);

        if ($title === '') {
            throw new HttpException(422, 'validation_error', 'Укажите название типа цен');
        }

        if (mb_strlen($title) > 255) {
            throw new HttpException(422, 'validation_error', 'Название слишком длинное (до 255 символов)');
        }

        $description = trim($description);

        if (mb_strlen($description) > 1000) {
            throw new HttpException(422, 'validation_error', 'Описание слишком длинное (до 1000 символов)');
        }

        $id = $this->prices->createType(
            $this->uniqueCode($title),
            $title,
            $description,
            $this->prices->nextSort(),
            $enabled
        );

        return $this->prices->findType($id) ?? [];
    }

    public function updateType(
        int $id,
        string $title,
        string $description = '',
        ?bool $enabled = null
    ): array {
        $type = $this->prices->findType($id);

        if ($type === null) {
            throw new HttpException(404, 'not_found', 'Тип цен не найден');
        }

        $title = trim($title);

        if ($title === '') {
            throw new HttpException(422, 'validation_error', 'Укажите название типа цен');
        }

        if (mb_strlen($title) > 255) {
            throw new HttpException(422, 'validation_error', 'Название слишком длинное (до 255 символов)');
        }

        $description = trim($description);

        if (mb_strlen($description) > 1000) {
            throw new HttpException(422, 'validation_error', 'Описание слишком длинное (до 1000 символов)');
        }

        $this->prices->updateType(
            $id,
            $title,
            $description,
            $enabled ?? (bool) ($type['enabled'] ?? true)
        );

        return $this->prices->findType($id) ?? [];
    }

    public function deleteType(int $id): void
    {
        $type = $this->prices->findType($id);

        if ($type === null) {
            throw new HttpException(404, 'not_found', 'Тип цен не найден');
        }

        $used = 0;

        foreach ($this->prices->types() as $row) {
            if ((int) $row['id'] === $id) {
                $used = (int) $row['users_count'];
            }
        }

        if ($used > 0) {
            throw new HttpException(
                422,
                'price_type_in_use',
                'Тип цен закреплён за пользователями (' . $used . '). Снимите его у них и повторите удаление'
            );
        }

        $this->prices->deletePricesForType($id);
        $this->prices->deleteType($id);
    }

    /**
     * Доступные пользователю типы цен: закреплённые; если ничего не закреплено — базовый тип.
     *
     * @return int[]
     */
    public function availableTypeIdsForUser(?array $user): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $userId = $user !== null ? (int) ($user['ID'] ?? 0) : 0;

        if ($userId > 0) {
            $ids = $this->prices->assignedTypeIds($userId);

            if ($ids !== []) {
                return $ids;
            }
        }

        $baseId = $this->prices->baseTypeId();

        return $baseId !== null ? [$baseId] : [];
    }

    /**
     * Доступные пользователю типы цен с названиями.
     *
     * @return array<int, array{id:int,title:string}>
     */
    public function availableTypesForUser(?array $user): array
    {
        $ids = $this->availableTypeIdsForUser($user);

        if ($ids === []) {
            return [];
        }

        $byId = [];

        foreach ($this->types() as $type) {
            $byId[(int) $type['id']] = $type;
        }

        $result = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $result[] = ['id' => (int) $id, 'title' => (string) $byId[$id]['title']];
            }
        }

        return $result;
    }

    /**
     * Закреплённые типы цен пользователя (пусто, если ничего не закреплено).
     *
     * @return array<int, array{id:int,title:string}>
     */
    public function assignedTypesForUser(?array $user): array
    {
        if ($user === null) {
            return [];
        }

        $userId = (int) ($user['ID'] ?? 0);

        return $userId > 0 ? $this->prices->assignedTypes($userId) : [];
    }

    /**
     * @return int[]
     */
    public function assignedTypeIdsForUser(?array $user): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $this->assignedTypesForUser($user));
    }

    /**
     * Заменяет закреплённый набор типов цен пользователя.
     *
     * @param array<int, mixed> $typeIds
     * @return array<int, array{id:int,title:string}>
     */
    public function setAssignedTypes(int $userId, array $typeIds): array
    {
        $ids = [];

        foreach ($typeIds as $value) {
            $id = (int) $value;

            if ($id > 0 && !in_array($id, $ids, true) && $this->prices->findType($id) !== null) {
                $ids[] = $id;
            }
        }

        $this->prices->replaceAssignedTypes($userId, $ids);

        return $this->assignedTypesForUser(['ID' => $userId]);
    }

    /**
     * Типы цен, используемые складом. Если настройка не задана — все типы.
     *
     * @return int[]
     */
    public function warehouseTypeIds(string $stockSid): array
    {
        $config = $this->prices->warehousePriceTypes($stockSid);

        return $config['set'] ? $config['ids'] : $this->prices->allTypeIds();
    }

    /**
     * @param string[] $stockSids
     * @return array<string, int[]>
     */
    public function warehouseTypeIdsForStocks(array $stockSids): array
    {
        $configs = $this->prices->warehousePriceTypesForStocks($stockSids);
        $all = null;
        $result = [];

        foreach ($configs as $sid => $config) {
            if ($config['set']) {
                $result[$sid] = $config['ids'];
            } else {
                $all ??= $this->prices->allTypeIds();
                $result[$sid] = $all;
            }
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $typeIds
     */
    public function setWarehouseTypes(string $stockSid, array $typeIds): void
    {
        $ids = [];

        foreach ($typeIds as $value) {
            $id = (int) $value;

            if ($id > 0 && !in_array($id, $ids, true) && $this->prices->findType($id) !== null) {
                $ids[] = $id;
            }
        }

        $this->prices->setWarehousePriceTypes($stockSid, $ids);
    }

    /**
     * Дополняет позиции заявки минимальной ценой среди доступных типов (снапшот).
     * Цена берётся по складу позиции: остаток → склад, либо склад заявки по названию.
     *
     * @param array<int, array<string, mixed>> $items
     * @param int[] $typeIds
     * @return array<int, array<string, mixed>>
     */
    public function attachPrices(array $items, array $typeIds): array
    {
        if ($items === []) {
            return [];
        }

        $typeIds = array_values(array_unique(array_filter(
            array_map('intval', $typeIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($typeIds === []) {
            return array_map(static function (array $item): array {
                $item['price'] = null;
                $item['price_type_id'] = null;

                return $item;
            }, $items);
        }

        $levels = $this->prices->nameSidsByLevelIds(array_map(
            static fn (array $item): int => (int) ($item['stock_level_id'] ?? 0),
            $items
        ));
        $names = $this->prices->nameSidsByNames(array_map(
            static fn (array $item): string => (string) ($item['name'] ?? ''),
            $items
        ));
        $warehouses = $this->prices->warehouseSidsByIds(array_map(
            static fn (array $item): int => (int) ($item['warehouse_id'] ?? 0),
            $items
        ));

        $pairs = [];
        $resolved = [];

        foreach ($items as $index => $item) {
            $levelId = (int) ($item['stock_level_id'] ?? 0);
            $warehouseId = (int) ($item['warehouse_id'] ?? 0);
            $name = (string) ($item['name'] ?? '');

            $nameSid = $levels[$levelId]['name_sid'] ?? $names[$name] ?? '';
            $stockSid = $levels[$levelId]['stock_sid'] ?? $warehouses[$warehouseId] ?? '';

            if ($nameSid === '' || $stockSid === '') {
                continue;
            }

            $resolved[$index] = [$stockSid, $nameSid];
            $pairs[] = [$stockSid, $nameSid];
        }

        $priceMap = $this->prices->pricesForPairsByTypes($pairs, $typeIds);

        $result = [];

        foreach ($items as $index => $item) {
            $price = null;
            $typeId = null;

            if (isset($resolved[$index])) {
                [$stockSid, $nameSid] = $resolved[$index];
                $key = $stockSid . '|' . $nameSid;
                $match = $priceMap[$key] ?? null;

                if ($match !== null) {
                    $price = $match['price'];
                    $typeId = $match['type_id'];
                }
            }

            $item['price'] = $price;
            $item['price_type_id'] = $typeId;
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Представление позиций заявки для клиента: цены и сумма.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{items: array<int, array<string, mixed>>, prices: array<string, mixed>}
     */
    public function decorateItems(array $items): array
    {
        if (!$this->enabled()) {
            $items = array_map(static function (array $item): array {
                $item['price'] = null;
                $item['price_type_id'] = null;
                $item['sum'] = null;

                return $item;
            }, $items);

            return [
                'items' => $items,
                'prices' => ['enabled' => false, 'type' => null, 'total' => null],
            ];
        }

        $typeIds = [];

        foreach ($items as $item) {
            if (($item['price_type_id'] ?? null) !== null) {
                $typeIds[(int) $item['price_type_id']] = true;
            }
        }

        $type = count($typeIds) === 1
            ? $this->prices->findType((int) array_key_first($typeIds))
            : null;
        $total = 0.0;

        $items = array_map(static function (array $item) use (&$total): array {
            $price = $item['price'] ?? null;
            $quantity = (float) ($item['quantity'] ?? 0);
            $sum = $price !== null ? round((float) $price * $quantity, 2) : null;

            if ($sum !== null) {
                $total += $sum;
            }

            $item['price'] = $price !== null ? (float) $price : null;
            $item['sum'] = $sum;

            return $item;
        }, $items);

        return [
            'items' => $items,
            'prices' => [
                'enabled' => true,
                'type' => $type !== null ? ['id' => (int) $type['id'], 'title' => (string) $type['title']] : null,
                'total' => round($total, 2),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{price: ?float, sum: ?float}>
     */
    public function previewRows(array $items): array
    {
        $rows = [];

        foreach ($items as $item) {
            $price = $item['price'] ?? null;
            $quantity = (float) ($item['quantity'] ?? 0);
            $rows[] = [
                'price' => $price !== null ? (float) $price : null,
                'sum' => $price !== null ? round((float) $price * $quantity, 2) : null,
            ];
        }

        return $rows;
    }

    public function total(array $rows): float
    {
        $total = 0.0;

        foreach ($rows as $row) {
            $total += $row['sum'] ?? 0.0;
        }

        return round($total, 2);
    }

    /**
     * @param array<int, string> $nameSids
     * @return array<string, float>
     */
    public function pricesForStockItems(string $stockSid, array $nameSids, int $typeId): array
    {
        return $this->prices->pricesForStockItems($stockSid, $nameSids, $typeId);
    }

    /**
     * @param array<int, string> $nameSids
     * @return array<string, array<int, float>>
     */
    public function pricesForStockItemsAll(string $stockSid, array $nameSids): array
    {
        return $this->prices->pricesForStockItemsAll($stockSid, $nameSids);
    }

    /**
     * @param array<int, string> $nameSids
     * @param int[] $typeIds
     * @return array<string, array<int, float>>
     */
    public function pricesForStockItemsByTypes(string $stockSid, array $nameSids, array $typeIds): array
    {
        return $this->prices->pricesForStockItemsByTypes($stockSid, $nameSids, $typeIds);
    }

    /**
     * Минимальные цены позиций склада среди доступных типов.
     *
     * @param array<int, string> $nameSids
     * @param int[] $typeIds
     * @return array{0: array<string, float>, 1: array<string, int>} цены и типы, давшие минимум
     */
    public function lowestPricesForStockItems(string $stockSid, array $nameSids, array $typeIds): array
    {
        $byTypes = $this->prices->pricesForStockItemsByTypes($stockSid, $nameSids, $typeIds);
        $prices = [];
        $types = [];

        foreach ($byTypes as $nameSid => $typePrices) {
            foreach ($typePrices as $typeId => $price) {
                if (!isset($prices[$nameSid]) || $price < $prices[$nameSid]) {
                    $prices[$nameSid] = (float) $price;
                    $types[$nameSid] = (int) $typeId;
                }
            }
        }

        return [$prices, $types];
    }

    /**
     * @return array<int, float> price_type_id => price
     */
    public function pricesForStockItem(string $stockSid, string $nameSid): array
    {
        return $this->prices->pricesForStockItem($stockSid, $nameSid);
    }

    /**
     * Сохраняет цены позиции склада по типам. Пустое значение удаляет цену.
     *
     * @param array<int|string, mixed> $input price_type_id => price
     */
    public function saveItemPrices(string $stockSid, string $nameSid, array $input): void
    {
        if ($stockSid === '' || $nameSid === '' || !$this->enabled()) {
            return;
        }

        $types = [];

        foreach ($this->prices->types() as $type) {
            $types[(int) $type['id']] = true;
        }

        foreach ($input as $typeId => $value) {
            $typeId = (int) $typeId;

            if (!isset($types[$typeId])) {
                continue;
            }

            if ($value === null || $value === '') {
                $this->prices->deletePrice($stockSid, $nameSid, $typeId);
                continue;
            }

            if (!is_numeric($value)) {
                throw new HttpException(422, 'validation_error', 'Цена должна быть числом');
            }

            $price = (float) $value;

            if ($price < 0 || $price > 1000000000) {
                throw new HttpException(422, 'validation_error', 'Цена должна быть неотрицательной');
            }

            $this->prices->upsertPrice($stockSid, $nameSid, $typeId, round($price, 2));
        }
    }

    public function normalizeImportName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/\b(цена|цены|цен|price|руб|руб\.|₽)\b/ui', ' ', $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);

        return trim($name);
    }

    /**
     * @return array<string, int>
     */
    public function typeIdsByTitle(): array
    {
        $map = [];

        foreach ($this->prices->types() as $type) {
            $key = $this->normalizeImportName((string) $type['title']);

            if ($key !== '') {
                $map[$key] = (int) $type['id'];
            }
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    public function importMappings(): array
    {
        return $this->prices->importMappings();
    }

    /**
     * @param array<string, mixed> $mapping
     */
    public function saveImportMappings(array $mapping): void
    {
        foreach ($mapping as $source => $typeId) {
            $source = $this->normalizeImportName((string) $source);

            if ($source === '') {
                continue;
            }

            $this->prices->saveImportMapping($source, (int) $typeId);
        }
    }

    public function importPrice(string $stockSid, string $nameSid, int $typeId, float $price): void
    {
        if ($stockSid === '' || $nameSid === '' || $typeId <= 0 || !$this->enabled()) {
            return;
        }

        $this->prices->upsertPrice($stockSid, $nameSid, $typeId, round($price, 2));
    }

    private function uniqueCode(string $title): string
    {
        $code = '';

        foreach (mb_str_split(mb_strtolower($title)) as $char) {
            $code .= self::TRANSLIT[$char] ?? $char;
        }

        $code = trim((string) preg_replace('/[^a-z0-9]+/', '-', $code), '-');

        if ($code === '') {
            $code = 'type';
        }

        $code = mb_substr($code, 0, 40);
        $candidate = $code;
        $suffix = 2;

        while ($this->prices->findTypeByCode($candidate) !== null) {
            $candidate = $code . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}
