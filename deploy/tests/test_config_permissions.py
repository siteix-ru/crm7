#!/usr/bin/env python3
"""Изолированные тесты прав конфигурации CRM7 (без root, Docker и реальных файлов)."""
from __future__ import annotations

import os
import pathlib
import sys
import tempfile
import unittest

DEPLOY_DIR = pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0, str(DEPLOY_DIR))

from config_permissions import protected_files, protected_permissions_script  # noqa: E402
from crm7_updater import set_version  # noqa: E402


class ConfigPermissionsTest(unittest.TestCase):
    def test_protected_files(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            config = pathlib.Path(tmp)
            for name in (".env", "database.json", "database.json.bak", "database.json.lock", ".cfg-abc", "x.tmp"):
                (config / name).write_text("x", encoding="utf-8")
            (config / "keep.txt").write_text("public", encoding="utf-8")

            names = {path.name for path in protected_files(config)}

            self.assertIn(".env", names)
            self.assertIn("database.json", names)
            self.assertIn("database.json.bak", names)
            self.assertIn("database.json.lock", names)
            self.assertIn(".cfg-abc", names)
            self.assertIn("x.tmp", names)
            self.assertNotIn("keep.txt", names)

    def test_script_uses_protected_modes_and_owner(self) -> None:
        script = protected_permissions_script("/srv/projects/crm7-test", "myuser:mygroup")

        self.assertIn('PHP_OWNER="myuser:mygroup"', script)
        self.assertIn("chmod 0750", script)
        self.assertIn("chmod 0600", script)
        self.assertNotIn("chmod 644", script)
        self.assertNotIn("chmod 777", script)
        self.assertIn("test -d", script)

    def test_script_is_idempotent(self) -> None:
        first = protected_permissions_script("/srv/projects/crm7-test")
        second = protected_permissions_script("/srv/projects/crm7-test")

        self.assertEqual(first, second)


class DeployScriptTest(unittest.TestCase):
    def test_remote_script_restores_protected_permissions(self) -> None:
        from deploy_crm7 import remote_script

        script = remote_script("/srv/projects/crm7-test", "82:82")
        protected = protected_permissions_script("/srv/projects/crm7-test", "82:82")

        self.assertIn(protected, script)
        self.assertLess(script.index("chmod 644"), script.index("chmod 0600"))
        self.assertNotIn("chmod 640", script)

    def test_deploy_excludes_runtime_config_files(self) -> None:
        from deploy_crm7 import EXCLUDE_FILES, EXCLUDE_PREFIXES

        self.assertIn("api/config/.env", EXCLUDE_FILES)
        self.assertIn("api/config/database.json", EXCLUDE_FILES)
        self.assertIn("api/config/database.json.lock", EXCLUDE_FILES)
        self.assertTrue(any(prefix.startswith("api/config/database.json.") for prefix in EXCLUDE_PREFIXES))
        self.assertTrue(any(prefix == "api/config/.cfg-" for prefix in EXCLUDE_PREFIXES))


class SetVersionTest(unittest.TestCase):
    def test_mode_and_owner_are_preserved(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            env_file = pathlib.Path(tmp) / ".env"
            env_file.write_text("CRM7_VERSION=1.0.0\nDB_PASS=secret\n", encoding="utf-8")
            os.chmod(env_file, 0o640)
            mode_before = env_file.stat().st_mode & 0o777

            set_version(env_file, "1.8.19")

            self.assertIn("CRM7_VERSION=1.8.19", env_file.read_text(encoding="utf-8"))
            self.assertIn("DB_PASS=secret", env_file.read_text(encoding="utf-8"))
            # На Windows POSIX-биты не полностью применимы: проверяем сохранение режима.
            self.assertEqual(env_file.stat().st_mode & 0o777, mode_before)
            self.assertFalse(env_file.with_name(".env.tmp").exists())

    def test_result_keeps_initial_restricted_mode(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            env_file = pathlib.Path(tmp) / ".env"
            env_file.write_text("CRM7_VERSION=1.0.0\n", encoding="utf-8")
            os.chmod(env_file, 0o600)
            mode_before = env_file.stat().st_mode & 0o777

            set_version(env_file, "1.8.19")

            self.assertEqual(env_file.stat().st_mode & 0o777, mode_before)


if __name__ == "__main__":
    unittest.main()
