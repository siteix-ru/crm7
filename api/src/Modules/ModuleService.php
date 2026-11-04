<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Database;
use App\Core\Logger;
use App\Http\HttpException;
use App\Repositories\SettingsRepository;
use App\Stocks\StocksService;
use App\Support\Modules;
use App\Users\UserAdminService;
use Throwable;

/**
 * Настройка модулей: вкл/выкл + доступ ролей (через права в level_capabilities).
 */
final class ModuleService
{
    private const LEVELS = [90, 50, 10, 5, 1];

    private const ROLES = [
        ['level' => 90, 'title' => 'Технический администратор'],
        ['level' => 50, 'title' => 'Администратор'],
        ['level' => 10, 'title' => 'Менеджер'],
        ['level' => 5, 'title' => 'Клиент'],
        ['level' => 1, 'title' => 'Гость'],
    ];

    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly UserAdminService $users = new UserAdminService(),
        private readonly StocksService $stocks = new StocksService()
    ) {
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    public function list(array $capabilities): array
    {
        $this->requireRolesManage($capabilities);

        return $this->payload();
    }

    /**
     * @param  array<string, mixed>  $actor
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $actor, array $capabilities, array $input): array
    {
        $this->requireRolesManage($capabilities);

        $requested = $input['modules'] ?? null;

        if (!is_array($requested)) {
            throw new HttpException(422, 'validation_error', 'Некорректный список модулей');
        }

        $wasWarehousesEnabled = $this->settings->warehousesEnabled();
        $settings = [];
        $access = [];

        foreach (Modules::all() as $module) {
            $code = (string) $module['code'];
            $row = $requested[$code] ?? null;

            if (!is_array($row)) {
                throw new HttpException(422, 'validation_error', 'Некорректные данные модуля: ' . $code);
            }

            $settings[(string) $module['setting']] = ($row['enabled'] ?? true) ? '1' : '0';

            $legacyLevels = (array) ($row['levels'] ?? []);
            $groupRows = is_array($row['groups'] ?? null) ? $row['groups'] : null;

            foreach (Modules::accessGroups($module) as $group) {
                $groupCode = (string) $group['code'];
                $raw = $groupRows !== null ? ($groupRows[$groupCode] ?? []) : $legacyLevels;
                $levels = array_map('intval', (array) $raw);
                $access[$code . ':' . $groupCode] = array_values(array_unique(array_filter(
                    $levels,
                    static fn (int $level): bool => in_array($level, self::LEVELS, true)
                )));
            }
        }

        $this->settings->many($settings);

        $warehousesRow = $requested['warehouses'] ?? null;
        $warehousesEnabled = is_array($warehousesRow)
            ? (bool) ($warehousesRow['enabled'] ?? true)
            : $wasWarehousesEnabled;

        if ($wasWarehousesEnabled && !$warehousesEnabled) {
            try {
                $this->stocks->collapseWarehouses($actor);
            } catch (Throwable $exception) {
                $this->settings->many(['module.warehouses' => '1']);
                Logger::error('stocks.collapse failed', ['error' => $exception->getMessage()]);

                throw new HttpException(500, 'collapse_failed', 'Не удалось объединить склады. Подробности — в журнале.');
            }
        }

        $this->applyAccess($actor, $capabilities, $access);

        return $this->payload();
    }

    /**
     * @param  array<string, mixed>  $actor
     * @param  array<int, string>  $capabilities
     * @param  array<string, array<int, int>>  $access
     */
    private function applyAccess(array $actor, array $capabilities, array $access): void
    {
        $catalog = [];
        $ownedBy = [];

        foreach (Modules::all() as $module) {
            $moduleCode = (string) $module['code'];

            foreach (Modules::accessGroups($module) as $group) {
                $key = $moduleCode . ':' . (string) $group['code'];
                $entry = [
                    'key' => $key,
                    'access' => array_values((array) $group['access']),
                    'grant' => array_values((array) $group['grant']),
                    'capabilities' => array_values((array) $group['capabilities']),
                ];
                $catalog[] = $entry;

                foreach ($entry['capabilities'] as $cap) {
                    $ownedBy[$cap][] = $key;
                }
            }
        }

        $current = $this->levelCapabilities();

        foreach (self::LEVELS as $level) {
            $before = $current[$level] ?? [];
            $add = [];
            $remove = [];

            foreach ($catalog as $group) {
                $was = count(array_intersect($group['access'], $before)) > 0;
                $want = in_array($level, $access[$group['key']] ?? [], true);

                if ($was === $want) {
                    continue;
                }

                if ($want) {
                    foreach ($group['grant'] as $cap) {
                        $add[(string) $cap] = true;
                    }
                } else {
                    foreach ($group['capabilities'] as $cap) {
                        $remove[(string) $cap] = true;
                    }
                }
            }

            if ($add === [] && $remove === []) {
                continue;
            }

            foreach (array_keys($remove) as $cap) {
                foreach ($ownedBy[$cap] ?? [] as $key) {
                    if (in_array($level, $access[$key] ?? [], true)) {
                        unset($remove[$cap]);
                        break;
                    }
                }
            }

            $after = array_values(array_unique(array_merge(
                array_values(array_diff($before, array_keys($remove))),
                array_keys($add)
            )));
            sort($after);

            $sortedBefore = $before;
            sort($sortedBefore);

            if ($after === $sortedBefore) {
                continue;
            }

            $this->users->saveRole($actor, $capabilities, $level, ['capabilities' => $after]);
            $current[$level] = $after;
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function levelCapabilities(): array
    {
        $map = [];

        foreach (Database::pdo()->query('SELECT level, capability_code FROM level_capabilities') as $row) {
            $map[(int) $row['level']][] = (string) $row['capability_code'];
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $current = $this->levelCapabilities();
        $items = [];

        foreach (Modules::all() as $module) {
            $levels = [];
            $groups = [];

            foreach (Modules::accessGroups($module) as $group) {
                $access = array_values((array) $group['access']);
                $groupLevels = [];

                foreach (self::LEVELS as $level) {
                    if (count(array_intersect($access, $current[$level] ?? [])) > 0) {
                        $groupLevels[] = $level;
                    }
                }

                $levels = array_values(array_unique(array_merge($levels, $groupLevels)));
                $groups[] = [
                    'code' => (string) $group['code'],
                    'title' => (string) $group['title'],
                    'description' => (string) ($group['description'] ?? ''),
                    'levels' => $groupLevels,
                    'capabilities' => array_values((array) $group['capabilities']),
                ];
            }

            sort($levels);

            $items[] = [
                'code' => (string) $module['code'],
                'title' => (string) $module['title'],
                'description' => (string) $module['description'],
                'enabled' => $this->settings->moduleEnabled((string) $module['setting']),
                'levels' => $levels,
                'capabilities' => array_values((array) $module['capabilities']),
                'groups' => $groups,
            ];
        }

        return ['modules' => $items, 'roles' => self::ROLES];
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function requireRolesManage(array $capabilities): void
    {
        if (!in_array('roles.manage', $capabilities, true)) {
            throw new HttpException(
                403,
                'forbidden',
                'Настраивать модули может только технический администратор'
            );
        }
    }
}
