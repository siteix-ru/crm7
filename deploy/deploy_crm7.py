#!/usr/bin/env python3
"""Deploy CRM7 from the local working tree to the dev server.

Pipeline: build frontend -> pack dist + api (runtime data excluded) ->
upload over SFTP -> extract, fix perms, run migrations, restart php/cron.

Usage:
    python deploy/deploy_crm7.py [--server root@www.crm7.ru:21023]
                                 [--key ~/.ssh/id_ed25519]
                                 [--dir /srv/projects/crm7]
                                 [--skip-build]

Requires: paramiko (pip install paramiko), Node.js for the frontend build.
"""
import argparse
import io
import os
import pathlib
import re
import shlex
import subprocess
import sys
import tarfile

from config_permissions import protected_permissions_script
from update_lock import posix_lock_path

try:
    import paramiko
except ImportError:
    sys.exit("paramiko is required: pip install paramiko")

ROOT = pathlib.Path(__file__).resolve().parent.parent
EXCLUDE_PREFIXES = (
    "api/db/backups/",
    "api/var/",
    "api/storage/",
    "api/config/database.json.",
    "api/config/.cfg-",
)
EXCLUDE_FILES = (
    "api/config/.env",
    "api/config/database.json",
    "api/config/database.json.lock",
)
ENV_DIRS = {
    "test": "/srv/projects/crm7-test",
    "k": "/srv/projects/crm7-k",
    "demo": "/srv/projects/crm7-demo",
}


def build_frontend() -> None:
    print("== building frontend ==")
    subprocess.run(
        "npm run build",
        cwd=ROOT / "frontend",
        check=True,
        shell=(os.name == "nt"),
    )


def make_tar() -> bytes:
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        dist = ROOT / "frontend" / "dist"
        if not dist.is_dir():
            sys.exit(f"frontend build not found: {dist}")
        for path in sorted(dist.rglob("*")):
            tar.add(path, arcname=str(path.relative_to(dist)), recursive=False)
        api = ROOT / "api"
        for path in sorted(api.rglob("*")):
            rel = path.relative_to(ROOT).as_posix()
            if any(rel.startswith(p) for p in EXCLUDE_PREFIXES):
                continue
            if rel in EXCLUDE_FILES:
                continue
            tar.add(path, arcname=rel, recursive=False)
    return buf.getvalue()


def remote_script(project_dir: str, php_owner: str = "82:82") -> str:
    protected = protected_permissions_script(project_dir, php_owner)
    lock = posix_lock_path(project_dir)
    quoted_dir = shlex.quote(project_dir)
    quoted_lock = shlex.quote(str(lock))
    quoted_lock_dir = shlex.quote(str(pathlib.PurePosixPath(project_dir) / "update-crm-7"))

    return f"""set -e
mkdir -p {quoted_lock_dir}
exec 9>{quoted_lock}
flock -n 9 || {{ echo "DEPLOY_LOCK_BUSY" >&2; exit 1; }}
tar -xzf /tmp/crm7-deploy.tgz -C {shlex.quote(project_dir + "/app")}
chown -R {php_owner} {shlex.quote(project_dir + "/app")}
find {shlex.quote(project_dir + "/app")} -type d -exec chmod 755 {{}} \\;
find {shlex.quote(project_dir + "/app")} -type f -exec chmod 644 {{}} \\;
{protected}
cd {quoted_dir}
docker compose exec -T php php /var/www/html/api/bin/migrate.php
docker compose restart php cron
echo DEPLOY_OK
"""


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--server", default="root@www.crm7.ru:21023")
    ap.add_argument("--key", default=str(pathlib.Path.home() / ".ssh" / "id_ed25519"))
    ap.add_argument("--env", choices=sorted(ENV_DIRS), default=None)
    ap.add_argument("--dir", default=None)
    ap.add_argument("--skip-build", action="store_true")
    ap.add_argument(
        "--php-owner",
        default="82:82",
        help="владелец конфигурации, читаемый PHP-FPM (по умолчанию 82:82 для Docker)",
    )
    args = ap.parse_args()

    if re.fullmatch(r"[A-Za-z0-9_.-]+:[A-Za-z0-9_.-]+", args.php_owner) is None:
        ap.error("--php-owner должен иметь вид user:group")

    if args.dir is None:
        if args.env is None:
            ap.error("укажите --env test|k|demo|prod (или --dir)")
        args.dir = ENV_DIRS[args.env]

    # Удалённый проект должен быть абсолютным POSIX-путём, иначе lock-файл
    # будет физически другим по сравнению с updater/restore.
    try:
        posix_lock_path(args.dir)
    except ValueError as error:
        ap.error(str(error))

    if not args.skip_build:
        build_frontend()

    data = make_tar()
    print(f"archive: {len(data)} bytes")

    user, _, hostport = args.server.partition("@")
    if not hostport:
        user, hostport = "root", args.server
    host, _, port = hostport.partition(":")
    port = int(port or 22)

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(host, port=port, username=user, key_filename=args.key, timeout=15)

    sftp = client.open_sftp()
    with sftp.open("/tmp/crm7-deploy.tgz", "wb") as handle:
        handle.write(data)
    sftp.close()
    print("uploaded")

    stdin, stdout, stderr = client.exec_command(
        remote_script(args.dir, args.php_owner), timeout=300
    )
    out = stdout.read().decode("utf-8", "replace")
    err = stderr.read().decode("utf-8", "replace")
    rc = stdout.channel.recv_exit_status()
    client.close()

    sys.stdout.write(out)
    if err.strip():
        sys.stderr.write(err)
    if rc != 0 or "DEPLOY_OK" not in out:
        print("deploy FAILED", file=sys.stderr)
        return 1
    print("deploy OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
