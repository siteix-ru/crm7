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
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import uuid

from update_lock import fsync_directory

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


class BackupError(RuntimeError):
    """Управляемая ошибка backup/verify."""


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
        temporary.unlink(missing_ok=True)
        raise

    try:
        os.replace(temporary, path)
    except Exception:
        temporary.unlink(missing_ok=True)
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
                fcntl.flock(handle.fileno(), fcntl.LOCK_UN)

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
            try:
                msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
            except Exception:
                pass


def _stat_signature(path: pathlib.Path) -> tuple[int, int, int] | None:
    if not path.exists():
        return None
    info = path.stat()
    return (info.st_ino, info.st_size, info.st_mtime_ns)


@contextlib.contextmanager
def _backup_id_claim(db_dir: pathlib.Path, backup_id: str):
    """Эксклюзивное право на backup_id; lock удаляется только владельцем."""
    lock_path = db_dir / f"crm7-{backup_id}.id.lock"

    try:
        descriptor = os.open(lock_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    except FileExistsError as error:
        raise BackupError("backup_id is already in use") from error

    os.close(descriptor)

    try:
        yield
    finally:
        lock_path.unlink(missing_ok=True)


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
            temporary.unlink(missing_ok=True)
            raise

    try:
        os.replace(temporary, target)
    except Exception:
        temporary.unlink(missing_ok=True)
        raise

    os.chmod(target, 0o600)

    with tarfile.open(target, mode="r") as archive:
        members = archive.getmembers()

    try:
        return _validate_tar_members(members, names)
    except BackupError:
        target.unlink(missing_ok=True)
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

    compose, entries, missing_optional = preflight(project, db_dir, secrets_dir, require_docker)
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
        if sql_path.exists() or bundle_path.exists() or manifest_path.exists():
            raise BackupError("backup_id is already in use")

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


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="CRM7 backup (DB + protected configuration).",
        epilog=(
            "Preflight проверяет только наличие APP_KEY/DB_CONFIG_KEY, не их валидность. "
            "verify проверяет целостность файлов и структуру комплекта, но не расшифровку "
            "и не возможность восстановления."
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
    except (BackupError, OSError, ValueError, tarfile.TarError, subprocess.CalledProcessError) as error:
        print(f"backup failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
