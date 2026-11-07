#!/usr/bin/env python3
"""Publish the CRM7 update knowledge base to update.crm7.ru.

The public site is served by nginx from /srv/build/crm7-update/public. This
script uploads the static files from deploy/update-site/public without touching
the v1/ release feed, which is published separately by
deploy/publish_update_feed.py.

Examples:
    python deploy/publish_update_site.py --dry-run
    python deploy/publish_update_site.py
"""
from __future__ import annotations

import argparse
import pathlib
import posixpath
import sys

try:
    import paramiko
except ImportError:
    sys.exit("paramiko is required: pip install paramiko")

ROOT = pathlib.Path(__file__).resolve().parent.parent
SOURCE = ROOT / "deploy" / "update-site" / "public"


def files_to_publish() -> list[pathlib.Path]:
    if not SOURCE.is_dir():
        raise SystemExit(f"site source not found: {SOURCE}")
    return sorted(path for path in SOURCE.rglob("*") if path.is_file())


def ensure_remote_dir(sftp: paramiko.SFTPClient, path: str) -> None:
    parts = path.strip("/").split("/")
    current = ""
    for part in parts:
        current += "/" + part
        try:
            sftp.stat(current)
        except IOError:
            sftp.mkdir(current)


def publish(server: str, key: str, remote_dir: str, dry_run: bool) -> int:
    files = files_to_publish()
    if dry_run:
        for path in files:
            relative = path.relative_to(SOURCE).as_posix()
            print(f"would publish: {relative} -> {posixpath.join(remote_dir, relative)}")
        print(f"dry-run: {len(files)} files, nothing uploaded")
        return 0

    user, _, hostport = server.partition("@")
    if not hostport:
        user, hostport = "root", server
    host, _, port = hostport.partition(":")
    port = int(port or 22)

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(host, port=port, username=user, key_filename=key, timeout=15)
    try:
        sftp = client.open_sftp()
        try:
            sftp.stat(remote_dir)
        except IOError:
            raise SystemExit(f"remote dir not found: {remote_dir}")

        for path in files:
            relative = path.relative_to(SOURCE).as_posix()
            remote = posixpath.join(remote_dir, relative)
            ensure_remote_dir(sftp, posixpath.dirname(remote))
            temporary = remote + ".tmp"
            sftp.put(str(path), temporary)
            try:
                sftp.posix_rename(temporary, remote)
            except IOError:
                try:
                    sftp.remove(remote)
                except IOError:
                    pass
                sftp.rename(temporary, remote)
            print(f"published: {relative}")
        sftp.close()
    finally:
        client.close()

    print(f"site OK: {len(files)} files")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Publish the CRM7 update knowledge base")
    parser.add_argument("--server", default="root@www.crm7.ru:21023")
    parser.add_argument("--key", default=str(pathlib.Path.home() / ".ssh" / "id_ed25519"))
    parser.add_argument("--remote-dir", default="/srv/build/crm7-update/public")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    return publish(args.server, args.key, args.remote_dir, args.dry_run)


if __name__ == "__main__":
    raise SystemExit(main())
