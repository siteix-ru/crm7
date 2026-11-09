# Сервер проектов (siteix.ru / crm7.ru / glowcrm.crm7.ru)

Один физический хост обслуживает все проекты: Debian 13, Docker + Compose,
Caddy как reverse proxy. Публичный вход — `178.155.15.42` (SSH-порт `21023`,
80/443 → Caddy). В локальной сети — `172.30.173.240`.

Имя хоста: `L1`. Диск: 105 ГБ (занято ~12 ГБ).

## Доступ с dev-машины

Ключ: `~/.ssh/id_ed25519` (без пароля), алиасы в `~/.ssh/config`:

| Алиас | Куда | Права |
| --- | --- | --- |
| `crm7-server` | `root@172.30.173.240:22` | полный доступ, docker |
| `crm7-dev` | `fynjy@172.30.173.240:22` | группа `docker`, без sudo |
| `www.crm7.ru` | `root@178.155.15.42:21023` | то же, через публичный вход |

Пароль `fynjy` — в `~/.ssh`-независимом месте у владельца (резерв), ключ
уже установлен в `authorized_keys` для `fynjy` и `root`.

Docker CLI + Compose установлены локально, контекст `crm7` указывает на
сервер и включён по умолчанию:

```powershell
docker ps                      # контейнеры сервера
docker logs -f crm7-test-php
docker context use default     # вернуться к локальному движку
docker --context crm7 ps
```

Compose-файлы лежат на сервере, поэтому команды compose выполняются там
(иначе относительные пути `./config`, `./data` разрешатся на Windows):

```powershell
ssh crm7-server "cd /srv/projects/crm7-test && docker compose ps"
ssh crm7-server "cd /srv/projects/crm7-test && docker compose logs --tail=100 php"
```

## Карта проектов и контейнеров

| Проект | Каталог на сервере | Compose | Контейнеры | Домен |
| --- | --- | --- | --- | --- |
| siteix (лендинг) | `/srv/projects/siteix` | `siteix` | `siteix-web`, `siteix-api` | siteix.ru, www.siteix.ru |
| crm7 — лендинг | `/srv/projects/crm7-site` | `crm7-site` | `crm7-site-web` | crm7.ru, www.crm7.ru |
| crm7 — dev/прод | `/srv/projects/crm7-k` | `crm7-k` | `crm7-k-web`, `crm7-k-php`, `crm7-k-cron`, `crm7-k-db` | k.crm7.ru |
| crm7 — тест | `/srv/projects/crm7-test` | `crm7-test` | `crm7-test-web`, `crm7-test-php`, `crm7-test-cron`, `crm7-test-db` | test.crm7.ru |
| crm7 — демо | `/srv/projects/crm7-demo` | `crm7-demo` | `crm7-demo-web`, `crm7-demo-php`, `crm7-demo-cron`, `crm7-demo-db` | demo.crm7.ru |
| crm7 — база обновлений | `/srv/projects/crm7-update` | `crm7-update` | `crm7-update-web` | update.crm7.ru |
| crm7 — инструкция установки | `/srv/projects/crm7-setup` | `crm7-setup` | `crm7-setup-web` | setup.crm7.ru |
| GlowCRM | `/srv/projects/glowcrm` | `glowcrm` | `glowcrm-web`, `glowcrm-api`, `glowcrm-db` | glowcrm.crm7.ru |
| Mediator (тест) | `/srv/projects/mediator-test` | `deploy` | `mediator-test-web`, `mediator-test-api`, `mediator-test-worker`, `mediator-test-db`, `mediator-test-redis` | test-mediator.siteix.ru |
| Mediator (заглушка) | `/srv/projects/mediator` | — | — | mediator.siteix.ru |
| Лендинг (старый) | `/srv/projects/landing` | `landing` | `landing-web` | не маршрутизируется Caddy |
| Caddy | `/srv/proxy` | `proxy` | `proxy-caddy` | :80, :443 |

Локальные репозитории:

| Проект | Репозиторий | Локально |
| --- | --- | --- |
| siteix | `siteix-ru/siteix` (`main`) | `C:\AI_Proects\siteix` |
| crm7 | `siteix-ru/crm7` (`master`) | `C:\AI_Proects\crm7` |
| GlowCRM | `siteix-ru/glowcrm` | `C:\AI_Proects\GlowCRM` |

## Версии стендов crm7 (на 9 ноября 2026)

| Стенд | `CRM7_VERSION` в `.env` | Что реально запущено |
| --- | --- | --- |
| `crm7-k` | `1.4.2` | `crm7-app/web:1.4.2` |
| `crm7-test` | `sha-09d8850` | `crm7-app/web:sha-09d8850` |
| `crm7-demo` | `1.4.4` | `sha-c751e6f` (контейнеры не пересозданы после смены `.env`) |

Демо-стенд обновляет себя сам: в `crm7-demo/php` смонтирован
`/var/lib/crm7-updater/demo` и задан `CRM7_UPDATE_DIR`.

## Сборка образов и релиз

Образы собираются на сервере (локальный Docker не нужен), скрипты ходят по
SSH с ключом `~/.ssh/id_ed25519`:

```powershell
cd C:\AI_Proects\crm7

# собрать образы из текущего frontend/dist и api/
python deploy\build_images.py --skip-build
# то же + запушить в GHCR
python deploy\build_images.py --skip-build --push

# выкатить тег на стенд (test | k | demo | all)
python deploy\release_crm7.py --env test --tag sha-add9ab6
python deploy\release_crm7.py --env demo --tag 1.4.4
```

`release_crm7.py` меняет `CRM7_VERSION` в `.env`, делает `compose pull`,
прогоняет миграции и пересоздаёт контейнеры. Данные (`./data`, `./storage`)
не трогаются.

CI на push в `master` (`.github/workflows/ci.yml`) сам собирает и пушит
`ghcr.io/siteix-ru/crm7-app:sha-<sha>` и `crm7-web:sha-<sha>`.
Раскатка — workflow `deploy-test.yml` (self-hosted runner на сервере) или
`release_crm7.py`.

## Проверка

```powershell
curl.exe -s https://k.crm7.ru/api/v2/health
curl.exe -s https://test.crm7.ru/api/v2/health
curl.exe -s https://demo.crm7.ru/api/v2/health
curl.exe -s https://siteix.ru/api/health
curl.exe -s https://glowcrm.crm7.ru/api/health
```

Caddy: конфиг на хосте `/srv/proxy/Caddyfile`, после правки —

```powershell
ssh crm7-server "docker exec proxy-caddy caddy validate --config /etc/caddy/Caddyfile"
ssh crm7-server "docker exec proxy-caddy caddy reload --config /etc/caddy/Caddyfile"
```

## Особенности

- `/srv/projects/crm7-*`, `/srv/projects/siteix`, `/srv/projects/landing`,
  `/srv/projects/crm7-site` принадлежат `root`; `fynjy` управляет только
  контейнерами (группа `docker`), файлы меняет через `root`-доступ.
- `/srv/projects/glowcrm` и `/srv/projects/mediator*` принадлежат `fynjy`.
- Порты 80/443 публикует `proxy-caddy`; прикладные контейнеры сидят во
  внутренней сети и наружу не смотрят.
- `crm7-demo` и `crm7-test` используют отдельные базы MariaDB в своих
  compose-проектах; `crm7-k` — рабочий стенд с реальными данными.
