#!/usr/bin/env python3
"""CRM7: безопасное восстановление комплекта, созданного backup_crm7.py.

Работает с согласованным снимком backup в staging. Пароли, ключи и DSN не
попадают в argv, shell-строки, логи и журнал операции.
"""
from __future__ import annotations

import argparse
import contextlib
import hashlib
import json
import os
import pathlib
import posixpath
import re
import shutil
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import uuid

import backup_crm7
from update_lock import LOCK_SUPPORTED, LockBusy, fsync_directory, update_lock


class RestoreError(RuntimeError):
    """Управляемая ошибка restore."""


class RestoreInterrupted(BaseException):
    """SIGINT/SIGTERM во время операции."""


ROOT_ENV = pathlib.PurePosixPath(".env")
PHP_ENV = pathlib.PurePosixPath("api/config/.env")
DB_JSON = pathlib.PurePosixPath("api/config/database.json")
DB_JSON_BAK = pathlib.PurePosixPath("api/config/database.json.bak")
COMPOSE_NAMES = tuple(backup_crm7.COMPOSE_NAMES)
APPROVED_MEMBERS = {ROOT_ENV.as_posix(), PHP_ENV.as_posix(),
                    DB_JSON.as_posix(), DB_JSON_BAK.as_posix()} | set(COMPOSE_NAMES)

COMPOSE_DB_KEYS = ("DB_NAME", "DB_USER", "DB_PASS", "DB_ROOT_PASS")
PHP_REQUIRED_KEYS = ("APP_KEY", "DB_CONFIG_KEY")
PHP_DB_KEYS = ("DB_NAME", "DB_USER", "DB_HOST", "DB_PORT")

CONTAINER_API_ROOT = "/var/www/html/api"
CONTAINER_CONFIG_DIR = CONTAINER_API_ROOT + "/config"
STATE_NAME = "restore-state.json"
HISTORY_DIR = "restore-history"
# Обратная совместимость имён, используемых тестами и документацией.
JOURNAL_NAME = STATE_NAME

DECRYPT_PHP = r"""
require $argv[1] . '/src/autoload.php';
\App\Core\Config::load($argv[2]);
$data = json_decode((string) file_get_contents($argv[3]), true);
if (!is_array($data) || !isset($data['password']) || !is_string($data['password'])) {
    exit(3);
}
$stored = $data['password'];
if (\App\Support\DatabaseConfigCrypto::isEncrypted($stored)) {
    try {
        $plain = \App\Support\DatabaseConfigCrypto::decrypt($stored);
    } catch (Throwable $e) {
        exit(2);
    }
} elseif (str_starts_with($stored, 'dbcfg:')) {
    exit(2);
} else {
    $plain = $stored;
}
if (count($argv) > 4 && $argv[4] === 'hash') {
    echo 'HASH ' . hash('sha256', $plain) . "\n";
    exit(0);
}
echo "OK\n";
exit(0);
"""

CONTAINER_DB_PHP = r"""
require '/var/www/html/api/src/autoload.php';
\App\Core\Config::load('/var/www/html/api/config/.env');
try {
    $settings = \App\Core\DatabaseSettings::current();
    $dsn = 'mysql:host=' . $settings['host'] . ';port=' . (int) $settings['port']
        . ';dbname=' . $settings['database'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $settings['user'], $settings['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $argv[1]) {
        exit(3);
    }
    $user = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    $separator = strpos($user, '@');
    if ($separator !== false) {
        $user = substr($user, 0, $separator);
    }
    if ($user !== $argv[2]) {
        exit(4);
    }
    $tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
    if (count($tables) === 0) {
        exit(5);
    }
    $admins = (int) $pdo->query(
        "SELECT COUNT(*) FROM users
         WHERE LEVEL >= 50 AND ACTIVE = 'Y' AND STATUS = 'Y'
           AND (reg_state IS NULL OR reg_state = 'active')"
    )->fetchColumn();
    if ($admins < 1) {
        exit(6);
    }
    echo "DB_OK\n";
    exit(0);
} catch (Throwable $e) {
    exit(2);
}
"""

CONTAINER_HASH_PHP = r"""
if (!is_file($argv[1])) {
    exit(1);
}
echo 'HASH ' . hash_file('sha256', $argv[1]) . "\n";
exit(0);
"""


def _default_runner(command, cwd, *, input=None, stdin=None, env=None, timeout=30, capture=True):
    kwargs = {"cwd": str(cwd), "env": env, "timeout": timeout}
    if capture:
        kwargs["stdout"] = subprocess.PIPE
        kwargs["stderr"] = subprocess.PIPE
    if input is not None:
        kwargs["input"] = input
    if stdin is not None:
        kwargs["stdin"] = stdin
    return subprocess.run(command, **kwargs)


def _php_env() -> dict[str, str]:
    env = dict(os.environ)
    for key in list(env):
        if key.startswith("DB_") or key in ("APP_KEY", "MYSQL_PWD", "MARIADB_PASSWORD"):
            env.pop(key, None)
    return env


def _parse_env(path: pathlib.Path) -> dict[str, str]:
    values: dict[str, str] = {}

    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()

        if not line or line.startswith("#") or "=" not in line:
            continue

        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("\"'")

    return values


def _secret_hash(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def _sha256(path: pathlib.Path) -> str:
    digest = hashlib.sha256()

    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)

    return digest.hexdigest()


def _stat_signature(path: pathlib.Path) -> tuple[int, int, int, int]:
    info = path.stat()
    return (info.st_ino, info.st_size, info.st_mtime_ns, info.st_nlink)


def _atomic_copy(source: pathlib.Path, target: pathlib.Path, mode: int) -> None:
    if target.is_symlink():
        raise RestoreError("restore target is a symlink")

    descriptor, raw = tempfile.mkstemp(prefix=f".{target.name}.", suffix=".tmp",
                                       dir=str(target.parent))
    temporary = pathlib.Path(raw)

    try:
        with source.open("rb") as reader, os.fdopen(descriptor, "wb") as writer:
            shutil.copyfileobj(reader, writer)
            writer.flush()
            os.fsync(writer.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, target)
    except Exception:
        temporary.unlink(missing_ok=True)
        raise


def _snapshot_file(source: pathlib.Path, target: pathlib.Path,
                   expected_size: int, expected_sha: str, label: str) -> None:
    """Безопасная копия исходника с проверкой подмены во время чтения."""
    if source.is_symlink():
        raise RestoreError(f"{label} snapshot source is a symlink")

    before = _stat_signature(source)
    _atomic_copy(source, target, 0o600)
    after = _stat_signature(source)

    if before != after:
        raise RestoreError(f"{label} changed during snapshot")

    if target.stat().st_size != expected_size or _sha256(target) != expected_sha:
        raise RestoreError(f"{label} snapshot does not match manifest")


def _read_db_json(path: pathlib.Path) -> dict:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise RestoreError("invalid database.json in bundle") from error

    if not isinstance(data, dict):
        raise RestoreError("invalid database.json in bundle")

    host = data.get("host")
    port = data.get("port")
    database = data.get("database")
    user = data.get("user")
    password = data.get("password", "")

    if (not isinstance(host, str) or not host
            or isinstance(port, bool) or not isinstance(port, int) or not 1 <= port <= 65535
            or not isinstance(database, str) or not database
            or not isinstance(user, str) or not user
            or not isinstance(password, str)):
        raise RestoreError("invalid database.json in bundle")

    return data


def _approved_names(members: list[str]) -> set[str]:
    names = set(members)

    if ROOT_ENV.as_posix() not in names or PHP_ENV.as_posix() not in names:
        raise RestoreError("required component missing from bundle")

    composes = names & set(COMPOSE_NAMES)

    if len(composes) != 1:
        raise RestoreError("compose file in bundle is ambiguous")
    if names - APPROVED_MEMBERS:
        raise RestoreError("unexpected component in bundle")

    return names


def _extract_approved(bundle: pathlib.Path, stage: pathlib.Path,
                      expected_members: list[str]) -> tuple[str, bool]:
    """Извлечение только из уже проверенной staging-копии bundle."""
    approved = _approved_names(expected_members)
    root = stage.resolve()
    compose_name = next(iter(approved & set(COMPOSE_NAMES)))
    has_db_json = DB_JSON.as_posix() in approved

    with tarfile.open(bundle, mode="r") as archive:
        members = archive.getmembers()
        backup_crm7._validate_tar_members(members, expected_members)

        for member in members:
            if member.name not in approved:
                raise RestoreError("unexpected tar member")

            relative = pathlib.PurePosixPath(member.name)
            target = stage.joinpath(*relative.parts)

            if not target.resolve().is_relative_to(root):
                raise RestoreError("tar member escapes staging directory")

            target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            source = archive.extractfile(member)

            if source is None:
                raise RestoreError("cannot read tar member")

            descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)

            try:
                with os.fdopen(descriptor, "wb") as handle:
                    shutil.copyfileobj(source, handle)
                    handle.flush()
                    os.fsync(handle.fileno())
            except Exception:
                target.unlink(missing_ok=True)
                raise

    return compose_name, has_db_json


def _local_php_check(project: pathlib.Path, script: str, args: list[pathlib.Path],
                     runner, label: str, expected: str = "OK") -> str:
    if shutil.which("php") is None and runner is _default_runner:
        raise RestoreError("php is not installed or not in PATH")

    result = runner(
        ["php", "-r", script] + [str(arg) for arg in args],
        cwd=project, env=_php_env(), timeout=30,
    )
    output = (result.stdout or b"").decode("utf-8", "replace")

    if result.returncode != 0 or expected not in output:
        raise RestoreError(label)

    return output


def _compose_php_check(project: pathlib.Path, script: str, args: list[str],
                       runner, label: str, expected: str = "DB_OK") -> None:
    command = ["docker", "compose", "exec", "-T", "php", "php", "-r", script] + [str(a) for a in args]
    result = runner(command, cwd=project, timeout=45)
    output = (result.stdout or b"").decode("utf-8", "replace")

    if result.returncode != 0 or expected not in output:
        raise RestoreError(label)


def _check_project(project: pathlib.Path, staged: dict, compose_source: pathlib.Path) -> None:
    if not project.is_dir():
        raise RestoreError("project dir not found")

    config_dir = project / "api" / "config"

    if not config_dir.is_dir() or config_dir.is_symlink():
        raise RestoreError("project config directory is missing or is a symlink")

    if os.name != "nt" and config_dir.stat().st_mode & 0o007:
        raise RestoreError("project config directory is world accessible")

    compose_target = project / staged["compose_name"]

    if compose_target.exists():
        if compose_target.is_symlink() or not compose_target.is_file():
            raise RestoreError("project compose file is not a regular file")
        if _sha256(compose_target) != _sha256(compose_source):
            raise RestoreError("bundled compose file differs from project compose file")

    root_env = project / ROOT_ENV.as_posix()

    if root_env.exists():
        if root_env.is_symlink() or not root_env.is_file():
            raise RestoreError("project .env is not a regular file")

        current = {key: value for key, value in _parse_env(root_env).items()
                   if key in ("DB_NAME", "DB_USER")}

        if current and current != {key: staged["compose_env"][key]
                                   for key in ("DB_NAME", "DB_USER")}:
            raise RestoreError("project database target does not match backup")

    live_db_json = config_dir / "database.json"
    expected = staged.get("database_json")

    if live_db_json.exists():
        if expected is None:
            raise RestoreError("project has database.json but the bundle does not")

        live = _read_db_json(live_db_json)

        if (live["database"], live["user"]) != (expected["database"], expected["user"]):
            raise RestoreError("project database config does not match backup")


def _container_value(project: pathlib.Path, name: str, runner) -> str | None:
    result = runner(
        ["docker", "compose", "exec", "-T", "db", "printenv", name],
        cwd=project, timeout=15,
    )

    if result.returncode != 0:
        return None

    return (result.stdout or b"").decode("utf-8", "replace").strip() or None


def _inspect(project: pathlib.Path, container: str, fmt: str, runner) -> str | None:
    result = runner(["docker", "inspect", "--format", fmt, container],
                    cwd=project, timeout=15)

    if result.returncode != 0:
        return None

    return (result.stdout or b"").decode("utf-8", "replace").strip() or None


def _target_identity(project: pathlib.Path, runner) -> dict[str, str]:
    """Требуется ровно один однозначно идентифицированный DB-контейнер."""
    result = runner(["docker", "compose", "ps", "-q", "db"], cwd=project, timeout=15)
    lines = [line.strip() for line in
             (result.stdout or b"").decode("utf-8", "replace").splitlines() if line.strip()]

    if result.returncode != 0 or len(lines) != 1:
        raise RestoreError("expected exactly one database container")

    container = lines[0]

    if not re.fullmatch(r"[0-9a-fA-F]{12,64}", container):
        raise RestoreError("invalid database container identifier")

    compose_project = _inspect(
        project, container,
        '{{ index .Config.Labels "com.docker.compose.project" }}', runner,
    )
    mounts = _inspect(
        project, container,
        '{{range .Mounts}}{{.Destination}}={{.Source}};{{end}}', runner,
    )

    if not compose_project or not mounts:
        raise RestoreError("cannot identify target compose project or volume")

    volumes = [
        source for item in mounts.split(";")
        for destination, separator, source in [item.partition("=")]
        if separator and destination == "/var/lib/mysql" and source
    ]

    if len(volumes) != 1:
        raise RestoreError("ambiguous database volume of target container")

    volume = volumes[0]
    identity = "|".join((compose_project, container, volume))
    return {
        "container": container,
        "compose_project": compose_project,
        "volume": volume,
        "target_id": hashlib.sha256(identity.encode("utf-8")).hexdigest()[:16],
    }


def _assert_target_identity(project: pathlib.Path, expected: dict,
                            runner, label: str) -> dict:
    """Свежая перепроверка: используется результат новых Docker-команд."""
    current = _target_identity(project, runner)

    if any(current.get(key) != expected.get(key)
           for key in ("container", "compose_project", "volume", "target_id")):
        raise RestoreError(label)

    return current


def _check_db_environment(project: pathlib.Path, staged: dict,
                          runner) -> tuple[list[str], dict]:
    available = []

    for container_name, key in (("MARIADB_DATABASE", "DB_NAME"),
                                ("MARIADB_USER", "DB_USER")):
        value = _container_value(project, container_name, runner)

        if value is None:
            raise RestoreError("cannot determine target database from compose")
        if value != staged["compose_env"][key]:
            raise RestoreError("running database container does not match backup")

        available.append(f"{container_name}={value}")

    identity = _target_identity(project, runner)
    available.append(f"compose_project={identity['compose_project']}")
    available.append(f"db_container={identity['container']}")
    available.append(f"db_volume={identity['volume']}")

    result = runner(
        ["docker", "compose", "exec", "-T", "db",
         "healthcheck.sh", "--connect", "--innodb_initialized"],
        cwd=project, timeout=30,
    )

    if result.returncode != 0:
        raise RestoreError("database is not ready")

    available.append("database healthcheck=ok")
    return available, identity


def _container_db_check(project: pathlib.Path, staged: dict, runner, label: str) -> None:
    _compose_php_check(
        project, CONTAINER_DB_PHP,
        [staged["db_name"], staged["db_user"]], runner, label,
    )


def _validate_staged(stage: pathlib.Path, compose_name: str, has_db_json: bool,
                     project: pathlib.Path, runner) -> dict:
    compose_env = _parse_env(stage / ROOT_ENV.as_posix())
    php_env = _parse_env(stage / PHP_ENV.as_posix())

    missing_compose = [key for key in COMPOSE_DB_KEYS if not compose_env.get(key)]
    missing_php = [key for key in PHP_REQUIRED_KEYS + PHP_DB_KEYS if not php_env.get(key)]

    if missing_compose or missing_php:
        raise RestoreError("required key is missing in bundle configuration")

    if not php_env["DB_PORT"].isdigit() or not 1 <= int(php_env["DB_PORT"]) <= 65535:
        raise RestoreError("invalid DB_PORT in bundle configuration")

    for key in ("DB_NAME", "DB_USER", "DB_PASS"):
        if compose_env[key] != php_env[key]:
            raise RestoreError("database target differs between compose and PHP config")

    database_json = None
    password_hash = _secret_hash(compose_env["DB_PASS"])

    if has_db_json:
        database_json = _read_db_json(stage / DB_JSON.as_posix())

        if (database_json["database"], database_json["user"]) != (
                compose_env["DB_NAME"], compose_env["DB_USER"]):
            raise RestoreError("database target differs in database.json")

        for key, json_key in (("DB_HOST", "host"), ("DB_PORT", "port")):
            expected = php_env[key] if json_key == "host" else int(php_env[key])
            if database_json[json_key] != expected:
                raise RestoreError("database host/port differs in database.json")

        decrypt_args = [
            project / "api",
            stage / PHP_ENV.as_posix(),
            stage / DB_JSON.as_posix(),
        ]

        _local_php_check(
            project, DECRYPT_PHP, decrypt_args,
            runner, "cannot decrypt database.json with restored DB_CONFIG_KEY",
        )
        output = _local_php_check(
            project, DECRYPT_PHP, decrypt_args + ["hash"],
            runner, "cannot derive database.json password hash", expected="HASH ",
        )
        json_hash = output.split("HASH ", 1)[1].strip().splitlines()[0]

        if json_hash != password_hash:
            raise RestoreError("database password differs in database.json")

    return {
        "compose_env": compose_env,
        "php_env": php_env,
        "compose_name": compose_name,
        "database_json": database_json,
        "db_name": compose_env["DB_NAME"],
        "db_user": compose_env["DB_USER"],
        "db_host": php_env["DB_HOST"],
        "db_port": int(php_env["DB_PORT"]),
        "password_hash": password_hash,
    }


def _target_files(staged: dict) -> list[tuple[pathlib.PurePosixPath, pathlib.PurePosixPath]]:
    compose_path = pathlib.PurePosixPath(staged["compose_name"])
    files = [(ROOT_ENV, ROOT_ENV), (compose_path, compose_path), (PHP_ENV, PHP_ENV)]

    if staged["database_json"] is not None:
        files.append((DB_JSON, DB_JSON))

    if staged.get("has_db_json_bak"):
        files.append((DB_JSON_BAK, DB_JSON_BAK))

    return files


def _rollback_name(relative: pathlib.PurePosixPath) -> str:
    name = relative.as_posix()

    if name == ROOT_ENV.as_posix():
        return "__env"

    return name.replace("/", "__")


def _prepare_rollback(project: pathlib.Path, staged: dict,
                      rollback_dir: pathlib.Path) -> list[dict]:
    rollback_dir.mkdir(mode=0o700, exist_ok=False)
    records = []

    for relative, source_relative in _target_files(staged):
        target = project.joinpath(*relative.parts)
        info = target.stat() if target.exists() else None
        record = {
            "target": target,
            "relative": relative.as_posix(),
            "existed": info is not None,
            "mode": info.st_mode & 0o777 if info is not None else 0o600,
            "uid": info.st_uid if info is not None else None,
            "gid": info.st_gid if info is not None else None,
            "backup": rollback_dir / _rollback_name(relative),
        }

        if record["existed"]:
            if not target.is_file() or target.is_symlink():
                raise RestoreError("restore target is not a regular file")

            descriptor = os.open(record["backup"],
                                 os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)

            with target.open("rb") as reader, os.fdopen(descriptor, "wb") as writer:
                shutil.copyfileobj(reader, writer)
                writer.flush()
                os.fsync(writer.fileno())

        record["backup_size"] = record["backup"].stat().st_size if record["existed"] else 0
        record["backup_sha256"] = _sha256(record["backup"]) if record["existed"] else None
        record["state"] = "saved"
        records.append(record)

    return records


def _rollback_metadata(record: dict) -> dict:
    return {
        "file": record["relative"],
        "name": record["backup"].name,
        "path": str(record["backup"]),
        "target": str(record["target"]),
        "existed": record["existed"],
        "mode": record["mode"],
        "uid": record["uid"],
        "gid": record["gid"],
        "size": record["backup_size"],
        "sha256": record["backup_sha256"],
        "state": record["state"],
    }


def _apply_one(project: pathlib.Path, stage: pathlib.Path,
               relative: pathlib.PurePosixPath, source_relative: pathlib.PurePosixPath,
               mode: int) -> None:
    target = project.joinpath(*relative.parts)
    source = stage.joinpath(*source_relative.parts)
    _atomic_copy(source, target, mode)

    if _sha256(target) != _sha256(source):
        raise RestoreError("config verification failed after apply")


def _restore_ownership(record: dict, target: pathlib.Path) -> None:
    if os.name == "nt" or not hasattr(os, "chown"):
        return

    info = target.stat()

    if info.st_uid != record["uid"] or info.st_gid != record["gid"]:
        os.chown(target, record["uid"], record["gid"])

    if (target.stat().st_mode & 0o777) != record["mode"]:
        os.chmod(target, record["mode"])


def _verify_restored(record: dict) -> bool:
    target = record["target"]

    if not record["existed"]:
        return not (target.exists() or target.is_symlink())
    if not target.is_file() or target.is_symlink():
        return False

    info = target.stat()

    if (info.st_mode & 0o777) != record["mode"]:
        return False
    if os.name != "nt" and (info.st_uid != record["uid"] or info.st_gid != record["gid"]):
        return False

    return _sha256(target) == record["backup_sha256"]


def _rollback_files(records: list[dict], state: dict) -> list[dict]:
    """Идемпотентный откат всех затронутых файлов; никогда не бросает исключения."""
    failures: list[dict] = []
    affected = set(state["files_attempted"]) | set(state["files_changed"])
    metadata = {item["file"]: item for item in state["rollback_copies"]}

    for record in reversed(records):
        name = record["relative"]

        if name not in affected:
            continue

        try:
            if record["existed"]:
                if not record["backup"].is_file():
                    raise RestoreError("rollback copy missing")
                if (record["backup"].stat().st_size != record["backup_size"]
                        or _sha256(record["backup"]) != record["backup_sha256"]):
                    raise RestoreError("rollback copy integrity failed")

                already_restored = (
                    record["target"].is_file()
                    and not record["target"].is_symlink()
                    and _sha256(record["target"]) == record["backup_sha256"]
                )

                if not already_restored:
                    _atomic_copy(record["backup"], record["target"], record["mode"])

                _restore_ownership(record, record["target"])

                if not _verify_restored(record):
                    raise RestoreError("rollback verification failed")
            elif record["target"].exists() and not record["target"].is_symlink():
                record["target"].unlink()

            if not _verify_restored(record):
                raise RestoreError("rollback verification failed")

            if name not in state["files_restored"]:
                state["files_restored"].append(name)

            if name in metadata:
                metadata[name]["state"] = "restored"
        except BaseException as error:
            failures.append({"file": name, "error": error.__class__.__name__})

            if name in metadata:
                metadata[name]["state"] = "restore_failed"

    state["rollback_failures"] = failures
    return failures


def _compose_quiet(project: pathlib.Path, runner) -> None:
    result = runner(["docker", "compose", "config", "-q"], cwd=project, timeout=30)

    if result.returncode != 0:
        raise RestoreError("docker compose configuration is invalid")


def _assert_container_config(project: pathlib.Path, staged: dict, runner) -> None:
    for relative in (PHP_ENV, DB_JSON):
        if relative == DB_JSON and staged["database_json"] is None:
            continue

        name = relative.as_posix().split("/")[-1]
        container_path = CONTAINER_CONFIG_DIR + "/" + name
        result = runner(
            ["docker", "compose", "exec", "-T", "php", "php", "-r",
             CONTAINER_HASH_PHP, container_path],
            cwd=project, timeout=45,
        )
        output = (result.stdout or b"").decode("utf-8", "replace")
        value = output.split("HASH ", 1)[-1].strip().splitlines()[0] if "HASH " in output else ""

        if result.returncode != 0 or value != _sha256(project.joinpath(*relative.parts)):
            raise RestoreError("php container does not use restored configuration")


def _post_checks(project: pathlib.Path, staged: dict, runner) -> None:
    _assert_container_config(project, staged, runner)

    result = runner(
        ["docker", "compose", "exec", "-T", "web", "wget", "-qO-",
         "http://127.0.0.1/api/v2/health"],
        cwd=project, timeout=40,
    )
    payload = (result.stdout or b"").decode("utf-8", "replace").replace(" ", "")

    if result.returncode != 0 or '"status":"ok"' not in payload or '"database":"ok"' not in payload:
        raise RestoreError("application health check failed")


def _sql_import(project: pathlib.Path, sql_path: pathlib.Path, runner) -> None:
    command = [
        "docker", "compose", "exec", "-T", "db", "sh", "-c",
        'export MYSQL_PWD="$MARIADB_PASSWORD"; '
        'client=$(command -v mariadb || command -v mysql) || exit 127; '
        'exec "$client" -u"$MARIADB_USER" "$MARIADB_DATABASE"',
    ]

    with sql_path.open("rb") as handle:
        # stderr перехватывается и не печатается: в нём могут быть фрагменты SQL.
        result = runner(command, cwd=project, stdin=handle, timeout=1800, capture=True)

    return int(result.returncode)


@contextlib.contextmanager
def _interrupt_guard(guard_state: dict):
    def handler(signum, frame):
        if guard_state.get("suppress"):
            guard_state["pending_interrupt"] = signum
            return
        guard_state["interrupt"] = signum
        raise RestoreInterrupted(f"interrupted by signal {signum}")

    installed = {}

    for signum in (signal.SIGINT, signal.SIGTERM):
        try:
            installed[signum] = signal.signal(signum, handler)
        except (ValueError, OSError):
            pass

    try:
        yield
    finally:
        for signum, previous in installed.items():
            signal.signal(signum, previous)


@contextlib.contextmanager
def _suppress_interrupts(guard_state: dict):
    previous = guard_state.get("suppress", False)
    guard_state["suppress"] = True

    try:
        yield
    finally:
        guard_state["suppress"] = previous


@contextlib.contextmanager
def _restore_locks(db_dir: pathlib.Path, project: pathlib.Path, backup_id: str):
    project_key = hashlib.sha256(str(project.resolve()).encode("utf-8")).hexdigest()[:12]
    names = [f"crm7-restore-project-{project_key}.lock",
             f"crm7-restore-id-{backup_id}.lock"]
    created = []

    try:
        for name in names:
            path = db_dir / name

            try:
                descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            except FileExistsError as error:
                raise RestoreError("restore lock is busy") from error

            os.close(descriptor)
            created.append(path)

        yield
    finally:
        for path in created:
            path.unlink(missing_ok=True)


def _utc_now() -> str:
    import datetime as dt
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def _write_journal(db_dir: pathlib.Path, state: dict) -> None:
    """Единственная точка durable-записи журнала текущей операции."""
    state["updated_at"] = _utc_now()
    payload = json.dumps(state, indent=2, ensure_ascii=False) + "\n"
    backup_crm7._atomic_bytes(db_dir / STATE_NAME, payload.encode("utf-8"))
    fsync_directory(db_dir)


def _fsync_file(path: pathlib.Path) -> None:
    # Windows не поддерживает fsync дескриптора, открытого только для чтения;
    # содержимое уже синхронизировано через дескриптор записи.
    if os.name == "nt":
        return

    descriptor = os.open(path, os.O_RDONLY)

    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def _fsync_after_move(source: pathlib.Path, target: pathlib.Path) -> None:
    """fsync destination.parent, затем source.parent — для кросс-каталогового rename."""
    fsync_directory(target.parent)
    fsync_directory(source.parent)


def _ensure_history_dir(path: pathlib.Path) -> None:
    """История 0700, без следования symlink; при ошибке — fail closed."""
    if path.is_symlink():
        raise RestoreError("restore history path is a symlink")

    parent = path.parent

    if path.exists():
        if not path.is_dir():
            raise RestoreError("restore history path is not a directory")

        # POSIX-режимы на Windows не информативны: повторный chmod/fsync
        # не области смысла и создавал бы лишние операции.
        if os.name == "nt" or stat.S_IMODE(path.stat().st_mode) == 0o700:
            return

        try:
            os.chmod(path, 0o700)
        except OSError as error:
            raise RestoreError("cannot secure restore history directory") from error

        try:
            fsync_directory(path)
            fsync_directory(parent)
        except OSError as error:
            raise RestoreError("cannot sync restore history directory") from error

        return

    try:
        os.mkdir(path, 0o700)
    except OSError as error:
        raise RestoreError("cannot create restore history directory") from error

    try:
        fsync_directory(parent)
    except OSError as error:
        raise RestoreError("cannot sync restore history directory") from error


def _archive_stat_signature(path: pathlib.Path) -> tuple[int, int, int, int, int]:
    info = path.stat()
    return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def _prepare_archive_copy(source: pathlib.Path, target: pathlib.Path) -> pathlib.Path:
    """Подготовить архив 0600 в истории, не публикуя его под именем target.

    Проверка stat до/после чтения обнаруживает изменение исходника сторонним
    процессом, не использующим lock CRM7. Это не замена общему lock.
    """
    if source.is_symlink() or not source.is_file():
        raise RestoreError("archive source is not a regular file")

    before = _archive_stat_signature(source)

    if not stat.S_ISREG(os.stat(source).st_mode):
        raise RestoreError("archive source is not a regular file")
    descriptor, raw = tempfile.mkstemp(
        prefix=".restore-archive-", suffix=".tmp", dir=str(target.parent)
    )
    temporary = pathlib.Path(raw)

    try:
        with source.open("rb") as reader, os.fdopen(descriptor, "wb") as writer:
            shutil.copyfileobj(reader, writer)
            writer.flush()
            os.fsync(writer.fileno())

        if _archive_stat_signature(source) != before:
            raise RestoreError("archive source changed during copy")

        os.chmod(temporary, 0o600)
        _fsync_file(temporary)
        return temporary
    except Exception:
        temporary.unlink(missing_ok=True)
        raise


def _restore_archive(target: pathlib.Path, current: pathlib.Path) -> str | None:
    """Вернуть предыдущий журнал; None при успехе, иначе описание ошибки."""
    if not target.is_file():
        return f"archive missing at {target}"

    try:
        previous = json.loads(target.read_text(encoding="utf-8"))

        if not isinstance(previous.get("operation_id"), str):
            return f"archive invalid at {target}"
    except (OSError, ValueError, TypeError):
        return f"archive invalid at {target}"

    try:
        os.replace(target, current)
    except OSError as error:
        return f"rollback rename failed ({error.__class__.__name__}); archive kept at {target}"

    try:
        _fsync_after_move(target, current)
    except OSError as error:
        return (
            f"rollback fsync failed ({error.__class__.__name__}); "
            f"current copy at {current}; manual verification required"
        )

    return None


def _rotate_state(db_dir: pathlib.Path, state: dict) -> None:
    """Архивировать предыдущий журнал и опубликовать новый под обеими блокировками.

    Ошибки fsync внешних каталогов считаются фатальными: предыдущий журнал
    возвращается на место с fsync обоих родителей, а при неудаче отката
    сохраняется архивная копия и выдаётся явная ошибка manual recovery.
    """
    current = db_dir / STATE_NAME
    history = db_dir / HISTORY_DIR
    target = None
    archive_copy = None

    if current.exists():
        try:
            previous = json.loads(current.read_text(encoding="utf-8"))
            previous_id = previous.get("operation_id")

            if not isinstance(previous_id, str) or not previous_id:
                raise ValueError("operation_id missing")
        except (OSError, ValueError, TypeError) as error:
            raise RestoreError("cannot read previous restore state") from error

        _ensure_history_dir(history)
        target = history / f"restore-{previous_id}.json"

        if target.exists():
            raise RestoreError("history entry already exists: " + previous_id)

    state["updated_at"] = _utc_now()
    payload = (json.dumps(state, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    descriptor, raw = tempfile.mkstemp(prefix=".restore-state-", suffix=".tmp",
                                       dir=str(db_dir))
    temporary = pathlib.Path(raw)
    archived = False

    try:
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())

        os.chmod(temporary, 0o600)
        _fsync_file(temporary)

        if target is not None:
            archive_copy = _prepare_archive_copy(current, target)
            os.replace(archive_copy, target)
            archive_copy = None
            archived = True
            _fsync_after_move(current, target)

        os.replace(temporary, current)
        fsync_directory(db_dir)
    except OSError as error:
        if archived:
            rollback_error = _restore_archive(target, current)

            if rollback_error:
                raise RestoreError(
                    f"cannot publish restore state; {rollback_error}"
                ) from error

        raise RestoreError("cannot publish restore state") from error
    finally:
        temporary.unlink(missing_ok=True)

        if archive_copy is not None:
            archive_copy.unlink(missing_ok=True)


def _set_sql_outcome(db_dir: pathlib.Path, state: dict, outcome: str,
                     exit_code: int | None = None, finished: bool = False,
                     step: str | None = None) -> None:
    if outcome != "succeeded" and exit_code == 0:
        raise RestoreError("inconsistent SQL outcome")

    # Фиксируем durable сначала, и только потом обновляем объект процесса:
    # при прерывании внутри записи in-memory состояние остаётся прежним.
    updated = dict(state)
    updated["sql_outcome"] = outcome
    updated["sql_exit_code"] = exit_code

    if finished:
        updated["sql_finished_at"] = _utc_now()

    if step is not None:
        updated["completed_steps"] = list(state["completed_steps"]) + [step]

    _write_journal(db_dir, updated)
    state.update(updated)


def _assert_staged_sql(sql_stage: pathlib.Path, manifest: dict, label: str) -> None:
    if (not sql_stage.is_file()
            or sql_stage.stat().st_size != manifest["sql_dump"]["size"]
            or _sha256(sql_stage) != manifest["sql_dump"]["sha256"]):
        raise RestoreError(label)


def _print_plan(manifest: dict, staged: dict, checks: list[str],
                project: pathlib.Path, identity: dict) -> None:
    print(f"backup_id: {manifest['backup_id']}")
    print(f"target project: {project}")
    print(f"target database: {staged['db_name']} (user: {staged['db_user']})")
    print(f"target host/port: {staged['db_host']}:{staged['db_port']}")
    print(f"compose project: {identity['compose_project']}")
    print(f"db container: {identity['container']}")
    print(f"db volume: {identity['volume']}")
    print(f"target_id: {identity['target_id']}")
    print("components:", ", ".join(sorted(manifest["secret_bundle"]["members"])))
    print("sql dump:", manifest["sql_dump"]["path"])
    print("checks passed:", ", ".join(checks))
    print("not performed without restore: config replacement, SQL import, post-checks")
    print("on --confirm: pass --confirm <backup_id> --confirm-target <target_id>")
    print("dry-run: no project files, database or compose state were changed")


def _prepare_view(project: pathlib.Path, db_dir: pathlib.Path,
                  secrets_dir: pathlib.Path, manifest_bytes: bytes,
                  stage: pathlib.Path, runner) -> tuple[dict, dict, dict, list[str], pathlib.Path]:
    """Снимок backup и read-only проверки; используется dry-run и реальным restore."""
    backup_crm7._atomic_bytes(stage / "manifest.json", manifest_bytes)
    manifest = json.loads((stage / "manifest.json").read_bytes())

    sql_stage = stage / "sql" / pathlib.Path(manifest["sql_dump"]["path"]).name
    sql_stage.parent.mkdir(mode=0o700, exist_ok=True)
    _snapshot_file(
        db_dir / manifest["sql_dump"]["path"], sql_stage,
        manifest["sql_dump"]["size"], manifest["sql_dump"]["sha256"], "SQL dump",
    )

    bundle_stage = stage / "bundle" / pathlib.Path(manifest["secret_bundle"]["path"]).name
    bundle_stage.parent.mkdir(mode=0o700, exist_ok=True)
    _snapshot_file(
        secrets_dir / manifest["secret_bundle"]["path"], bundle_stage,
        manifest["secret_bundle"]["size"], manifest["secret_bundle"]["sha256"],
        "secret bundle",
    )

    compose_name, has_db_json = _extract_approved(
        bundle_stage, stage, manifest["secret_bundle"]["members"]
    )
    staged = _validate_staged(stage, compose_name, has_db_json, project, runner)
    staged["has_db_json_bak"] = (stage / DB_JSON_BAK.as_posix()).is_file()
    _check_project(project, staged, stage / pathlib.PurePosixPath(compose_name))
    checks, identity = _check_db_environment(project, staged, runner)
    _container_db_check(
        project, staged, runner,
        "target database has no CRM schema or active administrator",
    )
    checks.append("container PHP database structure=ok")
    return manifest, staged, identity, checks, sql_stage


def restore(project: pathlib.Path, db_dir: pathlib.Path, secrets_dir: pathlib.Path,
            manifest_path: pathlib.Path, *, confirm: str | None = None,
            confirm_target: str | None = None, dry_run: bool = False,
            runner=None) -> None:
    runner = runner or _default_runner

    if runner is _default_runner and not LOCK_SUPPORTED:
        raise RestoreError("POSIX flock is required; refusing to run restore without a shared lock")

    project = pathlib.Path(project).resolve()
    db_dir = pathlib.Path(db_dir).resolve()
    secrets_dir = pathlib.Path(secrets_dir).resolve()
    manifest_path = pathlib.Path(manifest_path).resolve()

    guard_state: dict = {}

    # Обработчики сигналов устанавливаются ДО создания staging с секретами.
    with _interrupt_guard(guard_state):
        stage: pathlib.Path | None = None
        cleanup_stage = False
        journal_started = False
        records: list[dict] = []
        state = {
            "operation_id": uuid.uuid4().hex,
            "backup_id": None,
            "phase": "preflight",
            "status": "running",
            "updated_at": None,
            "completed_steps": [],
            "files_intended": [],
            "files_attempted": [],
            "files_changed": [],
            "files_restored": [],
            "rollback_copies": [],
            "rollback_failures": [],
            "sql_outcome": "not_started",
            "sql_exit_code": None,
            "sql_started_at": None,
            "sql_finished_at": None,
            "staging_dir": None,
            "instruction": (
                "Do not restart the application. Inspect the database manually; "
                "restore config files from staging/rollback if required."
            ),
        }

        try:
            backup_crm7.verify(manifest_path, secrets_dir)
            manifest_bytes = manifest_path.read_bytes()
            manifest = json.loads(manifest_bytes)
            backup_id = manifest["backup_id"]
            state["backup_id"] = backup_id

            if confirm is not None and confirm != backup_id:
                raise RestoreError("--confirm does not match backup_id")
            if not dry_run and confirm is None:
                raise RestoreError("--confirm <backup_id> is required for restore")

            if dry_run:
                stage = pathlib.Path(tempfile.mkdtemp(prefix=".restore-stage-", dir=str(secrets_dir)))
                os.chmod(stage, 0o700)
                cleanup_stage = True

                manifest, staged, identity, checks, _ = _prepare_view(
                    project, db_dir, secrets_dir, manifest_bytes, stage, runner,
                )
                if confirm_target is not None and confirm_target != identity["target_id"]:
                    raise RestoreError("--confirm-target does not match target environment")

                _print_plan(manifest, staged, checks, project, identity)
                return

            # Read-only preflight завершён. Блокировки захватываются ДО любых
            # изменений журнала, истории и staging.
            try:
                with update_lock(project), _restore_locks(db_dir, project, backup_id):
                    _rotate_state(db_dir, state)
                    journal_started = True
                    print(f"operation_id: {state['operation_id']}")

                    stage = pathlib.Path(tempfile.mkdtemp(prefix=".restore-stage-", dir=str(secrets_dir)))
                    os.chmod(stage, 0o700)
                    state["staging_dir"] = str(stage)
                    cleanup_stage = True

                    manifest, staged, identity, checks, sql_stage = _prepare_view(
                        project, db_dir, secrets_dir, manifest_bytes, stage, runner,
                    )

                    if confirm_target is not None and confirm_target != identity["target_id"]:
                        raise RestoreError("--confirm-target does not match target environment")
                    if confirm_target is None:
                        raise RestoreError("--confirm-target <target_id> is required; run dry-run first")

                    # С этого момента любая неопределённость сохраняет staging и копии.
                    cleanup_stage = False

                    _assert_target_identity(
                        project, identity, runner,
                        "target environment changed before configuration apply",
                    )

                    records = _prepare_rollback(project, staged, stage / "rollback")
                    state["phase"] = "rollback_prepared"
                    state["rollback_copies"] = [_rollback_metadata(record) for record in records]
                    _write_journal(db_dir, state)

                    state["phase"] = "config_apply_in_progress"
                    state["files_intended"] = [record["relative"] for record in records]
                    _write_journal(db_dir, state)

                    for record in records:
                        relative = pathlib.PurePosixPath(record["relative"])
                        mode = 0o600

                        if relative == ROOT_ENV or relative.as_posix() == staged["compose_name"]:
                            if record["existed"]:
                                mode = record["mode"]

                        # Намерение фиксируется durable ДО изменения файла.
                        state["files_attempted"].append(record["relative"])
                        state["phase"] = "config_apply_attempt:" + record["relative"]
                        _write_journal(db_dir, state)

                        _apply_one(
                            project, stage, relative,
                            pathlib.PurePosixPath(record["relative"]), mode,
                        )
                        state["files_changed"].append(record["relative"])
                        state["phase"] = "config_applied:" + record["relative"]
                        _write_journal(db_dir, state)

                    state["phase"] = "post_apply_in_progress"
                    _write_journal(db_dir, state)
                    _compose_quiet(project, runner)
                    _container_db_check(
                        project, staged, runner,
                        "restored configuration cannot connect to target database",
                    )
                    _assert_container_config(project, staged, runner)
                    state["phase"] = "post_apply_ok"
                    state["completed_steps"].append("config_applied")
                    _write_journal(db_dir, state)

                    # Все проверки ДО запуска команды: sql_outcome остаётся not_started.
                    _assert_target_identity(
                        project, identity, runner,
                        "target environment changed before SQL import",
                    )
                    _assert_staged_sql(sql_stage, manifest, "staged SQL changed before import")

                    # running фиксируется durable непосредственно перед запуском команды.
                    state["status"] = "manual_recovery_required"
                    state["phase"] = "sql_import_starting"
                    state["sql_started_at"] = _utc_now()
                    _set_sql_outcome(db_dir, state, "running")

                    print(f"sql import: {sql_stage.name}")
                    try:
                        exit_code = _sql_import(project, sql_stage, runner)
                    except BaseException:
                        # Результат неизвестен: exit code не получен.
                        state["phase"] = "sql_import_unknown"
                        state["status"] = "manual_recovery_required"
                        _set_sql_outcome(db_dir, state, "unknown")
                        cleanup_stage = False
                        raise

                    if exit_code != 0:
                        state["phase"] = "sql_import_failed"
                        state["status"] = "manual_recovery_required"
                        _set_sql_outcome(db_dir, state, "failed", exit_code, finished=True)
                        cleanup_stage = False
                        print(
                            f"SQL import failed (exit code {exit_code}); database state "
                            "requires manual verification. Journal: "
                            f"{db_dir / STATE_NAME}; staging: {stage}",
                            file=sys.stderr,
                        )
                        raise RestoreError(
                            f"sql import failed (exit code {exit_code}). Configuration was "
                            "NOT rolled back because a partial import cannot be undone. "
                            f"Journal: {db_dir / STATE_NAME}; staging: {stage}"
                        )

                    state["phase"] = "sql_import_finished"
                    _set_sql_outcome(
                        db_dir, state, "succeeded", 0, finished=True,
                        step="sql_import_finished",
                    )

                    state["phase"] = "post_checks_in_progress"
                    _write_journal(db_dir, state)
                    _container_db_check(project, staged, runner, "post-restore PHP/DB check failed")
                    _post_checks(project, staged, runner)

                    # Финализация: signals подавлены; единственный источник истины —
                    # restore-state.json со статусом complete.
                    with _suppress_interrupts(guard_state):
                        state["phase"] = "complete"
                        state["status"] = "complete"
                        state["completed_steps"].append("post_checks_ok")
                        _write_journal(db_dir, state)
                        cleanup_stage = True
            except LockBusy as error:
                # Lock не получен: журнал, история и staging не трогаются.
                raise RestoreError("restore lock is busy") from error
            except BaseException as error:
                if not journal_started:
                    # В том числе RestoreError("restore lock is busy") от ID lock.
                    raise

                if (state["sql_outcome"] != "not_started"
                        or state["phase"].startswith("sql_import_")):
                    state["status"] = "manual_recovery_required"

                    if state["phase"] == "post_checks_in_progress":
                        state["phase"] = "post_checks_failed"
                    elif state["phase"] == "complete":
                        state["phase"] = "finalization_failed"
                    elif state["phase"] == "sql_import_starting":
                        # exit code не был durable-зафиксирован -> неопределённость.
                        state["sql_outcome"] = "unknown"
                        state["sql_exit_code"] = None
                        state["phase"] = "sql_import_unknown"
                    elif state["sql_outcome"] == "running":
                        state["sql_outcome"] = "unknown"
                        state["sql_exit_code"] = None
                        state["phase"] = "sql_import_unknown"

                    _write_journal(db_dir, state)
                    cleanup_stage = False
                    print(
                        "SQL import reached an undefined result; database state requires "
                        f"manual verification. Journal: {db_dir / STATE_NAME}; staging: {stage}",
                        file=sys.stderr,
                    )
                    raise

                # До SQL: безопасный откат без прерывания повторными сигналами.
                with _suppress_interrupts(guard_state):
                    failures = _rollback_files(records, state)
                    affected = set(state["files_attempted"]) | set(state["files_changed"])
                    journal_written = True

                    if not affected:
                        state["status"] = "failed_before_changes"
                        state["phase"] = "preflight_failed"
                    else:
                        proven = not failures and affected.issubset(set(state["files_restored"]))
                        state["status"] = "rolled_back" if proven else "manual_recovery_required"
                        state["phase"] = "config_rolled_back" if proven else "rollback_incomplete"

                    try:
                        _write_journal(db_dir, state)
                    except Exception:
                        journal_written = False

                cleanup_stage = journal_written and (
                    state["status"] in ("failed_before_changes", "rolled_back")
                )

                if isinstance(error, RestoreError) and str(error).startswith("target environment changed"):
                    print(
                        "configuration rollback completed"
                        if state["status"] == "rolled_back" else
                        "configuration rollback INCOMPLETE; manual recovery required",
                        file=sys.stderr,
                    )

                raise
        except BaseException:
            if (journal_started and state["status"] == "running"
                    and not state["files_attempted"]
                    and state["sql_outcome"] == "not_started"):
                state["status"] = "failed_before_changes"
                state["phase"] = "preflight_failed"

                try:
                    _write_journal(db_dir, state)
                except Exception:
                    pass

            raise
        finally:
            if stage is not None and cleanup_stage:
                shutil.rmtree(stage, ignore_errors=True)


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="CRM7: безопасное восстановление backup-комплекта.",
        epilog=(
            "Канонический общий lock: <project>/update-crm-7/update.lock. "
            "target_id печатается в dry-run и подтверждается через --confirm-target "
            "перед изменениями и перед SQL import. SQL import не атомарен."
        ),
    )
    parser.add_argument("--project-dir")
    parser.add_argument("--db-backup-dir")
    parser.add_argument("--secrets-dir")
    parser.add_argument("--manifest")
    subparsers = parser.add_subparsers(dest="command")
    subparsers.add_parser("verify", help="проверить комплект без восстановления")
    subparsers.add_parser("status", help="показать журнал текущей операции (только чтение)")
    restore_parser = subparsers.add_parser("restore", help="восстановить комплект")
    restore_parser.add_argument("--dry-run", action="store_true",
                                help="только проверки, без изменений")
    restore_parser.add_argument("--confirm", metavar="BACKUP_ID",
                                help="точный backup_id из manifest")
    restore_parser.add_argument("--confirm-target", metavar="TARGET_ID",
                                help="target_id из вывода dry-run")
    return parser


def _show_status(db_dir: pathlib.Path) -> None:
    """Только чтение: текущая операция и путь к истории."""
    state_path = db_dir / STATE_NAME

    if not state_path.is_file():
        print("no active restore state")
        print(f"history: {db_dir / HISTORY_DIR}")
        return

    try:
        data = json.loads(state_path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise RestoreError("invalid restore state") from error

    for key in ("operation_id", "backup_id", "status", "phase", "updated_at",
                "sql_outcome", "sql_exit_code"):
        print(f"{key}: {data.get(key)}")

    print(f"state: {state_path}")
    print(f"history: {db_dir / HISTORY_DIR}")


def main(argv: list[str] | None = None) -> int:
    parser = _parser()
    args = parser.parse_args(argv)

    try:
        if args.command == "status":
            if not args.db_backup_dir:
                raise RestoreError("--db-backup-dir is required for status")

            _show_status(pathlib.Path(args.db_backup_dir).resolve())
            return 0

        if not args.manifest or not args.secrets_dir:
            raise RestoreError("--manifest and --secrets-dir are required")

        manifest_path = pathlib.Path(args.manifest)
        secrets_dir = pathlib.Path(args.secrets_dir)

        if args.command == "verify":
            backup_crm7.verify(manifest_path, secrets_dir)
            return 0

        if args.command != "restore":
            raise RestoreError("command is required: verify or restore")

        if not args.project_dir or not args.db_backup_dir:
            raise RestoreError("--project-dir and --db-backup-dir are required")

        if args.dry_run and (args.confirm or args.confirm_target):
            raise RestoreError("--dry-run and confirm options are mutually exclusive")

        restore(
            pathlib.Path(args.project_dir),
            pathlib.Path(args.db_backup_dir),
            secrets_dir,
            manifest_path,
            confirm=args.confirm,
            confirm_target=args.confirm_target,
            dry_run=args.dry_run,
        )
        return 0
    except (KeyboardInterrupt, RestoreInterrupted) as error:
        print(f"restore interrupted: {error.__class__.__name__}", file=sys.stderr)
        return 130
    except (RestoreError, backup_crm7.BackupError, OSError, ValueError,
            tarfile.TarError, subprocess.SubprocessError) as error:
        print(f"restore failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
