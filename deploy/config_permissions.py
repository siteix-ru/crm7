#!/usr/bin/env python3
"""Единая модель прав для конфигурации CRM7.

PHP-FPM владеет каталогом config и его файлами, поэтому может читать .env
и создавать/атомарно заменять database.json, .bak, .lock и temp-файлы.
Права 0600 исключают доступ других пользователей; каталог 0750 не даёт
доступа "world". Для обычного Linux владелец задаётся --php-owner.
"""
from __future__ import annotations

import pathlib

PROTECTED_FILE_NAMES = (
    ".env",
    "database.json",
    "database.json.bak",
    "database.json.lock",
)


def protected_files(config_dir: pathlib.Path) -> list[pathlib.Path]:
    """Все файлы конфигурации, которые должны иметь режим 0600."""
    files = [config_dir / name for name in PROTECTED_FILE_NAMES]
    files.extend(sorted(config_dir.glob(".cfg-*")))
    files.extend(sorted(config_dir.glob("*.tmp")))
    return files


def protected_permissions_script(project_dir: str, php_owner: str = "82:82") -> str:
    """Shell-фрагмент, восстанавливающий защищённые права после deployment."""
    config_dir = f"{project_dir}/app/api/config"

    return "\n".join([
        f'CONFIG_DIR="{config_dir}"',
        f'PHP_OWNER="{php_owner}"',
        'test -d "$CONFIG_DIR" || { echo "config dir not found" >&2; exit 1; }',
        'chown "$PHP_OWNER" "$CONFIG_DIR"',
        'chmod 0750 "$CONFIG_DIR"',
        'for f in "$CONFIG_DIR"/.env "$CONFIG_DIR"/database.json '
        '"$CONFIG_DIR"/database.json.bak "$CONFIG_DIR"/database.json.lock '
        '"$CONFIG_DIR"/.cfg-* "$CONFIG_DIR"/*.tmp; do',
        '  [ -e "$f" ] || continue',
        '  chown "$PHP_OWNER" "$f"',
        '  chmod 0600 "$f"',
        'done',
    ])
