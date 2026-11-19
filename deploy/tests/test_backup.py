#!/usr/bin/env python3
"""Тесты backup_crm7.py без Docker, реальной БД и реальных ключей."""
from __future__ import annotations

import contextlib
import hashlib
import io
import json
import os
import pathlib
import signal
import stat
import sys
import subprocess
import tarfile
import threading
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parent.parent.parent
sys.path.insert(0, str(ROOT / "deploy"))

import backup_crm7 as backup  # noqa: E402
import update_lock as lock_api  # noqa: E402


DEFAULT_ID = "20260101T000000Z-abcdef12"
FAKE_SQL = b"-- fake dump\nCREATE TABLE users (ID INT);\n"
FAKE_KEYS = "DB_CONFIG_KEY=unit-test-key\nAPP_KEY=unit-test-app-key\nDB_PASS=unit-pass\n"


class FakeFcntl:
    """Детерминированная замена fcntl для проверки shared/exclusive lock."""

    LOCK_SH = 1
    LOCK_EX = 2
    LOCK_NB = 4
    LOCK_UN = 8

    def __init__(self, busy: bool = False) -> None:
        self.busy = busy
        self.calls: list[int] = []

    def flock(self, descriptor: int, operation: int) -> None:
        self.calls.append(operation)
        if self.busy and operation & self.LOCK_NB:
            raise OSError("busy")


class BackupFixture(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.base = pathlib.Path(self.tmp.name)
        self.project = self.base / "project"
        self.db_dir = self.base / "db-backups"
        self.secrets_dir = self.base / "protected-secrets"

        (self.project / "api" / "config").mkdir(parents=True)
        (self.project / "frontend").mkdir()
        (self.project / ".env").write_text(
            "CRM7_VERSION=1.8.18\nDB_PASS=unit-pass\n", encoding="utf-8"
        )
        (self.project / "api" / "config" / ".env").write_text(FAKE_KEYS, encoding="utf-8")
        (self.project / "api" / "config" / "database.json").write_text(
            json.dumps({
                "host": "db", "port": 3306, "database": "crm7", "user": "crm_app",
                "password": "dbcfg:v1:fake",
            }),
            encoding="utf-8",
        )
        (self.project / "compose.yaml").write_text("services: {}\n", encoding="utf-8")
        (self.project / "frontend" / "package.json").write_text(
            json.dumps({"version": "1.8.18"}), encoding="utf-8"
        )

        # Обычные сценарии платформо-независимы: реальная lock-координация
        # проверяется отдельными тестами ниже. Здесь она заменяется на no-op,
        # чтобы fail-closed require_lock() на Windows не ломал эти сценарии.
        self._require_patch = mock.patch.object(backup, "require_lock")
        self._require_patch.start()
        self.addCleanup(self._require_patch.stop)
        self._lock_patch = mock.patch.object(
            backup, "update_lock",
            side_effect=lambda project: contextlib.nullcontext(),
        )
        self._lock_patch.start()
        self.addCleanup(self._lock_patch.stop)

    @contextlib.contextmanager
    def real_locks(self):
        """Вернуть реальную lock-реализацию на время одного теста."""
        self._require_patch.stop()
        self._lock_patch.stop()
        try:
            yield
        finally:
            self._require_patch.start()
            self._lock_patch.start()

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def make_backup(self, backup_id: str = DEFAULT_ID) -> pathlib.Path:
        output = io.StringIO()

        with redirect_stdout(output):
            manifest = backup.create_backup(
                self.project,
                self.db_dir,
                self.secrets_dir,
                runner=lambda command, cwd: FAKE_SQL,
                backup_id=backup_id,
                require_docker=False,
            )

        self.output = output.getvalue()
        return manifest

    @staticmethod
    def read_manifest(path: pathlib.Path) -> dict:
        return json.loads(path.read_text(encoding="utf-8"))

    def sql_path(self, backup_id: str = DEFAULT_ID) -> pathlib.Path:
        return self.db_dir / f"crm7-{backup_id}.sql"

    def bundle_path(self, backup_id: str = DEFAULT_ID) -> pathlib.Path:
        return self.secrets_dir / f"crm7-{backup_id}.secrets.tar"


class BackupIdTest(BackupFixture):
    def test_backup_id_format(self) -> None:
        self.assertRegex(backup.new_backup_id(), r"^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}$")


class PreflightTest(BackupFixture):
    def test_missing_api_env(self) -> None:
        (self.project / "api" / "config" / ".env").unlink()

        with self.assertRaises(backup.BackupError):
            backup.preflight(self.project, self.db_dir, self.secrets_dir, require_docker=False)

    def test_missing_db_config_key(self) -> None:
        env = self.project / "api" / "config" / ".env"
        env.write_text(
            env.read_text(encoding="utf-8").replace("DB_CONFIG_KEY=unit-test-key\n", ""),
            encoding="utf-8",
        )

        with self.assertRaisesRegex(backup.BackupError, "DB_CONFIG_KEY"):
            backup.preflight(self.project, self.db_dir, self.secrets_dir, require_docker=False)

    def test_missing_app_key(self) -> None:
        env = self.project / "api" / "config" / ".env"
        env.write_text(
            env.read_text(encoding="utf-8").replace("APP_KEY=unit-test-app-key\n", ""),
            encoding="utf-8",
        )

        with self.assertRaisesRegex(backup.BackupError, "APP_KEY"):
            backup.preflight(self.project, self.db_dir, self.secrets_dir, require_docker=False)

    def test_secrets_dir_must_be_separate(self) -> None:
        with self.assertRaisesRegex(backup.BackupError, "separate"):
            backup.preflight(self.project, self.db_dir, self.db_dir, require_docker=False)

    def test_nested_directories_are_rejected(self) -> None:
        nested = self.db_dir / "secrets"

        with self.assertRaisesRegex(backup.BackupError, "separate"):
            backup.preflight(self.project, self.db_dir, nested, require_docker=False)

    def test_symlink_source_is_rejected(self) -> None:
        env = self.project / "api" / "config" / ".env"
        outside = self.base / "outside.env"
        outside.write_text(FAKE_KEYS, encoding="utf-8")
        env.unlink()

        try:
            env.symlink_to(outside)
        except (OSError, NotImplementedError):
            self.skipTest("symlinks are not available in this environment")

        with self.assertRaisesRegex(backup.BackupError, "symlink"):
            backup.preflight(self.project, self.db_dir, self.secrets_dir, require_docker=False)

    def test_compose_symlink_is_rejected(self) -> None:
        compose = self.project / "compose.yaml"
        outside = self.base / "outside-compose.yaml"
        outside.write_text("services: {}\n", encoding="utf-8")
        compose.unlink()

        try:
            compose.symlink_to(outside)
        except (OSError, NotImplementedError):
            self.skipTest("symlinks are not available in this environment")

        with self.assertRaisesRegex(backup.BackupError, "symlink"):
            backup.preflight(self.project, self.db_dir, self.secrets_dir, require_docker=False)

    def test_hardlink_source_is_rejected(self) -> None:
        env = self.project / "api" / "config" / ".env"
        twin = self.project / "api" / "config" / "env-hardlink"

        try:
            os.link(env, twin)
        except (OSError, NotImplementedError):
            self.skipTest("hardlinks are not available in this environment")

        with self.assertRaisesRegex(backup.BackupError, "hardlink"):
            backup.preflight(self.project, self.db_dir, self.secrets_dir, require_docker=False)

    def test_empty_key_value_does_not_claim_validation(self) -> None:
        env = self.project / "api" / "config" / ".env"
        env.write_text("DB_CONFIG_KEY=\nAPP_KEY=\nOTHER=value\n", encoding="utf-8")

        with redirect_stdout(io.StringIO()):
            backup.preflight(self.project, self.db_dir, self.secrets_dir, require_docker=False)

        help_text = backup._parser().format_help()
        docs = (ROOT / "docs" / "backup-restore.md").read_text(encoding="utf-8")
        self.assertIn("валидность", help_text)
        self.assertIn("валид", docs)
        self.assertIn("presence", docs.lower())


class ConfigLockTest(BackupFixture):
    def test_shared_lock_reports_busy(self) -> None:
        lock = self.project / "api" / "config" / "database.json.lock"
        fake = FakeFcntl(busy=True)

        with mock.patch.object(backup, "fcntl", fake):
            with self.assertRaisesRegex(backup.BackupError, "configuration lock is busy"):
                with backup._shared_config_lock(lock):
                    self.fail("lock should not be acquired")

        self.assertIn(fake.LOCK_SH | fake.LOCK_NB, fake.calls)

    def test_lock_released_on_success(self) -> None:
        lock = self.project / "api" / "config" / "database.json.lock"
        fake = FakeFcntl()

        with mock.patch.object(backup, "fcntl", fake):
            with backup._shared_config_lock(lock):
                pass

        self.assertIn(fake.LOCK_SH | fake.LOCK_NB, fake.calls)
        self.assertIn(fake.LOCK_UN, fake.calls)

    def test_lock_released_on_error(self) -> None:
        lock = self.project / "api" / "config" / "database.json.lock"
        fake = FakeFcntl()

        with mock.patch.object(backup, "fcntl", fake):
            with self.assertRaises(ValueError):
                with backup._shared_config_lock(lock):
                    raise ValueError("boom")

        self.assertIn(fake.LOCK_UN, fake.calls)

    def test_config_change_is_detected(self) -> None:
        self.secrets_dir.mkdir(parents=True, exist_ok=True)
        database = self.project / "api" / "config" / "database.json"
        target = self.secrets_dir / "crm7-change.secrets.tar"
        entries = [(pathlib.PurePosixPath("api/config/.env"),
                    self.project / "api" / "config" / ".env")]

        with mock.patch.object(backup, "_stat_signature", side_effect=[(1, 10, 100), (1, 11, 101)]):
            with self.assertRaisesRegex(backup.BackupError, "configuration changed during backup"):
                backup._secret_bundle(
                    self.project, target, entries,
                    lock_path=None, watched_paths=(database,),
                )

        self.assertFalse(target.exists())

    def test_create_backup_uses_config_lock_and_watches_database(self) -> None:
        with mock.patch.object(backup, "_secret_bundle", wraps=backup._secret_bundle) as spy:
            self.make_backup()

        kwargs = spy.call_args.kwargs
        self.assertEqual(kwargs["lock_path"].name, "database.json.lock")
        self.assertTrue(any(path.name == "database.json" for path in kwargs["watched_paths"]))


class DuplicateIdTest(BackupFixture):
    def test_duplicate_id_does_not_overwrite(self) -> None:
        first = self.make_backup()
        before = first.read_bytes()
        runner = mock.Mock(return_value=FAKE_SQL)

        with redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(backup.BackupError, "backup_id is already in use"):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=runner, backup_id=DEFAULT_ID, require_docker=False,
                )

        runner.assert_not_called()
        self.assertEqual(first.read_bytes(), before)
        self.assertEqual(list(self.db_dir.glob("*.manifest.json")), [first])

    def test_parallel_same_id_only_one_wins(self) -> None:
        self.db_dir.mkdir(parents=True, exist_ok=True)

        with backup._backup_id_claim(self.db_dir, "parallel-id"):
            with self.assertRaisesRegex(backup.BackupError, "already in use"):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=lambda command, cwd: FAKE_SQL,
                    backup_id="parallel-id", require_docker=False,
                )

        self.assertFalse((self.db_dir / "crm7-parallel-id.id.lock").exists())
        self.assertFalse(self.sql_path("parallel-id").exists())
        self.assertFalse(self.bundle_path("parallel-id").exists())


class UniqueTempTest(BackupFixture):
    def test_unique_temp_files_for_all_components(self) -> None:
        seen: list[str] = []
        real_mkstemp = tempfile.mkstemp

        def recording(*args, **kwargs):
            descriptor, path = real_mkstemp(*args, **kwargs)
            seen.append(path)
            return descriptor, path

        with mock.patch.object(backup.tempfile, "mkstemp", side_effect=recording):
            self.make_backup()

        self.assertEqual(len(seen), 3)
        self.assertEqual(len(set(seen)), 3)
        self.assertEqual(sum(str(path).startswith(str(self.db_dir)) for path in seen), 2)
        self.assertEqual(sum(str(path).startswith(str(self.secrets_dir)) for path in seen), 1)
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])
        self.assertEqual(list(self.secrets_dir.glob(".*.tmp")), [])


class BackupCreationTest(BackupFixture):
    def test_manifest_and_components(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)

        self.assertEqual(manifest["format_version"], backup.MANIFEST_FORMAT)
        self.assertEqual(manifest["backup_id"], DEFAULT_ID)
        self.assertEqual(manifest["crm7_version"], "1.8.18")
        self.assertEqual(manifest["sql_dump"]["path"], self.sql_path().name)
        self.assertEqual(manifest["secret_bundle"]["path"], self.bundle_path().name)
        self.assertEqual(self.sql_path().read_bytes(), FAKE_SQL)
        self.assertFalse(manifest["secret_bundle"]["encrypted"])
        self.assertIn("api/config/.env", manifest["secret_bundle"]["members"])
        self.assertIn("compose.yaml", manifest["secret_bundle"]["members"])
        self.assertIn(".env", manifest["secret_bundle"]["members"])
        self.assertNotIn("dbcfg:v1:fake", manifest_path.read_text(encoding="utf-8"))
        self.assertNotIn("unit-test-key", self.output)
        self.assertNotIn("unit-pass", self.output)

        with tarfile.open(self.bundle_path()) as archive:
            names = archive.getnames()

        self.assertEqual(sorted(names), sorted(manifest["secret_bundle"]["members"]))
        self.assertTrue(all(not pathlib.PurePosixPath(name).is_absolute() for name in names))

    def test_optional_components_are_recorded(self) -> None:
        (self.project / "api" / "config" / "database.json.bak").unlink(missing_ok=True)
        manifest = self.read_manifest(self.make_backup())

        self.assertIn("api/config/database.json.bak", manifest["missing_optional_components"])

    def test_runtime_files_are_not_bundled(self) -> None:
        (self.project / "api" / "config" / "database.json.lock").write_text("lock", encoding="utf-8")
        (self.project / "api" / "config" / ".cfg-abc").write_text("temp", encoding="utf-8")
        manifest = self.read_manifest(self.make_backup())
        members = manifest["secret_bundle"]["members"]

        self.assertNotIn("api/config/database.json.lock", members)
        self.assertNotIn("api/config/.cfg-abc", members)

    def test_bundle_member_modes_are_0600(self) -> None:
        self.make_backup()

        with tarfile.open(self.bundle_path()) as archive:
            members = archive.getmembers()

        self.assertTrue(members)
        self.assertTrue(all(member.mode == 0o600 for member in members))
        self.assertTrue(all(member.isfile() for member in members))

    def test_file_modes(self) -> None:
        manifest_path = self.make_backup()

        if os.name == "nt":
            self.skipTest("POSIX chmod is not fully supported on Windows")

        for path in (manifest_path, self.sql_path(), self.bundle_path()):
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)

    def test_no_complete_manifest_when_bundle_fails(self) -> None:
        with mock.patch.object(backup, "_secret_bundle", side_effect=backup.BackupError("bundle failed")):
            with self.assertRaisesRegex(backup.BackupError, "bundle"):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=lambda command, cwd: FAKE_SQL,
                    backup_id="20260101T000001Z-abcdef12",
                    require_docker=False,
                )

        self.assertEqual(list(self.db_dir.glob("*.manifest.json")), [])
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])
        self.assertEqual(list(self.secrets_dir.glob(".*.tmp")), [])
        self.assertFalse((self.db_dir / "crm7-20260101T000001Z-abcdef12.id.lock").exists())

    def test_previous_backup_survives_failed_backup(self) -> None:
        first = self.make_backup()
        content_before = first.read_bytes()

        with mock.patch.object(backup.os, "replace", side_effect=OSError("rename failed")):
            with self.assertRaises(OSError):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=lambda command, cwd: FAKE_SQL,
                    backup_id="20260101T000002Z-abcdef12",
                    require_docker=False,
                )

        self.assertEqual(first.read_bytes(), content_before)
        self.assertEqual(list(self.db_dir.glob("*.manifest.json")), [first])
        self.assertFalse((self.db_dir / "crm7-20260101T000002Z-abcdef12.id.lock").exists())

    def test_dump_command_has_no_password_in_argv(self) -> None:
        self.db_dir.mkdir(parents=True, exist_ok=True)
        captured: dict[str, list[str]] = {}

        def runner(command, cwd):
            captured["command"] = command
            return FAKE_SQL

        backup._sql_dump(self.project, self.db_dir / "dump.sql", runner)
        command = captured["command"]

        self.assertEqual(command[:5], ["docker", "compose", "exec", "-T", "db"])
        self.assertIn("MYSQL_PWD", command[-1])
        self.assertTrue(all(not arg.startswith("-p") for arg in command))
        self.assertNotIn('-p"$MARIADB_PASSWORD"', command[-1])
        self.assertNotIn("unit-pass", " ".join(command))


class TarSafetyTest(BackupFixture):
    @staticmethod
    def make_member(name: str, kind: bytes, mode: int = 0o600) -> tarfile.TarInfo:
        info = tarfile.TarInfo(name)
        info.type = kind
        info.mode = mode
        info.linkname = "/etc/passwd"
        return info

    def test_valid_regular_file_is_accepted(self) -> None:
        member = self.make_member("api/config/.env", tarfile.REGTYPE)
        self.assertEqual(
            backup._validate_tar_members([member], ["api/config/.env"]),
            ["api/config/.env"],
        )

    def test_symlink_member_is_rejected(self) -> None:
        member = self.make_member("api/config/.env", tarfile.SYMTYPE)

        with self.assertRaisesRegex(backup.BackupError, "member type"):
            backup._validate_tar_members([member], ["api/config/.env"])

    def test_hardlink_member_is_rejected(self) -> None:
        member = self.make_member("api/config/.env", tarfile.LNKTYPE)

        with self.assertRaisesRegex(backup.BackupError, "member type"):
            backup._validate_tar_members([member], ["api/config/.env"])

    def test_special_members_are_rejected(self) -> None:
        for kind in (tarfile.CHRTYPE, tarfile.FIFOTYPE, tarfile.DIRTYPE):
            with self.subTest(kind=kind):
                member = self.make_member("api/config/.env", kind)

                with self.assertRaisesRegex(backup.BackupError, "member type"):
                    backup._validate_tar_members([member], ["api/config/.env"])

    def test_absolute_and_traversal_members_are_rejected(self) -> None:
        for name in ("/etc/passwd", "../evil.env"):
            with self.subTest(name=name):
                member = self.make_member(name, tarfile.REGTYPE)

                with self.assertRaisesRegex(backup.BackupError, "unsafe bundle member"):
                    backup._validate_tar_members([member], [name])

    def test_loose_member_mode_is_rejected(self) -> None:
        member = self.make_member("api/config/.env", tarfile.REGTYPE, mode=0o644)

        with self.assertRaisesRegex(backup.BackupError, "member mode"):
            backup._validate_tar_members([member], ["api/config/.env"])

    def test_unexpected_members_are_rejected(self) -> None:
        member = self.make_member("api/config/extra.env", tarfile.REGTYPE)

        with self.assertRaisesRegex(backup.BackupError, "contents mismatch"):
            backup._validate_tar_members([member], ["api/config/.env"])

    def test_verify_rejects_tar_with_symlink_member(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)

        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode="w") as archive:
            archive.addfile(self.make_member("api/config/.env", tarfile.SYMTYPE))

        payload = buffer.getvalue()
        self.bundle_path().write_bytes(payload)
        manifest["secret_bundle"]["size"] = len(payload)
        manifest["secret_bundle"]["sha256"] = hashlib.sha256(payload).hexdigest()
        manifest["secret_bundle"]["members"] = ["api/config/.env"]
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "member type"):
            backup.verify(manifest_path, self.secrets_dir)


class VerifyTest(BackupFixture):
    def verify(self, path: pathlib.Path) -> None:
        with redirect_stdout(io.StringIO()):
            backup.verify(path, self.secrets_dir)

    def test_verify_success(self) -> None:
        self.verify(self.make_backup())

    def test_verify_does_not_extract_anything(self) -> None:
        self.verify(self.make_backup())

        self.assertFalse((self.base / "api").exists())
        self.assertFalse((self.base / "compose.yaml").exists())

    def test_corrupted_sql(self) -> None:
        manifest_path = self.make_backup()
        corrupted = b"-- corrupted" + b"x" * (len(FAKE_SQL) - len(b"-- corrupted"))
        self.sql_path().write_bytes(corrupted)

        with self.assertRaisesRegex(backup.BackupError, "checksum"):
            self.verify(manifest_path)

    def test_corrupted_bundle(self) -> None:
        manifest_path = self.make_backup()
        size = self.bundle_path().stat().st_size
        self.bundle_path().write_bytes(b"not a tar".ljust(size, b"x"))

        with self.assertRaisesRegex(backup.BackupError, "checksum"):
            self.verify(manifest_path)

    def test_size_mismatch(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        manifest["sql_dump"]["size"] += 1
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "size mismatch"):
            self.verify(manifest_path)

    def test_missing_component(self) -> None:
        manifest_path = self.make_backup()
        self.sql_path().unlink()

        with self.assertRaisesRegex(backup.BackupError, "missing"):
            self.verify(manifest_path)

    def test_absolute_member_path_is_rejected(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        manifest["secret_bundle"]["members"] = ["/etc/passwd"]
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "contents mismatch"):
            self.verify(manifest_path)

    def test_manifest_id_mismatch(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        manifest["backup_id"] = "20260101T000000Z-deadbeef"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "manifest name does not match"):
            self.verify(manifest_path)

    def test_sql_component_id_mismatch(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        manifest["sql_dump"]["path"] = "crm7-20260101T000000Z-deadbeef.sql"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "component name does not match"):
            self.verify(manifest_path)

    def test_bundle_component_id_mismatch(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        manifest["secret_bundle"]["path"] = "crm7-20260101T000000Z-deadbeef.secrets.tar"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "component name does not match"):
            self.verify(manifest_path)

    def test_bad_format_version(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        manifest["format_version"] = "1"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "unsupported manifest format"):
            self.verify(manifest_path)

    def test_missing_required_field(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        del manifest["created_at"]
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "invalid manifest field"):
            self.verify(manifest_path)

    def test_missing_version_field(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        del manifest["crm7_version"]
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "invalid manifest field"):
            self.verify(manifest_path)

    def test_absolute_component_path(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        manifest["sql_dump"]["path"] = "/etc/passwd"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "unsafe component path"):
            self.verify(manifest_path)

    def test_traversal_component_path(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        manifest["secret_bundle"]["path"] = "../outside.tar"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "unsafe component path"):
            self.verify(manifest_path)

    def test_invalid_checksum_type(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        manifest["sql_dump"]["sha256"] = "zzzz"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "invalid component checksum"):
            self.verify(manifest_path)

    def test_invalid_size_type(self) -> None:
        manifest_path = self.make_backup()
        manifest = self.read_manifest(manifest_path)
        manifest["sql_dump"]["size"] = "42"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

        with self.assertRaisesRegex(backup.BackupError, "invalid component size"):
            self.verify(manifest_path)


class BackupFailureInjectionTest(BackupFixture):
    """G1/G4 — отказы runner и phase-specific failure windows."""

    def test_runner_exception_no_complete_backup(self) -> None:
        runner = mock.Mock(side_effect=RuntimeError("runner down"))

        with redirect_stdout(io.StringIO()):
            with self.assertRaises(RuntimeError):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=runner, backup_id=DEFAULT_ID, require_docker=False,
                )

        self.assertFalse(self.sql_path().exists())
        self.assertFalse(self.bundle_path().exists())
        self.assertEqual(list(self.db_dir.glob("*.manifest.json")), [])
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])
        self.assertEqual(list(self.secrets_dir.glob(".*.tmp")), [])
        self.assertFalse((self.db_dir / f"crm7-{DEFAULT_ID}.id.lock").exists())

    def test_runner_nonzero_exit_via_calledprocesserror(self) -> None:
        error = subprocess.CalledProcessError(1, ["mysqldump"])
        runner = mock.Mock(side_effect=error)

        with redirect_stdout(io.StringIO()):
            with self.assertRaises(subprocess.CalledProcessError):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=runner, backup_id=DEFAULT_ID, require_docker=False,
                )

        self.assertFalse(self.sql_path().exists())
        self.assertEqual(list(self.db_dir.glob("*.manifest.json")), [])

    def test_empty_dump_is_rejected(self) -> None:
        with redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(backup.BackupError, "empty SQL dump"):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=lambda command, cwd: b"",
                    backup_id=DEFAULT_ID, require_docker=False,
                )

        self.assertFalse(self.sql_path().exists())
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])

    def test_bad_dump_header_is_rejected(self) -> None:
        with redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(backup.BackupError, "unexpected SQL dump header"):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=lambda command, cwd: b"not-a-dump\n",
                    backup_id=DEFAULT_ID, require_docker=False,
                )

        self.assertFalse(self.sql_path().exists())

    def test_sql_write_failure_removes_temp_and_lock(self) -> None:
        real_atomic = backup._atomic_bytes

        def fail_sql(path, payload):
            if path.name.endswith(".sql"):
                raise OSError("write failed")
            return real_atomic(path, payload)

        with mock.patch.object(backup, "_atomic_bytes", side_effect=fail_sql):
            with redirect_stdout(io.StringIO()):
                with self.assertRaises(OSError):
                    backup.create_backup(
                        self.project, self.db_dir, self.secrets_dir,
                        runner=lambda command, cwd: FAKE_SQL,
                        backup_id=DEFAULT_ID, require_docker=False,
                    )

        self.assertFalse(self.sql_path().exists())
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])
        self.assertFalse((self.db_dir / f"crm7-{DEFAULT_ID}.id.lock").exists())

    def test_manifest_write_failure_leaves_sql_and_bundle_but_no_manifest(self) -> None:
        real_atomic = backup._atomic_bytes

        def fail_manifest(path, payload):
            if path.name.endswith(".manifest.json"):
                raise OSError("manifest failed")
            return real_atomic(path, payload)

        with mock.patch.object(backup, "_atomic_bytes", side_effect=fail_manifest):
            with redirect_stdout(io.StringIO()):
                with self.assertRaises(OSError):
                    backup.create_backup(
                        self.project, self.db_dir, self.secrets_dir,
                        runner=lambda command, cwd: FAKE_SQL,
                        backup_id=DEFAULT_ID, require_docker=False,
                    )

        self.assertTrue(self.sql_path().exists())
        self.assertTrue(self.bundle_path().exists())
        self.assertEqual(list(self.db_dir.glob("*.manifest.json")), [])
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])
        self.assertEqual(list(self.secrets_dir.glob(".*.tmp")), [])
        self.assertFalse((self.db_dir / f"crm7-{DEFAULT_ID}.id.lock").exists())

    def test_bundle_publication_failure_no_manifest(self) -> None:
        real_replace = os.replace

        def fail_bundle(source, target):
            if str(target).endswith(".secrets.tar"):
                raise OSError("bundle publish failed")
            return real_replace(source, target)

        with mock.patch.object(backup.os, "replace", side_effect=fail_bundle):
            with redirect_stdout(io.StringIO()):
                with self.assertRaises(OSError):
                    backup.create_backup(
                        self.project, self.db_dir, self.secrets_dir,
                        runner=lambda command, cwd: FAKE_SQL,
                        backup_id=DEFAULT_ID, require_docker=False,
                    )

        self.assertTrue(self.sql_path().exists())
        self.assertFalse(self.bundle_path().exists())
        self.assertEqual(list(self.db_dir.glob("*.manifest.json")), [])
        self.assertEqual(list(self.secrets_dir.glob(".*.tmp")), [])
        self.assertFalse((self.db_dir / f"crm7-{DEFAULT_ID}.id.lock").exists())

    def test_bundle_validation_failure_removes_published_bundle(self) -> None:
        with mock.patch.object(backup, "_validate_tar_members",
                               side_effect=backup.BackupError("validation failed")):
            with redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(backup.BackupError, "validation failed"):
                    backup.create_backup(
                        self.project, self.db_dir, self.secrets_dir,
                        runner=lambda command, cwd: FAKE_SQL,
                        backup_id=DEFAULT_ID, require_docker=False,
                    )

        self.assertTrue(self.sql_path().exists())
        self.assertFalse(self.bundle_path().exists())
        self.assertEqual(list(self.db_dir.glob("*.manifest.json")), [])
        self.assertFalse((self.db_dir / f"crm7-{DEFAULT_ID}.id.lock").exists())

    def test_previous_backup_survives_failed_new_backup(self) -> None:
        first = self.make_backup()
        before = first.read_bytes()
        runner = mock.Mock(side_effect=RuntimeError("boom"))

        with redirect_stdout(io.StringIO()):
            with self.assertRaises(RuntimeError):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=runner, backup_id="20260102T000000Z-aaaaaaaa",
                    require_docker=False,
                )

        self.assertEqual(first.read_bytes(), before)
        self.assertEqual(list(self.db_dir.glob("*.manifest.json")), [first])

    def test_no_credentials_in_failure_output(self) -> None:
        runner = mock.Mock(side_effect=RuntimeError("boom"))
        output = io.StringIO()

        with redirect_stdout(output):
            with self.assertRaises(RuntimeError):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=runner, backup_id=DEFAULT_ID, require_docker=False,
                )

        for marker in ("unit-test-key", "unit-pass", "dbcfg:v1:fake"):
            self.assertNotIn(marker, output.getvalue())


class BackupFilesystemFaultTest(BackupFixture):
    """G2 — fsync/rename/chmod failures on temporary filesystem."""

    def setUp(self) -> None:
        super().setUp()
        self.db_dir.mkdir(parents=True, exist_ok=True)
        self.secrets_dir.mkdir(parents=True, exist_ok=True)

    def test_atomic_bytes_fsync_failure_removes_temp(self) -> None:
        target = self.db_dir / "atomic.bin"
        with mock.patch.object(backup.os, "fsync", side_effect=OSError("fsync failed")):
            with self.assertRaises(OSError):
                backup._atomic_bytes(target, b"payload")

        self.assertFalse(target.exists())
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])

    def test_atomic_bytes_replace_failure_removes_temp(self) -> None:
        target = self.db_dir / "atomic.bin"
        with mock.patch.object(backup.os, "replace", side_effect=OSError("replace failed")):
            with self.assertRaises(OSError):
                backup._atomic_bytes(target, b"payload")

        self.assertFalse(target.exists())
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])

    def test_atomic_bytes_chmod_failure_propagates(self) -> None:
        target = self.db_dir / "atomic.bin"
        real_chmod = os.chmod
        target_str = str(target)

        def fail_chmod(path, mode):
            if str(path) == target_str:
                raise OSError("chmod failed")
            return real_chmod(path, mode)

        with mock.patch.object(backup.os, "chmod", side_effect=fail_chmod):
            with self.assertRaisesRegex(OSError, "chmod failed"):
                backup._atomic_bytes(target, b"payload")

        self.assertTrue(target.exists())

    def test_directory_fsync_failure_propagates(self) -> None:
        target = self.db_dir / "atomic.bin"
        with mock.patch.object(backup, "fsync_directory", side_effect=OSError("dir fsync failed")):
            with self.assertRaisesRegex(OSError, "dir fsync failed"):
                backup._atomic_bytes(target, b"payload")

        # Файл опубликован os.replace до fsync каталога: это ожидаемое поведение,
        # но операция не должна сообщать об успехе.
        self.assertTrue(target.exists())
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])

    def test_bundle_fsync_failure_removes_temp(self) -> None:
        target = self.secrets_dir / "crm7-bundle.secrets.tar"
        entries = [(pathlib.PurePosixPath("api/config/.env"),
                    self.project / "api" / "config" / ".env")]
        with mock.patch.object(backup.os, "fsync", side_effect=OSError("fsync failed")):
            with self.assertRaises(OSError):
                backup._secret_bundle(self.project, target, entries)

        self.assertFalse(target.exists())
        self.assertEqual(list(self.secrets_dir.glob(".*.tmp")), [])


# --- Изолированные subprocess-помощники для реальной POSIX-блокировки ---
# Скрипты работают только с путями из TemporaryDirectory и не трогают CRM7.

LOCK_HOLDER_SCRIPT = r'''
import pathlib, sys
path = pathlib.Path(sys.argv[1])
path.parent.mkdir(parents=True, exist_ok=True)
import fcntl
with path.open("a+b") as handle:
    fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
    print("READY", flush=True)
    sys.stdin.readline()
'''

BACKUP_HOLDER_SCRIPT = r'''
import pathlib, sys
sys.path.insert(0, sys.argv[1])
import backup_crm7 as b
project = pathlib.Path(sys.argv[2])
db = pathlib.Path(sys.argv[3])
secrets = pathlib.Path(sys.argv[4])
backup_id = sys.argv[5]
def runner(command, cwd):
    print("RUNNING", flush=True)
    sys.stdin.readline()
    return b"-- fake dump\nCREATE TABLE users (ID INT);\n"
b.create_backup(project, db, secrets, runner=runner,
                backup_id=backup_id, require_docker=False)
print("DONE", flush=True)
'''

SIGKILL_HOLDER_SCRIPT = r'''
import pathlib, sys
lock = pathlib.Path(sys.argv[1])
marker = pathlib.Path(sys.argv[2])
lock.parent.mkdir(parents=True, exist_ok=True)
marker.parent.mkdir(parents=True, exist_ok=True)
import fcntl
with lock.open("a+b") as handle:
    fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
    marker.touch(mode=0o600)
    print("READY", flush=True)
    sys.stdin.readline()
'''


class SubprocessHolderMixin:
    """Только тестовые Python-процессы; всегда завершаются в cleanup."""

    def _start_process(self, script: str, *args) -> subprocess.Popen:
        process = subprocess.Popen(
            [sys.executable, "-c", script, *[str(arg) for arg in args]],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True,
        )
        self.addCleanup(self._stop_process, process)
        return process

    @staticmethod
    def _ready(process: subprocess.Popen, expected: str = "READY") -> None:
        line = process.stdout.readline().strip()
        if line != expected:
            raise AssertionError(f"expected {expected!r}, got {line!r}")

    @staticmethod
    def _release(process: subprocess.Popen) -> None:
        process.stdin.write("go\n")
        process.stdin.flush()

    @staticmethod
    def _stop_process(process: subprocess.Popen) -> None:
        if process.poll() is None:
            try:
                process.stdin.write("go\n")
                process.stdin.flush()
            except (OSError, ValueError):
                pass
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=10)
        for stream in (process.stdin, process.stdout, process.stderr):
            try:
                stream.close()
            except OSError:
                pass


def _recording_project_lock(events: list[str]):
    """Контекстный менеджер, фиксирующий захват/освобождение project lock."""

    @contextlib.contextmanager
    def recorder(project):
        events.append("project:enter")
        try:
            yield None
        finally:
            events.append("project:exit")

    return recorder


@unittest.skipUnless(lock_api.LOCK_SUPPORTED, "POSIX flock required")
class ProjectLockIntegrationTest(SubprocessHolderMixin, BackupFixture):
    """A/B/F/G — реальный flock в temp dir, только изолированные subprocess."""

    def test_foreign_process_holding_lock_blocks_backup_and_no_artifacts(self) -> None:
        holder = self._start_process(LOCK_HOLDER_SCRIPT, lock_api.lock_path(self.project))
        self._ready(holder)

        with self.real_locks():
            with self.assertRaises(backup.LockBusy):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=lambda command, cwd: FAKE_SQL,
                    backup_id=DEFAULT_ID, require_docker=False,
                )

        self.assertFalse((self.db_dir / f"crm7-{DEFAULT_ID}.id.lock").exists())
        self.assertFalse(self.sql_path().exists())
        self.assertFalse(self.bundle_path().exists())
        self.assertEqual(list(self.db_dir.glob("*.manifest.json")), [])
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])
        self.assertEqual(list(self.secrets_dir.glob(".*.tmp")), [])

        self._release(holder)
        self.assertEqual(holder.wait(timeout=10), 0)
        with self.real_locks():
            self.make_backup()
        self.assertTrue(self.sql_path().exists())

    def test_two_backups_different_ids_are_serialized(self) -> None:
        second_id = "20260101T000001Z-abcdef13"
        holder = self._start_process(
            BACKUP_HOLDER_SCRIPT, str(ROOT / "deploy"),
            self.project, self.db_dir, self.secrets_dir, DEFAULT_ID,
        )
        self._ready(holder, "RUNNING")

        with self.real_locks():
            with self.assertRaises(backup.LockBusy):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=lambda command, cwd: FAKE_SQL,
                    backup_id=second_id, require_docker=False,
                )

        self.assertFalse((self.db_dir / f"crm7-{second_id}.id.lock").exists())
        self.assertFalse(self.sql_path(second_id).exists())
        self.assertTrue((self.db_dir / f"crm7-{DEFAULT_ID}.id.lock").exists())

        self._release(holder)
        # create_backup печатает 'backup ready' до нашего маркера DONE.
        for line in holder.stdout:
            if line.strip() == "DONE":
                break
        else:
            self.fail("backup holder exited without DONE")
        self.assertEqual(holder.wait(timeout=10), 0)
        self.assertFalse((self.db_dir / f"crm7-{DEFAULT_ID}.id.lock").exists())

        with self.real_locks():
            self.make_backup(second_id)
        self.assertTrue(self.sql_path(second_id).exists())

    def test_sigkill_releases_flock_but_leaves_synthetic_marker(self) -> None:
        marker = self.db_dir / f"crm7-{DEFAULT_ID}.id.lock"
        holder = self._start_process(
            SIGKILL_HOLDER_SCRIPT, lock_api.lock_path(self.project), marker
        )
        self._ready(holder)

        holder.kill()
        self.assertEqual(holder.wait(timeout=10), -signal.SIGKILL)
        self.assertTrue(marker.exists())

        # ОС освободила flock: тот же процесс может захватить lock повторно.
        with self.real_locks():
            handle = lock_api.acquire_update_lock(self.project)
            self.assertIsNotNone(handle)
            lock_api.release_update_lock(handle)

            with self.assertRaisesRegex(backup.BackupError, "backup_id is already in use"):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=lambda command, cwd: FAKE_SQL,
                    backup_id=DEFAULT_ID, require_docker=False,
                )

        # Ручная очистка только синтетического маркера и повтор с тем же ID.
        marker.unlink()
        with self.real_locks():
            self.make_backup()
        self.assertTrue(self.sql_path().exists())

    def test_verify_works_while_project_lock_is_held(self) -> None:
        manifest = self.make_backup()
        handle = lock_api.acquire_update_lock(self.project)
        try:
            with redirect_stdout(io.StringIO()):
                backup.verify(manifest, self.secrets_dir)
        finally:
            lock_api.release_update_lock(handle)


class LockOrderTest(BackupFixture):
    """C — порядок захвата проверяется поведением через моки, не текстом исходника."""

    def test_project_lock_enters_first_and_exits_last(self) -> None:
        events: list[str] = []
        real_preflight = backup.preflight
        real_claim = backup._backup_id_claim
        real_sql = backup._sql_dump
        real_config_lock = backup._shared_config_lock
        real_atomic = backup._atomic_bytes

        def preflight(*args, **kwargs):
            events.append("preflight")
            return real_preflight(*args, **kwargs)

        @contextlib.contextmanager
        def claim(*args, **kwargs):
            events.append("claim:enter")
            try:
                with real_claim(*args, **kwargs):
                    yield
            finally:
                events.append("claim:exit")

        def sql(*args, **kwargs):
            events.append("sql")
            return real_sql(*args, **kwargs)

        @contextlib.contextmanager
        def config_lock(*args, **kwargs):
            events.append("config:enter")
            try:
                with real_config_lock(*args, **kwargs):
                    yield
            finally:
                events.append("config:exit")

        def atomic(path, payload):
            if path.name.endswith(".manifest.json"):
                events.append("manifest")
            return real_atomic(path, payload)

        with mock.patch.object(backup, "update_lock",
                               side_effect=_recording_project_lock(events)), \
             mock.patch.object(backup, "preflight", side_effect=preflight), \
             mock.patch.object(backup, "_backup_id_claim", side_effect=claim), \
             mock.patch.object(backup, "_sql_dump", side_effect=sql), \
             mock.patch.object(backup, "_shared_config_lock", side_effect=config_lock), \
             mock.patch.object(backup, "_atomic_bytes", side_effect=atomic):
            with redirect_stdout(io.StringIO()):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=lambda command, cwd: FAKE_SQL,
                    backup_id=DEFAULT_ID, require_docker=False,
                )

        self.assertEqual(events[0], "project:enter")
        self.assertLess(events.index("preflight"), events.index("claim:enter"))
        self.assertLess(events.index("claim:enter"), events.index("sql"))
        self.assertLess(events.index("sql"), events.index("config:enter"))
        self.assertLess(events.index("config:enter"), events.index("config:exit"))
        # Config lock снимается внутри _secret_bundle, до записи manifest;
        # manifest и ID claim остаются внутри project lock.
        self.assertLess(events.index("config:exit"), events.index("manifest"))
        self.assertLess(events.index("claim:exit"), events.index("project:exit"))
        self.assertEqual(events[-1], "project:exit")


class LockCleanupTest(BackupFixture):
    """D — освобождение project lock и маркера при исключениях."""

    def _run_with_failure(self, failure_patch) -> list[str]:
        events: list[str] = []
        with mock.patch.object(backup, "update_lock",
                               side_effect=_recording_project_lock(events)), \
             failure_patch:
            with self.assertRaises(RuntimeError):
                with redirect_stdout(io.StringIO()):
                    backup.create_backup(
                        self.project, self.db_dir, self.secrets_dir,
                        runner=lambda command, cwd: FAKE_SQL,
                        backup_id=DEFAULT_ID, require_docker=False,
                    )
        return events

    def _assert_cleanup(self, events: list[str]) -> None:
        self.assertEqual(events, ["project:enter", "project:exit"])
        self.assertFalse((self.db_dir / f"crm7-{DEFAULT_ID}.id.lock").exists())
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])
        self.assertEqual(list(self.secrets_dir.glob(".*.tmp")), [])

    def _retry_same_id(self) -> None:
        # Удаляем только неполные артефакты неуспешной операции, как оператор.
        self.sql_path().unlink(missing_ok=True)
        self.bundle_path().unlink(missing_ok=True)
        (self.db_dir / f"crm7-{DEFAULT_ID}.manifest.json").unlink(missing_ok=True)

        with redirect_stdout(io.StringIO()):
            backup.create_backup(
                self.project, self.db_dir, self.secrets_dir,
                runner=lambda command, cwd: FAKE_SQL,
                backup_id=DEFAULT_ID, require_docker=False,
            )
        self.assertTrue(self.sql_path().exists())

    def test_preflight_exception_releases_lock(self) -> None:
        events = self._run_with_failure(
            mock.patch.object(backup, "preflight",
                              side_effect=RuntimeError("preflight failed"))
        )
        self._assert_cleanup(events)
        self._retry_same_id()

    def test_exception_after_id_claim_releases_marker(self) -> None:
        events = self._run_with_failure(
            mock.patch.object(backup, "_sql_dump",
                              side_effect=RuntimeError("dump failed"))
        )
        self._assert_cleanup(events)
        self._retry_same_id()

    def test_sql_write_exception_releases_lock(self) -> None:
        real_atomic = backup._atomic_bytes

        def fail_sql(path, payload):
            if path.name.endswith(".sql"):
                raise RuntimeError("sql write failed")
            return real_atomic(path, payload)

        events = self._run_with_failure(
            mock.patch.object(backup, "_atomic_bytes", side_effect=fail_sql)
        )
        self._assert_cleanup(events)
        self._retry_same_id()

    def test_secret_bundle_exception_releases_lock(self) -> None:
        events = self._run_with_failure(
            mock.patch.object(backup, "_secret_bundle",
                              side_effect=RuntimeError("bundle failed"))
        )
        self._assert_cleanup(events)
        self._retry_same_id()

    def test_manifest_exception_releases_lock(self) -> None:
        real_atomic = backup._atomic_bytes

        def fail_manifest(path, payload):
            if path.name.endswith(".manifest.json"):
                raise RuntimeError("manifest failed")
            return real_atomic(path, payload)

        events = self._run_with_failure(
            mock.patch.object(backup, "_atomic_bytes", side_effect=fail_manifest)
        )
        self._assert_cleanup(events)
        self._retry_same_id()


class SignalHandlingTest(BackupFixture):
    """E/G — SIGTERM через контролируемое исключение и отсутствие сайд-эффектов verify."""

    def test_sigterm_raises_backup_interrupted_and_cleans_up(self) -> None:
        events: list[str] = []
        handlers: dict[int, object] = {}

        def fake_signal(signum, handler):
            self.assertEqual(signum, signal.SIGTERM, "SIGINT handler must not be replaced")
            handlers[signum] = handler
            return signal.SIG_DFL

        signal_mock = mock.Mock(side_effect=fake_signal)

        def runner(command, cwd):
            self.assertIn(signal.SIGTERM, handlers)
            handlers[signal.SIGTERM](signal.SIGTERM, None)
            return FAKE_SQL

        with mock.patch.object(backup.signal, "signal", signal_mock), \
             mock.patch.object(backup, "update_lock",
                               side_effect=_recording_project_lock(events)):
            with self.assertRaises(backup.BackupInterrupted):
                with redirect_stdout(io.StringIO()):
                    backup.create_backup(
                        self.project, self.db_dir, self.secrets_dir,
                        runner=runner, backup_id=DEFAULT_ID, require_docker=False,
                    )

        self.assertEqual(events, ["project:enter", "project:exit"])
        self.assertEqual(signal_mock.call_count, 2)
        self.assertEqual(signal_mock.call_args_list[1].args,
                         (signal.SIGTERM, signal.SIG_DFL))
        self.assertFalse((self.db_dir / f"crm7-{DEFAULT_ID}.id.lock").exists())
        self.assertEqual(list(self.db_dir.glob(".*.tmp")), [])
        self.assertEqual(list(self.secrets_dir.glob(".*.tmp")), [])

        with redirect_stdout(io.StringIO()):
            backup.create_backup(
                self.project, self.db_dir, self.secrets_dir,
                runner=lambda command, cwd: FAKE_SQL,
                backup_id=DEFAULT_ID, require_docker=False,
            )
        self.assertTrue(self.sql_path().exists())

    def test_verify_does_not_touch_project_lock_or_signal_handlers(self) -> None:
        manifest = self.make_backup()

        def forbid(*args, **kwargs):
            raise AssertionError("verify must not touch lock or signals")

        with mock.patch.object(backup, "update_lock", side_effect=forbid), \
             mock.patch.object(backup, "require_lock", side_effect=forbid), \
             mock.patch.object(backup.signal, "signal", side_effect=forbid):
            with redirect_stdout(io.StringIO()):
                backup.verify(manifest, self.secrets_dir)


class SignalGuardThreadTest(unittest.TestCase):
    """Поведение backup._interrupt_guard в главном и неосновном потоках."""

    def _run_in_thread(self, target, timeout: float = 15) -> None:
        thread = threading.Thread(target=target)
        thread.start()
        thread.join(timeout=timeout)
        self.assertFalse(thread.is_alive(), "worker thread did not finish in time")

    def test_worker_thread_guard_is_noop_and_keeps_main_handler(self) -> None:
        original = signal.getsignal(signal.SIGTERM)
        real_signal = signal.signal
        calls: list[int] = []
        errors: list[BaseException] = []
        observed: dict[str, object] = {}
        entered = threading.Event()

        def tracking(signum, handler):
            calls.append(signum)
            return real_signal(signum, handler)

        def worker() -> None:
            try:
                with backup._interrupt_guard():
                    observed["handler"] = signal.getsignal(signal.SIGTERM)
                    entered.set()
            except BaseException as error:
                errors.append(error)

        with mock.patch.object(backup.signal, "signal", side_effect=tracking):
            self._run_in_thread(worker)

        self.assertTrue(entered.is_set())
        self.assertIn(signal.SIGTERM, calls, "install attempt must reach signal.signal")
        self.assertEqual(errors, [])
        # В потоке install падает с ValueError и перехватывается: состояние не меняется.
        self.assertEqual(observed["handler"], original)
        self.assertEqual(signal.getsignal(signal.SIGTERM), original)

    def test_worker_thread_guard_does_not_suppress_body_exception(self) -> None:
        original = signal.getsignal(signal.SIGTERM)
        errors: list[BaseException] = []

        def worker() -> None:
            try:
                with backup._interrupt_guard():
                    raise RuntimeError("body failed")
            except BaseException as error:
                errors.append(error)

        self._run_in_thread(worker)

        self.assertEqual(len(errors), 1)
        self.assertIsInstance(errors[0], RuntimeError)
        self.assertEqual(str(errors[0]), "body failed")
        self.assertEqual(signal.getsignal(signal.SIGTERM), original)

    def test_main_thread_guard_restores_previous_handler(self) -> None:
        original = signal.getsignal(signal.SIGTERM)

        def sentinel(signum, frame):
            pass

        signal.signal(signal.SIGTERM, sentinel)
        try:
            self.assertIs(signal.getsignal(signal.SIGTERM), sentinel)
            with backup._interrupt_guard():
                self.assertIsNot(signal.getsignal(signal.SIGTERM), sentinel)
            self.assertIs(signal.getsignal(signal.SIGTERM), sentinel)

            with self.assertRaisesRegex(RuntimeError, "body"):
                with backup._interrupt_guard():
                    raise RuntimeError("body")
            self.assertIs(signal.getsignal(signal.SIGTERM), sentinel)
        finally:
            signal.signal(signal.SIGTERM, original)

    def test_create_backup_from_worker_thread_keeps_main_handler(self) -> None:
        original = signal.getsignal(signal.SIGTERM)
        errors: list[BaseException] = []

        def worker() -> None:
            try:
                with redirect_stdout(io.StringIO()):
                    backup.create_backup(
                        pathlib.Path(self.tmp.name) / "project",
                        pathlib.Path(self.tmp.name) / "db-backups",
                        pathlib.Path(self.tmp.name) / "protected-secrets",
                        runner=lambda command, cwd: FAKE_SQL,
                        backup_id=DEFAULT_ID, require_docker=False,
                    )
            except BaseException as error:
                errors.append(error)

        self.tmp = tempfile.TemporaryDirectory()
        self.base = pathlib.Path(self.tmp.name)
        self.project = self.base / "project"
        self.db_dir = self.base / "db-backups"
        self.secrets_dir = self.base / "protected-secrets"
        (self.project / "api" / "config").mkdir(parents=True)
        (self.project / "frontend").mkdir()
        (self.project / ".env").write_text("CRM7_VERSION=1.8.18\n", encoding="utf-8")
        (self.project / "api" / "config" / ".env").write_text(FAKE_KEYS, encoding="utf-8")
        (self.project / "api" / "config" / "database.json").write_text(json.dumps({
            "host": "db", "port": 3306, "database": "crm7", "user": "crm_app",
            "password": "dbcfg:v1:fake",
        }), encoding="utf-8")
        (self.project / "compose.yaml").write_text("services: {}\n", encoding="utf-8")
        (self.project / "frontend" / "package.json").write_text(
            json.dumps({"version": "1.8.18"}), encoding="utf-8"
        )

        try:
            # Внешние операции подменены: реальные lock/Docker/БД не используются.
            with mock.patch.object(backup, "require_lock"), \
                 mock.patch.object(backup, "update_lock",
                                   side_effect=lambda project: contextlib.nullcontext()):
                self._run_in_thread(worker, timeout=30)

            self.assertEqual(errors, [])
            self.assertTrue((self.db_dir / f"crm7-{DEFAULT_ID}.manifest.json").exists())
            self.assertEqual(signal.getsignal(signal.SIGTERM), original)
        finally:
            self.tmp.cleanup()


class CleanupExceptionTest(BackupFixture):
    """Сохранение первичного исключения при ошибках вторичной очистки."""

    def marker(self, backup_id: str = DEFAULT_ID) -> pathlib.Path:
        return self.db_dir / f"crm7-{backup_id}.id.lock"

    def create(self, runner) -> pathlib.Path:
        with redirect_stdout(io.StringIO()):
            return backup.create_backup(
                self.project, self.db_dir, self.secrets_dir,
                runner=runner, backup_id=DEFAULT_ID, require_docker=False,
            )

    def fail_marker_unlink(self):
        # pathlib.Path.unlink на Python 3.10 использует кэшированный accessor,
        # поэтому патчим сам метод — работает и на 3.10, и на 3.12.
        real_unlink = pathlib.Path.unlink
        marker = self.marker()

        def unlink(path, *args, **kwargs):
            if str(path) == str(marker):
                raise OSError("CLEANUP_MARKER")
            return real_unlink(path, *args, **kwargs)

        return mock.patch.object(pathlib.Path, "unlink", unlink)

    def test_success_cleanup_ok(self) -> None:
        self.create(lambda command, cwd: FAKE_SQL)

        self.assertTrue(self.sql_path().exists())
        self.assertTrue((self.db_dir / f"crm7-{DEFAULT_ID}.manifest.json").exists())
        self.assertFalse(self.marker().exists())

    def test_primary_failure_cleanup_ok(self) -> None:
        def failing(*args, **kwargs):
            raise RuntimeError("PRIMARY")

        with self.assertRaisesRegex(RuntimeError, "PRIMARY"):
            self.create(failing)

        self.assertFalse((self.db_dir / f"crm7-{DEFAULT_ID}.manifest.json").exists())
        self.assertFalse(self.marker().exists())

    def test_success_cleanup_error_surfaces(self) -> None:
        errors = io.StringIO()

        with self.fail_marker_unlink(), redirect_stderr(errors):
            with self.assertRaisesRegex(OSError, "CLEANUP_MARKER"):
                self.create(lambda command, cwd: FAKE_SQL)

        # Без первичной ошибки cleanup не подавляется и не требует stderr-диагностики:
        # её сообщение доступно вызывающему коду напрямую (главный catch main()).
        self.assertEqual(errors.getvalue(), "")
        self.assertTrue(self.sql_path().exists())
        self.assertTrue((self.db_dir / f"crm7-{DEFAULT_ID}.manifest.json").exists())
        self.assertTrue(self.marker().exists())

    def test_primary_failure_cleanup_error_preserves_primary(self) -> None:
        def failing(*args, **kwargs):
            raise RuntimeError("PRIMARY")

        errors = io.StringIO()

        with self.fail_marker_unlink(), redirect_stderr(errors):
            with self.assertRaisesRegex(RuntimeError, "PRIMARY"):
                self.create(failing)

        self.assertIn("cleanup failed (backup ID marker): OSError", errors.getvalue())
        self.assertNotIn("backup cleanup failed", errors.getvalue())
        self.assertFalse((self.db_dir / f"crm7-{DEFAULT_ID}.manifest.json").exists())
        self.assertTrue(self.marker().exists())

    def test_multiple_cleanup_errors_are_all_diagnosed(self) -> None:
        def failing(*args, **kwargs):
            raise RuntimeError("PRIMARY")

        def cleanup_a():
            raise OSError("CLEANUP_A")

        def cleanup_b():
            raise OSError("CLEANUP_B")

        errors = io.StringIO()

        def body():
            try:
                raise RuntimeError("PRIMARY")
            except RuntimeError:
                backup.run_cleanup("first cleanup", cleanup_a)
                backup.run_cleanup("second cleanup", cleanup_b)
                raise

        with redirect_stderr(errors):
            with self.assertRaisesRegex(RuntimeError, "PRIMARY"):
                body()

        self.assertIn("cleanup failed (first cleanup): OSError", errors.getvalue())
        self.assertIn("cleanup failed (second cleanup): OSError", errors.getvalue())
        self.assertEqual(errors.getvalue().count("cleanup failed"), 2)
        self.assertNotIn("backup cleanup failed", errors.getvalue())

    def test_sigterm_with_cleanup_error_preserves_backup_interrupted(self) -> None:
        handlers: dict[int, object] = {}

        def fake_signal(signum, handler):
            handlers[signum] = handler
            return signal.SIG_DFL

        def runner(*args, **kwargs):
            handlers[signal.SIGTERM](signal.SIGTERM, None)
            return FAKE_SQL

        signal_mock = mock.Mock(side_effect=fake_signal)
        errors = io.StringIO()

        with mock.patch.object(backup.signal, "signal", signal_mock), \
             self.fail_marker_unlink(), redirect_stderr(errors):
            with self.assertRaises(backup.BackupInterrupted) as caught:
                self.create(runner)

        self.assertIn("interrupted by signal", str(caught.exception))
        self.assertIn("backup ID marker", errors.getvalue())
        self.assertEqual(signal_mock.call_count, 2)
        self.assertEqual(signal_mock.call_args_list[1].args,
                         (signal.SIGTERM, signal.SIG_DFL))

    def test_atomic_bytes_write_and_temp_cleanup_both_fail(self) -> None:
        self.db_dir.mkdir(parents=True, exist_ok=True)
        target = self.db_dir / "atomic.bin"
        real_unlink = pathlib.Path.unlink

        def unlink(path, *args, **kwargs):
            if ".atomic.bin." in str(path):
                raise OSError("CLEANUP_TEMP")
            return real_unlink(path, *args, **kwargs)

        errors = io.StringIO()
        with mock.patch.object(backup.os, "fsync", side_effect=OSError("PRIMARY_FSYNC")), \
             mock.patch.object(pathlib.Path, "unlink", unlink), redirect_stderr(errors):
            with self.assertRaisesRegex(OSError, "PRIMARY_FSYNC"):
                backup._atomic_bytes(target, b"payload")

        self.assertIn("atomic write temp", errors.getvalue())
        self.assertFalse(target.exists())

    def test_secret_bundle_write_and_temp_cleanup_both_fail(self) -> None:
        self.secrets_dir.mkdir(parents=True, exist_ok=True)
        target = self.secrets_dir / "crm7-bundle.secrets.tar"
        real_unlink = pathlib.Path.unlink

        def unlink(path, *args, **kwargs):
            if ".secrets.tar." in str(path):
                raise OSError("CLEANUP_TEMP")
            return real_unlink(path, *args, **kwargs)

        errors = io.StringIO()
        with mock.patch.object(backup.os, "fsync", side_effect=OSError("PRIMARY_FSYNC")), \
             mock.patch.object(pathlib.Path, "unlink", unlink), redirect_stderr(errors):
            with self.assertRaisesRegex(OSError, "PRIMARY_FSYNC"):
                backup._secret_bundle(
                    self.project, target,
                    [(pathlib.PurePosixPath("api/config/.env"),
                      self.project / "api" / "config" / ".env")],
                )

        self.assertIn("secret bundle write temp", errors.getvalue())
        self.assertFalse(target.exists())


    def test_diagnostic_failure_does_not_replace_primary(self) -> None:
        class BrokenStderr:
            def write(self, *args, **kwargs):
                raise OSError("STDERR_BROKEN")

            def flush(self):
                pass

        def cleanup():
            raise OSError("CLEANUP_DIRECT")

        def body():
            try:
                raise RuntimeError("PRIMARY_DIAG")
            except RuntimeError:
                backup.run_cleanup("broken stderr", cleanup)
                raise

        with mock.patch.object(sys, "stderr", BrokenStderr()):
            with self.assertRaisesRegex(RuntimeError, "PRIMARY_DIAG") as caught:
                body()

        self.assertEqual(str(caught.exception), "PRIMARY_DIAG")

    def test_cleanup_error_without_primary_surfaces(self) -> None:
        def cleanup():
            raise OSError("CLEANUP_DIRECT")

        with self.assertRaisesRegex(OSError, "CLEANUP_DIRECT"):
            backup.run_cleanup("no primary", cleanup)

    def test_nested_managers_preserve_primary_with_multiple_cleanup_errors(self) -> None:
        def fail():
            raise OSError("CLEANUP_NESTED")

        @contextlib.contextmanager
        def cleanup_layer(label):
            try:
                yield
            finally:
                # Реальный production-helper, не mock.
                backup.run_cleanup(label, fail)

        errors = io.StringIO()
        with redirect_stderr(errors):
            with self.assertRaisesRegex(RuntimeError, "PRIMARY_NESTED") as caught:
                with cleanup_layer("inner cleanup"), cleanup_layer("outer cleanup"):
                    raise RuntimeError("PRIMARY_NESTED")

        self.assertEqual(str(caught.exception), "PRIMARY_NESTED")
        self.assertIn("cleanup failed (inner cleanup): OSError", errors.getvalue())
        self.assertIn("cleanup failed (outer cleanup): OSError", errors.getvalue())
        self.assertNotIn("backup cleanup failed", errors.getvalue())


class CleanupLockCompatibilityTest(unittest.TestCase):
    """E — освобождение общего project lock при ошибках cleanup."""

    @staticmethod
    def _handle():
        class Handle:
            closed = False

            def close(self):
                self.closed = True

        return Handle()

    def test_release_error_without_primary_surfaces(self) -> None:
        handle = self._handle()

        def fail_release(h):
            h.close()
            raise OSError("CLEANUP_UNLOCK")

        with mock.patch.object(lock_api, "acquire_update_lock", return_value=handle), \
             mock.patch.object(lock_api, "release_update_lock", side_effect=fail_release):
            with self.assertRaisesRegex(OSError, "CLEANUP_UNLOCK"):
                with lock_api.update_lock(pathlib.Path("/tmp/crm7-unit")):
                    pass

        self.assertTrue(handle.closed)

    def test_release_error_with_primary_preserves_primary(self) -> None:
        handle = self._handle()

        def fail_release(h):
            h.close()
            raise OSError("CLEANUP_UNLOCK")

        errors = io.StringIO()
        with mock.patch.object(lock_api, "acquire_update_lock", return_value=handle), \
             mock.patch.object(lock_api, "release_update_lock", side_effect=fail_release), \
             redirect_stderr(errors):
            with self.assertRaisesRegex(RuntimeError, "PRIMARY"):
                with lock_api.update_lock(pathlib.Path("/tmp/crm7-unit")):
                    raise RuntimeError("PRIMARY")

        self.assertTrue(handle.closed)
        self.assertIn("project lock release", errors.getvalue())
        self.assertIn("OSError", errors.getvalue())

    def test_successful_release_keeps_context_contract(self) -> None:
        handle = self._handle()
        with mock.patch.object(lock_api, "acquire_update_lock", return_value=handle), \
             mock.patch.object(lock_api, "release_update_lock",
                               side_effect=lambda h: h.close()):
            with lock_api.update_lock(pathlib.Path("/tmp/crm7-unit")) as acquired:
                self.assertIs(acquired, handle)
        self.assertTrue(handle.closed)


class MarkerDiagnosticsTest(BackupFixture):
    """Read-only диагностика ID-маркеров и улучшенное сообщение collision."""

    def marker(self, backup_id: str = DEFAULT_ID) -> pathlib.Path:
        return backup._marker_path(self.db_dir, backup_id)

    def make_marker(self, backup_id: str = DEFAULT_ID, content: bytes = b"") -> pathlib.Path:
        self.db_dir.mkdir(parents=True, exist_ok=True)
        marker = self.marker(backup_id)
        marker.write_bytes(content)
        return marker

    @staticmethod
    def dir_state(root: pathlib.Path):
        if not root.exists():
            return None
        return {
            str(path.relative_to(root)): (
                path.stat().st_size, path.stat().st_mtime_ns, path.read_bytes()
            )
            for path in sorted(root.rglob("*")) if path.is_file()
        }

    def diagnose(self, project=None) -> dict:
        return backup.collect_marker_diagnostics(
            self.db_dir, self.secrets_dir, project or self.project
        )

    def test_no_markers_creates_nothing(self) -> None:
        self.db_dir.mkdir(parents=True, exist_ok=True)
        before_db = self.dir_state(self.db_dir)
        before_secrets = self.dir_state(self.secrets_dir)

        result = self.diagnose()

        self.assertEqual(result["markers"], [])
        self.assertEqual(self.dir_state(self.db_dir), before_db)
        self.assertEqual(self.dir_state(self.secrets_dir), before_secrets)
        output = io.StringIO()
        with redirect_stdout(output):
            backup.print_marker_diagnostics(result)
        self.assertIn("no backup ID markers found", output.getvalue())

    def test_single_empty_marker_is_not_claimed_orphan(self) -> None:
        marker = self.make_marker()
        before = self.dir_state(self.db_dir)

        result = self.diagnose()
        entry = result["markers"][0]

        self.assertEqual(entry["backup_id"], DEFAULT_ID)
        self.assertEqual(entry["marker_path"], str(marker))
        self.assertEqual(entry["marker_size"], 0)
        self.assertIs(entry["marker_empty"], True)
        self.assertFalse(entry["sql_present"])
        self.assertFalse(entry["bundle_present"])
        self.assertFalse(entry["manifest_present"])
        self.assertEqual(entry["status"], "incomplete")
        self.assertEqual(entry["verify"], "not available")
        self.assertEqual(self.dir_state(self.db_dir), before)

        output = io.StringIO()
        with redirect_stdout(output):
            backup.print_marker_diagnostics(result)
        self.assertNotIn("orphan", output.getvalue().lower())

    def test_marker_with_complete_backup_uses_production_verify(self) -> None:
        self.make_backup()
        marker = self.make_marker()
        before = self.dir_state(self.db_dir)
        before_secrets = self.dir_state(self.secrets_dir)
        before_stat = (marker.stat().st_size, marker.stat().st_mtime_ns)

        result = self.diagnose()
        entry = result["markers"][0]

        self.assertEqual(entry["status"], "complete")
        self.assertEqual(entry["verify"], "passed")
        self.assertTrue(entry["sql_present"])
        self.assertTrue(entry["bundle_present"])
        self.assertTrue(entry["manifest_present"])
        self.assertEqual(self.dir_state(self.db_dir), before)
        self.assertEqual(self.dir_state(self.secrets_dir), before_secrets)
        self.assertEqual((marker.stat().st_size, marker.stat().st_mtime_ns), before_stat)

    def test_marker_with_corrupted_manifest_is_invalid(self) -> None:
        self.make_backup()
        self.make_marker()
        (self.db_dir / f"crm7-{DEFAULT_ID}.manifest.json").write_bytes(b"{broken")

        result = self.diagnose()
        entry = result["markers"][0]

        self.assertEqual(entry["status"], "invalid")
        self.assertTrue(entry["verify"].startswith("failed:"))
        output = io.StringIO()
        with redirect_stdout(output):
            backup.print_marker_diagnostics(result)
        self.assertNotIn("Traceback", output.getvalue())
        self.assertNotIn(FAKE_KEYS, output.getvalue())

    def test_marker_with_partial_artifacts_is_incomplete(self) -> None:
        self.make_marker()
        (self.db_dir / f"crm7-{DEFAULT_ID}.sql").write_bytes(FAKE_SQL)

        entry = self.diagnose()["markers"][0]

        self.assertEqual(entry["status"], "incomplete")
        self.assertTrue(entry["sql_present"])
        self.assertFalse(entry["bundle_present"])
        self.assertFalse(entry["manifest_present"])
        self.assertEqual(entry["verify"], "not available")

    def test_non_empty_marker_is_flagged_not_parsed(self) -> None:
        self.make_marker(content=b"PID=4242\nSECRET=unit-test-key\n")
        before = self.dir_state(self.db_dir)

        result = self.diagnose()
        entry = result["markers"][0]

        self.assertIs(entry["marker_empty"], False)
        self.assertTrue(any("not empty" in warning for warning in entry["warnings"]))
        output = io.StringIO()
        with redirect_stdout(output):
            backup.print_marker_diagnostics(result)
        self.assertNotIn("PID=4242", output.getvalue())
        self.assertNotIn("unit-test-key", output.getvalue())
        self.assertEqual(self.dir_state(self.db_dir), before)

    def test_filesystem_errors_are_reported_not_claimed_orphan(self) -> None:
        missing = self.base / "missing-dir"
        result = backup.collect_marker_diagnostics(missing, self.secrets_dir, self.project)
        self.assertIsNotNone(result["error"])
        self.assertEqual(result["markers"], [])

        self.make_marker()
        real_lstat = pathlib.Path.lstat

        def fail_lstat(path, *args, **kwargs):
            if path.name == f"crm7-{DEFAULT_ID}.id.lock":
                raise OSError("stat-denied")
            return real_lstat(path, *args, **kwargs)

        with mock.patch.object(pathlib.Path, "lstat", fail_lstat):
            entry = self.diagnose()["markers"][0]

        self.assertEqual(entry["status"], "unknown")
        self.assertTrue(any("stat failed" in w for w in entry["warnings"]))

    @unittest.skipUnless(lock_api.LOCK_SUPPORTED, "POSIX flock required")
    def test_busy_project_lock_is_reported_fail_fast(self) -> None:
        self.make_marker()
        handle = lock_api.acquire_update_lock(self.project)
        try:
            result = self.diagnose()
        finally:
            lock_api.release_update_lock(handle)

        self.assertEqual(result["lock_status"], "busy")
        self.assertTrue(self.marker().exists())

    @unittest.skipUnless(lock_api.LOCK_SUPPORTED, "POSIX flock required")
    def test_diagnostics_does_not_deadlock_with_active_lock_holder(self) -> None:
        self.make_marker()
        ready = threading.Event()
        release = threading.Event()
        result_holder: dict = {}

        def holder():
            handle = lock_api.acquire_update_lock(self.project)
            ready.set()
            release.wait(10)
            lock_api.release_update_lock(handle)

        thread = threading.Thread(target=holder)
        thread.start()
        try:
            self.assertTrue(ready.wait(10))
            result_holder["result"] = self.diagnose()
        finally:
            release.set()
            thread.join(timeout=10)

        self.assertFalse(thread.is_alive())
        self.assertEqual(result_holder["result"]["lock_status"], "busy")
        self.assertTrue(self.marker().exists())

    def test_probe_does_not_create_lock_file(self) -> None:
        project = self.base / "no-lock-project"
        project.mkdir()
        before = self.dir_state(project)

        status = backup._probe_project_lock(project)

        self.assertEqual(status, "unknown")
        self.assertEqual(self.dir_state(project), before)

    def test_cli_subcommand_is_read_only(self) -> None:
        self.make_marker()
        before = self.dir_state(self.db_dir)

        output = io.StringIO()
        with redirect_stdout(output):
            code = backup.main([
                "markers",
                "--db-backup-dir", str(self.db_dir),
                "--secrets-dir", str(self.secrets_dir),
                "--project-dir", str(self.project),
            ])

        self.assertEqual(code, 0)
        self.assertIn("backup_id: " + DEFAULT_ID, output.getvalue())
        self.assertEqual(self.dir_state(self.db_dir), before)

    def test_cli_requires_directories(self) -> None:
        errors = io.StringIO()
        with redirect_stderr(errors):
            code = backup.main(["markers", "--db-backup-dir", str(self.db_dir)])
        self.assertEqual(code, 1)
        self.assertIn("--secrets-dir", errors.getvalue())

    def test_collision_message_keeps_exception_class_and_adds_diagnostics_hint(self) -> None:
        self.make_marker()

        with self.assertRaises(backup.BackupError) as caught:
            with redirect_stdout(io.StringIO()):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=lambda command, cwd: FAKE_SQL,
                    backup_id=DEFAULT_ID, require_docker=False,
                )

        message = str(caught.exception)
        self.assertIn(DEFAULT_ID, message)
        self.assertIn("marker exists", message)
        self.assertIn("markers", message)
        self.assertIn("does not prove", message)
        self.assertNotIn(FAKE_KEYS, message)

    def test_artifact_collision_message_reports_artifacts(self) -> None:
        self.db_dir.mkdir(parents=True, exist_ok=True)
        (self.db_dir / f"crm7-{DEFAULT_ID}.sql").write_bytes(FAKE_SQL)

        with self.assertRaises(backup.BackupError) as caught:
            with redirect_stdout(io.StringIO()):
                backup.create_backup(
                    self.project, self.db_dir, self.secrets_dir,
                    runner=lambda command, cwd: FAKE_SQL,
                    backup_id=DEFAULT_ID, require_docker=False,
                )

        self.assertIn("artifacts exist", str(caught.exception))


class MarkerDiagnosticsHardeningTest(BackupFixture):
    """Symlink/race/lock-probe диагностика c.15.24.1.3."""

    def make_complete(self) -> pathlib.Path:
        self.make_backup()
        marker = backup._marker_path(self.db_dir, DEFAULT_ID)
        marker.write_bytes(b"")
        return marker

    def entry(self) -> dict:
        return backup.collect_marker_diagnostics(
            self.db_dir, self.secrets_dir, self.project
        )["markers"][0]

    def test_marker_symlink_is_unknown(self) -> None:
        self.make_complete()
        target = self.base / "external-marker"
        target.write_bytes(b"")
        marker = backup._marker_path(self.db_dir, DEFAULT_ID)
        marker.unlink()
        marker.symlink_to(target)

        entry = self.entry()

        self.assertEqual(entry["status"], "unknown")
        self.assertTrue(entry["marker_is_symlink"])
        self.assertTrue(any("marker is a symlink" in warning for warning in entry["warnings"]))

    def test_broken_marker_symlink_is_unknown(self) -> None:
        self.make_complete()
        marker = backup._marker_path(self.db_dir, DEFAULT_ID)
        marker.unlink()
        marker.symlink_to(self.base / "missing")

        entry = self.entry()

        self.assertEqual(entry["status"], "unknown")
        self.assertTrue(entry["marker_is_symlink"])

    def test_sql_and_bundle_symlinks_are_unknown(self) -> None:
        self.make_complete()
        for name in ("sql", "bundle"):
            with self.subTest(artifact=name):
                path = (self.db_dir / f"crm7-{DEFAULT_ID}.sql" if name == "sql"
                        else self.bundle_path())
                copy = self.base / f"copy-{name}"
                copy.write_bytes(path.read_bytes())
                path.unlink()
                path.symlink_to(copy)

                entry = self.entry()
                self.assertEqual(entry["status"], "unknown")
                self.assertTrue(entry[f"{name}_is_symlink"])
                self.assertTrue(any(
                    f"{name} is a symlink" in warning for warning in entry["warnings"]
                ))
                path.unlink()
                path.write_bytes(copy.read_bytes())

    def test_manifest_symlink_is_unknown_not_invalid(self) -> None:
        self.make_complete()
        manifest = self.db_dir / f"crm7-{DEFAULT_ID}.manifest.json"
        copy = self.base / "manifest-copy"
        copy.write_bytes(manifest.read_bytes())
        manifest.unlink()
        manifest.symlink_to(copy)

        entry = self.entry()

        self.assertEqual(entry["status"], "unknown")
        self.assertTrue(entry["manifest_is_symlink"])
        self.assertNotIn("failed", entry["verify"])

    @unittest.skipUnless(lock_api.LOCK_SUPPORTED, "POSIX flock required")
    def test_symlink_lock_path_is_not_opened_or_locked(self) -> None:
        project = self.base / "lock-project"
        project.mkdir()
        real_lock = project / "real.lock"
        real_lock.write_bytes(b"")
        link = lock_api.lock_path(project)
        link.parent.mkdir(parents=True, exist_ok=True)
        link.symlink_to(real_lock)

        self.assertEqual(backup._probe_project_lock(project), "unknown")

        # Целевой файл не заблокирован probe: другой процесс может взять lock.
        fd = os.open(real_lock, os.O_RDONLY)
        try:
            lock_api.fcntl.flock(fd, lock_api.fcntl.LOCK_EX | lock_api.fcntl.LOCK_NB)
            lock_api.fcntl.flock(fd, lock_api.fcntl.LOCK_UN)
        finally:
            os.close(fd)

        result = backup.collect_marker_diagnostics(self.db_dir, self.secrets_dir, project)
        self.assertTrue(any(
            "project lock path is a symlink" in warning
            for warning in result["warnings"]
        ))

    @unittest.skipUnless(lock_api.LOCK_SUPPORTED, "POSIX flock required")
    def test_snapshot_warning_for_busy_lock(self) -> None:
        self.make_complete()
        handle = lock_api.acquire_update_lock(self.project)
        try:
            result = backup.collect_marker_diagnostics(
                self.db_dir, self.secrets_dir, self.project
            )
        finally:
            lock_api.release_update_lock(handle)

        output = io.StringIO()
        with redirect_stdout(output):
            backup.print_marker_diagnostics(result)
        text = output.getvalue()
        self.assertIn("project lock: busy", text)
        self.assertIn("snapshot is not guaranteed", text)
        self.assertNotIn("no backup ID markers found", text)

    def test_snapshot_note_matches_lock_status(self) -> None:
        self.make_complete()
        result = backup.collect_marker_diagnostics(
            self.db_dir, self.secrets_dir, self.project
        )
        output = io.StringIO()
        with redirect_stdout(output):
            backup.print_marker_diagnostics(result)
        text = output.getvalue()
        if result["lock_status"] == "available":
            self.assertIn("best-effort snapshot", text)
        else:
            self.assertIn("snapshot is not guaranteed", text)

    def test_summary_and_exit_code_note(self) -> None:
        self.make_complete()
        result = backup.collect_marker_diagnostics(
            self.db_dir, self.secrets_dir, self.project
        )
        output = io.StringIO()
        with redirect_stdout(output):
            backup.print_marker_diagnostics(result)
        text = output.getvalue()
        self.assertIn("summary: markers found=1", text)
        self.assertIn("complete=1", text)
        self.assertIn("exit code 0 means diagnostics ran successfully", text)
        self.assertIn("no files were removed or modified", text)

    def test_artifact_change_during_verify_is_unknown(self) -> None:
        self.make_complete()
        sql = self.db_dir / f"crm7-{DEFAULT_ID}.sql"
        original = sql.read_bytes()

        def mutate(*args, **kwargs):
            sql.write_bytes(original + b"-- changed\n")

        with mock.patch.object(backup, "verify", side_effect=mutate):
            entry = self.entry()

        self.assertEqual(entry["status"], "unknown")
        self.assertTrue(any("during verification" in w for w in entry["warnings"]))

    @unittest.skipUnless(lock_api.LOCK_SUPPORTED, "POSIX flock required")
    def test_unlock_failure_surfaces_without_primary(self) -> None:
        project = self.base / "lock-project"
        project.mkdir()
        lock = lock_api.lock_path(project)
        lock.parent.mkdir(parents=True, exist_ok=True)
        lock.write_bytes(b"")

        real_flock = lock_api.fcntl.flock

        def fail_unlock(fd, operation):
            if operation & lock_api.fcntl.LOCK_UN:
                raise OSError("UNLOCK_FAILED")
            return real_flock(fd, operation)

        with mock.patch.object(lock_api.fcntl, "flock", side_effect=fail_unlock):
            with self.assertRaisesRegex(OSError, "UNLOCK_FAILED"):
                backup._probe_project_lock(project)

    @unittest.skipUnless(lock_api.LOCK_SUPPORTED, "POSIX flock required")
    def test_close_failure_does_not_replace_unlock_failure(self) -> None:
        project = self.base / "lock-project"
        project.mkdir()
        lock = lock_api.lock_path(project)
        lock.parent.mkdir(parents=True, exist_ok=True)
        lock.write_bytes(b"")

        real_flock = lock_api.fcntl.flock
        real_close = backup.os.close
        real_open = backup.os.open
        opened = []

        def fail_unlock(fd, operation):
            if operation & lock_api.fcntl.LOCK_UN:
                raise OSError("UNLOCK_FAILED")
            return real_flock(fd, operation)

        def track_open(path, flags, *args, **kwargs):
            descriptor = real_open(path, flags, *args, **kwargs)
            opened.append(descriptor)
            return descriptor

        def fail_close(fd):
            raise OSError("CLOSE_FAILED")

        errors = io.StringIO()
        with mock.patch.object(backup.os, "open", side_effect=track_open), \
             mock.patch.object(lock_api.fcntl, "flock", side_effect=fail_unlock), \
             mock.patch.object(backup.os, "close", side_effect=fail_close), \
             redirect_stderr(errors):
            with self.assertRaisesRegex(OSError, "UNLOCK_FAILED"):
                backup._probe_project_lock(project)

        self.assertIn("cleanup failed (lock probe descriptor): OSError", errors.getvalue())
        for descriptor in opened:
            real_close(descriptor)


if __name__ == "__main__":
    unittest.main()
