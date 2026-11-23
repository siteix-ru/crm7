# Резервное копирование CRM7

Команда `deploy/backup_crm7.py` формирует **комплект** из двух раздельных частей:

- SQL-дамп БД в `--db-backup-dir` (`crm7-<backup_id>.sql`);
- защищённый архив конфигурации и секретов в **отдельном** `--secrets-dir`
  (`crm7-<backup_id>.secrets.tar`);
- manifest `crm7-<backup_id>.manifest.json` рядом с SQL-дампом связывает обе части
  общим `backup_id`, размерами и SHA-256.

`--secrets-dir` обязателен, не может совпадать с каталогом SQL-дампов и не может
быть вложен в него (проверяются canonical/resolved paths, включая symlink).

## Использование

```bash
python deploy/backup_crm7.py \
  --project-dir /srv/projects/crm7-demo \
  --db-backup-dir /srv/projects/crm7-demo/update-crm-7 \
  --secrets-dir /srv/protected-secrets/crm7-demo
```

Проверка комплекта без восстановления (`--secrets-dir` нужен, чтобы найти bundle,
который лежит в другом каталоге):

```bash
python deploy/backup_crm7.py verify \
  --manifest /srv/projects/crm7-demo/update-crm-7/crm7-<backup_id>.manifest.json \
  --secrets-dir /srv/protected-secrets/crm7-demo
```

## Общий project lock Backup

Реальный backup захватывает тот же канонический exclusive `update.lock`
(`<project>/update-crm-7/update.lock`), что и Restore, updater, release и deploy.
Lock берётся **до preflight** и удерживается до публикации manifest: SQL dump
не выполняется вне project lock. Порядок захвата:

1. `update.lock` — exclusive `flock`;
2. `crm7-<backup_id>.id.lock` — эксклюзивное право на ID (`O_EXCL`);
3. `database.json.lock` — shared `flock`, только на время чтения конфигурации
   в secret bundle;
4. создание SQL dump, secret bundle и manifest;
5. освобождение в обратном порядке.

Один проект не может одновременно выполнять Backup и Restore/updater/release/
deploy, а также два Backup. Занятый lock приводит к **fail-fast** и ненулевому
exit code с сообщением `backup lock is busy`: ожидания и retry нет, захват
неблокирующий (`LOCK_NB`), как и у остальных утилит.

`verify` read-only и **не** захватывает project lock; команда `status` у Restore
тоже читает только журнал. Реальный Backup требует POSIX `flock`: на Windows,
где его нет, он отказывается до создания артефактов (fail-closed).

### SIGTERM и аварийное завершение Backup

На время реального Backup устанавливается обработчик SIGTERM, который
преобразует сигнал в управляемое исключение, которое проходит через `finally`:
снимается общий lock, удаляются `crm7-<backup_id>.id.lock` и временные файлы,
CLI завершается ненулевым кодом. SIGINT сохраняет штатную семантику
`KeyboardInterrupt` с тем же cleanup. Обработчик восстанавливается после
завершения операции и не действует на `verify`.

SIGKILL перехватить нельзя: `flock` снимается ОС, но `O_EXCL`-маркер
`crm7-<backup_id>.id.lock` и временные файлы могут остаться. Backup **не** является
атомарной операцией и не гарантирует полную очистку после SIGKILL. Перед ручным
удалением маркера убедитесь, что процесс backup действительно завершён
(по `ps`/`pgrep` и времени изменения файла): удаление маркера активного процесса
допустит параллельный backup того же `backup_id`. Удаляйте только маркер нужного
`backup_id`, не трогая маркеры других операций, затем повторите backup.

### Read-only диагностика Backup ID markers

Подкоманда `markers` выполняет **только чтение** и не удаляет, не меняет и не
создаёт файлы:

```bash
python deploy/backup_crm7.py markers \
  --db-backup-dir /srv/projects/crm7-demo/update-crm-7 \
  --secrets-dir /srv/protected-secrets/crm7-demo \
  --project-dir /srv/projects/crm7-demo
```

`--project-dir` необязателен: он нужен только для проверки состояния общего
project lock. Диагностика не захватывает lock на всё время проверки: она
кратко проверяет существующий lock-файл неблокирующим `flock` и сообщает
`project lock: available / busy / unavailable / unknown`. При `busy` не
производится ожидания, а результат помечается как потенциально несогласованный —
диагностика не мешает Backup/Restore и не утверждает orphan.

Пример вывода для пустого маркера без артефактов:

```text
backup_id: 20261119T163458Z-f78465f9
  marker: .../crm7-20261119T163458Z-f78465f9.id.lock
  mtime: 2026-11-19T16:34:58Z
  size: 0
  marker empty: True
  symlink: False
  sql: False
  secret bundle: False
  manifest: False
  status: incomplete
  verify: not available
marker presence does not prove that a Backup process is gone; no files were removed or modified
```

Для неполного набора (`status: incomplete`) и для валидного комплекта
(`status: complete`, `verify: passed`) формат тот же; при повреждённом manifest
выводится `status: invalid` и `verify: failed: ...`. Дополнительное состояние
`unknown` используется, когда метаданные прочитать нельзя.

Маркер **пустой** и не содержит PID, hostname или timestamp внутри — только
файловая метаинформация. Поэтому Diagnostics никогда не называет маркер
«orphaned», а непустой маркер помечается предупреждением без попытки интерпретировать
его содержимое. Диагностика не выводит секреты, SQL, содержимое bundle или
traceback и использует штатную функцию `verify()` без дублирования логики.

Ручное удаление маркера по-прежнему требует отдельного разрешения и явного
подтверждения: сначала убедитесь в отсутствии активного Backup/Restore и
удерживайте общий project lock; без этого возможна гонка (TOCTOU) с новым Backup.
Автоматического восстановления и автоудаления в этой версии **нет**.

Диагностика проверяет filesystem-записи через `lstat` и **не разыменовывает**
symlink: символьная ссылка на маркер, SQL, bundle или manifest не считается
обычным валидным артефактом. Такой комплект получает статус `unknown` с
предупреждением, а не `complete` и не `invalid`: подозрительная структура не
приравнивается к доказанной порче. `unknown` — это «недостаточно данных или
есть подозрение», `invalid` — результат реально проваленной проверки `verify()`.
Диагностика не читает содержимое symlink-целей.

Если путь общего project lock является symlink, он не открывается и не
блокируется: возвращается `unknown` с предупреждением. Состояния `busy`,
`unknown` и `unavailable` означают, что **snapshot не гарантированно
консистентен** — об этом CLI сообщает явно. `available` означает только
успешное кратковременное получение и освобождение probe-блока в один момент;
диагностика **не удерживает** project lock во время `verify()`, поэтому файлы
могут измениться и после проверки. Это best-effort чтение, а не транзакционный
снимок.

Exit code `0` означает лишь успешное выполнение диагностики, **а не то, что все
найденные backups валидны**. При наличии записей CLI печатает итог
(`markers found / complete / incomplete / invalid / unknown`) — именно его
следует анализировать. Команда ничего не удаляет и не меняет.

## Согласованное чтение конфигурации

`api/config/database.json` может измениться во время архивации. Перед чтением
`database.json` и `database.json.bak` backup захватывает **shared `flock`** на тот
же файл `api/config/database.json.lock`, который использует `SafeConfigFileWriter`
(exclusive `flock`). Блокировка удерживается до окончания чтения обоих файлов в
архив и освобождается при любом исключении. Если её получить нельзя, backup
завершается ошибкой `configuration lock is busy` и не продолжает работу без
защиты. Дополнительно сравниваются `st_ino`, `st_size` и `st_mtime_ns` файлов до и
после чтения: при изменении выдаётся `configuration changed during backup`.
Проверка `stat()` не заменяет блокировку. Сам lock-файл в архив не попадает.

На Windows нет POSIX shared `flock`; используется лучший доступный механизм
`msvcrt` и описанные выше проверки `stat()`. Гарантии POSIX там нет.

## Защита от повторного backup_id

Перед созданием компонентов backup эксклюзивно создаёт lock-файл
`crm7-<backup_id>.id.lock` в каталоге SQL-дампов (`O_CREAT | O_EXCL`) и удаляет
только его при завершении. Повторный или параллельный запуск с тем же `backup_id`
получает `backup_id is already in use` и ничего не изменяет. Два Backup одного
проекта с разными ID тоже не выполняются параллельно: общий project lock даёт
`backup lock is busy`. Manifest пишется последним и служит маркером завершённого
комплекта: если какой-то шаг упал, manifest не появляется. Неполные файлы текущей
операции удаляются; предыдущие успешные комплекты не затрагиваются.

## SQL dump

Пароль не передаётся в аргументах `mysqldump`/`mariadb-dump` и не попадает в
команду `docker compose`. Внутри контейнера он остаётся в окружении:
`export MYSQL_PWD="$MARIADB_PASSWORD"` перед вызовом дампа; argv содержит только
`-u"$MARIADB_USER"` и имя БД. Остаток риска: `MYSQL_PWD` виден процессам с
доступом к окружению дампа внутри контейнера; переменная считается устаревшей,
но поддерживается MariaDB/MySQL. `--single-transaction` не даёт консистентности
для таблиц MyISAM.

## Preflight и ключи

Preflight читает **только имена** переменных из `api/config/.env` и проверяет
**наличие** `DB_CONFIG_KEY` и `APP_KEY`. Это **presence-only check**: он не
доказывает, что ключи валидны, не пусты по смыслу и что текущий `DB_CONFIG_KEY`
вообще расшифрует существующий `database.json`. Ключи не генерируются и не
выводятся. Проверка пригодности ключа без раскрытия секретов в этой версии не
выполняется и может быть спроектирована отдельно.

## Verify

Проверка читает manifest, сверяет `format_version`, обязательные поля и типы,
`backup_id` с именами manifest, SQL-дампа и bundle, отсутствие абсолютных путей и
`..`, размеры, SHA-256, а также состав и типы членов tar-архива. Архив не
распаковывается и не выполняется. SHA-256 обнаруживает повреждение относительно
manifest, но **не подтверждает подлинность** комплекта и не проверяет
расшифровку или успешность восстановления.

## Важно

- Обычный `tar` **не шифрует** содержимое. Архив и все его члены имеют режим
  `0600`, но этого недостаточно при копировании в незащищённое внешнее
  хранилище: secret bundle должен храниться отдельно от SQL-дампов, в защищённом
  месте.
- `DB_CONFIG_KEY` и `APP_KEY` сохраняются без изменений. Потеря `DB_CONFIG_KEY`
  означает, что старый зашифрованный `database.json` нельзя расшифровать.
- `0600` не заменяет защищённое внешнее хранение и не защищает секреты при
  компрометации PHP-процесса.
- Manifest не содержит паролей, ключей, DSN или содержимого `.env`/`database.json`.
- Наличие SQL-файла не означает, что backup проверен: используйте `verify`.
- Команда backup не выполняет восстановление и не подключается к реальной БД.
  Для восстановления используется отдельная утилита `deploy/restore_crm7.py`
  (не интегрирована в updater).
- Команда backup не удаляет предыдущие комплекты; ротация SQL-дампов updater
  остаётся без изменений.


### Порядок операций

1. `verify()` из `backup_crm7.py` (алгоритм не дублируется) и чтение manifest
   **один раз**.
2. Создание **согласованного снимка** в staging `0700` под `--secrets-dir`:
   копии manifest, SQL dump и secret bundle с уникальными именами и режимом
   `0600`. Для каждой копии сверяются размер и SHA-256 с одним и тем же manifest;
   исходный файл проверяется на symlink и на изменение `inode/size/mtime/nlink`
   до и после чтения. После снимка исходные файлы **не открываются**;
   manifest не перечитывается. SHA-256 — проверка целостности, а **не**
   криптографическая подлинность: без подписи или доверенного хранилища
   согласованная подмена комплекта не обнаруживается.
3. Проверка целевого проекта: каталог `api/config` существует, не symlink и не
   доступен всем; compose-файл проекта побайтово совпадает с копией из снимка;
   существующий корневой `.env` и `database.json` указывают на ту же БД.
4. Извлечение **только из staging-копии bundle** утверждённых компонентов
   (`.env`, `api/config/.env`, compose, `database.json`, `.bak` при наличии).
   `tar.extractall()` не используется; symlink/hardlink/специальные члены и `..`
   отклоняются.
5. Проверка ключей (`APP_KEY`, `DB_CONFIG_KEY`) и формата конфигураций.
   Если `database.json` зашифрован (`dbcfg:v1:`), расшифровка проверяется
   через реальный `DatabaseConfigCrypto` локальным PHP: в лог попадает только
   `OK`/FAIL или SHA-256 пароля для сравнения. Ключи не генерируются.
6. **Идентификация целевой БД.** Требуется ровно один контейнер сервиса `db`
   (`docker compose ps -q db`); пустой результат или несколько ID — отказ.
   Проверяются `MARIADB_DATABASE`/`MARIADB_USER`, Compose-проект и ID контейнера
   (`docker inspect`), ровно один mount с destination `/var/lib/mysql`,
   `DB_HOST`/`DB_PORT` из PHP `.env` (обязательны), согласование
   `DB_NAME`/`DB_USER`/`DB_PASS` между Compose, PHP `.env` и расшифрованным
   `database.json`, `healthcheck.sh --connect`. Внутри **php-контейнера**
   проверяются подключение, `SELECT DATABASE()`, `CURRENT_USER()`, таблица
   `users` и активный администратор. `target_id` = SHA-256 от
   `compose_project|container_id|volume`. Несовпадение тома с backup не ошибка:
   подтверждается целевая среда.
7. Захватывается канонический общий lock (см. ниже) и эксклюзивные lock’и
   проекта/`backup_id`. **Повторно** вычисляется `target_id` из свежих результатов
   Docker-команд и сравнивается с `--confirm-target`; при изменении — отказ до
   каких-либо изменений.
8. Создаются rollback-копии (0600) с сохранением `uid/gid/mode`, затем файлы
   применяются по одному; после каждого изменения в журнал пишется фаза
   `config_applied:<путь>` и список фактически изменённых файлов.
9. Проверки `docker compose config -q`, подключения/структуры БД и совпадения
   хешей конфигурации внутри php-контейнера.
10. Перед SQL import состояние журнала консервативно меняется на
    `manual_recovery_required`, `sql_outcome=running`; `target_id` и хеш
    staging-SQL проверяются снова. Импорт идёт только из staging-копии через
    `docker compose exec -T db`, пароль передаётся через `MYSQL_PWD` в окружении
    контейнера; stderr дочернего процесса перехватывается и не печатается —
    в логе только код завершения.
11. Постпроверки: PHP/БД внутри контейнера, хеши конфигурации, health
    `/api/v2/health` (`status=ok`, `database=ok`). При успехе в
    `restore-state.json` фиксируется `status=complete`, staging очищается.

Миграции не запускаются автоматически. SQL import не атомарен.

### Dry-run

Dry-run выполняет шаги 1–6, печатает `backup_id`, проект, БД и host/port (без
пароля), Compose-проект, container ID, том, `target_id`, компоненты и пройденные
проверки. Он не меняет файлы проекта, БД, Compose и права; staging под
`--secrets-dir` удаляется. SQL import и замена конфигурации не выполняются.

### Прерывание, SIGKILL и журнал операции

Обработчики SIGINT/SIGTERM устанавливаются **до создания staging**. Повторные
сигналы во время rollback подавляются и фиксируются, не прерывая откат.

- до SQL import: любая ошибка/прерывание вызывает откат только фактически
  изменённых файлов. Если все они восстановлены и журнал записан —
  `status=rolled_back`, staging удаляется. При ошибке отката (включая chown/chmod)
  — `status=manual_recovery_required`, `rollback_failures` заполняется, staging
  и копии сохраняются;
- во время и после SQL import откат конфигурации **не выполняется**;
  `status=manual_recovery_required`, `sql_outcome=unknown`/`failed`/`running`, staging и
  rollback-копии сохраняются всегда, в том числе при ошибке записи журнала;
- при SIGKILL обработчик не вызывается, но фаза пишется до каждого
  деструктивного шага: `rollback_prepared`, `config_apply_in_progress`,
  `config_apply_attempt:<путь>`, `config_applied:<путь>`,
  `post_apply_in_progress`, `post_apply_ok`, `sql_import_starting`,
  `sql_import_failed`, `sql_import_unknown`, `sql_import_finished`,
  `post_checks_in_progress`, `post_checks_failed`, `finalization_failed`,
  `complete`.


`restore-state.json` (0600, atomic replace) — **единственный** журнал текущей
операции, рядом с SQL-дампами. Обязательные поля: `operation_id`, `backup_id`,
`status`, `phase`, `updated_at`. Остальные: `completed_steps`, `files_intended`,
`files_attempted`, `files_changed`, `files_restored`, `rollback_copies`,
`rollback_failures`, `sql_outcome`, `sql_exit_code`, `sql_started_at`,
`sql_finished_at`, `staging_dir`, инструкцию. Секретов в журнале нет. Разница
между полями принципиальна: `files_attempted` фиксируется **до** изменения файла
и говорит, что файл мог быть затронут; `files_changed` — подтверждённое
изменение; `files_restored` — успешный откат.

Статусы операции однозначны:

- `running` — операция начата, результат неизвестен;
- `complete` — SQL import завершился кодом 0 и все постпроверки прошли;
- `rolled_back` — до SQL import все затронутые файлы доказательно восстановлены;
- `manual_recovery_required` — результат неоднозначен (в том числе после частичного
  SQL import) либо откат не удался полностью;
- `failed_before_changes` — отказ до изменения конфигурации и запуска SQL.

Каждый элемент `rollback_copies` содержит: `file` (исходный относительный путь),
`name` и `path` (rollback-копия), `target`, `existed`, `mode`, `uid`, `gid`,
`size`, `sha256` и `state` (`saved` / `restored` / `restore_failed`). Перед
использованием копии её размер и SHA-256 проверяются.

### История последовательных restore

В начале **реального** restore (не dry-run) CLI печатает `operation_id`. Сначала
выполняются read-only проверки комплекта, затем захватываются общий project lock
и restore ID lock, и **только после этого** предыдущий журнал архивируется и
создаётся новый. Если lock занят, журнал, история и staging не изменяются.
Предыдущий журнал переносится в `restore-history/restore-<operation_id>.json`
через подготовленную копию с правами `0600` и атомарный `os.replace`. Каталог
истории создаётся с правами `0700` и синхронизируется fsync родителя; архивные
файлы — `0600`. История никогда не перезаписывается: если запись с таким
`operation_id` уже есть, новый restore отвергается до изменений. Некорректный
или неархивируемый предыдущий журнал также блокирует restore.

При переносе между каталогами на POSIX fsync получают **оба** родителя: сначала
каталог истории, затем каталог дампов. Если fsync или публикация нового журнала
завершилась ошибкой, предыдущий журнал возвращается на место с fsync обоих
родителей. После успешного `os.replace(target, current)` отдельной копии в
`restore-history` уже нет — архивная запись перемещена обратно в `current`.
Если последующий fsync завершился ошибкой, состояние durability считается
неопределённым: операция завершается явной ошибкой manual recovery с указанием
пути к файлу `current`, который требует ручной проверки. Если rename отката не
удался, архивная копия остаётся в `restore-history`. POSIX-ошибки fsync не
подавляются. На Windows
fsync каталогов недоступен — документированное платформенное ограничение.
`restore-complete.json` больше не создаётся и не является источником истины;
старый файл с этим именем, оставшийся от прежних версий, можно игнорировать —
статус определяется только из `restore-state.json`.

Команда `python deploy/restore_crm7.py --db-backup-dir <каталог> status` только
читает журнал и печатает `operation_id`, `backup_id`, `status`, `phase`, SQL
outcome, путь к журналу и путь к истории. Dry-run не создаёт журнал операции.

### SQL state machine

Единственное поле `sql_outcome` принимает значения `not_started`, `running`,
`failed`, `succeeded`, `unknown`; дополнительно `sql_exit_code` (integer или
null), `sql_started_at` и `sql_finished_at`.

| Событие | sql_outcome | sql_exit_code |
|---|---|---|
| Проверки до запуска команды | `not_started` | null |
| Непосредственно перед запуском | `running` | null |
| Exit code 0 | `succeeded` | 0 |
| Ненулевой exit code | `failed` | фактический код |
| Исключение/сигнал после запуска | `unknown` | null |

Outcome и exit code фиксируются **одной atomic-записью**: если процесс погиб
после получения exit code, но до записи, журнал остаётся в `running` и при
 чтении трактуется как неопределённый результат. `running`, `unknown` и `failed`
никогда не дают `status=complete`. `succeeded` означает только получение exit
code 0, а не корректность содержимого БД; корректность дополнительно проверяется
постпроверками.

**Ручное восстановление при `manual_recovery_required`:** скопировать файлы из
`<staging>/rollback/` обратно в проект. Фактические имена: корневой `.env` →
`__env`, `api/config/.env` → `api__config__.env`, `api/config/database.json` →
`api__config__database.json`, `api/config/database.json.bak` →
`api__config__database.json.bak` (точка перед `bak` сохраняется). Для каждого
элемента `rollback_copies` в журнале есть исходный путь, режим `mode`, `uid`,
`gid` и `existed`; проверьте `size`/`sha256` копии перед копированием. Для файлов
с `existed=false` rollback-копии нет — такой файл нужно удалить. Восстановление
владельца через `chown` требует root (или совпадения пользователя); без этих прав
автоматический откат переводит операцию в `manual_recovery_required`. ACL не
сохраняются и должны восстанавливаться отдельно.

### Общий lock

Канонический путь не зависит от `--backup-dir`, `--db-backup-dir` и CWD:

```
<project>/update-crm-7/update.lock
```

`crm7_updater.acquire_lock()`, `backup` и `restore` используют `update_lock.py`
и один и тот же `flock(LOCK_EX|LOCK_NB)`. Updater, запускаемый stand-updater без
`--backup-dir`, и restore с любым каталогом дампов блокируют один физический
файл. `deploy/release_crm7.py` и `deploy/deploy_crm7.py` захватывают тот же файл
в удалённом скрипте через `flock -n` до изменения `.env` и контейнеров; при
занятом lock релиз/деплой завершаются ошибкой. Дополнительно restore создаёт
`crm7-restore-project-*.lock` и
`crm7-restore-id-*.lock` (удаляются только собственные). Lock-файл не удаляется
при завершении. После SIGKILL `flock` снимается ОС, но проект может остаться
частично восстановленным — перед повторным restore обязательны новый dry-run и
явное подтверждение.

На Windows POSIX `flock` отсутствует: updater, restore и release **отказываются**
продолжать критические операции понятной ошибкой вместо тихой работы без
блокировки; проверки прав `0750`/`0600` также не имеют силы. Основной целевой
deployment — Linux.

### Условия успешного восстановления

Прохождение `verify` или dry-run **не гарантирует** успешного восстановления.
SHA-256 подтверждает только целостность относительно manifest, но не подлинность
и не пригодность данных. Проверки не покрывают применение миграций, корректность
дампа и поведение после перезапуска приложения.

## Native backup/restore без Docker

Для Linux VPS без Docker добавлен отдельный backend
[`docs/backup-restore-native.md`](backup-restore-native.md) и утилита
`deploy/native_backup_restore.py`. Он использует локальные `mariadb-dump`/`mysql`,
сохраняет инварианты блокировок, target identity и подтверждения, а также
добавляет ARCHIVE `api/storage/`. Docker-скрипты в этом этапе не изменялись.
Cross-backend restore намеренно не поддерживается.
