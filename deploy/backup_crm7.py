#!/usr/bin/env python3
"""CRM7: согласованный backup БД и защищённой конфигурации.

SQL-дамп и secret bundle создаются в РАЗНЫЕ директории. Обычный tar НЕ шифрует
содержимое: secret bundle защищён режимом 0600 и должен храниться отдельно.
Ключи DB_CONFIG_KEY/APP_KEY не генерируются и не выводятся.
"""
from __future__ import annotations

import argparse
import contextlib
import datetime as dt
import hashlib
import io
import json
import os
import pathlib
import re
import shutil
import signal
import subprocess
import sys
import tarfile
import tempfile
import uuid

import update_lock as lock_api

from update_lock import (
    LockBusy, fsync_directory, require_lock, run_cleanup, update_lock,
)

try:
    import fcntl
except ImportError:  # Windows
    fcntl = None

MANIFEST_FORMAT = 1
REQUIRED_ENV_KEYS = ("DB_CONFIG_KEY", "APP_KEY")
REQUIRED_CONFIG_FILES = (
    pathlib.PurePosixPath(".env"),
    pathlib.PurePosixPath("api/config/.env"),
)
OPTIONAL_CONFIG_FILES = (
    pathlib.PurePosixPath("api/config/database.json"),
    pathlib.PurePosixPath("api/config/database.json.bak"),
)
COMPOSE_NAMES = ("compose.yaml", "compose.yml", "docker-compose.yml", "docker-compose.yaml")
EXCLUDED_NAME_PARTS = (".cfg-", ".tmp", ".lock")
MARKER_RE = re.compile(r"^crm7-([0-9A-Za-zT\-]+)\.id\.lock$")


def _marker_path(db_dir: pathlib.Path, backup_id: str) -> pathlib.Path:
    return db_dir / f"crm7-{backup_id}.id.lock"


def _format_mtime(timestamp: float) -> str:
    return dt.datetime.fromtimestamp(timestamp, dt.timezone.utc).isoformat(
        timespec="seconds"
    ).replace("+00:00", "Z")


class BackupError(RuntimeError):
    """Управляемая ошибка backup/verify."""


class BackupInterrupted(BackupError):
    """SIGTERM во время реального backup; cleanup выполняется через finally."""


@contextlib.contextmanager
def _interrupt_guard():
    """Преобразовать SIGTERM в исключение только на время реального backup.

    SIGINT намеренно не трогается: штатный KeyboardInterrupt уже проходит
    через finally и сохраняет существующую семантику cleanup.
    """
    if not hasattr(signal, "SIGTERM"):
        yield
        return

    def handler(signum, frame):
        raise BackupInterrupted(f"interrupted by signal {signum}")

    try:
        previous = signal.signal(signal.SIGTERM, handler)
    except (ValueError, OSError):
        previous = None

    try:
        yield
    finally:
        if previous is not None:
            run_cleanup(
                "SIGTERM handler restore",
                lambda: signal.signal(signal.SIGTERM, previous),
            )


def _utc_now() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def new_backup_id() -> str:
    stamp = dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    return f"{stamp}-{uuid.uuid4().hex[:8]}"


def _read_env_keys(env_file: pathlib.Path) -> set[str]:
    """Только имена переменных: значения не читаются в лог и не возвращаются."""
    keys: set[str] = set()

    for line in env_file.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()

        if not stripped or stripped.startswith("#") or "=" not in stripped:
            continue

        keys.add(stripped.split("=", 1)[0].strip())

    return keys


def _compose_file(project: pathlib.Path) -> pathlib.Path:
    for name in COMPOSE_NAMES:
        candidate = project / name

        if candidate.is_file():
            return candidate

    raise BackupError("compose file not found")


def _app_version(project: pathlib.Path) -> str | None:
    package = project / "frontend" / "package.json"

    if package.is_file():
        try:
            return str(json.loads(package.read_text(encoding="utf-8"))["version"])
        except (KeyError, ValueError, OSError):
            return None

    return None


def _sha256(path: pathlib.Path) -> str:
    digest = hashlib.sha256()

    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)

    return digest.hexdigest()


def _unique_temp(directory: pathlib.Path, name: str) -> tuple[int, pathlib.Path]:
    """Уникальный файл 0600 в целевом каталоге (не в публичном /tmp)."""
    descriptor, raw_path = tempfile.mkstemp(
        prefix=f".{name}.", suffix=".tmp", dir=str(directory)
    )
    os.chmod(raw_path, 0o600)
    return descriptor, pathlib.Path(raw_path)


def _atomic_bytes(path: pathlib.Path, payload: bytes) -> None:
    """Запись 0600 в уникальном temp, fsync и atomарный rename."""
    descriptor, temporary = _unique_temp(path.parent, path.name)

    try:
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
    except Exception:
        run_cleanup("atomic write temp", lambda: temporary.unlink(missing_ok=True))
        raise

    try:
        os.replace(temporary, path)
    except Exception:
        run_cleanup("atomic replace temp", lambda: temporary.unlink(missing_ok=True))
        raise

    os.chmod(path, 0o600)
    fsync_directory(path.parent)


def _safe_source(project: pathlib.Path, relative: pathlib.PurePosixPath) -> pathlib.Path:
    source = project.joinpath(*relative.parts)

    if source.is_symlink():
        raise BackupError("symlink in config source")
    if relative.is_absolute() or ".." in relative.parts:
        raise BackupError("unsafe config source path")

    resolved = source.resolve()

    if not resolved.is_relative_to(project.resolve()):
        raise BackupError("source path escapes project")

    if source.exists() and source.stat().st_nlink > 1:
        raise BackupError("hardlink in config source")

    return source


def _sql_dump(
    project: pathlib.Path,
    target: pathlib.Path,
    runner,
) -> None:
    """SQL-дамп через Docker Compose; runner нужен для тестов без Docker."""
    # Пароль остаётся в окружении контейнера: MYSQL_PWD читается дампом,
    # но не попадает в argv mysqldump, docker или shell command string.
    command = [
        "docker", "compose", "exec", "-T", "db", "sh", "-lc",
        'dump=$(command -v mariadb-dump || command -v mysqldump) || exit 127; '
        'export MYSQL_PWD="$MARIADB_PASSWORD"; '
        'exec "$dump" --single-transaction --quick '
        '-u"$MARIADB_USER" "$MARIADB_DATABASE"',
    ]
    payload = runner(command, project)

    if not isinstance(payload, (bytes, bytearray)) or not payload:
        raise BackupError("empty SQL dump")
    if not payload.lstrip().startswith((b"--", b"/*", b"CREATE", b"DROP")):
        raise BackupError("unexpected SQL dump header")

    _atomic_bytes(target, bytes(payload))


@contextlib.contextmanager
def _shared_config_lock(lock_path: pathlib.Path | None):
    """Раздельная блокировка lock-файла SafeConfigFileWriter."""
    if lock_path is None:
        yield
        return

    with lock_path.open("a+b") as handle:
        os.chmod(lock_path, 0o600)

        if fcntl is not None:
            try:
                fcntl.flock(handle.fileno(), fcntl.LOCK_SH | fcntl.LOCK_NB)
            except OSError as error:
                raise BackupError("configuration lock is busy") from error

            try:
                yield
            finally:
                run_cleanup(
                    "config lock release",
                    lambda: fcntl.flock(handle.fileno(), fcntl.LOCK_UN),
                )

            return

        # Windows: msvcrt не поддерживает shared lock — используется лучший доступный вариант.
        try:
            import msvcrt
            msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
        except Exception as error:
            raise BackupError("configuration lock is busy") from error

        try:
            yield
        finally:
            run_cleanup(
                "config lock release",
                lambda: msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1),
            )


def _stat_signature(path: pathlib.Path) -> tuple[int, int, int] | None:
    if not path.exists():
        return None
    info = path.stat()
    return (info.st_ino, info.st_size, info.st_mtime_ns)


@contextlib.contextmanager
def _backup_id_claim(db_dir: pathlib.Path, backup_id: str):
    """Эксклюзивное право на backup_id; lock удаляется только владельцем."""
    marker = _marker_path(db_dir, backup_id)

    try:
        descriptor = os.open(marker, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    except FileExistsError as error:
        mtime = ""
        try:
            mtime = f", mtime={_format_mtime(marker.stat().st_mtime)}"
        except OSError:
            pass
        raise BackupError(
            f"backup_id is already in use: marker exists at {marker}{mtime} "
            f"for backup_id {backup_id!r}. "
            "A marker alone does not prove that Backup is no longer active; "
            "run read-only diagnostics: "
            "python deploy/backup_crm7.py markers --db-backup-dir <dir> "
            "--secrets-dir <dir> (project-dir optional for lock status)"
        ) from error

    os.close(descriptor)

    try:
        yield
    finally:
        run_cleanup(
            "backup ID marker",
            lambda: marker.unlink(missing_ok=True),
        )


def _validate_tar_members(members, expected_names: list[str] | None = None) -> list[str]:
    """Только обычные файлы 0600 с безопасными относительными именами."""
    names = [member.name for member in members]

    if expected_names is not None and sorted(names) != sorted(expected_names):
        raise BackupError("bundle contents mismatch")

    for member in members:
        member_path = pathlib.PurePosixPath(member.name)

        if member_path.is_absolute() or ".." in member_path.parts:
            raise BackupError("unsafe bundle member")
        if (not member.isfile() or member.issym() or member.islnk()
                or member.isdev() or member.isfifo()):
            raise BackupError("unsafe bundle member type")
        if (member.mode & 0o777) != 0o600:
            raise BackupError("unsafe bundle member mode")

    return sorted(names)


def _secret_bundle(
    project: pathlib.Path,
    target: pathlib.Path,
    entries: list[tuple[pathlib.PurePosixPath, pathlib.Path]],
    lock_path: pathlib.Path | None = None,
    watched_paths: tuple[pathlib.Path, ...] = (),
) -> list[str]:
    descriptor, temporary = _unique_temp(target.parent, target.name)
    names: list[str] = []

    with _shared_config_lock(lock_path):
        before = {path: _stat_signature(path) for path in watched_paths}

        try:
            with os.fdopen(descriptor, "wb") as handle:
                with tarfile.open(fileobj=handle, mode="w") as archive:
                    for relative, source in entries:
                        name = relative.as_posix()

                        if name.startswith("/") or ".." in relative.parts:
                            raise BackupError("unsafe bundle path")
                        if any(part in name for part in EXCLUDED_NAME_PARTS):
                            raise BackupError("runtime file in bundle")
                        if source.is_symlink() or not source.is_file():
                            raise BackupError("unsafe bundle source")

                        info = archive.gettarinfo(str(source), arcname=name)

                        if not info.isfile() or info.issym() or info.islnk():
                            raise BackupError("unsafe file type in bundle")

                        info.mode = 0o600

                        with source.open("rb") as source_handle:
                            archive.addfile(info, source_handle)

                        names.append(name)

                handle.flush()
                os.fsync(handle.fileno())

            changed = [
                str(path) for path in watched_paths
                if _stat_signature(path) != before[path]
            ]

            if changed:
                raise BackupError("configuration changed during backup")
        except Exception:
            run_cleanup("secret bundle write temp", lambda: temporary.unlink(missing_ok=True))
            raise

    try:
        os.replace(temporary, target)
    except Exception:
        run_cleanup("secret bundle publish temp", lambda: temporary.unlink(missing_ok=True))
        raise

    os.chmod(target, 0o600)

    with tarfile.open(target, mode="r") as archive:
        members = archive.getmembers()

    try:
        return _validate_tar_members(members, names)
    except BackupError:
        run_cleanup("secret bundle validation", lambda: target.unlink(missing_ok=True))
        raise


def preflight(
    project: pathlib.Path,
    db_dir: pathlib.Path,
    secrets_dir: pathlib.Path,
    require_docker: bool = True,
) -> tuple[pathlib.Path, list[tuple[pathlib.PurePosixPath, pathlib.Path]], list[str]]:
    project = project.resolve()
    db_dir = db_dir.resolve()
    secrets_dir = secrets_dir.resolve()

    if not project.is_dir():
        raise BackupError("project dir not found")
    if db_dir == secrets_dir or secrets_dir.is_relative_to(db_dir) or db_dir.is_relative_to(secrets_dir):
        raise BackupError("secret destination must be separate from DB backup dir")
    if require_docker and shutil.which("docker") is None:
        raise BackupError("docker is not installed or not in PATH")

    compose = _safe_source(project, pathlib.PurePosixPath(_compose_file(project).name))
    app_env = _safe_source(project, pathlib.PurePosixPath("api/config/.env"))

    if not app_env.is_file():
        raise BackupError("api/config/.env not found")

    keys = _read_env_keys(app_env)
    missing_keys = [key for key in REQUIRED_ENV_KEYS if key not in keys]

    if missing_keys:
        raise BackupError("missing env key: " + ", ".join(missing_keys))

    entries: list[tuple[pathlib.PurePosixPath, pathlib.Path]] = []

    for relative in REQUIRED_CONFIG_FILES:
        source = _safe_source(project, relative)

        if not source.is_file():
            raise BackupError("required config file not found: " + relative.as_posix())

        entries.append((relative, source))

    entries.append((pathlib.PurePosixPath(compose.name), compose))

    missing_optional: list[str] = []

    for relative in OPTIONAL_CONFIG_FILES:
        source = _safe_source(project, relative)

        if source.is_file():
            entries.append((relative, source))
        else:
            missing_optional.append(relative.as_posix())

    db_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    secrets_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(db_dir, 0o700)
    os.chmod(secrets_dir, 0o700)

    return compose, entries, missing_optional


def create_backup(
    project: pathlib.Path,
    db_dir: pathlib.Path,
    secrets_dir: pathlib.Path,
    *,
    runner=None,
    backup_id: str | None = None,
    require_docker: bool = True,
) -> pathlib.Path:
    if runner is None:
        def runner(command: list[str], cwd: pathlib.Path) -> bytes:
            print("+", " ".join(command[:5]), "...")
            return subprocess.check_output(command, cwd=cwd)

    project = pathlib.Path(project).resolve()
    db_dir = pathlib.Path(db_dir).resolve()
    secrets_dir = pathlib.Path(secrets_dir).resolve()

    # Fail-closed: реальный backup без POSIX flock не выполняется.
    require_lock()

    # Порядок захвата: update.lock (EX) -> backup ID claim (O_EXCL)
    # -> database.json.lock (SH) -> артефакты -> освобождение в обратном порядке.
    with _interrupt_guard():
        with update_lock(project):
            compose, entries, missing_optional = preflight(
                project, db_dir, secrets_dir, require_docker
            )
            backup_id = backup_id or new_backup_id()

            if not re.fullmatch(r"[0-9A-Za-zT\-]+", backup_id):
                raise BackupError("invalid backup_id")

            sql_path = db_dir / f"crm7-{backup_id}.sql"
            bundle_path = secrets_dir / f"crm7-{backup_id}.secrets.tar"
            manifest_path = db_dir / f"crm7-{backup_id}.manifest.json"

            # тот же lock-файл, что использует SafeConfigFileWriter для database.json
            config_lock = project / "api" / "config" / "database.json.lock"
            watched = tuple(
                source for relative, source in entries if relative in OPTIONAL_CONFIG_FILES
            )

            with _backup_id_claim(db_dir, backup_id):
                existing = [
                    str(path) for path in (sql_path, bundle_path, manifest_path)
                    if path.exists()
                ]
                if existing:
                    raise BackupError(
                        f"backup_id is already in use: artifacts exist for backup_id "
                        f"{backup_id!r} ({', '.join(existing)}). "
                        "Existing backups are never overwritten; run read-only "
                        "diagnostics with the 'markers' subcommand before considering "
                        "any manual action"
                    )

                _sql_dump(project, sql_path, runner)
                members = _secret_bundle(
                    project, bundle_path, entries,
                    lock_path=config_lock, watched_paths=watched,
                )

                manifest = {
                    "format_version": MANIFEST_FORMAT,
                    "backup_id": backup_id,
                    "created_at": _utc_now(),
                    "crm7_version": _app_version(project),
                    "database_engine": "mariadb",
                    "database_schema_version": None,
                    "sql_dump": {
                        "path": sql_path.name,
                        "size": sql_path.stat().st_size,
                        "sha256": _sha256(sql_path),
                    },
                    "secret_bundle": {
                        "path": bundle_path.name,
                        "size": bundle_path.stat().st_size,
                        "sha256": _sha256(bundle_path),
                        "members": members,
                        "encrypted": False,
                    },
                    "included_components": list(members),
                    "missing_optional_components": missing_optional,
                }

                _atomic_bytes(
                    manifest_path,
                    (json.dumps(manifest, indent=2, ensure_ascii=False) + "\n").encode("utf-8"),
                )

    print(f"backup ready: {manifest_path}")
    return manifest_path


def verify(manifest_path: pathlib.Path, secrets_dir: pathlib.Path) -> None:
    """Проверка целостности и структуры. Не проверяет расшифровку и restore."""
    manifest_path = pathlib.Path(manifest_path).resolve()
    secrets_dir = pathlib.Path(secrets_dir).resolve()

    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise BackupError("cannot read manifest") from error

    if not isinstance(manifest, dict):
        raise BackupError("invalid manifest")

    version = manifest.get("format_version")
    if (not isinstance(version, int) or isinstance(version, bool)
            or version != MANIFEST_FORMAT):
        raise BackupError("unsupported manifest format")

    backup_id = manifest.get("backup_id")
    if not isinstance(backup_id, str) or not re.fullmatch(r"[0-9A-Za-zT\-]+", backup_id):
        raise BackupError("invalid backup_id")

    if manifest_path.name != f"crm7-{backup_id}.manifest.json":
        raise BackupError("manifest name does not match backup_id")

    for key in ("created_at", "database_engine"):
        value = manifest.get(key)

        if not isinstance(value, str) or not value:
            raise BackupError("invalid manifest field: " + key)

    for key in ("included_components", "missing_optional_components"):
        if not isinstance(manifest.get(key), list):
            raise BackupError("invalid manifest field: " + key)

    if "crm7_version" not in manifest:
        raise BackupError("invalid manifest field: crm7_version")
    crm7_version = manifest["crm7_version"]

    if crm7_version is not None and not isinstance(crm7_version, str):
        raise BackupError("invalid manifest field: crm7_version")

    if "database_schema_version" not in manifest:
        raise BackupError("invalid manifest field: database_schema_version")
    schema_version = manifest["database_schema_version"]

    if (schema_version is not None
            and (isinstance(schema_version, bool)
                 or not isinstance(schema_version, (str, int)))):
        raise BackupError("invalid manifest field: database_schema_version")

    base = manifest_path.parent

    def component(key: str, filename: str, directory: pathlib.Path) -> pathlib.Path:
        part = manifest.get(key)

        if not isinstance(part, dict):
            raise BackupError("missing component: " + key)

        raw = part.get("path")

        if not isinstance(raw, str) or not raw or pathlib.PurePosixPath(raw).name != raw:
            raise BackupError("unsafe component path: " + key)
        if raw != filename:
            raise BackupError("component name does not match backup_id: " + key)

        size = part.get("size")

        if not isinstance(size, int) or isinstance(size, bool) or size < 0:
            raise BackupError("invalid component size: " + key)

        digest = part.get("sha256")

        if (not isinstance(digest, str) or len(digest) != 64
                or any(char not in "0123456789abcdef" for char in digest)):
            raise BackupError("invalid component checksum: " + key)

        path = (directory / raw).resolve()

        if not path.is_file():
            raise BackupError("component missing: " + key)
        if path.stat().st_size != size:
            raise BackupError("component size mismatch: " + key)
        if _sha256(path) != digest:
            raise BackupError("checksum mismatch: " + key)

        return path

    component("sql_dump", f"crm7-{backup_id}.sql", base)
    bundle_path = component("secret_bundle", f"crm7-{backup_id}.secrets.tar", secrets_dir)

    bundle = manifest["secret_bundle"]
    members = bundle.get("members")

    if not isinstance(members, list) or not all(isinstance(m, str) and m for m in members):
        raise BackupError("invalid bundle member list")
    if not isinstance(bundle.get("encrypted"), bool):
        raise BackupError("invalid manifest field: encrypted")

    try:
        with tarfile.open(bundle_path, mode="r") as archive:
            actual = archive.getmembers()
    except tarfile.TarError as error:
        raise BackupError("invalid secret bundle") from error

    _validate_tar_members(actual, members)

    print("backup verified")


def _probe_project_lock(project: pathlib.Path | None) -> str:
    """Только проверить состояние общего lock, не создавая файлов.

    Возвращает available / busy / unavailable / unknown. При busy не ждёт и не
    мешает production: transient flock снимается сразу после проверки.
    """
    if project is None:
        return "unknown"

    path = lock_api.lock_path(project)

    # Симлинк не разыменовываем: иначе можно заблокировать внешний файл.
    try:
        if path.is_symlink():
            return "unknown"
        if not path.exists():
            return "unknown"
    except OSError:
        return "unavailable"
    if lock_api.fcntl is None:
        return "unavailable"

    try:
        descriptor = os.open(path, os.O_RDONLY)
    except OSError:
        return "unavailable"

    try:
        try:
            lock_api.fcntl.flock(descriptor, lock_api.fcntl.LOCK_EX | lock_api.fcntl.LOCK_NB)
        except OSError:
            return "busy"
        run_cleanup("lock probe unlock", lambda: lock_api.fcntl.flock(
            descriptor, lock_api.fcntl.LOCK_UN
        ))
        return "available"
    finally:
        run_cleanup("lock probe descriptor", lambda: os.close(descriptor))


def collect_marker_diagnostics(
    db_dir: pathlib.Path,
    secrets_dir: pathlib.Path,
    project: pathlib.Path | None = None,
) -> dict:
    """Собрать read-only диагностику ID-маркеров. Ничего не удаляет и не создаёт."""
    db_dir = pathlib.Path(db_dir).resolve()
    secrets_dir = pathlib.Path(secrets_dir).resolve()
    result: dict = {
        "db_dir": str(db_dir),
        "secrets_dir": str(secrets_dir),
        "lock_status": _probe_project_lock(project),
        "markers": [],
        "warnings": [],
        "error": None,
    }

    if project is not None:
        try:
            if lock_api.lock_path(project).is_symlink():
                result["warnings"].append(
                    "project lock path is a symlink; not opened or locked"
                )
        except OSError as error:
            result["warnings"].append(
                f"project lock path stat failed: {type(error).__name__}"
            )

    try:
        candidates = sorted(db_dir.iterdir(), key=lambda item: item.name)
    except OSError as error:
        result["error"] = f"cannot list backup directory: {type(error).__name__}"
        return result

    for marker in candidates:
        match = MARKER_RE.fullmatch(marker.name)
        if match is None:
            continue

        backup_id = match.group(1)
        entry: dict = {
            "backup_id": backup_id,
            "marker_path": str(marker),
            "marker_mtime": None,
            "marker_size": None,
            "marker_empty": None,
            "marker_is_symlink": None,
            "sql_present": None,
            "bundle_present": None,
            "manifest_present": None,
            "sql_is_symlink": None,
            "bundle_is_symlink": None,
            "manifest_is_symlink": None,
            "status": "unknown",
            "verify": "not available",
            "warnings": [],
        }
        result["markers"].append(entry)

        suspicious = False

        try:
            info = marker.lstat()
        except OSError as error:
            entry["warnings"].append(f"marker stat failed: {type(error).__name__}")
            continue

        entry["marker_mtime"] = _format_mtime(info.st_mtime)
        entry["marker_size"] = info.st_size
        entry["marker_is_symlink"] = marker.is_symlink()
        if entry["marker_is_symlink"]:
            entry["warnings"].append("marker is a symlink; not inspected")
            suspicious = True
        if info.st_size == 0:
            entry["marker_empty"] = True
        else:
            entry["marker_empty"] = False
            entry["warnings"].append("marker is not empty; contents not inspected")

        sql_path = db_dir / f"crm7-{backup_id}.sql"
        bundle_path = secrets_dir / f"crm7-{backup_id}.secrets.tar"
        manifest_path = db_dir / f"crm7-{backup_id}.manifest.json"
        artifacts = (
            ("sql", sql_path),
            ("bundle", bundle_path),
            ("manifest", manifest_path),
        )
        before: dict = {}

        try:
            for key, path in artifacts:
                is_link = path.is_symlink()
                entry[f"{key}_is_symlink"] = is_link
                present = path.is_file() and not is_link
                entry[f"{key}_present"] = present

                if is_link:
                    entry["warnings"].append(
                        f"{key} is a symlink; not treated as a regular artifact"
                    )
                    suspicious = True
                elif present:
                    stat_info = path.lstat()
                    before[key] = (stat_info.st_ino, stat_info.st_size, stat_info.st_mtime_ns)
        except OSError as error:
            entry["warnings"].append(f"artifact stat failed: {type(error).__name__}")
            suspicious = True

        if suspicious:
            entry["status"] = "unknown"
            continue

        if not entry["manifest_present"]:
            entry["status"] = "incomplete"
            continue
        if not (entry["sql_present"] and entry["bundle_present"]):
            entry["status"] = "incomplete"
            continue

        verify_error = None
        try:
            with contextlib.redirect_stdout(io.StringIO()):
                verify(manifest_path, secrets_dir)
        except (BackupError, OSError, ValueError, tarfile.TarError) as error:
            verify_error = error

        # Отличаем реальную порчу от изменения файлов параллельным Backup.
        after = None
        try:
            after = {}
            for key, path in artifacts:
                if path.is_file() and not path.is_symlink():
                    stat_info = path.lstat()
                    after[key] = (
                        stat_info.st_ino, stat_info.st_size, stat_info.st_mtime_ns
                    )
        except OSError:
            after = None

        if after is None or after != before:
            entry["status"] = "unknown"
            entry["verify"] = "not available"
            entry["warnings"].append(
                "artifacts changed or became unreadable during verification"
            )
            continue

        if verify_error is None:
            entry["verify"] = "passed"
            entry["status"] = "complete"
        else:
            entry["verify"] = f"failed: {verify_error}"
            entry["status"] = "invalid"

    return result


def print_marker_diagnostics(result: dict) -> None:
    print(f"db-backup-dir: {result['db_dir']}")
    print(f"secrets-dir: {result['secrets_dir']}")
    print(f"project lock: {result['lock_status']}")
    for warning in result.get("warnings", []):
        print(f"warning: {warning}")

    lock_status = result["lock_status"]
    if lock_status == "available":
        print(
            "note: lock probe succeeded only at one moment; files may still change "
            "during verification (best-effort snapshot)"
        )
    else:
        print(
            "warning: project lock is not confirmed free; snapshot is not guaranteed "
            "to be consistent"
        )
    if result["error"]:
        print(f"error: {result['error']}")

    markers = result["markers"]
    if not markers:
        print("no backup ID markers found")
        print(
            "marker presence does not prove that a Backup process is gone; "
            "no files were removed or modified"
        )
        return

    for entry in markers:
        print(f"backup_id: {entry['backup_id']}")
        print(f"  marker: {entry['marker_path']}")
        print(f"  mtime: {entry['marker_mtime']}")
        print(f"  size: {entry['marker_size']}")
        print(f"  marker empty: {entry['marker_empty']}")
        print(f"  symlink: {entry['marker_is_symlink']}")
        print(f"  sql: {entry['sql_present']} (symlink: {entry['sql_is_symlink']})")
        print(f"  secret bundle: {entry['bundle_present']} "
              f"(symlink: {entry['bundle_is_symlink']})")
        print(f"  manifest: {entry['manifest_present']} "
              f"(symlink: {entry['manifest_is_symlink']})")
        print(f"  status: {entry['status']}")
        print(f"  verify: {entry['verify']}")
        for warning in entry["warnings"]:
            print(f"  warning: {warning}")

    summary = {"complete": 0, "incomplete": 0, "invalid": 0, "unknown": 0}
    for entry in markers:
        summary[entry["status"]] = summary.get(entry["status"], 0) + 1
    print(
        f"summary: markers found={len(markers)}, complete={summary['complete']}, "
        f"incomplete={summary['incomplete']}, invalid={summary['invalid']}, "
        f"unknown={summary['unknown']}"
    )
    print(
        "exit code 0 means diagnostics ran successfully, not that all backups are valid"
    )
    print(
        "marker presence does not prove that a Backup process is gone; "
        "no files were removed or modified"
    )


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="CRM7 backup (DB + protected configuration).",
        epilog=(
            "Preflight проверяет только наличие APP_KEY/DB_CONFIG_KEY, не их валидность. "
            "verify проверяет целостность файлов и структуру комплекта, но не расшифровку "
            "и не возможность восстановления. Подкоманда markers read-only; exit code 0 "
            "означает успешное выполнение диагностики, а не валидность всех backup."
        ),
    )
    parser.add_argument("--project-dir")
    parser.add_argument("--db-backup-dir")
    parser.add_argument("--secrets-dir")
    parser.add_argument("--skip-docker-check", action="store_true")
    parser.add_argument("--backup-id")
    subparsers = parser.add_subparsers(dest="command")
    verify_parser = subparsers.add_parser(
        "verify",
        help="проверить целостность комплекта без restore (не проверяет расшифровку)",
    )
    verify_parser.add_argument("--manifest", required=True)
    verify_parser.add_argument("--secrets-dir", default=argparse.SUPPRESS)
    markers_parser = subparsers.add_parser(
        "markers",
        help="read-only диагностика Backup ID markers (ничего не удаляет)",
    )
    markers_parser.add_argument("--db-backup-dir", default=argparse.SUPPRESS)
    markers_parser.add_argument("--secrets-dir", default=argparse.SUPPRESS)
    markers_parser.add_argument("--project-dir", default=argparse.SUPPRESS)
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = _parser()
    args = parser.parse_args(argv)
    secrets_dir = getattr(args, "secrets_dir", None)

    try:
        if args.command == "verify":
            if not secrets_dir:
                raise BackupError("--secrets-dir is required for verify")
            verify(pathlib.Path(args.manifest), pathlib.Path(secrets_dir))
            return 0

        if args.command == "markers":
            marker_db = getattr(args, "db_backup_dir", None)
            marker_secrets = getattr(args, "secrets_dir", None)
            marker_project = getattr(args, "project_dir", None)

            if not marker_db or not marker_secrets:
                raise BackupError(
                    "markers requires --db-backup-dir and --secrets-dir"
                )
            print_marker_diagnostics(collect_marker_diagnostics(
                pathlib.Path(marker_db),
                pathlib.Path(marker_secrets),
                pathlib.Path(marker_project) if marker_project else None,
            ))
            return 0

        if not args.project_dir or not args.db_backup_dir or not secrets_dir:
            raise BackupError(
                "--project-dir, --db-backup-dir and --secrets-dir are required"
            )

        create_backup(
            pathlib.Path(args.project_dir),
            pathlib.Path(args.db_backup_dir),
            pathlib.Path(secrets_dir),
            backup_id=args.backup_id,
            require_docker=not args.skip_docker_check,
        )
        return 0
    except (
        BackupError, LockBusy, OSError, ValueError,
        tarfile.TarError, subprocess.CalledProcessError,
    ) as error:
        print(f"backup failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
