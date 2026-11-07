# CRM7 Setup site

Публичная инструкция по установке CRM7 на Linux: `https://setup.crm7.ru/`.

Страница одна, с якорями: требования, Docker, проект, compose, переменные,
миграции, HTTPS, проверка, первый вход и обновления. Скриншоты лежат в
`public/assets`.

## Публикация

```bash
python deploy/publish_setup_site.py --dry-run
python deploy/publish_setup_site.py
```

Сервер отдаёт файлы из `/srv/build/crm7-setup/public` через контейнер
`crm7-setup-web` (nginx) и Caddy на `setup.crm7.ru`. Пересборка образа не
нужна: каталог смонтирован в контейнер. Пример блока Caddy — в
`Caddyfile.example`.

## Локальная проверка

```bash
python -m http.server 4324 --directory deploy/setup-site/public
```

Открыть `http://127.0.0.1:4324/`.
