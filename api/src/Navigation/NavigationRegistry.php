<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Support\Modules;
use LogicException;

/**
 * Phase 0 Navigation Contract (docs/navigation-2.0.md, §18).
 *
 * Registry is a projection of the existing module access policy. It never
 * creates capabilities and never replaces backend authorization.
 */
final class NavigationRegistry
{
    /**
     * Raw navigation nodes. Capabilities are derived, never declared here for
     * module-backed nodes. System nodes use existing policy codes that are
     * validated against the `capabilities` table by contract tests.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definition(): array
    {
        return [
            [
                'id' => 'requests', 'label' => 'Заявки', 'iconId' => 'requests',
                'group' => 'work', 'route' => '#/requests', 'module' => 'requests',
                'order' => 1, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => true, 'drawer' => true],
            ],
            [
                'id' => 'clients', 'label' => 'Клиенты', 'iconId' => 'clients',
                'group' => 'work', 'route' => '#/clients', 'module' => 'clients',
                'order' => 2, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => true, 'drawer' => true],
            ],
            [
                'id' => 'chat', 'label' => 'Чат', 'iconId' => 'chat',
                'group' => 'work', 'route' => '#/chat', 'module' => 'chat',
                'order' => 3, 'type' => 'section',
                'badge' => ['source' => 'chat'],
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => false, 'drawer' => true],
            ],
            [
                'id' => 'stocks', 'label' => 'Номенклатура', 'iconId' => 'stocks',
                'group' => 'stock', 'route' => '#/stocks', 'module' => 'warehouses',
                'order' => 1, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => true, 'drawer' => true],
            ],
            [
                'id' => 'stock-lots', 'label' => 'Партии', 'iconId' => 'tags',
                'group' => 'stock', 'route' => '#/stock-lots', 'module' => 'stock_lots',
                'order' => 2, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => false, 'drawer' => true],
            ],
            [
                'id' => 'warehouses', 'label' => 'Управление складами', 'iconId' => 'warehouse',
                'group' => 'stock', 'route' => '#/stocks/warehouses', 'module' => 'warehouses',
                'order' => 3, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => false, 'drawer' => true],
            ],
            [
                'id' => 'stock-types', 'label' => 'Типы номенклатуры', 'iconId' => 'types',
                'group' => 'stock', 'route' => '#/stocks/types', 'module' => 'stock_edit',
                'moduleGroup' => 'edit',
                'order' => 4, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => false, 'drawer' => true],
            ],
            [
                'id' => 'stock-import', 'label' => 'Импорт остатков из 1С', 'iconId' => 'import',
                'group' => 'stock', 'route' => '#/stocks/import', 'module' => 'stock_edit',
                'moduleGroup' => 'import',
                'order' => 5, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => false, 'drawer' => true],
            ],
            [
                'id' => 'reports', 'label' => 'Отчёты', 'iconId' => 'reports',
                'group' => 'analytics', 'route' => '#/reports', 'module' => 'reports',
                'moduleGroup' => 'view_all',
                'order' => 1, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => true, 'drawer' => true],
            ],
            [
                'id' => 'my-reports', 'label' => 'Мои отчёты', 'iconId' => 'my-reports',
                'group' => 'analytics', 'route' => '#/my-reports', 'module' => 'reports',
                'moduleGroup' => 'view_own',
                'order' => 2, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => true, 'drawer' => true],
            ],
            [
                'id' => 'staff', 'label' => 'Сотрудники', 'iconId' => 'staff',
                'group' => 'org', 'route' => '#/staff', 'module' => 'staff',
                'moduleGroup' => 'users',
                'order' => 1, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => false, 'drawer' => true],
            ],
            [
                'id' => 'substitutions', 'label' => 'Замещения', 'iconId' => 'substitutions',
                'group' => 'org', 'route' => '#/substitutions', 'module' => 'substitutions',
                'order' => 2, 'type' => 'subsection',
                'presentation' => ['rail' => false, 'topbar' => false, 'bottom' => false, 'drawer' => true],
            ],
            [
                'id' => 'settings', 'label' => 'Настройки', 'iconId' => 'settings',
                'group' => 'system', 'route' => '#/settings', 'module' => null,
                'policyCapabilities' => ['settings.manage', 'mail.manage', 'mail.send', 'messengers.manage'],
                'order' => 1, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => false, 'drawer' => true],
            ],
            [
                'id' => 'admin-audit', 'label' => 'Журнал аудита', 'iconId' => 'audit',
                'group' => 'system', 'route' => '#/admin/audit', 'module' => null,
                'policyCapabilities' => ['audit.view'],
                'order' => 2, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => false, 'drawer' => true],
            ],
            [
                'id' => 'about', 'label' => 'О программе', 'iconId' => 'info',
                'group' => 'system', 'route' => '#/about', 'module' => null,
                'policyCapabilities' => [],
                'order' => 3, 'type' => 'section',
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => false, 'drawer' => true],
            ],
            [
                'id' => 'notifications', 'label' => 'Уведомления', 'iconId' => 'bell',
                'group' => 'system', 'route' => '#/notifications', 'module' => 'notifications',
                'order' => 4, 'type' => 'section',
                'badge' => ['source' => 'notifications'],
                'presentation' => ['rail' => false, 'topbar' => true, 'bottom' => false, 'drawer' => true],
            ],
            [
                'id' => 'cart', 'label' => 'Корзина', 'iconId' => 'cart',
                'group' => 'work', 'route' => '#/cart', 'module' => 'requests',
                'order' => 4, 'type' => 'section',
                'badge' => ['source' => 'cart'],
                'presentation' => ['rail' => true, 'topbar' => false, 'bottom' => false, 'drawer' => true],
            ],
        ];
    }

    /**
     * Capabilities for a node, derived from Modules access policy only.
     *
     * @param  array<string, mixed>  $node
     * @return array<int, string>
     */
    public static function capabilitiesFor(array $node): array
    {
        $moduleCode = (string) ($node['module'] ?? '');

        if ($moduleCode !== '') {
            $module = Modules::byCode()[$moduleCode] ?? null;
            if ($module === null) {
                throw new LogicException('Navigation node references unknown module: ' . $moduleCode);
            }

            $groupCode = (string) ($node['moduleGroup'] ?? '');
            if ($groupCode !== '') {
                foreach (Modules::accessGroups($module) as $group) {
                    if ((string) ($group['code'] ?? '') === $groupCode) {
                        return array_values(array_map('strval', (array) ($group['access'] ?? [])));
                    }
                }

                throw new LogicException('Unknown access group for module ' . $moduleCode . ': ' . $groupCode);
            }

            return array_values(array_map('strval', (array) ($module['access'] ?? [])));
        }

        return array_values(array_map('strval', (array) ($node['policyCapabilities'] ?? [])));
    }

    /**
     * Existing policy codes used by non-module system nodes.
     *
     * @return array<int, string>
     */
    public static function policyCodes(): array
    {
        $codes = [];

        foreach (self::definition() as $node) {
            if (($node['module'] ?? null) === null) {
                $codes = array_merge($codes, (array) ($node['policyCapabilities'] ?? []));
            }
        }

        return array_values(array_unique(array_map('strval', $codes)));
    }

    /**
     * Build visible navigation for the current user.
     *
     * @param  array<int, string>  $capabilities
     * @param  callable(string): bool  $moduleEnabled
     * @return array<int, array<string, mixed>>
     */
    public function visible(array $capabilities, callable $moduleEnabled): array
    {
        $visible = [];

        foreach (self::definition() as $node) {
            $nodeCapabilities = self::capabilitiesFor($node);

            if ($nodeCapabilities !== [] && array_intersect($nodeCapabilities, $capabilities) === []) {
                continue;
            }

            $moduleCode = (string) ($node['module'] ?? '');
            if ($moduleCode !== '' && !$moduleEnabled($moduleCode)) {
                continue;
            }

            $item = [
                'id' => (string) $node['id'],
                'label' => (string) $node['label'],
                'iconId' => (string) $node['iconId'],
                'group' => (string) $node['group'],
                'route' => (string) $node['route'],
                'capabilities' => $nodeCapabilities,
                'presentation' => (array) $node['presentation'],
                'order' => (int) $node['order'],
                'type' => (string) $node['type'],
            ];

            if (isset($node['badge']) && is_array($node['badge'])) {
                $item['badge'] = $node['badge'];
            }

            if (isset($node['parent'])) {
                $item['parent'] = (string) $node['parent'];
            }

            $visible[] = $item;
        }

        usort($visible, static function (array $left, array $right): int {
            return [(string) $left['group'], (int) $left['order']]
                <=> [(string) $right['group'], (int) $right['order']];
        });

        return $visible;
    }
}
