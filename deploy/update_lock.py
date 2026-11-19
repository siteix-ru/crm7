#!/usr/bin/env python3
"""Общий механизм блокировки updater и restore CRM7.

Канонический путь lock НЕ зависит от --backup-dir/--db-backup-dir и CWD:

    <project>/update-crm-7/update.lock

Все поддерживаемые способы запуска updater (в том числе stand-updater без
--backup-dir) и restore.resolve() приходят к одному физическому файлу.

Семантика намеренно сохранена прежней: на Windows (нет fcntl) функция
возвращает None, как и исходный crm7_updater.acquire_lock().
"""
from __future__ import annotations

import contextlib
import os
import pathlib
import posixpath
import sys

try:
    import fcntl
except ImportError:  # Windows
    fcntl = None


LOCK_SUBDIR = "update-crm-7"
LOCK_NAME = "update.lock"
LOCK_SUPPORTED = fcntl is not None


class LockBusy(RuntimeError):
    """Lock уже занят другой операцией."""


def run_cleanup(label: str, operation) -> None:
    """Выполнить вторичную очистку, не подменяя первичное исключение.

    Вызывается только из cleanup-путей (finally /except). Если в момент вызова
    уже распространяется исключение основной операции, ошибка очистки
    диагностируется в stderr и подавляется; иначе она поднимается дальше.
    В диагностику намеренно попадает только тип ошибки и метка — без путей,
    содержимого конфигурации и секретов.
    """
    primary = sys.exc_info()[1]
    try:
        operation()
    except BaseException as error:
        if primary is None:
            raise
        # Диагностика не должна подменять первичное исключение: сбой вывода
        # (закрытый stderr и т.п.) подавляется только внутри этого блока.
        try:
            print(f"cleanup failed ({label}): {type(error).__name__}", file=sys.stderr)
        except BaseException:
            pass


def fsync_directory(directory: pathlib.Path) -> bool:
    """Сделать атомарный rename durable на POSIX.

    На Windows fsync каталога недоступен — это явно документированное
    платформенное ограничение. На POSIX ошибки fsync не подавляются.
    """
    if os.name == "nt":
        return False

    flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0)
    descriptor = os.open(str(directory), flags)

    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)

    return True


def lock_path(project: pathlib.Path) -> pathlib.Path:
    """Единственный канонический путь общего lock для проекта."""
    return pathlib.Path(project).resolve() / LOCK_SUBDIR / LOCK_NAME


def posix_lock_path(project_dir: str) -> pathlib.PurePosixPath:
    """Канонический путь lock для удалённого POSIX-проекта.

    Используется скриптами, которые выполняются на сервере: локальный resolve()
    здесь привёл бы к неверному пути на Windows-оркестраторе.
    """
    normalized = posixpath.normpath(project_dir)

    if not posixpath.isabs(normalized):
        raise ValueError("remote project directory must be an absolute POSIX path")

    return pathlib.PurePosixPath(normalized) / LOCK_SUBDIR / LOCK_NAME


def acquire_update_lock(project: pathlib.Path):
    """Захватить общий exclusive flock; вернуть handle или None на Windows."""
    if fcntl is None:
        return None

    path = lock_path(project)
    path.parent.mkdir(parents=True, exist_ok=True)
    handle = path.open("a+b")

    try:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except (BlockingIOError, OSError) as error:
        handle.close()
        raise LockBusy("another operation is already running") from error

    return handle


def release_update_lock(handle) -> None:
    if handle is None:
        return

    try:
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
    finally:
        handle.close()


def require_lock() -> None:
    """Критические операции не продолжаются без реальной POSIX-блокировки."""
    if not LOCK_SUPPORTED:
        raise LockBusy("POSIX flock is required; refusing to continue without a shared lock")


@contextlib.contextmanager
def update_lock(project: pathlib.Path):
    """Контекстный менеджер общего lock; None на Windows обрабатывается безопасно."""
    handle = acquire_update_lock(project)

    try:
        yield handle
    finally:
        # deallocate fd внутри release_update_lock сохраняется его собственным finally.
        run_cleanup("project lock release", lambda: release_update_lock(handle))
