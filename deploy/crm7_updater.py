#!/usr/bin/env python3
"""CRM7 updater: checks the public update feed and applies one release.

Examples:
    python deploy/crm7_updater.py --check
    python deploy/crm7_updater.py --check --current 1.4.1
    python deploy/crm7_updater.py --apply --project-dir /srv/projects/crm7-demo --yes
    python deploy/crm7_updater.py --apply --project-dir /srv/projects/crm7-demo --dry-run

The update feed has no license tokens. Images are pulled from the public registry
configured in the project's docker-compose.yml. Before changing anything the
updater dumps the database (mode 0600) and keeps the dump if the update fails.
"""
from __future__ import annotations

import argparse
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import time
import urllib.request
from datetime import datetime

DEFAULT_URL = "https://update.crm7.ru/v1/releases.json"
VERSION_RE = re.compile(r"^(\d+)\.(\d+)\.(\d+)")
MAX_MANIFEST_BYTES = 1024 * 1024
COMPOSE_FILES = ("docker-compose.yml", "docker-compose.yaml", "compose.yml", "compose.yaml")


def version_tuple(value: str) -> tuple[int, int, int] | None:
    match = VERSION_RE.match(value.strip())
    if not match:
        return None
    return tuple(int(part) for part in match.groups())


def read_version(env_file: pathlib.Path, explicit: str | None) -> str:
    if explicit:
        return explicit.strip()

    if not env_file.exists():
        raise SystemExit(f"env file not found: {env_file}")

    for line in env_file.read_text(encoding="utf-8").splitlines():
        if line.startswith("CRM7_VERSION="):
            return line.split("=", 1)[1].strip().strip('"').strip("'")

    raise SystemExit(f"CRM7_VERSION not found in {env_file}")


def fetch_manifest(url: str) -> dict:
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "crm7-updater/1.0", "Accept": "application/json"},
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        raw = response.read(MAX_MANIFEST_BYTES + 1)
    if len(raw) > MAX_MANIFEST_BYTES:
        raise SystemExit("update manifest is too large")
    try:
        manifest = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise SystemExit(f"invalid update manifest: {error}")
    if not isinstance(manifest, dict):
        raise SystemExit("invalid update manifest: object expected")
    return manifest


def next_release(manifest: dict, current: str) -> dict | None:
    current_tuple = version_tuple(current)
    if current_tuple is None:
        raise SystemExit(f"unsupported version: {current!r}")

    candidates = []
    for release in manifest.get("releases", []):
        if not isinstance(release, dict):
            continue
        version = str(release.get("version", ""))
        release_tuple = version_tuple(version)
        if release_tuple is None or release_tuple <= current_tuple:
            continue
        min_from = str(release.get("min_from", "")).strip()
        if min_from:
            min_tuple = version_tuple(min_from)
            if min_tuple is None:
                continue
            if current_tuple < min_tuple:
                continue
        candidates.append((release_tuple, release))

    if not candidates:
        return None

    candidates.sort(key=lambda item: item[0])
    return candidates[0][1]


def print_release(current: str, release: dict | None) -> None:
    print(f"Текущая версия: {current}")
    if release is None:
        print("Обновлений нет.")
        return

    print(f"Следующая версия: {release.get('version')} ({release.get('released_at', 'дата не указана')})")
    notes = str(release.get("notes", "")).strip()
    if notes:
        print(f"Изменения: {notes}")
    images = release.get("images", {})
    if isinstance(images, dict):
        for name, image in images.items():
            print(f"{name}: {image}")


def run(command: list[str], cwd: pathlib.Path, dry_run: bool) -> None:
    print("+", " ".join(command))
    if dry_run:
        return
    result = subprocess.run(command, cwd=cwd)
    if result.returncode != 0:
        raise RuntimeError(f"command failed ({result.returncode}): {' '.join(command)}")


def set_version(env_file: pathlib.Path, version: str) -> None:
    lines = env_file.read_text(encoding="utf-8").splitlines()
    updated = False
    result = []
    for line in lines:
        if line.startswith("CRM7_VERSION="):
            result.append(f"CRM7_VERSION={version}")
            updated = True
        else:
            result.append(line)
    if not updated:
        result.append(f"CRM7_VERSION={version}")

    mode = env_file.stat().st_mode & 0o777
    temporary = env_file.with_name(env_file.name + ".tmp")
    temporary.write_text("\n".join(result) + "\n", encoding="utf-8")
    os.chmod(temporary, mode)
    os.replace(temporary, env_file)


def backup_database(project: pathlib.Path, backup_dir: pathlib.Path, dry_run: bool) -> pathlib.Path:
    target = backup_dir / f"crm7-{datetime.now():%Y%m%d-%H%M%S}.sql"
    command = [
        "docker", "compose", "exec", "-T", "db", "sh", "-lc",
        'dump=$(command -v mariadb-dump || command -v mysqldump) || exit 127; '
        'exec "$dump" --single-transaction --quick '
        '-u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"',
    ]
    print("+", " ".join(command), ">", target)
    if dry_run:
        return target

    backup_dir.mkdir(parents=True, exist_ok=True)
    with target.open("wb") as handle:
        result = subprocess.run(command, cwd=project, stdout=handle)
    if result.returncode != 0 or target.stat().st_size == 0:
        target.unlink(missing_ok=True)
        raise RuntimeError("database backup failed")
    os.chmod(target, 0o600)
    print(f"backup: {target} ({target.stat().st_size} bytes)")
    return target


def prune_backups(backup_dir: pathlib.Path, keep: int) -> None:
    if keep <= 0:
        return
    backups = sorted(
        backup_dir.glob("crm7-*.sql"),
        key=lambda path: path.stat().st_mtime,
        reverse=True,
    )
    for old in backups[keep:]:
        try:
            old.unlink()
            print(f"backup removed: {old}")
        except OSError as error:
            print(f"backup cleanup failed: {old}: {error}", file=sys.stderr)


def wait_for_db(project: pathlib.Path, dry_run: bool, timeout: int = 60) -> None:
    if dry_run:
        print("+ wait for database readiness")
        return

    command = [
        "docker", "compose", "exec", "-T", "db",
        "healthcheck.sh", "--connect", "--innodb_initialized",
    ]
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        result = subprocess.run(command, cwd=project, capture_output=True, text=True)
        if result.returncode == 0:
            return
        time.sleep(2)
    raise RuntimeError("database did not become ready")


def health(project: pathlib.Path, timeout: int = 30) -> bool:
    command = ["docker", "compose", "exec", "-T", "web", "wget", "-qO-", "http://127.0.0.1/api/v2/health"]
    deadline = time.monotonic() + timeout
    last_output = ""
    while time.monotonic() < deadline:
        try:
            result = subprocess.run(command, cwd=project, capture_output=True, text=True, timeout=10)
            last_output = (result.stdout or "").strip()
            if result.returncode == 0 and '"status":"ok"' in last_output.replace(" ", ""):
                print(last_output)
                return True
        except Exception as error:
            last_output = str(error)
        time.sleep(2)

    print(f"health check failed: {last_output}", file=sys.stderr)
    return False


def apply_release(project: pathlib.Path, env_file: pathlib.Path, release: dict, args: argparse.Namespace) -> int:
    current = read_version(env_file, args.current)
    target = str(release["version"])
    print_release(current, release)

    if not args.dry_run and not args.yes:
        answer = input(f"Обновить {current} -> {target}? [y/N]: ").strip().lower()
        if answer not in ("y", "yes", "д", "да"):
            print("Отменено.")
            return 0

    backup_dir = pathlib.Path(args.backup_dir) if args.backup_dir else project / "update-crm-7"
    backup = None
    if not args.skip_backup:
        backup = backup_database(project, backup_dir, args.dry_run)
    else:
        print("backup: пропущен (--skip-backup)")

    if args.dry_run:
        print(f"+ set CRM7_VERSION={target} in {env_file}")
    else:
        set_version(env_file, target)

    try:
        run(["docker", "compose", "pull"], project, args.dry_run)
        run(["docker", "compose", "up", "-d", "db"], project, args.dry_run)
        wait_for_db(project, args.dry_run)
        run(["docker", "compose", "run", "--rm", "php", "php", "/var/www/html/api/bin/migrate.php"], project, args.dry_run)
        run(["docker", "compose", "up", "-d"], project, args.dry_run)

        if args.dry_run:
            print("+ wait for healthy web (http://127.0.0.1/api/v2/health)")
            print("dry-run: обновление не выполнялось.")
            return 0

        if not health(project):
            raise RuntimeError("health check failed; database backup kept")
    except Exception as error:
        print(f"update failed: {error}", file=sys.stderr)
        if backup is not None:
            print(f"backup kept: {backup}", file=sys.stderr)
        if args.dry_run:
            return 1
        print("Откатываю версию контейнеров; миграции не откатываются.", file=sys.stderr)
        try:
            set_version(env_file, current)
            run(["docker", "compose", "up", "-d"], project, False)
        except Exception as rollback_error:
            print(f"rollback failed: {rollback_error}", file=sys.stderr)
        return 1

    if backup is not None and not args.skip_backup:
        prune_backups(backup_dir, args.keep_backups)

    print(f"Готово: CRM7 обновлён до {target}.")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Проверка и применение обновлений CRM7")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--check", action="store_true", help="показать доступное обновление")
    group.add_argument("--apply", action="store_true", help="применить следующую версию")
    parser.add_argument("--url", default=DEFAULT_URL)
    parser.add_argument("--current", help="текущая версия, если её нет в .env")
    parser.add_argument("--project-dir", default=".")
    parser.add_argument("--env-file", help="путь к .env проекта")
    parser.add_argument("--backup-dir", help="каталог резервных копий БД")
    parser.add_argument("--skip-backup", action="store_true")
    parser.add_argument("--keep-backups", type=int, default=10, help="сколько дампов БД хранить")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--yes", action="store_true", help="не спрашивать подтверждение")
    args = parser.parse_args()

    if args.keep_backups < 0:
        raise SystemExit("--keep-backups must be zero or greater")

    project = pathlib.Path(args.project_dir).resolve()
    env_file = pathlib.Path(args.env_file).resolve() if args.env_file else project / ".env"

    manifest = fetch_manifest(args.url)
    if manifest.get("schema") != 1:
        raise SystemExit(f"unsupported update manifest schema: {manifest.get('schema')!r}")

    current = read_version(env_file, args.current)
    release = next_release(manifest, current)

    if args.check:
        print_release(current, release)
        return 0

    if release is None:
        print("Обновлений нет.")
        return 0

    if not project.is_dir():
        raise SystemExit(f"project dir not found: {project}")
    if not any((project / name).is_file() for name in COMPOSE_FILES):
        raise SystemExit(f"docker compose file not found in {project}")
    if not env_file.is_file():
        raise SystemExit(f"env file not found: {env_file}")
    if not args.dry_run and shutil.which("docker") is None:
        raise SystemExit("docker is not installed or not in PATH")

    return apply_release(project, env_file, release, args)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (KeyboardInterrupt, EOFError):
        print("\nОтменено.")
        raise SystemExit(130)
