# CRM7 — Native backup/restore (без Docker)

Этот документ описывает **native backend** для Linux VPS без Docker. Существующий
Docker-путь (`deploy/backup_crm7.py`, `deploy/restore_crm7.py`) остаётся основным
и не изменяется этим этапом.

## Статус

- **VERIFIED IN DISPOSABLE LINUX ENVIRONMENT**: PHP 8.2.36, MariaDB 10.11.19,
  `mariadb-dump` 11.8.9, Python 3.13.5, Docker Desktop 29.9.3 / WSL2.
- **REQUIRES REAL VPS VALIDATION**: реальный хостинг, TLS, дисковые квоты и
  права пользователя не проверялись.

## Требования

- Linux с PHP 8.2+ (`openssl` обязателен для расшифровки `database.json`),
  Python 3.10+, `mariadb-dump` или `mysqldump`, `mariadb` или `mysql`.
- Доступ к каталогу проекта и к server-side `.env` / `database.json`.
- POSIX `flock` (fail-closed, как и в Docker backend).
- Для тестового сервера без TLS можно установить env `CRM7_NATIVE_NO_SSL=1` — он добавляет
  `ssl=0` во временный option file. **В production эту переменную использовать
  нельзя**: TLS настраивается на уровне сервера БД.

## Команды

```bash
cd deploy

# backup (с api/storage/)
python3 native_backup_restore.py \
  --project-dir /srv/crm7 \
  --db-backup-dir /srv/crm7/backups \
  --secrets-dir /srv/protected-crm7 \
  backup --include-storage --backup-id <ID>

# проверка без записи
python3 native_backup_restore.py \
  --db-backup-dir /srv/crm7/backups \
  --secrets-dir /srv/protected-crm7 \
  verify --manifest /srv/crm7/backups/crm7-<ID>.manifest.json

# безопасный preview
python3 native_backup_restore.py \
  --project-dir /srv/crm7 \
  --db-backup-dir /srv/crm7/backups \
  --secrets-dir /srv/protected-crm7 \
  restore --manifest /srv/crm7/backups/crm7-<ID>.manifest.json \
  --confirm <ID> --confirm-target <TARGET_ID> --dry-run

# реальный restore
python3 native_backup_restore.py \
  --project-dir /srv/crm7 \
  --db-backup-dir /srv/crm7/backups \
  --secrets-dir /srv/protected-crm7 \
  restore --manifest /srv/crm7/backups/crm7-<ID>.manifest.json \
  --confirm <ID> --confirm-target <TARGET_ID>
```

## Backup format

Используется формат манифеста версии 1 существующего Docker backend с аддитивными полями:

- `backend: "native"`;
- `native_target_id` — 20 hex-символов;
- `storage` — `{path, size, sha256, members}` или `null`.

Артефакты (имена совпадают с Docker backend):

| Файл | Каталог | Права | Содержимое |
|---|---|---|---|
| `crm7-<ID>.sql` | db-backup-dir | 0600 | SQL-дамп |
| `crm7-<ID>.secrets.tar` | secrets-dir | 0600 | `.env`, `api/config/.env`, опционально `database.json*` |
| `crm7-<ID>.storage.tar` | db-backup-dir | 0640 | файлы `api/storage/` |
| `crm7-<ID>.manifest.json` | db-backup-dir | 0600 | метаданные + SHA-256 |
| `crm7-<ID>.id.lock` | db-backup-dir | 0600 | O_EXCL-маркер, удаляется владельцем |
| `crm7-<ID>.prerestore.sql` | db-backup-dir | 0600 | снимок БД перед restore |

`backup_crm7.verify()` принимает манифест с дополнительными полями; native verify
дополнительно проверяет storage-архив. **Cross-backend restore намеренно не
поддерживается**: текущий Docker restore требует ровно один compose-файл в bundle
и отклонит native-бэкап.

## Native target identity

Идентификатор вычисляется как SHA-256 канонического JSON от:

```
backend | project path (resolved) | DB_HOST | DB_PORT | DB_NAME | DB_USER
```

Пароль **не входит** в identity. Restore требует одновременного совпадения:

1. `native_target_id` в манифесте;
2. 현재 вычисленного `native_target_id`;
3. явного `--confirm-target`;
4. явного `--confirm <BACKUP_ID>`.

Любое расхождение — отказ до изменений данных.

## Credential handling

Пароль БД не передаётся в argv, environment или логи. PHP-хелпер читает `.env` и
(при наличии) зашифрованный `database.json`, расшифровывает пароль через
`App\Support\DatabaseConfigCrypto` и записывает временный MySQL option file с
режимом `0600` внутри каталога проекта. Файл удаляется в `finally`. Команды
`mariadb-dump`/`mariadb` получают только `--defaults-extra-file=<path>`.

## Storage scope

Включаются обычные файлы `api/storage/` с сохранением относительных путей.
Исключаются: symlink, каталоги, специальные файлы, `.tmp*`, `.crm7-*`, `~`,
`.DS_Store`, `Thumbs.db`. Члены архива имеют режим `0600` (общий валидатор tar),
при restoration файлы записываются как `0640`. Существующие файлы при restore
копируются в rollback-каталог внутри staging-каталога.

Не включаются: secrets, `.env`, `database.json`, конфигурация веб-сервера —
они остаются в secret bundle с отдельной политикой 0600.

## Restore strategy

1. `verify` манифеста, SQL и storage + проверка target/confirmation.
2. Взятие канонического `update.lock` + `require_lock()`.
3. Снимок текущей БД в `crm7-<ID>.prerestore.sql` (если ещё нет) для ручного отката.
4. Безопасная распаковка storage в staging `0700` (без `extractall`, без symlink/ traversal).
5. SQL import через локальный `mariadb`/`mysql`; stderr перехватывается и не печатается.
6. Покопирование storage-файлов с rollback-копиями.
7. Durable journal `crm7-native-restore-<ID>.json` с состояниями
   `validated → pre_restore_snapshot → sql_imported → completed`.
8. При ошибке **до** начала SQL imports/storage apply — `failed_before_changes`;
   staging и созданный `prerestore.sql` удаляются, целевые данные не менялись.
9. При ошибке **после** начала destructive-фазы (SQL import или применение
   storage) — `manual_recovery_required`; журнал содержит точные поля
   `recovery_dir`, `sql_snapshot` и `storage_rollback`. Каталог `recovery_dir`
   режимом `0700` **не удаляется**; `prerestore.sql` и rollback-копии storage
   имеют режим `0600`. Повторный restore с тем же `backup_id` отклоняется, пока
   journal находится в `manual_recovery_required`, чтобы случайно не перезаписать
   ссылки на recovery-артефакты.
10. При успешном restore staging и созданный в этой операции `prerestore.sql`
    удаляются. Если удаление не удалось, состояние остаётся `completed`, но в
    journal добавляются `cleanup_warning` и `residual_recovery_dir` (или
    `residual_sql_snapshot`), а предупреждение выводится в stderr — успех restore
    не превращается в ошибку данных.
11. Автоматического отката SQL или storage нет: импорт SQL не транзакционен,
    а сохранённые артефакты предназначены для **ручного** восстановления.
    Не удаляйте `recovery_dir`, пока восстановление не завершено и проверено.

## Retention и диск

- Новый backup с существующим `backup_id` отклоняется; перезапись невозможна.
- Перед backup проверяется отдельность db/secrets directory и создаются каталоги 0700.
- Достаточное место на диске не проверяется автоматически — это ответственность
  оператора (**DOCUMENTED BUT NOT TESTED**).

## Известные ограничения

- Переключение `CRM7_NATIVE_NO_SSL=1` отключает TLS только в тестовом контуре.
- MyISAM-таблицы не имеют `--single-transaction` консистентности.
- Импорт SQL не атомарен; автоматический отказ после частичного imports невозможен.
- Реальные VPS-права, дисковые квоты и TLS не проверялись.
- `--skip-docker-check` в Docker backend не является переключателем native режима.
