#!/usr/bin/env python3
"""Render and publish the public CRM7 update feed.

The reviewed source of truth is ``deploy/update-feed.json``. This script validates
it against the local git history and publishes two files atomically:

    v1/releases.json  releases, commits and image references
    v1/health.json    feed status

Examples:
    python deploy/publish_update_feed.py --dry-run
    python deploy/publish_update_feed.py --verify-images
    python deploy/publish_update_feed.py
"""
from __future__ import annotations

import argparse
import json
import pathlib
import re
import subprocess
import sys
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone

ROOT = pathlib.Path(__file__).resolve().parent.parent
SOURCE = ROOT / "deploy" / "update-feed.json"
VERSION_RE = re.compile(r"^(\d+)\.(\d+)\.(\d+)$")
COMMIT_RE = re.compile(r"^[0-9a-f]{7,40}$")
DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
IMAGE_REPOS = {
    "app": "ghcr.io/siteix-ru/crm7-app",
    "web": "ghcr.io/siteix-ru/crm7-web",
}
MOSCOW = timezone(timedelta(hours=3))
MANIFEST_ACCEPT = ", ".join(
    (
        "application/vnd.oci.image.index.v1+json",
        "application/vnd.docker.distribution.manifest.list.v2+json",
        "application/vnd.docker.distribution.manifest.v2+json",
    )
)


def version_tuple(value: str) -> tuple[int, int, int] | None:
    match = VERSION_RE.match(value.strip())
    if not match:
        return None
    return tuple(int(part) for part in match.groups())


def load_source(path: pathlib.Path) -> dict:
    try:
        source = json.loads(path.read_text(encoding="utf-8-sig"))
    except FileNotFoundError:
        raise SystemExit(f"feed source not found: {path}")
    except json.JSONDecodeError as error:
        raise SystemExit(f"invalid feed source: {error}")
    if not isinstance(source, dict):
        raise SystemExit("invalid feed source: object expected")
    return source


def git_commit_exists(commit: str) -> bool:
    try:
        result = subprocess.run(
            ["git", "cat-file", "-e", f"{commit}^{{commit}}"],
            cwd=ROOT,
            capture_output=True,
        )
    except FileNotFoundError:
        raise SystemExit("git is required to validate commits")
    return result.returncode == 0


def validate(source: dict) -> list[dict]:
    if source.get("schema") != 1:
        raise SystemExit(f"unsupported schema: {source.get('schema')!r}")
    for key in ("channel", "repository", "documentation"):
        value = source.get(key)
        if not isinstance(value, str) or not value.strip():
            raise SystemExit(f"feed source: {key} is required")

    releases = source.get("releases")
    if not isinstance(releases, list) or not releases:
        raise SystemExit("feed source: releases must be a non-empty list")

    seen: set[str] = set()
    previous: tuple[int, int, int] | None = None
    result: list[dict] = []

    for index, release in enumerate(releases, 1):
        if not isinstance(release, dict):
            raise SystemExit(f"release #{index}: object expected")

        version = str(release.get("version", "")).strip()
        current = version_tuple(version)
        if current is None:
            raise SystemExit(f"release #{index}: invalid version {version!r}")
        if version in seen:
            raise SystemExit(f"release {version}: duplicate version")
        if previous is not None and current <= previous:
            raise SystemExit(f"release {version}: versions must be strictly ascending")
        previous = current

        commit = str(release.get("commit", "")).strip()
        if not COMMIT_RE.match(commit):
            raise SystemExit(f"release {version}: invalid commit {commit!r}")
        if not git_commit_exists(commit):
            raise SystemExit(f"release {version}: commit {commit} not found in git")

        released_at = str(release.get("released_at", "")).strip()
        if not DATE_RE.match(released_at):
            raise SystemExit(f"release {version}: released_at must be YYYY-MM-DD")

        notes = str(release.get("notes", "")).strip()
        if not notes:
            raise SystemExit(f"release {version}: notes are required")

        min_from = str(release.get("min_from", "")).strip()
        previous_version = result[-1]["version"] if result else None

        if previous_version is None:
            if min_from != "":
                raise SystemExit(f"release {version}: first release must have empty min_from")
        elif min_from != previous_version:
            raise SystemExit(
                f"release {version}: min_from must be the previous release ({previous_version})"
            )

        seen.add(version)
        result.append(
            {
                "version": version,
                "released_at": released_at,
                "commit": commit,
                "min_from": min_from,
                "notes": notes,
            }
        )

    return result


def render(source: dict, releases: list[dict]) -> tuple[dict, dict]:
    generated_at = datetime.now(MOSCOW).replace(microsecond=0).isoformat()
    rendered = []
    for release in releases:
        version = release["version"]
        rendered.append(
            {
                "version": version,
                "released_at": release["released_at"],
                "commit": release["commit"],
                "min_from": release["min_from"],
                "images": {
                    "app": f"{IMAGE_REPOS['app']}:{version}",
                    "web": f"{IMAGE_REPOS['web']}:{version}",
                },
                "notes": release["notes"],
            }
        )

    manifest = {
        "schema": 1,
        "channel": source["channel"],
        "generated_at": generated_at,
        "repository": source["repository"],
        "documentation": source["documentation"],
        "releases": rendered,
    }
    health = {
        "ok": True,
        "service": "crm7-update",
        "channel": source["channel"],
        "updated_at": generated_at,
    }
    return manifest, health


def dump_json(data: dict) -> str:
    return json.dumps(data, ensure_ascii=False, indent=2) + "\n"


def registry_token(repo: str) -> str:
    url = f"https://ghcr.io/token?service=ghcr.io&scope=repository:{repo}:pull"
    with urllib.request.urlopen(url, timeout=20) as response:
        return json.load(response)["token"]


def image_exists(image: str) -> bool:
    repo, _, tag = image.removeprefix("ghcr.io/").rpartition(":")
    if not repo or not tag:
        raise SystemExit(f"invalid image reference: {image}")
    token = registry_token(repo)
    request = urllib.request.Request(
        f"https://ghcr.io/v2/{repo}/manifests/{tag}",
        headers={"Authorization": f"Bearer {token}", "Accept": MANIFEST_ACCEPT},
        method="HEAD",
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            return response.status == 200
    except urllib.error.HTTPError as error:
        if error.code == 404:
            return False
        raise


def verify_images(manifest: dict) -> None:
    for release in manifest["releases"]:
        for name, image in release["images"].items():
            if not image_exists(image):
                raise SystemExit(f"release {release['version']}: image not found: {image}")
            print(f"image OK: {name} {image}")


def publish(server: str, key: str, remote_dir: str, files: dict[str, str]) -> None:
    try:
        import paramiko
    except ImportError:
        raise SystemExit("paramiko is required: pip install paramiko")

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

        for name, content in files.items():
            remote = f"{remote_dir.rstrip('/')}/{name}"
            temporary = remote + ".tmp"
            with sftp.open(temporary, "wb") as handle:
                handle.write(content.encode("utf-8"))
            try:
                sftp.posix_rename(temporary, remote)
            except IOError:
                try:
                    sftp.remove(remote)
                except IOError:
                    pass
                sftp.rename(temporary, remote)
            print(f"published: {remote}")
        sftp.close()
    finally:
        client.close()


def main() -> int:
    parser = argparse.ArgumentParser(description="Публикация фида обновлений CRM7")
    parser.add_argument("--source", default=str(SOURCE))
    parser.add_argument("--server", default="root@www.crm7.ru:21023")
    parser.add_argument("--key", default=str(pathlib.Path.home() / ".ssh" / "id_ed25519"))
    parser.add_argument("--remote-dir", default="/srv/build/crm7-update/public/v1")
    parser.add_argument("--verify-images", action="store_true", help="проверить теги в GHCR")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    source = load_source(pathlib.Path(args.source))
    releases = validate(source)
    manifest, health = render(source, releases)

    if args.verify_images:
        verify_images(manifest)

    files = {
        "releases.json": dump_json(manifest),
        "health.json": dump_json(health),
    }

    if args.dry_run:
        print("=== v1/releases.json ===")
        print(files["releases.json"], end="")
        print("=== v1/health.json ===")
        print(files["health.json"], end="")
        print(f"dry-run: {len(releases)} releases, nothing published")
        return 0

    publish(args.server, args.key, args.remote_dir, files)
    print(f"feed OK: {len(releases)} releases, latest {releases[-1]['version']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
