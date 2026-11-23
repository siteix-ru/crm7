# CRM7 — Dockerless VPS profile (P0 templates)

Этот каталог содержит **шаблоны и документацию** первого этапа поддержки
обычного Linux VPS без Docker. Это не готовый production-профиль: шаблоны
прошли изолированный smoke в Stage 3.4.163 (см. «Результаты верификации» ниже),
но не проверялись на реальном production-хостинге и не подтверждают
production readiness.

Docker deployment (`deploy/Dockerfile.app`, `deploy/Dockerfile.web`,
`deploy/nginx.conf`, `deploy/*_crm7.py`, `deploy/stand-updater/`) остаётся
поддерживаемым и **не заменяется** этим профилем.

## Что входит и что не входит в этот этап

Входит: `nginx-root.conf`, `nginx-subdir.conf`, `apache-root.conf`
(статус — VERIFIED IN DISPOSABLE SMOKE), `env.example`, `cron.sh` и данная
инструкция.

Результаты верификации (Stage 3.4.163, изолированный disposable-стенд):

- окружение: Nginx 1.27.x, Apache 2.4.71, PHP-FPM 8.2.36, MariaDB 10.11.19;
- Nginx root и subdir: smoke пройден (`/api/v2/health`, `/demo/api/v2/health`,
  приватные пути, `..`/`%2e%2e`, произвольные PHP-URL);
- Apache/PHP-FPM: smoke пройден, исходный `REQUEST_URI` и фиксированный
  `SCRIPT_FILENAME` подтверждены логом FPM;
- cron-wrapper: проверен с **настоящим** `flock` и fake PHP (конкурентный запуск,
  exit codes, снятие блокировки);
- Apache остаётся `VERIFIED IN DISPOSABLE SMOKE` и требует повторной проверки
  на целевом дистрибутиве;
- это **не production deployment**; права каталогов на настоящем Linux-хосте
  не подтверждены (см. раздел «Ограничения»).

Не входит (отдельные этапы):

- CI matrix с dockerless-задачей — отдельный этап (smoke выполнялся вручную);
- backup/restore и updater без Docker — P1, отдельные этапы;
- изменения PHP, frontend, Dockerfile, compose, миграций и схемы БД.

## Требования

- Linux: Ubuntu 22.04/24.04 или Debian 12 (рекомендуется), Nginx **или**
  Apache 2.4 + PHP-FPM. Для Apache обязательны модули, реально использованные
  в проверенном конфиге: `mod_rewrite`, `mod_proxy_fcgi`, `mod_alias`,
  `mod_authz_core`, `mod_unixd`, `mod_dir`, `mod_mime`, `mod_log_config`,
  `mod_headers` (полный список также приведён в разделе «Apache» ниже).
- PHP 8.2+ с расширениями `pdo_mysql`, `mbstring`, `openssl`, `gd`
  (jpeg/freetype/webp), `curl`, `zip`, `exif`, `opcache`.
- MySQL 8 или MariaDB 10.5+ с **TCP**-доступом и DDL-правами на отдельную базу.
- Node.js 22+ нужен только на этапе сборки `frontend/dist`.
- Обязательно: TLS terminates на внешнем прокси/веб-сервере (Caddy, nginx,
  панель хостинга). Шаблоны слушают порт 80 и предполагают termination снаружи.
- PHP-лимиты: `upload_max_filesize`/`post_max_size >= 12M`,
  `memory_limit >= 256M`; для тяжёлых импортов/отчётов лимиты нужно
  поднимать отдельно (этот этап их не меняет).

## Структура каталогов

Root-инсталляция (один проект):

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
│       └── cron.log    # вывод cron.php, вне docroot
└── backups/            # бэкапы (P1), вне docroot, 0700
```

Document root — **исключительно** `/srv/crm7/public`. Каталоги `api/`, `var/`,
`backups/` и `.env` не должны попадать в публичный document root ни напрямую,
ни через symlink. Frontend `dist/` копируется в `public/`.

### Второй URL: `/demo/`

`/demo/` — **не второй маршрут того же backend**, а независимый deployment instance:

```text
/srv/crm7/public/demo/    # вторая копия frontend/dist
/srv/crm7/demo/api/       # вторая копия api/ со своим .env
/srv/crm7/demo/var/
/srv/crm7/demo/backups/
```

Один backend instance обслуживает ровно одну конфигурацию `API_BASE_PATH`.
Не считать, что один backend с одним `API_BASE_PATH` одновременно обслуживает
`/api/v2/*` и `/demo/api/v2/*`. Не внедрять для этого второй FPM pool,
переписывание `REQUEST_URI` или изменения PHP-кода.

| Инсталляция | `API_BASE_PATH` | frontend base | backend |
|---|---|---|---|
| root | `/api/v2` | `./`, `./api/v2` | `/srv/crm7/api` |
| `/demo/` | `/demo/api/v2` | `./`, `./api/v2` | `/srv/crm7/demo/api` |

`frontend/vite.config.ts` задаёт `base: './'`; `frontend/public/config.js`
задаёт `window.CRM_CONFIG.apiV2Base = './api/v2'`. Одна сборка `dist/` пригодна
для обоих каталогов; для `/demo/` достаточно скопировать её в
`/srv/crm7/public/demo/`.

## Подготовка

1. Собрать frontend: `cd frontend && npm ci && npm run build`.
2. Скопировать `frontend/dist/*` в `/srv/crm7/public/` (или `/srv/crm7/public/demo/`).
3. Скопировать каталог `api/` в `/srv/crm7/api/` (или `/srv/crm7/demo/api/`).
4. Скопировать `env.example` в `api/config/.env` и заполнить значения.
   **Не публиковать `.env`, не коммитить, не класть в `public/`.**
5. Создать **новую пустую** базу с `utf8mb4` / `utf8mb4_unicode_ci` и применить
   миграции:

   ```bash
   php /srv/crm7/api/bin/migrate.php --allow-destructive
   ```

   Флаг `--allow-destructive` необходим для прохождения уже существующих
   миграций `009_settings_permissions.sql` и `025_remove_manager_clients.sql`,
   которые содержат `DELETE FROM` / `DROP TABLE`. Без флага `migrate.php`
   останавливается с отказом (проверено на чистой БД в Stage 3.4.163).

   **Ограничения применения флага:**

   - используйте его **только** при первоначальной установке на специально
     созданную пустую БД;
   - **не запускайте** эту команду на существующей или production БД без
     предварительного анализа конкретных миграций;
   - перед миграциями на существующей БД необходимы проверенная резервная копия
     и отдельная оценка того, что удаляют эти миграции;
   - наличие резервной копии **само по себе не делает** destructive-миграции
     безопасными — копия не отменяет потерю данных и возможный простой;
   - сами миграции и `api/bin/migrate.php` в этом профиле не изменяются.
6. Настроить права. В профиле используется **один системный пользователь** `crm`:
   от него работают и PHP-FPM, и cron. Не смешивайте `crm` и `www-data`. Если
   на целевом дистрибутиве PHP-FPM работает от `www-data`, замените `crm` на
   `www-data` во всех командах ниже **и** в пользователе cron — но не
   оставляйте разных пользователей. Общая группа не нужна.

   ```bash
   chown -R crm:crm /srv/crm7/api/config /srv/crm7/api/storage /srv/crm7/api/var /srv/crm7/var /srv/crm7/var/log /srv/crm7/backups
   chmod 750 /srv/crm7/api/config
   chmod 600 /srv/crm7/api/config/.env /srv/crm7/api/config/database.json* 2>/dev/null || true
   chmod 750 /srv/crm7/api/storage /srv/crm7/api/var /srv/crm7/var /srv/crm7/var/log
   chmod 700 /srv/crm7/backups
   ```

   В пуле PHP-FPM укажите `user = crm`, `group = crm`; cron-задачи запускайте
   от того же пользователя `crm`. Каталог `config` должен быть writable для
   `crm`: `SafeConfigFileWriter` создаёт temp-файл, backup и делает atomic
   `rename`. Чтение конфигурации (`0600`, владелец `crm`) и запись логов/storage
   (`0750`, владелец `crm`) разделены по каталогам. Права на `backups` и секреты
   не расширяются; `chmod 777` не применяется.

## Nginx — основной профиль

Файлы:

- `nginx-root.conf` — корневая инсталляция (`server_name`, socket заменить).
- `nginx-subdir.conf` — независимая инсталляция в `/demo/`.

Ключевые свойства:

- `root /srv/crm7/public`; backend физически вне docroot.
- `location ^~ /api/` → единственный front controller с фиксированным
  `SCRIPT_FILENAME /srv/crm7/api/public/index.php`.
- `include fastcgi_params;` сохраняет исходный `REQUEST_URI`
  (`fastcgi_param REQUEST_URI $request_uri;`). **Не добавлять дублирующий
  `fastcgi_param REQUEST_URI`.**
- Более специфичные `location ^~ /api/config`, `/api/db`, `/api/src`,
  `/api/bin`, `/api/tests`, `/api/storage`, `/api/var` с `deny all`.
- `location ~ /\. { deny all; }` — dotfiles в публичном каталоге.
- `location ~ \.php$ { return 404; }` — никакого PHP в document root.
  Внутри `/api/` regex не применяется из-за `^~`, но все URL уходят на
  фиксированный `SCRIPT_FILENAME`, поэтому произвольные `.php` не исполняются.
- SPA fallback `try_files $uri $uri/ /index.html` не перехватывает API.
- `client_max_body_size 12m`, `fastcgi_read_timeout 120s`,
  `HTTP_AUTHORIZATION` / `X-Forwarded-For` / `X-Real-IP` передаются в PHP.

Пути с `..` и `%2e%2e` nginx нормализует до выбора location; эти случаи
проверены в Stage 3.4.163 на disposable-стенде (403, утечек нет).

## Apache 2.4 + PHP-FPM — VERIFIED IN DISPOSABLE SMOKE

**VERIFIED IN DISPOSABLE SMOKE (Apache 2.4.71 + PHP-FPM 8.2.36)**;
не является заявкой о production readiness на целевом хостинге.

`apache-root.conf` прошёл smoke в Stage 3.4.163. Требуемые модули:
`mod_alias`, `mod_rewrite`, `mod_proxy`, `mod_proxy_fcgi`, `mod_authz_core`,
`mod_unixd`, `mod_dir`, `mod_mime`, `mod_log_config`, `mod_headers`.
Окружающий `httpd.conf` обязан их загружать — одного vhost-сниппета недостаточно.

Конфиг использует:

- `Alias /api/` только в `/srv/crm7/api/public/`;
- `<Directory /srv/crm7/api> Require all denied </Directory>` и явный
  `Require all granted` только для `api/public`;
- `AllowOverride None` — `.htaccess` не используется;
- rewrite всех API-запросов в `index.php`, запрет других `.php`;
- `FilesMatch` в публичном docroot: dotfiles и `*.php` запрещены (иначе PHP
  отдавался бы как текст при отсутствии mod_php);
- `SetHandler proxy:unix:…|fcgi://localhost` только для `index.php`.

Подтверждено на smoke: `GET /api/v2/health` → JSON `status: ok`;
`SCRIPT_FILENAME=/srv/crm7/api/public/index.php`; исходный `REQUEST_URI`
сохраняется после internal rewrite; `/api/router.php` не исполняется;
служебные каталоги, dotfiles и PHP-исходники недоступны; `AllowOverride None`
не ломает API. Перед production-использованием повторить проверку на целевом
дистрибутиве Apache/PHP-FPM.

## Cron

`api/bin/cron.php` выполняет очередь писем, расписания отчётов, замещения,
передачи клиентов и пометку «зависших» заданий импорта. На Dockerless VPS
**все** запуски должны идти только через `cron.sh`. Прямой запуск
`php /srv/crm7/api/bin/cron.php` из crontab, systemd, панели или вручную
не использовать.

### Установка и настройка

```bash
# пользователь CRM — тот же самый, от которого работает PHP-FPM: crm
install -m 0750 -o root -g crm deploy/dockerless/cron.sh /usr/local/bin/crm7-cron
chown crm:crm /srv/crm7/var /srv/crm7/var/log
chmod 0750 /srv/crm7/var /srv/crm7/var/log
chmod 0600 /srv/crm7/var/crm7-cron.lock 2>/dev/null || true
```

Переменные окружения wrapper (все значения заключаются в кавычки внутри скрипта):

| Переменная | Default | Назначение |
|---|---|---|
| `CRM7_ROOT` | `/srv/crm7` | корень инсталляции |
| `CRM7_PHP_BIN` | `/usr/bin/php` | PHP CLI |
| `CRM7_FLOCK_BIN` | `flock` | утилита flock (можно задать абсолютный путь) |
| `CRM7_CRON_LOCK` | `/srv/crm7/var/crm7-cron.lock` | lock-файл |
| `CRM7_CRON_LOG` | `/srv/crm7/var/log/cron.log` | лог |

Пример crontab (интервал 1 минута):

```cron
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
CRM7_PHP_BIN=/usr/bin/php8.2
* * * * * /usr/local/bin/crm7-cron
```

Пример выше — пользовательский crontab пользователя `crm` (`crontab -u crm -e`),
поэтому поле пользователя в строке не нужно. Если используется системный
`/etc/crontab`, добавьте поле пользователя:
`* * * * * crm /usr/local/bin/crm7-cron`.

Права не расширяются на весь `/srv/crm7`: wrapper исполняем пользователю `crm`,
`/srv/crm7/var` и `/srv/crm7/var/log` принадлежат `crm` и доступны на запись.
Lock и логи остаются вне document root. Wrapper не создаёт файлы в `/run`,
`/tmp` или публичном каталоге и не удаляет lock-файл после выполнения.

### Exit codes

| Код | Значение |
|---:|---|
| `0` | PHP завершился успешно; код PHP передаётся как есть |
| `75` | другой запуск уже держит блокировку; **PHP не запускался** |
| `69` | нет `flock`, PHP CLI, рабочего каталога или `bin/cron.php` |
| `73` | не удалось открыть/создать lock-файл |
| `74` | не удалось открыть лог на запись |

Повторный запуск при уже работающем cron завершается `75` и ничего не пишет
в `cron.log` — по этому коду мониторинг отличает пропуск от успешной обработки.

Блокировка снимается ядром, когда закрываются **все** дескрипторы lock-файла.
Если процесс PHP завершить через SIGKILL, но он успел породить долгоживущие
дочерние процессы, они наследуют fd 9 и могут удерживать лок до своего
выхода (в smoke-тесте с дочерним `sleep` это наблюдалось). `cron.php` такие
процессы не создаёт, но при аварийном завершении стоит учитывать задержку;
удалять lock-файл вручную не рекомендуется.

### Диагностика

- Если `flock` отсутствует в `PATH` cron, wrapper завершится `69`. Укажите
  `PATH` в crontab или `CRM7_FLOCK_BIN=/usr/bin/flock`. **Не** удаляйте проверку
  `flock` и не запускайте `cron.php` напрямую — это снимет защиту от параллельных
  запусков.
- Лог смотреть так: `tail -f /srv/crm7/var/log/cron.log`. cron.php пишет одну
  JSON-строку за запуск; stderr тоже попадает в этот файл.

### Почта: ограничение гарантий

`cron.php` обрабатывает до 50 писем за запуск; SMTP-клиент имеет timeout
до 15 секунд на connect/read. Поэтому один batch может длиться больше 10 минут
(stale timeout в `EmailQueueRepository`). `releaseStale()` не проверяет, жив ли
процесс, а `markSent()` не проверяет claim token. Если обойти wrapper или
запустить `cron.php` параллельно другим способом, письмо, которое ещё
отправляется, может быть возвращено в очередь и отправлено повторно.

`flock` — обязательная часть штатного Dockerless cron. Текущая реализация
очереди **не гарантирует exactly-once delivery**; таймауты, batch limit
и код mail queue в этом этапе не изменились.

## Ограничения текущего этапа

- Smoke Stage 3.4.163 выполнялся в изолированном disposable-окружении на
  Nginx 1.27.x, Apache 2.4.71, PHP-FPM 8.2.36 и MariaDB 10.11.19. Это **не**
  проверка на реальном VPS и не подтверждение совместимости с другими
  дистрибутивами или версиями.
- `nginx -t` и `apachectl configtest` проходили внутри контейнеров smoke;
  `cron.sh` проверялся с настоящим `flock` и fake PHP, но не на production
  хостинге. Права каталогов на настоящем Linux-хосте не подтверждены:
  Docker Desktop под Windows монтирует каталоги с режимом `0777`, поэтому
  команды `chown`/`chmod` выше остаются рекомендацией для целевого сервера.
- Backup/restore, updater, CI matrix и HTTPS-конфигурация в этот профиль не
  входят. HTTPS — обязательное условие production, но настройка TLS остаётся
  за инфраструктурой.
- Не заявлять, что Dockerless hosting прошёл полноценное production-тестирование
  или готов к замене Docker deployment.
