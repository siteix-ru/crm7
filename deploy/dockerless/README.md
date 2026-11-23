# CRM7 — Dockerless VPS profile (P0 templates)

Этот каталог содержит шаблоны, тесты и документацию профиля развёртывания CRM7
на обычном Linux VPS без Docker. Профиль **не является production-ready сам по
себе**: он проверен в изолированном disposable Linux/Docker-окружении, но не на
реальном VPS и не на целевом дистрибутиве заказчика.

Docker deployment (`deploy/Dockerfile.app`, `deploy/Dockerfile.web`,
`deploy/nginx.conf`, `deploy/*_crm7.py`, `deploy/stand-updater/`) остаётся
поддерживаемым и **не заменяется** этим профилем.

## Статусы утверждений

| Обозначение | Значение |
|---|---|
| **VERIFIED IN DISPOSABLE LINUX ENVIRONMENT** | Проверено внутри контейнеров с нативной Linux-ФС в Stage 3.4.178 |
| **DOCUMENTED BUT NOT TESTED** | Описано, но не проверялось даже в disposable-среде |
| **NOT SUPPORTED** | В текущем профиле реализация отсутствует |
| **REQUIRES REAL VPS VALIDATION** | Не может быть подтверждено без реального сервера |

## Что проверено (Stage 3.4.178)

Disposable-стенд: Docker Desktop 29.9.3 / WSL2 Linux 6.18.58.1, сеть и тома с
префиксом `crm7-stage-34178`, контейнеры удалены после тестов.

| Компонент | Версия |
|---|---|
| PHP CLI / FPM | 8.2.36 (Debian GNU/Linux 13 trixie, образ `php:8.2-fpm`) |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `curl`, `gd` (jpeg/freetype/webp), `exif`, `zip`, `opcache`, `json`, `zlib` |
| Nginx | 1.27.5 (Alpine) |
| Apache | 2.4.71 (Alpine) |
| MariaDB | 10.11.19 |

Проверено:

- root и `/demo/` инсталляции Nginx; API `/api/v2/health` и
  `/demo/api/v2/health` → JSON `{"ok":true,...,"status":"ok"}`;
- Apache/PHP-FPM vhost: `apachectl configtest`, health, `SCRIPT_FILENAME` и
  исходный `REQUEST_URI` (по логу FPM);
- **авторизация Bearer**: `POST /auth/login` → `GET /auth/me` — работает и на
  Nginx, и на Apache (регрессия, описана ниже);
- закрытые пути (`.env`, `database.json*`, `api/src`, `api/bin`, `api/tests`,
  `api/storage`, `api/var/log`, `backups`), dotfiles, `*.php` в docroot,
  `..` и `%2e%2e` — 20/20 проверок на каждом из трёх серверов;
- cron-wrapper: 11 сценариев с настоящим `flock` и fake PHP;
- права на **нативной Linux-ФС** внутри контейнера: 11/11 проверок;
- `logrotate -d` для предложенного конфига.

Автоматические проверки лежат в `deploy/dockerless/tests/`:

```bash
sh deploy/dockerless/tests/config-check.sh
sh deploy/dockerless/tests/http-smoke.sh http://127.0.0.1:18280 /api/v2 admin '<password>'
sh deploy/dockerless/tests/http-smoke.sh http://127.0.0.1:18281 /demo/api/v2 admin '<password>' /demo
sh deploy/dockerless/tests/cron-wrapper-tests.sh deploy/dockerless/cron.sh
```

`http-smoke.sh` ожидает canary-файлы и 구조, описанную ниже; в production эти
canary-файлы **не создаются**. `config-check.sh` не требует установки nginx или
Apache: отсутствующие бинарники помечаются как SKIP.

## Исправленные дефекты (первоначальное состояние → исправление)

| Дефект | Проявление | Исправление |
|---|---|---|
| Apache не передавал `Authorization` в PHP-FPM | `POST /auth/login` успешен, но `GET /auth/me` → `unauthorized` | `mod_setenvif` + `SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1` в `apache-root.conf`; добавлен в список обязательных модулей; покрыто регрессионным тестом |
| Клиентские `X-Forwarded-For` / `X-Real-IP` попадали в PHP |FPM log: `xff=<TEST-NET-IP>`; обход rate limit и подделка аудита | Nginx передаёт все клиентские заголовки в FastCGI автоматически, поэтому добавлены явные `fastcgi_param HTTP_X_FORWARDED_FOR ""` и `HTTP_X_REAL_IP ""`; в Apache — `RequestHeader unset` на уровне vhost |
| `ServerTokens` в vhost | `apachectl configtest` ошибка при попытке задать директиву внутри `<VirtualHost>` | Эти директивы нельзя задавать в vhost; задавайте `ServerTokens Prod` и `ServerSignature Off` в основном `httpd.conf` целевого сервера (**DOCUMENTED BUT NOT TESTED** на целевом дистрибутиве) |

## Требования

- Linux: Ubuntu 22.04/24.04 или Debian 12+ (проверен Debian 13 в контейнере).
- Nginx **или** Apache 2.4 + PHP-FPM. Обязательные модули Apache (проверенный
  набор): `mod_alias`, `mod_rewrite`, `mod_proxy`, `mod_proxy_fcgi`,
  `mod_authz_core`, `mod_unixd`, `mod_dir`, `mod_mime`, `mod_log_config`,
  `mod_headers`, `mod_setenvif`. Основной `httpd.conf` должен их загружать —
  одного vhost-сниппета недостаточно.
- PHP 8.2+ с расширениями `pdo_mysql`, `mbstring`, `openssl`, `curl`,
  `gd` (jpeg/freetype/webp), `exif`, `zip`, `opcache`. `curl` нужен также для
  `/admin/update/check` (см. раздел про updater); `exif` опционален, но используется для
  ориентации фото.
- MySQL 8 или MariaDB 10.5+ с **TCP**-доступом и DDL-правами на отдельную базу.
- Node.js 22+ нужен только на этапе сборки `frontend/dist`.
- Обязательно: TLS terminates на внешнем прокси/веб-сервере. Шаблоны слушают
  порт 80 и предполагают termination снаружи.
- PHP-лимиты: `upload_max_filesize`/`post_max_size >= 12M`,
  `memory_limit >= 256M`; для тяжёлых импортов/отчётов лимиты нужно поднимать
  отдельно. Установленные значения проверены только в disposable-образе.

## Структура каталогов

```text
/srv/crm7/
├── public/             # только frontend/dist и config.js
│   ├── index.html
│   ├── config.js
│   ├── manifest.webmanifest
│   ├── sw.js
│   └── assets/
├── api/                # backend, НЕ в document root
│   ├── public/index.php
│   ├── config/.env     # 0600, каталог config 0750
│   ├── src/, db/, bin/, tests/
│   ├── storage/        # загрузки, writable
│   └── var/            # логи приложения
├── var/
│   ├── crm7-cron.lock  # блокировка cron, вне docroot
│   └── log/
│       ├── cron.log
│       └── app.log
└── backups/            # бэкапы (P1), вне docroot, 0700
```

Document root — **исключительно** `/srv/crm7/public`. Каталоги `api/`, `var/`,
`backups/` и `.env` не должны попадать в публичный document root ни напрямую,
ни через symlink. Frontend `dist/` копируется в `public/`.

Второй URL `/demo/` — **не второй маршрут того же backend**, а независимый
deployment instance со своим `api/`, `.env`, `storage/`, `var/` и `backups/`:

```text
/srv/crm7/public/demo/    # вторая копия frontend/dist
/srv/crm7/demo/api/
/srv/crm7/demo/var/
/srv/crm7/demo/backups/
```

Один backend instance обслуживает ровно одну конфигурацию `API_BASE_PATH`.
Не считать, что один backend с одним `API_BASE_PATH` одновременно обслуживает
`/api/v2/*` и `/demo/api/v2/*`.

| Инсталляция | `API_BASE_PATH` | frontend base | backend |
|---|---|---|---|
| root | `/api/v2` | `./`, `./api/v2` | `/srv/crm7/api` |
| `/demo/` | `/demo/api/v2` | `./`, `./api/v2` | `/srv/crm7/demo/api` |

## Подготовка

1. Собрать frontend: `cd frontend && npm ci && npm run build`.
2. Скопировать `frontend/dist/*` в `/srv/crm7/public/` (или `/srv/crm7/public/demo/`).
   Production-сборка (`npm run build`) не создаёт `.map`: `vite.config.ts`
   включает sourcemap только для dev-сервера / режима `development`. Docker и
   Dockerless используют одну и ту же production-сборку, поэтому публикация
   исходников фронтенда исключена; регрессионный тест — `vite-config.test.ts`.
3. Скопировать каталог `api/` в `/srv/crm7/api/` (или `/srv/crm7/demo/api/`).
4. Скопировать `env.example` в `api/config/.env` и заполнить значения.
   **Не публиковать `.env`, не коммитить, не класть в `public/`.**
   Для демо-инстанса обязательно переопределить `API_BASE_PATH`,
   `LOG_DIR` и `STORAGE_DIR` (см. `env.example`).
5. Создать **новую пустую** базу с `utf8mb4` / `utf8mb4_unicode_ci` и применить
   миграции:

   ```bash
   php /srv/crm7/api/bin/migrate.php --allow-destructive
   ```

   Флаг нужен из-за миграций `009_settings_permissions.sql` и
   `025_remove_manager_clients.sql` (`DELETE FROM` / `DROP TABLE`). Использовать
   **только** на новой пустой БД. На существующей БД — отдельный анализ
   миграций и проверенная резервная копия; наличие копии само по себе не делает
   destructive-миграции безопасными.

6. **Первичная учётная запись.** Миграции не создают пользователей. Без этого
   шага войти в систему нельзя, а `DatabaseSettings::assertHasAdmin()` не
   даст сохранить настройки БД через админку. Для тестового/демо-стенда:

   ```bash
   cd /srv/crm7/api && php bin/seed-dev.php --password='<надёжный пароль>'
   ```

   Для production создайте одного администратора контролируемым одноразовым
   скриптом и сразу смените пароль; не используйте стандартные логины
   `admin`/`administrator` без ротации пароля (**DOCUMENTED BUT NOT TESTED** на
   целевом хостинге).

7. Настроить права. Используется **один системный пользователь** `crm`: под ним
   работают PHP-FPM и cron. Не смешивайте `crm` и `www-data`. Если дистрибутив
   использует `www-data`, замените `crm` во всех командах и в пользователе cron,
   но не оставляйте разных пользователей. Общая группа не нужна.

   ```bash
   chown -R crm:crm /srv/crm7/api/config /srv/crm7/api/storage /srv/crm7/api/var /srv/crm7/var /srv/crm7/var/log /srv/crm7/backups
   chmod 750 /srv/crm7/api/config
   chmod 600 /srv/crm7/api/config/.env /srv/crm7/api/config/database.json* 2>/dev/null || true
   chmod 750 /srv/crm7/api/storage /srv/crm7/api/var /srv/crm7/var /srv/crm7/var/log
   chmod 700 /srv/crm7/backups
   ```

   Для второго инстанса повторите то же для `/srv/crm7/demo/api/config`,
   `/srv/crm7/demo/api/storage`, `/srv/crm7/demo/api/var`, `/srv/crm7/demo/var`,
   `/srv/crm7/demo/var/log`, `/srv/crm7/demo/backups` — иначе логи, загрузки и
   запись конфигурации демо-инстанса не заработают.

   Права проверены на нативной Linux-ФС внутри контейнера: пользователь `nobody`
   не может прочитать/записать приватные каталоги, пользователь `crm` может.
   `chmod 777` и рекурсивное изменение прав на чужие каталоги не применяются.
   umask PHP-FPM может создавать файлы storage/log с режимом 0644; если нужна
   изоляция между локальными пользователями, задайте `umask 007` в пуле FPM.

## Nginx — основной профиль

- `nginx-root.conf` — корневая инсталляция; `nginx-subdir.conf` — `/demo/`.
- `root /srv/crm7/public`; backend физически вне docroot.
- `location ^~ /api/` → единственный front controller с фиксированным
  `SCRIPT_FILENAME /srv/crm7/api/public/index.php`.
- `include fastcgi_params;` сохраняет исходный `REQUEST_URI`; дублирующий
  `fastcgi_param REQUEST_URI` не добавляется.
- `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` передаёт Bearer-токен.
- `HTTP_X_FORWARDED_FOR` и `HTTP_X_REAL_IP` **принудительно очищаются пустыми
  значениями**: Nginx автоматически передаёт все клиентские заголовки в
  FastCGI, одного удаления `fastcgi_param` недостаточно. За доверенным
  reverse proxy эту схему нужно проверять отдельно.
- deny-локации `/api/config`, `/api/db`, `/api/src`, `/api/bin`, `/api/tests`,
  `/api/storage`, `/api/var`, `/var/`, `/backups/`; dotfiles и `*.php` в
  docroot закрыты; SPA fallback не перехватывает API.
- Путь к сокету `/run/php/php8.2-fpm.sock` конкретен для PHP 8.2. На дистрибутиве
  с PHP 8.3/8.4 замените его на фактический (`php8.3-fpm.sock` и т.д.).
- Заявление про `..`/`%2e%2e`: Nginx нормализует `$uri` **для выбора location**,
  но исходный `$request_uri` передаётся в PHP без нормализации. Утечки нет,
  потому что backend находится вне docroot и `SCRIPT_FILENAME` фиксирован;
  не следует считать, что PHP получает «очищенный» путь.

## Apache 2.4 + PHP-FPM — VERIFIED IN DISPOSABLE SMOKE

Проверено на Apache 2.4.71 + PHP-FPM 8.2.36 в disposable-контейнере. Это **не**
подтверждение совместимости с любым дистрибутивом; проверьте на целевом.

- `Alias /api/` только в `/srv/crm7/api/public/`; родительский
  `/srv/crm7/api` закрыт, служебные каталоги закрыты `DirectoryMatch`.
- `AllowOverride None`, `.htaccess` не используется.
- `SetEnvIfNoCase ^Authorization$` обязателен: без него `mod_proxy_fcgi`
  не передаёт Bearer-токен и все авторизованные запросы получают `401`.
- `RequestHeader unset X-Forwarded-For/X-Real-IP/X-Forwarded-Host` на уровне
  vhost убирает подделываемые заголовки.
- `FilesMatch` запрещает dotfiles и `*.php` в публичном docroot; в `api/public`
  запрещены все `.php`, кроме `index.php`; FPM handler применяется только к
  `index.php`.
- `ServerTokens Prod` и `ServerSignature Off` задаются в основном `httpd.conf`,
  **не** в vhost (иначе `apachectl configtest` завершается ошибкой). Готовый
  двухстрочный фрагмент — `apache-main.conf.example`; вставьте его в основной
  контекст сервера (Debian/Ubuntu — `/etc/apache2/apache2.conf`) вне блоков
  `<VirtualHost>`. Модули включаются штатным механизмом дистрибутива, например
  `a2enmod alias rewrite proxy proxy_fcgi authz_core unixd dir mime log_config
  headers setenvif`; пути модулей из Alpine-теста не копируйте.

## Cron

`php api/bin/cron.php` обрабатывает очередь писем (до 50 за запуск),
расписания отчётов, замещения, передачи клиентов и «зависшие» импорты. Все
запуски идут только через `cron.sh`.

```cron
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
CRM7_PHP_BIN=/usr/bin/php8.2
* * * * * /usr/local/bin/crm7-cron
```

Это пример пользовательского crontab пользователя `crm` (`crontab -u crm -e`);
в системном `/etc/crontab` добавьте поле пользователя.

Exit codes: `0` — успех/PHP-код; `75` — лок занят, PHP не запускался; `69` —
нет `flock`, PHP CLI, каталога или скрипта; `73` — нельзя открыть lock; `74` —
нельзя открыть лог. Код `73` выдаётся проверкой доступности lock-файла; ошибка
непосредственного `exec 9>>` может дать общий код из-за семантики POSIX-шелла.

Блокировка снимается, когда закрываются **все** дескрипторы файла. Если PHP
породил долгоживущий дочерний процесс, он наследует fd 9 и удерживает лок после
сигнала родителю (проверено в тесте: следующий запуск получает `75`, а после
выхода ребёнка — `0`). `cron.php` такие процессы не создаёт. Lock-файл не
удаляется.

Почтовая очередь **не гарантирует exactly-once**: batch до 50 писем × таймаут
SMTP до 15с может превысить 10-минутный stale timeout; `releaseStale()` не
проверяет живой процесс, `markSent()` не проверяет claim token. `flock` —
обязательная часть профиля; обход wrapper опасен повторной отправкой.

### Ротация логов

`logrotate.conf` — проверенный `logrotate -d` шаблон для
`/srv/crm7/var/log/cron.log` (неделя, 8 копий, `copytruncate`, `su crm crm`).
`copytruncate` выбран потому, что cron открывает лог на каждый запуск и не
держит его постоянно; несколько байт, записанных точно во время ротации, могут
потеряться — это приемлемо для информационного лога. Настройки применяются
только к информационному `cron.log`, не к структурированному `app.log`.

`cron.sh` использует `umask 077`: новый `cron.log` и lock-файл создаются с
режимом `0600`. Для согласованности `logrotate.conf` задаёт
`create 0600 crm crm` — ротированный/вновь созданный лог остаётся `0600`,
без расширения прав на группу или мир. После ротации проверьте:

```bash
logrotate -d /etc/logrotate.d/crm7-cron
ls -l /srv/crm7/var/log/cron.log*
```

Для структурированного `app.log` при необходимости строгой сохранности
нужна отдельная схема.

## Backup/restore и updater

### Docker backend (существующий)

`deploy/backup_crm7.py` и `deploy/restore_crm7.py` остаются рабочим Docker-путем.
Они требуют Docker Compose, `docker inspect`, томов и (для restore) root-право на
восстановление владельцев файлов. Флаг `--skip-docker-check` **не** включает
Dockerless-режим: дамп всё равно выполняется через `docker compose exec -T db`,
а preflight требует compose-файл. `/admin/update/apply` требует
`CRM7_UPDATE_DIR`, systemd path unit и Docker; на Dockerless-инсталляции он
недоступен. admin-эндпоинт `/admin/update/check` остаётся доступным и требует
`php-curl`.

### Native backend (для Dockerless VPS)

Для Linux без Docker добавлен отдельный backend:

- `deploy/native_backup_restore.py`;
- документация — [`docs/backup-restore-native.md`](../../docs/backup-restore-native.md);
- общая справка — [`docs/backup-restore.md`](../../docs/backup-restore.md).

Команды: `backup` (опционально `--include-storage`), `verify`, `restore`
(`--dry-run`, обязательные `--confirm <BACKUP_ID>` и `--confirm-target <ID>`).
Native identity вычисляется из project path + DB_HOST/PORT/NAME/USER и не
включает пароль. Backup включает SQL, secret bundle (`.env`, `api/config/.env`
и опционально `database.json*`) и, при `--include-storage`, архив `api/storage/`.
Формат манифеста версии 1 совместим с Docker-верификатором по базовым полям, но
**native и Docker форматы не являются cross-compatible для restore**: Docker
restore требует compose-файл в bundle и отклонит native-бэкап.

### Ограничения native backend

- **VERIFIED IN DISPOSABLE LINUX ENVIRONMENT** (PHP 8.2.36 / MariaDB 10.11.19 /
  `mariadb-dump` 11.8.9); **НЕ проверялся на реальном VPS**.
- SQL restore не транзакционен; после частичного failure возможна ситуация
  `manual_recovery_required` — используйте `crm7-<ID>.prerestore.sql`.
- Проверка свободного места на диске — ответственность оператора.
- Права 0600/0700 и владелец `crm` должны быть проверены на целевом сервере.
- TLS для БД настраивается инфраструктурой; тестовая переменная
  `CRM7_NATIVE_NO_SSL=1` не для production.
- updater по-прежнему **NOT SUPPORTED** без Docker/systemd; нужен отдельный этап.

## Безопасность

- Секреты только в server-side `.env` / `database.json`; `APP_KEY` и
  `DB_CONFIG_KEY` генерируются отдельно. `.env` имеет режим 0600.
- `DEMO_MODE` должен быть `false` на production: при `true` публичный endpoint
  отдаёт демо-логины и пароль.
- `X-Forwarded-For` не доверяется (см. выше). IP-лимиты и аудит используют
  `REMOTE_ADDR` прокси/сервера.
- Ошибки PHP скрыты при `APP_ENV=prod`; `server_tokens off` для Nginx;
  `ServerTokens Prod` — в основном httpd.conf для Apache.
- TLS обязателен на уровне инфраструктуры; приложение не принуждает HTTPS.
- Аутентификация — Bearer в `localStorage` (нет HttpOnly cookie); это
  существующая модель CRM7, не изменяемая в этом профиле.
- Дополнительные HTTP-заголовки безопасности добавлены в оба шаблона.

## Первичный запуск и проверка доступности

1. Подготовить БД, `.env`, права, первого администратора (см. выше).
2. Поднять PHP-FPM, веб-сервер и cron-wrapper.
3. Проверить:

   ```bash
   curl -fsS http://<host>/api/v2/health
   sh deploy/dockerless/tests/http-smoke.sh http://<host> /api/v2 <login> <password>
   tail -f /srv/crm7/var/log/cron.log
   tail -f /srv/crm7/var/log/app.log
   ```

4. Убедиться, что `/api/config/.env`, `/api/storage/`, `/backups/` недоступны
   извне (делает `http-smoke.sh`).

## Откат (безопасный rollback)

- Откат конфигурации веб-сервера: вернуть предыдущий vhost и перезапустить
  сервис; файлы приложения при этом не меняются.
- Откат PHP-кода: восстановить предыдущую копию каталога `api/` (кроме
  `config/`, `storage/`, `var/`) и выполнить `php bin/migrate.php --status`.
- Откат миграций не поддерживается: `migrate.php` не делает down-миграций.
  Перед destructive-миграциями — проверенная резервная копия и отдельный план
  (**DOCUMENTED BUT NOT TESTED**).
- Откат базы в Dockerless-профиле не реализован (см. раздел про backup/restore).

## Диагностика

- `unauthorized` после логина на Apache → проверьте `mod_setenvif` и строку
  `SetEnvIfNoCase ^Authorization$`.
- Rate limit не срабатывает → проверьте, что `X-Forwarded-For` очищается
  (FPM access log с `%{HTTP_X_FORWARDED_FOR}e`).
- `502 Bad Gateway` → проверьте владельца/режим сокета PHP-FPM и принадлежность
  пользователя веб-сервера группе `crm`.
- cron завершается `75` → другой запуск держит лок или дочерний процесс удерживает fd.

## Неподтверждённые предположения

- Реальный VPS, конкретный дистрибутив, systemd-юниты, firewall и TLS-терминация
  не проверялись (**REQUIRES REAL VPS VALIDATION**).
- Права и владельцы на реальном хостинге могут отличаться от проверенной
  конфигурации контейнеров.
- Excel (`LEGACY_VENDOR=`) в этом профиле не настроен; XLS/XLSX-импорт/экспорт
  вернёт `422`, если не указать путь кPhpSpreadsheet vendor вне webroot.
- CI matrix для dockerless-профиля отсутствует; тесты запускаются вручную.
- Производительность, лимиты PHP и поведение при реальных объёмах данных
  не измерялись.
