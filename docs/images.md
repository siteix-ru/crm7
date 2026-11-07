# Образы и версии CRM7

Код поставляется как **образы** в реестре, данные живут в томах и в образ не
попадают. Обновление на сервере = смена версии + перезапуск, без копирования
файлов.

## Что в образе, а что нет

| В образе (код) | Вне образа (данные/конфиг, тома) |
|---|---|
| `api/` (PHP) | `config/.env`, `config/database.json` |
| `frontend/dist` | `storage/` (вложения) |
| расширения PHP, `nginx.conf` | `var/` (логи, очереди) |
| | `data/db` (MariaDB) |

`.dockerignore` исключает `api/config/.env`, `database.json`, `api/var`,
`api/storage`, `api/db/backups` — в образ они не попадают. **Данные клиентов,
демо и тестов в реестр не уезжают.**

## Реестр
Публичный **GHCR**: `ghcr.io/siteix-ru/crm7-app` и `.../crm7-web`.
Репозиторий и образы открыты (MIT), поэтому клиенты тянут образы анонимно,
без токенов. Публикация (`docker push`) требует GitHub PAT со scope
`write:packages`.

```bash
# на dev-сервере для публикации — один раз, токен не хранить в репозитории:
echo "$GHCR_TOKEN" | docker login ghcr.io -u <github-user> --password-stdin
```

## Сборка и публикация
```bash
python deploy/build_images.py            # собрать на dev-сервере (без пуша)
python deploy/build_images.py --push     # собрать и запушить в GHCR
```
Теги: `<version>` (из `frontend/package.json`) и `sha-<git-commit>`.

## Релиз на окружения
```bash
python deploy/release_crm7.py --env all  --tag 1.4.0
python deploy/release_crm7.py --env test --tag 1.4.0
```
Шаги для каждого окружения: `CRM7_VERSION=<tag>` в `.env` → `docker compose pull`
→ миграции (`migrate.php`) → `docker compose up -d` → проверка health. Тома с
данными не трогаются.

## Ручной запуск без агента

Windows: двойной клик `docker-update.cmd` — меню «собрать / опубликовать /
выпустить / полный цикл». Или из терминала:

```bash
python deploy/update_images.py --mode full --env test --tag 1.4.0
python deploy/update_images.py --mode full --env test --tag 1.4.0 --dry-run
```

По умолчанию предлагается `test`; для `k` и `all` подтверждение по умолчанию —
«нет». `--dry-run` показывает команды без запуска. Подробный разбор команд,
ожидаемых ответов и откат — в [`manual-update.md`](manual-update.md).

**Откат** — релиз предыдущего тега:
```bash
python deploy/release_crm7.py --env k --tag sha-<old-sha>
```

## Структура окружения
```
/srv/projects/crm7-<env>/
  docker-compose.yml      образы crm7-app/crm7-web:${CRM7_VERSION}
  .env                    DB_PASS, DB_ROOT_PASS, CRM7_VERSION
  config/                 → /var/www/html/api/config   (том)
  storage/                → /var/www/html/api/storage  (том)
  var/                    → /var/www/html/api/var      (том)
  data/db/                → /var/lib/mysql             (том)
```
PHP-сервис публикует сетевой алиас `crm7-php`, поэтому один и тот же
`nginx.conf` в образе работает во всех окружениях.

## Обновление клиента
`deploy/crm7_updater.py` — проверка и применение одного релиза по публичному
фиду `https://update.crm7.ru/v1/releases.json`. Токены не нужны, образы
публичные.

```bash
python deploy/crm7_updater.py --check
python deploy/crm7_updater.py --apply --project-dir /srv/projects/crm7-demo --yes
python deploy/crm7_updater.py --apply --project-dir /srv/projects/crm7-demo --dry-run
```

Перед обновлением скрипт делает дамп БД (`update-crm-7/`, режим 0600), меняет
`CRM7_VERSION`, ждёт готовности `db`, применяет миграции, перезапускает
контейнеры и проверяет `/api/v2/health`. При ошибке версия в `.env`
откатывается, дамп сохраняется; применённые миграции не откатываются.

## Фид обновлений
Источник — `deploy/update-feed.json` (правится в git, проходит ревью).
Публикация в `update.crm7.ru`:

```bash
python deploy/publish_update_feed.py --dry-run
python deploy/publish_update_feed.py --verify-images
```

Скрипт проверяет версии, коммиты и `min_from`, при `--verify-images` — теги в
GHCR, затем атомарно пишет `v1/releases.json` и `v1/health.json` на сервер.

## Данные при обновлении
- БД, вложения, конфиг — в томах, обновление образа их не затрагивает.
- Миграции применяются инкрементально (см. `docs/migrations.md`).
- `seed-demo.php --force` — только demo; на k/prod не запускается.
- Перенос наработок клиента (k → прод) — отдельный `mariadb-dump`/restore.
