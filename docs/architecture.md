# Архитектура CRM7

Карта устройства системы: слои, поток запроса, данные, окружения. Актуальна на момент
последних изменений; при смене entry point или инварианта — обновляйте вместе с кодом.

## Обзор

- **Фронтенд** — SPA на Svelte 5 (runes), hash-роутинг, общается с REST API v2.
- **Бэкенд** — собственный REST API без фреймворка: Router → Controller → Service → Repository → PDO.
- **БД** — MySQL 8 / MariaDB 10.5+ (utf8mb4).
- Код поставляется как образы (GHCR), данные живут в томах (см. «Окружения»).

## Поток запроса (бэкенд)

Точка входа — `api/public/index.php` (префикс API — `API_BASE_PATH`, по умолчанию `/api/v2`):

1. Autoload `App\` (`api/src/autoload.php`), загрузка конфига (`Config::load(config/.env)`).
2. `Router` сопоставляет HTTP-метод и путь с парой `[Controller, action]`.
3. `ApiController::context()` аутентифицирует по Bearer-токену и собирает capabilities пользователя.
4. Controller валидирует вход и вызывает Service.
5. Service — бизнес-логика, обращается к Repository.
6. Repository — SQL через `Database::pdo()`.
7. Ответ — `Response::ok($data)` (JSON `{ "ok": true, "data": ... }`); ошибки — `HttpException` → JSON с кодом.

### Слои

| Слой | Назначение |
| --- | --- |
| `Core/` | `Config`, `Database`, `Router`, `RateLimiter`, `Logger`, `DatabaseSettings` |
| `Http/` | `Request`, `Response`, `Router`, `HttpException`, `FileResponse`, `DownloadResponse` |
| `Controllers/` | Тонкие контроллеры по домену (Auth, Request, Stocks, Chat, Client, Report, Admin…) |
| `<Домен>/*Service.php` | Бизнес-логика (Requests, Stocks, Prices, Chat, Clients, Users, Reports…) |
| `Repositories/` | Доступ к БД (по одной таблице/агрегату) |
| `Support/` | `Storage` (файлы), `Crypto` (AES-256-GCM), `SmtpMailer`, `Validator` |

Роуты и контроллеры сгруппированы по префиксам: `auth`, `profile`, `requests`,
`request-drafts`, `clients`, `chat`, `stocks`, `reports`, `substitutions`,
`notifications`, `admin`, `settings`, `legal`, `users`, `preferences`.

## Аутентификация и права

- Логин (`POST /auth/login`) → токен в БД (`TokenRepository`); далее заголовок `Authorization: Bearer <token>`.
- Уровни: **90** сисадмин, **50** администратор, **10** менеджер, **5** клиент, **1** гость.
- Матрица прав — таблица `level_capabilities` (код → уровень). На фронте проверка `auth.can('code')`.
- Защита входа от перебора — `RateLimiter` + таблица попыток по IP (`login_attempts`).
- Пароли — `password_hash(..., PASSWORD_DEFAULT)`; сброс админом генерирует пароль `bin2hex(random_bytes(5)).'Aa1'`.

## Фронтенд

- `main.ts` → `App.svelte`: выбирает представление по `router.current.path`.
- Роуты — `frontend/src/App.svelte` (в т.ч. параметрические: `/requests/:id`, `/stocks/items/:id/edit`).
- `lib/router.svelte.ts` — hash-роутер (`$state`); `lib/match-route.ts` — разбор параметров пути.
- `lib/api/*.ts` — типизированные обёртки над API (`apiRequest`, Bearer, ошибки `ApiError`).
- `lib/stores/*.svelte.ts` — реактивные сторы: `auth`, `cart`, `app-settings`, `avatar`, `filter-prefs`.
- `lib/components/` — UI: `ui/` (Button, Modal, Input, Icon, SearchInput…), `layout/` (AppShell),
  `requests/`, `stocks/` (PhotoGallery); `lib/actions/tooltip.ts` — тултипы.
- `lib/config.ts` — адрес API (`window.CRM_CONFIG` / `VITE_API_V2_BASE`, по умолчанию `./api/v2`) и интервалы polling.

## Данные (ключевые таблицы)

- **Пользователи/права:** `users` (`LOGIN`, `PASSWORD`, `LEVEL`, `ACTIVE`, `SID`), `level_capabilities`, `tokens`, `user_history`, `login_attempts`.
- **Заявки:** `requests`, `request_items`, `request_statuses`, `request_assignments`, `request_history`, `request_activities`, `request_comments`, `request_drafts`.
- **Склад:** `stocks` (склады: `NAME`, `NAME_1C`, `ADDRESS`, `CONTACT_NAME`, `PHONE`, `EMAIL`, `NOTE`, `TYPE`, `STOCK_NUM`, `SORT`, `IS_DEFAULT`, `ALLOW_ORDERS`, `IN_REPORTS`, `RESPONSIBLE_SID`, `ACTIVE`, `LEVEL` — роли с доступом), `stock_levels` (остатки), `nomenclature` (`SID`, `NAME`, `NAME_1C`, `ARTICLE`, `TYPE`, `ACTIVE`, `UNIT`, `DESCRIPTION`, `group_id`), `nomenclature_composition` (состав набора: `SET_SID`, `ITEM_SID`, `QUANTITY`, `SORT`), `nomenclature_photos` (`storage_path`/`card_path`/`preview_path`, `mime`, `size`, `width`, `height`), `nomenclature_prices` (`name_sid`, `stock_sid`, `price_type_id`, `price`), `price_types`, `price_import_mappings`, `item_groups`, `user_level_stock` (персональный доступ к складу).
- **Клиенты/чат/прочее:** `clients`, `client_managers`, `chat_threads`/`chat_messages`, `notifications`, `settings`, `audit`, `schema_migrations`.

### Инварианты

- Остаток и цена привязаны к паре `(STOCK_SID, NAME_SID)`; цены — по типам и **по каждому складу отдельно**.
- **Позиция единая для всех складов:** `nomenclature` — глобальная (одно имя на все склады), `stock_levels` — остаток по каждому складу. Переименование правит `nomenclature` и переносит `NAME`/`UNIT` во все `stock_levels`; карточка позиции (`#/stocks/items/:id/edit`, `GET/PATCH /stocks/levels/{id}`) содержит вкладку «Количество» — список **всех доступных пользователю складов**: остаток задаётся по `warehouse_sid`, при отсутствии уровня он создаётся (`POST`-создание — без выбора склада, уровень на первом доступном). Переименование в имя другой позиции отклоняется (`422 duplicate_item`). Список номенклатуры и цены остаются в разрезе складов.
- Импорт остатков **не удаляет и не перезаписывает** номенклатуру: отсутствующие в файле позиции обнуляются (не удаляются); новые склады добавляются; новая позиция создаётся на всех складах (0 там, где количества нет в файле); настройки позиции (описание, группа, единица, фото) сохраняются.
- **Модуль «Склады»** (`module.warehouses`) можно отключить: система переходит в режим одного склада (запись `stocks` с фиксированным `SID=single-warehouse`, имя из `stocks.single_name`). Отключение **необратимо**: остатки схлопываются по номенклатуре (количество — сумма), цены по типу — максимум, остальные склады удаляются. Импорт в этом режиме агрегирует строки по названию (количество — сумма, цена — максимум) и склады не заводит. Доступ к складу — роли (`stocks.LEVEL`) или персонально (`user_level_stock`).
- **Поля склада:** `TYPE` (`main|transit|returns|reserve|defect`), `IS_DEFAULT` (единственный «основной», в списке — первым), `ALLOW_ORDERS` (доступен для заявок), `IN_REPORTS` (учитывать в отчётах; отчёт по складам исключает `IN_REPORTS=0`), `RESPONSIBLE_SID` (ответственный, `users.SID`), `STOCK_NUM`/`SORT` (номер и порядок), контакты (`CONTACT_NAME`, `PHONE`, `EMAIL`, `NOTE`). `NAME` и `NAME_1C` уникальны; пустое `NAME_1C` берётся из `NAME` (используется при сопоставлении импорта).
- **Модули** (`settings`): `module.requests` (включает корзину), `module.clients`, `module.chat`, `module.notifications`, `module.staff`, `module.registration`, `module.stock_edit`, `module.mail`, `module.manager_assign`, `module.substitutions`, `module.warehouses`, `module.reports`. Отключённый модуль скрывается в меню/интерфейсе и блокируется на сервере (`403 module_disabled`): заявки, клиенты, чат, уведомления, сотрудники/роли, регистрация, редактирование номенклатуры, почта, замещения, назначение менеджера, склады (`/stocks/warehouses*`), отчёты (`/reports/*`, `/admin/report-schedules*`). Управление — в `#/settings` (вкладка «Модули»); флаги отдаются в `/settings/app`, публичные (`registration_enabled`, `mail_available`) — в `/settings/public`. В карточке «Заявки» доступ разбит на группы: просмотр всех/своих, создание, редактирование, комментирование, назначение менеджера. Аналогичные группы есть у клиентов, чата, сотрудников, регистрации, номенклатуры, складов и отчётов; модули с одной возможностью сохраняют один выбор ролей. Отдельная вкладка «Роли» убрана: системные права `settings.manage` и `audit.view` закреплены за техническим администратором. Доступ к почте настраивается группами в карточке модуля «Почта»: `mail.manage` — SMTP, `mail.send` — шаблоны/очередь/расписание, `mail.recovery` — восстановление пароля (гостям — форма входа, сотрудникам — отправка доступа из карточек).
- **Реестр модулей** — `App\Support\Modules` (код, название, ключ настройки, права модуля, права доступа, гарды API) — единый источник для вкладки «Модули» (`#/admin`, `GET/POST /admin/modules`), серверных гейтов (`index.php`) и доступа ролей. Доступ ролей к модулю выражается правами: «роль имеет доступ» = есть любое из `access`-прав модуля; включение роли выдаёт `grant`-набор, выключение снимает права модуля (кроме тех, что даёт другой включённый модуль). Тонкая матрица — вкладка «Роли» (`level_capabilities`).
- **Типы номенклатуры:** `nomenclature.TYPE` — `product|service|set` (реестр `App\Support\ItemTypes`). Разрешённые типы — настройка `stocks.item_types` (CSV кодов; отсутствие = все); управление — вкладка «Типы позиций» админки (`GET/POST /admin/item-types`, `settings.manage`). В карточке выбираются только разрешённые типы (смена на отключённый — `422 type_disabled`). Услуги учитываются по складам так же, как товары. Набор (`set`) имеет состав (`nomenclature_composition`) из любых позиций с количеством; правка — вкладка «Состав» (`PATCH /stocks/levels/{id}` с `composition`); при смене типа на не-набор состав очищается.
- **Активность позиции:** `nomenclature.ACTIVE` (`Y`/`N`). Деактивировать/активировать, видеть неактивные и фильтровать их — право `stocks.deactivate` (по умолчанию Сисадмин/Администратор/Менеджер). Удалять — `stocks.manage`. Удалить нельзя, если позиция входит в состав набора (`422 in_use_sets`) или использовалась в заявках (`request_items` по `stock_level_id`/имени — `422 in_use_requests`); в последнем случае — только деактивация. Деактивировать нельзя, если позиция входит в состав набора. Деактивированная позиция: не добавляется в наборы (`422 item_inactive`), не видна без права (список/остатки/экспорт фильтруются), недоступна для заявок (`422 item_inactive`), но **обновляется импортом остатков** и остаётся в отчётах. Список: параметр `active` (`active|inactive`; без права принудительно `active`).
- Фото хранятся в `storage/` (том) в трёх вариантах: `storage_path` (максимум), `card_path` (карточка), `preview_path` (превью); в БД — метаданные (`mime`, `size`, `width`, `height`). Размеры вариантов — в настройках (`stocks.photo_size_preview/card/max`); при загрузке изображение уменьшается без увеличения (GD).
- Миграции инкрементальные (`schema_migrations`); применённые файлы не редактируются.

## Окружения и выкладка

- Код — образы в GHCR (`ghcr.io/siteix-ru/crm7-app`, `.../crm7-web`); данные — в томах.
- Окружения: `demo` (демо), `test` (общий тест), `k` (dev клиента «Камень»).
- Структура окружения: `/srv/projects/crm7-<env>/` — `docker-compose.yml`, `.env` (`CRM7_VERSION`, `DB_*`),
  тома `config/`, `storage/`, `var/`, `data/db/`.
- Сборка: `python deploy/build_images.py [--push]` — frontend build → SFTP контекста → `docker build` на сервере.
- Релиз: `python deploy/release_crm7.py --env <env|all> --tag <ver>` — версия в `.env` → pull → `migrate.php` → `up -d` → health.
- Обновление клиента: `python deploy/crm7_updater.py --check|--apply [--all]` по публичному фиду
  `https://update.crm7.ru/v1/releases.json`; `--all` проходит цепочку до последней версии,
  `pending.json` восстанавливает прерванный шаг; источник фида — `deploy/update-feed.json`,
  публикация — `python deploy/publish_update_feed.py` (проверяет непрерывность `min_from`).
- База знаний `update.crm7.ru` — статические страницы в `deploy/update-site/public`;
  публикация — `python deploy/publish_update_site.py` (не трогает `v1/`).
- Инструкция по установке `setup.crm7.ru` — `deploy/setup-site/public`; публикация —
  `python deploy/publish_setup_site.py`; Caddy проксирует домен на `crm7-setup-web`.
- Автообновление стендов: systemd-таймеры `crm7-update-demo.timer` (каждые 30 минут) и
  `crm7-update-k.timer` (ежедневно в 03:30); юниты и установщик — `deploy/stand-updater/`.
  На время обновления — `maintenance`-флаг, остановка `cron` и `503` обычным пользователям.
- Ручная проверка/запуск из админки: `GET/POST /admin/update/*` (тех-админ) →
  `/var/lib/crm7-updater/<env>/request.json` → systemd path unit; в ответе `chain` и
  `latest_version`, применяется последняя версия цепочки.

Подробности: [`images.md`](images.md), [`deploy.md`](deploy.md), [`data-import.md`](data-import.md).

## Как добавить…

- **Эндпоинт:** роут в `api/public/index.php` → метод в `Controllers/<Domain>Controller.php` →
  метод в `<Домен>/*Service.php` → (при необходимости) метод в `Repositories/`; при изменении схемы — миграция.
- **Страницу:** `frontend/src/views/<Page>.svelte`, роут в `App.svelte`, обёртка API в `lib/api/`.
- **Миграцию:** `api/db/migrations/NNN_*.sql` (только `CREATE`/`ADD`, без `DROP`/`DELETE`; см. [`migrations.md`](migrations.md)).
