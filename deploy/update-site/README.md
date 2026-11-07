# CRM7 Update site

Публичная точка `https://update.crm7.ru`: фид релизов и бесплатная база знаний
по обновлению CRM7.

## Страницы

- `/` — обзор и выбор способа обновления;
- `/admin/` — автоматическое обновление из админки;
- `/manual/` — ручное обновление через консоль;
- `/troubleshooting/` — диагностика ошибок и режима обслуживания;
- `/v1/releases.json`, `/v1/health.json` — публичный фид (публикуется отдельно
  скриптом `deploy/publish_update_feed.py`).

## Публикация

Файлы сайта лежат в `deploy/update-site/public`. На сервере они смонтированы в
контейнер `crm7-update-web` из `/srv/build/crm7-update/public`.

```bash
python deploy/publish_update_site.py --dry-run
python deploy/publish_update_site.py
```

Скрипт не трогает `v1/` — фид обновляется отдельным пайплайном. Пересборка
образа не нужна: nginx читает смонтированный каталог.

## Локальная проверка

```bash
python -m http.server 4322 --directory deploy/update-site/public
```

Открыть `http://127.0.0.1:4322/`. В локальном окружении нет `v1/releases.json`,
поэтому главная покажет резервный текст; на сервере фид подхватится.

## Скриншоты

`public/assets/admin-updates.png`, `admin-maintenance.png` и
`manual-terminal.png` — учебные примеры. При изменении интерфейса обновите их
через Playwright на тестовом стенде.
