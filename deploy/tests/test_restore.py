#!/usr/bin/env python3
"""Тесты restore_crm7.py без Docker, реальной БД и реальных ключей."""
from __future__ import annotations

import argparse
import hashlib
import shutil
import io
import json
import os
import pathlib
import secrets
import signal
import stat
import subprocess
import sys
import threading
import time
import tarfile
import tempfile
import unittest
from contextlib import redirect_stdout, redirect_stderr
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parent.parent.parent
sys.path.insert(0, str(ROOT / "deploy"))

import backup_crm7  # noqa: E402
import restore_crm7 as restore  # noqa: E402


DEFAULT_ID = "20260101T000000Z-abcdef12"
FAKE_SQL = b"-- fake dump\nCREATE TABLE users (ID INT);\n"
COMPOSE_DATA = b"name: crm7\nservices: {}\n"
ROOT_ENV_DATA = (
    b"CRM7_VERSION=1.8.18\nDB_NAME=crm_test\nDB_USER=crm_app\n"
    b"DB_PASS=unit-pass\nDB_ROOT_PASS=unit-root-pass\n"
)
PHP_ENV_DATA = (
    b"APP_KEY=unit-app-key\nDB_CONFIG_KEY=unit-dbcfg-key\n"
    b"DB_NAME=crm_test\nDB_USER=crm_app\nDB_HOST=db\nDB_PORT=3306\nDB_PASS=unit-pass\n"
)
DB_JSON_DATA = json.dumps({
    "host": "db", "port": 3306, "database": "crm_test", "user": "crm_app",
    "password": "dbcfg:v1:fake",
}).encode("utf-8")

DEFAULT_FILES = {
    ".env": ROOT_ENV_DATA,
    "api/config/.env": PHP_ENV_DATA,
    "compose.yaml": COMPOSE_DATA,
    "api/config/database.json": DB_JSON_DATA,
}

SECRET_MARKERS = ("unit-pass", "unit-root-pass", "unit-dbcfg-key",
                  "unit-app-key", "dbcfg:v1:fake")


class FakeResult:
    def __init__(self, returncode: int = 0, stdout: bytes = b"", stderr: bytes = b"") -> None:
        self.returncode = returncode
        self.stdout = stdout
        self.stderr = stderr


class FakeRunner:
    def __init__(self, *, db_name: str = "crm_test", db_user: str = "crm_app",
                 volume: str = "/srv/projects/crm7/data/db",
                 container: str = "abc123def456", compose_project: str = "crm7-test",
                 php_decrypt_ok: bool = True, php_live_ok: bool = True,
                 post_apply_db_ok: bool = True,
                 password_hash_ok: bool = True, container_hash_ok: bool = True,
                 docker_ok: bool = True, no_users: bool = False, no_admin: bool = False,
                 db_ok: bool = True, sql_ok: bool = True, health_ok: bool = True,
                 containers: list[str] | None = None,
                 extra_mysql_mount: bool = False,
                 sql_stderr: bytes = b"") -> None:
        self.db_name = db_name
        self.db_user = db_user
        self.volume = volume
        self.container = container
        self.compose_project = compose_project
        self.php_decrypt_ok = php_decrypt_ok
        self.php_live_ok = php_live_ok
        self.post_apply_db_ok = post_apply_db_ok
        self.db_check_calls = 0
        self.password_hash_ok = password_hash_ok
        self.container_hash_ok = container_hash_ok
        self.docker_ok = docker_ok
        self.no_users = no_users
        self.no_admin = no_admin
        self.db_ok = db_ok
        self.sql_ok = sql_ok
        self.health_ok = health_ok
        self.containers = containers
        self.extra_mysql_mount = extra_mysql_mount
        self.sql_stderr = sql_stderr
        self.calls: list[list[str]] = []
        self.stdin_paths: list[str] = []

    def __call__(self, command, cwd=None, **kwargs):
        self.calls.append(list(command))
        stdin = kwargs.get("stdin")

        if stdin is not None:
            self.stdin_paths.append(getattr(stdin, "name", str(stdin)))

        joined = " ".join(command)

        if command[0] == "php":
            script = command[2]

            if "hash" in command:
                digest = (hashlib.sha256(b"unit-pass").hexdigest()
                          if self.password_hash_ok else "0" * 64)
                return FakeResult(0 if self.php_decrypt_ok else 2,
                                  ("HASH " + digest + "\n").encode())

            return FakeResult(0 if self.php_decrypt_ok else 2, b"OK\n")

        if command[0] == "docker" and "inspect" in command:
            if not self.docker_ok:
                return FakeResult(1)
            fmt = command[3]

            if "com.docker.compose.project" in fmt:
                return FakeResult(0, (self.compose_project + "\n").encode())

            mounts = f"/var/lib/mysql={self.volume};"

            if self.extra_mysql_mount:
                mounts += f"/var/lib/mysql={self.volume}-copy;"

            mounts += "/etc/hosts=/etc/hosts"
            return FakeResult(0, mounts.encode())

        if command[0] == "docker" and "ps" in command:
            values = self.containers if self.containers is not None else [self.container]
            return FakeResult(0 if self.docker_ok else 1, ("\n".join(values) + "\n").encode())

        if command[0] == "docker" and "php" in command and "-r" in command:
            script = command[7]

            if "SHOW TABLES" in script:
                self.db_check_calls += 1

                if not self.php_live_ok:
                    return FakeResult(2)
                if self.db_check_calls >= 2 and not self.post_apply_db_ok:
                    return FakeResult(2)
                if self.no_users:
                    return FakeResult(5)
                if self.no_admin:
                    return FakeResult(6)
                return FakeResult(0, b"DB_OK\n")

            if "hash_file" in script:
                if not self.container_hash_ok:
                    return FakeResult(0, b"HASH " + b"0" * 64 + b"\n")
                payload = DB_JSON_DATA if command[-1].endswith("database.json") else PHP_ENV_DATA
                return FakeResult(0, ("HASH " + hashlib.sha256(payload).hexdigest() + "\n").encode())

        if "printenv" in command:
            name = command[-1]
            value = self.db_name if name == "MARIADB_DATABASE" else self.db_user
            return FakeResult(0, (value + "\n").encode())

        if "healthcheck.sh" in command:
            return FakeResult(0 if self.db_ok else 1)

        if "config" in command and "-q" in command:
            return FakeResult(0)

        if "wget" in command:
            return FakeResult(0 if self.health_ok else 1,
                              b'{"status":"ok","database":"ok"}')

        if "docker" in joined and "sh" in command:
            return FakeResult(0 if self.sql_ok else 1, b"", self.sql_stderr)

        return FakeResult(0)


class RestoreFixture(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.base = pathlib.Path(self.tmp.name)
        self.project = self.base / "project"
        self.db_dir = self.base / "db-backups"
        self.secrets_dir = self.base / "protected-secrets"
        self.db_dir.mkdir()
        self.secrets_dir.mkdir()

        (self.project / "api" / "config").mkdir(parents=True)
        (self.project / "frontend").mkdir()
        (self.project / ".env").write_bytes(ROOT_ENV_DATA)
        (self.project / "compose.yaml").write_bytes(COMPOSE_DATA)
        (self.project / "api" / "config" / ".env").write_bytes(PHP_ENV_DATA)
        (self.project / "api" / "config" / "database.json").write_bytes(DB_JSON_DATA)
        (self.project / "frontend" / "package.json").write_text(
            json.dumps({"version": "1.8.18"}), encoding="utf-8"
        )

        self.runner = FakeRunner()
        self.manifest_path = self.build_bundle()

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def build_bundle(self, files: dict[str, bytes] | None = None, *,
                     sql: bytes = FAKE_SQL, backup_id: str = DEFAULT_ID,
                     special: dict[str, bytes] | None = None) -> pathlib.Path:
        files = dict(DEFAULT_FILES if files is None else files)
        special = special or {}
        files.update({name: b"" for name in special})

        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode="w") as archive:
            for name, data in files.items():
                info = tarfile.TarInfo(name)
                info.type = special.get(name, tarfile.REGTYPE)
                info.mode = 0o600
                info.linkname = "/etc/passwd"
                info.size = 0 if info.type != tarfile.REGTYPE else len(data)
                archive.addfile(info, io.BytesIO(data) if info.size else None)

        payload = buffer.getvalue()
        bundle = self.secrets_dir / f"crm7-{backup_id}.secrets.tar"
        bundle.write_bytes(payload)
        sql_path = self.db_dir / f"crm7-{backup_id}.sql"
        sql_path.write_bytes(sql)

        manifest = {
            "format_version": backup_crm7.MANIFEST_FORMAT,
            "backup_id": backup_id,
            "created_at": "2026-01-01T00:00:00Z",
            "crm7_version": "1.8.18",
            "database_engine": "mariadb",
            "database_schema_version": None,
            "sql_dump": {
                "path": sql_path.name,
                "size": len(sql),
                "sha256": hashlib.sha256(sql).hexdigest(),
            },
            "secret_bundle": {
                "path": bundle.name,
                "size": len(payload),
                "sha256": hashlib.sha256(payload).hexdigest(),
                "members": sorted(files),
                "encrypted": False,
            },
            "included_components": sorted(files),
            "missing_optional_components": [],
        }
        path = self.db_dir / f"crm7-{backup_id}.manifest.json"
        path.write_text(json.dumps(manifest), encoding="utf-8")
        return path

    def target_id(self, runner=None) -> str:
        return restore._target_identity(self.project, runner or self.runner)["target_id"]

    def do_restore(self, *, dry_run: bool = False, confirm: str | None = DEFAULT_ID,
                   runner=None, manifest=None, confirm_target=True):
        runner = runner or self.runner

        if confirm_target is True:
            target = self.target_id(runner)
        else:
            target = confirm_target

        output, errors = io.StringIO(), io.StringIO()

        with redirect_stdout(output), redirect_stderr(errors):
            restore.restore(
                self.project, self.db_dir, self.secrets_dir,
                manifest or self.manifest_path,
                confirm=confirm, confirm_target=target, dry_run=dry_run,
                runner=runner,
            )

        return output.getvalue(), errors.getvalue()

    def snapshot(self) -> dict[str, bytes]:
        return {path.relative_to(self.project).as_posix(): path.read_bytes()
                for path in sorted(self.project.rglob("*")) if path.is_file()}

    def journal(self) -> dict:
        return json.loads((self.db_dir / restore.JOURNAL_NAME).read_text(encoding="utf-8"))


class ContainerPhpTest(RestoreFixture):
    def test_php_db_checks_run_inside_compose_container(self) -> None:
        self.do_restore()

        container_checks = [cmd for cmd in self.runner.calls
                            if cmd[:7] == ["docker", "compose", "exec", "-T",
                                           "php", "php", "-r"]]
        self.assertTrue(container_checks)
        self.assertTrue(any("SHOW TABLES" in cmd[7] for cmd in container_checks))

    def test_local_php_is_not_used_for_live_db_check(self) -> None:
        self.do_restore()

        for command in self.runner.calls:
            if command[0] == "php":
                self.assertNotIn("DatabaseSettings", command[2])

    def test_compose_php_failure_rolls_back_before_sql(self) -> None:
        before = self.snapshot()
        self.runner.post_apply_db_ok = False

        with self.assertRaisesRegex(restore.RestoreError, "cannot connect"):
            self.do_restore()

        self.assertEqual(self.snapshot(), before)
        self.assertFalse(any("MYSQL_PWD" in " ".join(cmd) for cmd in self.runner.calls))
        self.assertEqual(self.journal()["status"], "rolled_back")

    def test_docker_error_blocks_restore(self) -> None:
        self.runner.docker_ok = False

        with self.assertRaises(restore.RestoreError):
            self.do_restore()

        self.assertEqual(self.snapshot()["api/config/.env"], PHP_ENV_DATA)


class TargetIdentityTest(RestoreFixture):
    def test_target_id_is_printed_in_dry_run(self) -> None:
        stdout, _ = self.do_restore(dry_run=True)

        self.assertIn(f"target_id: {self.target_id()}", stdout)

    def test_confirm_target_is_required(self) -> None:
        with self.assertRaisesRegex(restore.RestoreError, "confirm-target"):
            self.do_restore(confirm_target=False)

    def test_confirm_target_mismatch_is_rejected(self) -> None:
        with self.assertRaisesRegex(restore.RestoreError, "confirm-target"):
            self.do_restore(confirm_target="wrong-target")

    def test_same_name_user_but_different_volume_changes_target_id(self) -> None:
        first = self.target_id()
        self.runner.volume = "/srv/other/volume"

        self.assertNotEqual(self.target_id(), first)
        with self.assertRaisesRegex(restore.RestoreError, "confirm-target"):
            self.do_restore(confirm_target=first)

    def test_compose_project_or_container_is_part_of_identity(self) -> None:
        first = self.target_id()
        self.runner.compose_project = "crm7-other"

        self.assertNotEqual(self.target_id(), first)

    def test_password_mismatch_between_compose_and_php(self) -> None:
        files = dict(DEFAULT_FILES)
        files["api/config/.env"] = PHP_ENV_DATA.replace(b"DB_PASS=unit-pass", b"DB_PASS=other-pass")
        manifest = self.build_bundle(files)

        with self.assertRaisesRegex(restore.RestoreError, "differs between compose and PHP"):
            self.do_restore(manifest=manifest)

    def test_encrypted_password_mismatch(self) -> None:
        self.runner.password_hash_ok = False

        with self.assertRaisesRegex(restore.RestoreError, "password differs"):
            self.do_restore()

    def test_missing_db_host_port(self) -> None:
        files = dict(DEFAULT_FILES)
        files["api/config/.env"] = PHP_ENV_DATA.replace(b"DB_HOST=db\n", b"").replace(b"DB_PORT=3306\n", b"")
        manifest = self.build_bundle(files)

        with self.assertRaisesRegex(restore.RestoreError, "required key is missing"):
            self.do_restore(manifest=manifest)

    def test_no_users_table(self) -> None:
        self.runner.no_users = True

        with self.assertRaisesRegex(restore.RestoreError, "no CRM schema"):
            self.do_restore(dry_run=True)

    def test_no_active_admin(self) -> None:
        self.runner.no_admin = True

        with self.assertRaisesRegex(restore.RestoreError, "no CRM schema"):
            self.do_restore(dry_run=True)

    def test_valid_target_passes_preflight(self) -> None:
        self.do_restore(dry_run=True)


class InterruptionTest(RestoreFixture):
    def test_keyboard_interrupt_during_first_config_file(self) -> None:
        before = self.snapshot()
        original = restore._apply_one
        counter = {"n": 0}

        def flaky(*args, **kwargs):
            original(*args, **kwargs)
            counter["n"] += 1

            if counter["n"] == 1:
                raise KeyboardInterrupt()

        with mock.patch.object(restore, "_apply_one", side_effect=flaky):
            with self.assertRaises(KeyboardInterrupt):
                self.do_restore()

        self.assertEqual(self.snapshot(), before)
        self.assertEqual(self.journal()["status"], "rolled_back")
        self.assertEqual(list(self.secrets_dir.glob(".restore-stage-*")), [])

    def test_keyboard_interrupt_during_third_config_file(self) -> None:
        before = self.snapshot()
        original = restore._apply_one
        counter = {"n": 0}

        def flaky(*args, **kwargs):
            original(*args, **kwargs)
            counter["n"] += 1

            if counter["n"] == 3:
                raise KeyboardInterrupt()

        with mock.patch.object(restore, "_apply_one", side_effect=flaky):
            with self.assertRaises(KeyboardInterrupt):
                self.do_restore()

        self.assertEqual(self.snapshot(), before)
        self.assertEqual(self.journal()["status"], "rolled_back")

    def test_sigterm_during_config_apply(self) -> None:
        before = self.snapshot()
        original = restore._apply_one
        counter = {"n": 0}

        def interrupt(*args, **kwargs):
            original(*args, **kwargs)
            counter["n"] += 1

            if counter["n"] == 2:
                signal.raise_signal(signal.SIGTERM)

        with mock.patch.object(restore, "_apply_one", side_effect=interrupt):
            with self.assertRaises(restore.RestoreInterrupted):
                self.do_restore()

        self.assertEqual(self.snapshot(), before)
        self.assertEqual(self.journal()["status"], "rolled_back")

    def test_keyboard_interrupt_during_sql_import(self) -> None:
        files = dict(DEFAULT_FILES)
        files[".env"] = ROOT_ENV_DATA.replace(b"CRM7_VERSION=1.8.18", b"CRM7_VERSION=9.9.9")
        manifest = self.build_bundle(files)

        def interrupt(*args, **kwargs):
            raise KeyboardInterrupt()

        with mock.patch.object(restore, "_sql_import", side_effect=interrupt):
            with self.assertRaises(KeyboardInterrupt):
                self.do_restore(manifest=manifest)

        state = self.journal()
        self.assertEqual(state["status"], "manual_recovery_required")
        self.assertEqual(state["sql_outcome"], "unknown")
        self.assertTrue(list(self.secrets_dir.glob(".restore-stage-*")))
        self.assertIn(b"CRM7_VERSION=9.9.9", self.snapshot()[".env"])

    def test_error_after_sql_import_keeps_staging(self) -> None:
        self.runner.health_ok = False

        with self.assertRaisesRegex(restore.RestoreError, "health check failed"):
            self.do_restore()

        state = self.journal()
        self.assertEqual(state["status"], "manual_recovery_required")
        self.assertEqual(state["phase"], "post_checks_failed")
        self.assertTrue(list(self.secrets_dir.glob(".restore-stage-*")))

    def test_journal_has_no_secrets(self) -> None:
        self.runner.sql_ok = False

        with self.assertRaises(restore.RestoreError):
            self.do_restore()

        content = (self.db_dir / restore.JOURNAL_NAME).read_text(encoding="utf-8")

        for marker in SECRET_MARKERS:
            self.assertNotIn(marker, content)

    def test_complete_journal_on_success(self) -> None:
        self.do_restore()

        state = self.journal()
        self.assertEqual(state["status"], "complete")
        self.assertEqual(state["sql_outcome"], "succeeded")
        self.assertEqual(state["sql_exit_code"], 0)
        self.assertTrue(state["operation_id"])
        self.assertTrue(state["updated_at"])
        self.assertEqual(list(self.secrets_dir.glob(".restore-stage-*")), [])


class ToctouTest(RestoreFixture):
    def test_sql_swapped_after_first_verify_is_rejected(self) -> None:
        original = backup_crm7.verify
        counter = {"n": 0}

        def tamper(path, secrets):
            result = original(path, secrets)
            counter["n"] += 1

            if counter["n"] == 1:
                (self.db_dir / f"crm7-{DEFAULT_ID}.sql").write_bytes(b"tampered")

            return result

        with mock.patch.object(backup_crm7, "verify", side_effect=tamper):
            with self.assertRaisesRegex(restore.RestoreError, "SQL dump snapshot"):
                self.do_restore()

    def test_sql_swapped_after_second_verify_does_not_affect_import(self) -> None:
        original = backup_crm7.verify
        counter = {"n": 0}

        def tamper(path, secrets):
            result = original(path, secrets)
            counter["n"] += 1

            if counter["n"] == 2:
                (self.db_dir / f"crm7-{DEFAULT_ID}.sql").write_bytes(b"tampered")

            return result

        with mock.patch.object(backup_crm7, "verify", side_effect=tamper):
            self.do_restore()

        self.assertTrue(any(".restore-stage-" in path and "/sql/" in path.replace("\\", "/")
                            for path in self.runner.stdin_paths))

    def test_staging_sql_is_rechecked_before_import(self) -> None:
        original = restore._assert_staged_sql

        def corrupt(stage_path, manifest, label):
            if label.startswith("staged SQL"):
                stage_path.write_bytes(b"bad")

            return original(stage_path, manifest, label)

        with mock.patch.object(restore, "_assert_staged_sql", side_effect=corrupt):
            with self.assertRaisesRegex(restore.RestoreError, "staged SQL changed"):
                self.do_restore()

        self.assertFalse(any("MYSQL_PWD" in " ".join(cmd) for cmd in self.runner.calls))

    def test_bundle_hash_is_checked_before_extraction(self) -> None:
        original = backup_crm7.verify

        def tamper(path, secrets):
            result = original(path, secrets)
            bundle = self.secrets_dir / f"crm7-{DEFAULT_ID}.secrets.tar"
            payload = bytearray(bundle.read_bytes())
            payload[100] ^= 0xFF
            bundle.write_bytes(payload)
            return result

        with mock.patch.object(backup_crm7, "verify", side_effect=tamper):
            with self.assertRaisesRegex(restore.RestoreError, "secret bundle snapshot"):
                self.do_restore()

    def test_import_uses_only_staging_path(self) -> None:
        self.do_restore()

        self.assertEqual(len(self.runner.stdin_paths), 1)
        self.assertIn(".restore-stage-", self.runner.stdin_paths[0])
        self.assertNotIn(str(self.db_dir), self.runner.stdin_paths[0])


class LockTest(RestoreFixture):
    def test_update_lock_blocks_restore(self) -> None:
        if os.name == "nt":
            self.skipTest("POSIX flock is not available on Windows")

        import fcntl
        from update_lock import lock_path

        path = lock_path(self.project)
        handle = path.open("a+b")
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)

        try:
            with self.assertRaisesRegex(restore.RestoreError, "restore lock is busy"):
                self.do_restore()
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
            handle.close()

        self.assertEqual(self.snapshot()["api/config/.env"], PHP_ENV_DATA)

    def test_restore_blocks_updater(self) -> None:
        if os.name == "nt":
            self.skipTest("POSIX flock is not available on Windows")

        from update_lock import LockBusy, acquire_update_lock, release_update_lock

        observed = {}

        def try_updater(*args, **kwargs):
            try:
                handle = acquire_update_lock(self.project)
            except LockBusy:
                observed["blocked"] = True
                raise
            else:
                release_update_lock(handle)

        with mock.patch.object(restore, "_sql_import", side_effect=try_updater):
            with self.assertRaises(LockBusy):
                self.do_restore()

        self.assertTrue(observed.get("blocked"))

    def test_two_restores_same_project_conflict(self) -> None:
        key = hashlib.sha256(str(self.project.resolve()).encode("utf-8")).hexdigest()[:12]
        lock = self.db_dir / f"crm7-restore-project-{key}.lock"
        lock.write_bytes(b"")

        try:
            with self.assertRaisesRegex(restore.RestoreError, "lock is busy"):
                self.do_restore()
        finally:
            lock.unlink(missing_ok=True)

    def test_lock_file_is_not_deleted_after_restore(self) -> None:
        if os.name == "nt":
            self.skipTest("POSIX flock is not available on Windows")

        from update_lock import lock_path

        self.do_restore()

        self.assertTrue(lock_path(self.project).exists())

    def test_lock_released_after_error(self) -> None:
        self.runner.php_live_ok = False

        with self.assertRaises(restore.RestoreError):
            self.do_restore()

        self.assertEqual(list(self.db_dir.glob("crm7-restore-*.lock")), [])


class SafetyTest(RestoreFixture):
    def test_config_files_are_restored_to_correct_targets(self) -> None:
        files = dict(DEFAULT_FILES)
        files[".env"] = ROOT_ENV_DATA.replace(b"CRM7_VERSION=1.8.18", b"CRM7_VERSION=9.9.9")
        files["compose.yaml"] = COMPOSE_DATA + b"# marker\n"
        manifest = self.build_bundle(files)
        (self.project / "compose.yaml").write_bytes(files["compose.yaml"])

        self.do_restore(manifest=manifest)

        self.assertIn(b"CRM7_VERSION=9.9.9", (self.project / ".env").read_bytes())
        self.assertIn(b"# marker", (self.project / "compose.yaml").read_bytes())

    def test_restore_accepts_bundle_created_by_backup_command(self) -> None:
        manifest = backup_crm7.create_backup(
            self.project, self.db_dir, self.secrets_dir,
            runner=lambda command, cwd: FAKE_SQL,
            backup_id="20260101T000001Z-abcdef12", require_docker=False,
        )

        self.do_restore(dry_run=True, manifest=manifest,
                        confirm="20260101T000001Z-abcdef12")

    def test_verify_is_used_before_restore(self) -> None:
        with mock.patch.object(backup_crm7, "verify", wraps=backup_crm7.verify) as spy:
            self.do_restore(dry_run=True)

        self.assertEqual(spy.call_count, 1)

    def test_manifest_is_snapshotted_and_not_reread(self) -> None:
        with mock.patch.object(backup_crm7, "verify", wraps=backup_crm7.verify) as spy:
            self.do_restore()

        # После снимка исходный manifest не перечитывается; целостность
        # компонентов проверяется по staging-копиям.
        self.assertEqual(spy.call_count, 1)

    def test_confirm_is_required(self) -> None:
        with self.assertRaisesRegex(restore.RestoreError, "--confirm"):
            self.do_restore(confirm=None)

    def test_confirm_must_match_backup_id(self) -> None:
        with self.assertRaisesRegex(restore.RestoreError, "does not match backup_id"):
            self.do_restore(confirm="wrong-id")

    def test_dry_run_does_not_change_project(self) -> None:
        before = self.snapshot()
        self.do_restore(dry_run=True)

        self.assertEqual(self.snapshot(), before)
        self.assertEqual(list(self.db_dir.glob("crm7-restore-*.lock")), [])

    def test_dry_run_does_not_import_sql(self) -> None:
        self.do_restore(dry_run=True)

        self.assertFalse(any("MYSQL_PWD" in " ".join(cmd) for cmd in self.runner.calls))

    def test_dry_run_output(self) -> None:
        stdout, _ = self.do_restore(dry_run=True)

        self.assertIn(DEFAULT_ID, stdout)
        self.assertIn("crm_test", stdout)
        self.assertIn("checks passed", stdout)
        for marker in SECRET_MARKERS:
            self.assertNotIn(marker, stdout)

    def test_required_component_missing(self) -> None:
        files = dict(DEFAULT_FILES)
        del files["api/config/.env"]
        manifest = self.build_bundle(files)

        with self.assertRaisesRegex(restore.RestoreError, "required component missing"):
            self.do_restore(dry_run=True, manifest=manifest)

    def test_corrupted_bundle(self) -> None:
        bundle = self.secrets_dir / f"crm7-{DEFAULT_ID}.secrets.tar"
        payload = bytearray(bundle.read_bytes())
        payload[100] ^= 0xFF
        bundle.write_bytes(payload)

        with self.assertRaises(backup_crm7.BackupError):
            self.do_restore(dry_run=True)

    def test_traversal_member_is_rejected(self) -> None:
        files = {name: data for name, data in DEFAULT_FILES.items() if name != "compose.yaml"}
        files["../evil.yaml"] = COMPOSE_DATA
        manifest = self.build_bundle(files)

        with self.assertRaisesRegex(backup_crm7.BackupError, "unsafe bundle member"):
            self.do_restore(dry_run=True, manifest=manifest)

    def test_special_members_are_rejected(self) -> None:
        for kind in (tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.FIFOTYPE):
            with self.subTest(kind=kind):
                manifest = self.build_bundle(
                    dict(DEFAULT_FILES), special={"api/config/database.json.bak": kind}
                )

                with self.assertRaisesRegex(backup_crm7.BackupError, "member type"):
                    self.do_restore(dry_run=True, manifest=manifest)

    def test_wrong_db_config_key_stops_before_changes(self) -> None:
        before = self.snapshot()
        self.runner.php_decrypt_ok = False

        with self.assertRaisesRegex(restore.RestoreError, "decrypt"):
            self.do_restore()

        self.assertEqual(self.snapshot(), before)

    def test_missing_required_keys(self) -> None:
        files = dict(DEFAULT_FILES)
        files["api/config/.env"] = PHP_ENV_DATA.replace(b"DB_CONFIG_KEY=unit-dbcfg-key\n", b"")
        manifest = self.build_bundle(files)

        with self.assertRaisesRegex(restore.RestoreError, "required key is missing"):
            self.do_restore(dry_run=True, manifest=manifest)

    def test_wrong_project_compose_file(self) -> None:
        (self.project / "compose.yaml").write_bytes(b"name: OTHER\nservices: {}\n")

        with self.assertRaisesRegex(restore.RestoreError, "compose file differs"):
            self.do_restore(dry_run=True)

    def test_wrong_container_database(self) -> None:
        self.runner.db_name = "crm_other"

        with self.assertRaisesRegex(restore.RestoreError, "container does not match"):
            self.do_restore(dry_run=True)

    def test_no_passwords_in_argv_or_output(self) -> None:
        stdout, stderr = self.do_restore()

        for command in self.runner.calls:
            joined = " ".join(command)
            for marker in SECRET_MARKERS:
                self.assertNotIn(marker, joined)

        for marker in SECRET_MARKERS:
            self.assertNotIn(marker, stdout)
            self.assertNotIn(marker, stderr)

    def test_database_unavailable(self) -> None:
        self.runner.db_ok = False

        with self.assertRaisesRegex(restore.RestoreError, "database is not ready"):
            self.do_restore(dry_run=True)

    def test_sql_import_error_does_not_claim_rollback(self) -> None:
        self.runner.sql_ok = False

        with self.assertRaisesRegex(restore.RestoreError, "NOT rolled back") as caught:
            self.do_restore()

        self.assertNotIn("SQL rollback", str(caught.exception))
        self.assertTrue(list(self.secrets_dir.glob(".restore-stage-*")))

    def test_health_check_error_after_sql(self) -> None:
        self.runner.health_ok = False

        with self.assertRaisesRegex(restore.RestoreError, "health check failed"):
            self.do_restore()

        self.assertTrue(list(self.secrets_dir.glob(".restore-stage-*")))

    def test_config_rollback_before_sql_import(self) -> None:
        before = self.snapshot()
        self.runner.post_apply_db_ok = False

        with self.assertRaisesRegex(restore.RestoreError, "cannot connect"):
            self.do_restore()

        self.assertEqual(self.snapshot(), before)
        self.assertEqual(list(self.secrets_dir.glob(".restore-stage-*")), [])

    def test_staging_permissions_and_cleanup(self) -> None:
        seen = {}
        original = restore._extract_approved

        def capture(bundle, stage, expected):
            result = original(bundle, stage, expected)
            seen["stage"] = stage
            seen["mode"] = stage.stat().st_mode & 0o777
            return result

        with mock.patch.object(restore, "_extract_approved", side_effect=capture):
            self.do_restore()

        if os.name != "nt":
            self.assertEqual(seen["mode"], 0o700)
        self.assertFalse(seen["stage"].exists())

    def test_bundle_without_database_json_uses_env_fallback(self) -> None:
        (self.project / "api" / "config" / "database.json").unlink()
        files = {
            ".env": ROOT_ENV_DATA,
            "api/config/.env": PHP_ENV_DATA,
            "compose.yaml": COMPOSE_DATA,
        }
        manifest = self.build_bundle(files)

        self.do_restore(manifest=manifest)

        self.assertFalse((self.project / "api" / "config" / "database.json").exists())

    def test_assert_staged_sql_detects_corruption(self) -> None:
        manifest = json.loads(self.manifest_path.read_text(encoding="utf-8"))
        target = self.base / "bad.sql"
        target.write_bytes(b"bad")

        with self.assertRaisesRegex(restore.RestoreError, "does not match manifest"):
            restore._assert_staged_sql(target, manifest, "does not match manifest")

    def test_cli_verify_and_missing_confirm(self) -> None:
        argv = [
            "--project-dir", str(self.project),
            "--db-backup-dir", str(self.db_dir),
            "--secrets-dir", str(self.secrets_dir),
            "--manifest", str(self.manifest_path),
        ]

        with redirect_stdout(io.StringIO()):
            self.assertEqual(restore.main(argv + ["verify"]), 0)
            self.assertEqual(restore.main(argv + ["restore"]), 1)


class CanonicalLockTest(RestoreFixture):
    def test_lock_path_is_project_canonical(self) -> None:
        from update_lock import lock_path

        expected = lock_path(self.project)
        self.assertEqual(expected, self.project / "update-crm-7" / "update.lock")

        previous = pathlib.Path.cwd()
        try:
            os.chdir(self.base)
            relative = pathlib.Path(os.path.relpath(self.project, self.base))
            self.assertEqual(lock_path(relative), expected)
        finally:
            os.chdir(previous)

    def test_restore_passes_project_not_backup_dir_to_lock(self) -> None:
        with mock.patch.object(restore, "update_lock", wraps=restore.update_lock) as spy:
            self.do_restore()

        self.assertEqual(spy.call_args.args[0], self.project)

    def test_updater_ignores_backup_dir_for_lock(self) -> None:
        import crm7_updater

        args = argparse.Namespace(backup_dir=str(self.base / "somewhere-else"))

        with mock.patch.object(crm7_updater, "require_lock"), \
                mock.patch.object(crm7_updater, "acquire_update_lock") as spy:
            crm7_updater.acquire_lock(self.project, args)

        self.assertEqual(spy.call_args.args[0], self.project)

    def test_two_real_processes_conflict_on_canonical_lock(self) -> None:
        if os.name == "nt":
            self.skipTest("POSIX flock is not available on Windows")

        import subprocess
        import time
        from update_lock import LockBusy, acquire_update_lock, lock_path

        code = (
            "import fcntl,sys,time;"
            "f=open(sys.argv[1],'a+b');"
            "fcntl.flock(f.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB);"
            "time.sleep(1.5)"
        )
        process = subprocess.Popen([sys.executable, "-c", code, str(lock_path(self.project))])

        try:
            time.sleep(0.3)

            with self.assertRaises(LockBusy):
                acquire_update_lock(self.project)
        finally:
            process.terminate()
            process.wait(timeout=10)

    def test_updater_without_backup_dir_blocks_restore_with_external_backup_dir(self) -> None:
        if os.name == "nt":
            self.skipTest("POSIX flock is not available on Windows")

        import crm7_updater
        import fcntl
        from update_lock import lock_path

        args = argparse.Namespace(backup_dir=None)
        handle = crm7_updater.acquire_lock(self.project, args)

        try:
            with self.assertRaisesRegex(restore.RestoreError, "lock is busy"):
                self.do_restore()
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
            handle.close()

    def test_foreign_lock_file_is_not_deleted(self) -> None:
        from update_lock import lock_path

        path = lock_path(self.project)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(b"foreign")

        self.do_restore()

        self.assertEqual(path.read_bytes(), b"foreign")


class InterruptionRegressionTest(RestoreFixture):
    def test_sigterm_before_staging_leaves_no_materials(self) -> None:
        def interrupt(*args, **kwargs):
            signal.raise_signal(signal.SIGTERM)

        with mock.patch.object(restore.tempfile, "mkdtemp", side_effect=interrupt):
            with self.assertRaises(restore.RestoreInterrupted):
                self.do_restore()

        self.assertEqual(list(self.secrets_dir.glob(".restore-stage-*")), [])
        state = self.journal()
        self.assertEqual(state["status"], "failed_before_changes")
        self.assertEqual(state["sql_outcome"], "not_started")
        self.assertEqual(self.snapshot()[".env"], ROOT_ENV_DATA)

    def test_failure_while_creating_rollback_copy_keeps_project_unchanged(self) -> None:
        before = self.snapshot()

        def fail(*args, **kwargs):
            raise OSError("rollback copy failed")

        with mock.patch.object(restore, "_prepare_rollback", side_effect=fail):
            with self.assertRaises(OSError):
                self.do_restore()

        self.assertEqual(self.snapshot(), before)
        self.assertEqual(list(self.secrets_dir.glob(".restore-stage-*")), [])

    def test_rollback_failure_on_first_file_keeps_staging(self) -> None:
        before = self.snapshot()
        original_journal = restore._write_journal

        def fail_after_apply(db_dir, state):
            if state["phase"].startswith("config_applied:"):
                raise KeyboardInterrupt()

            return original_journal(db_dir, state)

        def fail_ownership(*args, **kwargs):
            raise OSError("chown failed")

        with mock.patch.object(restore, "_write_journal", side_effect=fail_after_apply):
            with mock.patch.object(restore, "_restore_ownership", side_effect=fail_ownership):
                with self.assertRaises(KeyboardInterrupt):
                    self.do_restore()

        state = self.journal()
        self.assertEqual(state["status"], "manual_recovery_required")
        self.assertTrue(state["rollback_failures"])
        self.assertTrue(list(self.secrets_dir.glob(".restore-stage-*")))

    def test_repeat_keyboard_interrupt_during_rollback_is_recorded(self) -> None:
        original_journal = restore._write_journal

        def fail_after_apply(db_dir, state):
            if state["phase"].startswith("config_applied:"):
                raise KeyboardInterrupt()

            return original_journal(db_dir, state)

        def repeat(*args, **kwargs):
            raise KeyboardInterrupt()

        with mock.patch.object(restore, "_write_journal", side_effect=fail_after_apply):
            with mock.patch.object(restore, "_restore_ownership", side_effect=repeat):
                with self.assertRaises(KeyboardInterrupt):
                    self.do_restore()

        state = self.journal()
        self.assertTrue(state["rollback_failures"])
        self.assertTrue(list(self.secrets_dir.glob(".restore-stage-*")))

    def test_repeat_sigterm_during_rollback_is_suppressed(self) -> None:
        original_journal = restore._write_journal

        def fail_after_apply(db_dir, state):
            if state["phase"].startswith("config_applied:"):
                raise KeyboardInterrupt()

            return original_journal(db_dir, state)

        def repeat(*args, **kwargs):
            signal.raise_signal(signal.SIGTERM)
            raise OSError("chown failed")

        with mock.patch.object(restore, "_write_journal", side_effect=fail_after_apply):
            with mock.patch.object(restore, "_restore_ownership", side_effect=repeat):
                with self.assertRaises(KeyboardInterrupt):
                    self.do_restore()

        state = self.journal()
        self.assertEqual(state["status"], "manual_recovery_required")
        self.assertTrue(state["rollback_failures"])
        self.assertTrue(list(self.secrets_dir.glob(".restore-stage-*")))

    def test_staged_sql_failure_after_apply_keeps_rollback(self) -> None:
        original = restore._assert_staged_sql

        def fail(path, manifest, label):
            if label.startswith("staged SQL"):
                raise restore.RestoreError("staged SQL changed before import")

            return original(path, manifest, label)

        with mock.patch.object(restore, "_assert_staged_sql", side_effect=fail):
            with self.assertRaisesRegex(restore.RestoreError, "staged SQL changed"):
                self.do_restore()

        state = self.journal()
        self.assertEqual(state["status"], "rolled_back")
        self.assertEqual(state["sql_outcome"], "not_started")
        self.assertEqual(state["phase"], "config_rolled_back")
        self.assertEqual(list(self.secrets_dir.glob(".restore-stage-*")), [])

    def test_interrupt_between_post_apply_and_sql_keeps_rollback(self) -> None:
        original = restore._write_journal

        def interrupt(db_dir, state):
            if state["phase"] == "sql_import_starting":
                raise KeyboardInterrupt()

            return original(db_dir, state)

        with mock.patch.object(restore, "_write_journal", side_effect=interrupt):
            with self.assertRaises(KeyboardInterrupt):
                self.do_restore()

        state = self.journal()
        self.assertEqual(state["sql_outcome"], "unknown")
        self.assertEqual(state["status"], "manual_recovery_required")
        self.assertTrue(list(self.secrets_dir.glob(".restore-stage-*")))

    def test_failure_writing_sql_import_finished_keeps_rollback(self) -> None:
        original = restore._write_journal

        counter = {"n": 0}

        def fail(db_dir, state):
            if state["phase"] == "sql_import_finished" and counter["n"] == 0:
                counter["n"] += 1
                raise OSError("journal write failed")

            return original(db_dir, state)

        with mock.patch.object(restore, "_write_journal", side_effect=fail):
            with self.assertRaises(OSError):
                self.do_restore()

        state = self.journal()
        self.assertEqual(state["sql_outcome"], "unknown")
        self.assertIsNone(state["sql_exit_code"])
        self.assertEqual(state["phase"], "sql_import_unknown")
        self.assertEqual(state["status"], "manual_recovery_required")
        self.assertTrue(list(self.secrets_dir.glob(".restore-stage-*")))

    def test_failure_writing_complete_journal_keeps_rollback(self) -> None:
        def fail(*args, **kwargs):
            raise OSError("complete journal failed")

        original_journal = restore._write_journal

        def fail(db_dir, state):
            if state["phase"] == "complete":
                raise OSError("final journal failed")

            return original_journal(db_dir, state)

        with mock.patch.object(restore, "_write_journal", side_effect=fail):
            with self.assertRaises(OSError):
                self.do_restore()

        state = self.journal()
        self.assertEqual(state["status"], "manual_recovery_required")
        self.assertEqual(state["phase"], "finalization_failed")
        self.assertTrue(list(self.secrets_dir.glob(".restore-stage-*")))
        self.assertFalse(list(self.db_dir.glob("restore-complete.json")))

    def test_journal_identifies_partial_config_after_simulated_crash(self) -> None:
        original_journal = restore._write_journal

        def crash(db_dir, state):
            if state["phase"].startswith("config_applied:"):
                raise restore.RestoreError("simulated crash after first file")

            return original_journal(db_dir, state)

        with mock.patch.object(restore, "_write_journal", side_effect=crash):
            with self.assertRaises(restore.RestoreError):
                self.do_restore()

        state = self.journal()
        self.assertEqual(state["status"], "rolled_back")
        self.assertTrue(state["files_intended"])
        self.assertIn(state["files_changed"][0], state["files_intended"])


class TargetRegressionTest(RestoreFixture):
    def test_empty_db_container_list_is_rejected(self) -> None:
        self.runner.containers = []

        with self.assertRaisesRegex(restore.RestoreError, "exactly one"):
            self.do_restore(dry_run=True)

    def test_two_db_containers_are_rejected(self) -> None:
        self.runner.containers = ["abc123def456", "fedcba987654"]

        with self.assertRaisesRegex(restore.RestoreError, "exactly one"):
            self.do_restore(dry_run=True)

    def test_ambiguous_mysql_mount_is_rejected(self) -> None:
        self.runner.extra_mysql_mount = True

        with self.assertRaisesRegex(restore.RestoreError, "ambiguous database volume"):
            self.do_restore(dry_run=True)

    def test_target_change_after_lock_aborts_before_config(self) -> None:
        before = self.snapshot()
        first = self.target_id()
        changed = {
            "container": "fedcba987654",
            "compose_project": "crm7-test",
            "volume": self.runner.volume,
            "target_id": "changed-target-id",
        }
        calls = {"n": 0}
        original = restore._target_identity

        def changing(project, runner):
            calls["n"] += 1

            if calls["n"] >= 2:
                return dict(changed)

            return original(project, runner)

        with mock.patch.object(restore, "_target_identity", side_effect=changing):
            with self.assertRaisesRegex(restore.RestoreError, "changed before configuration"):
                self.do_restore(confirm_target=first)

        self.assertEqual(self.snapshot(), before)
        self.assertEqual(self.journal()["status"], "failed_before_changes")

    def test_target_change_before_sql_import_keeps_rollback(self) -> None:
        first = self.target_id()
        original = restore._target_identity
        calls = {"n": 0}

        def changing(project, runner):
            calls["n"] += 1

            if calls["n"] == 3:
                return {
                    "container": "fedcba987654",
                    "compose_project": "crm7-test",
                    "volume": self.runner.volume,
                    "target_id": "changed-target-id",
                }

            return original(project, runner)

        with mock.patch.object(restore, "_target_identity", side_effect=changing):
            with self.assertRaisesRegex(restore.RestoreError, "changed before SQL import"):
                self.do_restore(confirm_target=first)

        state = self.journal()
        self.assertEqual(state["status"], "rolled_back")
        self.assertEqual(state["sql_outcome"], "not_started")
        self.assertEqual(list(self.secrets_dir.glob(".restore-stage-*")), [])


class SnapshotRegressionTest(RestoreFixture):
    def test_bundle_is_taken_from_staging_only(self) -> None:
        observed = {}
        original = restore.tarfile.open

        def record(name, *args, **kwargs):
            observed["path"] = str(name)
            return original(name, *args, **kwargs)

        with mock.patch.object(restore.tarfile, "open", side_effect=record):
            self.do_restore()

        self.assertIn(".restore-stage-", observed["path"])

    def test_source_bundle_change_after_snapshot_does_not_affect_restore(self) -> None:
        original = restore._extract_approved

        def tamper(bundle, stage, expected):
            source = self.secrets_dir / f"crm7-{DEFAULT_ID}.secrets.tar"
            payload = bytearray(source.read_bytes())
            payload[120] ^= 0xFF
            source.write_bytes(payload)
            return original(bundle, stage, expected)

        with mock.patch.object(restore, "_extract_approved", side_effect=tamper):
            self.do_restore()

        self.assertIn(b"CRM7_VERSION=1.8.18", (self.project / ".env").read_bytes())

    def test_manifest_swap_between_verify_and_snapshot_is_rejected(self) -> None:
        original = backup_crm7.verify

        def swap(path, secrets):
            result = original(path, secrets)
            data = json.loads(pathlib.Path(path).read_text(encoding="utf-8"))
            data["backup_id"] = "swapped-backup-id"
            pathlib.Path(path).write_text(json.dumps(data), encoding="utf-8")
            return result

        with mock.patch.object(backup_crm7, "verify", side_effect=swap):
            with self.assertRaisesRegex(restore.RestoreError, "confirm does not match"):
                self.do_restore()

    def test_symlink_bundle_is_rejected(self) -> None:
        bundle = self.secrets_dir / f"crm7-{DEFAULT_ID}.secrets.tar"
        real = self.secrets_dir / "real-bundle.tar"
        real.write_bytes(bundle.read_bytes())
        bundle.unlink()

        try:
            bundle.symlink_to(real)
        except (OSError, NotImplementedError):
            self.skipTest("symlinks are not available in this environment")

        with self.assertRaisesRegex(restore.RestoreError, "snapshot source is a symlink"):
            self.do_restore(dry_run=True)

    def test_hardlink_bundle_is_accepted_with_matching_hash(self) -> None:
        if os.name == "nt":
            self.skipTest("hardlinks are not reliably available on Windows")

        bundle = self.secrets_dir / f"crm7-{DEFAULT_ID}.secrets.tar"
        twin = self.secrets_dir / "bundle-twin.tar"
        os.link(bundle, twin)

        self.do_restore(dry_run=True)

    def test_sql_change_during_copy_is_rejected(self) -> None:
        original = restore._stat_signature
        counter = {"n": 0}

        def changing(path):
            signature = original(path)

            if path.name.endswith(".sql"):
                counter["n"] += 1

                if counter["n"] == 1:
                    return (signature[0] + 1, signature[1], signature[2], signature[3])

            return signature

        with mock.patch.object(restore, "_stat_signature", side_effect=changing):
            with self.assertRaisesRegex(restore.RestoreError, "changed during snapshot"):
                self.do_restore(dry_run=True)

    def test_bundle_change_during_copy_is_rejected(self) -> None:
        original = restore._stat_signature
        counter = {"n": 0}

        def changing(path):
            signature = original(path)

            if path.name.endswith(".secrets.tar"):
                counter["n"] += 1

                if counter["n"] == 1:
                    return (signature[0] + 1, signature[1], signature[2], signature[3])

            return signature

        with mock.patch.object(restore, "_stat_signature", side_effect=changing):
            with self.assertRaisesRegex(restore.RestoreError, "changed during snapshot"):
                self.do_restore(dry_run=True)


class RollbackMetadataTest(RestoreFixture):
    def test_root_env_rollback_copy_name_matches_docs(self) -> None:
        original_apply = restore._apply_one

        def fail(*args, **kwargs):
            original_apply(*args, **kwargs)
            raise KeyboardInterrupt()

        with mock.patch.object(restore, "_apply_one", side_effect=fail):
            with self.assertRaises(KeyboardInterrupt):
                self.do_restore()

        names = [pathlib.Path(item["path"]).name for item in self.journal()["rollback_copies"]]
        self.assertIn("__env", names)
        self.assertIn("api__config__.env", names)
        self.assertNotIn(".env", names)

    def test_rollback_metadata_has_uid_gid_mode(self) -> None:
        before = self.snapshot()
        self.runner.sql_ok = False

        with self.assertRaises(restore.RestoreError):
            self.do_restore()

        state = self.journal()
        self.assertTrue(state["files_changed"])
        self.assertEqual(len(state["rollback_copies"]), len(state["files_intended"]))

        for path in self.project.rglob("*"):
            if path.is_file() and path.relative_to(self.project).as_posix() in state["files_changed"]:
                info = path.stat()
                self.assertIsInstance(info.st_uid, int)
                self.assertIsInstance(info.st_gid, int)
                self.assertIsInstance(info.st_mode & 0o777, int)

    def test_manual_recovery_from_staging_is_possible(self) -> None:
        before = self.snapshot()
        self.runner.sql_ok = False

        with self.assertRaises(restore.RestoreError):
            self.do_restore()

        state = self.journal()

        for item in state["rollback_copies"]:
            relative = item["file"]
            target = self.project / relative

            if target.exists():
                shutil.copyfile(item["path"], target)

        self.assertEqual(self.snapshot(), before)

class SqlDiagnosticsTest(RestoreFixture):
    def test_sql_stderr_with_secrets_is_not_logged(self) -> None:
        self.runner.sql_ok = False
        self.runner.sql_stderr = (
            b"ERROR: unit-pass unit-dbcfg-key SELECT * FROM users"
        )

        stderr = ""

        with self.assertRaises(restore.RestoreError) as caught:
            _, stderr = self.do_restore()

        message = str(caught.exception)

        for marker in SECRET_MARKERS:
            self.assertNotIn(marker, stderr)
            self.assertNotIn(marker, message)

        self.assertNotIn("SELECT *", stderr)
        self.assertNotIn("SELECT *", message)
        self.assertIn("exit code 1", message)


class DefectRegressionTest(RestoreFixture):
    def test_hash_failure_after_replace_is_rolled_back(self) -> None:
        before = self.snapshot()
        real_hash = restore._sha256
        flags = {"armed": False}

        def flaky(path):
            value = real_hash(path)

            if ".restore-stage-" in str(path).replace("\\", "/"):
                flags["armed"] = True
                return value
            if flags["armed"] and path.name == ".env":
                flags["armed"] = False
                return "0" * 64

            return value

        with mock.patch.object(restore, "_sha256", side_effect=flaky):
            with self.assertRaises(restore.RestoreError):
                self.do_restore()

        state = self.journal()
        self.assertIn(".env", state["files_attempted"])
        self.assertIn(".env", state["files_restored"])
        self.assertEqual(state["status"], "rolled_back")
        self.assertEqual(self.snapshot(), before)

    def test_failure_before_replace_records_attempt_only(self) -> None:
        before = self.snapshot()

        def fail(*args, **kwargs):
            raise restore.RestoreError("failed before replace")

        with mock.patch.object(restore, "_apply_one", side_effect=fail):
            with self.assertRaises(restore.RestoreError):
                self.do_restore()

        state = self.journal()
        self.assertTrue(state["files_attempted"])
        self.assertEqual(state["files_changed"], [])
        self.assertEqual(state["status"], "rolled_back")
        self.assertEqual(self.snapshot(), before)

    def test_new_file_created_then_removed_on_rollback(self) -> None:
        (self.project / "api" / "config" / "database.json").unlink()

        original_journal = restore._write_journal
        target = "api/config/database.json"

        def fail_after_apply(db_dir, state):
            if state["phase"] == "config_applied:" + target:
                raise KeyboardInterrupt()

            return original_journal(db_dir, state)

        with mock.patch.object(restore, "_write_journal", side_effect=fail_after_apply):
            with self.assertRaises(KeyboardInterrupt):
                self.do_restore()

        self.assertFalse((self.project / "api" / "config" / "database.json").exists())
        state = self.journal()
        self.assertIn(target, state["files_attempted"])
        self.assertIn(target, state["files_restored"])

    def test_rollback_continues_after_one_file_failure(self) -> None:
        original_journal = restore._write_journal

        def fail_after_apply(db_dir, state):
            if state["phase"] == "config_applied:api/config/database.json":
                raise KeyboardInterrupt()

            return original_journal(db_dir, state)

        def fail_first(record, target):
            if target == self.project / ".env":
                raise OSError("chown failed")

        with mock.patch.object(restore, "_write_journal", side_effect=fail_after_apply):
            with mock.patch.object(restore, "_restore_ownership", side_effect=fail_first):
                with self.assertRaises(KeyboardInterrupt):
                    self.do_restore()

        state = self.journal()
        self.assertEqual(len(state["rollback_failures"]), 1)
        self.assertGreaterEqual(len(state["files_restored"]), 2)
        self.assertEqual(state["status"], "manual_recovery_required")
        self.assertTrue(list(self.secrets_dir.glob(".restore-stage-*")))

    def test_rollback_is_idempotent(self) -> None:
        staged = {
            "compose_name": "compose.yaml",
            "database_json": None,
            "has_db_json_bak": False,
        }
        records = restore._prepare_rollback(self.project, staged, self.base / "rollback")
        names = [record["relative"] for record in records]
        state = {
            "files_attempted": list(names),
            "files_changed": list(names),
            "files_restored": [],
            "rollback_copies": [restore._rollback_metadata(record) for record in records],
            "rollback_failures": [],
        }

        first = restore._rollback_files(records, state)
        content_after_first = self.snapshot()
        second = restore._rollback_files(records, state)

        self.assertEqual(first, [])
        self.assertEqual(second, [])
        self.assertEqual(self.snapshot(), content_after_first)

    def test_sql_nonzero_exit_marks_failed_phase(self) -> None:
        self.runner.sql_ok = False

        with self.assertRaises(restore.RestoreError):
            self.do_restore()

        state = self.journal()
        self.assertEqual(state["phase"], "sql_import_failed")
        self.assertEqual(state["sql_outcome"], "failed")
        self.assertEqual(state["sql_exit_code"], 1)
        self.assertIsNotNone(state["sql_started_at"])
        self.assertIsNotNone(state["sql_finished_at"])
        self.assertEqual(state["status"], "manual_recovery_required")

    def test_sql_exception_marks_unknown_phase(self) -> None:
        def fail(*args, **kwargs):
            raise ValueError("runner broken")

        with mock.patch.object(restore, "_sql_import", side_effect=fail):
            with self.assertRaises(ValueError):
                self.do_restore()

        state = self.journal()
        self.assertEqual(state["phase"], "sql_import_unknown")
        self.assertEqual(state["sql_outcome"], "unknown")
        self.assertIsNone(state["sql_exit_code"])

    def test_signal_during_sql_marks_unknown_phase(self) -> None:
        def interrupt(*args, **kwargs):
            signal.raise_signal(signal.SIGTERM)

        with mock.patch.object(restore, "_sql_import", side_effect=interrupt):
            with self.assertRaises(restore.RestoreInterrupted):
                self.do_restore()

        state = self.journal()
        self.assertEqual(state["phase"], "sql_import_unknown")
        self.assertEqual(state["sql_outcome"], "unknown")

    def test_signal_after_successful_sql_before_result_marks_unknown(self) -> None:
        original_journal = restore._write_journal

        def interrupt(db_dir, state):
            if state["phase"] == "sql_import_finished":
                signal.raise_signal(signal.SIGINT)

            return original_journal(db_dir, state)

        with mock.patch.object(restore, "_write_journal", side_effect=interrupt):
            with self.assertRaises(restore.RestoreInterrupted):
                self.do_restore()

        state = self.journal()
        self.assertEqual(state["phase"], "sql_import_unknown")
        self.assertEqual(state["sql_outcome"], "unknown")
        self.assertIsNone(state["sql_exit_code"])

    def test_journal_contains_full_rollback_metadata(self) -> None:
        self.runner.sql_ok = False

        with self.assertRaises(restore.RestoreError):
            self.do_restore()

        state = self.journal()
        self.assertTrue(state["rollback_copies"])

        for item in state["rollback_copies"]:
            for field in ("file", "name", "path", "target", "existed", "mode",
                          "uid", "gid", "size", "sha256", "state"):
                self.assertIn(field, item)

            self.assertIn(item["state"], ("saved", "restored", "restore_failed"))

    def test_finalization_uses_single_state_journal(self) -> None:
        self.do_restore()

        state = self.journal()
        self.assertEqual(state["status"], "complete")
        self.assertEqual(state["sql_outcome"], "succeeded")
        self.assertFalse(list(self.db_dir.glob("restore-complete.json")))

    def test_previous_complete_is_archived_for_new_operation(self) -> None:
        self.do_restore()
        first = self.journal()
        self.assertEqual(first["status"], "complete")

        self.runner.sql_ok = False

        with self.assertRaises(restore.RestoreError):
            self.do_restore()

        state = self.journal()
        self.assertNotEqual(state["status"], "complete")
        self.assertNotEqual(state["backup_id"], None)
        history = list((self.db_dir / restore.HISTORY_DIR).glob("restore-*.json"))
        self.assertEqual(len(history), 1)

        archived = json.loads(history[0].read_text(encoding="utf-8"))
        self.assertEqual(archived["status"], "complete")
        self.assertEqual(archived["operation_id"], first["operation_id"])
        self.assertNotEqual(archived["operation_id"], state["operation_id"])

    def test_status_command_is_read_only(self) -> None:
        self.do_restore()
        before = self.journal()
        output = io.StringIO()

        with redirect_stdout(output):
            code = restore.main([
                "--db-backup-dir", str(self.db_dir), "status",
            ])

        self.assertEqual(code, 0)
        self.assertIn(before["operation_id"], output.getvalue())
        self.assertEqual(self.journal(), before)

    def test_target_identity_checks_order(self) -> None:
        self.do_restore()

        calls = [cmd for cmd in self.runner.calls
                 if cmd[:5] == ["docker", "compose", "ps", "-q", "db"]]
        # 1 вызов из тестового помощника + 3 в restore (preflight, после lock, перед SQL).
        self.assertEqual(len(calls), 4)

    def test_dry_run_checks_target_identity_once(self) -> None:
        self.do_restore(dry_run=True)

        calls = [cmd for cmd in self.runner.calls
                 if cmd[:5] == ["docker", "compose", "ps", "-q", "db"]]
        # 1 вызов из тестового помощника + 1 в preflight dry-run.
        self.assertEqual(len(calls), 2)

    def test_release_and_deploy_use_physically_identical_lock(self) -> None:
        import deploy_crm7
        import release_crm7
        from update_lock import lock_path, posix_lock_path

        project = "/srv/projects/crm7-k"
        expected = str(posix_lock_path(project))
        release_script = release_crm7.remote_block("k", project, "1.0.0", True)
        deploy_script = deploy_crm7.remote_script(project)

        self.assertIn(expected, release_script)
        self.assertIn(expected, deploy_script)
        self.assertIn("flock -n 9", release_script)
        self.assertIn("flock -n 9", deploy_script)

        if os.name != "nt":
            self.assertEqual(expected, str(lock_path(pathlib.Path(project))))

    def test_relative_remote_project_is_rejected(self) -> None:
        from update_lock import posix_lock_path

        with self.assertRaises(ValueError):
            posix_lock_path("relative/project")

    def test_remote_lock_path_with_spaces_is_quoted(self) -> None:
        import deploy_crm7
        from update_lock import posix_lock_path

        project = "/srv/projects/crm7 test"
        expected = str(posix_lock_path(project))
        script = deploy_crm7.remote_script(project)

        self.assertIn(expected, script)
        self.assertIn("'/srv/projects/crm7 test/update-crm-7/update.lock'", script)


class D1ConcurrencyTest(RestoreFixture):
    def _lock_probe_code(self) -> str:
        return (
            "import sys;"
            "sys.path.insert(0, r'" + str(ROOT / "deploy") + "');"
            "from update_lock import acquire_update_lock, release_update_lock;"
            "from pathlib import Path;"
            "h=acquire_update_lock(Path(sys.argv[1]));"
            "release_update_lock(h);"
            "print('ok')"
        )

    def test_different_backup_id_busy_does_not_touch_journal(self) -> None:
        if os.name == "nt":
            self.skipTest("POSIX flock is not available on Windows")

        from update_lock import lock_path

        code = (
            "import sys,time;"
            "sys.path.insert(0, r'" + str(ROOT / "deploy") + "');"
            "from update_lock import update_lock;"
            "from pathlib import Path;"
            "ctx=update_lock(Path(sys.argv[1]));"
            "ctx.__enter__();"
            "time.sleep(1.5)"
        )
        process = subprocess.Popen(
            [sys.executable, "-c", code, str(lock_path(self.project))],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )

        try:
            time.sleep(0.35)

            with self.assertRaisesRegex(restore.RestoreError, "lock is busy"):
                self.do_restore()

            self.assertFalse((self.db_dir / restore.STATE_NAME).exists())
            self.assertFalse((self.db_dir / restore.HISTORY_DIR).exists())
        finally:
            process.terminate()
            process.wait(timeout=10)

        self.do_restore()
        state = self.journal()
        self.assertEqual(state["status"], "complete")
        self.assertTrue(state["operation_id"])

    def test_same_backup_id_only_one_process_acquires(self) -> None:
        ready = threading.Event()
        results = []

        def first():
            with restore._restore_locks(self.db_dir, self.project, "same-id"):
                ready.set()
                time.sleep(0.4)
                results.append("first")

        def second():
            if not ready.wait(2):
                return

            try:
                with restore._restore_locks(self.db_dir, self.project, "same-id"):
                    results.append("second")
            except restore.RestoreError:
                results.append("busy")

        threads = [threading.Thread(target=first), threading.Thread(target=second)]

        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join(timeout=5)

        self.assertIn("first", results)
        self.assertIn("busy", results)
        self.assertNotIn("second", results)

    def test_project_lock_released_when_id_lock_fails(self) -> None:
        if os.name == "nt":
            self.skipTest("POSIX flock is not available on Windows")

        from update_lock import lock_path

        key = hashlib.sha256(str(self.project.resolve()).encode("utf-8")).hexdigest()[:12]
        (self.db_dir / f"crm7-restore-project-{key}.lock").write_bytes(b"")

        with self.assertRaisesRegex(restore.RestoreError, "lock is busy"):
            with restore.update_lock(self.project), restore._restore_locks(
                    self.db_dir, self.project, "any-id"):
                self.fail("must not acquire both locks")

        result = subprocess.run(
            [sys.executable, "-c", self._lock_probe_code(), str(lock_path(self.project))],
            capture_output=True, text=True, timeout=15,
        )
        self.assertEqual(result.stdout.strip(), "ok")

    def test_archive_failure_preserves_previous_journal(self) -> None:
        self.do_restore()
        previous = (self.db_dir / restore.STATE_NAME).read_bytes()
        before = self.snapshot()
        original_replace = restore.os.replace

        def fail_archive(source, target):
            if (pathlib.Path(source).name.startswith(".restore-archive-")
                    and pathlib.Path(target).parent.name == restore.HISTORY_DIR):
                raise OSError("archive failed")

            return original_replace(source, target)

        with mock.patch.object(restore.os, "replace", side_effect=fail_archive):
            with self.assertRaisesRegex(restore.RestoreError, "cannot publish restore state"):
                self.do_restore()

        self.assertEqual((self.db_dir / restore.STATE_NAME).read_bytes(), previous)
        self.assertEqual(self.snapshot(), before)
        self.assertEqual(
            list((self.db_dir / restore.HISTORY_DIR).glob("*.json")) if
            (self.db_dir / restore.HISTORY_DIR).exists() else [],
            [],
        )


class D2D3D4Test(RestoreFixture):
    def test_history_dir_created_0700_under_umask_022(self) -> None:
        if os.name == "nt":
            self.skipTest("POSIX modes are not available on Windows")

        previous_umask = os.umask(0o022)

        try:
            restore._ensure_history_dir(self.db_dir / restore.HISTORY_DIR)
        finally:
            os.umask(previous_umask)

        mode = stat.S_IMODE((self.db_dir / restore.HISTORY_DIR).stat().st_mode)
        self.assertEqual(mode, 0o700)

    def test_existing_history_dir_rights_are_tightened(self) -> None:
        if os.name == "nt":
            self.skipTest("POSIX modes are not available on Windows")

        path = self.db_dir / restore.HISTORY_DIR
        path.mkdir()
        os.chmod(path, 0o755)

        restore._ensure_history_dir(path)

        self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o700)

    def test_history_dir_symlink_is_rejected(self) -> None:
        target = self.db_dir / "real-history"
        target.mkdir()
        link = self.db_dir / restore.HISTORY_DIR

        try:
            link.symlink_to(target, target_is_directory=True)
        except (OSError, NotImplementedError):
            self.skipTest("symlinks are not available in this environment")

        with self.assertRaisesRegex(restore.RestoreError, "symlink"):
            restore._ensure_history_dir(link)

    def test_history_mkdir_and_chmod_errors_are_fatal(self) -> None:
        with mock.patch.object(restore.os, "mkdir", side_effect=OSError("mkdir failed")):
            with self.assertRaisesRegex(restore.RestoreError, "history directory"):
                restore._ensure_history_dir(self.db_dir / restore.HISTORY_DIR)

        if os.name == "nt":
            return

        path = self.db_dir / restore.HISTORY_DIR
        path.mkdir()
        os.chmod(path, 0o755)

        with mock.patch.object(restore.os, "chmod", side_effect=OSError("chmod failed")):
            with self.assertRaisesRegex(restore.RestoreError, "history directory"):
                restore._ensure_history_dir(path)

    def test_atomic_bytes_fsyncs_parent_directory(self) -> None:
        with mock.patch.object(backup_crm7, "fsync_directory", wraps=backup_crm7.fsync_directory) as spy:
            target = self.db_dir / "atomic.bin"
            backup_crm7._atomic_bytes(target, b"payload")

        self.assertIn(self.db_dir, [call.args[0] for call in spy.call_args_list])

    def test_journal_write_fsyncs_db_dir(self) -> None:
        with mock.patch.object(restore, "fsync_directory", wraps=restore.fsync_directory) as spy:
            self.do_restore()

        self.assertIn(self.db_dir, [call.args[0] for call in spy.call_args_list])

    def test_fsync_failure_does_not_report_success(self) -> None:
        with mock.patch.object(backup_crm7, "fsync_directory", side_effect=OSError("fsync failed")):
            with self.assertRaises(OSError):
                backup_crm7._atomic_bytes(self.db_dir / "atomic.bin", b"payload")

    def test_sql_result_write_failure_leaves_no_completed_step(self) -> None:
        original = restore._write_journal

        def fail(db_dir, state):
            if state["phase"] == "sql_import_finished":
                raise OSError("result write failed")

            return original(db_dir, state)

        with mock.patch.object(restore, "_write_journal", side_effect=fail):
            with self.assertRaises(OSError):
                self.do_restore()

        state = self.journal()
        self.assertEqual(state["sql_outcome"], "unknown")
        self.assertIsNone(state["sql_exit_code"])
        self.assertNotIn("sql_import_finished", state["completed_steps"])

    def test_signal_in_sql_result_window_leaves_no_completed_step(self) -> None:
        original = restore._write_journal

        def interrupt(db_dir, state):
            if state["phase"] == "sql_import_finished":
                signal.raise_signal(signal.SIGINT)

            return original(db_dir, state)

        with mock.patch.object(restore, "_write_journal", side_effect=interrupt):
            with self.assertRaises(restore.RestoreInterrupted):
                self.do_restore()

        state = self.journal()
        self.assertEqual(state["sql_outcome"], "unknown")
        self.assertNotIn("sql_import_finished", state["completed_steps"])


class D3HardeningTest(RestoreFixture):
    def _make_previous_journal(self) -> bytes:
        self.do_restore()
        return (self.db_dir / restore.STATE_NAME).read_bytes()

    def assert_no_journal_temps(self) -> None:
        self.assertEqual(list(self.db_dir.glob(".restore-state-*.tmp")), [])
        history = self.db_dir / restore.HISTORY_DIR

        if history.exists():
            self.assertEqual(list(history.glob(".restore-archive-*.tmp")), [])

    def test_a_successful_archive_fsyncs_both_parents_and_mode_0600(self) -> None:
        previous = self._make_previous_journal()
        history = self.db_dir / restore.HISTORY_DIR
        calls = []
        real = restore.fsync_directory

        def record(path):
            calls.append(path)
            return real(path)

        with mock.patch.object(restore, "fsync_directory", side_effect=record):
            self.do_restore()

        self.assertIn(history, calls)
        index = calls.index(history)
        self.assertLess(index + 1, len(calls))
        self.assertEqual(calls[index + 1], self.db_dir)

        archives = list(history.glob("restore-*.json"))
        self.assertEqual(len(archives), 1)
        self.assertEqual(json.loads(archives[0].read_text(encoding="utf-8"))["status"], "complete")

        if os.name != "nt":
            self.assertEqual(stat.S_IMODE(archives[0].stat().st_mode), 0o600)

        self.assertNotEqual((self.db_dir / restore.STATE_NAME).read_bytes(), previous)
        self.assert_no_journal_temps()

    def test_b_history_fsync_failure_rolls_back_with_both_parents_synced(self) -> None:
        previous = self._make_previous_journal()
        history = self.db_dir / restore.HISTORY_DIR
        calls = []
        failed = {"value": False}
        real = restore.fsync_directory

        def fail_once(path):
            calls.append(path)

            if path == history and not failed["value"]:
                failed["value"] = True
                raise OSError("history fsync failed")

            return real(path)

        with mock.patch.object(restore, "fsync_directory", side_effect=fail_once):
            with self.assertRaisesRegex(restore.RestoreError, "cannot publish restore state"):
                self.do_restore()

        self.assertEqual((self.db_dir / restore.STATE_NAME).read_bytes(), previous)
        self.assertEqual(list(history.glob("*.json")), [])
        self.assertEqual(self.snapshot()[".env"], ROOT_ENV_DATA)
        self.assert_no_journal_temps()

        rollback_index = calls.index(self.db_dir, calls.index(history) + 1)
        self.assertEqual(calls[rollback_index + 1], history)

    def test_c_db_dir_fsync_failure_during_archive_rolls_back(self) -> None:
        previous = self._make_previous_journal()
        history = self.db_dir / restore.HISTORY_DIR
        state = {"history_synced": False, "failed": False}
        real = restore.fsync_directory

        # Отказ адресуется конкретному каталогу и моменту: db_dir fsync
        # сразу после того, как архив уже синхронизирован.
        def fail_db_dir_after_history(path):
            if path == history:
                state["history_synced"] = True
                return real(path)

            if (path == self.db_dir and state["history_synced"]
                    and not state["failed"]):
                state["failed"] = True
                raise OSError("db_dir fsync failed")

            return real(path)

        with mock.patch.object(restore, "fsync_directory",
                               side_effect=fail_db_dir_after_history):
            with self.assertRaisesRegex(restore.RestoreError, "cannot publish restore state"):
                self.do_restore()

        self.assertTrue(state["failed"])
        self.assertEqual((self.db_dir / restore.STATE_NAME).read_bytes(), previous)
        self.assertEqual(list(history.glob("*.json")), [])
        self.assertEqual(self.snapshot()[".env"], ROOT_ENV_DATA)
        self.assert_no_journal_temps()

    def test_d_rollback_fsync_failure_is_not_suppressed(self) -> None:
        previous = self._make_previous_journal()
        history = self.db_dir / restore.HISTORY_DIR
        failed = {"history": False, "rollback": False}
        real = restore.fsync_directory

        def fail_both(path):
            if path == history and not failed["history"]:
                failed["history"] = True
                raise OSError("archive fsync failed")
            if path == self.db_dir and failed["history"] and not failed["rollback"]:
                failed["rollback"] = True
                raise OSError("rollback fsync failed")

            return real(path)

        with mock.patch.object(restore, "fsync_directory", side_effect=fail_both):
            with self.assertRaisesRegex(restore.RestoreError, "manual verification required"):
                self.do_restore()

        self.assertEqual((self.db_dir / restore.STATE_NAME).read_bytes(), previous)
        self.assertEqual(self.snapshot()[".env"], ROOT_ENV_DATA)
        self.assert_no_journal_temps()

    def test_e_history_creation_fsync_and_no_redundant_fsync(self) -> None:
        path = self.db_dir / restore.HISTORY_DIR
        calls = []
        real = restore.fsync_directory

        def record(directory):
            calls.append(directory)
            return real(directory)

        with mock.patch.object(restore, "fsync_directory", side_effect=record):
            restore._ensure_history_dir(path)

        self.assertIn(self.db_dir, calls)

        if os.name != "nt":
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o700)

        calls.clear()

        with mock.patch.object(restore, "fsync_directory", side_effect=record):
            restore._ensure_history_dir(path)

        self.assertEqual(calls, [])

        with mock.patch.object(restore, "fsync_directory", side_effect=OSError("fsync failed")):
            with self.assertRaisesRegex(restore.RestoreError, "cannot sync restore history"):
                restore._ensure_history_dir(self.base / "missing-history")

    def test_f_archive_chmod_error_keeps_previous_journal(self) -> None:
        previous = self._make_previous_journal()
        history = self.db_dir / restore.HISTORY_DIR
        original_chmod = os.chmod

        def fail_chmod(path, mode):
            if ".restore-archive-" in str(path):
                raise OSError("chmod failed")

            return original_chmod(path, mode)

        with mock.patch.object(restore.os, "chmod", side_effect=fail_chmod):
            with self.assertRaisesRegex(restore.RestoreError, "cannot publish restore state"):
                self.do_restore()

        self.assertEqual((self.db_dir / restore.STATE_NAME).read_bytes(), previous)
        self.assertEqual(list(history.glob("*.json")), [])
        self.assertEqual(self.snapshot()[".env"], ROOT_ENV_DATA)
        self.assert_no_journal_temps()

    def test_archive_source_change_during_copy_is_rejected(self) -> None:
        previous = self._make_previous_journal()
        history = self.db_dir / restore.HISTORY_DIR
        real = restore._archive_stat_signature
        calls = {"n": 0}

        def changing(path):
            calls["n"] += 1

            if calls["n"] == 2:
                return (999, 999, 999, 999, 999)

            return real(path)

        with mock.patch.object(restore, "_archive_stat_signature", side_effect=changing):
            with self.assertRaisesRegex(restore.RestoreError, "changed during copy"):
                self.do_restore()

        self.assertEqual((self.db_dir / restore.STATE_NAME).read_bytes(), previous)
        self.assertEqual(list(history.glob("*.json")), [])
        self.assertEqual(self.snapshot()[".env"], ROOT_ENV_DATA)
        self.assert_no_journal_temps()

    def test_g_lock_owner_crash_releases_lock(self) -> None:
        if os.name == "nt":
            self.skipTest("POSIX flock is not available on Windows")

        import fcntl
        from update_lock import acquire_update_lock, lock_path, release_update_lock

        path = lock_path(self.project)
        code = (
            "import fcntl,sys,time;"
            "f=open(sys.argv[1],'a+b');"
            "fcntl.flock(f.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB);"
            "time.sleep(1.5)"
        )
        process = subprocess.Popen(
            [sys.executable, "-c", code, str(path)],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )

        try:
            time.sleep(0.35)

            with self.assertRaises(Exception):
                acquire_update_lock(self.project)
        finally:
            process.kill()
            process.wait(timeout=10)

        handle = acquire_update_lock(self.project)
        self.assertIsNotNone(handle)
        release_update_lock(handle)


class DecryptArgvContractTest(RestoreFixture):
    """Проверки реального контракта DECRYPT_PHP (Python -> PHP argv)."""

    class StrictRunner(FakeRunner):
        def __init__(self, project, *args, **kwargs):
            super().__init__(*args, **kwargs)
            self.project = project
            self.decrypt_calls: list[list[str]] = []

        def __call__(self, command, cwd=None, **kwargs):
            if command[0] == "php" and len(command) > 2 and command[2] == restore.DECRYPT_PHP:
                self.decrypt_calls.append(list(command))

                if len(command) not in (6, 7):
                    return FakeResult(2, b"")

                if command[3] != str(self.project / "api"):
                    return FakeResult(2, b"")

                if not command[4].replace("\\", "/").endswith("/.env"):
                    return FakeResult(2, b"")

                if not command[5].replace("\\", "/").endswith("database.json"):
                    return FakeResult(2, b"")

            return super().__call__(command, cwd=cwd, **kwargs)

    def _run(self) -> StrictRunner:
        runner = self.StrictRunner(self.project)
        self.do_restore(dry_run=True, runner=runner, confirm_target=None)
        return runner

    def test_a_three_arguments_after_php_code(self) -> None:
        runner = self._run()

        self.assertTrue(runner.decrypt_calls)

        for call in runner.decrypt_calls:
            self.assertIn(len(call), (6, 7))

    def test_b_argument_order(self) -> None:
        runner = self._run()
        call = runner.decrypt_calls[0]

        self.assertEqual(call[3], str(self.project / "api"))
        self.assertTrue(call[4].replace("\\", "/").endswith("/.env"))
        self.assertTrue(call[5].replace("\\", "/").endswith("database.json"))

        if len(call) > 6:
            self.assertEqual(call[6], "hash")

    def test_c_no_shell(self) -> None:
        with mock.patch.object(restore.subprocess, "run",
                               return_value=FakeResult(0, b"OK")) as spy:
            restore._default_runner(["php", "-r", "echo 1;"], cwd=self.project)

        self.assertEqual(spy.call_count, 1)
        self.assertNotIn("shell", spy.call_args.kwargs)

    def test_d_no_secrets_in_argv_or_output(self) -> None:
        runner = self._run()

        for call in runner.decrypt_calls:
            for marker in SECRET_MARKERS:
                self.assertNotIn(marker, " ".join(call))

    def test_e_php_failure_is_safe(self) -> None:
        runner = self.StrictRunner(self.project)
        runner.php_decrypt_ok = False

        with self.assertRaisesRegex(restore.RestoreError, "decrypt"):
            self.do_restore(dry_run=True, runner=runner, confirm_target=None)

    def test_f_missing_files_are_rejected(self) -> None:
        files = dict(DEFAULT_FILES)
        del files["api/config/database.json"]
        manifest = self.build_bundle(files)

        with self.assertRaisesRegex(restore.RestoreError, "database.json"):
            self.do_restore(dry_run=True, manifest=manifest, confirm_target=None)

    def test_g_missing_third_argument_never_succeeds(self) -> None:
        class TwoArgRunner(FakeRunner):
            def __call__(self, command, cwd=None, **kwargs):
                if command[0] == "php" and len(command) > 2 and command[2] == restore.DECRYPT_PHP:
                    return FakeResult(2, b"")

                return super().__call__(command, cwd=cwd, **kwargs)

        with self.assertRaisesRegex(restore.RestoreError, "decrypt"):
            self.do_restore(dry_run=True, runner=TwoArgRunner(), confirm_target=None)

    def test_h_regression_of_original_defect(self) -> None:
        runner = self.StrictRunner(self.project)

        with mock.patch.object(restore, "_local_php_check",
                               side_effect=RuntimeError("old-two-arg-call")):
            with self.assertRaises(RuntimeError):
                self.do_restore(dry_run=True, runner=runner, confirm_target=None)

        runner = self._run()
        self.assertTrue(runner.decrypt_calls)


class RealPhpDecryptTest(unittest.TestCase):
    """Реальный PHP CLI: не FakeRunner."""

    @unittest.skipUnless(shutil.which("php"), "PHP CLI is not available")
    def test_decrypt_contract_with_real_php_cli(self) -> None:
        php = shutil.which("php")
        version = subprocess.run([php, "-v"], capture_output=True, text=True,
                                 timeout=15).stdout.splitlines()[0]
        self.assertIn("PHP ", version)

        env = {key: value for key, value in os.environ.items()
               if not key.startswith("DB_")}

        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            api = root / "api"
            config = api / "config"
            config.mkdir(parents=True)

            key = secrets.token_hex(32)
            (config / ".env").write_text(f"DB_CONFIG_KEY={key}\n", encoding="utf-8")

            plaintext = secrets.token_hex(16)
            plain_file = root / "plain.txt"
            plain_file.write_text(plaintext, encoding="utf-8")

            encrypt_code = (
                "require $argv[1] . '/src/autoload.php';"
                "\\App\\Core\\Config::load($argv[2]);"
                "$p=file_get_contents($argv[3]);"
                "$e=\\App\\Support\\DatabaseConfigCrypto::encrypt($p);"
                "file_put_contents($argv[4], json_encode(['password'=>$e]));"
            )
            encrypted = subprocess.run(
                [php, "-r", encrypt_code, str(ROOT / "api"),
                 str(config / ".env"), str(plain_file), str(config / "database.json")],
                env=env, capture_output=True, timeout=30,
            )
            self.assertEqual(encrypted.returncode, 0, encrypted.stderr.decode("utf-8", "replace")[:200])

            def run_decrypt() -> subprocess.CompletedProcess:
                return subprocess.run(
                    [php, "-r", restore.DECRYPT_PHP, str(ROOT / "api"),
                     str(config / ".env"), str(config / "database.json")],
                    env=env, capture_output=True, timeout=30,
                )

            ok = run_decrypt()
            self.assertEqual(ok.returncode, 0)
            self.assertIn("OK", ok.stdout.decode("utf-8", "replace"))
            self.assertNotIn(plaintext, ok.stdout.decode("utf-8", "replace"))
            self.assertNotIn(plaintext, ok.stderr.decode("utf-8", "replace"))
            self.assertNotIn(key, ok.stdout.decode("utf-8", "replace"))
            self.assertNotIn(key, ok.stderr.decode("utf-8", "replace"))

            (config / ".env").write_text(
                f"DB_CONFIG_KEY={secrets.token_hex(32)}\n", encoding="utf-8"
            )
            wrong_key = run_decrypt()
            self.assertNotEqual(wrong_key.returncode, 0)

            (config / ".env").write_text(f"DB_CONFIG_KEY={key}\n", encoding="utf-8")
            (config / "database.json").write_text(
                json.dumps({"password": "dbcfg:v1:AAAA"}), encoding="utf-8"
            )
            broken = run_decrypt()
            self.assertNotEqual(broken.returncode, 0)


class FailurePathRestoreTest(RestoreFixture):
    """G3/G5/G7/G8 — дополнительные failure-path проверки."""

    def test_sql_timeout_marks_unknown(self) -> None:
        def timeout(*args, **kwargs):
            raise subprocess.TimeoutExpired(cmd=["docker"], timeout=1)

        with mock.patch.object(restore, "_sql_import", side_effect=timeout):
            with self.assertRaises(subprocess.TimeoutExpired):
                self.do_restore(confirm_target=self.target_id())

        state = self.journal()
        self.assertEqual(state["sql_outcome"], "unknown")
        self.assertEqual(state["status"], "manual_recovery_required")
        self.assertNotIn("complete", state["phase"])

    def test_no_automatic_sql_retry_after_failure(self) -> None:
        calls = {"n": 0}
        real_import = restore._sql_import

        def failing(*args, **kwargs):
            calls["n"] += 1
            return 1

        with mock.patch.object(restore, "_sql_import", side_effect=failing):
            with self.assertRaises(restore.RestoreError):
                self.do_restore(confirm_target=self.target_id())

        self.assertEqual(calls["n"], 1)

    def test_stale_id_lock_blocks_new_restore(self) -> None:
        lock = self.db_dir / f"crm7-restore-id-{DEFAULT_ID}.lock"
        lock.write_bytes(b"")
        before = self.snapshot()

        with self.assertRaisesRegex(restore.RestoreError, "lock is busy"):
            self.do_restore()

        self.assertEqual(lock.read_bytes(), b"")
        self.assertEqual(self.snapshot(), before)
        self.assertFalse((self.db_dir / restore.STATE_NAME).exists())

    def test_pre_sql_signal_keeps_rolled_back_not_manual(self) -> None:
        original = restore._apply_one

        def interrupt(*args, **kwargs):
            original(*args, **kwargs)
            raise KeyboardInterrupt()

        with mock.patch.object(restore, "_apply_one", side_effect=interrupt):
            with self.assertRaises(KeyboardInterrupt):
                self.do_restore(confirm_target=self.target_id())

        state = self.journal()
        self.assertEqual(state["sql_outcome"], "not_started")
        self.assertEqual(state["status"], "rolled_back")

    def test_no_secrets_in_journal_after_sql_failure(self) -> None:
        with mock.patch.object(restore, "_sql_import", return_value=1):
            with self.assertRaises(restore.RestoreError):
                self.do_restore(confirm_target=self.target_id())

        content = (self.db_dir / restore.STATE_NAME).read_text(encoding="utf-8")
        for marker in SECRET_MARKERS:
            self.assertNotIn(marker, content)


if __name__ == "__main__":
    unittest.main()
