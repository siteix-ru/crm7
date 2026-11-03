<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Реестр модулей — единый источник для админки, серверных гейтов и меню.
 *
 * Модуль описывает:
 *  - setting      — ключ в settings (module.<code>), вкл/выкл;
 *  - capabilities — все права, которыми «владеет» модуль;
 *  - access       — права, наличие любого из которых означает «роль имеет доступ»;
 *  - grant        — права, которые выдаются роли при включении доступа;
 *  - guards       — регулярные выражения путей API, блокируемые при выключенном модуле.
 */
final class Modules
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'requests',
                'title' => 'Заявки',
                'description' => 'Создание и ведение заявок, комментарии, смена статуса',
                'setting' => 'module.requests',
                'capabilities' => ['requests.view.all', 'requests.view.own', 'requests.create', 'requests.assign', 'requests.transition', 'requests.comment'],
                'access' => ['requests.view.all', 'requests.view.own'],
                'grant' => ['requests.view.own', 'requests.create', 'requests.transition', 'requests.comment'],
                'groups' => [
                    [
                        'code' => 'view_all',
                        'title' => 'Просмотр всех заявок',
                        'description' => 'Все заявки компании, включая чужие',
                        'capabilities' => ['requests.view.all', 'requests.view.own'],
                        'access' => ['requests.view.all'],
                        'grant' => ['requests.view.all', 'requests.view.own'],
                    ],
                    [
                        'code' => 'view_own',
                        'title' => 'Просмотр своих заявок',
                        'description' => 'Только заявки, назначенные на сотрудника',
                        'capabilities' => ['requests.view.own'],
                        'access' => ['requests.view.own'],
                        'grant' => ['requests.view.own'],
                    ],
                    [
                        'code' => 'create',
                        'title' => 'Создание заявок',
                        'description' => 'Новые заявки и черновики; создатель видит свои заявки',
                        'capabilities' => ['requests.create', 'requests.view.own'],
                        'access' => ['requests.create'],
                        'grant' => ['requests.create', 'requests.view.own'],
                    ],
                    [
                        'code' => 'edit',
                        'title' => 'Редактирование заявок',
                        'description' => 'Смена статуса, состава и параметров заявки',
                        'capabilities' => ['requests.transition'],
                        'access' => ['requests.transition'],
                        'grant' => ['requests.transition'],
                    ],
                    [
                        'code' => 'comment',
                        'title' => 'Комментирование',
                        'description' => 'Комментарии и внутренняя переписка по заявкам',
                        'capabilities' => ['requests.comment'],
                        'access' => ['requests.comment'],
                        'grant' => ['requests.comment'],
                    ],
                    [
                        'code' => 'assign',
                        'title' => 'Назначение и настройка',
                        'description' => 'Назначение и переназначение ответственных менеджеров',
                        'capabilities' => ['requests.assign'],
                        'access' => ['requests.assign'],
                        'grant' => ['requests.assign'],
                    ],
                ],
                'guards' => ['#^/requests(/|$)#', '#^/request-drafts(/|$)#'],
            ],
            [
                'code' => 'clients',
                'title' => 'Клиенты',
                'description' => 'Список клиентов и управление клиентскими карточками',
                'setting' => 'module.clients',
                'capabilities' => ['clients.view.all', 'clients.view.own', 'clients.edit', 'clients.confirm'],
                'access' => ['clients.view.all', 'clients.view.own'],
                'grant' => ['clients.view.own', 'clients.edit', 'clients.confirm'],
                'groups' => [
                    [
                        'code' => 'view_all',
                        'title' => 'Просмотр всех клиентов',
                        'description' => 'Все клиенты компании и их карточки',
                        'capabilities' => ['clients.view.all', 'clients.view.own'],
                        'access' => ['clients.view.all'],
                        'grant' => ['clients.view.all', 'clients.view.own'],
                    ],
                    [
                        'code' => 'view_own',
                        'title' => 'Просмотр своих клиентов',
                        'description' => 'Только клиенты, закреплённые за сотрудником',
                        'capabilities' => ['clients.view.own'],
                        'access' => ['clients.view.own'],
                        'grant' => ['clients.view.own'],
                    ],
                    [
                        'code' => 'edit',
                        'title' => 'Редактирование клиентов',
                        'description' => 'Контакты, реквизиты и данные клиентской карточки',
                        'capabilities' => ['clients.edit', 'clients.view.own'],
                        'access' => ['clients.edit'],
                        'grant' => ['clients.edit', 'clients.view.own'],
                    ],
                    [
                        'code' => 'confirm',
                        'title' => 'Подтверждение регистрации',
                        'description' => 'Активация, отклонение и блокировка клиентов',
                        'capabilities' => ['clients.confirm', 'clients.view.own'],
                        'access' => ['clients.confirm'],
                        'grant' => ['clients.confirm', 'clients.view.own'],
                    ],
                ],
                'guards' => [
                    '#^/clients/pending$#',
                    '#^/clients/\d+/(activate|reject|block|unblock|login|reset-password|reset-password-email|revoke-sessions)$#',
                ],
            ],
            [
                'code' => 'chat',
                'title' => 'Чат',
                'description' => 'Переписка менеджеров и клиентов',
                'setting' => 'module.chat',
                'capabilities' => ['chat.use', 'chat.moderate'],
                'access' => ['chat.use'],
                'grant' => ['chat.use'],
                'groups' => [
                    [
                        'code' => 'use',
                        'title' => 'Использование чата',
                        'description' => 'Переписка с клиентами и сотрудниками',
                        'capabilities' => ['chat.use'],
                        'access' => ['chat.use'],
                        'grant' => ['chat.use'],
                    ],
                    [
                        'code' => 'moderate',
                        'title' => 'Модерация чата',
                        'description' => 'Контроль переписки и управление диалогами',
                        'capabilities' => ['chat.moderate', 'chat.use'],
                        'access' => ['chat.moderate'],
                        'grant' => ['chat.moderate', 'chat.use'],
                    ],
                ],
                'guards' => ['#^/chat(/|$)#'],
            ],
            [
                'code' => 'notifications',
                'title' => 'Уведомления',
                'description' => 'Уведомления в системе и настройки подписок',
                'setting' => 'module.notifications',
                'capabilities' => ['notifications.use'],
                'access' => ['notifications.use'],
                'grant' => ['notifications.use'],
                'guards' => ['#^/notifications(/|$)#', '#^/notification-settings(/|$)#'],
            ],
            [
                'code' => 'staff',
                'title' => 'Сотрудники',
                'description' => 'Сотрудники, роли и управление учётными записями',
                'setting' => 'module.staff',
                'capabilities' => ['users.manage', 'roles.manage', 'prices.assign'],
                'access' => ['users.manage', 'roles.manage', 'prices.assign'],
                'grant' => ['users.manage', 'roles.manage', 'prices.assign'],
                'groups' => [
                    [
                        'code' => 'users',
                        'title' => 'Сотрудники и учётные записи',
                        'description' => 'Создание, блокировка, доступы и данные сотрудников',
                        'capabilities' => ['users.manage'],
                        'access' => ['users.manage'],
                        'grant' => ['users.manage'],
                    ],
                    [
                        'code' => 'roles',
                        'title' => 'Роли и права',
                        'description' => 'Настройка ролей и наборов разрешений',
                        'capabilities' => ['roles.manage'],
                        'access' => ['roles.manage'],
                        'grant' => ['roles.manage'],
                    ],
                    [
                        'code' => 'prices',
                        'title' => 'Типы цен сотрудников',
                        'description' => 'Назначение доступных типов цен в карточке сотрудника',
                        'capabilities' => ['prices.assign'],
                        'access' => ['prices.assign'],
                        'grant' => ['prices.assign'],
                    ],
                ],
                'guards' => [
                    '#^/users$#',
                    '#^/users/facets$#',
                    '#^/users/\d+$#',
                    '#^/users/\d+/(history|clients|conversion|convert|block|unblock|login|send-credentials|reset-password|revoke-sessions|price-types)$#',
                    '#^/roles(/|$)#',
                ],
            ],
            [
                'code' => 'registration',
                'title' => 'Регистрация клиентов',
                'description' => 'Самостоятельная регистрация клиентов',
                'setting' => 'module.registration',
                'capabilities' => ['registration.use'],
                'access' => ['registration.use'],
                'grant' => ['registration.use'],
                'groups' => [
                    [
                        'code' => 'self',
                        'title' => 'Самостоятельная регистрация',
                        'description' => 'Гостям доступна форма регистрации клиента',
                        'capabilities' => ['registration.use'],
                        'access' => ['registration.use'],
                        'grant' => ['registration.use'],
                    ],
                ],
                'guards' => ['#^/auth/register$#'],
            ],
            [
                'code' => 'stock_edit',
                'title' => 'Управление номенклатурой',
                'description' => 'Редактирование, импорт и справочники номенклатуры',
                'setting' => 'module.stock_edit',
                'capabilities' => ['stocks.edit', 'stocks.import', 'stocks.manage', 'stocks.deactivate'],
                'access' => ['stocks.edit', 'stocks.import', 'stocks.manage'],
                'grant' => ['stocks.edit', 'stocks.import', 'stocks.manage', 'stocks.deactivate'],
                'groups' => [
                    [
                        'code' => 'edit',
                        'title' => 'Редактирование номенклатуры',
                        'description' => 'Создание и изменение позиций, цены и остатки',
                        'capabilities' => ['stocks.edit'],
                        'access' => ['stocks.edit'],
                        'grant' => ['stocks.edit'],
                    ],
                    [
                        'code' => 'import',
                        'title' => 'Импорт номенклатуры',
                        'description' => 'Загрузка позиций из файлов',
                        'capabilities' => ['stocks.import'],
                        'access' => ['stocks.import'],
                        'grant' => ['stocks.import'],
                    ],
                    [
                        'code' => 'manage',
                        'title' => 'Справочники',
                        'description' => 'Типы, группы и ценовые категории номенклатуры',
                        'capabilities' => ['stocks.manage'],
                        'access' => ['stocks.manage'],
                        'grant' => ['stocks.manage'],
                    ],
                    [
                        'code' => 'deactivate',
                        'title' => 'Снятие с продажи',
                        'description' => 'Деактивация и возврат позиций в продажу',
                        'capabilities' => ['stocks.deactivate'],
                        'access' => ['stocks.deactivate'],
                        'grant' => ['stocks.deactivate'],
                    ],
                ],
                'guards' => [
                    '#^/stocks/import(/|$)#',
                    '#^/stocks/\d+/items$#',
                    '#^/stocks/levels/\d+/photos$#',
                    '#^/stocks/levels/\d+/(activate|deactivate)$#',
                    '#^/admin/item-types(/|$)#',
                    '#^/admin/price-types(/|$)#',
                    '#^/admin/item-groups(/|$)#',
                ],
            ],
            [
                'code' => 'mail',
                'title' => 'Почта',
                'description' => 'SMTP, отправка писем и уведомлений',
                'setting' => 'module.mail',
                'capabilities' => ['mail.manage', 'mail.send', 'mail.recovery'],
                'access' => ['mail.manage', 'mail.send', 'mail.recovery'],
                'grant' => ['mail.manage', 'mail.send', 'mail.recovery'],
                'groups' => [
                    [
                        'code' => 'smtp',
                        'title' => 'Настройка SMTP',
                        'description' => 'Сервер, порт, учётные данные и проверка подключения',
                        'capabilities' => ['mail.manage'],
                        'access' => ['mail.manage'],
                        'grant' => ['mail.manage'],
                    ],
                    [
                        'code' => 'send',
                        'title' => 'Отправка писем и уведомлений',
                        'description' => 'Шаблоны писем, очередь отправки и расписание отчётов',
                        'capabilities' => ['mail.send'],
                        'access' => ['mail.send'],
                        'grant' => ['mail.send'],
                    ],
                    [
                        'code' => 'recovery',
                        'title' => 'Восстановление пароля',
                        'description' => 'Форма восстановления для гостей и отправка доступа из карточек',
                        'capabilities' => ['mail.recovery'],
                        'access' => ['mail.recovery'],
                        'grant' => ['mail.recovery'],
                    ],
                ],
                'guards' => [
                    '#^/admin/settings/email(/|$)#',
                    '#^/admin/templates(/|$)#',
                    '#^/admin/queue(/|$)#',
                    '#^/admin/report-schedules(/|$)#',
                    '#^/auth/password/reset-request$#',
                    '#^/clients/\d+/reset-password-email$#',
                    '#^/users/\d+/send-credentials$#',
                ],
            ],
            [
                'code' => 'manager_assign',
                'title' => 'Назначение менеджера',
                'description' => 'Закрепление и передача клиентов между менеджерами',
                'setting' => 'module.manager_assign',
                'capabilities' => ['clients.assign'],
                'access' => ['clients.assign'],
                'grant' => ['clients.assign'],
                'guards' => ['#^/clients/\d+/(claim|assign|transfer)$#', '#^/client-transfers/#', '#^/requests/\d+/(claim|assign)$#'],
            ],
            [
                'code' => 'substitutions',
                'title' => 'Замещения',
                'description' => 'Замещения менеджеров',
                'setting' => 'module.substitutions',
                'capabilities' => ['clients.assign'],
                'access' => ['clients.assign'],
                'grant' => ['clients.assign'],
                'guards' => ['#^/substitutions(/|$)#'],
            ],
            [
                'code' => 'warehouses',
                'title' => 'Склады',
                'description' => 'Несколько складов, страница и карточка склада',
                'setting' => 'module.warehouses',
                'capabilities' => ['stocks.view', 'stocks.edit', 'stocks.import', 'stocks.manage', 'stocks.deactivate'],
                'access' => ['stocks.edit', 'stocks.import', 'stocks.manage'],
                'grant' => ['stocks.view', 'stocks.edit', 'stocks.import', 'stocks.manage'],
                'groups' => [
                    [
                        'code' => 'view',
                        'title' => 'Просмотр складов',
                        'description' => 'Список складов и остатки',
                        'capabilities' => ['stocks.view'],
                        'access' => ['stocks.view'],
                        'grant' => ['stocks.view'],
                    ],
                    [
                        'code' => 'edit',
                        'title' => 'Редактирование складов',
                        'description' => 'Создание и изменение складов и остатков',
                        'capabilities' => ['stocks.edit', 'stocks.view'],
                        'access' => ['stocks.edit'],
                        'grant' => ['stocks.edit', 'stocks.view'],
                    ],
                    [
                        'code' => 'import',
                        'title' => 'Импорт остатков',
                        'description' => 'Загрузка остатков из файлов',
                        'capabilities' => ['stocks.import', 'stocks.view'],
                        'access' => ['stocks.import'],
                        'grant' => ['stocks.import', 'stocks.view'],
                    ],
                    [
                        'code' => 'manage',
                        'title' => 'Справочники складов',
                        'description' => 'Типы, группы и ценовые категории',
                        'capabilities' => ['stocks.manage', 'stocks.view'],
                        'access' => ['stocks.manage'],
                        'grant' => ['stocks.manage', 'stocks.view'],
                    ],
                    [
                        'code' => 'deactivate',
                        'title' => 'Снятие с продажи',
                        'description' => 'Деактивация и возврат позиций в продажу',
                        'capabilities' => ['stocks.deactivate', 'stocks.view'],
                        'access' => ['stocks.deactivate'],
                        'grant' => ['stocks.deactivate', 'stocks.view'],
                    ],
                ],
                'guards' => ['#^/stocks/warehouses(/|$)#', '#^/stocks/\d+$#', '#^/stocks/\d+/items$#'],
            ],
            [
                'code' => 'reports',
                'title' => 'Отчёты',
                'description' => 'Страница отчётов, выгрузки и расписание',
                'setting' => 'module.reports',
                'capabilities' => ['reports.view.all', 'reports.view.own', 'reports.export'],
                'access' => ['reports.view.all', 'reports.view.own'],
                'grant' => ['reports.view.own'],
                'groups' => [
                    [
                        'code' => 'view_all',
                        'title' => 'Просмотр всех отчётов',
                        'description' => 'Отчёты по всем сотрудникам и заявкам',
                        'capabilities' => ['reports.view.all', 'reports.view.own'],
                        'access' => ['reports.view.all'],
                        'grant' => ['reports.view.all', 'reports.view.own'],
                    ],
                    [
                        'code' => 'view_own',
                        'title' => 'Просмотр своих отчётов',
                        'description' => 'Отчёты только по своим заявкам',
                        'capabilities' => ['reports.view.own'],
                        'access' => ['reports.view.own'],
                        'grant' => ['reports.view.own'],
                    ],
                    [
                        'code' => 'export',
                        'title' => 'Выгрузка отчётов',
                        'description' => 'Экспорт отчётов в файлы',
                        'capabilities' => ['reports.export', 'reports.view.own'],
                        'access' => ['reports.export'],
                        'grant' => ['reports.export', 'reports.view.own'],
                    ],
                ],
                'guards' => ['#^/reports(/|$)#', '#^/admin/report-schedules(/|$)#'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function byCode(): array
    {
        $map = [];

        foreach (self::all() as $module) {
            $map[(string) $module['code']] = $module;
        }

        return $map;
    }

    /**
     * Группы доступа модуля: у обычного модуля одна группа с настройками модуля.
     *
     * @param  array<string, mixed>  $module
     * @return array<int, array<string, mixed>>
     */
    public static function accessGroups(array $module): array
    {
        $groups = $module['groups'] ?? null;

        if (is_array($groups) && $groups !== []) {
            return array_values(array_filter($groups, static fn (mixed $group): bool => is_array($group)));
        }

        return [[
            'code' => 'default',
            'title' => 'Доступные роли',
            'description' => '',
            'capabilities' => array_values((array) ($module['capabilities'] ?? [])),
            'access' => array_values((array) ($module['access'] ?? [])),
            'grant' => array_values((array) ($module['grant'] ?? [])),
        ]];
    }

    /**
     * Все пути API, которые блокируются выключенными модулями.
     *
     * @param  callable(string): bool  $enabled  модуль включён?
     * @return array<int, string>
     */
    public static function guards(callable $enabled): array
    {
        $guards = [];

        foreach (self::all() as $module) {
            if ($enabled((string) $module['setting'])) {
                continue;
            }

            foreach ((array) $module['guards'] as $guard) {
                $guards[] = (string) $guard;
            }
        }

        return $guards;
    }
}
