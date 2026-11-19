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
import tempfile
import unittest
from contextlib import redirect_stdout
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


if __name__ == "__main__":
    unittest.main()
