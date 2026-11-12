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

## Версии стендов crm7 (на 12 ноября 2026)

| Стенд | `CRM7_VERSION` в `.env` | Образ в контейнерах |
| --- | --- | --- |
| `crm7-k` | `1.8.12` | `crm7-app/web:1.8.12` (= `sha-a057f92`) |
| `crm7-test` | `1.8.12` | `crm7-app/web:1.8.12` (те же digest) |
| `crm7-demo` | `1.8.12` | `crm7-app/web:1.8.12` (те же digest) |

Образ `1.8.12` собран `deploy/build_images.py --push` с машины разработчика
(фронтенд собирается локально, Docker — на сервере) и соответствует коммиту
`a057f92` (`master` на момент релиза). Теги: `1.8.12` и `sha-a057f92`.
На всех стендах применена миграция `079_status_colors_contrast.sql`
(`migrate.php`: applied 80, pending 0), health `ok`/`database ok` локально
и по публичным адресам.

Фид обновлений (`update.crm7.ru/v1/releases.json`) обновлён 2026-11-12 (последняя — `1.8.12`):
`1.4.0 → 1.4.1 → 1.4.2 → 1.4.4 → 1.5.0 → 1.5.1 → 1.6.0 → 1.6.1 → 1.6.2 → 1.6.3 → 1.6.4 → 1.6.5 → 1.6.6 → 1.6.7 → 1.6.8 → 1.6.9 → 1.6.10 → 1.6.11 → 1.6.12 → 1.7.0 → 1.7.1 → 1.7.2 → 1.8.0 → 1.8.1 → 1.8.2 → 1.8.3 → 1.8.4 → 1.8.5 → 1.8.6 → 1.8.7 → 1.8.8 → 1.8.9 → 1.8.10 → 1.8.11 → 1.8.12` (образ `1.4.3` не публиковался,
поэтому записи нет — цепочка `min_from` идёт `1.4.4 ← 1.4.2`). Все теги
проверены в GHCR (`--verify-images`). Стенды уже на `1.8.12`, таймеры demo/k
при следующем запуске увидят актуальную версию и обновлений не предложат.

Демо-стенд обновляет себя сам: в `crm7-demo-php` смонтирован
`/var/lib/crm7-updater/demo` и задан `CRM7_UPDATE_DIR`. Таймеры:
`crm7-update-demo.timer` (каждые ~30 минут), `crm7-update-k.timer` (раз в
сутки).

Демо-стенд в режиме `DEMO_MODE=true`: страница входа показывает учётные записи
`demo_root` / `demo_admin` / `demo_manager` / `demo_client` (пароль `demo123456`,
создаются `php api/bin/seed-demo-users.php`) для быстрого входа при отладке.

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
python deploy\release_crm7.py --env test --tag sha-798348b
python deploy\release_crm7.py --env demo --tag 1.5.0
```

`release_crm7.py` меняет `CRM7_VERSION` в `.env`, делает `compose pull`,
прогоняет миграции и пересоздаёт контейнеры. Данные (`./data`, `./storage`)
не трогаются.

CI на push в `master` (`.github/workflows/ci.yml`) сам собирает и пушит
`ghcr.io/siteix-ru/crm7-app:sha-<sha>` и `crm7-web:sha-<sha>`.
Раскатка — workflow `deploy-test.yml` (self-hosted runner на сервере) или
`release_crm7.py`.

### Self-hosted runner

Runner `crm7-server` живёт на сервере и обслуживает `deploy-test.yml`:

- каталог `/srv/runner-crm7`, пользователь `ghrunner` (группа `docker`),
  версия runner `2.338.2`;
- сервис `actions.runner.crm7.service` (`systemctl status|restart|stop`),
  логи — `journalctl -u actions.runner.crm7.service`;
- `KillMode=control-group` обязателен: при `KillMode=process` старый
  `Runner.Listener` остаётся жить после рестарта, и новый получает
  `Session Conflict` (в логах «A session for this runner already exists»).
  Лечится так: `systemctl stop`, `pkill -u ghrunner -f Runner.Listener`,
  затем старт (после правки юнита — просто `systemctl restart`);
- метки: `self-hosted`, `Linux`, `X64`, `crm7`;
- перерегистрация: `./register.sh` под `ghrunner` (скрипт сам выбирает
  `--pat` для `ghp_`/`github_pat_` и `--token` для registration token);
  PAT после регистрации удаляется, runner хранит только credentials;
- `ghrunner` имеет право писать `/srv/projects/crm7-test/.env` (группа
  `docker`, `664`) — workflow делает там `sed -i`.

Проверить, что runner на месте:

```powershell
$tok = (Get-Content "$env:USERPROFILE\.secrets\crm7\gh-token-runner.txt" -Raw).Trim()
curl.exe -s -H "Authorization: Bearer $tok" https://api.github.com/repos/siteix-ru/crm7/actions/runners
```

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
