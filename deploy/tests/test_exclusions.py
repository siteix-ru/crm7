#!/usr/bin/env python3
"""Тесты исключений конфигурации и подключения PHP-тестов в CI."""
from __future__ import annotations

import pathlib
import subprocess
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parent.parent.parent
sys.path.insert(0, str(ROOT / "deploy"))


def is_git_ignored(relative: str) -> bool:
    result = subprocess.run(
        ["git", "check-ignore", "-q", relative],
        cwd=ROOT,
        check=False,
    )
    return result.returncode == 0


class GitIgnoreTest(unittest.TestCase):
    def test_runtime_config_is_ignored(self) -> None:
        runtime = (
            "api/config/.env",
            "api/config/database.json",
            "api/config/database.json.bak",
            "api/config/database.json.tmp",
            "api/config/database.json.lock",
            "api/config/.cfg-abc123",
        )

        for relative in runtime:
            with self.subTest(path=relative):
                self.assertTrue(is_git_ignored(relative))

    def test_sources_examples_and_tests_are_not_ignored(self) -> None:
        tracked = (
            "api/config/.env.example",
            "api/src/Core/Config.php",
            "api/tests/database-settings.php",
            "deploy/build_images.py",
            "deploy/deploy_crm7.py",
        )

        for relative in tracked:
            with self.subTest(path=relative):
                self.assertFalse(is_git_ignored(relative))


class DockerIgnoreTest(unittest.TestCase):
    def test_runtime_config_is_excluded(self) -> None:
        lines = (ROOT / ".dockerignore").read_text(encoding="utf-8").splitlines()

        for pattern in (
            "api/config/.env",
            "api/config/database.json",
            "api/config/database.json.bak",
            "api/config/database.json.tmp",
            "api/config/database.json.*",
            "api/config/.cfg-*",
            "api/config/*.lock",
        ):
            with self.subTest(pattern=pattern):
                self.assertIn(pattern, lines)

    def test_example_and_sources_stay_in_build_context(self) -> None:
        lines = (ROOT / ".dockerignore").read_text(encoding="utf-8").splitlines()

        self.assertNotIn("api/config/.env.example", lines)
        self.assertNotIn("api/src", lines)
        self.assertNotIn("api/tests", lines)


class ArchiveExclusionTest(unittest.TestCase):
    def test_build_images_excludes_runtime_config(self) -> None:
        from build_images import EXCLUDE_FILES, EXCLUDE_PREFIXES

        self.assertIn("api/config/.env", EXCLUDE_FILES)
        self.assertIn("api/config/database.json", EXCLUDE_FILES)
        self.assertIn("api/config/database.json.lock", EXCLUDE_FILES)
        self.assertIn("api/config/database.json.", EXCLUDE_PREFIXES)
        self.assertIn("api/config/.cfg-", EXCLUDE_PREFIXES)

    def test_deploy_crm7_excludes_runtime_config(self) -> None:
        from deploy_crm7 import EXCLUDE_FILES, EXCLUDE_PREFIXES

        self.assertIn("api/config/.env", EXCLUDE_FILES)
        self.assertIn("api/config/database.json", EXCLUDE_FILES)
        self.assertIn("api/config/database.json.lock", EXCLUDE_FILES)
        self.assertIn("api/config/database.json.", EXCLUDE_PREFIXES)
        self.assertIn("api/config/.cfg-", EXCLUDE_PREFIXES)


class CiTest(unittest.TestCase):
    def test_php_standalone_tests_are_discovered(self) -> None:
        ci = (ROOT / ".github" / "workflows" / "ci.yml").read_text(encoding="utf-8")

        self.assertIn("for f in api/tests/*.php", ci)
        self.assertIn("set -e", ci)
        self.assertIn('[ -e "$f" ] || continue', ci)
        self.assertIn("php \"$f\"", ci)
        self.assertIn("extensions: openssl", ci)

    def test_db_config_key_is_generated_in_ci(self) -> None:
        ci = (ROOT / ".github" / "workflows" / "ci.yml").read_text(encoding="utf-8")

        self.assertIn("DB_CONFIG_KEY=$(openssl rand -hex 32)", ci)
        self.assertNotIn("DB_CONFIG_KEY=change_me_random_64_hex", ci)
        self.assertNotIn("echo $DB_CONFIG_KEY", ci)


if __name__ == "__main__":
    unittest.main()
