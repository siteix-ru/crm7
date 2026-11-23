#!/usr/bin/env python3
"""CRM7: native (non-Docker) backup and restore for Linux VPS.

The existing Docker scripts (backup_crm7.py / restore_crm7.py) are intentionally
left untouched. This module reuses their safety helpers where it is safe and
adds a native backend with:

  * local mariadb-dump / mysql clients;
  * credentials only inside a 0600 MySQL option file (never argv/env/logs);
  * a deterministic native target identity that must match the manifest AND be
    explicitly confirmed;
  * POSIX locks, O_EXCL backup-id claims and integrity checks;
  * optional api/storage/ archive with symlink/traversal-safe extraction;
  * a pre-restore DB snapshot and a durable journal for partial failures.

No command from this module is executed at import time.
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

import backup_crm7 as docker_backup
import restore_crm7 as docker_restore
from update_lock import require_lock, update_lock, run_cleanup

BACKEND = "native"
MANIFEST_FORMAT = docker_backup.MANIFEST_FORMAT
TMP_PREFIXES = (".tmp", ".crm7-", "~")
STORAGE_EXCLUDE = {".DS_Store", "Thumbs.db"}

NATIVE_TARGET_PHP = r"""
require $argv[1] . '/src/autoload.php';
\App\Core\Config::load($argv[2]);
$host = (string) \App\Core\Config::get('DB_HOST', '127.0.0.1');
$port = (string) \App\Core\Config::get('DB_PORT', '3306');
$name = (string) \App\Core\Config::get('DB_NAME', '');
$user = (string) \App\Core\Config::get('DB_USER', '');
$pass = (string) \App\Core\Config::get('DB_PASS', '');
$json = $argv[3];
if (is_file($json)) {
    $data = json_decode((string) file_get_contents($json), true);
    if (!is_array($data)) { exit(4); }
    $host = (string) ($data['host'] ?? $host);
    $port = (string) ($data['port'] ?? $port);
    $name = (string) ($data['database'] ?? $name);
    $user = (string) ($data['user'] ?? $user);
    $stored = (string) ($data['password'] ?? '');
    if ($stored !== '') {
        if (\App\Support\DatabaseConfigCrypto::isEncrypted($stored)) {
            try { $pass = \App\Support\DatabaseConfigCrypto::decrypt($stored); }
            catch (Throwable $e) { exit(5); }
        } elseif (str_starts_with($stored, 'dbcfg:')) {
            exit(5);
        } else {
            $pass = $stored;
        }
    }
}
foreach ([$host, $port, $name, $user, $pass] as $value) {
    if (strpbrk($value, "\r\n\\") !== false) { exit(6); }
}
if ($name === '' || $user === '' || !ctype_digit($port)) { exit(7); }
$content = "[client]\nhost={$host}\nport={$port}\nuser={$user}\npassword={$pass}\n";
if (getenv('CRM7_NATIVE_NO_SSL') === '1') { $content .= "ssl=0\n"; }
if (file_put_contents($argv[4], $content, LOCK_EX) === false) { exit(8); }
chmod($argv[4], 0600);
echo "OK\n";
"""


class NativeError(RuntimeError):
    pass


def _utc_now() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _find_client() -> str:
    for candidate in ("mariadb-dump", "mysqldump"):
        path = shutil.which(candidate)
        if path:
            return path
    raise NativeError("mariadb-dump or mysqldump not found in PATH")


def _find_importer() -> str:
    for candidate in ("mariadb", "mysql"):
        path = shutil.which(candidate)
        if path:
            return path
    raise NativeError("mariadb or mysql not found in PATH")


def _project_paths(project: pathlib.Path) -> dict[str, pathlib.Path]:
    project = project.resolve()
    return {
        "project": project,
        "api": project / "api",
        "env": project / "api" / "config" / ".env",
        "db_json": project / "api" / "config" / "database.json",
        "storage": project / "api" / "storage",
        "config_lock": project / "api" / "config" / "database.json.lock",
    }


def _db_settings(project: pathlib.Path) -> dict[str, str]:
    paths = _project_paths(project)
    values = docker_restore._parse_env(paths["env"])  # names+values, kept in memory only
    db_json = paths["db_json"]

    if db_json.is_file() and not db_json.is_symlink():
        try:
            data = json.loads(db_json.read_text(encoding="utf-8"))
        except (OSError, ValueError) as error:
            raise NativeError("cannot read database.json") from error
        if not isinstance(data, dict):
            raise NativeError("invalid database.json")
        for source, target in (("host", "host"), ("port", "port"), ("database", "database"), ("user", "user")):
            if source in data:
                values["DB_" + target.upper()] = str(data[source])

    settings = {
        "host": values.get("DB_HOST", "127.0.0.1"),
        "port": values.get("DB_PORT", "3306"),
        "name": values.get("DB_NAME", ""),
        "user": values.get("DB_USER", ""),
    }
    if not settings["name"] or not settings["user"] or not str(settings["port"]).isdigit():
        raise NativeError("incomplete DB configuration")
    return settings


def native_target_id(project: pathlib.Path) -> str:
    """Stable identity of a Dockerless target; password is intentionally excluded."""
    settings = _db_settings(project)
    payload = json.dumps({
        "backend": BACKEND,
        "project": str(project.resolve()),
        "host": settings["host"],
        "port": str(settings["port"]),
        "database": settings["name"],
        "user": settings["user"],
    }, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()[:20]


@contextlib.contextmanager
def _option_file(project: pathlib.Path):
    """Create a 0600 MySQL option file; never log or expose its contents."""
    paths = _project_paths(project)
    if shutil.which("php") is None:
        raise NativeError("php is required to read encrypted DB settings")
    handle, raw = tempfile.mkstemp(prefix="crm7-native-my-", dir=str(project))
    os.close(handle)
    option_path = pathlib.Path(raw)

    try:
        os.chmod(option_path, 0o600)
        env = docker_restore._php_env()
        result = subprocess.run(
            ["php", "-r", NATIVE_TARGET_PHP, str(paths["api"]), str(paths["env"]),
             str(paths["db_json"]), str(option_path)],
            cwd=str(project), env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=30,
        )
        if result.returncode != 0 or b"OK" not in result.stdout:
            raise NativeError("cannot prepare DB credentials")
        yield option_path
    finally:
        with contextlib.suppress(OSError):
            option_path.unlink(missing_ok=True)


@contextlib.contextmanager
def _temp_artifact(directory: pathlib.Path, suffix: str):
    handle, raw = tempfile.mkstemp(prefix=".crm7-native-", suffix=suffix, dir=str(directory))
    os.close(handle)
    path = pathlib.Path(raw)
    os.chmod(path, 0o600)
    try:
        yield path
    except BaseException:
        with contextlib.suppress(OSError):
            path.unlink(missing_ok=True)
        raise


def _dump_sql(project: pathlib.Path, target: pathlib.Path) -> None:
    client = _find_client()
    with _option_file(project) as option_path:
        command = [
            client, f"--defaults-extra-file={option_path}",
            "--single-transaction", "--quick", "--hex-blob",
            "--default-character-set=utf8mb4",
            _db_settings(project)["name"],
        ]
        with _temp_artifact(target.parent, ".sql") as temp_path:
            with temp_path.open("wb") as handle:
                result = subprocess.run(
                    command, cwd=str(project), stdout=handle, stderr=subprocess.PIPE,
                    stdin=subprocess.DEVNULL, timeout=1800,
                )
            if result.returncode != 0:
                raise NativeError("database dump failed")
            payload = temp_path.read_bytes()
            if not payload or not payload.lstrip().startswith((b"--", b"/*", b"CREATE", b"DROP")):
                raise NativeError("unexpected SQL dump header")
            os.replace(temp_path, target)
    os.chmod(target, 0o600)


def _import_sql(project: pathlib.Path, sql_path: pathlib.Path) -> None:
    client = _find_importer()
    with _option_file(project) as option_path:
        command = [client, f"--defaults-extra-file={option_path}",
                   "--default-character-set=utf8mb4", _db_settings(project)["name"]]
        with sql_path.open("rb") as handle:
            result = subprocess.run(
                command, cwd=str(project), stdin=handle, stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE, timeout=1800,
            )
    if result.returncode != 0:
        raise NativeError("database import failed")


def _safe_storage_files(storage: pathlib.Path) -> list[pathlib.Path]:
    if not storage.is_dir() or storage.is_symlink():
        raise NativeError("storage directory not found or symlink")
    files: list[pathlib.Path] = []
    for root, dirs, names in os.walk(storage, followlinks=False):
        dirs[:] = [d for d in dirs if not (pathlib.Path(root) / d).is_symlink()]
        for name in names:
            if name in STORAGE_EXCLUDE or name.startswith((".tmp", ".crm7-")) \
                    or name.endswith((".tmp", "~")):
                continue
            path = pathlib.Path(root) / name
            if path.is_symlink() or not path.is_file():
                continue
            files.append(path)
    return sorted(files)


def _storage_archive(project: pathlib.Path, target: pathlib.Path) -> list[str]:
    storage = _project_paths(project)["storage"]
    files = _safe_storage_files(storage)
    names: list[str] = []
    with _temp_artifact(target.parent, ".tar") as temp_path:
        with tarfile.open(temp_path, mode="w", format=tarfile.PAX_FORMAT) as archive:
            for path in files:
                relative = path.relative_to(storage).as_posix()
                info = archive.gettarinfo(str(path), arcname=relative)
                info.mode = 0o600  # shared tar validator requires 0600 members
                info.uid = info.gid = 0
                info.uname = info.gname = ""
                with path.open("rb") as handle:
                    archive.addfile(info, handle)
                names.append(relative)
        os.replace(temp_path, target)
    os.chmod(target, 0o640)
    return names


def _extract_storage(project: pathlib.Path, archive_path: pathlib.Path, stage: pathlib.Path) -> list[str]:
    stage.mkdir(parents=True, exist_ok=True, mode=0o700)
    names: list[str] = []
    with tarfile.open(archive_path, mode="r") as archive:
        members = archive.getmembers()
        docker_backup._validate_tar_members(members)
        for member in members:
            relative = pathlib.PurePosixPath(member.name)
            if relative.is_absolute() or ".." in relative.parts:
                raise NativeError("unsafe storage archive member")
            destination = (stage / pathlib.Path(*relative.parts)).resolve()
            if not destination.is_relative_to(stage.resolve()):
                raise NativeError("storage archive traversal")
            destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            source = archive.extractfile(member)
            if source is None:
                raise NativeError("invalid storage archive member")
            with source, destination.open("wb") as handle:
                shutil.copyfileobj(source, handle)
            os.chmod(destination, 0o640)
            names.append(member.name)
    return names


def _write_journal(db_dir: pathlib.Path, backup_id: str, state: dict) -> None:
    path = db_dir / f"crm7-native-restore-{backup_id}.json"
    docker_backup._atomic_bytes(path, (json.dumps(state, indent=2, ensure_ascii=False) + "\n").encode("utf-8"))
    os.chmod(path, 0o600)


def _preflight(project: pathlib.Path, db_dir: pathlib.Path, secrets_dir: pathlib.Path,
               include_storage: bool) -> dict[str, pathlib.Path]:
    paths = _project_paths(project)
    if not paths["project"].is_dir():
        raise NativeError("project dir not found")
    if not paths["env"].is_file():
        raise NativeError("api/config/.env not found")
    required = ("DB_CONFIG_KEY", "APP_KEY")
    keys = docker_backup._read_env_keys(paths["env"])
    missing = [key for key in required if key not in keys]
    if missing:
        raise NativeError("missing env key: " + ", ".join(missing))
    if db_dir.resolve() == secrets_dir.resolve() or secrets_dir.resolve().is_relative_to(db_dir.resolve()) \
            or db_dir.resolve().is_relative_to(secrets_dir.resolve()):
        raise NativeError("secret destination must be separate from DB backup dir")
    if include_storage and not paths["storage"].is_dir():
        raise NativeError("storage directory not found")
    _find_client()
    db_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    secrets_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(db_dir, 0o700)
    os.chmod(secrets_dir, 0o700)
    return paths


def native_backup(project: pathlib.Path, db_dir: pathlib.Path, secrets_dir: pathlib.Path, *,
                  backup_id: str | None = None, include_storage: bool = False) -> pathlib.Path:
    require_lock()
    project = pathlib.Path(project).resolve()
    db_dir = pathlib.Path(db_dir).resolve()
    secrets_dir = pathlib.Path(secrets_dir).resolve()
    paths = _preflight(project, db_dir, secrets_dir, include_storage)
    backup_id = backup_id or docker_backup.new_backup_id()
    if not re.fullmatch(r"[0-9A-Za-zT\-]+", backup_id):
        raise NativeError("invalid backup_id")

    sql_path = db_dir / f"crm7-{backup_id}.sql"
    bundle_path = secrets_dir / f"crm7-{backup_id}.secrets.tar"
    manifest_path = db_dir / f"crm7-{backup_id}.manifest.json"
    storage_path = db_dir / f"crm7-{backup_id}.storage.tar"

    with docker_backup.update_lock(project):
        with docker_backup._backup_id_claim(db_dir, backup_id):
            existing = [str(p) for p in (sql_path, bundle_path, manifest_path, storage_path) if p.exists()]
            if existing:
                raise NativeError("backup_id is already in use: " + ", ".join(existing))

            created_artifacts: list[pathlib.Path] = []
            try:
                _dump_sql(project, sql_path)
                created_artifacts.append(sql_path)
                entries: list[tuple[pathlib.PurePosixPath, pathlib.Path]] = []
                for relative in docker_backup.REQUIRED_CONFIG_FILES:
                    source = docker_backup._safe_source(project, relative)
                    if not source.is_file():
                        raise NativeError("required config file not found: " + relative.as_posix())
                    entries.append((relative, source))
                optional_missing: list[str] = []
                for relative in docker_backup.OPTIONAL_CONFIG_FILES:
                    source = docker_backup._safe_source(project, relative)
                    if source.is_file():
                        entries.append((relative, source))
                    else:
                        optional_missing.append(relative.as_posix())

                watched = tuple(src for relative, src in entries if relative in docker_backup.OPTIONAL_CONFIG_FILES)
                with docker_backup._shared_config_lock(paths["config_lock"] if paths["config_lock"].exists() else None):
                    members = docker_backup._secret_bundle(
                        project, bundle_path, entries,
                        lock_path=paths["config_lock"] if paths["config_lock"].exists() else None,
                        watched_paths=watched,
                    )
                    created_artifacts.append(bundle_path)

                storage_members: list[str] = []
                if include_storage:
                    storage_members = _storage_archive(project, storage_path)
                    created_artifacts.append(storage_path)

                manifest = {
                    "format_version": MANIFEST_FORMAT,
                    "backup_id": backup_id,
                    "created_at": _utc_now(),
                    "crm7_version": docker_backup._app_version(project),
                    "database_engine": "mariadb",
                    "database_schema_version": None,
                    "backend": BACKEND,
                    "native_target_id": native_target_id(project),
                    "sql_dump": {
                        "path": sql_path.name,
                        "size": sql_path.stat().st_size,
                        "sha256": docker_backup._sha256(sql_path),
                    },
                    "secret_bundle": {
                        "path": bundle_path.name,
                        "size": bundle_path.stat().st_size,
                        "sha256": docker_backup._sha256(bundle_path),
                        "members": members,
                        "encrypted": False,
                    },
                    "storage": None if not include_storage else {
                        "path": storage_path.name,
                        "size": storage_path.stat().st_size,
                        "sha256": docker_backup._sha256(storage_path),
                        "members": storage_members,
                    },
                    "included_components": list(members) + storage_members,
                    "missing_optional_components": optional_missing,
                }
                docker_backup._atomic_bytes(
                    manifest_path,
                    (json.dumps(manifest, indent=2, ensure_ascii=False) + "\n").encode("utf-8"),
                )
            except BaseException:
                for artifact in created_artifacts:
                    with contextlib.suppress(OSError):
                        artifact.unlink(missing_ok=True)
                raise

    print(f"native backup ready: {manifest_path}")
    return manifest_path


def _verify_storage_component(manifest: dict, db_dir: pathlib.Path) -> pathlib.Path | None:
    part = manifest.get("storage")
    if part is None:
        return None
    if not isinstance(part, dict):
        raise NativeError("invalid storage component")
    raw = part.get("path")
    if not isinstance(raw, str) or pathlib.PurePosixPath(raw).name != raw:
        raise NativeError("unsafe storage component path")
    path = (db_dir / raw).resolve()
    if not path.is_file() or path.stat().st_size != part.get("size") \
            or docker_backup._sha256(path) != part.get("sha256"):
        raise NativeError("storage component integrity check failed")
    with tarfile.open(path, mode="r") as archive:
        docker_backup._validate_tar_members(archive.getmembers(), part.get("members"))
    return path


def native_restore(project: pathlib.Path, db_dir: pathlib.Path, secrets_dir: pathlib.Path,
                   manifest_path: pathlib.Path, *, confirm: str, confirm_target: str,
                   dry_run: bool = False) -> None:
    require_lock()
    project = pathlib.Path(project).resolve()
    db_dir = pathlib.Path(db_dir).resolve()
    secrets_dir = pathlib.Path(secrets_dir).resolve()
    manifest_path = pathlib.Path(manifest_path).resolve()

    docker_backup.verify(manifest_path, secrets_dir)
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))

    if manifest.get("backend") != BACKEND:
        raise NativeError("manifest is not a native backup")
    backup_id = manifest["backup_id"]
    if confirm != backup_id or confirm_target != manifest.get("native_target_id"):
        raise NativeError("confirmation does not match backup ID and native target")
    if native_target_id(project) != manifest["native_target_id"]:
        raise NativeError("native target identity mismatch")
    storage_archive_path = _verify_storage_component(manifest, db_dir)

    paths = _project_paths(project)
    if not paths["env"].is_file():
        raise NativeError("api/config/.env not found")
    if any(path.is_symlink() for path in (paths["env"], paths["db_json"], paths["storage"])):
        raise NativeError("restore target contains symlinks")

    journal_path = db_dir / f"crm7-native-restore-{backup_id}.json"
    if journal_path.exists():
        try:
            previous = json.loads(journal_path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            previous = {}
        if previous.get("state") == "manual_recovery_required":
            raise NativeError(
                "a previous native restore requires manual recovery; "
                f"see {journal_path} before retrying"
            )

    staged_root: pathlib.Path | None = None
    prerestore_created = False
    destructive_started = False

    with docker_backup.update_lock(project):
        # Re-read the target under the same canonical lock used by backup and
        # config writers: identity must not change between validation and any
        # destructive step.
        if native_target_id(project) != manifest["native_target_id"]:
            raise NativeError("native target identity changed before restore")

        journal = {
            "backup_id": backup_id,
            "backend": BACKEND,
            "state": "validated",
            "updated_at": _utc_now(),
            "sql_outcome": None,
            "storage_outcome": None,
            "recovery_dir": None,
            "sql_snapshot": None,
            "storage_rollback": None,
        }
        _write_journal(db_dir, backup_id, journal)
        if dry_run:
            print("native restore dry-run: validation passed")
            return

        sql_path = (db_dir / manifest["sql_dump"]["path"]).resolve()
        if sql_path.parent != db_dir or not sql_path.is_file():
            raise NativeError("unsafe SQL dump path")

        prerestore = db_dir / f"crm7-{backup_id}.prerestore.sql"
        try:
            if not prerestore.exists():
                _dump_sql(project, prerestore)
                prerestore_created = True
                os.chmod(prerestore, 0o600)
            journal.update(state="pre_restore_snapshot", updated_at=_utc_now())
            _write_journal(db_dir, backup_id, journal)

            staged_root = pathlib.Path(
                tempfile.mkdtemp(prefix="crm7-native-restore-", dir=str(db_dir))
            )
            staged_root.chmod(0o700)

            if storage_archive_path is not None:
                _extract_storage(project, storage_archive_path, staged_root / "storage")

            # From here on the target may already be modified. Any failure must
            # retain recovery artifacts instead of deleting them.
            destructive_started = True

            _import_sql(project, sql_path)
            journal.update(
                state="sql_imported", sql_outcome="ok", updated_at=_utc_now()
            )
            _write_journal(db_dir, backup_id, journal)

            if storage_archive_path is not None:
                staging = staged_root / "storage"
                paths["storage"].mkdir(parents=True, exist_ok=True, mode=0o750)
                for source in sorted(staging.rglob("*")):
                    if not source.is_file():
                        continue
                    relative = source.relative_to(staging)
                    destination = paths["storage"] / relative
                    destination.parent.mkdir(parents=True, exist_ok=True, mode=0o750)
                    rollback = staged_root / "rollback" / relative
                    if destination.exists():
                        rollback.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                        shutil.copy2(destination, rollback)
                        os.chmod(rollback, 0o600)
                    with source.open("rb") as src, destination.open("wb") as dst:
                        shutil.copyfileobj(src, dst)
                    os.chmod(destination, 0o640)
                journal.update(storage_outcome="ok")

            journal.update(state="completed", updated_at=_utc_now())
            _write_journal(db_dir, backup_id, journal)

            if staged_root is not None:
                try:
                    shutil.rmtree(staged_root)
                except OSError as error:
                    journal.update(
                        cleanup_warning=type(error).__name__,
                        residual_recovery_dir=str(staged_root),
                        updated_at=_utc_now(),
                    )
                    try:
                        _write_journal(db_dir, backup_id, journal)
                    except Exception:
                        pass
                    try:
                        print(
                            f"native restore cleanup warning: {type(error).__name__} "
                            f"for {staged_root}",
                            file=sys.stderr,
                        )
                    except Exception:
                        pass
                staged_root = None

            if prerestore_created:
                try:
                    prerestore.unlink(missing_ok=True)
                except OSError as error:
                    journal.update(
                        cleanup_warning=type(error).__name__,
                        residual_sql_snapshot=str(prerestore),
                        updated_at=_utc_now(),
                    )
                    try:
                        _write_journal(db_dir, backup_id, journal)
                    except Exception:
                        pass
                    try:
                        print(
                            f"native restore cleanup warning: {type(error).__name__} "
                            f"for {prerestore}",
                            file=sys.stderr,
                        )
                    except Exception:
                        pass

        except BaseException as error:
            if destructive_started:
                journal.update(
                    state="manual_recovery_required",
                    error=type(error).__name__,
                    updated_at=_utc_now(),
                    recovery_dir=str(staged_root) if staged_root is not None else None,
                    sql_snapshot=str(prerestore),
                    storage_rollback=(
                        str(staged_root / "rollback") if staged_root is not None else None
                    ),
                )
            else:
                journal.update(
                    state="failed_before_changes",
                    error=type(error).__name__,
                    updated_at=_utc_now(),
                )
            try:
                _write_journal(db_dir, backup_id, journal)
            except BaseException as journal_error:
                print(
                    "native restore journal warning: "
                    + type(journal_error).__name__,
                    file=sys.stderr,
                )

            if not destructive_started:
                if staged_root is not None:
                    with contextlib.suppress(OSError):
                        shutil.rmtree(staged_root)
                    staged_root = None
                if prerestore_created:
                    with contextlib.suppress(OSError):
                        prerestore.unlink(missing_ok=True)
            # On destructive failure staged_root and prerestore are deliberately
            # retained; no unconditional cleanup is performed here.
            raise

def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--project-dir")
    parser.add_argument("--db-backup-dir")
    parser.add_argument("--secrets-dir")
    sub = parser.add_subparsers(dest="command", required=True)

    backup = sub.add_parser("backup", help="create a native backup")
    backup.add_argument("--include-storage", action="store_true")
    backup.add_argument("--backup-id")

    verify = sub.add_parser("verify", help="verify a native backup")
    verify.add_argument("--manifest", required=True)

    restore = sub.add_parser("restore", help="restore a native backup")
    restore.add_argument("--manifest", required=True)
    restore.add_argument("--confirm", required=True)
    restore.add_argument("--confirm-target", required=True)
    restore.add_argument("--dry-run", action="store_true")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = _parser().parse_args(argv)
    project = pathlib.Path(args.project_dir or os.environ.get("CRM7_PROJECT_DIR", "/srv/crm7"))
    db_dir = pathlib.Path(args.db_backup_dir or os.environ.get("CRM7_DB_BACKUP_DIR", "/srv/crm7/backups"))
    secrets_dir = pathlib.Path(args.secrets_dir or os.environ.get("CRM7_SECRETS_DIR", "/srv/protected-crm7"))

    try:
        if args.command == "backup":
            native_backup(project, db_dir, secrets_dir,
                          backup_id=args.backup_id, include_storage=args.include_storage)
        elif args.command == "verify":
            docker_backup.verify(pathlib.Path(args.manifest), secrets_dir)
            manifest = json.loads(pathlib.Path(args.manifest).read_text(encoding="utf-8"))
            if manifest.get("backend") != BACKEND:
                raise NativeError("manifest is not a native backup")
            _verify_storage_component(manifest, db_dir)
            print("native backup verified")
        elif args.command == "restore":
            native_restore(project, db_dir, secrets_dir, pathlib.Path(args.manifest),
                           confirm=args.confirm, confirm_target=args.confirm_target,
                           dry_run=args.dry_run)
        else:
            raise NativeError("unknown command")
        return 0
    except (NativeError, docker_backup.BackupError) as error:
        print("native backup error: " + str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
