"""Unit tests for the native Dockerless backup/restore backend.

These tests are intentionally Docker/PHP-free. The full disposable runtime
verification is described in docs/backup-restore-native.md.
"""
import contextlib
import stat
import json
import os
import pathlib
import sys
import tempfile
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "deploy"))

import backup_crm7  # noqa: E402
import native_backup_restore as native  # noqa: E402


class NativeTargetIdTest(unittest.TestCase):
    def test_target_id_stable_and_password_independent(self):
        with tempfile.TemporaryDirectory() as raw:
            project = pathlib.Path(raw)
            settings = {
                "host": "127.0.0.1", "port": "3306",
                "name": "crm_test", "user": "crm_app",
            }
            with mock.patch.object(native, "_db_settings", return_value=settings):
                first = native.native_target_id(project)
                second = native.native_target_id(project)
            self.assertEqual(first, second)
            self.assertEqual(len(first), 20)
            self.assertRegex(first, r"^[0-9a-f]{20}$")

            changed = dict(settings, name="other_db")
            with mock.patch.object(native, "_db_settings", return_value=changed):
                self.assertNotEqual(first, native.native_target_id(project))


class StorageSafetyTest(unittest.TestCase):
    def test_symlinks_and_temp_files_are_excluded(self):
        with tempfile.TemporaryDirectory() as raw:
            storage = pathlib.Path(raw)
            (storage / "keep.txt").write_bytes(b"keep")
            (storage / ".tmp-drop").write_bytes(b"temp")
            (storage / "photo.tmp").write_bytes(b"temp")
            (storage / "link").symlink_to(storage / "keep.txt")

            with mock.patch.object(native, "_project_paths",
                                   return_value={"storage": storage}):
                files = native._safe_storage_files(storage)

            names = [path.name for path in files]
            self.assertEqual(names, ["keep.txt"])
            self.assertTrue(all(not path.is_symlink() for path in files))


class ConfirmationTest(unittest.TestCase):
    def test_wrong_confirmation_fails_before_any_import(self):
        manifest = {
            "format_version": backup_crm7.MANIFEST_FORMAT,
            "backup_id": "TEST001",
            "backend": "native",
            "native_target_id": "a" * 20,
            "sql_dump": {"path": "crm7-TEST001.sql"},
            "storage": None,
        }
        with tempfile.TemporaryDirectory() as raw:
            root = pathlib.Path(raw)
            manifest_path = root / "crm7-TEST001.manifest.json"
            manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

            with mock.patch.object(native, "require_lock"), \
                 mock.patch.object(backup_crm7, "update_lock"), \
                 mock.patch.object(backup_crm7, "verify"), \
                 mock.patch.object(native, "native_target_id", return_value="a" * 20), \
                 mock.patch.object(native, "_verify_storage_component", return_value=None), \
                 mock.patch.object(native, "_project_paths",
                                   return_value={"env": root / ".env",
                                                 "db_json": root / "database.json",
                                                 "storage": root / "storage"}), \
                 mock.patch.object(native, "_import_sql",
                                   side_effect=AssertionError("must not import")):
                (root / ".env").write_text("APP_KEY=x\nDB_CONFIG_KEY=y\n", encoding="utf-8")
                with self.assertRaises(native.NativeError):
                    native.native_restore(
                        root, root, root, manifest_path,
                        confirm="WRONG", confirm_target="a" * 20,
                    )


class ManifestContractTest(unittest.TestCase):
    def test_verify_accepts_additive_native_fields(self):
        with tempfile.TemporaryDirectory() as raw:
            root = pathlib.Path(raw)
            db = root / "db"
            secrets = root / "secrets"
            db.mkdir()
            secrets.mkdir()
            sql = db / "crm7-X.sql"
            sql.write_bytes(b"-- test dump\n")
            bundle = secrets / "crm7-X.secrets.tar"
            import io, tarfile
            with tarfile.open(bundle, "w") as archive:
                info = tarfile.TarInfo(".env")
                info.mode = 0o600
                archive.addfile(info, io.BytesIO(b"APP_KEY=x\n"))
            manifest = {
                "format_version": backup_crm7.MANIFEST_FORMAT,
                "backup_id": "X",
                "created_at": "2026-01-01T00:00:00Z",
                "crm7_version": None,
                "database_engine": "mariadb",
                "database_schema_version": None,
                "backend": "native",
                "native_target_id": "b" * 20,
                "storage": None,
                "sql_dump": {"path": sql.name, "size": sql.stat().st_size,
                             "sha256": backup_crm7._sha256(sql)},
                "secret_bundle": {"path": bundle.name, "size": bundle.stat().st_size,
                                  "sha256": backup_crm7._sha256(bundle),
                                  "members": [".env"], "encrypted": False},
                "included_components": [".env"],
                "missing_optional_components": [],
            }
            path = db / "crm7-X.manifest.json"
            path.write_text(json.dumps(manifest), encoding="utf-8")
            backup_crm7.verify(path, secrets)


if __name__ == "__main__":
    unittest.main()


class NativeRestoreRecoveryTest(unittest.TestCase):
    """Deterministic recovery-lifecycle tests with mocked DB/storage clients."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = pathlib.Path(self.tmp.name)
        self.project = self.root / "project"
        (self.project / "api" / "config").mkdir(parents=True)
        (self.project / "api" / "config" / ".env").write_text(
            "APP_KEY=x\nDB_CONFIG_KEY=y\n", encoding="utf-8"
        )
        self.storage = self.project / "api" / "storage"
        self.storage.mkdir(parents=True)
        (self.storage / "existing.txt").write_bytes(b"original")
        self.db_dir = self.root / "db"
        self.db_dir.mkdir()
        self.secrets_dir = self.root / "secrets"
        self.secrets_dir.mkdir()
        self.sql = self.db_dir / "crm7-T1.sql"
        self.sql.write_bytes(b"-- dump\n")
        self.target = "a" * 20
        self.manifest = {
            "format_version": backup_crm7.MANIFEST_FORMAT,
            "backup_id": "T1",
            "backend": "native",
            "native_target_id": self.target,
            "sql_dump": {"path": self.sql.name},
            "storage": {"path": "crm7-T1.storage.tar", "members": []},
        }
        self.manifest_path = self.db_dir / "crm7-T1.manifest.json"
        self.manifest_path.write_text(json.dumps(self.manifest), encoding="utf-8")
        self.staged_paths = []
        self.real_mkdtemp = tempfile.mkdtemp

    def journal(self):
        path = self.db_dir / "crm7-native-restore-T1.json"
        return json.loads(path.read_text(encoding="utf-8"))

    def fake_dump_sql(self, project, target):
        pathlib.Path(target).write_bytes(b"-- prerestore\n")

    def fake_extract(self, project, archive, stage):
        stage = pathlib.Path(stage)
        stage.mkdir(parents=True, exist_ok=True)
        (stage / "existing.txt").write_bytes(b"restored")

    def fake_mkdtemp(self, *args, **kwargs):
        path = pathlib.Path(self.real_mkdtemp(*args, **kwargs))
        self.staged_paths.append(path)
        return str(path)

    def patches(self, *, import_error=None, extract_error=None, dump_error=None,
                target_side_effect=None, copy_error=False, rmtree_error=False):
        stack = contextlib.ExitStack()
        stack.enter_context(mock.patch.object(native, "require_lock"))
        stack.enter_context(mock.patch.object(backup_crm7, "verify"))
        stack.enter_context(
            mock.patch.object(backup_crm7, "update_lock", return_value=contextlib.nullcontext())
        )
        if target_side_effect is None:
            target_mock = mock.patch.object(native, "native_target_id", return_value=self.target)
        else:
            target_mock = mock.patch.object(
                native, "native_target_id", side_effect=target_side_effect
            )
        stack.enter_context(target_mock)
        stack.enter_context(
            mock.patch.object(native, "_verify_storage_component", return_value=self.root / "archive.tar")
        )
        stack.enter_context(
            mock.patch.object(
                native,
                "_project_paths",
                return_value={
                    "env": self.project / "api" / "config" / ".env",
                    "db_json": self.project / "api" / "config" / "database.json",
                    "storage": self.storage,
                },
            )
        )
        dump_side_effect = dump_error or self.fake_dump_sql
        stack.enter_context(
            mock.patch.object(native, "_dump_sql", side_effect=dump_side_effect)
        )
        if import_error is not None:
            stack.enter_context(mock.patch.object(native, "_import_sql", side_effect=import_error))
        else:
            stack.enter_context(mock.patch.object(native, "_import_sql"))
        if extract_error is not None:
            stack.enter_context(mock.patch.object(native, "_extract_storage", side_effect=extract_error))
        else:
            stack.enter_context(mock.patch.object(native, "_extract_storage", side_effect=self.fake_extract))
        stack.enter_context(
            mock.patch.object(native.tempfile, "mkdtemp", side_effect=self.fake_mkdtemp)
        )
        if copy_error:
            real_copy = native.shutil.copyfileobj

            def failing_copy(src, dst):
                if str(getattr(dst, "name", "")).startswith(str(self.storage)):
                    raise OSError("injected storage apply failure")
                return real_copy(src, dst)

            stack.enter_context(mock.patch.object(native.shutil, "copyfileobj", side_effect=failing_copy))
        if rmtree_error:
            real_rmtree = native.shutil.rmtree

            def failing_rmtree(path, *args, **kwargs):
                if self.staged_paths and pathlib.Path(path) == self.staged_paths[0]:
                    raise OSError("injected cleanup failure")
                return real_rmtree(path, *args, **kwargs)

            stack.enter_context(mock.patch.object(native.shutil, "rmtree", side_effect=failing_rmtree))
        return stack

    def call_restore(self):
        native.native_restore(
            self.project, self.db_dir, self.secrets_dir, self.manifest_path,
            confirm="T1", confirm_target=self.target,
        )

    def test_a_success_cleans_staging_and_prerestore(self):
        with self.patches():
            self.call_restore()
        state = self.journal()
        self.assertEqual(state["state"], "completed")
        self.assertEqual((self.storage / "existing.txt").read_bytes(), b"restored")
        self.assertTrue(all(not path.exists() for path in self.staged_paths))
        self.assertFalse((self.db_dir / "crm7-T1.prerestore.sql").exists())

    def test_b_failure_before_destructive_phase_cleans_up(self):
        with self.patches(extract_error=RuntimeError("extract failed")):
            with self.assertRaises(RuntimeError):
                self.call_restore()
        state = self.journal()
        self.assertEqual(state["state"], "failed_before_changes")
        self.assertTrue(all(not path.exists() for path in self.staged_paths))
        self.assertEqual((self.storage / "existing.txt").read_bytes(), b"original")
        self.assertFalse((self.db_dir / "crm7-T1.prerestore.sql").exists())

    def test_c_sql_import_failure_preserves_recovery_artifacts(self):
        with self.patches(import_error=RuntimeError("import failed")):
            with self.assertRaises(RuntimeError):
                self.call_restore()
        state = self.journal()
        self.assertEqual(state["state"], "manual_recovery_required")
        self.assertTrue(self.staged_paths[0].exists())
        if os.name != "nt":
            self.assertEqual(stat.S_IMODE(self.staged_paths[0].stat().st_mode), 0o700)
        snapshot = pathlib.Path(state["sql_snapshot"])
        self.assertEqual(snapshot.read_bytes(), b"-- prerestore\n")
        if os.name != "nt":
            self.assertEqual(stat.S_IMODE(snapshot.stat().st_mode), 0o600)
        self.assertEqual(pathlib.Path(state["recovery_dir"]), self.staged_paths[0])

    def test_d_storage_apply_failure_preserves_rollback_data(self):
        with self.patches(copy_error=True):
            with self.assertRaises(OSError):
                self.call_restore()
        state = self.journal()
        self.assertEqual(state["state"], "manual_recovery_required")
        recovery = pathlib.Path(state["recovery_dir"])
        self.assertTrue(recovery.exists())
        if os.name != "nt":
            self.assertEqual(stat.S_IMODE(recovery.stat().st_mode), 0o700)
        rollback = pathlib.Path(state["storage_rollback"])
        self.assertTrue((rollback / "existing.txt").exists())
        self.assertEqual((rollback / "existing.txt").read_bytes(), b"original")
        if os.name != "nt":
            self.assertEqual(stat.S_IMODE((rollback / "existing.txt").stat().st_mode), 0o600)
        self.assertTrue(pathlib.Path(state["sql_snapshot"]).exists())

    def test_e_snapshot_failure_is_non_destructive(self):
        with self.patches(dump_error=RuntimeError("dump failed")):
            with self.assertRaises(RuntimeError):
                self.call_restore()
        state = self.journal()
        self.assertEqual(state["state"], "failed_before_changes")
        self.assertEqual((self.storage / "existing.txt").read_bytes(), b"original")
        self.assertTrue(all(not path.exists() for path in self.staged_paths))

    def test_f_cleanup_failure_after_success_does_not_change_state(self):
        with self.patches(rmtree_error=True):
            self.call_restore()
        state = self.journal()
        self.assertEqual(state["state"], "completed")
        self.assertIn("cleanup_warning", state)
        self.assertEqual(pathlib.Path(state["residual_recovery_dir"]), self.staged_paths[0])
        self.assertTrue(self.staged_paths[0].exists())

    def test_g_target_identity_is_rechecked_under_lock(self):
        with self.patches(target_side_effect=[self.target, "b" * 20]):
            with self.assertRaises(native.NativeError):
                self.call_restore()
        self.assertEqual((self.storage / "existing.txt").read_bytes(), b"original")
        self.assertFalse((self.db_dir / "crm7-native-restore-T1.json").exists())

    def test_h_prior_manual_recovery_blocks_retry(self):
        (self.db_dir / "crm7-native-restore-T1.json").write_text(
            json.dumps({"state": "manual_recovery_required"}), encoding="utf-8"
        )
        with self.patches():
            with self.assertRaises(native.NativeError):
                self.call_restore()
        self.assertEqual((self.storage / "existing.txt").read_bytes(), b"original")
